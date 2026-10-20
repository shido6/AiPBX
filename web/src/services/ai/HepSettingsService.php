<?php
/**
 * Correlation assistance / HEP capture target — where Asterisk sends SIP/RTCP,
 * configured from Echo.
 *
 * Five honest choices:
 *   builtin      — this portal's own daemon (127.0.0.1:9061). Local counts and
 *                  Echo's SIP error numbers keep working with no other software.
 *   homer        — a HOMER (sipcapture) collector on this server, UDP 9060 by
 *                  default. HOMER brings its own UI, search and retention.
 *   voipmonitor  — a VoIPmonitor sniffer on this server, also UDP 9060 by
 *                  default. Its HEP listener must be enabled (hep = yes,
 *                  hep_bind_ip, hep_bind_port); the sniffer is GPL and its
 *                  web GUI is licensed per channel.
 *   both         — HOMER AND VoIPmonitor at the same time, on this server or
 *                  another host. Asterisk's res_hep has one capture_address,
 *                  so the built-in daemon receives the stream and forwards an
 *                  unchanged copy to each collector (see forwardPath()). Both
 *                  collectors keep their own UI and retention; the local rows
 *                  keep Greg's numbers working. Each collector processes the
 *                  full stream, so their combined load is roughly double one.
 *   external     — a HOMER, VoIPmonitor or other HEP collector elsewhere. HEP
 *                  over plain UDP is NOT encrypted and capture_password is a
 *                  shared secret, not transport security: use a VPN or a
 *                  restricted firewall.
 *
 * The portal generates /etc/asterisk/hep.conf (like rtp.conf) and reloads
 * res_hep, so the page and the file cannot disagree. Values are validated
 * here, not only in the form: a newline or a semicolon in a field would
 * otherwise inject arbitrary Asterisk configuration.
 */

require_once __DIR__ . '/../../secret_box.php';
require_once __DIR__ . '/../GregJournal.php';
require_once __DIR__ . '/../../asterisk_helper.php';
require_once __DIR__ . '/../../audit_log.php';

class HepSettingsService
{
    public const MODES = ['off', 'builtin', 'homer', 'voipmonitor', 'both', 'external'];
    public const BUILTIN_PORT = 9061;
    public const HOMER_PORT = 9060;
    public const VOIPMONITOR_PORT = 9060;
    public const COLLECTOR_PORT = 9060;
    /** Second local port for the "both" mode; 9060 is already taken there. */
    public const BOTH_VOIPMONITOR_PORT = 9062;
    public const DEFAULT_PASSWORD = 'aipbx';
    /** Written by the portal, read by aipbx-signaling; not an Asterisk file. */
    public const DEFAULT_FORWARD_PATH = '/var/lib/aipbx/signaling/forward.json';
    public const DEFAULT_CAPTURE_ID = 1001;

    public static function confPath(): string
    {
        return ASTERISK_CONF_DIR . '/hep.conf';
    }

    /** Where the daemon reads its fan-out list (both mode); env override for tests. */
    public static function forwardPath(): string
    {
        $path = getenv('AIPBX_SIGNALING_FORWARD');
        return is_string($path) && $path !== '' ? $path : self::DEFAULT_FORWARD_PATH;
    }

