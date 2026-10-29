<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregService.php';
require_once __DIR__ . '/../../src/services/GregHandoffService.php';

/**
 * The relief engineer: who has the phones when Greg sleeps, and what is
 * written down when the shift changes.
 *
 * These tests pin the contract: the clock decides, a manual handoff lasts only
 * until the next edge of the quiet window, the handoff lands in AGENTS.md and
 * the captain's log once (not once per message), the administrator's own words
 * in AGENTS.md survive, and no relief engineer means no handoff.
 */
final class GregHandoffServiceTest extends TestCase
{
    private PDO $db;
    private array $session = [];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->session = $_SESSION ?? [];
        $_SESSION = ['user_id' => 1, 'username' => 'tester', 'user_role' => 'admin'];
        $this->settings([
            'enabled' => '1', 'name' => 'Greg', 'model_source' => 'bigpickle',
            'quiet_hours' => '22:00-07:00',
            'relief_enabled' => '1', 'relief_name' => 'Mahir Gul', 'relief_model_source' => 'system',
        ]);
        GregHandoffService::clear();
        @unlink(GregJournal::agentsPath());
        @unlink(GregBrief::path());
        $this->db->exec("DELETE FROM sys_audit_log WHERE domain = 'greg' AND entity_type IN ('handoff', 'onboarding', 'brief')");
    }

    protected function tearDown(): void
    {
        GregHandoffService::clear();
        @unlink(GregJournal::agentsPath());
        @unlink(GregBrief::path());
        $_SESSION = $this->session;
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'greg.%'");
        $this->db->exec("DELETE FROM sys_audit_log WHERE domain = 'greg' AND entity_type IN ('handoff', 'onboarding', 'brief')");
    }

    private function settings(array $values): void
    {
        $st = $this->db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($values as $k => $v) {
            $st->execute(['greg.' . $k, (string) $v]);
        }
    }

    /** A quiet window that contains right now. */
    private function quietNow(): string
    {
        return date('H:i', time() - 60) . '-' . date('H:i', time() + 3600);
    }

    /** A quiet window that starts later, so right now is daytime. */
    private function quietLater(): string
    {
        return date('H:i', time() + 1800) . '-' . date('H:i', time() + 3600);
    }

    // ------------------------------------------------------------- schedule

    public function testTheClockAndTheSettingDecideWhoIsOnDuty(): void
    {
        // "Daytime" must be said, not assumed: setUp's 22:00-07:00 default is
        // the quiet window at night, so the suite used to fail after 22:00.
        $this->settings(['quiet_hours' => $this->quietLater()]);
        $this->assertSame('greg', GregHandoffService::scheduled(), 'daytime is Greg');
        $this->settings(['quiet_hours' => $this->quietNow()]);
        $this->assertSame('relief', GregHandoffService::scheduled(), 'quiet hours are the relief engineer');
        $this->settings(['relief_enabled' => '0']);
        $this->assertSame('greg', GregHandoffService::scheduled(), 'no relief engineer means Greg covers everything');
        $this->settings(['relief_enabled' => '1', 'enabled' => '0']);
        $this->assertSame('greg', GregHandoffService::scheduled(), 'nobody is on duty while Greg is switched off');
    }

    // -------------------------------------------------------------- handoff

    public function testTheHandoffIsWrittenIntoAgentsAndTheCaptainsLog(): void
    {
        // Daytime baseline: Greg has the phones.
        $this->settings(['quiet_hours' => $this->quietLater()]);
        $this->assertNull(GregHandoffService::ensure(), 'the first observation is silent');

        // Night falls.
        $this->settings(['quiet_hours' => $this->quietNow()]);
        $change = GregHandoffService::ensure();
        $this->assertNotNull($change);
        $this->assertSame('Greg', $change['from']);
        $this->assertSame('Mahir Gul', $change['to']);
        $this->assertStringContainsString('quiet hours', $change['reason']);

        $agents = GregJournal::agents();
        $this->assertStringContainsString('## Handoffs', $agents);
        $this->assertStringContainsString('Greg → Mahir Gul', $agents);
        $this->assertStringContainsString('quiet hours', $agents);
        $this->assertSame(1, count(GregJournal::handoffs()));

        $log = GregJournal::recentText(40);
        $this->assertStringContainsString('Handoff: Greg → Mahir Gul', $log);

        $rows = (int) $this->db->query(
            "SELECT COUNT(*) FROM sys_audit_log WHERE entity_type = 'handoff'"
        )->fetchColumn();
        $this->assertSame(1, $rows, 'the handoff is audited');
    }

    public function testTheSameHandoffIsNotWrittenTwice(): void
    {
        $this->settings(['quiet_hours' => $this->quietLater()]);
        GregHandoffService::ensure();
        $this->settings(['quiet_hours' => $this->quietNow()]);
        GregHandoffService::ensure();

        $this->assertNull(GregHandoffService::ensure(), 'the second message must not repeat it');
        $this->assertSame(1, count(GregJournal::handoffs()));
        $this->assertSame(1, (int) $this->db->query(
            "SELECT COUNT(*) FROM sys_audit_log WHERE entity_type = 'handoff'"
        )->fetchColumn());
    }

    public function testTheMorningHandsThePhonesBack(): void
    {
        $this->settings(['quiet_hours' => $this->quietLater()]);
        GregHandoffService::ensure();   // daytime baseline: Greg on duty
        $this->settings(['quiet_hours' => $this->quietNow()]);
        GregHandoffService::ensure();   // night
        $this->settings(['quiet_hours' => $this->quietLater()]);

        $change = GregHandoffService::ensure();   // morning
        $this->assertNotNull($change);
        $this->assertSame('Mahir Gul', $change['from']);
        $this->assertSame('Greg', $change['to']);

        $notes = GregJournal::handoffs();
        $this->assertCount(2, $notes);
        $this->assertStringContainsString('Mahir Gul → Greg', implode("\n", $notes));
    }

    public function testTheAdministratorsOwnWordsInAgentsSurviveTheHandoffs(): void
    {
        GregJournal::saveAgents("# House rules\n\nNever touch the main trunk before 9.\n");
        $this->settings(['quiet_hours' => $this->quietLater()]);
        GregHandoffService::ensure();
        $this->settings(['quiet_hours' => $this->quietNow()]);
        GregHandoffService::ensure();

        $agents = GregJournal::agents();
        $this->assertStringContainsString('Never touch the main trunk before 9.', $agents);
        $this->assertStringContainsString('## Handoffs', $agents);
        $this->assertSame(1, substr_count($agents, '<!-- greg:handoffs -->'));
    }

    // --------------------------------------------------------------- manual

    public function testAManualHandoffOverridesTheScheduleUntilTheNextEdge(): void
    {
        $this->settings(['quiet_hours' => $this->quietLater()]);
        $this->assertSame('greg', GregHandoffService::onDuty());

        GregHandoffService::handOff();
        $this->assertSame('relief', GregHandoffService::onDuty());
        $notes = GregJournal::handoffs();
        $this->assertCount(1, $notes, 'a manual handoff is a handoff and is written down');
        $this->assertStringContainsString('Greg → Mahir Gul (manual handoff)', implode("\n", $notes));
        $state = GregHandoffService::state();
        $this->assertSame('relief', $state['manual']);
        $this->assertGreaterThan(time(), $state['manual_until'],
            'a manual handoff does not last forever');
        $this->assertLessThanOrEqual(time() + 86400, $state['manual_until']);

        GregHandoffService::takeBack();
        $this->assertSame('greg', GregHandoffService::onDuty());
    }

    public function testAnExpiredManualHandoffReleasesThePhonesToTheSchedule(): void
    {
        $this->settings(['quiet_hours' => $this->quietLater()]);
        GregHandoffService::handOff(time() - 1);
        $this->assertSame('greg', GregHandoffService::onDuty(),
            'past its edge, the schedule is back in charge');
    }

    public function testAManualHandoffIsRefusedWithoutAReliefEngineer(): void
    {
        $this->settings(['relief_enabled' => '0']);
        try {
            GregHandoffService::handOff();
            $this->fail('there is nobody to hand over to');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_voip_engineer.err_relief_off'), $e->getMessage());
        }
        $this->assertFileDoesNotExist(GregHandoffService::path());
    }

    public function testTurningTheReliefEngineerOffEndsTheHandoff(): void
    {
        $this->settings(['quiet_hours' => $this->quietLater()]);
        GregHandoffService::handOff();
        $this->assertSame('relief', GregHandoffService::onDuty());

        $this->settings(['relief_enabled' => '0']);
        $this->assertNull(GregHandoffService::ensure());
        $this->assertNull(GregHandoffService::state());
        $this->assertSame('greg', GregHandoffService::onDuty());
    }
}
