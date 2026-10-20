<?php
/**
 * Observability coverage — how much of the call path Greg can actually see.
 *
 * Greg's model does not get better when a collector is installed; the evidence
 * he can read does. This service turns that into one number the administrator
 * can act on and one context block the model reads before it answers:
 *
 *   capture      14  Asterisk sends HEP from this PBX (built-in or fan-out)
 *   HOMER        18  a HOMER target is configured and its last check passed
 *   VoIPmonitor  18  a VoIPmonitor target is configured and checked
 *   heplify      14  a heplify-server install is visible on this host
 *   Sipfront     14  keys are set and Echo is on, so test runs are readable
 *   RTCP         14  local RTCP reports exist for the quality window
 *   opencode      8  the engine's own logs are readable, for self-diagnosis
 *
 * Detection is deliberately passive: settings, the last stored health check,
 * fixed install paths, and one bounded RTCP query. No port scan, no login, no
 * shell command, and never a call to a collector's API. A configured but
 * unreachable collector earns little; a missing one earns nothing.
 *
 * The score is not a grade of the model. It says how much of the answer can be
 * evidence instead of theory — and the prompt block tells Greg which source to
 * cite, which one he cannot query, and when to ask for a Call-ID rather than
 * invent a ladder.
 */

require_once __DIR__ . '/ai/HepSettingsService.php';
require_once __DIR__ . '/ai/EchoService.php';
require_once __DIR__ . '/ai/OpencodeService.php';
require_once __DIR__ . '/CallQualityService.php';
require_once __DIR__ . '/GregReadinessService.php';

final class GregObservabilityService
{
    public const CAPTURE_MAX = 14;
    public const HOMER_MAX = 18;
    public const VOIPMONITOR_MAX = 18;
    public const HEPLIFY_MAX = 14;
    public const SIPFRONT_MAX = 14;
    public const RTCP_MAX = 14;
    public const OPENCODE_MAX = 8;

    /** Fixed, read-only paths: never search the disk or run `command -v`. */
    private const HEPLIFY_BINARIES = [
        '/usr/local/bin/heplify-server',
        '/usr/bin/heplify-server',
        '/opt/heplify-server/heplify-server',
        '/opt/heplify-server/bin/heplify-server',
    ];

    private const HEPLIFY_EVIDENCE = [
        '/etc/heplify-server.toml',
        '/etc/heplify-server/heplify-server.toml',
        '/etc/systemd/system/heplify-server.service',
        '/lib/systemd/system/heplify-server.service',
        '/usr/lib/systemd/system/heplify-server.service',
        '/opt/heplify-server',
    ];

    /** @return array{score:int, temperature:string, parts:array, missing:array, next:array} */
    public static function report(): array
    {
        return self::score(self::detect());
    }

    /**
     * What is installed/configured right now, as signals for score().
     *
     * @return array<string, array{state:string, detail:string, note:string, url:string}>
     */
    public static function detect(): array
    {
        return [
            'capture'     => self::capture(),
            'homer'       => self::collector('homer'),
            'voipmonitor' => self::collector('voipmonitor'),
            'heplify'     => self::heplify(),
            'sipfront'    => self::sipfront(),
            'rtcp'        => self::rtcp(),
            'opencode'    => self::opencode(),
        ];
    }

