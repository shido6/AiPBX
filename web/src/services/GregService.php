<?php
/**
 * AI → VoIP Engineer: "Greg", the entity that watches over the phone system.
 *
 * The administrator enables him on /ai-voip-engineer. From then on his
 * avatar sits top-right in every screen (logo top-left, Greg top-right),
 * carries the token counter, shows his notices in a scrolling panel, and
 * answers questions in /chat.
 *
 * He informs — concisely, and never nags:
 *   - notices are deduplicated (one per key per 6-hour window),
 *   - e-mail only for warn/critical, never inside quiet hours,
 *   - info notices stay in-app only.
 *
 * Model sources: "system" is the portal's own chain — AssistantModel's
 * local-runtime-then-cloud ladder, i.e. whatever LLM the system is set up
 * for. "ling" is OpenRouter's free Ling model (strict daily request
 * limits, shown next to the selector). "bigpickle" is the free model that
 * ships with the opencode CLI — no key and no account, and the default as
 * soon as Greg is enabled.
 *
 * Everything Greg does is tracked: settings and notices write the audit
 * log, and PBX changes must go through AssistantPlanService, whose
 * pbx_ai_changes rows the Dialplan editor already rolls back one click.
 */

require_once __DIR__ . '/ai/AssistantModel.php';
require_once __DIR__ . '/ai/EchoService.php';
require_once __DIR__ . '/ai/ResearchService.php';
require_once __DIR__ . '/ai/OpencodeService.php';
require_once __DIR__ . '/ai/CloudAiService.php';
require_once __DIR__ . '/tts/TtsProviders.php';
require_once __DIR__ . '/MailTemplateService.php';
require_once __DIR__ . '/../audit_log.php';

class GregService
{
    public const DEFAULT_NAME = 'Greg';
    public const SOURCES = ['bigpickle', 'system', 'ling'];
    public const QUIET_DEFAULT = '22:00-07:00';
    public const LING_MODEL = 'inclusionai/ling-3.0-flash:free';
    private const DEDUPE_WINDOW = 21600;   // 6 h — one notice per key per window
    private const CHAT_HISTORY_MAX = 12;

    // ------------------------------------------------------------- settings

