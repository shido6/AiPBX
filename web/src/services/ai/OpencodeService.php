<?php
/**
 * Greg's hands — opencode, but only to PROPOSE.
 *
 * On by default, like Greg himself; the switch on AI → Echo turns it off.
 * opencode can read a codebase, consult docs and write a plan. It is not
 * allowed to change anything here: every job runs with edit/write/bash/
 * external_directory DENIED (OPENCODE_PERMISSION), in an empty throwaway
 * workspace that holds nothing of the PBX, and its answer is stored as a
 * proposal for an administrator to read and act on. Approving a proposal
 * records the decision and the audit row; it does not install code.
 *
 * That is deliberate. opencode's docs offer permission rules, not a sandbox,
 * and "add a tool from GitHub" means running code from the internet on a PBX.
 * Greg proposes the tool and the exact config; a human decides.
 */

require_once __DIR__ . '/CloudAiService.php';
require_once __DIR__ . '/../../audit_log.php';

class OpencodeService
{
    public const DEFAULT_MODEL = 'opencode/big-pickle';
    public const DEFAULT_BIN = 'opencode';
    public const DEFAULT_WORKSPACE = '/var/lib/aipbx/opencode';
    public const MAX_OUTPUT = 12000;
    public const MIN_TASK = 8;
    public const MAX_TASK = 2000;

    /** @return array{enabled:bool, model:string, bin:string, workspace:string, timeout:int, available:bool} */
    public static function settings(): array
    {
        $bin = trim((string) getSystemSetting('greg.opencode_bin', self::DEFAULT_BIN));
        if ($bin === '') {
            $bin = self::DEFAULT_BIN;
        }
        $ws = trim((string) getSystemSetting('greg.opencode_workspace', self::DEFAULT_WORKSPACE));
        if ($ws === '' || $ws[0] !== '/') {
            $ws = self::DEFAULT_WORKSPACE;
        }
        $timeout = (int) getSystemSetting('greg.opencode_timeout', '180');
        if ($timeout < 30 || $timeout > 600) {
            $timeout = 180;
        }
        return [
            'enabled'   => getSystemSetting('greg.opencode_enabled', '1') === '1',
            'model'     => trim((string) getSystemSetting('greg.opencode_model', self::DEFAULT_MODEL)) ?: self::DEFAULT_MODEL,
            'bin'       => $bin,
            'workspace' => $ws,
            'timeout'   => $timeout,
            'available' => self::available($bin),
        ];
    }

    public static function save(array $post): array
    {
        $model = trim((string) ($post['opencode_model'] ?? self::DEFAULT_MODEL));
        if ($model !== '' && !preg_match('#^[a-z0-9._-]+/[A-Za-z0-9._:-]{1,80}$#', $model)) {
            throw new CloudAiException(t('ai_echo.oc_err_model'));
        }
        $db = getDB();
        $up = $db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $up->execute(['greg.opencode_enabled', !empty($post['opencode_enabled']) ? '1' : '0']);
        $up->execute(['greg.opencode_model', $model !== '' ? $model : self::DEFAULT_MODEL]);
        writeAuditLog('greg', 'opencode', 'greg',
            'opencode proposals ' . (!empty($post['opencode_enabled']) ? 'enabled' : 'disabled')
                . ' (model ' . ($model !== '' ? $model : self::DEFAULT_MODEL) . ')',
            'update', $_SESSION['user_id'] ?? null);
        return ['message' => t('ai_voip_engineer.msg_saved')];
    }

