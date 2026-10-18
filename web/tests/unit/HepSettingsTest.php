<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/HepSettingsService.php';

/**
 * HEP capture target: Asterisk sends SIP/RTCP to the built-in daemon, a local
 * HOMER, or an external collector. The portal generates hep.conf, so these
 * tests pin the generated file, the validation that stops config injection,
 * and the fact that saving changes the file AND the stored target together.
 */
final class HepSettingsTest extends TestCase
{
    private PDO $db;
    private string $conf;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'hep.%'");
        $this->conf = HepSettingsService::confPath();
        if (is_file($this->conf)) {
            unlink($this->conf);
        }
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'hep.%'");
        if (is_file($this->conf)) {
            unlink($this->conf);
        }
    }

    private function setting(string $key): ?string
    {
        $st = $this->db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function testTheDefaultIsThisPortalsOwnDaemonOnLoopback(): void
    {
        $s = HepSettingsService::settings();
        $this->assertSame('builtin', $s['mode']);
        $this->assertTrue($s['enabled']);
        $this->assertSame('127.0.0.1', $s['host']);
        $this->assertSame(9061, $s['port']);
        $this->assertSame(HepSettingsService::DEFAULT_PASSWORD, $s['password']);
        $this->assertFalse($s['password_set'], 'the default password is not something the admin typed');
        $this->assertSame(1001, $s['capture_id']);
    }

    public function testGeneratedFileCarriesTheTargetAndNeverLiesAboutEnabled(): void
    {
        $on = HepSettingsService::renderConf([
            'mode' => 'builtin', 'enabled' => true, 'host' => '127.0.0.1', 'port' => 9061,
            'password' => 'aipbx', 'capture_id' => 1001,
        ]);
        $this->assertStringContainsString('[general]', $on);
        $this->assertStringContainsString('enabled = yes', $on);
        $this->assertStringContainsString('capture_address = 127.0.0.1:9061', $on);
        $this->assertStringContainsString('capture_password = aipbx', $on);
        $this->assertStringContainsString('capture_id = 1001', $on);
        $this->assertStringContainsString('uuid_type = call-id', $on);

        $off = HepSettingsService::renderConf([
            'mode' => 'off', 'enabled' => false, 'host' => '127.0.0.1', 'port' => 9061,
            'password' => '', 'capture_id' => 1001,
        ]);
        $this->assertStringContainsString('enabled = no', $off);
        $this->assertStringNotContainsString('capture_password', $off);
    }

    public function testApplyingALocalHomerTargetWritesTheFileAndStoresIt(): void
    {
        $res = HepSettingsService::save([
            'hep_mode' => 'homer',
            'hep_host' => '203.0.113.9',   // ignored for a LOCAL collector
            'hep_port' => '',              // default 9060
            'hep_password' => 'homer-secret',
            'hep_capture_id' => '2002',
            'hep_homer_url' => 'http://127.0.0.1:9080',
        ]);
        $this->assertArrayHasKey('message', $res);

        $s = HepSettingsService::settings();
        $this->assertSame('homer', $s['mode']);
        $this->assertSame('127.0.0.1', $s['host'], 'a local collector is reached on loopback, whatever was typed');
        $this->assertSame(9060, $s['port']);
        $this->assertSame(2002, $s['capture_id']);
        $this->assertSame('http://127.0.0.1:9080', $s['homer_url']);

        $this->assertSame('sb1:', substr((string) $this->setting('hep.password'), 0, 4), 'the HEP secret is encrypted at rest');
        $this->assertFileExists($this->conf);
        $live = HepSettingsService::currentFile();
        $this->assertTrue($live['exists']);
        $this->assertTrue($live['enabled']);
        $this->assertSame('127.0.0.1:9060', $live['capture_address']);
        $this->assertSame('2002', $live['capture_id']);
        $this->assertTrue($live['password_set']);
        $this->assertStringContainsString('HOMER', (string) file_get_contents($this->conf));
    }

    public function testAnExternalCollectorKeepsItsOwnAddress(): void
    {
        HepSettingsService::save([
            'hep_mode' => 'external', 'hep_host' => 'homer.example.net', 'hep_port' => '9062',
            'hep_password' => 'shared', 'hep_capture_id' => '7',
        ]);
        $s = HepSettingsService::settings();
        $this->assertSame('external', $s['mode']);
        $this->assertSame('homer.example.net', $s['host']);
        $this->assertSame(9062, $s['port']);
        $this->assertStringContainsString('capture_address = homer.example.net:9062', (string) file_get_contents($this->conf));
        $this->assertStringContainsString('NOT encryption', (string) file_get_contents($this->conf));
    }

    public function testTurningCaptureOffStillWritesAnHonestFile(): void
    {
        HepSettingsService::save(['hep_mode' => 'off']);
        $live = HepSettingsService::currentFile();
        $this->assertTrue($live['exists']);
        $this->assertFalse($live['enabled']);
        $this->assertTrue(HepSettingsService::settings()['enabled'] === false);
    }

    public function testValuesThatWouldInjectConfigurationAreRefusedBeforeAnyWrite(): void
    {
        $bad = [
            [['hep_mode' => 'external', 'hep_host' => "evil\nenabled = no", 'hep_port' => '9060'], 'ai_echo.hep_err_host'],
            [['hep_mode' => 'external', 'hep_host' => 'good.example', 'hep_port' => '99999'], 'ai_echo.hep_err_port'],
            [['hep_mode' => 'external', 'hep_host' => 'good.example', 'hep_port' => 'abc'], 'ai_echo.hep_err_port'],
            [['hep_mode' => 'homer', 'hep_password' => "secret\ncapture_address = 1.2.3.4:1"], 'ai_echo.hep_err_password'],
            [['hep_mode' => 'homer', 'hep_password' => 'has space'], 'ai_echo.hep_err_password'],
            [['hep_mode' => 'homer', 'hep_capture_id' => '0'], 'ai_echo.hep_err_capture_id'],
            [['hep_mode' => 'homer', 'hep_capture_id' => 'x'], 'ai_echo.hep_err_capture_id'],
            [['hep_mode' => 'homer', 'hep_homer_url' => 'javascript:alert(1)'], 'ai_echo.hep_err_url'],
            [['hep_mode' => 'bogus'], 'ai_echo.hep_err_mode'],
        ];
        foreach ($bad as [$post, $key]) {
            try {
                HepSettingsService::save($post);
                $this->fail("save should have refused: {$key}");
            } catch (CloudAiException $e) {
                $this->assertSame(t($key), $e->getMessage(), $key);
            }
        }
        $this->assertFileDoesNotExist($this->conf, 'a refused save must not create or touch hep.conf');
        $this->assertNull($this->setting('hep.mode'), 'and must not store anything');
    }

    public function testPasswordCanBeChangedWithoutTouchingTheTarget(): void
    {
        HepSettingsService::save(['hep_mode' => 'homer', 'hep_password' => 'first']);
        HepSettingsService::save(['hep_mode' => 'homer', 'hep_password' => 'second']);
        $this->assertStringContainsString('capture_password = second', (string) file_get_contents($this->conf));
        $this->assertSame('second', HepSettingsService::settings()['password']);
    }

    public function testAnOffTargetCannotBeProbed(): void
    {
        HepSettingsService::save(['hep_mode' => 'off']);
        $probe = HepSettingsService::probe(HepSettingsService::settings());
        $this->assertFalse($probe['ok']);
        $this->assertSame(t('ai_echo.hep_probe_off'), $probe['message']);
    }

    public function testTheProbeMessageNamesTheTargetItCouldNotReach(): void
    {
        $probe = HepSettingsService::probe(['enabled' => true, 'host' => '127.0.0.1', 'port' => 1]);
        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('127.0.0.1:1', $probe['message'], 'placeholders must be filled, not shown as %s');
        $this->assertStringNotContainsString('%s', $probe['message']);
    }

    public function testDescribeIsTheOneLineForTheAuditLogAndGreg(): void
    {
        $this->assertSame('HOMER → 127.0.0.1:9060', HepSettingsService::describe([
            'mode' => 'homer', 'enabled' => true, 'host' => '127.0.0.1', 'port' => 9060,
        ]));
        $this->assertSame('off', HepSettingsService::describe(['mode' => 'off', 'enabled' => false]));
    }
}