    /** @return array{mode:string, enabled:bool, host:string, port:int, password:string,
     *                password_set:bool, capture_id:int, ui_url:string, provider:string,
     *                homer_host:string, homer_port:int, homer_ui_url:string,
     *                voipmonitor_host:string, voipmonitor_port:int, voipmonitor_ui_url:string,
     *                form_host:string, form_port:int, form_ui_url:string, targets:array} */
    public static function settings(): array
    {
        $mode = (string) getSystemSetting('hep.mode', 'builtin');
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'builtin';
        }
        // The single-target fields are also the fallback for switching back
        // from "both"; they are returned separately as form_* so the form never
        // shows the daemon's 9061 as a collector address.
        $formHost = trim((string) getSystemSetting('hep.host', ''));
        if ($formHost === '') {
            $formHost = $mode === 'external' ? '' : '127.0.0.1';
        }
        $formPort = (int) getSystemSetting('hep.port', '0');
        if ($formPort < 1 || $formPort > 65535) {
            $formPort = in_array($mode, ['homer', 'voipmonitor', 'both'], true)
                ? self::COLLECTOR_PORT : self::BUILTIN_PORT;
        }
        $host = $formHost;
        $port = $formPort;
        // "hep.homer_url" is the key older installations wrote; read it as a
        // fallback and let the next save migrate it to the neutral name.
        $uiUrl = trim((string) getSystemSetting('hep.ui_url',
            (string) getSystemSetting('hep.homer_url', '')));
        $homerHost = trim((string) getSystemSetting('hep.homer_host', '127.0.0.1'));
        $homerPort = (int) getSystemSetting('hep.homer_port', (string) self::HOMER_PORT);
        $homerUi = trim((string) getSystemSetting('hep.homer_ui_url', ''));
        $vmHost = trim((string) getSystemSetting('hep.voipmonitor_host', '127.0.0.1'));
        $vmPort = (int) getSystemSetting('hep.voipmonitor_port', (string) self::BOTH_VOIPMONITOR_PORT);
        $vmUi = trim((string) getSystemSetting('hep.voipmonitor_ui_url', ''));
        if ($homerHost === '') {
            $homerHost = '127.0.0.1';
        }
        if ($vmHost === '') {
            $vmHost = '127.0.0.1';
        }
        if ($homerPort < 1 || $homerPort > 65535) {
            $homerPort = self::HOMER_PORT;
        }
        if ($vmPort < 1 || $vmPort > 65535) {
            $vmPort = self::BOTH_VOIPMONITOR_PORT;
        }
        // In both mode Asterisk talks to the built-in daemon, which fans the
        // stream out; the effective capture address is therefore 9061.
        if ($mode === 'both') {
            $host = '127.0.0.1';
            $port = self::BUILTIN_PORT;
        }
        $stored = self::decryptPassword((string) getSystemSetting('hep.password', ''));
        $captureId = (int) getSystemSetting('hep.capture_id', (string) self::DEFAULT_CAPTURE_ID);
        if ($captureId < 1 || $captureId > 999999) {
            $captureId = self::DEFAULT_CAPTURE_ID;
        }
        $settings = [
            'mode'         => $mode,
            'enabled'      => $mode !== 'off',
            'host'         => $host,
            'port'         => $port,
            'password'     => $stored !== '' ? $stored : self::DEFAULT_PASSWORD,
            'password_set' => $stored !== '',
            'capture_id'   => $captureId,
            'ui_url'       => $uiUrl,
            'provider'     => in_array($mode, ['homer', 'voipmonitor'], true) ? $mode : '',
            'homer_host'   => $homerHost,
            'homer_port'   => $homerPort,
            'homer_ui_url' => $homerUi,
            'voipmonitor_host'   => $vmHost,
            'voipmonitor_port'   => $vmPort,
            'voipmonitor_ui_url' => $vmUi,
            'form_host'    => $formHost,
            'form_port'    => $formPort,
            'form_ui_url'  => $uiUrl,
        ];
        $settings['targets'] = self::targets($settings);
        return $settings;
    }

    /** The two collectors of "both" mode, in display order. Empty for other modes. */
    public static function targets(array $s): array
    {
        if (($s['mode'] ?? '') !== 'both') {
            return [];
        }
        return [
            ['key' => 'homer', 'label' => 'HOMER',
             'host' => (string) ($s['homer_host'] ?? '127.0.0.1'),
             'port' => (int) ($s['homer_port'] ?? self::HOMER_PORT),
             'ui_url' => (string) ($s['homer_ui_url'] ?? '')],
            ['key' => 'voipmonitor', 'label' => 'VoIPmonitor',
             'host' => (string) ($s['voipmonitor_host'] ?? '127.0.0.1'),
             'port' => (int) ($s['voipmonitor_port'] ?? self::BOTH_VOIPMONITOR_PORT),
             'ui_url' => (string) ($s['voipmonitor_ui_url'] ?? '')],
        ];
    }

    /**
     * Save + write + reload. Order matters: the file is written first and the
     * previous content restored if Asterisk refuses the reload, so a failed
     * apply never leaves a half-configured PBX.
     */
    public static function save(array $post): array
    {
        $mode = (string) ($post['hep_mode'] ?? 'builtin');
        if (!in_array($mode, self::MODES, true)) {
            throw new CloudAiException(t('ai_echo.hep_err_mode'));
        }
        $host = trim((string) ($post['hep_host'] ?? ''));
        $port = trim((string) ($post['hep_port'] ?? ''));
        $password = trim((string) ($post['hep_password'] ?? ''));
        $captureId = trim((string) ($post['hep_capture_id'] ?? ''));
        // Accept the old field name so a page cached before this change (or an
        // installation still holding hep.homer_url) saves without losing the URL.
        // A form that does not carry the field at all keeps the stored address.
        $hasUiField = array_key_exists('hep_ui_url', $post) || array_key_exists('hep_homer_url', $post);
        $previous = self::settings();
        $uiUrl = $hasUiField
            ? trim((string) ($post['hep_ui_url'] ?? $post['hep_homer_url'] ?? ''))
            : $previous['ui_url'];

        // The two "both" slots are validated whatever mode is selected, so a
        // value the administrator can see can never sit there unvalidated.
        $both = self::bothTargetsFromPost($post, $previous);

        $isLocal = in_array($mode, ['builtin', 'homer', 'voipmonitor', 'off', 'both'], true);
        if ($mode === 'both') {
            // Asterisk has one capture_address: it talks to the built-in
            // daemon, which forwards an unchanged copy to each collector.
            $host = '127.0.0.1';
            $port = (string) self::BUILTIN_PORT;
            // The single-target fields are ignored here; keep them for the
            // day the administrator switches back to one collector.
            $uiUrl = $previous['ui_url'];
            if (strcasecmp($both[0]['host'], $both[1]['host']) === 0
                    && (int) $both[0]['port'] === (int) $both[1]['port']) {
                throw new CloudAiException(t('ai_echo.hep_err_same_target'));
            }
        } else {
            if ($mode === 'builtin') {
                // The local daemon always listens on 9061; a stored 9060 (for
                // example after a spell in "both") must not silently point the
                // PBX at a port nothing is listening on.
                $host = '127.0.0.1';
                $port = (string) self::BUILTIN_PORT;
            } elseif ($isLocal) {
                $host = '127.0.0.1';
                if ($port === '') {
                    $port = (string) (in_array($mode, ['homer', 'voipmonitor'], true)
                        ? self::COLLECTOR_PORT
                        : self::BUILTIN_PORT);
                }
            } elseif ($mode === 'external') {
                if ($host === '' || !preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $host)) {
                    throw new CloudAiException(t('ai_echo.hep_err_host'));
                }
                if ($port === '') {
                    $port = (string) self::COLLECTOR_PORT;
                }
            }
            if (!ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
                throw new CloudAiException(t('ai_echo.hep_err_port'));
            }
            if ($uiUrl !== '' && (!filter_var($uiUrl, FILTER_VALIDATE_URL)
                    || !preg_match('#^https?://#i', $uiUrl))) {
                throw new CloudAiException(t('ai_echo.hep_err_url'));
            }
        }
        if ($captureId === '') {
            $captureId = (string) self::DEFAULT_CAPTURE_ID;
        }
        if (!ctype_digit($captureId) || (int) $captureId < 1 || (int) $captureId > 999999) {
            throw new CloudAiException(t('ai_echo.hep_err_capture_id'));
        }
        // A space, newline or ';' would change the shape of the config file.
        if ($password !== '' && !preg_match('/^[A-Za-z0-9._@#%+-]{1,64}$/', $password)) {
            throw new CloudAiException(t('ai_echo.hep_err_password'));
        }

        // What the single-target fields keep for the next switch. In both mode
        // store a neutral collector address, never the daemon's 9061.
        $singleHost = $mode === 'both' ? '127.0.0.1' : ($isLocal ? '127.0.0.1' : $host);
        $singlePort = $mode === 'both' ? self::COLLECTOR_PORT : (int) $port;

        $settings = [
            'mode'       => $mode,
            'enabled'    => $mode !== 'off',
            'host'       => $isLocal ? '127.0.0.1' : $host,
            'port'       => (int) $port,
            'password'   => $password !== '' ? $password : $previous['password'],
            'capture_id' => (int) $captureId,
            'ui_url'     => $uiUrl,
            'provider'   => in_array($mode, ['homer', 'voipmonitor'], true) ? $mode : '',
            'homer_host' => $both[0]['host'],
            'homer_port' => (int) $both[0]['port'],
            'homer_ui_url' => $both[0]['ui_url'],
            'voipmonitor_host' => $both[1]['host'],
            'voipmonitor_port' => (int) $both[1]['port'],
            'voipmonitor_ui_url' => $both[1]['ui_url'],
        ];

        // The forward list comes first: when switching TO both, collectors are
        // already listed before res_hep starts sending to the daemon. If the
        // Asterisk half fails, the previous list is put back.
        $forwardPath = self::forwardPath();
        $previousForward = is_file($forwardPath) ? (string) file_get_contents($forwardPath) : null;
        try {
            self::writeForward(self::forwardConfig($settings));
            self::apply($settings);
        } catch (\Throwable $e) {
            self::restoreForward($previousForward);
            throw $e;
        }

        $db = getDB();
        $up = $db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $up->execute(['hep.mode', $settings['mode']]);
        $up->execute(['hep.host', $singleHost]);
        $up->execute(['hep.port', (string) $singlePort]);
        $up->execute(['hep.ui_url', $settings['ui_url']]);
        $up->execute(['hep.capture_id', (string) $settings['capture_id']]);
        $up->execute(['hep.homer_host', $settings['homer_host']]);
        $up->execute(['hep.homer_port', (string) $settings['homer_port']]);
        $up->execute(['hep.homer_ui_url', $settings['homer_ui_url']]);
        $up->execute(['hep.voipmonitor_host', $settings['voipmonitor_host']]);
        $up->execute(['hep.voipmonitor_port', (string) $settings['voipmonitor_port']]);
        $up->execute(['hep.voipmonitor_ui_url', $settings['voipmonitor_ui_url']]);
        // The neutral key now holds it; do not let the legacy key resurrect an
        // old address when the administrator clears the field.
        $db->exec("DELETE FROM sys_settings WHERE setting_key = 'hep.homer_url'");
        if (!empty($post['hep_password'])) {
            $up->execute(['hep.password', SecretBox::encrypt($settings['password'])]);
        }
        writeAuditLog('echo', 'hep_settings', 'hep',
            'Correlation assistance: ' . self::describe($settings),
            'update', $_SESSION['user_id'] ?? null);
        GregJournal::append('Correlation assistance applied', array_filter(array_merge(
            [self::describe($settings)],
            array_map(fn($t) => $t['ui_url'] !== '' ? $t['label'] . ' UI: ' . $t['ui_url'] : '',
                self::targets($settings)),
            [$settings['ui_url'] !== '' ? 'UI: ' . $settings['ui_url'] : '']
        )));
        return ['message' => t('ai_echo.hep_saved')];
    }

    /** One line for the audit log, the page and Greg. */
    public static function describe(array $s): string
    {
        if (empty($s['enabled'])) {
            return 'off';
        }
        if (($s['mode'] ?? '') === 'both') {
            $parts = [];
            foreach (self::targets($s) as $t) {
                $parts[] = $t['label'] . ' → ' . $t['host'] . ':' . $t['port'];
            }
            return implode(' + ', $parts);
        }
        $label = [
            'builtin' => 'built-in capture',
            'homer' => 'HOMER',
            'voipmonitor' => 'VoIPmonitor',
            'external' => 'external HEP',
        ][$s['mode']] ?? $s['mode'];
        return $label . ' → ' . $s['host'] . ':' . $s['port'];
    }

    /** "HOMER 127.0.0.1:9060 and VoIPmonitor 127.0.0.1:9062" — probe/journal text. */
    public static function targetSummary(array $s): string
    {
        $parts = [];
        foreach (self::targets($s) as $t) {
            $parts[] = $t['label'] . ' ' . $t['host'] . ':' . $t['port'];
        }
        return implode(' and ', $parts);
    }

    /** The generated file. Pure: no writes, no reloads — testable as-is. */
    public static function renderConf(array $s): string
    {
        $enabled = !empty($s['enabled']) ? 'yes' : 'no';
        $conf  = "; Correlation assistance / HEP capture (generated by AiPBX — AI → Echo)\n";
        $conf .= "; Manual edits are LOST the next time the target is applied.\n";
        $conf .= ";\n";
        $conf .= "; " . self::describe($s) . "\n";
        if (($s['mode'] ?? '') === 'both') {
            $conf .= "; Asterisk sends ONE stream: the built-in daemon (:9061) stores it\n";
            $conf .= "; and forwards an unchanged copy to " . self::targetSummary($s) . ".\n";
            $conf .= "; The forward list is /var/lib/aipbx/signaling/forward.json; keep\n";
            $conf .= "; each collector on loopback, a private network or another trusted\n";
            $conf .= "; host. HEP is plain UDP. Both collectors process the full stream.\n";
        } elseif (($s['mode'] ?? '') === 'homer') {
            $conf .= "; A HOMER collector on this server: its UI is usually :9080 and\n";
            $conf .= "; its HEP listener udp/9060. HEP is plain UDP — keep it on loopback\n";
            $conf .= "; or a private network.\n";
        } elseif (($s['mode'] ?? '') === 'voipmonitor') {
            $conf .= "; VoIPmonitor on this server: enable the sniffer's HEP listener\n";
            $conf .= "; (hep = yes, hep_bind_ip, hep_bind_port = 9060) and keep it on\n";
            $conf .= "; loopback or a private network. HEP is plain UDP.\n";
        } elseif (($s['mode'] ?? '') === 'external') {
            $conf .= "; External collector: HEP is plain UDP, capture_password is a shared\n";
            $conf .= "; secret and NOT encryption. Use a VPN or restrict the source address.\n";
        } elseif (($s['mode'] ?? '') === 'builtin') {
            $conf .= "; The portal's own daemon writes pbx_signaling_events, which Echo reads.\n";
        }
        $conf .= "\n[general]\n";
        $conf .= "enabled = {$enabled}\n";
        $conf .= "capture_address = " . $s['host'] . ':' . (int) $s['port'] . "\n";
        if (!empty($s['password'])) {
            $conf .= "capture_password = " . $s['password'] . "\n";
        }
        $conf .= "capture_id = " . (int) $s['capture_id'] . "\n";
        $conf .= "uuid_type = call-id\n";
        return $conf;
    }

    /** Write + reload, restoring the previous file when the reload fails. */
    private static function apply(array $s): void
    {
        $path = self::confPath();
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new CloudAiException(sprintf(t('ai_echo.hep_err_write'), $path));
        }
        $previous = is_readable($path) ? (string) file_get_contents($path) : null;
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, self::renderConf($s), LOCK_EX) === false) {
            throw new CloudAiException(sprintf(t('ai_echo.hep_err_write'), $path));
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new CloudAiException(sprintf(t('ai_echo.hep_err_write'), $path));
        }

        $errors = [];
        foreach (['res_hep.so', 'res_hep_pjsip.so', 'res_hep_rtcp.so'] as $module) {
            $res = AsteriskHelper::execCLI('module reload ' . $module);
            if (empty($res['success'])) {
                $errors[] = $module . ': ' . trim((string) ($res['output'] ?? ''));
            }
        }
        if ($errors) {
            if ($previous !== null) {
                @file_put_contents($path, $previous, LOCK_EX);
            }
            throw new CloudAiException(t('ai_echo.hep_err_reload') . ' ' . mb_substr(implode(' | ', $errors), 0, 200));
        }
    }

    /**
     * The two "both" addresses from the form, falling back to the stored ones
     * (and finally to loopback defaults) when a field is absent. Validation is
     * unconditional: a field shown on the page is never trusted unvalidated.
     */
    private static function bothTargetsFromPost(array $post, array $previous): array
    {
        $specs = [
            ['label' => 'HOMER', 'host' => 'homer_host', 'port' => 'homer_port',
             'ui' => 'homer_ui_url', 'post' => 'hep_homer_', 'default_port' => self::HOMER_PORT],
            ['label' => 'VoIPmonitor', 'host' => 'voipmonitor_host', 'port' => 'voipmonitor_port',
             'ui' => 'voipmonitor_ui_url', 'post' => 'hep_voipmonitor_',
             'default_port' => self::BOTH_VOIPMONITOR_PORT],
        ];
        $out = [];
        foreach ($specs as $spec) {
            $postHost = $spec['post'] . 'host';
            $host = array_key_exists($postHost, $post)
                ? trim((string) $post[$postHost])
                : trim((string) ($previous[$spec['host']] ?? ''));
            if ($host === '') {
                $host = '127.0.0.1';
            }
            if (!preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $host)) {
                throw new CloudAiException(t('ai_echo.hep_err_host'));
            }

            $postPort = $spec['post'] . 'port';
            $port = array_key_exists($postPort, $post)
                ? trim((string) $post[$postPort])
                : (string) (int) ($previous[$spec['port']] ?? 0);
            if ($port === '' || $port === '0') {
                $port = (string) $spec['default_port'];
            }
            if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new CloudAiException(t('ai_echo.hep_err_port'));
            }

            $postUi = $spec['post'] . 'ui_url';
            $ui = array_key_exists($postUi, $post)
                ? trim((string) $post[$postUi])
                : trim((string) ($previous[$spec['ui']] ?? ''));
            if ($ui !== '' && (!filter_var($ui, FILTER_VALIDATE_URL)
                    || !preg_match('#^https?://#i', $ui))) {
                throw new CloudAiException(t('ai_echo.hep_err_url'));
            }
            $out[] = [
                'key' => strtolower($spec['label']),
                'label' => $spec['label'],
                'host' => $host,
                'port' => (int) $port,
                'ui_url' => $ui,
            ];
        }
        return $out;
    }

    /** What aipbx-signaling should forward to. Empty targets mean "do not". */
    public static function forwardConfig(array $s): array
    {
        $targets = [];
        if (($s['mode'] ?? '') === 'both' && !empty($s['enabled'])) {
            foreach (self::targets($s) as $t) {
                $targets[] = ['name' => $t['key'], 'host' => $t['host'], 'port' => (int) $t['port']];
            }
        }
        return ['version' => 1, 'targets' => $targets];
    }

    /**
     * Write (or remove) the forward list the daemon reads. The daemon refreshes
     * it within a second, so no service restart is needed — and an empty list
     * is an explicit file removal, so a stale copy never keeps flowing.
     */
    private static function writeForward(array $config): void
    {
        $path = self::forwardPath();
        if (empty($config['targets'])) {
            if (is_file($path) && !@unlink($path)) {
                throw new CloudAiException(sprintf(t('ai_echo.hep_err_forward'), $path));
            }
            return;
        }
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new CloudAiException(sprintf(t('ai_echo.hep_err_forward'), $path));
        }
        self::atomicWrite($path, $json . "\n");
    }

    private static function atomicWrite(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new CloudAiException(sprintf(t('ai_echo.hep_err_forward'), $path));
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new CloudAiException(sprintf(t('ai_echo.hep_err_forward'), $path));
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new CloudAiException(sprintf(t('ai_echo.hep_err_forward'), $path));
        }
    }

    private static function restoreForward(?string $previous): void
    {
        $path = self::forwardPath();
        try {
            if ($previous === null) {
                if (is_file($path)) {
                    @unlink($path);
                }
                return;
            }
            self::atomicWrite($path, $previous);
        } catch (\Throwable $e) {
            // Do not hide the Asterisk error that sent us here; leave a trace.
            error_log('HEP forward restore failed: ' . $e->getMessage());
        }
    }

    /** What the live file actually says — the page shows this beside the saved target. */
    public static function currentFile(): array
    {
        $path = self::confPath();
        $out = ['exists' => false, 'enabled' => null, 'capture_address' => '', 'capture_id' => '', 'password_set' => false];
        if (!is_readable($path)) {
            return $out;
        }
        $out['exists'] = true;
        foreach (preg_split('/\R/', (string) file_get_contents($path)) as $line) {
            $line = trim(preg_replace('/;.*$/', '', $line));
            if ($line === '' || $line[0] === '[') {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $k = trim($parts[0]);
            $v = trim($parts[1]);
            if ($k === 'enabled') {
                $out['enabled'] = strtolower($v) === 'yes';
            } elseif ($k === 'capture_address') {
                $out['capture_address'] = $v;
            } elseif ($k === 'capture_id') {
                $out['capture_id'] = $v;
            } elseif ($k === 'capture_password') {
                $out['password_set'] = $v !== '';
            }
        }
        return $out;
    }

    /**
     * Can this server open a TCP connection to the target? HOMER publishes TCP
     * 9060 as well as UDP, so a success is proof; a failure only means "not
     * proven", because plain HEP — and VoIPmonitor's listener — may be UDP-only.
     */
    public static function probe(array $s): array
    {
        if (empty($s['enabled'])) {
            return ['ok' => false, 'message' => t('ai_echo.hep_probe_off')];
        }
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($s['host'], (int) $s['port'], $errno, $errstr, 2);
        if (($s['mode'] ?? '') === 'both') {
            // Both collectors are UDP, and so is the daemon; a TCP connect
            // proves nothing. Ask the kernel whether something holds the UDP
            // port, then name both targets so a wrong address is visible.
            $summary = self::targetSummary($s);
            $listening = self::udpPortListening((int) $s['port']);
            if ($listening === true) {
                // Listening is not enough: the list the daemon reads must name
                // exactly these two collectors, or it forwards nowhere.
                $doc = json_decode((string) @file_get_contents(self::forwardPath()), true);
                $actual = [];
                foreach (is_array($doc) && is_array($doc['targets'] ?? null) ? $doc['targets'] : [] as $t) {
                    if (is_array($t)) {
                        $actual[] = [(string) ($t['host'] ?? ''), (int) ($t['port'] ?? 0)];
                    }
                }
                $expected = array_map(fn($t) => [$t['host'], (int) $t['port']], self::targets($s));
                if ($actual !== $expected) {
                    return ['ok' => false, 'message' => sprintf(
                        t('ai_echo.hep_probe_both_no_list'), self::forwardPath())];
                }
                return ['ok' => true, 'message' => sprintf(
                    t('ai_echo.hep_probe_both_ok'), $s['host'], (string) $s['port'], $summary)];
            }
            if ($listening === false) {
                return ['ok' => false, 'message' => sprintf(
                    t('ai_echo.hep_probe_both_down'), $s['host'], (string) $s['port'], $summary)];
            }
            if ($fp) {
                fclose($fp);
                return ['ok' => true, 'message' => sprintf(
                    t('ai_echo.hep_probe_both_ok'), $s['host'], (string) $s['port'], $summary)];
            }
            return ['ok' => false, 'message' => sprintf(
                t('ai_echo.hep_probe_both_no_tcp'), $s['host'], (string) $s['port'], (string) $errno, $summary)];
        }
        if ($fp) {
            fclose($fp);
            return ['ok' => true, 'message' => sprintf(t('ai_echo.hep_probe_ok'), $s['host'], (string) $s['port'])];
        }
        return ['ok' => false, 'message' => sprintf(t('ai_echo.hep_probe_no_tcp'), $s['host'], (string) $s['port'], (string) $errno)];
    }

    /**
     * The collectors the health check watches. The built-in daemon is this
     * portal's own service and "off" means nothing external to watch, so both
     * return an empty list rather than a misleading OK.
     *
     * @return array<int, array{key:string,label:string,host:string,port:int,ui_url:string}>
     */
    public static function monitoredTargets(array $s): array
    {
        if (empty($s['enabled'])) {
            return [];
        }
        $mode = (string) ($s['mode'] ?? '');
        if ($mode === 'both') {
            return self::targets($s);
        }
        if (!in_array($mode, ['homer', 'voipmonitor', 'external'], true)) {
            return [];
        }
        return [[
            'key' => $mode,
            'label' => [
                'homer' => 'HOMER',
                'voipmonitor' => 'VoIPmonitor',
                'external' => 'external HEP',
            ][$mode],
            'host' => (string) $s['host'],
            'port' => (int) $s['port'],
            'ui_url' => (string) ($s['ui_url'] ?? ''),
        ]];
    }

    /**
     * Turn per-target results into the health page's [status, message]. Pure,
     * so the mapping is tested without a network or a collector. A missing UI
     * address is "info": reachability is unknown, never a false alarm.
     *
     * @param array<int, array{label:string,status:string,detail:string}> $results
     * @return array{0:string, 1:string}
     */
    public static function healthVerdict(array $results): array
    {
        if (!$results) {
            return ['off', t('health.msg_hep_off')];
        }
        $rank = ['info' => 0, 'ok' => 1, 'warn' => 2, 'fail' => 3];
        $status = 'ok';
        foreach ($results as $r) {
            if (($rank[$r['status']] ?? 0) > ($rank[$status] ?? 0)) {
                $status = $r['status'];
            }
        }
        if ($status === 'ok' && count(array_filter($results, fn($r) => $r['status'] === 'info')) === count($results)) {
            $status = 'info';
        }
        $parts = [];
        foreach ($results as $r) {
            $parts[] = $r['label'] . ': ' . $r['detail'];
        }
        return [$status, implode('; ', $parts)];
    }

    /**
     * The health check itself. When a UI address is configured it is fetched
     * over HTTP(S) — that proves the collector's web tier answers, including a
     * sign-in redirect or a 401. Without a UI address only a TCP listener can
     * be proven; HEP is usually UDP, so a closed port is reported as "not
     * monitored" (info) instead of a failure that would alarm every 15 min.
     *
     * @return array{0:string, 1:string}
     */
    public static function health(): array
    {
        $targets = self::monitoredTargets(self::settings());
        if (!$targets) {
            return ['off', t('health.msg_hep_off')];
        }
        $results = [];
        foreach ($targets as $target) {
            $results[] = self::monitorTarget($target);
        }
        return self::healthVerdict($results);
    }

    /** @return array{label:string, status:string, detail:string} */
    private static function monitorTarget(array $target): array
    {
        $ui = (string) ($target['ui_url'] ?? '');
        if ($ui !== '') {
            $code = self::httpStatus($ui);
            if ($code === null) {
                return ['label' => $target['label'], 'status' => 'fail',
                    'detail' => sprintf(t('health.msg_hep_ui_down'), $ui)];
            }
            if ($code >= 500) {
                return ['label' => $target['label'], 'status' => 'warn',
                    'detail' => sprintf(t('health.msg_hep_ui_error'), $ui, (string) $code)];
            }
            return ['label' => $target['label'], 'status' => 'ok',
                'detail' => sprintf(t('health.msg_hep_ui_ok'), $ui, (string) $code)];
        }
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($target['host'], (int) $target['port'], $errno, $errstr, 3);
        if ($fp) {
            fclose($fp);
            return ['label' => $target['label'], 'status' => 'ok',
                'detail' => sprintf(t('health.msg_hep_port_ok'), $target['host'], (string) $target['port'])];
        }
        return ['label' => $target['label'], 'status' => 'info',
            'detail' => sprintf(t('health.msg_hep_no_ui'), $target['host'], (string) $target['port'])];
    }

    /**
     * Liveness of the collector's web tier: status code only, no body, no
     * login, no credentials. Certificate verification is off because a
     * collector on the LAN often uses a self-signed certificate; this probe
     * proves "something answers", not identity or trust.
     */
    private static function httpStatus(string $url): ?int
    {
        // curl is installed by install.sh (php-curl) and talks to the same
        // Apache TLS that the browser does; PHP's ssl:// wrapper can hang on a
        // vhost that only answers after SNI. Fall back to a raw socket when
        // the extension is missing.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_PROXY => '',
                CURLOPT_USERAGENT => 'AiPBX-health',
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);
            return $ok === false || $code === 0 ? null : $code;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $tls = strtolower((string) ($parts['scheme'] ?? 'http')) === 'https';
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($tls ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $scheme = $tls ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($scheme . '://' . $host . ':' . $port, $errno, $errstr, 3,
            STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            return null;
        }
        stream_set_timeout($fp, 3);
        fwrite($fp, 'HEAD ' . $path . " HTTP/1.0\r\nHost: " . $host . "\r\nUser-Agent: AiPBX-health\r\nConnection: close\r\n\r\n");
        $line = fgets($fp, 512);
        fclose($fp);
        return is_string($line) && preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m) ? (int) $m[1] : null;
    }

    /** The last stored result of the collector health check, for Echo and Greg. */
    public static function lastHealth(): ?array
    {
        try {
            $stmt = getDB()->prepare(
                'SELECT status, message, checked_at FROM health_checks WHERE check_key = ?'
            );
            $stmt->execute(['hep_collector']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        return [
            'status' => (string) $row['status'],
            'message' => (string) $row['message'],
            'checked_at' => $row['checked_at'] !== null ? (string) $row['checked_at'] : null,
        ];
    }

    /**
     * Is a UDP socket bound to this port? Linux exposes it in /proc/net/udp;
     * null means this host cannot tell us (then the caller falls back to TCP).
     */
    private static function udpPortListening(int $port): ?bool
    {
        $readable = false;
        foreach (['/proc/net/udp', '/proc/net/udp6'] as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $readable = true;
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_slice($lines, 1) as $line) {
                $cols = preg_split('/\s+/', trim($line));
                $local = (string) ($cols[1] ?? '');
                $suffix = strrchr($local, ':');
                if ($suffix !== false && hexdec(substr($suffix, 1)) === $port) {
                    return true;
                }
            }
        }
        return $readable ? false : null;
    }

    /** The local HOMER command, shown on the page — not run by the portal. */
    public static function homerCommand(): string
    {
        return 'git clone https://github.com/sipcapture/homer7-docker && '
            . 'cd homer7-docker/heplify-server/hom7-prom-all && docker compose up -d';
    }

    /**
     * The VoIPmonitor side of the same choice, shown on the page — the portal
     * cannot configure another product, so it writes only Asterisk's half.
     * The port follows the field: in "both" mode 9060 is already HOMER's.
     */
    public static function voipmonitorConfig(int $port = self::VOIPMONITOR_PORT): string
    {
        $port = max(1, min(65535, $port));
        return "hep = yes\nhep_bind_ip = 127.0.0.1\nhep_bind_port = " . $port;
    }

    private static function decryptPassword(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        try {
            return SecretBox::decrypt($stored);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
