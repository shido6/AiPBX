<?php
/**
 * Greg's own memory: AGENTS.md plus a captain's log, on disk.
 *
 * AiPBX already records what it can see (CDRs, settings, audit rows). This is
 * the part no schema knows: "we put HOMER behind the VPN", "the carrier's
 * SBC drops OPTIONS at 30 s", "the workshop is read-only on purpose". Greg
 * reads these notes before every answer, the same way he reads Echo's counts.
 *
 * Deliberately plain files under /var/lib/aipbx/greg, owned by the web user,
 * not in the git checkout: an update must never overwrite what Greg learned,
 * and an operator can read or edit them with cat and vi. Writes are atomic
 * (tmp + rename). Nothing here accepts a path from the browser.
 */

require_once __DIR__ . '/ai/CloudAiService.php';

class GregJournal
{
    public const MAX_AGENTS = 20000;      // characters of AGENTS.md
    public const PROMPT_AGENTS = 4000;    // characters handed to the model
    public const PROMPT_LOG = 1600;
    public const RECENT_LINES = 14;       // shown on the page / in context
    public const MAX_LINE = 300;

    public static function baseDir(): string
    {
        $dir = getenv('AIPBX_GREG_DIR');
        $dir = is_string($dir) && $dir !== '' ? $dir : '/var/lib/aipbx/greg';
        return rtrim($dir, '/');
    }

    public static function agentsPath(): string
    {
        return self::baseDir() . '/AGENTS.md';
    }

    public static function logDir(): string
    {
        return self::baseDir() . '/captains-logs';
    }

    /** Today's log file, UTC — one file per day, like the repo's captains-logs. */
    public static function todayPath(): string
    {
        return self::logDir() . '/' . gmdate('Y-m-d') . '.md';
    }

    /** What is in AGENTS.md right now ('' when it does not exist yet). */
    public static function agents(): string
    {
        $path = self::agentsPath();
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        return (string) file_get_contents($path);
    }