    /** Is the binary actually installed? Honest false, never an optimistic guess. */
    public static function available(?string $bin = null): bool
    {
        $bin = $bin ?? trim((string) getSystemSetting('greg.opencode_bin', self::DEFAULT_BIN));
        if ($bin === '') {
            return false;
        }
        if (str_contains($bin, '/')) {
            return is_file($bin) && is_executable($bin);
        }
        $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        foreach (explode(':', $path) as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $bin) && is_executable($dir . '/' . $bin)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ask opencode for an answer — Greg's Big Pickle brain.
     *
     * No API key, no account, no extra configuration: the model ships with
     * the CLI. This path is deliberately independent of the workshop switch,
     * because choosing Big Pickle as his source IS the configuration. It is
     * still read-only: same denied writes/shell/outside folders.
     */
    public static function chat(string $prompt): string
    {
        $s = self::settings();
        if (!$s['available']) {
            throw new CloudAiException(sprintf(t('greg.err_opencode_missing'), $s['bin']));
        }
        $ws = rtrim($s['workspace'], '/') . '/chat-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!is_dir($ws) && !@mkdir($ws, 0700, true) && !is_dir($ws)) {
            throw new CloudAiException(sprintf(t('ai_echo.oc_err_workspace'), $s['workspace']));
        }
        $res = self::run($s, $ws, $prompt);
        return trim($res['output']);
    }

    /**
     * Run one read-only opencode job and store its answer as a proposal.
     * @return array{id:int, output:string}
     */
    public static function propose(string $task, string $kind = 'tool'): array
    {
        $task = trim($task);
        if (mb_strlen($task) < self::MIN_TASK) {
            throw new CloudAiException(t('ai_echo.oc_err_task'));
        }
        $task = mb_substr($task, 0, self::MAX_TASK);
        $s = self::settings();
        if (!$s['enabled']) {
            throw new CloudAiException(t('ai_echo.oc_err_disabled'));
        }
        if (!$s['available']) {
            throw new CloudAiException(sprintf(t('ai_echo.oc_err_missing'), $s['bin']));
        }

        $ws = rtrim($s['workspace'], '/') . '/job-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!is_dir($ws) && !@mkdir($ws, 0700, true) && !is_dir($ws)) {
            throw new CloudAiException(sprintf(t('ai_echo.oc_err_workspace'), $s['workspace']));
        }

        $prompt = "You are the planning half of an AiPBX assistant. You have NO write access and NO shell. "
            . "Read nothing outside this empty workspace. Answer with a concise, concrete plan: what the tool "
            . "is, where it comes from (GitHub URL or MCP server package), what it would need (env vars, ports, "
            . "commands), the risks, and the exact configuration to add. Do not claim anything was installed.\n\n"
            . "Task: " . $task;

