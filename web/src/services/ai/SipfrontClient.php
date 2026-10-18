<?php
/**
 * Sipfront's REST API (api/v2) — the SIP test platform Echo correlates with.
 *
 * Authentication is HTTP Basic: the PUBLIC key is the username, the SECRET is
 * the password (Sipfront → Account → API Keys → Create new key; the secret is
 * shown once). Both live in sys_settings: the public part plainly, the secret
 * through SecretBox, so a database dump does not reveal it.
 *
 * Only the endpoints Echo actually needs are wrapped, and every one of them
 * is a read except runTest() — which starts a test the administrator
 * configured on Sipfront, never an arbitrary call. Nothing here can change
 * this PBX.
 *
 * Response envelope: Sipfront answers {"data": …, "status": 200}. The exact
 * shape of a run row is not documented publicly, so rows are normalised
 * leniently (id/session_id, test_name/name, started_at, is_failed) and the
 * caller decides what to show.
 */

require_once __DIR__ . '/../../secret_box.php';
require_once __DIR__ . '/CloudAiService.php';

class SipfrontClient
{
    public const BASE = 'https://app.sipfront.com/api/v2';

    public static function publicKey(): string
    {
        return trim((string) getSystemSetting('echo.sipfront_public_key', ''));
    }

    /** Decrypted secret, or '' when absent/unreadable. Never logged or echoed. */
    public static function secret(): string
    {
        return trim(SecretBox::decrypt((string) getSystemSetting('echo.sipfront_secret', '')));
    }

    public static function configured(): bool
    {
        return self::publicKey() !== '' && self::secret() !== '';
    }

    /**
     * The honest check the page's "Test connection" button calls.
     * @return array{count:int}
     */
    public static function probe(): array
    {
        return ['count' => count(self::recentRuns(1))];
    }

    /** Newest runs first. @return array<int, array> */
    public static function recentRuns(int $count = 5): array
    {
        $count = max(1, min(50, $count));
        $json = self::request('GET', '/runs?count=' . $count . '&orderby=started_at&orderdir=desc');
        $data = $json['data'] ?? [];
        if (is_array($data) && isset($data['runs']) && is_array($data['runs'])) {
            $data = $data['runs'];
        }
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter($data, 'is_array'));
    }

    /** @return array run row (session_id, report_url, status_url) */
    public static function runTest(int $testId): array
    {
        if ($testId <= 0) {
            throw new CloudAiException(t('ai_echo.err_test_id'));
        }
        $json = self::request('POST', '/tests/' . $testId . '/run', []);
        $data = $json['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /** @return array status payload */
    public static function runStatus(string $sessionId): array
    {
        $sid = rawurlencode($sessionId);
        $json = self::request('GET', '/runs/' . $sid . '/status');
        $data = $json['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * @param array|null $body JSON body, null for GET
     * @return array decoded response
     * @throws CloudAiException with a localized, key-free message
     */
    private static function request(string $method, string $path, ?array $body = null, int $timeout = 20): array
    {
        if (!self::configured()) {
            throw new CloudAiException(t('ai_echo.not_configured'));
        }
        $headers = ['Accept: application/json'];
        $ch = curl_init(self::BASE . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERPWD        => self::publicKey() . ':' . self::secret(),
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            // The transport message can contain the URL but never the password.
            throw new CloudAiException(t('ai_echo.err_network') . ($curlError !== '' ? ' (' . mb_substr($curlError, 0, 80) . ')' : ''));
        }
        if ($status === 401 || $status === 403) {
            throw new CloudAiException(t('ai_echo.err_auth'));
        }
        if ($status >= 400) {
            throw new CloudAiException(t('ai_echo.err_http', [(string) $status]));
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new CloudAiException(t('ai_echo.err_http', [(string) $status]));
        }
        return $json;
    }
}
