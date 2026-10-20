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
        // The relief engineer is tested on its own; keep Greg's persona tests
        // independent of the hour the suite happens to run at.
        $this->set(['enabled' => '1', 'show_avatar' => '1', 'show_tokens' => '1',
                    'notice_email' => '0', 'name' => 'Greg', 'model_source' => 'system',
                    'quiet_hours' => '22:00-07:00', 'relief_enabled' => '0']);
    }

    protected function tearDown(): void
    {
        \GregHandoffService::clear();
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
        $this->assertSame('bigpickle', $s['model_source'], 'Big Pickle is preselected — no key, no setup');
        $this->assertSame(GregService::QUIET_DEFAULT, $s['quiet_hours']);
        $this->assertSame(GregService::DEFAULT_RELIEF, $s['relief_name'], 'the relief engineer is named Mahir Gul by default');
        $this->assertTrue($s['relief_enabled'], 'quiet hours are covered by default');
        $this->assertSame('system', $s['relief_model_source'], 'and he starts on the system chain, not Greg\'s free model');
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

    public function testTheSpellingOfHisNameDoesNotMatter(): void
    {
        foreach (['greg', 'GREG', 'Greg'] as $spelling) {
            $this->set(['name' => $spelling]);
            $this->assertSame('Greg', GregService::settings()['name'], $spelling . ' is still Greg');
            $this->assertSame('Greg', GregService::displayName());
        }
        // A real rename is not touched: only his own name is canonicalised.
        $this->set(['name' => 'Gregory']);
        $this->assertSame('Gregory', GregService::settings()['name']);
        $this->assertSame('Gregory', GregService::canonicalName('  Gregory  '));
    }

    public function testSavingHisNameInLowerCaseStoresGreg(): void
    {
        GregService::save(['greg_name' => 'greg', 'greg_quiet_hours' => '22:00-07:00']);
        $this->assertSame('Greg', GregService::settings()['name']);
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

    public function testReliefSettingsAreSavedAndItsNameIsValidated(): void
    {
        GregService::save([
            'greg_name' => 'Greg',
            'greg_relief_enabled' => '1',
            'greg_relief_name' => 'Mahir Gul',
            'greg_relief_model_source' => 'ling',
        ]);
        $s = GregService::settings();
        $this->assertTrue($s['relief_enabled']);
        $this->assertSame('Mahir Gul', $s['relief_name']);
        $this->assertSame('ling', $s['relief_model_source']);

        try {
            GregService::save(['greg_name' => 'Greg', 'greg_relief_name' => '<script>']);
            $this->fail('a name with markup must be refused');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_voip_engineer.err_relief_name'), $e->getMessage());
        }
        $this->assertSame('Mahir Gul', GregService::settings()['relief_name'], 'nothing was half-saved');
    }

    public function testQuietHoursHandThePhonesToTheReliefEngineerWithItsOwnModel(): void
    {
        $this->set([
            'enabled' => '1', 'name' => 'Greg', 'model_source' => 'bigpickle',
            'relief_enabled' => '1', 'relief_name' => 'Mahir Gul', 'relief_model_source' => 'ling',
            'quiet_hours' => date('H:i', time() - 60) . '-' . date('H:i', time() + 3600),
        ]);
        GregHandoffService::clear();

        $duty = GregService::onDuty();
        $this->assertTrue($duty['relief']);
        $this->assertSame('Mahir Gul', $duty['name']);
        $this->assertSame('ling', $duty['source'], 'the night answers on its own provider');

        $p = GregService::persona();
        $this->assertStringContainsString('Mahir Gul', $p);
        $this->assertStringContainsString('relief VoIP engineer', $p);
        $this->assertStringContainsString('night shift', $p);
        $this->assertStringContainsString('Evidence before theory', $p, 'the judgement is shared');

        $prompt = GregService::promptFor('Is the trunk up?', [['role' => 'user', 'content' => 'Is the trunk up?']]);
        $this->assertStringEndsWith('Mahir Gul:', $prompt,
            'the conversation is signed by the engineer actually on duty');
        $this->assertStringNotContainsString("\nGreg:", $prompt);
    }

    public function testAnUnknownModelSourceFallsBackToTheSystemChain(): void
    {
        GregService::save(['greg_name' => 'Greg', 'greg_model_source' => 'openai-magic']);
        $this->assertSame('bigpickle', GregService::settings()['model_source']);
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
        $this->assertStringContainsString('built-in VoIP Engineer', $p);
        $this->assertStringContainsString('plain language', $p);
        $this->assertStringContainsString('never invent numbers', $p);
        $this->assertStringContainsString('do not nag', $p);

        // The identity the user asked for: the assistant, and a VoIP engineer
        // with twenty-plus years of production telephony behind him.
        $this->assertStringContainsString('the assistant of this AiPBX phone system', $p);
        $this->assertStringContainsString('twenty-plus years', $p);
        $this->assertStringContainsString('Evidence before theory', $p);
        $this->assertStringContainsString('most likely cause, then the runner-up', $p);
        $this->assertStringContainsString('what you cannot know', $p);
        $this->assertStringContainsString('A colleague on their first week', $p);
    }

    public function testThePromptAsksForTheBriefOnceAndUsesItWhenItArrives(): void
    {
        $first = [['role' => 'user', 'content' => 'Is the trunk up?']];
        $later = [
            ['role' => 'user', 'content' => 'Is the trunk up?'],
            ['role' => 'assistant', 'content' => 'Yes.'],
            ['role' => 'user', 'content' => 'And the queue?'],
        ];

        // No brief yet: he asks once, on the first answer, and then gets on
        // with the work instead of nagging.
        $p = GregService::promptFor('Is the trunk up?', $first);
        $this->assertStringContainsString('do not have an onboarding brief', $p);
        $this->assertStringContainsString('onboard me', $p, 'he offers the six questions instead of only a document');
        $this->assertStringContainsString('User: Is the trunk up?', $p);
        $this->assertStringNotContainsString(
            'do not have an onboarding brief',
            GregService::promptFor('And the queue?', $later)
        );

        // With the file in place it is part of every answer and the ask is gone.
        GregBrief::save("# Acme Dental\n\nThe emergency line is extension 700.\n");
        try {
            $p = GregService::promptFor('Is the trunk up?', $first);
            $this->assertStringContainsString('ONBOARDING BRIEF', $p);
            $this->assertStringContainsString('emergency line is extension 700', $p);
            $this->assertStringNotContainsString('do not have an onboarding brief', $p);
            $this->assertStringContainsString(
                'emergency line is extension 700',
                GregService::promptFor('And the queue?', $later)
            );

            // The systems map is the other half of the handover, read with it.
            GregSystems::save("# Systems\n\n## 1. What this PBX is for\nBell trunk, Dentrix screen-pop.\n");
            $p = GregService::promptFor('Is the trunk up?', $first);
            $this->assertStringContainsString('SYSTEMS DOCUMENTATION', $p);
            $this->assertStringContainsString('Dentrix screen-pop', $p);
        } finally {
            GregBrief::remove();
            GregSystems::remove();
        }
        $this->assertFalse(GregBrief::exists());
        $this->assertFalse(GregSystems::exists());
    }

    public function testThePromptCarriesWhatGregCanActuallySee(): void
    {
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'hep.%'");
        $this->db->exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('hep.mode', 'off')");
        $prompt = GregService::promptFor('Why did the call drop?', [
            ['role' => 'user', 'content' => 'Why did the call drop?'],
        ]);
        $this->assertStringContainsString('Observability coverage on this PBX:', $prompt);
        $this->assertStringContainsString('Nothing is captured or queryable', $prompt,
            'with no source, Greg is told to ask for the evidence instead of inventing it');
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'hep.%'");
    }

    public function testThePromptUsesTheDownloadedAsteriskReferenceOnlyWhenItFits(): void
    {
        $fetch = static function (string $url): array {
            return ['ok' => true, 'body' => "---\ntitle: PJSIP\n---\n\nAuthentication\n===\n\n"
                . "A 401 challenge means the endpoint has no matching auth object, or the password is wrong. "                . "Check the auth section, then the registration object. A 403 means the endpoint matched "
                . "but the request was refused by the configuration.\n",
                'error' => '',
            ];
        };
        $history = [['role' => 'user', 'content' => 'PJSIP endpoint gets a 401 on registration']];
        try {
            AsteriskDocsService::sync($fetch);
            $p = GregService::promptFor('PJSIP endpoint gets a 401 on registration', $history);
            $this->assertStringContainsString('Official Asterisk documentation', $p);
            $this->assertStringContainsString('docs.asterisk.org', $p);
            $this->assertStringContainsString('401 challenge', $p);
            $this->assertStringNotContainsString(
                'Official Asterisk documentation',
                GregService::promptFor('Good morning', $history),
                'a greeting must not drag the manual into the prompt'
            );
        } finally {
            AsteriskDocsService::remove();
        }
    }

    public function testTheTwoEngineersAreCounterpartsAndConferByDefault(): void
    {
        $this->set([
            'relief_enabled' => '1', 'relief_name' => 'Mahir Gul',
            'relief_model_source' => 'system', 'model_source' => 'bigpickle',
        ]);
        $this->assertTrue(GregService::settings()['council'], 'the council is on by default');

        $other = GregService::counterpart(['key' => 'greg', 'name' => 'Greg', 'source' => 'bigpickle']);
        $this->assertSame('Mahir Gul', $other['name']);
        $this->assertSame('system', $other['source']);

        $back = GregService::counterpart(['key' => 'relief', 'name' => 'Mahir Gul', 'source' => 'system']);
        $this->assertSame('Greg', $back['name']);
        $this->assertSame('bigpickle', $back['source']);
    }

    public function testTheCouncilCanBeTurnedOffInTheSettings(): void
    {
        $form = [
            'greg_name' => 'Greg', 'greg_quiet_hours' => '22:00-07:00',
            'greg_relief_name' => 'Mahir Gul', 'greg_relief_enabled' => '1',
        ];
        GregService::save($form);
        $this->assertFalse(GregService::settings()['council'], 'an unchecked box turns the council off');

        GregService::save($form + ['greg_council' => '1']);
        $this->assertTrue(GregService::settings()['council']);
    }
}
