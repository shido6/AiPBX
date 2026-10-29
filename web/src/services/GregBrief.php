<?php
/**
 * The onboarding brief: one markdown file Greg asks for.
 *
 * A real VoIP engineer starting on a new system gets a handover document —
 * what the business does, who calls, which numbers matter, which carrier is
 * which, who to wake at 3 a.m. Greg gets the same thing, deliberately as ONE
 * file in plain markdown, because that is the shape an administrator can
 * actually produce from what they already have.
 *
 * Storage follows GregJournal: a plain file under /var/lib/aipbx/greg, owned by
 * the web user, outside the git checkout, written atomically. Nothing here
 * accepts a path from the browser, and an upload is read and validated rather
 * than copied, so the file name and its directory never decide anything.
 *
 * The brief is configuration, not code: no customer name, no host name and no
 * secret belongs in the repository, and the template says so.
 */

require_once __DIR__ . '/GregJournal.php';

final class GregBrief
{
    /** A generous brief is a few dozen pages; beyond that something is wrong. */
    public const MAX_CHARS = 120000;

    /** What Greg reads on every answer — the rest stays on disk, searchable. */
    public const PROMPT_CHARS = 14000;

    public const FILENAME = 'greg-onboarding-brief.md';

    public static function path(): string
    {
        return GregJournal::baseDir() . '/BRIEF.md';
    }

    public static function exists(): bool
    {
        return is_file(self::path()) && is_readable(self::path());
    }

    /** The brief as stored ('' when there is none). */
    public static function text(): string
    {
        return self::exists() ? (string) file_get_contents(self::path()) : '';
    }

    /** Size and modification time, for the card. */
    public static function status(): array
    {
        $path = self::path();
        $exists = self::exists();
        return [
            'exists' => $exists,
            // Trailing whitespace we add ourselves is not a character the
            // administrator wrote, so the badge counts what they see.
            'chars' => $exists ? mb_strlen(trim(self::text())) : 0,
            'updated' => $exists ? (int) filemtime($path) : 0,
        ];
    }

    /**
     * A marked section of the brief, without its markers ('' when absent).
     *
     * Used by the onboarding conversation: what Greg learned in chat is
     * written into the same file, inside markers, so it can be found again,
     * replaced without duplicating, and edited by hand like everything else.
     */
    public static function section(string $marker): string
    {
        [$begin, $end] = self::markers($marker);
        $text = self::text();
        if ($text === '' || !preg_match('/' . preg_quote($begin, '/') . '(.*?)' . preg_quote($end, '/') . '/s', $text, $m)) {
            return '';
        }
        return trim($m[1]);
    }

    /**
     * Write a marked section into the brief: the prose the administrator wrote
     * stays as it is, and calling this again replaces only the block — a
     * conversation can be resumed, corrected or re-run without piling up
     * copies of the same answers.
     */
    public static function mergeSection(string $marker, string $title, string $body): void
    {
        [$begin, $end] = self::markers($marker);
        $block = $begin . "\n## " . trim($title) . "\n\n" . rtrim($body) . "\n" . $end;
        $text = rtrim(self::text());
        $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '/s';
        if ($text !== '' && preg_match($pattern, $text)) {
            $text = (string) preg_replace_callback($pattern, static fn() => $block, $text, 1);
        } else {
            $text = $text === '' ? $block : $text . "\n\n" . $block;
        }
        self::save($text, 'onboarding conversation');
    }

    /** @return array{0: string, 1: string} */
    private static function markers(string $marker): array
    {
        if (!preg_match('/^[a-z0-9-]{1,32}$/', $marker)) {
            throw new \InvalidArgumentException('Invalid brief section marker');
        }
        return ['<!-- greg:' . $marker . ' -->', '<!-- /greg:' . $marker . ' -->'];
    }

