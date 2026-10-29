<?php
/**
 * The egress ledger for opencode.
 *
 * Greg's brain and the workshop both run the opencode CLI. When that CLI uses a
 * remote model, the prompt it receives leaves this server. This service keeps
 * one encrypted row per attempt — the exact prompt, the final answer or the
 * error, the provider/model, how long it took and who asked — so an
 * administrator can answer "what did we send to that model, and what came
 * back?" without opening a packet capture or trusting a provider dashboard.
 *
 * Local providers are deliberately not recorded: nothing left the server.
 * The payloads are encrypted with SecretBox; sizes, hashes and metadata stay
 * readable for the list view. The record is bounded to RETENTION_DAYS.
 *
 * Scope: this is the application-level prompt handed to opencode and the
 * final text it returned. opencode adds its own system prompt, tool list and
 * workspace context before calling the provider; provider TLS is not
 * intercepted. The page says so rather than pretending this is a wire dump.
 */

require_once __DIR__ . '/../../secret_box.php';

final class OpencodeTrafficService
{
    public const RETENTION_DAYS = 90;
    public const MAX_PAYLOAD_BYTES = 1048576; // 1 MB per side
    public const MAX_ROWS = 100;

    /**
     * Provider prefixes that keep the model on this host. An unknown provider
     * is treated as remote and therefore recorded: over-recording is safer
     * than silently losing egress evidence.
     */
    private const LOCAL_PROVIDERS = [
        'ollama', 'lmstudio', 'llamacpp', 'llama-cpp', 'llamafile',
        'vllm', 'sglang', 'local', 'text-generation-webui', 'koboldcpp', 'jan',
    ];

    /** `opencode/big-pickle` → `opencode`; a bare model name is its own provider. */
    public static function providerOf(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return '';
        }
        $slash = strpos($model, '/');
        $colon = strpos($model, ':');
        $cut = $slash === false ? $colon : ($colon === false ? $slash : min($slash, $colon));
        return strtolower(trim($cut === false ? $model : substr($model, 0, $cut)));
    }

    public static function isLocalModel(string $model): bool
    {
        return in_array(self::providerOf($model), self::LOCAL_PROVIDERS, true);
    }

    /**
     * Record one attempt. Never throws: a logging failure must not take Greg's
     * answer down with it. Returns the new id, or 0 when nothing was recorded.
     *
     * @param string      $purpose    chat | proposal
     * @param string      $request    the exact prompt handed to opencode
     * @param string|null $response   the final answer, or null
     * @param string|null $error      the honest failure message, or null
     * @param string      $status     ok | timeout | error
     */
    public static function record(
        string $purpose,
        string $model,
        string $request,
        ?string $response,
        ?string $error,
        string $status,
        int $durationMs
    ): int {
        if (self::isLocalModel($model)) {
            return 0;
        }
        $purpose = in_array($purpose, ['chat', 'proposal'], true) ? $purpose : 'chat';
        $status = in_array($status, ['ok', 'timeout', 'error'], true) ? $status : 'error';
        $request = self::bounded($request);
        $response = self::bounded((string) ($response ?? ''));
        $error = self::redact(mb_substr(trim((string) ($error ?? '')), 0, 500));
        if ($status === 'ok') {
            $error = '';
        }

        try {
            $db = getDB();
            $st = $db->prepare(
                'INSERT INTO pbx_opencode_exchanges
                    (created_at, purpose, provider, model, status, error, duration_ms,
                     request_bytes, response_bytes, request_sha256, response_sha256,
                     request_enc, response_enc, created_by, user_name)
                 VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $purpose,
                self::providerOf($model),
                mb_substr(trim($model), 0, 120),
                $status,
                $error,
                max(0, $durationMs),
                strlen($request),
                strlen($response),
                hash('sha256', $request),
                hash('sha256', $response),
                SecretBox::encrypt($request),
                SecretBox::encrypt($response),
                isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                mb_substr(trim((string) ($_SESSION['username'] ?? '')), 0, 120),
            ]);
            $id = (int) $db->lastInsertId();
            self::prune();
            return $id;
        } catch (\Throwable $e) {
            error_log('opencode traffic: ' . $e->getMessage());
            return 0;
        }
    }

    /** @return array<int, array<string, mixed>> newest first, payloads excluded */
    public static function recent(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(self::MAX_ROWS, $limit));
        $offset = max(0, $offset);
        return getDB()->query(
            'SELECT id, created_at, purpose, provider, model, status, error, duration_ms,
                    request_bytes, response_bytes, created_by, user_name
             FROM pbx_opencode_exchanges ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One row with decrypted payloads, or null. */
    public static function find(int $id): ?array
    {
        $st = getDB()->prepare('SELECT * FROM pbx_opencode_exchanges WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['request'] = SecretBox::decrypt((string) $row['request_enc']);
        $row['response'] = SecretBox::decrypt((string) $row['response_enc']);
        unset($row['request_enc'], $row['response_enc']);
        return $row;
    }

    /** @return array{total:int, last_day:int, request_bytes:int, response_bytes:int, last_at:string} */
    public static function stats(): array
    {
        $row = getDB()->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(created_at >= (NOW() - INTERVAL 1 DAY)), 0) AS last_day,
                    COALESCE(SUM(request_bytes), 0) AS request_bytes,
                    COALESCE(SUM(response_bytes), 0) AS response_bytes,
                    COALESCE(MAX(created_at), '') AS last_at
             FROM pbx_opencode_exchanges"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total'          => (int) ($row['total'] ?? 0),
            'last_day'       => (int) ($row['last_day'] ?? 0),
            'request_bytes'  => (int) ($row['request_bytes'] ?? 0),
            'response_bytes' => (int) ($row['response_bytes'] ?? 0),
            'last_at'        => (string) ($row['last_at'] ?? ''),
        ];
    }

    /** Delete everything. Returns the number of removed rows. */
    public static function purge(): int
    {
        return (int) getDB()->exec('DELETE FROM pbx_opencode_exchanges');
    }

    private static function prune(): void
    {
        try {
            getDB()->exec(
                'DELETE FROM pbx_opencode_exchanges
                 WHERE created_at < (NOW() - INTERVAL ' . self::RETENTION_DAYS . ' DAY)'
            );
        } catch (\Throwable $e) {
            error_log('opencode traffic prune: ' . $e->getMessage());
        }
    }

    /** Provider errors can echo a token; keep the reason, drop the credential. */
    private static function redact(string $text): string
    {
        $text = preg_replace(
            '/\b(sk-[A-Za-z0-9_-]{8,}|ghp_[A-Za-z0-9]{8,}|github_pat_[A-Za-z0-9_]{8,}|xox[baprs]-[A-Za-z0-9-]{8,})\b/',
            '***',
            $text
        ) ?? $text;
        return preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/-]+/i', '$1***', $text) ?? $text;
    }

    private static function bounded(string $text): string
    {
        if (strlen($text) <= self::MAX_PAYLOAD_BYTES) {
            return $text;
        }
        return mb_strcut($text, 0, self::MAX_PAYLOAD_BYTES, 'UTF-8')
            . "\n\n[truncated at " . self::MAX_PAYLOAD_BYTES . ' bytes]';
    }
}
