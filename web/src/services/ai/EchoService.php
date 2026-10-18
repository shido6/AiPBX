<?php
/**
 * Echo — the SIP correlation tool, configured as its own AI section.
 *
 * Two data sources, deliberately kept apart:
 *   - this server's own numbers (EchoReporter: CDRs, captured SIP), and
 *   - Sipfront's test runs (SipfrontClient), when keys are entered.
 *
 * Greg reads a compact summary of both before he answers a question, so he
 * can say "the carrier test failed at 14:02 with a 503, and this PBX logged
 * 3 calls at that minute" instead of guessing. Echo is read-only: the only
 * write path is saving settings (audited), and the only Sipfront call that
 * starts anything is runTest(), on a test the administrator configured.
 */

require_once __DIR__ . '/SipfrontClient.php';
require_once __DIR__ . '/EchoReporter.php';
require_once __DIR__ . '/../../audit_log.php';
require_once __DIR__ . '/../GregJournal.php';

class EchoService
{
    /** @return array{enabled:bool, public_key:string, public_key_masked:string,
     *                secret_set:bool, test_id:int, configured:bool} */
    public static function settings(): array
    {
        $pub = SipfrontClient::publicKey();
        $testId = (string) getSystemSetting('echo.test_id', '');
        return [
            'enabled'          => getSystemSetting('echo.enabled', '0') === '1',
            'public_key'       => $pub,
            'public_key_masked' => $pub === '' ? '' : (strlen($pub) <= 6 ? '••••' : '••••' . substr($pub, -4)),
            'secret_set'       => SipfrontClient::secret() !== '',
            'test_id'          => ctype_digit($testId) ? (int) $testId : 0,
            'configured'       => SipfrontClient::configured(),
        ];
    }

    public static function enabled(): bool
    {
        return self::settings()['enabled'];
    }

    /**
     * Save the section's form (admin + CSRF already enforced by the controller).
     * An empty secret field keeps the stored key — the page never shows it back.
     */
    public static function save(array $post): array
    {
        $pub = trim((string) ($post['echo_public_key'] ?? ''));
        if ($pub !== '' && preg_match('/\s/', $pub)) {
            throw new CloudAiException(t('ai_echo.err_public'));
        }
        $testId = trim((string) ($post['echo_test_id'] ?? ''));
        if ($testId !== '' && !ctype_digit($testId)) {
            throw new CloudAiException(t('ai_echo.err_test_id'));
        }
        $secret = trim((string) ($post['echo_secret'] ?? ''));

        $db = getDB();
        $up = $db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $up->execute(['echo.enabled', !empty($post['echo_enabled']) ? '1' : '0']);
        $up->execute(['echo.sipfront_public_key', $pub]);
        $up->execute(['echo.test_id', $testId]);
        if ($secret !== '') {
            $up->execute(['echo.sipfront_secret', SecretBox::encrypt($secret)]);
        }
        $s = self::settings();
        writeAuditLog('echo', 'settings', 'echo',
            'Echo (Sipfront) settings saved (' . ($s['configured'] ? 'keys set' : 'no keys')
                . ', ' . ($s['enabled'] ? 'on' : 'off') . ')',
            'update', $_SESSION['user_id'] ?? null);
        GregJournal::append('Echo (Sipfront) settings saved', [
            'keys: ' . ($s['configured'] ? 'set' : 'not set'),
            'state: ' . ($s['enabled'] ? 'on' : 'off'),
            $s['test_id'] > 0 ? 'watch test #' . $s['test_id'] : '',
        ]);
        return ['message' => t('ai_voip_engineer.msg_saved')];
    }

    /** Where the keys come from, and what the plan needs — one honest line. */
    public static function setupSteps(): string
    {
        return t('ai_echo.setup_steps');
    }

    /**
     * A compact, deterministic context block for Greg's prompt. Never throws:
     * a broken Sipfront call becomes one line of context, not a failed reply.
     */
    public static function promptContext(int $runLimit = 3): string
    {
        if (!self::enabled()) {
            return '';
        }
        $lines = ['Echo (SIP correlation), facts you may use — do not invent others:'];
        try {
            $facts = EchoReporter::facts();
            if (isset($facts['calls_today'])) {
                $minutes = isset($facts['minutes_today'])
                    ? rtrim(rtrim(number_format((float) $facts['minutes_today'], 1, '.', ''), '0'), '.')
                    : null;
                $lines[] = '- Calls today: ' . $facts['calls_today']
                    . ($minutes !== null ? ' (' . ($minutes === '' ? '0' : $minutes) . ' talk minutes)' : '');
            }
            if (isset($facts['sip_errors_24h'])) {
                $lines[] = '- SIP error responses, last 24 h: ' . $facts['sip_errors_24h'];
            }
        } catch (\Throwable $e) {
            $lines[] = '- Local call data: unavailable right now.';
        }
        require_once __DIR__ . '/HepSettingsService.php';
        $hep = HepSettingsService::settings();
        $lines[] = '- HEP capture: ' . HepSettingsService::describe($hep)
            . ($hep['homer_url'] !== '' ? ' (HOMER UI: ' . $hep['homer_url'] . ')' : '');
        if (!SipfrontClient::configured()) {
            $lines[] = '- Sipfront is not configured (AI → Echo); no external SIP test data.';
            return implode("\n", $lines);
        }
        try {
            $runs = SipfrontClient::recentRuns($runLimit);
            if (!$runs) {
                $lines[] = '- Sipfront returned no runs.';
            } else {
                $lines[] = '- Recent Sipfront runs:';
                foreach ($runs as $r) {
                    $lines[] = '  · ' . self::formatRun($r);
                }
            }
        } catch (\Throwable $e) {
            $lines[] = '- Sipfront: ' . mb_substr($e->getMessage(), 0, 160);
        }
        return implode("\n", $lines);
    }

    /** One run, one line: what ran, when, whether it failed, its session. */
    public static function formatRun(array $r): string
    {
        $name = (string) ($r['test_name'] ?? $r['name'] ?? $r['test_id'] ?? 'run');
        $when = (string) ($r['started_at'] ?? $r['started'] ?? $r['created_at'] ?? '');
        $failed = array_key_exists('is_failed', $r)
            ? ((int) $r['is_failed'] === 1 || $r['is_failed'] === true ? 'failed' : 'passed')
            : (string) ($r['status'] ?? 'unknown');
        $sid = (string) ($r['session_id'] ?? $r['id'] ?? '');
        return $name . ' — ' . ($when !== '' ? $when . ', ' : '') . $failed
            . ($sid !== '' ? ', session ' . $sid : '');
    }

    /** The page's "Test connection" button. @return array{count:int} */
    public static function probe(): array
    {
        return SipfrontClient::probe();
    }

    /** @return array run payload (session_id, report_url, …) */
    public static function runTest(): array
    {
        $id = self::settings()['test_id'];
        if ($id === 0) {
            throw new CloudAiException(t('ai_echo.err_test_id'));
        }
        return SipfrontClient::runTest($id);
    }

    /** Recent runs for the page; a failure is returned, never thrown. @return array{runs:array, error:?string} */
    public static function recentRuns(int $count = 5): array
    {
        if (!SipfrontClient::configured()) {
            return ['runs' => [], 'error' => null];
        }
        try {
            return ['runs' => SipfrontClient::recentRuns($count), 'error' => null];
        } catch (\Throwable $e) {
            return ['runs' => [], 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