    /**
     * Pure scoring: a signal's state is worth all, most, little or none of its
     * share. Kept separate so the gauge, the prompt and the tests agree.
     *
     * @param array<string, array{state:string, detail:string, note:string, url?:string}> $signals
     * @return array{score:int, temperature:string, parts:array, missing:array, next:array}
     */
    public static function score(array $signals): array
    {
        $defs = [
            'capture'     => [self::CAPTURE_MAX, 'ai_voip_engineer.obs_part_capture', 'fa-tower-broadcast'],
            'homer'       => [self::HOMER_MAX, 'ai_voip_engineer.obs_part_homer', 'fa-layer-group'],
            'voipmonitor' => [self::VOIPMONITOR_MAX, 'ai_voip_engineer.obs_part_voipmonitor', 'fa-chart-line'],
            'heplify'     => [self::HEPLIFY_MAX, 'ai_voip_engineer.obs_part_heplify', 'fa-server'],
            'sipfront'    => [self::SIPFRONT_MAX, 'ai_voip_engineer.obs_part_sipfront', 'fa-vial-circle-check'],
            'rtcp'        => [self::RTCP_MAX, 'ai_voip_engineer.obs_part_rtcp', 'fa-wave-square'],
            'opencode'    => [self::OPENCODE_MAX, 'ai_voip_engineer.obs_part_opencode', 'fa-brain'],
        ];
        $factor = ['on' => 1.0, 'limited' => 0.6, 'down' => 0.2, 'off' => 0.0];

        $parts = [];
        $missing = [];
        $next = [];
        $score = 0;
        foreach ($defs as $key => [$max, $labelKey, $icon]) {
            $sig = $signals[$key] ?? self::signal('off', '', '');
            $points = (int) round($max * ($factor[$sig['state']] ?? 0.0));
            $score += $points;
            $label = t($labelKey);
            $parts[] = [
                'key'    => $key,
                'label'  => $label,
                'icon'   => $icon,
                'points' => $points,
                'max'    => $max,
                'state'  => $sig['state'],
                'detail' => $sig['detail'],
                'note'   => $sig['note'],
                'url'    => (string) ($sig['url'] ?? ''),
            ];
            if ($sig['state'] === 'off') {
                $missing[] = $label;
            }
            if ($points < $max) {
                [$action, $url] = self::nextAction($key, (string) $sig['state'], $label);
                if ($action !== '') {
                    $next[] = [
                        'key'    => $key,
                        'label'  => $label,
                        'gain'   => $max - $points,
                        'action' => $action,
                        'url'    => $url,
                        'state'  => $sig['state'],
                    ];
                }
            }
        }
        $score = max(0, min(100, $score));

        return [
            'score'       => $score,
            'temperature' => GregReadinessService::temperature($score),
            'parts'       => $parts,
            'missing'     => $missing,
            'next'        => $next,
        ];
    }