        $res = self::run($s, $ws, $prompt);
        $output = $res['output'];
        $db = getDB();
        $ins = $db->prepare(
            'INSERT INTO pbx_greg_proposals (created_at, created_by, kind, task, output, status, model)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $_SESSION['user_id'] ?? null,
            $kind === 'research' ? 'research' : 'tool',
            mb_substr($task, 0, 2000),
            mb_substr($output, 0, 65535),
            'pending',
            mb_substr($s['model'], 0, 120),
        ]);
        $id = (int) $db->lastInsertId();
        writeAuditLog('greg', 'proposal', $id, mb_substr($task, 0, 120), 'create', $_SESSION['user_id'] ?? null);
        return ['id' => $id, 'output' => $output];
    }

    /** Approve/reject. Records the decision; installing anything stays manual. */
    public static function decide(int $id, bool $approve): void
    {
        $db = getDB();
        $st = $db->prepare(
            'UPDATE pbx_greg_proposals
             SET status = ?, decided_at = NOW(), decided_by = ?
             WHERE id = ? AND status = ?'
        );
        $st->execute([$approve ? 'approved' : 'rejected', $_SESSION['user_id'] ?? null, $id, 'pending']);
        if ($st->rowCount() === 0) {
            throw new CloudAiException(t('ai_echo.oc_err_decision'));
        }
        writeAuditLog('greg', 'proposal', $id, 'proposal #' . $id, $approve ? 'approve' : 'reject', $_SESSION['user_id'] ?? null);
    }

    /** @return array<int, array> newest first */
    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return getDB()->query(
            'SELECT id, created_at, kind, task, output, status, model, decided_at
             FROM pbx_greg_proposals ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{output:string, exit:int, timed_out:bool}
     * @throws CloudAiException
     */
    private static function run(array $s, string $ws, string $prompt): array
    {
        $cmd = [
            $s['bin'], 'run',
            '--dir', $ws,
            '--model', $s['model'],
            '--format', 'json',
            '--pure',
            $prompt,
        ];
        $permission = json_encode([
            '*' => 'deny',
            'read' => 'allow',
            'glob' => 'allow',
            'grep' => 'allow',
            'edit' => 'deny',
            'bash' => 'deny',
            'external_directory' => 'deny',
        ]);
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $ws . '/.home',
            'OPENCODE_PERMISSION' => $permission,
            'OPENCODE_DISABLE_AUTOUPDATE' => '1',
            'OPENCODE_DISABLE_DEFAULT_PLUGINS' => '1',
            'NO_COLOR' => '1',
        ];
        @mkdir($ws . '/.home', 0700, true);

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, $ws, $env);
        if (!is_resource($proc)) {
            throw new CloudAiException(t('ai_echo.oc_err_start'));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $start = time();
        $timedOut = false;
        while (true) {
            $status = proc_get_status($proc);
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (time() - $start > $s['timeout']) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
            usleep(120000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        // A provider refusal (free-tier limit, offline) arrives as an error
        // event, not as an answer. Surface it honestly instead of showing the
        // JSON to the administrator as if it were Greg's reply.
        if ($providerError = self::extractError($out)) {
            throw new CloudAiException(mb_substr($providerError, 0, 280));
        }
        $text = trim(self::extractText($out));
        if ($text === '' && trim($err) !== '') {
            $text = trim($err);
        }
        if ($timedOut) {
            $text = ($text !== '' ? $text . "\n\n" : '') . '[stopped after ' . $s['timeout'] . ' s]';
        }
        if ($text === '') {
            throw new CloudAiException(t('ai_echo.oc_err_empty'));
        }
        return ['output' => mb_substr($text, 0, self::MAX_OUTPUT), 'exit' => $exit, 'timed_out' => $timedOut];
    }

    /**
     * opencode --format json streams JSON events. Keep readable text and the
     * final answer; drop the noise. Falls back to the raw output when the
     * binary prints plain text (an older version, or a stub in a test).
     */
    public static function extractText(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw[0] !== '{') {
            return $raw;
        }
        $texts = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $ev = json_decode(trim($line), true);
            if (!is_array($ev)) {
                continue;
            }
            foreach (['text', 'content', 'message'] as $k) {
                if (isset($ev[$k]) && is_string($ev[$k]) && trim($ev[$k]) !== '') {
                    $texts[] = trim($ev[$k]);
                }
            }
            // Final answer parts only: reasoning/thinking parts are the model
            // talking to itself, and they must not appear as Greg's reply.
            if (isset($ev['part']['text']) && is_string($ev['part']['text'])
                && ($ev['part']['type'] ?? 'text') === 'text') {
                $texts[] = trim(self::stripThinking($ev['part']['text']));
            }
        }
        $texts = array_values(array_filter($texts, fn($t) => $t !== ''));
        return $texts ? implode("\n", array_unique($texts)) : $raw;
    }

    /** Remove <think>…</think> blocks that some free models inline in text. */
    private static function stripThinking(string $text): string
    {
        $text = preg_replace('/<think[^>]*>.*?<\/think>/is', '', $text) ?? $text;
        return trim(preg_replace('/^\s*<\/think>\s*/i', '', $text) ?? $text);
    }

    /** The first provider error message in a JSON event stream, if any. */
    public static function extractError(string $raw): ?string
    {
        if (trim($raw) === '' || trim($raw)[0] !== '{') {
            return null;
        }
        foreach (preg_split('/\R/', $raw) as $line) {
            $ev = json_decode(trim($line), true);
            if (!is_array($ev) || ($ev['type'] ?? '') !== 'error') {
                continue;
            }
            $msg = $ev['error']['data']['message'] ?? ($ev['error']['message'] ?? '');
            if (is_string($msg) && trim($msg) !== '') {
                return trim($msg);
            }
            return t('ai_echo.oc_err_provider');
        }
        return null;
    }
}
