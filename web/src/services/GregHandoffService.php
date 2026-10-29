<?php
/**
 * The relief engineer: who has the phones when Greg is asleep.
 *
 * Greg's quiet hours already exist — the window in which he does not mail
 * anyone. The same window is when a second engineer takes over: Mahir Gul, the
 * relief VoIP engineer, who reads Greg's handover (the onboarding brief,
 * AGENTS.md, the captain's log) and answers with his own model source, so the
 * night is covered by a different pair of eyes rather than a silent assistant.
 *
 * A handoff is a real event, not a label: both engineers must be able to see
 * what changed and why, so it is written to AGENTS.md (the part Greg reads
 * first) and to the captain's log, and audited. The notes are bounded — the
 * last ten handoffs — and the administrator's own words in AGENTS.md are
 * never dropped to make room.
 *
 * The administrative decision lives in the session and the settings; the clock
 * decides the schedule. A manual handoff lasts until the next edge of the
 * quiet window, after which the schedule is back in charge.
 */

require_once __DIR__ . '/GregJournal.php';
require_once __DIR__ . '/GregService.php';
require_once __DIR__ . '/../audit_log.php';

class GregHandoffService
{
    /** The last handoffs kept in AGENTS.md. */
    public const MAX_HANDOFFS = 10;

    public static function path(): string
    {
        return GregJournal::baseDir() . '/HANDOFF.json';
    }

    /** @return array{last: string, manual: string|null, manual_until: int, at: int}|null */
    public static function state(): ?array
    {
        $path = self::path();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !in_array($data['last'] ?? '', ['greg', 'relief'], true)) {
            return null;
        }
        $manual = in_array($data['manual'] ?? '', ['greg', 'relief'], true) ? $data['manual'] : null;
        return [
            'last' => (string) $data['last'],
            'manual' => $manual,
            'manual_until' => (int) ($data['manual_until'] ?? 0),
            'at' => (int) ($data['at'] ?? 0),
        ];
    }

    /** Who the clock says should be on duty right now. */
    public static function scheduled(): string
    {
        $settings = GregService::settings();
        // Nobody is on duty while Greg is switched off, and a disabled relief
        // engineer never covers for him.
        if (!$settings['enabled'] || !$settings['relief_enabled']) {
            return 'greg';
        }
        return GregService::quietHoursNow() ? 'relief' : 'greg';
    }

    /** The engineer actually on duty, honouring a manual handoff until it lapses. */
    public static function onDuty(): string
    {
        $state = self::state();
        if ($state !== null && $state['manual'] !== null && $state['manual_until'] > time()) {
            return $state['manual'];
        }
        return self::scheduled();
    }

    /**
     * Called whenever someone talks to the engineer or opens the page. When the
     * engineer on duty differs from the last recorded one, the handoff is
     * written down once — not on every message.
     *
     * @return array{from: string, to: string, reason: string}|null
     */
    public static function ensure(): ?array
    {
        if (!GregService::settings()['enabled']) {
            return null;
        }
        // The relief engineer exists only while Greg has someone to hand to.
        if (!GregService::settings()['relief_enabled']) {
            if (self::state() !== null) {
                self::clear();
            }
            return null;
        }
        $on = self::onDuty();
        $state = self::state();
        if ($state === null) {
            self::save($on, null, 0);
            return null;
        }
        if ($state['last'] === $on) {
            return null;
        }
        return self::record($state['last'], $on, self::reasonFor($on));
    }

    /**
     * Hand the phones to the relief engineer now, until the next edge of the
     * quiet window. Refused when no relief engineer is configured.
     */
    public static function handOff(?int $until = null): array
    {
        return self::manual('relief', $until);
    }

    /** Take them back until the next edge of the quiet window. */
    public static function takeBack(?int $until = null): array
    {
        return self::manual('greg', $until);
    }

    private static function manual(string $engineer, ?int $until): array
    {
        if (!GregService::settings()['relief_enabled']) {
            throw new CloudAiException(t('ai_voip_engineer.err_relief_off'));
        }
        $until = $until ?? self::nextEdge();
        // The manual override changes who is on duty, not who was last seen:
        // ensure() must still see the difference and write the handoff down.
        $state = self::state();
        self::save($state['last'] ?? self::scheduled(), $engineer, $until);
        $change = self::ensure();
        return $change ?? [
            'from' => $engineer,
            'to' => $engineer,
            'reason' => t('ai_voip_engineer.handoff_manual'),
        ];
    }

    /** For tests and a fresh install: forget who was on duty. */
    public static function clear(): void
    {
        @unlink(self::path());
    }

    /** The next quiet-hours edge — the moment the schedule takes over again. */
    public static function nextEdge(): int
    {
        $quiet = GregService::settings()['quiet_hours'];
        if (!preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $quiet, $m)) {
            return time() + 3600;
        }
        $midnight = (int) strtotime('today 00:00');
        $edges = [];
        foreach ([[$m[1], $m[2]], [$m[3], $m[4]]] as $hm) {
            $ts = $midnight + ((int) $hm[0]) * 3600 + ((int) $hm[1]) * 60;
            while ($ts <= time()) {
                $ts += 86400;
            }
            $edges[] = $ts;
        }
        return min($edges);
    }

    // ------------------------------------------------------------ internals

    private static function reasonFor(string $engineer): string
    {
        $state = self::state();
        if ($state !== null && $state['manual'] === $engineer && $state['manual_until'] > time()) {
            return t('ai_voip_engineer.handoff_manual');
        }
        $quiet = GregService::settings()['quiet_hours'];
        if ($engineer === 'relief') {
            return sprintf(t('ai_voip_engineer.handoff_quiet'), $quiet);
        }
        return t('ai_voip_engineer.handoff_back');
    }

    private static function record(string $from, string $to, string $reason): array
    {
        $fromName = self::name($from);
        $toName = self::name($to);
        $when = gmdate('Y-m-d H:i');
        $line = $when . ' UTC — ' . $fromName . ' → ' . $toName . ' (' . $reason . ').'
            . ' Open: ' . GregService::unreadCount() . ' unread notices.';

        $agents = GregJournal::appendHandoff($line);
        GregJournal::append('Handoff: ' . $fromName . ' → ' . $toName, [
            'reason: ' . $reason,
            'AGENTS.md: ' . ($agents ? 'handoff written' : 'not written (no room)'),
        ]);
        writeAuditLog('greg', 'handoff', 0,
            'Handoff ' . $fromName . ' → ' . $toName . ' (' . $reason . ')',
            'update', $_SESSION['user_id'] ?? null);

        $state = self::state();
        self::save($to, $state['manual'] ?? null, $state['manual_until'] ?? 0);
        return ['from' => $fromName, 'to' => $toName, 'reason' => $reason];
    }

    private static function name(string $engineer): string
    {
        $settings = GregService::settings();
        return $engineer === 'relief' ? $settings['relief_name'] : $settings['name'];
    }

    private static function save(string $last, ?string $manual, int $until): void
    {
        $dir = GregJournal::baseDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }
        $path = self::path();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = (string) json_encode([
            'last' => $last,
            'manual' => $manual,
            'manual_until' => max(0, $until),
            'at' => time(),
        ], JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return;
        }
        @chmod($path, 0640);
    }
}