    /** Replace AGENTS.md. Rejects rather than silently truncating. */
    public static function saveAgents(string $text, ?string $actor = null): void
    {
        $text = str_replace("\r\n", "\n", trim($text));
        if (mb_strlen($text) > self::MAX_AGENTS) {
            throw new CloudAiException(sprintf(t('ai_voip_engineer.err_notes_long'), (string) self::MAX_AGENTS));
        }
        self::ensureDirs();
        $path = self::agentsPath();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $text . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new CloudAiException(sprintf(t('ai_voip_engineer.err_notes_write'), self::baseDir()));
        }
        @chmod($path, 0640);
        writeAuditLog('greg', 'journal', 'agents.md',
            'AGENTS.md saved (' . mb_strlen($text) . ' characters)',
            'update', $_SESSION['user_id'] ?? null);
        self::append('AGENTS.md updated', [mb_strlen($text) . ' characters'], $actor);
    }

    /**
     * Append one dated entry to today's captain's log.
     * Every piece is flattened to a single line: a title or a detail can never
     * forge a heading or inject a new bullet.
     */
    public static function append(string $title, array $lines = [], ?string $actor = null): void
    {
        try {
            self::ensureDirs();
        } catch (\Throwable $e) {
            return; // a log that cannot be written must never break the action it describes
        }
        $path = self::todayPath();
        $new = !is_file($path);
        $title = self::oneLine($title, 120);
        $who = $actor !== null ? self::oneLine($actor, 60) : self::actorName();
        $entry = '- **' . gmdate('H:i') . ' UTC** — ' . ($title !== '' ? $title : 'note')
            . ($who !== '' ? ' (' . $who . ')' : '') . "\n";
        foreach ($lines as $line) {
            $line = self::oneLine((string) $line, self::MAX_LINE);
            if ($line !== '') {
                $entry .= '  - ' . $line . "\n";
            }
        }
        $body = ($new ? '# ' . t('ai_voip_engineer.notes_log_heading') . ' — ' . gmdate('Y-m-d') . "\n\n" : '')
            . $entry;
        if (@file_put_contents($path, $body, FILE_APPEND | LOCK_EX) === false) {
            // Never break the action being logged, but never lose the memory
            // silently either: a root-owned log file once stopped Greg's journal
            // without a trace.
            error_log('aipbx: Greg captain\'s log is not writable: ' . $path);
            return;
        }
        @chmod($path, 0640);
    }

    /**
     * Handoff notes already in AGENTS.md (the marked block only).
     * @return array<int, string> lines, each already starting with "- "
     */
    public static function handoffs(): array
    {
        if (!preg_match('/<!-- greg:handoffs -->(.*?)<!-- \/greg:handoffs -->/s', self::agents(), $m)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '- ')) {
                $out[] = $line;
            }
        }
        return array_slice($out, -10);
    }

    /**
     * Record a handoff where both engineers will see it: AGENTS.md, the file
     * Greg reads before anything else. Bounded to the last ten notes, and the
     * administrator's own words are never dropped to make room — if the file
     * is full, this returns false and the captain's log still has the entry.
     */
    public static function appendHandoff(string $line): bool
    {
        $line = self::oneLine($line, 280);
        if ($line === '') {
            return false;
        }
        $notes = self::handoffs();
        $notes[] = '- ' . $line;
        $notes = array_slice($notes, -10);

        $agents = self::agents();
        $pattern = '/<!-- greg:handoffs -->.*?<!-- \/greg:handoffs -->/s';
        $outside = trim((string) preg_replace($pattern, '', $agents));
        $build = static fn(array $notes): string => "<!-- greg:handoffs -->\n## Handoffs\n\n"
            . implode("\n", $notes) . "\n<!-- /greg:handoffs -->";

        while ($notes && mb_strlen($outside === '' ? $build($notes) : $outside . "\n\n" . $build($notes)) > self::MAX_AGENTS) {
            array_shift($notes);
        }
        if (!$notes) {
            return false;
        }

        $block = $build($notes);
        $text = trim($agents);
        if ($text !== '' && preg_match($pattern, $text)) {
            $text = (string) preg_replace_callback($pattern, static fn() => $block, $text, 1);
        } else {
            $text = $text === '' ? $block : $text . "\n\n" . $block;
        }
        if (mb_strlen($text) > self::MAX_AGENTS) {
            return false;
        }
        try {
            self::ensureDirs();
        } catch (\Throwable $e) {
            return false;
        }
        $path = self::agentsPath();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $text . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0640);
        // The event itself is audited once by GregHandoffService; reviewing a
        // handoff should not mean two rows for one shift change.
        return true;
    }

    /** The last entries, newest at the bottom — for the page. */
    public static function recentText(int $lines = self::RECENT_LINES): string
    {
        $path = self::todayPath();
        if (!is_file($path)) {
            $yesterday = self::logDir() . '/' . gmdate('Y-m-d', time() - 86400) . '.md';
            $path = is_file($yesterday) ? $yesterday : '';
        }
        if ($path === '' || !is_readable($path)) {
            return '';
        }
        $all = preg_split('/\R/', trim((string) file_get_contents($path))) ?: [];
        $all = array_values(array_filter($all, fn($l) => trim($l) !== '' && !str_starts_with($l, '# ')));
        return implode("\n", array_slice($all, -max(1, $lines)));
    }

    /**
     * The block Greg reads before answering. Empty until he has notes, so his
     * prompt is unchanged on a fresh install.
     */
    public static function context(): string
    {
        $notes = trim(self::agents());
        $log = trim(self::recentText(self::RECENT_LINES));
        if ($notes === '' && $log === '') {
            return '';
        }
        $out = "Your own notes about this installation (AGENTS.md and your captain's log). "
            . "Treat them as facts you recorded earlier; if they look stale, say so instead of inventing.";
        if ($notes !== '') {
            $out .= "\n\nAGENTS.md:\n" . mb_substr($notes, 0, self::PROMPT_AGENTS);
        }
        if ($log !== '') {
            $out .= "\n\nRecent captain's-log entries (UTC):\n" . mb_substr($log, -self::PROMPT_LOG);
        }
        return $out;
    }

    // ------------------------------------------------------------- internals

    private static function ensureDirs(): void
    {
        foreach ([self::baseDir(), self::logDir()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new CloudAiException(sprintf(t('ai_voip_engineer.err_notes_write'), self::baseDir()));
            }
        }
    }

    /** One line, no markdown control, bounded — safe to write into a log. */
    private static function oneLine(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_substr($text, 0, $limit);
    }

    private static function actorName(): string
    {
        $name = trim((string) ($_SESSION['username'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $id = (int) ($_SESSION['user_id'] ?? 0);
        return $id > 0 ? 'user #' . $id : '';
    }
}
