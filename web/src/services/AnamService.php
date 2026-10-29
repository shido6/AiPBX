<?php
/**
 * Anam.ai — an optional face and voice for Greg.
 *
 * Greg answers in text; with an Anam persona he also speaks, in a browser video
 * stream (WebRTC) driven by Anam's cloud. Nothing about the PBX changes: the
 * chat is still the source of truth, Greg's answer is what is spoken, and the
 * avatar is a face on top of it.
 *
 * Three rules shape the code:
 *   - The API key never leaves the server. The browser gets a session token
 *     (bound to the persona, one session) minted here with `POST
 *     /v1/auth/session-token`; Anam's own docs warn against the quickstart
 *     pattern that calls that endpoint from JavaScript with the key.
 *   - No persona id is shipped. Every installation creates its own persona in
 *     Anam (avatar, voice, and optionally its own LLM) and enters its id in
 *     the settings card; the id is configuration, not code. The API key is
 *     encrypted with SecretBox, so a database dump does not reveal it.
 *   - Every session has a hard length. A persona is billed by the minute, so
 *     the limit goes into the token itself (`maxSessionLengthSeconds`) and the
 *     page stops on the same deadline: the engine cuts it, and the browser
 *     does not keep talking to a session that has ended.
 *
 * Reference: https://anam.ai/docs/api-reference/overview (base /v1, bearer
 * auth), https://anam.ai/docs/javascript-sdk/authentication (token flow).
 */

require_once __DIR__ . '/../secret_box.php';
require_once __DIR__ . '/GregJournal.php';
require_once __DIR__ . '/../audit_log.php';

final class AnamService
{
    public const BASE = 'https://api.anam.ai/v1';
    public const DOCS = 'https://anam.ai/docs/api-reference/overview';
    public const PRICING = 'https://anam.ai/pricing';
    public const HOWTO = 'https://github.com/shido6/AiPBX/blob/main/howtos/greg-anam-avatar.md';
    public const SDK = '/assets/vendor/anam/anam.js';

    /** Meeting hosts Anam's Meetings API documents (Google Meet, Zoom, Teams). */
    public const MEETING_HOSTS = ['meet.google.com', 'zoom.us', 'teams.microsoft.com', 'teams.live.com'];
    public const MEETING_MAX_DAYS_AHEAD = 7;

    /** Anam's own free tier cuts a session at three minutes; the default matches it. */
    public const DEFAULT_SECONDS = 180;
    public const MIN_SECONDS = 30;
    public const MAX_SECONDS = 3600;

    private const K_ENABLED = 'greg.anam_enabled';
    private const K_PERSONA = 'greg.anam_persona_id';
    private const K_KEY = 'greg.anam_api_key';
    private const K_LIMIT = 'greg.anam_max_seconds';
    private const K_LISTEN = 'greg.anam_listen';

    // ------------------------------------------------------------- settings

    /**
     * @return array{enabled:bool, persona_id:string, key_set:bool, key_masked:string,
     *               limit:int, listen:bool, configured:bool}
     */
    public static function settings(): array
    {
        $persona = self::personaId();
        $key = self::apiKey();
        return [
            'enabled' => getSystemSetting(self::K_ENABLED, '0') === '1',
            'persona_id' => $persona,
            'key_set' => $key !== '',
            'key_masked' => SecretBox::mask($key),
            'limit' => self::sessionLimit(),
            'listen' => getSystemSetting(self::K_LISTEN, '0') === '1',
            'configured' => $persona !== '' && $key !== '',
        ];
    }

    /** The configured persona, or '' — never a built-in one. */
    public static function personaId(): string
    {
        $id = trim((string) getSystemSetting(self::K_PERSONA, ''));
        return preg_match('/^[A-Za-z0-9_-]{6,64}$/', $id) ? $id : '';
    }

    /** Decrypted key, or '' when absent/unreadable. Never logged or echoed. */
    public static function apiKey(): string
    {
        return trim(SecretBox::decrypt((string) getSystemSetting(self::K_KEY, '')));
    }