    /**
     * Save pasted text (or an already-read upload).
     * Rejects rather than truncating: half a brief is worse than a clear error.
     */
    public static function save(string $text, string $source = 'pasted'): void
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);
        if ($text === '') {
            throw new CloudAiException(t('ai_voip_engineer.err_brief_empty'));
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new CloudAiException(sprintf(t('ai_voip_engineer.err_brief_long'), number_format(self::MAX_CHARS)));
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new CloudAiException(t('ai_voip_engineer.err_brief_encoding'));
        }
        $dir = GregJournal::baseDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new CloudAiException(sprintf(t('ai_voip_engineer.err_notes_write'), $dir));
        }
        $path = self::path();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $text . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new CloudAiException(sprintf(t('ai_voip_engineer.err_notes_write'), $dir));
        }
        @chmod($path, 0640);
        writeAuditLog('greg', 'brief', 'onboarding',
            'Onboarding brief saved (' . mb_strlen($text) . ' characters, ' . $source . ')',
            'update', $_SESSION['user_id'] ?? null);
        GregJournal::append('Onboarding brief saved', [
            mb_strlen($text) . ' characters, ' . $source,
        ]);
    }

    /**
     * Take the uploaded file ($_FILES entry), validate it, and store its text.
     * Only .md/.markdown/.txt, only UTF-8, only inside the size limit.
     */
    public static function saveUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new CloudAiException(t('ai_voip_engineer.err_brief_empty'));
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new CloudAiException(t('ai_voip_engineer.err_brief_upload'));
        }
        $name = strtolower((string) ($file['name'] ?? ''));
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (!in_array($ext, ['md', 'markdown', 'txt'], true)) {
            throw new CloudAiException(t('ai_voip_engineer.err_brief_type'));
        }
        // The guard that matters: only a file PHP itself received is read.
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new CloudAiException(t('ai_voip_engineer.err_brief_upload'));
        }
        $raw = (string) file_get_contents((string) $file['tmp_name']);
        // A byte-order mark is the one non-UTF-8 byte we can fix silently.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        self::save($raw, 'uploaded ' . $ext);
    }

    public static function remove(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
        writeAuditLog('greg', 'brief', 'onboarding', 'Onboarding brief removed',
            'delete', $_SESSION['user_id'] ?? null);
        GregJournal::append('Onboarding brief removed', []);
    }

    /**
     * What goes into the prompt, before every answer.
     *
     * $firstAnswer: true only on the first reply of a conversation — Greg asks
     * for the document once, in his own words, and then gets on with the work.
     * A nagging assistant is worse than an uninformed one.
     */
    public static function context(bool $firstAnswer = false): string
    {
        $text = trim(self::text());
        if ($text === '') {
            if (!$firstAnswer) {
                return '';
            }
            return "You do not have an onboarding brief for this installation yet. You are the new engineer on your first day: "
                . "mention the missing handover once, briefly and in your own words, at the end of this answer — one markdown file "
                . "about the business and the phone system (customers, opening hours, the numbers that matter, carriers, call flows, "
                . "who to call, what must not break) is how a system is handed over. Say it can be uploaded under "
                . "AI → VoIP Engineer → Onboarding brief, and that saying “onboard me” makes you ask the six questions yourself, one "
                . "at a time, and write the file from the answers. Do not bring it up again unless the user asks.";
        }
        return "The administrator gave you an onboarding brief. Treat it as what the business told you about itself: "
            . "facts a colleague would have handed over on your first day. Where it disagrees with what you can observe now, "
            . "say which one looks stale rather than choosing silently. Never quote passwords, keys or card numbers from it.\n\n"
            . "ONBOARDING BRIEF:\n" . mb_substr($text, 0, self::PROMPT_CHARS)
            . (mb_strlen($text) > self::PROMPT_CHARS ? "\n\n[The brief continues; ask to see the rest if you need it.]" : '');
    }

    /**
     * The template Greg hands over: the questions a new engineer would ask,
     * in the order they are usually answerable.
     */
    public static function template(): string
    {
        return <<<'MD'
# Onboarding brief — <your company>

One file, plain markdown. Write it the way you would explain the place to a
new engineer on their first morning: what we do, what breaks, who to call.
Skip anything you do not know — an honest gap is more useful than a guess.

Do not put passwords, API keys, card numbers or SIP secrets in this file.
Greg does not need them and this document is meant to be shared.

## 1. The business

- What we do, in one paragraph:
- Who calls us (customers, patients, suppliers, staff):
- Opening hours, time zone, holidays:
- What a missed call costs us:
- The one number customers know us by:

## 2. The phone system

- How many extensions, sites, queues and IVRs:
- The lines that matter most (sales, emergency, on-call, reception):
- Call flows that must not break:
- Numbers/DIDs we answer on, and what each is for:
- Anything unusual: shared lines, hot desks, roaming phones, door phones.

## 3. Carriers and network

- Carriers / SIP providers, and what each is used for:
- The internet circuit, firewall, NAT, VPNs:
- Known carrier quirks (OPTIONS drops, codec limits, CLI rules):
- Who owns the carrier account:

## 4. People

- Who to call for what — names, roles, hours:
- Who approves changes:
- Who must be told when the phones are down:

## 5. Constraints and history

- Things that must not change, and why:
- Past incidents worth remembering:
- Anything you should never do here:
- Upcoming changes we already know about:

## 6. What good looks like

- The numbers we watch (answer rate, missed calls, queue time):
- Our worst recurring problem:
- If you had one week here, what would you fix first:

MD;
    }
}
