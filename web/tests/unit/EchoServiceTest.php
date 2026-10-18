<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/EchoService.php';

/**
 * Echo as its own AI section: the keys it stores (secret encrypted, never
 * shown back), the honest "not configured" state, and the context block Greg
 * reads. No test here talks to Sipfront — the network path is exercised on the
 * live page with deliberately wrong keys, where a 401 is the proof.
 */
final class EchoServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'echo.%'");
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'echo.%'");
    }

    private function setting(string $key): ?string
    {
        $st = $this->db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function testOffAndUnconfiguredUntilAnAdministratorSetsItUp(): void
    {
        $s = EchoService::settings();
        $this->assertFalse($s['enabled']);
        $this->assertFalse($s['configured']);
        $this->assertFalse($s['secret_set']);
        $this->assertSame(0, $s['test_id']);
        $this->assertSame('', $s['public_key']);
        $this->assertSame('', EchoService::promptContext(), 'off means Greg gets no Echo context');
    }

    public function testTheSecretIsStoredEncryptedAndNeverComesBack(): void
    {
        EchoService::save([
            'echo_enabled' => '1',
            'echo_public_key' => 'pk_test_123456',
            'echo_secret' => 'super-secret-value',
            'echo_test_id' => '42',
        ]);
        $stored = $this->setting('echo.sipfront_secret');
        $this->assertNotNull($stored);
        $this->assertStringStartsWith('sb1:', $stored, 'the secret is encrypted at rest');
        $this->assertStringNotContainsString('super-secret-value', $stored);

        $s = EchoService::settings();
        $this->assertTrue($s['configured']);
        $this->assertTrue($s['secret_set']);
        $this->assertSame(42, $s['test_id']);
        $this->assertSame('••••3456', $s['public_key_masked']);
        $this->assertArrayNotHasKey('secret', $s, 'the settings array must never carry the secret');
        $this->assertSame('super-secret-value', SipfrontClient::secret(), 'but the client can decrypt it');
    }

    public function testAnEmptySecretFieldKeepsTheStoredKey(): void
    {
        EchoService::save(['echo_public_key' => 'pk_a', 'echo_secret' => 'first-secret']);
        $this->assertTrue(EchoService::settings()['configured']);
        EchoService::save(['echo_public_key' => 'pk_b', 'echo_secret' => '']);
        $s = EchoService::settings();
        $this->assertTrue($s['secret_set'], 'leaving the field empty must not erase the key');
        $this->assertSame('first-secret', SipfrontClient::secret());
        $this->assertSame('pk_b', SipfrontClient::publicKey());
    }

    public function testItRejectsAKeyWithSpacesAndANonNumericTestId(): void
    {
        try {
            EchoService::save(['echo_public_key' => 'pk with space', 'echo_secret' => 'x']);
            $this->fail('a public key with whitespace must be refused');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_echo.err_public'), $e->getMessage());
        }
        try {
            EchoService::save(['echo_public_key' => 'pk_ok', 'echo_secret' => 'x', 'echo_test_id' => 'abc']);
            $this->fail('a non-numeric test ID must be refused');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_echo.err_test_id'), $e->getMessage());
        }
        $this->assertNull($this->setting('echo.sipfront_public_key'), 'nothing was written by the refused saves');
    }

    public function testSavingWritesAnAuditRow(): void
    {
        $before = (int) $this->db->query("SELECT COUNT(*) FROM sys_audit_log WHERE domain = 'echo'")->fetchColumn();
        $res = EchoService::save(['echo_enabled' => '1', 'echo_public_key' => 'pk_x', 'echo_secret' => 's']);
        $this->assertArrayHasKey('message', $res);
        $after = (int) $this->db->query("SELECT COUNT(*) FROM sys_audit_log WHERE domain = 'echo'")->fetchColumn();
        $this->assertSame($before + 1, $after);
    }

    public function testGregContextSaysHonestlyWhenSipfrontIsNotConfigured(): void
    {
        EchoService::save(['echo_enabled' => '1', 'echo_public_key' => '', 'echo_secret' => '']);
        $ctx = EchoService::promptContext();
        $this->assertStringContainsString('Echo (SIP correlation)', $ctx);
        $this->assertStringContainsString('not configured', $ctx);
        $this->assertStringNotContainsString('Recent Sipfront runs', $ctx);
    }

    public function testUnconfiguredCallsFailWithTheLocalizedReason(): void
    {
        $this->assertSame(['runs' => [], 'error' => null], EchoService::recentRuns());
        try {
            SipfrontClient::probe();
            $this->fail('probe without keys must not pretend to work');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_echo.not_configured'), $e->getMessage());
        }
        try {
            EchoService::runTest();
            $this->fail('running a test without a test ID must be refused');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_echo.err_test_id'), $e->getMessage());
        }
    }

    public function testRunRowsAreNormalisedForThePageAndForGreg(): void
    {
        $line = EchoService::formatRun([
            'test_name' => 'Carrier inbound',
            'started_at' => '2026-10-18 14:02:00',
            'is_failed' => 1,
            'session_id' => 'abc123',
        ]);
        $this->assertStringContainsString('Carrier inbound', $line);
        $this->assertStringContainsString('failed', $line);
        $this->assertStringContainsString('abc123', $line);

        $line2 = EchoService::formatRun(['id' => 'r9', 'status' => 'passed']);
        $this->assertStringContainsString('passed', $line2);
        $this->assertStringContainsString('r9', $line2);
    }
}