    /** Greg's stored configuration with defaults; disabled until enabled. */
    public static function settings(): array
    {
        $source = (string) getSystemSetting('greg.model_source', 'bigpickle');
        $quiet = (string) getSystemSetting('greg.quiet_hours', self::QUIET_DEFAULT);
        return [
            'enabled'      => getSystemSetting('greg.enabled', '0') === '1',
            'name'         => trim((string) getSystemSetting('greg.name', '')) ?: self::DEFAULT_NAME,
            'model_source' => in_array($source, self::SOURCES, true) ? $source : 'bigpickle',
            'show_avatar'  => getSystemSetting('greg.show_avatar', '1') === '1',
            'show_tokens'  => getSystemSetting('greg.show_tokens', '1') === '1',
            'notice_email' => getSystemSetting('greg.notice_email', '1') === '1',
            'quiet_hours'  => preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $quiet) ? $quiet : self::QUIET_DEFAULT,
        ];
    }

    public static function enabled(): bool
    {
        return self::settings()['enabled'];
    }

    /** Avatar top-right: only when enabled AND the administrator shows it. */
    public static function avatarVisible(): bool
    {
        $s = self::settings();
        return $s['enabled'] && $s['show_avatar'];
    }

    /**
     * Persist the form (admin only, CSRF checked by the controller).
     * Validation mirrors the portal's own: a bad value is an honest error,
     * never a silent drop.
     */
    public static function save(array $post): array
    {
        $s = self::settings();
        $name = trim((string) ($post['greg_name'] ?? $s['name']));
        if (!preg_match('/^[\p{L}\p{N} .\'_-]{1,30}$/u', $name)) {
            throw new CloudAiException(t('ai_voip_engineer.err_name'));
        }
        $quiet = trim((string) ($post['greg_quiet_hours'] ?? $s['quiet_hours']));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/', $quiet)) {
            throw new CloudAiException(t('ai_voip_engineer.err_quiet'));
        }
        $source = (string) ($post['greg_model_source'] ?? $s['model_source']);
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'bigpickle';
        }
        $up = getDB()->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $values = [
            'greg.enabled'      => !empty($post['greg_enabled']) ? '1' : '0',
            'greg.name'         => $name,
            'greg.model_source' => $source,
            'greg.show_avatar'  => !empty($post['greg_show_avatar']) ? '1' : '0',
            'greg.show_tokens'  => !empty($post['greg_show_tokens']) ? '1' : '0',
            'greg.notice_email' => !empty($post['greg_notice_email']) ? '1' : '0',
            'greg.quiet_hours'  => $quiet,
        ];
        foreach ($values as $k => $v) {
            $up->execute([$k, $v]);
        }
        writeAuditLog('greg', 'settings', 'greg',
            $name . ' settings saved (' . ($values['greg.enabled'] === '1' ? 'active' : 'disabled') . ')',
            'update', $_SESSION['user_id'] ?? null);
        return ['message' => t('ai_voip_engineer.msg_saved')];
    }

    // ------------------------------------------------------- tokens / chat

    /** This month's LLM tokens across cloud providers (the chip's number). */
    public static function monthTokens(): int
    {
        try {
            $sum = 0;
            foreach (CloudAiService::usageThisMonth() as $row) {
                $sum += (int) ($row['llm_tokens'] ?? 0);
            }
            return $sum;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Who he is, in one prompt the model reads first. */
    public static function persona(): string
    {
        $name = self::settings()['name'];
        return 'You are ' . $name . ' (VoIPMonk), the AI VoIP Engineer of this AiPBX phone system — '
            . 'a purple-hat engineer equally at home in blue-team security, red-team thinking and the '
            . 'finance of calls (what failures cost). You watch the system and help administrators and '
            . 'users through problems. Rules: plain language, no jargon without a one-line explanation; '
            . 'short and concrete — a few sentences, not essays; answer in the language the user wrote '
            . 'in; if you do not know something from the conversation, say so plainly instead of '
            . 'inventing; never invent numbers about calls or extensions. You inform, you do not nag.';
    }

    /**
     * One question from /chat. History lives in the session (12 messages);
     * the whole conversation is handed to AssistantModel::ask()'s layer
     * chain — local runtime first, cloud second — exactly like the
     * Dialplan editor's "Ask", so Greg runs on whatever LLM the system is
     * set up for.
     *
     * @return array{success: bool, message?: string, error?: string}
     */
    public static function chatSend(string $question): array
    {
        $q = trim($question);
        if ($q === '') {
            return ['success' => false, 'error' => t('greg.err_empty')];
        }
        $s = self::settings();
        if (!$s['enabled']) {
            return ['success' => false, 'error' => t('greg.err_disabled')];
        }
        $hist = $_SESSION['greg_chat'] ?? [];
        $hist[] = ['role' => 'user', 'content' => $q];
        $hist = array_slice($hist, -self::CHAT_HISTORY_MAX);

        // Greg's workshop: when the question is plainly "add a tool" (or
        // "research this"), opencode drafts the plan and it is stored for
        // review instead of being answered from memory. Nothing is installed.
        $workshop = self::workshopIntent($q);
        if ($workshop !== null) {
            try {
                $proposal = OpencodeService::propose($q, $workshop);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
            $reply = sprintf(t('greg.oc_drafted'), (string) $proposal['id'])
                . "\n\n" . $proposal['output'] . "\n\n" . t('greg.oc_review');
            $hist[] = ['role' => 'assistant', 'content' => $reply];
            $_SESSION['greg_chat'] = array_slice($hist, -self::CHAT_HISTORY_MAX);
            return ['success' => true, 'message' => $reply];
        }

        $prompt = self::persona() . "\n\n";
        if (OpencodeService::settings()['enabled']) {
            $prompt .= t('greg.oc_capability') . "\n\n";
        }
        // ResearchService: a URL in the question becomes quotable text with
        // its source named. Off by default; unchanged prompt when off.
        $research = ResearchService::contextFor($q);
        if ($research !== '') {
            $prompt .= $research . "\n\n";
        }
        // Echo: this server's own counts plus Sipfront's runs, when the
        // administrator enabled it. Empty string when Echo is off, so Greg
        // behaves exactly as before.
        $echo = EchoService::promptContext();
        if ($echo !== '') {
            $prompt .= $echo . "\n\n";
        }
        foreach ($hist as $m) {
            $prompt .= ($m['role'] === 'user' ? 'User' : $s['name']) . ': ' . $m['content'] . "\n";
        }
        $prompt .= $s['name'] . ':';

        try {
            $reply = self::brain($s['model_source'], $prompt);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        $hist[] = ['role' => 'assistant', 'content' => $reply];
        $_SESSION['greg_chat'] = array_slice($hist, -self::CHAT_HISTORY_MAX);
        return ['success' => true, 'message' => $reply];
    }

    /**
     * Does this question ask Greg to reach for opencode? Deliberately narrow:
     * an explicit "research:" prefix, an explicit "ask opencode", or an
     * add/install/propose + tool/plugin/MCP sentence. Ordinary questions keep
     * going to the model exactly as before.
     *
     * @return string|null 'tool' | 'research' | null
     */
    public static function workshopIntent(string $q): ?string
    {
        // Drafting a tool proposal is administrator work: a normal user's
        // question is answered by the model, never turned into a job.
        if (($_SESSION['user_role'] ?? '') !== 'admin') {
            return null;
        }
        if (getSystemSetting('greg.opencode_enabled', '1') !== '1') {
            return null;
        }
        $text = trim($q);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(research|araştır)\s*[:\-]/iu', $text)) {
            return 'research';
        }
        if (preg_match('/\b(ask|use|with|via)\s+opencode\b/i', $text)) {
            return 'research';
        }
        if (preg_match('/\b(add|install|propose|integrate|wire|set\s*up|hook\s*up)\b.{0,60}?\b(tool|plugin|mcp|integration|connector|skill)\b/is', $text)) {
            return 'tool';
        }
        return null;
    }

    /** @return array<int, array{role: string, content: string}> */
    public static function history(): array
    {
        return $_SESSION['greg_chat'] ?? [];
    }

    /** The chosen source's answer; a refused source throws an honest error. */
    private static function brain(string $source, string $prompt): string
    {
        if ($source === 'bigpickle') {
            // The free model that ships with the opencode CLI: no key, no
            // account. Read-only and in a throwaway workspace, like everything
            // else opencode does here.
            return OpencodeService::chat($prompt);
        }
        if ($source === 'ling') {
            return self::lingChat($prompt);
        }
        $reply = AssistantModel::ask($prompt);
        if ($reply === null || trim($reply) === '') {
            throw new CloudAiException(t('greg.err_no_model'));
        }
        return trim($reply);
    }

    /** OpenRouter's free Ling model, when an OpenRouter account exists. */
    private static function lingChat(string $prompt): string
    {
        $key = (string) (CloudAiService::config('openrouter')['api_key'] ?? '');
        if ($key === '') {
            throw new CloudAiException(t('greg.err_ling_key'));
        }
        $r = TtsHttp::request('POST', 'https://openrouter.ai/api/v1/chat/completions',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key],
            (string) json_encode([
                'model' => self::LING_MODEL,
                'max_tokens' => 500,
                'temperature' => 0.3,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]), 45);
        $status = (int) ($r['status'] ?? 0);
        $d = json_decode((string) ($r['body'] ?? ''), true);
        if ($status >= 400 || !is_array($d)) {
            // The provider's own short answer — no key, no request body echoed.
            throw new CloudAiException(t('greg.err_ling') . ' (' . $status . ')');
        }
        $text = $d['choices'][0]['message']['content'] ?? '';
        if (!is_string($text) || trim($text) === '') {
            throw new CloudAiException(t('greg.err_no_model'));
        }
        return trim($text);
    }

    // ------------------------------------------------------------- notices

    /**
     * One notice: in-app always (the avatar panel), e-mail for warn/critical
     * outside quiet hours when e-mail is on. Deduped per key for 6 h so a
     * repeating problem informs once — the "do not annoy" rule made code.
     *
     * @return array{id: int} | array{deduped: true} | array{skipped: string}
     */
    public static function notify(string $severity, string $title, string $body,
                                  string $dedupeKey = '', string $source = 'greg'): array
    {
        if (!self::enabled()) {
            return ['skipped' => 'disabled'];
        }
        if (!in_array($severity, ['info', 'warn', 'critical'], true)) {
            $severity = 'warn';
        }
        $dedupeKey = $dedupeKey !== '' ? substr($dedupeKey, 0, 64) : substr(sha1($severity . '|' . $title), 0, 64);
        $db = getDB();
        $st = $db->prepare(
            'SELECT id FROM pbx_greg_notices
             WHERE dedupe_key = ? AND created_at > (NOW() - INTERVAL ' . (int) self::DEDUPE_WINDOW . ' SECOND)
             LIMIT 1'
        );
        $st->execute([$dedupeKey]);
        if ($st->fetchColumn()) {
            return ['deduped' => true];
        }
        $ins = $db->prepare(
            'INSERT INTO pbx_greg_notices (created_at, severity, title, body, source, dedupe_key)
             VALUES (NOW(), ?, ?, ?, ?, ?)'
        );
        $ins->execute([$severity, mb_substr($title, 0, 150), $body, mb_substr($source, 0, 32), $dedupeKey]);
        $id = (int) $db->lastInsertId();
        writeAuditLog('greg', 'notice', $id, $title, 'create', null);

        $s = self::settings();
        if ($s['notice_email'] && in_array($severity, ['warn', 'critical'], true) && !self::quietHoursNow()) {
            if (self::emailNotice($severity, $title, $body)) {
                $db->prepare('UPDATE pbx_greg_notices SET emailed_at = NOW() WHERE id = ?')->execute([$id]);
            }
        }
        return ['id' => $id];
    }

    /** True inside the configured quiet window (may cross midnight). */
    public static function quietHoursNow(?int $ts = null): bool
    {
        $q = self::settings()['quiet_hours'];
        if (!preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $q, $m)) {
            return false;
        }
        $t = date('H:i', $ts ?? time());
        return $m[1] <= $m[2] ? ($t >= $m[1] && $t < $m[2]) : ($t >= $m[1] || $t < $m[2]);
    }

    /** @return array<int, array> newest notices for the top-bar panel */
    public static function recentNotices(int $limit = 12): array
    {
        $st = getDB()->prepare(
            'SELECT id, created_at, severity, title, body, read_at, emailed_at
             FROM pbx_greg_notices ORDER BY id DESC LIMIT ' . max(1, min(50, $limit))
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function unreadCount(): int
    {
        try {
            return (int) getDB()->query('SELECT COUNT(*) FROM pbx_greg_notices WHERE read_at IS NULL')->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** The configured display name (renamed on the Greg settings page). */
    public static function displayName(): string
    {
        return self::settings()['name'] ?: t('greg.default_name');
    }

    public static function markAllRead(): void
    {
        getDB()->exec('UPDATE pbx_greg_notices SET read_at = NOW() WHERE read_at IS NULL');
    }

    /**
     * One concise mail to every active admin, through the portal's own
     * health-alarm template (subject/body are already localized). A mail
     * failure must never break the operation — same contract as
     * writeAuditLog.
     */
    private static function emailNotice(string $severity, string $title, string $body): bool
    {
        try {
            $admins = getDB()->query(
                "SELECT id, email FROM sys_users
                 WHERE role = 'admin' AND email IS NOT NULL AND email != '' AND is_active = 1"
            )->fetchAll(PDO::FETCH_ASSOC);
            $name = self::settings()['name'];
            $ok = false;
            foreach ($admins as $a) {
                $lang = MailTemplateService::languageFor($a['email'], (int) $a['id']);
                $blocks = MailTemplateService::inLanguage($lang, fn() => [
                    'problems' => MailTemplateService::detailsBlock('', [
                        $name . ' — ' . t('ai_voip_engineer.sev_' . $severity) . ': ' . $title
                            => mb_substr($body, 0, 600),
                    ]),
                ]);
                $res = MailTemplateService::send('health_alarm', $a['email'], $lang, [
                    'count' => '1',
                    'checks' => $name,
                    'host' => gethostname() ?: 'aipbx',
                    'date' => date('d.m.Y H:i:s'),
                    'portal_link' => '/',
                ], $blocks);
                $ok = $ok || !empty($res['success']);
            }
            return $ok;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
