<?php
/**
 * The systems document: the second half of a handover.
 *
 * The onboarding brief says what the business is — who calls, when, what
 * matters. This file says what the phone system is connected to and what it is
 * meant to watch: carriers and trunks, the CRM and ticketing behind them, the
 * monitoring and alerting around them, access and ownership, and the things
 * that are deliberately out of scope. A new engineer needs both maps before
 * they can be trusted with a production PBX; Greg is no different.
 *
 * Storage follows GregBrief and GregJournal: a plain markdown file under
 * /var/lib/aipbx/greg, outside the git checkout, atomic writes, 0640, and no
 * path ever comes from the browser. Only .md/.markdown/.txt, UTF-8, and a size
 * that is refused rather than truncated.
 *
 * Reference: the readiness card is what asks for this file; Greg reads it on
 * every answer once it exists.
 */

require_once __DIR__ . '/GregJournal.php';

final class GregSystems
{
    /** A systems map is shorter than a business brief; beyond this is a dump. */
    public const MAX_CHARS = 120000;

    /** What goes into the prompt — the rest stays on disk. */
    public const PROMPT_CHARS = 8000;

    public const FILENAME = 'greg-systems-document.md';

    public static function path(): string
    {
        return GregJournal::baseDir() . '/SYSTEMS.md';
    }

    public static function exists(): bool
    {
        return is_file(self::path()) && is_readable(self::path());
    }

    public static function text(): string
    {
        return self::exists() ? (string) file_get_contents(self::path()) : '';
    }

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
            'sections' => self::sections(),
        ];
    }

    /**
     * How many of the template's parts the document actually covers, by
     * heading. Used for the readiness card, not for grading the prose.
     */
    public static function sections(): int
    {
        $text = self::text();
        if ($text === '') {
            return 0;
        }
        $count = 0;
        if (preg_match_all('/^##\s+\S/m', $text, $m)) {
            $count = count($m[0]);
        }
        return min(6, $count);
    }

    public static function save(string $text, string $source = 'pasted'): void
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            throw new CloudAiException(t('ai_voip_engineer.err_sys_empty'));
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new CloudAiException(t('ai_voip_engineer.err_sys_encoding'));
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new CloudAiException(sprintf(t('ai_voip_engineer.err_sys_long'), number_format(self::MAX_CHARS)));
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
        writeAuditLog('greg', 'systems', 'document',
            'Systems document saved (' . mb_strlen($text) . ' characters, ' . $source . ')',
            'update', $_SESSION['user_id'] ?? null);
        GregJournal::append('Systems documentation saved', [
            mb_strlen($text) . ' characters, ' . $source,
        ]);
    }

    public static function saveUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new CloudAiException(t('ai_voip_engineer.err_sys_empty'));
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new CloudAiException(t('ai_voip_engineer.err_sys_upload'));
        }
        $name = strtolower((string) ($file['name'] ?? ''));
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (!in_array($ext, ['md', 'markdown', 'txt'], true)) {
            throw new CloudAiException(t('ai_voip_engineer.err_sys_type'));
        }
        // The guard that matters: only a file PHP itself received is read.
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new CloudAiException(t('ai_voip_engineer.err_sys_upload'));
        }
        $raw = (string) file_get_contents((string) $file['tmp_name']);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        self::save($raw, 'uploaded ' . $ext);
    }

    public static function remove(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
        writeAuditLog('greg', 'systems', 'document', 'Systems document removed',
            'delete', $_SESSION['user_id'] ?? null);
        GregJournal::append('Systems documentation removed', []);
    }

    /**
     * The block Greg reads before answering. Empty until there is a document,
     * so the prompt is unchanged on an installation that has not written one.
     */
    public static function context(): string
    {
        $text = trim(self::text());
        if ($text === '') {
            return '';
        }
        return "The administrator documented the systems this PBX is connected to and what it should watch. "
            . "Treat it as the map of the environment: it is what a colleague would have drawn on the whiteboard "
            . "on your first day. Where it disagrees with what you can observe, say which looks stale rather than "
            . "choosing silently, and never quote credentials from it.\n\n"
            . "SYSTEMS DOCUMENTATION:\n" . mb_substr($text, 0, self::PROMPT_CHARS)
            . (mb_strlen($text) > self::PROMPT_CHARS ? "\n\n[The systems document continues; ask to see the rest if you need it.]" : '');
    }

    /**
     * The template handed to the administrator: what is this PBX, what is it
     * connected to, what is it meant to watch, and what is out of scope.
     */
    public static function template(): string
    {
        return <<<'MD'
# Systems documentation — <your company>

One file, plain markdown. This is the other half of the handover: not what the
business does, but what the phone system is attached to and what it is meant to
watch. Write it for an engineer who is allowed to touch the system.

Do not put passwords, SIP secrets, API keys or card numbers in this file. Name
who owns an account instead.

## 1. What this PBX is for

- What this installation does, and what it deliberately does not do:
- Who depends on it (one team, a call centre, a whole company):
- The worst thing that can happen here:

## 2. Carriers and trunks

- Carriers / SIP providers, and which one carries what:
- Trunk names, DIDs and their purpose:
- Registration, IP allowlists, codecs, CLI rules:
- Known carrier behaviour: OPTIONS, retries, failover, outages:

## 3. Connected systems

- CRM, ticketing, ERP, e-mail, SMS, wallboards, door/PA, recording:
- Correlation / call capture: HOMER, VoIPmonitor or both, each UI address and retention:
- How each one talks to the PBX (AMI, API, webhook, database, screen-pop):
- Which integrations break calls if they are down, and which only lose data:

## 4. What Greg should watch

- The numbers that matter: answer rate, missed calls, queue time, trunk state:
- Thresholds worth an alert, and who receives it:
- Things that look like problems but are normal here:

## 5. Access and ownership

- Who owns the carrier accounts, the Anam/LLM keys and the DNS:
- Who can approve a configuration change:
- Where credentials live (never in this file):

## 6. Constraints and history

- Changes that must not be made, and why:
- Past incidents worth remembering:
- Planned work: migrations, new sites, carrier changes:

MD;
    }
}
