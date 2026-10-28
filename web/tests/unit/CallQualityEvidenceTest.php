<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/CallQualityEvidenceService.php';

/**
 * Call quality is RTCP counters, not audio. These tests pin the arithmetic
 * (8-bit fraction lost), the "two bad reports, not one spike" rule, and the
 * honesty of Greg's prompt: he must never claim to have listened.
 */
final class CallQualityEvidenceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec('DELETE FROM pbx_signaling_events');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->db->exec('DELETE FROM pbx_signaling_events');
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key IN
            ('hep.mode', 'call_quality.fix.testtrunk', 'call_quality.fix.qc_test',
             'greg.enabled', 'greg.auto_fix', 'greg.notice_email')");
        $this->db->exec("DELETE FROM pbx_trunks WHERE trunk_name IN ('testtrunk', 'qc_test', 'qc_backup')");
        $this->db->exec("DELETE FROM pbx_outbound_routes WHERE route_name = 'QC test route'");
        $this->db->exec("DELETE FROM sys_pending_sync WHERE domain = 'outbound_dialplan'");
        $this->db->exec("DELETE FROM pbx_greg_notices WHERE dedupe_key LIKE 'quality:%'");
        $this->db->exec('DELETE FROM pbx_quality_evidence');
    }

    private function setting(string $key, string $value): void
    {
        $this->db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
    }

    private function report(string $callId, int $fraction, int $jitter = 0,
                            string $src = '203.0.113.9:10000',
                            string $dst = '192.0.2.10:10000'): void
    {
        $this->db->prepare(
            "INSERT INTO pbx_signaling_events
                (ts, call_id, method, proto, src, dst, first_line, line,
                 rtcp_fraction_lost, rtcp_lost, rtcp_jitter)
             VALUES (NOW() - INTERVAL 1 MINUTE, ?, 'RTCP', 'rtcp', ?, ?, 'RTCP', 'RTCP', ?, ?, ?)"
        )->execute([$callId, $src, $dst, $fraction, $fraction, $jitter]);
    }

    public function testVerdictNeedsTwoBadReportsToBePoor(): void
    {
        $one = CallQualityEvidenceService::verdict(['worst_fraction' => 26, 'reports' => 1, 'poor_reports' => 1]);
        $this->assertSame('fair', $one['level'], 'a single spike is not sustained loss');
        $this->assertSame(10.16, $one['loss_pct'], 'fraction 26 is ~10 % (26/256)');

        $two = CallQualityEvidenceService::verdict(['worst_fraction' => 26, 'reports' => 4, 'poor_reports' => 2]);
        $this->assertSame('poor', $two['level']);

        $clean = CallQualityEvidenceService::verdict(['worst_fraction' => 0, 'reports' => 6, 'poor_reports' => 0]);
        $this->assertSame('good', $clean['level']);

        $noise = CallQualityEvidenceService::verdict(['worst_fraction' => 2, 'reports' => 6, 'poor_reports' => 0]);
        $this->assertSame('good', $noise['level'], '0.78 % is background noise');
        $fair = CallQualityEvidenceService::verdict(['worst_fraction' => 3, 'reports' => 6, 'poor_reports' => 0]);
        $this->assertSame('fair', $fair['level'], 'above 1 % is degraded');
    }

    public function testAggregationKeepsTheWorstReportAndBothPeers(): void
    {
        $this->report('call-a', 0, 8);
        $this->report('call-a', 26, 96);
        $this->report('call-a', 13, 40);

        $calls = CallQualityEvidenceService::aggregate(CallQualityEvidenceService::rows());
        $this->assertArrayHasKey('call-a', $calls);
        $call = $calls['call-a'];
        $this->assertSame(3, $call['reports']);
        $this->assertSame(26, $call['worst_fraction']);
        $this->assertSame(2, $call['poor_reports'], '26 and 13 are both at/above 5 %');
        $this->assertSame(96, $call['jitter']);
        $this->assertEqualsCanonicalizing(['203.0.113.9', '192.0.2.10'], $call['peers']);
    }

    public function testActiveReportsAPoorCallAndItsTrunk(): void
    {
        $this->db->prepare(
            'INSERT INTO pbx_trunks (trunk_name, title, ip_address) VALUES (?, ?, ?)'
        )->execute(['testtrunk', 'Test Carrier', '203.0.113.9']);
        $this->report('call-a', 26, 96);
        $this->report('call-a', 26, 96);

        $active = CallQualityEvidenceService::active();
        $this->assertCount(1, $active);
        $this->assertSame('poor', $active[0]['verdict']['level']);
        $this->assertSame('testtrunk', $active[0]['trunk']['trunk_name'] ?? null);
    }

    public function testHostOfHandlesPortsAndIpv6(): void
    {
        $this->assertSame('192.0.2.10', CallQualityEvidenceService::hostOf('192.0.2.10:5060'));
        $this->assertSame('2001:db8::1', CallQualityEvidenceService::hostOf('[2001:db8::1]:5060'));
        $this->assertSame('', CallQualityEvidenceService::hostOf(''));
    }

    public function testPromptSaysHeDidNotListenAndNamesTheRule(): void
    {
        $this->setting('hep.mode', 'builtin');
        $this->report('call-a', 26);
        $this->report('call-a', 26);

        $ctx = CallQualityEvidenceService::promptContext();
        $this->assertStringContainsString('did not listen to any audio', $ctx);
        $this->assertStringContainsString('never say you heard a call', $ctx);
        $this->assertStringContainsString('two reports at 5%+', $ctx);
        $this->assertStringContainsString('call-a', $ctx);
        $this->assertStringContainsString('10.16%', $ctx);
    }

    public function testPromptIsEmptyWhenRtcpGoesToARemoteCollector(): void
    {
        $this->setting('hep.mode', 'homer');
        $this->assertFalse(CallQualityEvidenceService::available());
        $this->assertSame('', CallQualityEvidenceService::promptContext());
    }

    public function testEvidenceKeepsTheRtcpProofAndTheSourceTrail(): void
    {
        $this->setting('hep.mode', 'builtin');
        $this->report('call-ev', 26, 96);
        $this->report('call-ev', 26, 240);
        $call = CallQualityEvidenceService::active()[0];

        $id = CallQualityEvidenceService::recordEvidence(
            $call,
            'no_alternative',
            'No outbound route has another trunk behind the lossy one; nothing changed.'
        );
        $this->assertNotNull($id);
        $this->assertSame($id, CallQualityEvidenceService::recordEvidence($call, 'no_alternative', ''),
            'the same call and action is one incident, not one per cron run');

        $trail = CallQualityEvidenceService::evidenceTrail((int) $id);
        $this->assertStringContainsString('Evidence #' . $id, $trail);
        $this->assertStringContainsString('call-ev', $trail);
        $this->assertStringContainsString('203.0.113.9', $trail);
        $this->assertStringContainsString('fraction_lost=26', $trail);
        $this->assertStringContainsString('res_hep_rtcp -> aipbx-signaling -> pbx_signaling_events', $trail);
        $this->assertStringContainsString('No outbound route has another trunk', $trail);
        $this->assertStringContainsString('two reports at 5%', $trail);

        $report = CallQualityEvidenceService::report(24);
        $this->assertStringContainsString('#' . $id, $report);
        $this->assertStringContainsString('call-ev', $report);
    }

    public function testTickDoesNothingWhileGregIsDisabled(): void
    {
        $res = CallQualityEvidenceService::tick();
        $this->assertContains('greg_disabled', $res['skipped']);
        $this->assertSame([], $res['fixed']);
    }

    public function testObserveOnlyKeepsTheProofAndLeavesTheRouteAlone(): void
    {
        $this->setting('greg.enabled', '1');
        $this->setting('greg.auto_fix', '0');
        $this->setting('greg.notice_email', '0');
        $this->setting('hep.mode', 'builtin');
        $this->db->prepare('INSERT INTO pbx_trunks (trunk_name, title, ip_address) VALUES (?, ?, ?)')
            ->execute(['qc_test', 'QC bad carrier', '203.0.113.77']);
        $this->db->prepare('INSERT INTO pbx_trunks (trunk_name, title, ip_address) VALUES (?, ?, ?)')
            ->execute(['qc_backup', 'QC good carrier', '203.0.113.78']);
        $this->db->prepare(
            "INSERT INTO pbx_outbound_routes (route_name, match_pattern, trunks_json, is_active)
             VALUES ('QC test route', '9.', ?, 1)"
        )->execute([json_encode([
            ['trunk_name' => 'qc_test'],
            ['trunk_name' => 'qc_backup'],
        ])]);
        $this->report('call-qc', 26, 96, '203.0.113.77:10000');
        $this->report('call-qc', 26, 96, '203.0.113.77:10000');

        $res = CallQualityEvidenceService::tick();
        $this->assertContains('auto_fix_off', $res['skipped']);
        $this->assertSame([], $res['fixed']);
        $stored = $this->db->query("SELECT trunks_json FROM pbx_outbound_routes WHERE route_name = 'QC test route'")->fetchColumn();
        $this->assertSame(['qc_test', 'qc_backup'], array_column(json_decode((string) $stored, true), 'trunk_name'),
            'observe-only must not reorder the route');
        $action = $this->db->query('SELECT action FROM pbx_quality_evidence ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertSame('observed', $action);
        $notice = $this->db->query("SELECT body FROM pbx_greg_notices WHERE dedupe_key = 'quality:observed:qc_test'")->fetchColumn();
        $this->assertStringContainsString('Evidence #', (string) $notice);
    }

    public function testTickMovesALossyTrunkToTheBackForNewCalls(): void
    {
        $this->setting('greg.enabled', '1');
        $this->setting('greg.auto_fix', '1');
        $this->setting('greg.notice_email', '0');
        $this->setting('hep.mode', 'builtin');
        $this->db->prepare('INSERT INTO pbx_trunks (trunk_name, title, ip_address) VALUES (?, ?, ?)')
            ->execute(['qc_test', 'QC bad carrier', '203.0.113.77']);
        $this->db->prepare('INSERT INTO pbx_trunks (trunk_name, title, ip_address) VALUES (?, ?, ?)')
            ->execute(['qc_backup', 'QC good carrier', '203.0.113.78']);
        $this->db->prepare(
            "INSERT INTO pbx_outbound_routes (route_name, match_pattern, trunks_json, is_active)
             VALUES ('QC test route', '9.', ?, 1)"
        )->execute([json_encode([
            ['trunk_name' => 'qc_test', 'callerid_override' => ''] ,
            ['trunk_name' => 'qc_backup', 'callerid_override' => ''] ,
        ])]);
        $this->report('call-qc', 26, 96, '203.0.113.77:10000');
        $this->report('call-qc', 26, 96, '203.0.113.77:10000');

        $res = CallQualityEvidenceService::tick();
        $this->assertContains('qc_test', $res['fixed'], 'the lossy trunk is demoted');

        $stored = $this->db->query("SELECT trunks_json FROM pbx_outbound_routes WHERE route_name = 'QC test route'")->fetchColumn();
        $names = array_column(json_decode((string) $stored, true), 'trunk_name');
        $this->assertSame(['qc_backup', 'qc_test'], $names, 'new calls try the good trunk first');
        $conf = (string) @file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
        $good = strpos($conf, 'qc_backup');
        $bad = strpos($conf, 'qc_test');
        $this->assertNotFalse($good);
        $this->assertNotFalse($bad);
        $this->assertLessThan($bad, $good, 'the generated dialplan tries the good trunk first');

        $evidence = $this->db->query(
            'SELECT id, action, action_detail, evidence_json FROM pbx_quality_evidence ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('trunk_demoted', $evidence['action']);
        $this->assertStringContainsString('qc_test', $evidence['action_detail']);
        $proof = json_decode((string) $evidence['evidence_json'], true);
        $this->assertNotEmpty($proof['samples']);
        $this->assertSame('qc_test', $proof['trunk']['trunk_name']);
        $notice = $this->db->query(
            "SELECT body FROM pbx_greg_notices WHERE dedupe_key = 'quality:fix:qc_test'"
        )->fetchColumn();
        $this->assertStringContainsString('Evidence #' . $evidence['id'], (string) $notice);
        $this->assertSame('0', (string) $this->db->query(
            "SELECT COUNT(*) FROM sys_pending_sync WHERE domain = 'outbound_dialplan'"
        )->fetchColumn(), 'the applied domain leaves no pending row behind');
    }
}
