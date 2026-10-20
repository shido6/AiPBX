<?php
/**
 * Greg's onboarding: the first week of a new employee, in six questions.
 *
 * A handover document is what a good administrator can hand over on day one.
 * Not every administrator has one written down — but almost everyone can
 * answer "what do you do, who calls, what must not break" out loud. So Greg
 * does what a new engineer does: he asks, one question at a time, and writes
 * the answers into the same markdown brief he would otherwise be given.
 *
 * The file stays the single source of truth: answers land in a marked section
 * of BRIEF.md as they arrive, so a lost session loses no knowledge, the block
 * can be downloaded and handed to a human, and re-running the conversation
 * replaces the block instead of duplicating it. Questions already answered are
 * not asked again.
 *
 * The state machine lives in the session, like GregHealthService's interview:
 * the model never invents an answer, a message that reads like a question is
 * answered normally while the interview waits, and "later" stops without
 * throwing away what has been written.
 */

require_once __DIR__ . '/GregBrief.php';
require_once __DIR__ . '/GregJournal.php';
require_once __DIR__ . '/../audit_log.php';

final class GregOnboardingService
{
    public const SESSION_KEY = 'greg_onboarding_interview';
    public const MAX_ANSWER = 1200;
    public const SECTION = 'onboarding';

    /**
     * The six things a new engineer asks before touching anything — the same
     * six sections as the template, in the order they are answerable.
     *
     * @return array<string, array{title: string, q: string}>
     */
    public static function questions(): array
    {
        return [
            'business' => [
                'title' => t('ai_voip_engineer.onb_topic_business'),
                'q'     => t('ai_voip_engineer.onb_q_business'),
            ],
            'hours' => [
                'title' => t('ai_voip_engineer.onb_topic_hours'),
                'q'     => t('ai_voip_engineer.onb_q_hours'),
            ],
            'flows' => [
                'title' => t('ai_voip_engineer.onb_topic_flows'),
                'q'     => t('ai_voip_engineer.onb_q_flows'),
            ],
            'carriers' => [
                'title' => t('ai_voip_engineer.onb_topic_carriers'),
                'q'     => t('ai_voip_engineer.onb_q_carriers'),
            ],
            'people' => [
                'title' => t('ai_voip_engineer.onb_topic_people'),
                'q'     => t('ai_voip_engineer.onb_q_people'),
            ],
            'good' => [
                'title' => t('ai_voip_engineer.onb_topic_good'),
                'q'     => t('ai_voip_engineer.onb_q_good'),
            ],
        ];
    }

    // --------------------------------------------------------------- state

