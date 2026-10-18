<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/AnamService.php';

/**
 * Greg's optional Anam.ai face: nothing is shipped, nothing is switched on by
 * accident, the browser never gets the key, and every session carries a limit.
 *
 * No test here reaches api.anam.ai: the settings, the payload and the refusal
 * paths are all local, which is exactly what must work on a PBX with no
 * outbound access at all.
 */
final class AnamServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
    }

    private function clear(): void
    {
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'greg.anam%'");
        $this->db->exec("DELETE FROM sys_audit_log WHERE entity_type = 'avatar'");
    }

    private function set(array $values): void
    {
        $st = $this->db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($values as $k => $v) {
            $st->execute(['greg.anam_' . $k, (string) $v]);
        }
    }

    // ------------------------------------------------------- nothing shipped

    public function testAFreshInstallHasNoPersonaAndNoKey(): void
    {
        $s = AnamService::settings();
        $this->assertSame('', $s['persona_id'], 'no persona id may ship with the code');
        $this->assertFalse($s['key_set']);
        $this->assertFalse($s['configured']);
        $this->assertFalse($s['enabled'], 'the face is off until somebody configures it');
        $this->assertFalse(AnamService::active());
        $this->assertSame(AnamService::DEFAULT_SECONDS, $s['limit']);
    }

    public function testAPersonaIdThatIsNotOneIsIgnored(): void
    {
        $this->set(['persona_id' => 'not a persona id!']);
        $this->assertSame('', AnamService::personaId());
        $this->assertFalse(AnamService::configured());

        $this->set(['persona_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', AnamService::personaId());
    }

    // ---------------------------------------------------------------- limit

    public function testTheSessionLimitIsAlwaysInsideTheSafeRange(): void
    {
        $this->set(['max_seconds' => '5']);
        $this->assertSame(AnamService::MIN_SECONDS, AnamService::sessionLimit(),
            'a too-short limit is lifted to the minimum, never used as typed');

        $this->set(['max_seconds' => '999999']);
        $this->assertSame(AnamService::MAX_SECONDS, AnamService::sessionLimit());

        $this->set(['max_seconds' => 'garbage']);
        $this->assertSame(AnamService::DEFAULT_SECONDS, AnamService::sessionLimit());

        $this->set(['max_seconds' => '240']);
        $this->assertSame(240, AnamService::sessionLimit());
    }

    public function testThePayloadCarriesThePersonaAndTheLimit(): void
    {
        $this->set(['max_seconds' => '240']);
        $p = AnamService::payload('persona-abc-123');
        $this->assertSame('persona-abc-123', $p['personaConfig']['personaId']);
        $this->assertSame(240, $p['personaConfig']['maxSessionLengthSeconds']);

        $p = AnamService::payload('persona-abc-123', 90);
        $this->assertSame(90, $p['personaConfig']['maxSessionLengthSeconds'],
            'the engine-side cut is per session, not only per setting');
    }

    // ------------------------------------------------------------- refusals

    public function testATokenIsRefusedWhileNothingIsConfigured(): void
    {
        $r = AnamService::sessionToken();
        $this->assertFalse($r['ok']);
        $this->assertSame('', $r['token']);
        $this->assertNotSame('', $r['error']);
        $this->assertSame(AnamService::DEFAULT_SECONDS, $r['limit']);
    }

    public function testVerifyIsRefusedWhileNothingIsConfigured(): void
    {
        $r = AnamService::verify();
        $this->assertFalse($r['ok']);
        $this->assertSame('', $r['name']);
        $this->assertNotSame('', $r['error']);
    }

    public function testTheAvatarCannotBeSwitchedOnWithoutBothHalves(): void
    {
        $thrown = false;
        try {
            AnamService::save(['anam_persona_id' => 'persona-abc-123', 'anam_enabled' => '1',
                               'anam_max_seconds' => '180']);
        } catch (\CloudAiException $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'half a configuration must not be stored as "on"');
        $this->assertFalse(AnamService::settings()['enabled']);
    }

    public function testAPersonaIdWithSpacesIsRefused(): void
    {
        $this->expectException(\CloudAiException::class);
        AnamService::save(['anam_persona_id' => 'persona id with spaces']);
    }

    public function testASessionLimitOutsideTheRangeIsRefused(): void
    {
        $this->expectException(\CloudAiException::class);
        AnamService::save(['anam_max_seconds' => '2']);
    }

    // ---------------------------------------------------------------- saving

    public function testSavingStoresTheKeyEncryptedAndNeverInTheClear(): void
    {
        $key = 'sk-anam-test-0123456789abcdef';
        AnamService::save([
            'anam_persona_id' => 'persona-abc-123',
            'anam_api_key' => $key,
            'anam_max_seconds' => '240',
            'anam_enabled' => '1',
        ]);

        $stored = (string) $this->db->query(
            "SELECT setting_value FROM sys_settings WHERE setting_key = 'greg.anam_api_key'"
        )->fetchColumn();
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($key, $stored, 'the key must not be readable in the table');

        $s = AnamService::settings();
        $this->assertTrue($s['key_set'], 'but the server can still read it back');
        $this->assertSame($key, AnamService::apiKey());
        $this->assertSame('persona-abc-123', $s['persona_id']);
        $this->assertSame(240, $s['limit']);
        $this->assertTrue($s['enabled']);
        $this->assertTrue(AnamService::active());
        $this->assertStringNotContainsString($key, $s['key_masked']);
    }

    public function testSavingWritesOneAuditRowWithoutTheKey(): void
    {
        AnamService::save([
            'anam_persona_id' => 'persona-abc-123',
            'anam_api_key' => 'sk-anam-test-0123456789abcdef',
            'anam_max_seconds' => '180',
        ]);
        $rows = $this->db->query(
            "SELECT entity_label, action FROM sys_audit_log WHERE entity_type = 'avatar'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertStringNotContainsString('sk-anam-test', $rows[0]['entity_label']);
        $this->assertStringContainsString('…bc-123', $rows[0]['entity_label'],
            'the log names the persona by its tail, enough to tell two apart');
    }

    public function testTheKeyCanBeRemovedAgain(): void
    {
        AnamService::save([
            'anam_persona_id' => 'persona-abc-123',
            'anam_api_key' => 'sk-anam-test-0123456789abcdef',
            'anam_max_seconds' => '180',
            'anam_enabled' => '1',
        ]);
        $this->assertTrue(AnamService::active());

        AnamService::save([
            'anam_persona_id' => 'persona-abc-123',
            'anam_key_clear' => '1',
            'anam_max_seconds' => '180',
        ]);

        $this->assertFalse(AnamService::settings()['key_set']);
        $this->assertFalse(AnamService::active(), 'without a key the face cannot be on');
        $this->assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) FROM sys_settings WHERE setting_key = 'greg.anam_api_key'"
        )->fetchColumn());
    }

    // ---------------------------------------------------- listening mode

    public function testTheAvatarDoesNotListenUnlessToldTo(): void
    {
        $this->assertFalse(AnamService::listens(), 'the default must not open a microphone');
        AnamService::save(['anam_persona_id' => 'persona-abc-123',
                           'anam_api_key' => 'sk-anam-test-0123456789abcdef',
                           'anam_max_seconds' => '180', 'anam_listen' => '1']);
        $this->assertTrue(AnamService::listens());
        $this->assertTrue(AnamService::settings()['listen']);
        AnamService::save(['anam_persona_id' => 'persona-abc-123', 'anam_max_seconds' => '180']);
        $this->assertFalse(AnamService::listens(), 'un-ticking the box turns it off again');
    }

    // ---------------------------------------------------------- meetings

    public function testOnlyTheMeetingHostsAnamSupportsAreAccepted(): void
    {
        foreach ([
            'https://meet.google.com/abc-defg-hij',
            'https://us02web.zoom.us/j/1234567890?pwd=x',
            'https://teams.microsoft.com/l/meetup-join/19%3ameeting',
            'https://teams.live.com/meet/123',
        ] as $url) {
            $this->assertSame('', AnamService::meetingUrlProblem($url), $url);
        }
        foreach ([
            'http://meet.google.com/abc-defg-hij' => 'plain http',
            'https://example.com/meet/1' => 'not a meeting host',
            '' => 'empty',
            'meet.google.com/abc' => 'no scheme',
        ] as $url => $why) {
            $this->assertNotSame('', AnamService::meetingUrlProblem((string) $url), $why);
        }
    }

    public function testTheMeetingPayloadNamesThePersonaAndTheMode(): void
    {
        $this->set(['persona_id' => 'persona-abc-123']);
        $p = AnamService::meetingPayload('https://meet.google.com/abc-defg-hij');
        $this->assertSame('https://meet.google.com/abc-defg-hij', $p['meetingUrl']);
        $this->assertSame('persona-abc-123', $p['personaId'],
            'a saved persona is named at the top level; personaConfig is for inline ones');
        $this->assertArrayNotHasKey('personaConfig', $p);
        $this->assertTrue($p['groupCall'], 'a group call is the safe default: it waits to be addressed');
        $this->assertArrayNotHasKey('joinAt', $p, 'join now carries no time');

        $p = AnamService::meetingPayload('https://meet.google.com/abc-defg-hij', '2026-10-20T09:00:00Z', false);
        $this->assertSame('2026-10-20T09:00:00Z', $p['joinAt']);
        $this->assertFalse($p['groupCall']);
    }

    public function testAMeetingInviteNeedsAConfiguredPersonaAndTouchesNoNetworkOtherwise(): void
    {
        $r = AnamService::inviteToMeeting('https://meet.google.com/abc-defg-hij');
        $this->assertFalse($r['ok']);
        $this->assertNotSame('', $r['error']);

        $this->set(['persona_id' => 'persona-abc-123']);
        $this->set(['api_key' => \SecretBox::encrypt('sk-anam-test-0123456789abcdef')]);
        $r = AnamService::inviteToMeeting('https://example.com/not-a-meeting');
        $this->assertFalse($r['ok'], 'a foreign host is refused before any request is made');
        $this->assertNotSame('', $r['error']);
    }

    public function testAMeetingFurtherAheadThanAWeekIsRefused(): void
    {
        $this->set(['persona_id' => 'persona-abc-123']);
        $this->set(['api_key' => \SecretBox::encrypt('sk-anam-test-0123456789abcdef')]);
        $r = AnamService::inviteToMeeting('https://meet.google.com/abc-defg-hij',
            gmdate('Y-m-d\TH:i:s\Z', time() + 8 * 86400));
        $this->assertFalse($r['ok'], 'Anam schedules at most seven days ahead');
        $this->assertNotSame('', $r['error']);
    }

    public function testTheCardRefusesABadMeetingLinkWithoutCallingAnam(): void
    {
        $this->set(['persona_id' => 'persona-abc-123']);
        $this->set(['api_key' => \SecretBox::encrypt('sk-anam-test-0123456789abcdef')]);
        $this->expectException(\CloudAiException::class);
        AnamService::joinMeeting(['meeting_url' => 'ftp://meet.google.com/x']);
    }

    public function testClickingSaveWithTheKeyFieldEmptyKeepsTheStoredKey(): void
    {
        AnamService::save([
            'anam_persona_id' => 'persona-abc-123',
            'anam_api_key' => 'sk-anam-test-0123456789abcdef',
            'anam_max_seconds' => '180',
        ]);
        AnamService::save([
            'anam_persona_id' => 'persona-abc-123',
            'anam_max_seconds' => '300',
        ]);
        $this->assertSame('sk-anam-test-0123456789abcdef', AnamService::apiKey(),
            'an empty password field means "unchanged", not "delete"');
        $this->assertSame(300, AnamService::sessionLimit());
    }
}
