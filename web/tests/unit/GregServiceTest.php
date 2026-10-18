<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregService.php';

/**
 * Greg, the AI VoIP Engineer: the gate (off until enabled), the rename, the
 * model-source choice, and the "inform, do not annoy" notice rules — dedupe
 * inside six hours, mail only for warn/critical, never inside quiet hours.
 */
final class GregServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec('DELETE FROM pbx_greg_notices');
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'greg.%'");
        // Enabled, in-app only: notice tests must never reach the mailer.
        $this->set(['enabled' => '1', 'show_avatar' => '1', 'show_tokens' => '1',
                    'notice_email' => '0', 'name' => 'Greg', 'model_source' => 'system',
                    'quiet_hours' => '22:00-07:00']);
    }

    protected function tearDown(): void
    {
        $this->db->exec('DELETE FROM pbx_greg_notices');
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'greg.%'");
    }

    private function set(array $values): void
    {
        $st = $this->db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($values as $k => $v) {
            $st->execute(['greg.' . $k, (string) $v]);
        }
    }

    private function notices(): array
    {
        return $this->db->query('SELECT * FROM pbx_greg_notices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------- gate

    public function testOffUntilAnAdministratorEnablesHim(): void
    {
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'greg.%'");
        $s = GregService::settings();
        $this->assertFalse($s['enabled'], 'Greg must be off by default');
        $this->assertFalse(GregService::avatarVisible());
        $this->assertSame('Greg', $s['name']);
        $this->assertSame('system', $s['model_source']);
        $this->assertSame(GregService::QUIET_DEFAULT, $s['quiet_hours']);
        $this->assertSame(['skipped' => 'disabled'], GregService::notify('warn', 'x', 'y'));
    }

    public function testAvatarNeedsBothEnabledAndShown(): void
    {
        $this->assertTrue(GregService::avatarVisible());
        $this->set(['show_avatar' => '0']);
        $this->assertFalse(GregService::avatarVisible());
        $this->set(['show_avatar' => '1', 'enabled' => '0']);
        $this->assertFalse(GregService::avatarVisible(), 'disabled means no avatar, whatever the box says');
    }

    public function testDisplayNameFallsBackToTheDefaultAndFollowsTheRename(): void
    {
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key = 'greg.name'");
        $this->assertSame(t('greg.default_name'), GregService::displayName());
        GregService::save(['greg_enabled' => '1', 'greg_name' => 'Ada']);
        $this->assertSame('Ada', GregService::displayName());
    }

    // ----------------------------------------------------------- settings

    public function testSaveRejectsANameAndQuietWindowItCannotUse(): void
    {
        try {
            GregService::save(['greg_name' => '<script>']);
            $this->fail('a name with markup must be refused');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_voip_engineer.err_name'), $e->getMessage());
        }
        try {
            GregService::save(['greg_quiet_hours' => '10pm-7am']);
            $this->fail('an unparseable quiet window must be refused, not silently dropped');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_voip_engineer.err_quiet'), $e->getMessage());
        }
        $this->assertSame('Greg', GregService::displayName(), 'nothing was half-saved');
    }

    public function testSavePersistsEveryChoiceAndWritesTheAuditRow(): void
    {
        $before = (int) $this->db->query("SELECT COUNT(*) FROM sys_audit_log WHERE domain = 'greg'")->fetchColumn();
        $res = GregService::save([
            'greg_enabled' => '1',
            'greg_name' => 'Ada',
            'greg_model_source' => 'ling',
            'greg_show_avatar' => '',
            'greg_show_tokens' => '1',
            'greg_notice_email' => '1',
            'greg_quiet_hours' => '23:00-06:30',
        ]);
        $this->assertArrayHasKey('message', $res);
        $s = GregService::settings();
        $this->assertTrue($s['enabled']);
        $this->assertSame('Ada', $s['name']);
        $this->assertSame('ling', $s['model_source']);
        $this->assertFalse($s['show_avatar']);
        $this->assertTrue($s['show_tokens']);
        $this->assertTrue($s['notice_email']);
        $this->assertSame('23:00-06:30', $s['quiet_hours']);
        $after = (int) $this->db->query("SELECT COUNT(*) FROM sys_audit_log WHERE domain = 'greg'")->fetchColumn();
        $this->assertSame($before + 1, $after, 'every settings change is roll-back-tracked in the audit log');
    }

    public function testAnUnknownModelSourceFallsBackToTheSystemChain(): void
    {
        GregService::save(['greg_name' => 'Greg', 'greg_model_source' => 'openai-magic']);
        $this->assertSame('system', GregService::settings()['model_source']);
    }

    // -------------------------------------------------------- quiet hours

    public function testQuietHoursCoverTheWindowAndCrossMidnight(): void
    {
        $this->set(['quiet_hours' => '22:00-07:00']);
        $at = fn(string $h) => strtotime('2026-01-15 ' . $h . ':00');
        $this->assertTrue(GregService::quietHoursNow($at('23:30')));
        $this->assertTrue(GregService::quietHoursNow($at('03:00')));
        $this->assertTrue(GregService::quietHoursNow($at('22:00')), 'the window starts inside');
        $this->assertFalse(GregService::quietHoursNow($at('07:00')), 'and ends outside');
        $this->assertFalse(GregService::quietHoursNow($at('12:00')));

        $this->set(['quiet_hours' => '09:00-17:00']);
        $this->assertTrue(GregService::quietHoursNow($at('10:00')));
        $this->assertFalse(GregService::quietHoursNow($at('08:59')));
        $this->assertFalse(GregService::quietHoursNow($at('17:00')));
    }

    // ------------------------------------------------------------ notices

    public function testOneNoticePerProblemPerSixHours(): void
    {
        $first = GregService::notify('warn', 'Disk', '/ 97 % full', 'health:disk');
        $this->assertArrayHasKey('id', $first);
        $second = GregService::notify('warn', 'Disk', '/ 97 % full', 'health:disk');
        $this->assertSame(['deduped' => true], $second, 'a repeating fault informs once, it does not nag');
        $this->assertCount(1, $this->notices());

        // A different problem is a different notice.
        $this->assertArrayHasKey('id', GregService::notify('critical', 'Asterisk', 'down', 'health:asterisk'));
        $this->assertCount(2, $this->notices());
    }

    public function testAnUnknownSeverityIsTreatedAsWarning(): void
    {
        GregService::notify('shout', 'Mail queue', '42 stuck', 'health:mail');
        $this->assertSame('warn', $this->notices()[0]['severity']);
    }

    public function testInAppOnlyInsideQuietHours(): void
    {
        $this->set(['quiet_hours' => '00:00-23:59', 'notice_email' => '1']);
        GregService::notify('critical', 'Asterisk', 'not answering', 'health:asterisk');
        $n = $this->notices()[0];
        $this->assertNull($n['emailed_at'], 'quiet hours mean in-app only — never a 3 a.m. e-mail');
        $this->assertNotNull($n['title']);
    }

    public function testInfoStaysInThePanelEvenOutsideQuietHours(): void
    {
        $this->set(['quiet_hours' => '22:00-07:00', 'notice_email' => '1']);
        GregService::notify('info', 'Backup', 'ran', 'backup:ran');
        $this->assertNull($this->notices()[0]['emailed_at'], 'info is in-app only');
    }

    public function testSeverityIsClampedAndLongTextTruncated(): void
    {
        GregService::notify('warn', str_repeat('T', 400), 'body', str_repeat('k', 200));
        $n = $this->notices()[0];
        $this->assertLessThanOrEqual(150, mb_strlen($n['title']));
        $this->assertLessThanOrEqual(64, mb_strlen($n['dedupe_key']));
    }

    public function testReadStateDrivesTheBadge(): void
    {
        $this->assertSame(0, GregService::unreadCount());
        GregService::notify('info', 'One', 'b', 'k1');
        GregService::notify('warn', 'Two', 'b', 'k2');
        $this->assertSame(2, GregService::unreadCount());
        GregService::markAllRead();
        $this->assertSame(0, GregService::unreadCount());
        $recent = GregService::recentNotices();
        $this->assertCount(2, $recent);
        $this->assertArrayHasKey('emailed_at', $recent[0], 'the panel shows whether the e-mail actually fired');
        $this->assertNotNull($recent[0]['read_at']);
    }

    // --------------------------------------------------------------- chat

    public function testChatRefusesAnEmptyQuestionAndAnswersHonestlyWhenOff(): void
    {
        $this->assertSame(t('greg.err_empty'), GregService::chatSend('   ')['error']);
        $this->set(['enabled' => '0']);
        $res = GregService::chatSend('Is the trunk up?');
        $this->assertFalse($res['success']);
        $this->assertSame(t('greg.err_disabled'), $res['error']);
    }

    public function testPersonaCarriesTheRenamedIdentityAndTheHouseRules(): void
    {
        $this->set(['name' => 'Ada']);
        $p = GregService::persona();
        $this->assertStringContainsString('Ada (VoIPMonk)', $p);
        $this->assertStringContainsString('plain language', $p);
        $this->assertStringContainsString('never invent numbers', $p);
        $this->assertStringContainsString('do not nag', $p);
    }
}