    /**
     * The block Greg reads. Always non-empty: saying "I have no capture" is a
     * better answer than inventing a SIP ladder, and the model needs the rule
     * even when every collector is missing.
     */
    public static function promptContext(): string
    {
        $r = self::report();
        $lines = ['Observability coverage on this PBX: ' . $r['score'] . '/100 ('
            . $r['temperature'] . ').'];
        $notes = [];
        foreach ($r['parts'] as $part) {
            if ($part['state'] !== 'off' && trim($part['note']) !== '') {
                $notes[] = '  · ' . $part['note'];
            }
        }
        if (!$notes) {
            $lines[] = '- Nothing is captured or queryable: no HEP capture, collector, Sipfront test data or local'
                . ' RTCP. Do not describe a SIP ladder, packet loss, jitter or audio you cannot see.'
                . ' Ask for the Call-ID and name the one source that would settle the question.';
            return implode("\n", $lines);
        }
        $lines[] = 'Sources in this environment, and their limits:';
        foreach ($notes as $note) {
            $lines[] = $note;
        }
        if ($r['missing']) {
            $lines[] = '- Not available: ' . implode(', ', $r['missing'])
                . '. Do not pretend to have read it. If one of them would settle the question, name it'
                . ' and the one step that enables it instead of guessing.';
        }
        $lines[] = '- Evidence order: any local counters/CDRs/RTCP in this prompt for what happened,'
            . ' Sipfront runs for an independent test, HOMER for the SIP ladder,'
            . ' VoIPmonitor for RTP and packet detail. opencode logs explain only your own'
            . ' missing, slow or refused answers — never call evidence.'
            . ' When sources disagree, say which one is closest to the media path and why.';
        $lines[] = '- Never claim to have opened a collector UI, run a packet capture, or heard audio.'
            . ' A configured collector you cannot query is something you point the administrator to,'
            . ' with the Call-ID and time window you want them to open.';
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------- detection

    /** @return array{state:string, detail:string, note:string, url:string} */
    private static function capture(): array
    {
        $hep = HepSettingsService::settings();
        $mode = (string) $hep['mode'];
        if ($mode === 'off') {
            return self::signal('off',
                t('ai_voip_engineer.obs_capture_off'),
                'HEP capture is off: no SIP, RTP or RTCP leaves the PBX for a collector.');
        }

        $address = $hep['host'] . ':' . $hep['port'];
        if (!in_array($mode, ['builtin', 'both'], true)) {
            $where = $address;
            return self::signal('limited',
                sprintf(t('ai_voip_engineer.obs_capture_remote'), $where),
                'HEP capture sends a copy to ' . $where . '. You cannot query that collector from this chat;'
                    . ' when a Call-ID is involved, give it to the administrator and name the UI to open.'
                    . ' Do not claim you read the capture yourself.',
                (string) $hep['ui_url']);
        }

        $file = HepSettingsService::currentFile();
        if (!$file['exists'] || $file['enabled'] === false || $file['capture_address'] === '') {
            return self::signal('limited',
                t('ai_voip_engineer.obs_capture_unapplied'),
                'HEP capture is configured but hep.conf is missing, disabled or has no capture_address;'
                    . ' Asterisk is not sending yet. Do not claim captured SIP.');
        }

        // The guard above already returned when capture_address was empty.
        $captured = (string) $file['capture_address'];
        return self::signal('on',
            sprintf(t('ai_voip_engineer.obs_capture_on'), $captured),
            'HEP capture is live at ' . $captured . '. SIP signalling, RTP/RTCP counters and CDRs are captured'
                . ' on this server; cite them as local evidence.');
    }

    /** @return array{state:string, detail:string, note:string, url:string} */
    private static function collector(string $key): array
    {
        $hep = HepSettingsService::settings();
        $mode = (string) $hep['mode'];
        $both = $mode === 'both';
        if (!$both && $mode !== $key) {
            return self::signal('off',
                t('ai_voip_engineer.obs_collector_off'),
                '', '');
        }

        $label = $key === 'homer' ? 'HOMER' : 'VoIPmonitor';
        // In a single-target mode the form's host/port/UI are the target; the
        // homer_*/voipmonitor_* fields exist for "both" and may be defaults.
        $host = (string) ($both
            ? ($key === 'homer' ? $hep['homer_host'] : $hep['voipmonitor_host'])
            : $hep['host']);
        $port = (int) ($both
            ? ($key === 'homer' ? $hep['homer_port'] : $hep['voipmonitor_port'])
            : $hep['port']);
        $ui = (string) ($both
            ? ($key === 'homer' ? $hep['homer_ui_url'] : $hep['voipmonitor_ui_url'])
            : $hep['ui_url']);
        $where = $host . ':' . $port . ($ui !== '' ? ' (UI: ' . $ui . ')' : '');

        $health = HepSettingsService::lastHealth();
        $state = 'limited';
        $detailKey = 'ai_voip_engineer.obs_collector_nohc';
        if ($health !== null) {
            if ($health['status'] === 'ok') {
                $state = 'on';
                $detailKey = 'ai_voip_engineer.obs_collector_ok';
            } elseif ($health['status'] === 'fail') {
                $state = 'down';
                $detailKey = 'ai_voip_engineer.obs_collector_fail';
            } elseif ($health['status'] === 'warn') {
                // The web tier answered with a server error; the collector is
                // installed but degraded, so it keeps some of its share.
                $detailKey = 'ai_voip_engineer.obs_collector_warn';
            }
        }

        $note = $label . ' is configured at ' . $where
            . ($health !== null
                ? '; last health check: ' . $health['status']
                    . (!empty($health['checked_at']) ? ' at ' . $health['checked_at'] : '')
                : '; no health check stored yet')
            . '. It holds ' . ($key === 'homer'
                ? 'the SIP ladder, registrations and Call-ID search'
                : 'per-call RTP, packet loss, MOS and packet captures')
            . '. You cannot query it from this chat; give the administrator the Call-ID and the exact view to open.';

        return self::signal($state, sprintf(t($detailKey), $label, $where), $note, $ui);
    }

    /** @return array{state:string, detail:string, note:string, url:string} */
    private static function heplify(): array
    {
        $binary = '';
        foreach (self::HEPLIFY_BINARIES as $path) {
            if (is_file($path) && is_executable($path)) {
                $binary = $path;
                break;
            }
        }
        if ($binary !== '') {
            return self::signal('on',
                sprintf(t('ai_voip_engineer.obs_heplify_on'), $binary),
                'heplify-server is installed on this host at ' . $binary
                    . '. It is a HEP collector, not a query endpoint: you cannot read its database from this chat.');
        }

        $evidence = [];
        foreach (self::HEPLIFY_EVIDENCE as $path) {
            if (file_exists($path)) {
                $evidence[] = $path;
            }
        }
        if ($evidence) {
            return self::signal('limited',
                sprintf(t('ai_voip_engineer.obs_heplify_partial'), implode(', ', array_slice($evidence, 0, 3))),
                'heplify-server configuration or a service unit exists (' . implode(', ', array_slice($evidence, 0, 3))
                    . '), but no binary was found in a known path. It may be installed elsewhere or not running;'
                    . ' do not claim it is collecting until the administrator confirms it.');
        }

        return self::signal('off',
            t('ai_voip_engineer.obs_heplify_off'),
            'heplify-server is not visible on this host.');
    }

    /** @return array{state:string, detail:string, note:string, url:string} */
    private static function sipfront(): array
    {
        $s = EchoService::settings();
        if (!$s['configured']) {
            return self::signal('off',
                t('ai_voip_engineer.obs_sipfront_none'),
                'Sipfront has no keys, so there is no external SIP test data.');
        }
        if (!$s['enabled']) {
            return self::signal('limited',
                t('ai_voip_engineer.obs_sipfront_off'),
                'Sipfront keys are set but Echo is switched off; enable Echo to read its test runs.');
        }
        return self::signal('on',
            t('ai_voip_engineer.obs_sipfront_on'),
            'Sipfront test runs are available through Echo; use them as an independent proof, separate from this PBX\'s own counters.');
    }

    /** @return array{state:string, detail:string, note:string, url:string} */
    private static function rtcp(): array
    {
        if (!CallQualityService::available()) {
            return self::signal('off',
                t('ai_voip_engineer.obs_rtcp_off'),
                'RTCP quality rows are not local: the capture target is remote, so you have no packet-loss evidence from this PBX.');
        }
        try {
            $summary = CallQualityService::summary();
        } catch (\Throwable $e) {
            return self::signal('limited',
                t('ai_voip_engineer.obs_rtcp_none'),
                'Local RTCP is configured but could not be read just now; do not claim the lines are healthy.');
        }
        if ((int) $summary['measured'] > 0) {
            return self::signal('on',
                sprintf(t('ai_voip_engineer.obs_rtcp_on'), (int) $summary['measured'], CallQualityService::WINDOW_MIN),
                'Local RTCP quality is measurable: ' . (int) $summary['measured'] . ' call(s) in the last '
                    . CallQualityService::WINDOW_MIN . ' minutes. Use the quality block for loss, evidence ids and actions.');
        }
        return self::signal('limited',
            t('ai_voip_engineer.obs_rtcp_none'),
            'Local RTCP is configured but no reports arrived in the window; capture may be off, the module unloaded, or no call carried RTCP.');
    }

    /**
     * The one action that earns a source's remaining share, and where to do it.
     * The card's detail says what is wrong; this says what to do next.
     *
     * @return array{0:string, 1:string} action, URL
     */
    private static function nextAction(string $key, string $state, string $label): array
    {
        switch ($key) {
            case 'capture':
                return [$state === 'off'
                    ? t('ai_voip_engineer.obs_next_capture_off')
                    : t('ai_voip_engineer.obs_next_capture_limited'), '/ai-echo'];
            case 'homer':
            case 'voipmonitor':
                if ($state === 'off') {
                    return [sprintf(t('ai_voip_engineer.obs_next_collector_off'), $label), '/ai-echo'];
                }
                return [$state === 'down'
                    ? t('ai_voip_engineer.obs_next_collector_down')
                    : t('ai_voip_engineer.obs_next_collector_limited'), '/health'];
            case 'heplify':
                return [$state === 'off'
                    ? t('ai_voip_engineer.obs_next_heplify_off')
                    : t('ai_voip_engineer.obs_next_heplify_limited'), '/ai-echo'];
            case 'sipfront':
                return [$state === 'off'
                    ? t('ai_voip_engineer.obs_next_sipfront_off')
                    : t('ai_voip_engineer.obs_next_sipfront_disabled'), '/ai-echo'];
            case 'rtcp':
                return [$state === 'off'
                    ? t('ai_voip_engineer.obs_next_rtcp_off')
                    : t('ai_voip_engineer.obs_next_rtcp_none'), '/ai-echo'];
            case 'opencode':
                return [$state === 'off'
                    ? t('ai_voip_engineer.obs_next_opencode_run')
                    : t('ai_voip_engineer.obs_next_opencode_check'), '/ai-echo'];
        }
        return ['', ''];
    }

    /** @return array{state:string, detail:string, note:string, url:string} */
    private static function opencode(): array
    {
        try {
            $log = OpencodeService::logReport();
        } catch (\Throwable $e) {
            return self::signal('limited',
                t('ai_voip_engineer.obs_opencode_unreadable'),
                'opencode logs could not be read just now; do not claim the engine ran.');
        }
        if (!$log['readable']) {
            return self::signal('off',
                t('ai_voip_engineer.obs_opencode_none'),
                'opencode logs: none found under the workshop workspace. The CLI has not run,'
                    . ' or its HOME is elsewhere; do not claim the engine ran.');
        }

        $when = (int) $log['updated'] > 0
            ? gmdate('Y-m-d H:i', (int) $log['updated']) . ' UTC'
            : t('ai_voip_engineer.obs_opencode_never');
        $stale = (int) $log['updated'] > 0 && (int) $log['updated'] < time() - 7 * 86400;
        $state = ($log['errors'] > 0 || $log['warnings'] > 0 || $log['parsed'] === 0 || $stale)
            ? 'limited' : 'on';
        $detail = (int) $log['parsed'] > 0
            ? sprintf(t('ai_voip_engineer.obs_opencode_detail'), $when, (int) $log['runs'],
                (int) $log['errors'], (int) $log['warnings'])
            : t('ai_voip_engineer.obs_opencode_unparsed');
        if ($stale) {
            $detail .= ' · ' . t('ai_voip_engineer.obs_opencode_stale');
        }

        $note = $log['summary'];
        if ((int) $log['parsed'] === 0) {
            $note = 'opencode logs exist but no runs could be parsed; the format may have changed.';
        }
        $note .= ' These are your own engine logs; use them to explain a missing, slow or refused'
            . ' answer, never as call evidence.';

        return self::signal($state, $detail, $note, '/ai-echo');
    }

    /** @return array{state:string, detail:string, note:string, url:string} */
    private static function signal(string $state, string $detail, string $note, string $url = ''): array
    {
        return ['state' => $state, 'detail' => $detail, 'note' => $note, 'url' => $url];
    }
}
