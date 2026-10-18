<?php
/**
 * Greg's guided health check.
 *
 * The Health page holds 17 checks the server runs for itself. Most of their
 * answers a person cannot know — so Greg fills those in himself from
 * health_checks, and asks the user only what a machine cannot see: what is
 * broken, which phones, since when, and whether audio actually flows. Where
 * Sipfront is configured, its latest test run is the independent proof that a
 * call can complete, and it is quoted next to the server's own opinion.
 *
 * The result is frozen into greg_health_reports (answers + checks + Sipfront
 * at that moment), written to Greg's captain's log and audited. The interview
 * is a small state machine in the session: the model never invents an answer,
 * and a message that is not a valid answer re-asks instead of guessing.
 */

require_once __DIR__ . '/HealthService.php';
require_once __DIR__ . '/ai/EchoService.php';
require_once __DIR__ . '/GregJournal.php';
require_once __DIR__ . '/../audit_log.php';

class GregHealthService
{
    public const SESSION_KEY = 'greg_health_interview';
    public const MAX_TEXT = 300;

    /** @return array<string, array{type:string, required?:bool, q:string}> */
    public static function questions(): array
    {
        return [
            'symptom' => ['type' => 'text', 'required' => true, 'q' => t('health.greg_q_symptom')],
            'scope' => ['type' => 'text', 'required' => false, 'q' => t('health.greg_q_scope')],
            'since' => ['type' => 'text', 'required' => false, 'q' => t('health.greg_q_since')],
            'audio' => ['type' => 'choice', 'choices' => ['both', 'oneway', 'none', 'na'], 'q' => t('health.greg_q_audio')],
            'frequency' => ['type' => 'choice', 'choices' => ['every', 'some', 'one'], 'q' => t('health.greg_q_frequency')],
        ];
    }

    public static function active(): bool
    {
        return is_array($_SESSION[self::SESSION_KEY] ?? null);
    }

    /**
     * The phrase that starts it, in English or Turkish — but not a question
     * *about* health checks ("what is a health check?"), which is answered from
     * memory like any other question.
     */
    public static function looksLikeStart(string $q): bool
    {
        if (preg_match('/^\s*(what|how|why|where|who|when|which|is|are|does|do|did|can|could|should|would)\b/iu', $q)) {
            return false;
        }
        if (preg_match('/^\s*(ne|nasıl|nedir|kim|hangi|niçin|nerede|mi|mı)\b/iu', $q)) {
            return false;
        }
        return (bool) preg_match('/\b(health\s?check|check\s?up|system\s?check|sağlık\s?kontrolü|saglik\s?kontrolu)\b/iu', $q);
    }

