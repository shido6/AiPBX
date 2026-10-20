<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregObservabilityService.php';

/**
 * The gauge behind Greg's evidence: a source that is installed and reachable
 * fills its share; a configured but unreachable collector fills little; a
 * missing one fills nothing and is named in the prompt as something he cannot
 * read. The arithmetic is pure, so no collector or network is needed.
 */
final class GregObservabilityServiceTest extends TestCase
{
    private array $session = [];

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? [];
        $_SESSION = ['user_id' => 1, 'username' => 'tester', 'user_role' => 'admin'];
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'hep.%' OR setting_key LIKE 'echo.%'");
        getDB()->exec("DELETE FROM health_checks WHERE check_key = 'hep_collector'");
        @unlink(HepSettingsService::confPath());
    }

    protected function tearDown(): void
    {
        @unlink(HepSettingsService::confPath());
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'hep.%' OR setting_key LIKE 'echo.%'");
        getDB()->exec("DELETE FROM health_checks WHERE check_key = 'hep_collector'");
        $_SESSION = $this->session;
    }

    private function setting(string $key, string $value): void
    {
        $st = getDB()->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $st->execute([$key, $value]);
    }

    private function parts(array $report): array
    {
        return array_column($report['parts'], null, 'key');
    }

    private function signals(array $states): array
    {
        $out = [];
        foreach ($states as $key => $state) {
            $out[$key] = [
                'state' => $state,
                'detail' => $key . ' is ' . $state,
                'note' => $key . ' note',
                'url' => '',
            ];
        }
        return $out;
    }

    public function testNothingInstalledIsColdAndNamesEveryGap(): void
    {
        $r = GregObservabilityService::score([]);
        $this->assertSame(0, $r['score']);
        $this->assertSame('cold', $r['temperature']);
        $this->assertSame(
            ['capture', 'homer', 'voipmonitor', 'heplify', 'sipfront', 'rtcp'],
            array_column($r['parts'], 'key')
        );
        $this->assertCount(6, $r['missing']);
        $this->assertCount(6, $r['next']);
        $this->assertSame(100, array_sum(array_column($r['next'], 'gain')));
        $this->assertSame('capture', $r['next'][0]['key']);
        $this->assertStringContainsString('capture target', $r['next'][0]['action']);
    }

    public function testEverySourceAtFullShareIsAHundredAndHot(): void
    {
        $r = GregObservabilityService::score($this->signals([
            'capture' => 'on', 'homer' => 'on', 'voipmonitor' => 'on',
            'heplify' => 'on', 'sipfront' => 'on', 'rtcp' => 'on',
        ]));
        $this->assertSame(100, $r['score']);
        $this->assertSame('hot', $r['temperature']);
        $this->assertSame([], $r['missing']);
        $this->assertSame([], $r['next'], 'nothing to fix when every source is full');
        $this->assertSame(
            ['capture' => 15, 'homer' => 20, 'voipmonitor' => 20, 'heplify' => 15, 'sipfront' => 15, 'rtcp' => 15],
            array_column($r['parts'], 'points', 'key')
        );
    }

    public function testAConfiguredButUnreachableCollectorEarnsLittle(): void
    {
        $r = GregObservabilityService::score($this->signals([
            'capture' => 'on', 'homer' => 'down', 'voipmonitor' => 'off',
            'heplify' => 'off', 'sipfront' => 'off', 'rtcp' => 'on',
        ]));
        $parts = $this->parts($r);
        $this->assertSame(4, $parts['homer']['points'], 'a failed collector is not full credit');
        $this->assertSame('down', $parts['homer']['state']);
        $this->assertSame(34, $r['score']);
        $this->assertCount(4, $r['next']);
        $this->assertSame(66, array_sum(array_column($r['next'], 'gain')));
        $this->assertSame('homer', $r['next'][0]['key']);
        $this->assertSame('/health', $r['next'][0]['url']);
        $this->assertStringContainsString('check the collector', $r['next'][0]['action']);
    }

    public function testTheScoreIsTheSumOfItsParts(): void
    {
        $r = GregObservabilityService::score($this->signals([
            'capture' => 'limited', 'homer' => 'on', 'voipmonitor' => 'limited',
            'heplify' => 'down', 'sipfront' => 'off', 'rtcp' => 'on',
        ]));
        $sum = array_sum(array_column($r['parts'], 'points'));
        $this->assertSame($sum, $r['score']);
        $this->assertSame(9 + 20 + 12 + 3 + 0 + 15, $r['score']);
        $this->assertSame(['sipfront'], array_map(
            fn($part) => $part['key'],
            array_values(array_filter($r['parts'], fn($part) => $part['state'] === 'off'))
        ));
        $this->assertSame(['capture', 'voipmonitor', 'heplify', 'sipfront'], array_column($r['next'], 'key'));
        $this->assertSame([6, 8, 12, 15], array_column($r['next'], 'gain'));
        $this->assertSame(100, $r['score'] + array_sum(array_column($r['next'], 'gain')));
    }

    public function testCaptureIsLiveOnlyWhenHepConfIsApplied(): void
    {
        $this->setting('hep.mode', 'builtin');
        $path = HepSettingsService::confPath();
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "[general]\nenabled = yes\ncapture_address = 127.0.0.1:9061\n");

        $parts = $this->parts(GregObservabilityService::report());
        $this->assertSame('on', $parts['capture']['state']);
        $this->assertSame(15, $parts['capture']['points']);
        $this->assertStringContainsString('127.0.0.1:9061', $parts['capture']['detail']);
        $this->assertNotContains('capture', array_column(GregObservabilityService::report()['next'], 'key'));
    }

    public function testConfiguredCaptureWithoutHepConfIsOnlyPartial(): void
    {
        $this->setting('hep.mode', 'builtin');
        $parts = $this->parts(GregObservabilityService::report());
        $this->assertSame('limited', $parts['capture']['state']);
        $this->assertSame(9, $parts['capture']['points']);
        $this->assertStringContainsString('hep.conf', $parts['capture']['detail']);
        $r = GregObservabilityService::report();
        $next = array_column($r['next'], null, 'key');
        $this->assertSame(6, $next['capture']['gain']);
        $this->assertStringContainsString('Apply the saved target', $next['capture']['action']);
    }

    public function testHomerConfiguredWithoutAHealthCheckIsPartial(): void
    {
        $this->setting('hep.mode', 'homer');
        $this->setting('hep.host', '10.0.0.5');
        $this->setting('hep.port', '9060');
        $this->setting('hep.ui_url', 'https://homer.example');

        $parts = $this->parts(GregObservabilityService::report());
        $this->assertSame('limited', $parts['homer']['state']);
        $this->assertSame(12, $parts['homer']['points']);
        $this->assertStringContainsString('10.0.0.5:9060', $parts['homer']['detail']);
        $this->assertStringContainsString('no stored health check', $parts['homer']['detail']);
        $next = array_column(GregObservabilityService::report()['next'], null, 'key');
        $this->assertSame(8, $next['homer']['gain']);
        $this->assertSame('/health', $next['homer']['url']);
        $this->assertStringContainsString('prove it answers', $next['homer']['action']);
    }

    public function testCollectorHealthMovesTheShareWithoutPretendingItIsDown(): void
    {
        $this->setting('hep.mode', 'homer');
        $this->setting('hep.host', '10.0.0.5');
        $this->setting('hep.port', '9060');
        $st = getDB()->prepare(
            'INSERT INTO health_checks (check_key, status, message, checked_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), message = VALUES(message), checked_at = VALUES(checked_at)'
        );
        $st->execute(['hep_collector', 'warn', 'HOMER UI returned 503']);
        $parts = $this->parts(GregObservabilityService::report());
        $this->assertSame('limited', $parts['homer']['state']);
        $this->assertSame(12, $parts['homer']['points']);
        $this->assertStringContainsString('warning', $parts['homer']['detail']);
        $warnNext = array_column(GregObservabilityService::report()['next'], null, 'key');
        $this->assertSame(8, $warnNext['homer']['gain']);

        $st->execute(['hep_collector', 'fail', 'HOMER UI did not answer']);
        $r = GregObservabilityService::report();
        $parts = $this->parts($r);
        $this->assertSame('down', $parts['homer']['state']);
        $this->assertSame(4, $parts['homer']['points']);
        $this->assertStringContainsString('failed', $parts['homer']['detail']);
        $failNext = array_column($r['next'], null, 'key');
        $this->assertSame(16, $failNext['homer']['gain']);
        $this->assertStringContainsString('check the collector', $failNext['homer']['action']);
    }

    public function testPromptNamesTheMissingSourcesAndForbidsPretending(): void
    {
        $this->setting('hep.mode', 'off');
        $prompt = GregObservabilityService::promptContext();
        $this->assertStringContainsString('Observability coverage on this PBX: 0/100', $prompt);
        $this->assertStringContainsString('Nothing is captured or queryable', $prompt);
        $this->assertStringContainsString('Ask for the Call-ID', $prompt);
        $this->assertStringContainsString('Do not describe a SIP ladder', $prompt);
    }

    public function testPromptSaysAConfiguredCollectorStillCannotBeQueried(): void
    {
        $this->setting('hep.mode', 'homer');
        $this->setting('hep.host', '10.0.0.5');
        $this->setting('hep.port', '9060');
        $this->setting('hep.ui_url', 'https://homer.example');

        $prompt = GregObservabilityService::promptContext();
        $this->assertStringContainsString('HOMER is configured at 10.0.0.5:9060', $prompt);
        $this->assertStringContainsString('You cannot query it from this chat', $prompt);
        $this->assertStringContainsString('Evidence order:', $prompt);
    }
}