    /** @return array{keys: array<int,string>, step: int, answers: array<string,string>, started: int}|null */
    public static function state(): ?array
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($state) ? $state : null;
    }

    public static function active(): bool
    {
        return self::state() !== null;
    }

    /** For the settings card: what the brief already knows, and where he is. */
    public static function status(): array
    {
        $state = self::state();
        $saved = self::saved();
        return [
            'active' => $state !== null,
            'step' => $state !== null ? (int) $state['step'] + 1 : 0,
            'answered' => count($saved),
            'total' => count(self::questions()),
        ];
    }

    /**
     * Answers already written into the brief, keyed by question.
     * @return array<string, string>
     */
    public static function saved(): array
    {
        $body = GregBrief::section(self::SECTION);
        if ($body === '') {
            return [];
        }
        $out = [];
        if (preg_match_all('/^### ([a-z0-9_]+) — .*?\R(.+?)(?=\R### |\z)/ms', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                if (isset(self::questions()[$row[1]])) {
                    $out[$row[1]] = trim($row[2]);
                }
            }
        }
        return $out;
    }

    public static function cancel(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    // ------------------------------------------------------------- the flow

    /**
     * One chat message. Returns Greg's reply when the onboarding handled it,
     * null when the message is an ordinary question.
     */
    public static function handle(string $q): ?string
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }
        $admin = ($_SESSION['user_role'] ?? '') === 'admin';

        if (self::active()) {
            // A role that changed mid-session must not keep being interviewed.
            if (!$admin) {
                return null;
            }
            if (preg_match('/^(later|pause|stop|enough|daha\s+sonra|duraklat|iptal|vazgeç|yeter)\b/iu', $q)) {
                return self::pause();
            }
            // A real question is answered normally: the interview waits rather
            // than swallowing "why is the trunk down?" as an answer.
            if (self::looksLikeQuestion($q)) {
                return null;
            }
            return self::take($q);
        }

        if (!self::looksLikeStart($q)) {
            return null;
        }
        if (!$admin) {
            return t('ai_voip_engineer.onb_admin_only');
        }
        return self::start();
    }

    /**
     * Begins (or resumes) the conversation. Questions already in the brief are
     * skipped, so "onboard me" a second time picks up where it stopped.
     */
    public static function start(): string
    {
        if (($_SESSION['user_role'] ?? '') !== 'admin') {
            return t('ai_voip_engineer.onb_admin_only');
        }
        $saved = self::saved();
        $keys = array_values(array_filter(
            array_keys(self::questions()),
            static fn($key) => !isset($saved[$key])
        ));
        if (!$keys) {
            return t('ai_voip_engineer.onb_already');
        }
        $_SESSION[self::SESSION_KEY] = [
            'keys' => $keys,
            'step' => 0,
            'answers' => $saved,
            'started' => time(),
        ];
        writeAuditLog('greg', 'onboarding', 0,
            'Onboarding conversation started (' . count($keys) . ' questions)',
            'create', $_SESSION['user_id'] ?? null);
        return self::prompt();
    }

    /** The intro and the current question — what the chat shows on arrival. */
    public static function prompt(): string
    {
        $state = self::state();
        if ($state === null) {
            return '';
        }
        return t('ai_voip_engineer.onb_intro') . "\n\n" . self::ask((int) $state['step']);
    }

    /**
     * The phrase that starts it, in English or Turkish — but not a question
     * *about* onboarding ("what is the onboarding brief?"), which is answered
     * like any other question.
     */
    public static function looksLikeStart(string $q): bool
    {
        if (preg_match('/^\s*(what|how|why|where|who|when|which|is|are|does|do|did|can|could|should|would)\b/iu', $q)) {
            return false;
        }
        if (preg_match('/^\s*(ne|nasıl|nedir|kim|hangi|niçin|nerede|mi|mı)\b/iu', $q)) {
            return false;
        }
        return (bool) preg_match('/\b(onboard(ing)?|induction|orientation|oryantasyon|işe\s+başlama)\b/iu', $q);
    }

    private static function looksLikeQuestion(string $q): bool
    {
        if (str_ends_with(rtrim($q), '?')) {
            return true;
        }
        return (bool) preg_match(
            '/^\s*(what|how|why|where|who|when|which|is|are|was|does|do|did|can|could|should|would|show|tell|check|explain|ne|nasıl|nedir|kim|hangi|niçin|nerede|göster|anlat|kontrol)\b/iu',
            $q
        );
    }

    private static function take(string $q): string
    {
        $state = self::state();
        $key = $state['keys'][$state['step']] ?? null;
        if ($key === null) {
            $answers = $state['answers'] ?? [];
            self::cancel();
            return self::finish($answers);
        }

        if (preg_match('/^(skip|pass|next|atla|geç)\b/iu', $q)) {
            $state['step'] = (int) $state['step'] + 1;
            if ($state['step'] >= count($state['keys'])) {
                self::cancel();
                return self::finish($state['answers']);
            }
            $_SESSION[self::SESSION_KEY] = $state;
            return t('ai_voip_engineer.onb_skipped') . "\n\n" . self::ask($state['step']);
        }

        $answer = self::clean($q);
        if ($answer === '') {
            return t('ai_voip_engineer.onb_need_answer') . "\n\n" . self::ask((int) $state['step']);
        }
        $state['answers'][$key] = $answer;
        $state['step'] = (int) $state['step'] + 1;
        $_SESSION[self::SESSION_KEY] = $state;

        // Written as it arrives: closing the tab, losing the session or
        // switching to the settings page loses none of the conversation.
        self::write($state['answers']);

        if ($state['step'] < count($state['keys'])) {
            return self::ack((int) $state['step']) . "\n\n" . self::ask($state['step']);
        }
        $answers = $state['answers'];
        self::cancel();
        return self::finish($answers);
    }

    private static function pause(): string
    {
        $state = self::state();
        $count = count($state['answers'] ?? []);
        self::cancel();
        return $count > 0
            ? sprintf(t('ai_voip_engineer.onb_paused_saved'), $count)
            : t('ai_voip_engineer.onb_paused');
    }

    private static function ask(int $step): string
    {
        $state = self::state();
        $key = $state['keys'][$step] ?? null;
        if ($key === null) {
            return '';
        }
        $def = self::questions()[$key];
        return sprintf(t('ai_voip_engineer.onb_count'), $step + 1, count($state['keys']))
            . ' — ' . $def['q'] . "\n" . t('ai_voip_engineer.onb_hint');
    }

    /** Not "Saved." — a colleague who just listened says something human. */
    private static function ack(int $step): string
    {
        return t('ai_voip_engineer.onb_ack_' . (($step % 3) + 1));
    }

    private static function clean(string $raw): string
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        return mb_substr($raw, 0, self::MAX_ANSWER);
    }

    // ------------------------------------------------------------- the file

    /**
     * The section written into BRIEF.md. One heading per answered question
     * carries its stable key, which is how a second run knows what it has.
     */
    private static function block(array $answers): string
    {
        $out = sprintf(t('ai_voip_engineer.onb_recorded'), self::actor(), date('Y-m-d'));
        foreach (self::questions() as $key => $def) {
            if (!isset($answers[$key]) || $answers[$key] === '') {
                continue;
            }
            $out .= "\n### " . $key . ' — ' . $def['title'] . "\n" . $answers[$key] . "\n";
        }
        return $out;
    }

    /** True when the answers are in the file; false when the file is unwritable. */
    private static function write(array $answers): bool
    {
        if (!$answers) {
            return true;
        }
        try {
            GregBrief::mergeSection(self::SECTION, t('ai_voip_engineer.onb_section'), self::block($answers));
            return true;
        } catch (\Throwable $e) {
            error_log('aipbx: Greg could not write the onboarding brief: ' . $e->getMessage());
            return false;
        }
    }

    private static function finish(array $answers): string
    {
        $wrote = self::write($answers);
        $qs = self::questions();
        $answered = [];
        $missing = [];
        foreach ($qs as $key => $def) {
            if (isset($answers[$key]) && $answers[$key] !== '') {
                $answered[] = mb_strtolower($def['title']);
            } else {
                $missing[] = mb_strtolower($def['title']);
            }
        }

        writeAuditLog('greg', 'onboarding', 0,
            'Onboarding conversation finished (' . count($answered) . ' of ' . count($qs) . ' answered)',
            'update', $_SESSION['user_id'] ?? null);
        GregJournal::append('Onboarding conversation finished', [
            count($answered) . ' of ' . count($qs) . ' answers' . ($wrote ? ', written to the brief' : ', NOT written to the brief'),
        ]);

        $out = sprintf(t('ai_voip_engineer.onb_done'), count($answered), count($qs));
        if ($missing) {
            $out .= "\n" . sprintf(t('ai_voip_engineer.onb_missing'), implode(', ', $missing));
        }
        if (!$wrote) {
            $out .= "\n" . t('ai_voip_engineer.onb_write_failed');
        }
        return $out;
    }

    private static function actor(): string
    {
        $name = trim((string) ($_SESSION['username'] ?? ''));
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        return $name !== '' ? mb_substr($name, 0, 60) : t('ai_voip_engineer.onb_actor_admin');
    }
}