    /**
     * One chat message. Returns Greg's reply when the interview handled it,
     * null when the message is an ordinary question.
     */
    public static function handle(string $q): ?string
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }
        if (self::active()) {
            if (preg_match('/^(cancel|stop|iptal|vazgeç)\b/iu', $q)) {
                unset($_SESSION[self::SESSION_KEY]);
                return t('health.greg_cancelled');
            }
            return self::take($q);
        }
        if (self::looksLikeStart($q)) {
            return self::start();
        }
        return null;
    }

    /** Begin a new interview and ask the first question. */
    public static function start(): string
    {
        $_SESSION[self::SESSION_KEY] = ['step' => 0, 'answers' => [], 'started' => time()];
        return t('health.greg_intro') . "\n\n" . self::ask(0);
    }

    public static function cancel(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    // ------------------------------------------------------------- the flow

    private static function take(string $q): string
    {
        $state = $_SESSION[self::SESSION_KEY];
        $keys = array_keys(self::questions());
        $step = (int) ($state['step'] ?? 0);
        $key = $keys[$step] ?? null;
        if ($key === null) {
            unset($_SESSION[self::SESSION_KEY]);
            return t('health.greg_cancelled');
        }
        $value = self::parse($key, $q);
        if ($value === null) {
            return t('health.greg_invalid') . "\n\n" . self::ask($step);
        }
        $state['answers'][$key] = $value;
        $state['step'] = $step + 1;
        $_SESSION[self::SESSION_KEY] = $state;

        if ($state['step'] < count($keys)) {
            return t('health.greg_thanks') . "\n\n" . self::ask($state['step']);
        }
        unset($_SESSION[self::SESSION_KEY]);
        return self::finish($state['answers']);
    }

    /** @return string|null null means "not a valid answer, ask again" */
    private static function parse(string $key, string $raw)
    {
        $def = self::questions()[$key];
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if (($def['type'] ?? 'text') === 'choice') {
            $choices = $def['choices'];
            if (ctype_digit($raw)) {
                $i = (int) $raw - 1;
                return $choices[$i] ?? null;
            }
            $flat = mb_strtolower($raw);
            foreach ($choices as $c) {
                if ($flat === mb_strtolower($c) || $flat === mb_strtolower(self::choiceLabel($key, $c))) {
                    return $c;
                }
            }
            return null;
        }
        if ($raw === '' || preg_match('/^(skip|atla|-|—)$/iu', $raw)) {
            return !empty($def['required']) ? null : '';
        }
        if (mb_strlen($raw) < 3) {
            return null;
        }
        return mb_substr($raw, 0, self::MAX_TEXT);
    }

    private static function ask(int $step): string
    {
        $qs = self::questions();
        $keys = array_keys($qs);
        $key = $keys[$step];
        $def = $qs[$key];
        $out = sprintf(t('health.greg_progress'), $step + 1, count($keys)) . "\n" . $def['q'];
        if (($def['type'] ?? 'text') === 'choice') {
            foreach ($def['choices'] as $i => $c) {
                $out .= "\n" . ($i + 1) . ') ' . self::choiceLabel($key, $c);
            }
        } elseif (empty($def['required'])) {
            $out .= "\n" . t('health.greg_optional');
        }
        return $out . "\n" . t('health.greg_cancel_hint');
    }

    public static function choiceLabel(string $key, string $value): string
    {
        return t('health.greg_' . $key . '_' . $value);
    }

    // ------------------------------------------------------------ evidence

    /**
     * The checks worth reporting: enabled ones, with their stored result.
     * @return array<string, array{status:string, message:string, checked_at:?string}>
     */
    public static function checks(): array
    {
        $out = [];
        foreach (HealthService::checks() as $key => $c) {
            if ((int) $c['interval'] === 0) {
                continue;
            }
            $out[$key] = [
                'status' => (string) $c['status'],
                'message' => (string) $c['message'],
                'checked_at' => $c['checked_at'],
            ];
        }
        return $out;
    }

    /**
     * The independent call test. Sipfront when it has keys; otherwise the
     * sip_calltest health check, which runs SIPp on this server under root and
     * captures the exchange with tcpdump. Greg reads the check rather than
     * running a second probe as the web user (no capture permission there).
     *
     * @return array{source:string, error:?string, latest:?array, status:string,
     *               message:string, checked_at:?string, runs:array}
     */
    public static function callTest(): array
    {
        if (SipfrontClient::configured()) {
            $r = EchoService::recentRuns(3);
            $runs = $r['runs'] ?? [];
            return [
                'source' => 'sipfront', 'error' => $r['error'] ?? null,
                'latest' => $runs[0] ?? null, 'runs' => $runs,
                'status' => '', 'message' => '', 'checked_at' => null,
            ];
        }
        $check = HealthService::checks()['sip_calltest'] ?? null;
        if ($check === null) {
            return ['source' => 'none', 'error' => null, 'latest' => null, 'runs' => [],
                'status' => '', 'message' => '', 'checked_at' => null];
        }
        return [
            'source' => 'check', 'error' => null, 'latest' => null, 'runs' => [],
            'status' => (string) $check['status'],
            'message' => (string) $check['message'],
            'checked_at' => $check['checked_at'],
        ];
    }

    /** fail > warn > ok, from the checks, the call test and the audio answer. */
    public static function verdict(array $checks, array $test, array $answers): string
    {
        $fail = 0;
        $warn = 0;
        $unknown = 0;
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') {
                $fail++;
            } elseif ($c['status'] === 'warn') {
                $warn++;
            } elseif ($c['status'] === 'unknown') {
                $unknown++;
            }
        }
        if (($test['source'] ?? '') === 'sipfront') {
            if (($test['latest']['is_failed'] ?? 0)) {
                $fail++;
            }
            if (($test['error'] ?? null) || !($test['latest'] ?? null)) {
                $warn++;
            }
        } elseif (($test['source'] ?? '') === 'check') {
            if (($test['status'] ?? '') === 'fail') {
                $fail++;
            } elseif (in_array($test['status'] ?? '', ['warn'], true)) {
                $warn++;
            }
        }
        if ($checks && $unknown === count($checks)) {
            $warn++;
        }
        if (in_array($answers['audio'] ?? '', ['oneway', 'none'], true)) {
            $warn++;
        }
        return $fail > 0 ? 'fail' : ($warn > 0 ? 'warn' : 'ok');
    }

    /**
     * Freeze the report, tell the administrators' log, and write Greg's reply.
     */
    private static function finish(array $answers): string
    {
        $checks = self::checks();
        $sip = self::callTest();
        $admin = ($_SESSION['user_role'] ?? '') === 'admin';
        $verdict = self::verdict($checks, $sip, $answers);
        $summary = self::summary($answers, $checks, $sip, $verdict, $admin);

        $db = getDB();
        $ins = $db->prepare(
            'INSERT INTO greg_health_reports (created_at, user_id, username, verdict, answers, checks, sipfront, summary)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $_SESSION['user_id'] ?? null,
            mb_substr((string) ($_SESSION['username'] ?? ''), 0, 50),
            $verdict,
            json_encode($answers, JSON_UNESCAPED_UNICODE),
            json_encode($checks, JSON_UNESCAPED_UNICODE),
            json_encode($sip, JSON_UNESCAPED_UNICODE),
            $summary,
        ]);
        $id = (int) $db->lastInsertId();
        writeAuditLog('health', 'greg_report', $id, 'health check ' . $verdict, 'create', $_SESSION['user_id'] ?? null);
        GregJournal::append('Health check', [
            'verdict: ' . $verdict,
            'reported: ' . ($answers['symptom'] ?? ''),
        ]);
        return sprintf(t('health.greg_saved'), (string) $id) . "\n\n" . $summary;
    }

    /** The filled form, in the order the questions were asked. */
    public static function summary(array $answers, array $checks, array $test, string $verdict, bool $admin): string
    {
        $qs = self::questions();
        $out = t('health.greg_answers_title');
        foreach ($qs as $key => $def) {
            $value = $answers[$key] ?? '';
            if ($value === '') {
                $value = t('health.greg_not_answered');
            } elseif (($def['type'] ?? 'text') === 'choice') {
                $value = self::choiceLabel($key, (string) $value);
            }
            $out .= "\n- " . $def['q'] . ' ' . $value;
        }

        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'unknown' => 0, 'info' => 0, 'off' => 0];
        foreach ($checks as $c) {
            $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
        }
        $out .= "\n\n" . t('health.greg_checks_title') . "\n"
            . sprintf(t('health.greg_checks_counts'), $counts['ok'], $counts['warn'], $counts['fail'], $counts['unknown']);
        foreach ($checks as $key => $c) {
            if (!in_array($c['status'], ['warn', 'fail'], true)) {
                continue;
            }
            $out .= "\n- " . t('health.status_' . $c['status']) . ': ' . $key;
            if ($admin && $c['message'] !== '') {
                $out .= ' — ' . mb_substr($c['message'], 0, 160);
            }
        }
        if ($counts['unknown'] > 0) {
            $out .= "\n" . sprintf(t('health.greg_checks_unknown'), $counts['unknown']);
        }

        $out .= "\n\n" . t('health.greg_calltest_title');
        if (($test['source'] ?? '') === 'sipfront') {
            if ($test['error']) {
                $out .= "\n" . sprintf(t('health.greg_sipfront_error'), mb_substr((string) $test['error'], 0, 160));
            } elseif (!$test['latest']) {
                $out .= "\n" . t('health.greg_sipfront_none');
            } else {
                $r = $test['latest'];
                $out .= "\n" . sprintf(
                    t('health.greg_sipfront_last'),
                    (string) ($r['test_name'] ?? $r['name'] ?? 'test'),
                    (string) ($r['started_at'] ?? $r['created_at'] ?? ''),
                    !empty($r['is_failed']) ? t('health.status_fail') : t('health.status_ok')
                );
            }
        } elseif (($test['source'] ?? '') === 'check') {
            $out .= "\n" . t('health.status_' . ($test['status'] ?: 'unknown')) . ': ' . ($test['message'] ?: t('health.greg_sipp_no_answer'));
            if (!empty($test['checked_at'])) {
                $out .= "\n" . sprintf(t('health.greg_checked_at'), (string) $test['checked_at']);
            }
        } else {
            $out .= "\n" . t('health.greg_sipp_missing');
        }

        $out .= "\n\n" . t('health.greg_verdict') . ': ' . t('health.greg_verdict_' . $verdict);
        if (!$admin) {
            $out .= "\n" . t('health.greg_nonadmin');
        }
        return $out;
    }

    /** @return array<int, array{id:int, created_at:string, username:string, verdict:string, summary:string}> */
    public static function reports(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $rows = getDB()->query(
            'SELECT id, created_at, username, verdict, summary FROM greg_health_reports ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'created_at' => (string) $r['created_at'],
            'username' => (string) $r['username'],
            'verdict' => (string) $r['verdict'],
            'summary' => (string) $r['summary'],
        ], $rows);
    }
}
