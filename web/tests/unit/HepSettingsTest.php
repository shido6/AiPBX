<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/HepSettingsService.php';

/**
 * Correlation assistance: Asterisk sends SIP/RTCP to the built-in daemon, a
 * local HOMER or VoIPmonitor, or an external collector. The portal generates
 * hep.conf, so these tests pin the generated file, the validation that stops
 * config injection, and the fact that saving changes the file AND the stored
 * target together.
 */
final class HepSettingsTest extends TestCase
{
    private PDO $db;
    private string $conf;
    private string $forward;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'hep.%'");
        $this->conf = HepSettingsService::confPath();
        $this->forward = HepSettingsService::forwardPath();
        if (is_file($this->conf)) {
            unlink($this->conf);
        }
        if (is_file($this->forward)) {
            unlink($this->forward);
        }
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'hep.%'");
        if (is_file($this->conf)) {
            unlink($this->conf);
        }
        if (is_file($this->forward)) {
            unlink($this->forward);
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
            'hep_ui_url' => 'http://127.0.0.1:9080',
        ]);
        $this->assertArrayHasKey('message', $res);

        $s = HepSettingsService::settings();
        $this->assertSame('homer', $s['mode']);
        $this->assertSame('127.0.0.1', $s['host'], 'a local collector is reached on loopback, whatever was typed');
        $this->assertSame(9060, $s['port']);
        $this->assertSame(2002, $s['capture_id']);
        $this->assertSame('http://127.0.0.1:9080', $s['ui_url']);
        $this->assertSame('homer', $s['provider']);

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

    public function testVoIPmonitorIsItsOwnTargetNotAnAliasForHomer(): void
    {
        HepSettingsService::save([
            'hep_mode' => 'voipmonitor',
            'hep_port' => '',                       // default 9060
            'hep_capture_id' => '3003',
            'hep_ui_url' => 'http://voipmonitor.local:8080',
        ]);

        $s = HepSettingsService::settings();
        $this->assertSame('voipmonitor', $s['mode']);
        $this->assertSame('127.0.0.1', $s['host']);
        $this->assertSame(HepSettingsService::VOIPMONITOR_PORT, $s['port']);
        $this->assertSame('voipmonitor', $s['provider']);
        $this->assertSame('http://voipmonitor.local:8080', $s['ui_url']);
        $this->assertSame('VoIPmonitor → 127.0.0.1:9060', HepSettingsService::describe($s));

        $live = HepSettingsService::currentFile();
        $this->assertSame('127.0.0.1:9060', $live['capture_address']);
        $conf = (string) file_get_contents($this->conf);
        $this->assertStringContainsString('VoIPmonitor', $conf);
        $this->assertStringContainsString('hep_bind_port = 9060', $conf);
        $this->assertStringContainsString('capture_address = 127.0.0.1:9060', $conf);
        $this->assertStringContainsString('hep = yes', HepSettingsService::voipmonitorConfig());
        $this->assertStringContainsString('hep_bind_port = 9060', HepSettingsService::voipmonitorConfig());
        // In both mode HOMER already holds 9060; the snippet follows the field.
        $this->assertStringContainsString('hep_bind_port = 9062', HepSettingsService::voipmonitorConfig(9062));
    }

    public function testAnOldHomerUrlIsReadAndMigratedToTheNeutralKey(): void
    {
        $st = $this->db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
        $st->execute(['hep.homer_url', 'http://old-homer.local:9080']);

        $this->assertSame('http://old-homer.local:9080', HepSettingsService::settings()['ui_url']);

        HepSettingsService::save(['hep_mode' => 'homer']); // no URL field: keep the stored one
        $this->assertSame('http://old-homer.local:9080', HepSettingsService::settings()['ui_url']);
        $this->assertSame('http://old-homer.local:9080', $this->setting('hep.ui_url'));
        $this->assertNull($this->setting('hep.homer_url'), 'the next save migrates the legacy key');
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
            [['hep_mode' => 'homer', 'hep_ui_url' => 'javascript:alert(1)'], 'ai_echo.hep_err_url'],
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

    public function testTheHealthCheckWatchesOnlyConfiguredExternalCollectors(): void
    {
        $this->assertSame([], HepSettingsService::monitoredTargets(['enabled' => true, 'mode' => 'builtin']));
        $this->assertSame([], HepSettingsService::monitoredTargets(['enabled' => true, 'mode' => 'off']));
        $this->assertSame([], HepSettingsService::monitoredTargets(['enabled' => false, 'mode' => 'homer', 'host' => '10.0.0.5']));

        $one = HepSettingsService::monitoredTargets([
            'enabled' => true, 'mode' => 'homer', 'host' => '10.0.0.5', 'port' => 9060,
            'ui_url' => 'http://10.0.0.5:9080',
        ]);
        $this->assertCount(1, $one);
        $this->assertSame('HOMER', $one[0]['label']);
        $this->assertSame('10.0.0.5', $one[0]['host']);
        $this->assertSame('http://10.0.0.5:9080', $one[0]['ui_url']);

        $both = HepSettingsService::monitoredTargets([
            'enabled' => true, 'mode' => 'both',
            'homer_host' => '127.0.0.1', 'homer_port' => 9060, 'homer_ui_url' => '',
            'voipmonitor_host' => '10.0.0.6', 'voipmonitor_port' => 9062, 'voipmonitor_ui_url' => 'http://10.0.0.6:8080',
        ]);
        $this->assertSame(['HOMER', 'VoIPmonitor'], array_column($both, 'label'));
    }

    public function testHealthVerdictUsesTheWorstTargetAndNeverAlarmsOnUnknown(): void
    {
        [$status, $message] = HepSettingsService::healthVerdict([
            ['label' => 'HOMER', 'status' => 'ok', 'detail' => 'answers'],
        ]);
        $this->assertSame('ok', $status);
        $this->assertSame('HOMER: answers', $message);

        [$status] = HepSettingsService::healthVerdict([
            ['label' => 'HOMER', 'status' => 'ok', 'detail' => 'answers'],
            ['label' => 'VoIPmonitor', 'status' => 'warn', 'detail' => 'HTTP 503'],
        ]);
        $this->assertSame('warn', $status, 'one bad collector must not hide behind one good one');

        [$status] = HepSettingsService::healthVerdict([
            ['label' => 'HOMER', 'status' => 'warn', 'detail' => 'HTTP 503'],
            ['label' => 'VoIPmonitor', 'status' => 'fail', 'detail' => 'no answer'],
        ]);
        $this->assertSame('fail', $status);

        [$status] = HepSettingsService::healthVerdict([
            ['label' => 'HOMER', 'status' => 'info', 'detail' => 'no UI address'],
        ]);
        $this->assertSame('info', $status, 'a UDP-only collector must not alarm every 15 minutes');

        [$status, $message] = HepSettingsService::healthVerdict([]);
        $this->assertSame('off', $status);
        $this->assertSame(t('health.msg_hep_off'), $message);
    }

    public function testLastHealthIsNullBeforeTheFirstCheck(): void
    {
        $this->db->exec("DELETE FROM health_checks WHERE check_key = 'hep_collector'");
        $this->assertNull(HepSettingsService::lastHealth());
    }

    public function testTheProbeMessageNamesTheTargetItCouldNotReach(): void
    {
        $probe = HepSettingsService::probe(['enabled' => true, 'host' => '127.0.0.1', 'port' => 1]);
        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('127.0.0.1:1', $probe['message'], 'placeholders must be filled, not shown as %s');
        $this->assertStringNotContainsString('%s', $probe['message']);
    }

    public function testBothWritesTheDaemonTargetAndTheForwardList(): void
    {
        HepSettingsService::save([
            'hep_mode' => 'both',
            'hep_homer_host' => '127.0.0.1',
            'hep_homer_port' => '9060',
            'hep_homer_ui_url' => 'http://127.0.0.1:9080',
            'hep_voipmonitor_host' => '127.0.0.1',
            'hep_voipmonitor_port' => '9062',
            'hep_voipmonitor_ui_url' => 'http://127.0.0.1:8080',
            'hep_capture_id' => '4004',
        ]);

        $s = HepSettingsService::settings();
        $this->assertSame('both', $s['mode']);
        $this->assertSame('127.0.0.1', $s['host']);
        $this->assertSame(9061, $s['port'], 'Asterisk talks to the daemon, not to a collector');
        $this->assertSame(9060, $s['form_port'], 'the single-target field keeps a collector port for a later switch');
        $this->assertCount(2, $s['targets']);
        $this->assertSame('HOMER', $s['targets'][0]['label']);
        $this->assertSame(9060, $s['targets'][0]['port']);
        $this->assertSame('VoIPmonitor', $s['targets'][1]['label']);
        $this->assertSame(9062, $s['targets'][1]['port']);
        $this->assertSame(
            'HOMER → 127.0.0.1:9060 + VoIPmonitor → 127.0.0.1:9062',
            HepSettingsService::describe($s));

        $live = HepSettingsService::currentFile();
        $this->assertSame('127.0.0.1:9061', $live['capture_address']);
        $conf = (string) file_get_contents($this->conf);
        $this->assertStringContainsString('HOMER → 127.0.0.1:9060', $conf);
        $this->assertStringContainsString('forwards an unchanged copy', $conf);

        $this->assertFileExists($this->forward);
        $doc = json_decode((string) file_get_contents($this->forward), true);
        $this->assertIsArray($doc);
        $this->assertSame(1, $doc['version']);
        $this->assertSame([
            ['name' => 'homer', 'host' => '127.0.0.1', 'port' => 9060],
            ['name' => 'voipmonitor', 'host' => '127.0.0.1', 'port' => 9062],
        ], $doc['targets']);
    }

    public function testLeavingBothRemovesTheForwardList(): void
    {
        HepSettingsService::save([
            'hep_mode' => 'both',
            'hep_homer_port' => '9060',
            'hep_voipmonitor_port' => '9062',
        ]);
        $this->assertFileExists($this->forward);

        HepSettingsService::save(['hep_mode' => 'homer', 'hep_port' => '9060']);
        $this->assertFileDoesNotExist($this->forward, 'a stale list would keep sending copies');
        $s = HepSettingsService::settings();
        $this->assertSame('homer', $s['mode']);
        $this->assertSame('127.0.0.1:9060', HepSettingsService::currentFile()['capture_address']);
    }

    public function testBothMayTargetCollectorsOnAnotherHost(): void
    {
        HepSettingsService::save([
            'hep_mode' => 'both',
            'hep_homer_host' => 'homer.example.net',
            'hep_homer_port' => '9060',
            'hep_voipmonitor_host' => 'vm.example.net',
            'hep_voipmonitor_port' => '9062',
        ]);

        $s = HepSettingsService::settings();
        $this->assertSame('homer.example.net', $s['targets'][0]['host']);
        $this->assertSame('vm.example.net', $s['targets'][1]['host']);
        $this->assertStringContainsString(
            'HOMER → homer.example.net:9060 + VoIPmonitor → vm.example.net:9062',
            HepSettingsService::describe($s));

        $doc = json_decode((string) file_get_contents($this->forward), true);
        $this->assertSame('homer.example.net', $doc['targets'][0]['host']);
        $this->assertSame('vm.example.net', $doc['targets'][1]['host']);
        $this->assertStringContainsString('another trusted', (string) file_get_contents($this->conf));
    }

    public function testBothRefusesTheSameHostAndPortBeforeWritingAnything(): void
    {
        try {
            HepSettingsService::save([
                'hep_mode' => 'both',
                'hep_homer_host' => '127.0.0.1',
                'hep_homer_port' => '9060',
                'hep_voipmonitor_host' => '127.0.0.1',
                'hep_voipmonitor_port' => '9060',
            ]);
            $this->fail('save should have refused two collectors on one address');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_echo.hep_err_same_target'), $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->conf);
        $this->assertFileDoesNotExist($this->forward);
        $this->assertNull($this->setting('hep.mode'));
    }

    public function testBothRejectsAnInvalidCollectorAddress(): void
    {
        $this->expectException(CloudAiException::class);
        $this->expectExceptionMessage(t('ai_echo.hep_err_host'));
        HepSettingsService::save([
            'hep_mode' => 'both',
            'hep_homer_host' => "evil\nport = 1",
            'hep_homer_port' => '9060',
            'hep_voipmonitor_port' => '9062',
        ]);
    }

    public function testBothWithAnInvalidUiIsRefusedBeforeTheForwardList(): void
    {
        try {
            HepSettingsService::save([
                'hep_mode' => 'both',
                'hep_homer_port' => '9060',
                'hep_voipmonitor_port' => '9062',
                'hep_voipmonitor_ui_url' => 'javascript:alert(1)',
            ]);
            $this->fail('save should have refused a non-http UI address');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_echo.hep_err_url'), $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->forward);
    }

    public function testSwitchingToBuiltinAlwaysUsesTheDaemonsPort(): void
    {
        HepSettingsService::save(['hep_mode' => 'homer', 'hep_port' => '9060']);
        HepSettingsService::save(['hep_mode' => 'builtin', 'hep_port' => '9060']);
        $this->assertSame('127.0.0.1:9061', HepSettingsService::currentFile()['capture_address']);
    }

    public function testDescribeIsTheOneLineForTheAuditLogAndGreg(): void
    {
        $this->assertSame('HOMER → 127.0.0.1:9060', HepSettingsService::describe([
            'mode' => 'homer', 'enabled' => true, 'host' => '127.0.0.1', 'port' => 9060,
        ]));
        $this->assertSame('VoIPmonitor → 127.0.0.1:9060', HepSettingsService::describe([
            'mode' => 'voipmonitor', 'enabled' => true, 'host' => '127.0.0.1', 'port' => 9060,
        ]));
        $this->assertSame('off', HepSettingsService::describe(['mode' => 'off', 'enabled' => false]));
    }
}