    /** Session length in seconds, clamped to a range that cannot run away. */
    public static function sessionLimit(): int
    {
        $raw = (int) getSystemSetting(self::K_LIMIT, (string) self::DEFAULT_SECONDS);
        if ($raw <= 0) {
            return self::DEFAULT_SECONDS;
        }
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $raw));
    }

    public static function configured(): bool
    {
        return self::personaId() !== '' && self::apiKey() !== '';
    }

    /** Is the face on the page *now* (configured and switched on)? */
    public static function active(): bool
    {
        $s = self::settings();
        return $s['enabled'] && $s['configured'];
    }

    /**
     * Save the card's form (admin only, CSRF checked by the controller).
     * @return array{message:string}
     */
    public static function save(array $post): array
    {
        $db = getDB();
        $up = $db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $del = $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?');

        $persona = trim((string) ($post['anam_persona_id'] ?? ''));
        if ($persona !== '' && !preg_match('/^[A-Za-z0-9_-]{6,64}$/', $persona)) {
            throw new CloudAiException(t('ai_voip_engineer.err_anam_persona'));
        }
        $up->execute([self::K_PERSONA, $persona]);

        $newKey = trim((string) ($post['anam_api_key'] ?? ''));
        if ($newKey !== '') {
            if (strlen($newKey) < 16) {
                throw new CloudAiException(t('ai_voip_engineer.err_anam_key'));
            }
            // Stored without a network check: the card's Test button verifies,
            // and a PBX without outbound access must still be able to save.
            $up->execute([self::K_KEY, SecretBox::encrypt($newKey)]);
        } elseif (!empty($post['anam_key_clear'])) {
            $del->execute([self::K_KEY]);
        }

        $limit = (int) ($post['anam_max_seconds'] ?? self::DEFAULT_SECONDS);
        if ($limit < self::MIN_SECONDS || $limit > self::MAX_SECONDS) {
            throw new CloudAiException(t('ai_voip_engineer.err_anam_limit'));
        }
        $up->execute([self::K_LIMIT, (string) $limit]);
        $up->execute([self::K_LISTEN, !empty($post['anam_listen']) ? '1' : '0']);

        $wanted = !empty($post['anam_enabled']);
        $have = self::personaId() !== '' && self::apiKey() !== '';
        if ($wanted && !$have) {
            // Refuse rather than store an "on" that can only fail later.
            $up->execute([self::K_ENABLED, '0']);
            throw new CloudAiException(t('ai_voip_engineer.err_anam_incomplete'));
        }
        $up->execute([self::K_ENABLED, $wanted ? '1' : '0']);

        writeAuditLog('greg', 'avatar', 'anam',
            'Anam avatar ' . ($wanted ? 'switched on' : 'switched off')
            . ($persona !== '' ? ' (persona …' . substr($persona, -6) . ')' : '')
            . ', ' . $limit . 's sessions',
            'update', $_SESSION['user_id'] ?? null);
        GregJournal::append('Greg avatar ' . ($wanted ? 'enabled' : 'updated'), [
            'anam persona: ' . ($persona !== '' ? 'set' : 'not set'),
            'api key: ' . (self::apiKey() !== '' ? 'set' : 'not set'),
            'session limit: ' . $limit . 's',
        ]);
        return ['message' => t('ai_voip_engineer.anam_saved')];
    }

    // --------------------------------------------------------------- Anam

    /**
     * The body Anam expects: which persona, how long it may run, and the AI
     * disclosure.
     *
     * `sessionOptions.showAIAvatarDisclosure` is bound to the token (Anam's
     * docs are explicit that the SDK cannot change it later), so the browser
     * cannot forget to render the watermark. That is the disclosure layer that
     * travels with the video; the stage is also labelled, and the chat greeting
     * already says he is an AI VoIP engineer.
     *
     * Reference: https://anam.ai/docs/security/ai-avatar-disclosure
     */
    public static function payload(string $personaId, ?int $seconds = null): array
    {
        return [
            'personaConfig' => [
                'personaId' => $personaId,
                'maxSessionLengthSeconds' => $seconds ?? self::sessionLimit(),
            ],
            'sessionOptions' => ['showAIAvatarDisclosure' => true],
        ];
    }

    /**
     * A browser token for the configured persona, valid for one session.
     * The API key stays here; only the token leaves.
     *
     * @return array{ok:bool, token:string, limit:int, error:string}
     */
    public static function sessionToken(): array
    {
        $limit = self::sessionLimit();
        if (!self::configured()) {
            return ['ok' => false, 'token' => '', 'limit' => $limit,
                    'error' => t('ai_voip_engineer.anam_not_configured')];
        }
        $r = self::request('POST', '/auth/session-token', self::payload(self::personaId(), $limit));
        $token = (string) ($r['json']['sessionToken'] ?? '');
        if (!$r['ok'] || $token === '') {
            return ['ok' => false, 'token' => '', 'limit' => $limit,
                    'error' => $r['error'] !== '' ? $r['error'] : t('ai_voip_engineer.anam_no_token')];
        }
        return ['ok' => true, 'token' => $token, 'limit' => $limit, 'error' => ''];
    }

    /**
     * "Test" on the card: does this key see this persona?
     * @return array{ok:bool, name:string, error:string}
     */
    public static function verify(): array
    {
        if (self::apiKey() === '' || self::personaId() === '') {
            return ['ok' => false, 'name' => '', 'error' => t('ai_voip_engineer.anam_not_configured')];
        }
        return self::verifyWith(self::apiKey(), self::personaId());
    }

    /**
     * @return array{ok:bool, name:string, error:string}
     */
    private static function verifyWith(string $key, string $personaId): array
    {
        $r = self::request('GET', '/personas/' . rawurlencode($personaId), null, $key);
        if (!$r['ok']) {
            return ['ok' => false, 'name' => '', 'error' => $r['error']];
        }
        return ['ok' => true, 'name' => (string) ($r['json']['name'] ?? ''), 'error' => ''];
    }

    // ------------------------------------------------------------- meetings

    /** Does the persona listen (microphone on) instead of only speaking? */
    public static function listens(): bool
    {
        return getSystemSetting(self::K_LISTEN, '0') === '1';
    }

    /**
     * '' when this is a meeting link Anam can join, otherwise the reason.
     * Deliberately strict: the key is sent with it, so only the hosts Anam
     * documents are accepted.
     *
     * @return string '' | error text
     */
    public static function meetingUrlProblem(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return t('ai_voip_engineer.err_anam_meeting_url');
        }
        if (strlen($url) > 500) {
            return t('ai_voip_engineer.err_anam_meeting_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return t('ai_voip_engineer.err_anam_meeting_url');
        }
        $host = strtolower($parts['host']);
        foreach (self::MEETING_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return '';
            }
        }
        return sprintf(t('ai_voip_engineer.err_anam_meeting_host'), implode(', ', self::MEETING_HOSTS));
    }

    /**
     * The body Anam's Meetings API expects. A persona that already exists in
     * the account is named by a TOP-LEVEL personaId — `personaConfig` is for
     * inline definitions (avatar + voice) and is rejected for a saved persona,
     * which the API says in as many words.
     *
     * @return array{meetingUrl:string, personaId:string, joinAt?:string, groupCall:bool}
     */
    public static function meetingPayload(string $url, string $joinAt = '', bool $groupCall = true): array
    {
        $body = [
            'meetingUrl' => trim($url),
            'personaId' => self::personaId(),
            'groupCall' => $groupCall,
        ];
        if ($joinAt !== '') {
            $body['joinAt'] = $joinAt;
        }
        return $body;
    }

    /**
     * Send Greg into a meeting (Google Meet, Zoom, Teams), now or later.
     * @return array{ok:bool, id:string, error:string}
     */
    public static function inviteToMeeting(string $url, string $joinAt = '', bool $groupCall = true): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'id' => '', 'error' => t('ai_voip_engineer.anam_not_configured')];
        }
        $problem = self::meetingUrlProblem($url);
        if ($problem !== '') {
            return ['ok' => false, 'id' => '', 'error' => $problem];
        }
        if ($joinAt !== '') {
            $ts = strtotime($joinAt);
            $now = time();
            if ($ts === false) {
                return ['ok' => false, 'id' => '', 'error' => t('ai_voip_engineer.err_anam_meeting_time')];
            }
            if ($ts < $now - 60 || $ts > $now + self::MEETING_MAX_DAYS_AHEAD * 86400) {
                return ['ok' => false, 'id' => '', 'error' => t('ai_voip_engineer.err_anam_meeting_time')];
            }
            $joinAt = gmdate('Y-m-d\TH:i:s\Z', $ts);
        }
        $r = self::request('POST', '/meetings/invites',
            self::meetingPayload($url, $joinAt, $groupCall));
        if (!$r['ok']) {
            return ['ok' => false, 'id' => '', 'error' => $r['error']];
        }
        $id = (string) ($r['json']['meetingId'] ?? $r['json']['id'] ?? '');
        return ['ok' => true, 'id' => $id, 'error' => ''];
    }

    /**
     * Send the persona into a meeting from the card (admin only).
     * The full link never reaches the audit log — only the host, because a
     * meeting link is often the only thing standing between a stranger and
     * the room.
     *
     * @return array{message:string}
     */
    public static function joinMeeting(array $post): array
    {
        $url = trim((string) ($post['meeting_url'] ?? ''));
        $joinAt = trim((string) ($post['meeting_at'] ?? ''));
        $group = !empty($post['meeting_group']);
        $r = self::inviteToMeeting($url, $joinAt, $group);
        if (!$r['ok']) {
            throw new CloudAiException($r['error']);
        }
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        writeAuditLog('greg', 'meeting', $host,
            'Anam persona invited to a meeting on ' . $host . ($joinAt !== '' ? ' at ' . $joinAt : ' now'),
            'create', $_SESSION['user_id'] ?? null);
        GregJournal::append('Greg joined a meeting', [
            'host: ' . $host,
            'when: ' . ($joinAt !== '' ? $joinAt : 'now'),
            'mode: ' . ($group ? 'group call — answers when addressed' : 'one-to-one'),
        ]);
        return ['message' => t('ai_voip_engineer.anam_meeting_sent')];
    }

    /**
     * One REST call. Errors are described, never dumped: the response body can
     * echo parts of a request, and the request carries the key.
     *
     * @return array{ok:bool, status:int, json:array, error:string}
     */
    private static function request(string $method, string $path, ?array $body, ?string $key = null): array
    {
        $fail = static fn(string $why, int $status = 0) => ['ok' => false, 'status' => $status, 'json' => [], 'error' => $why];
        if (!function_exists('curl_init')) {
            return $fail(t('ai_voip_engineer.anam_no_curl'));
        }
        $ch = curl_init(self::BASE . $path);
        $headers = ['Authorization: Bearer ' . ($key ?? self::apiKey()), 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = (string) curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return $fail($curlError !== '' ? $curlError : t('ai_voip_engineer.anam_unreachable'));
        }
        $json = json_decode((string) $raw, true);
        $json = is_array($json) ? $json : [];
        if ($status === 401 || $status === 403) {
            return $fail(t('ai_voip_engineer.anam_bad_key'), $status);
        }
        if ($status === 404) {
            return $fail(t('ai_voip_engineer.anam_no_persona'), $status);
        }
        if ($status < 200 || $status >= 300) {
            return $fail(sprintf(t('ai_voip_engineer.anam_http'), $status), $status);
        }
        return ['ok' => true, 'status' => $status, 'json' => $json, 'error' => ''];
    }
}
