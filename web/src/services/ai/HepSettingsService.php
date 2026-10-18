<?php
/**
 * HEP capture target — where Asterisk sends SIP/RTCP, configured from Echo.
 *
 * Three honest choices:
 *   builtin  — this portal's own daemon (127.0.0.1:9061). Local counts and
 *              Echo's SIP error numbers keep working with no other software.
 *   homer    — a HOMER (sipcapture) collector on this server, UDP 9060 by
 *              default. HOMER brings its own UI, search and retention.
 *   external — a HOMER or other HEP collector elsewhere. HEP over plain UDP
 *              is NOT encrypted and capture_password is a shared secret, not
 *              transport security: use a VPN or a restricted firewall.
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
    public const MODES = ['off', 'builtin', 'homer', 'external'];
    public const BUILTIN_PORT = 9061;
    public const HOMER_PORT = 9060;
    public const DEFAULT_PASSWORD = 'aipbx';
    public const DEFAULT_CAPTURE_ID = 1001;

    public static function confPath(): string
    {
        return ASTERISK_CONF_DIR . '/hep.conf';
    }

    /** @return array{mode:string, enabled:bool, host:string, port:int, password:string,
     *                password_set:bool, capture_id:int, homer_url:string} */
    public static function settings(): array
    {
        $mode = (string) getSystemSetting('hep.mode', 'builtin');
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'builtin';
        }
        $host = trim((string) getSystemSetting('hep.host', ''));
        if ($host === '') {
            $host = $mode === 'external' ? '' : '127.0.0.1';
        }
        $port = (int) getSystemSetting('hep.port', '0');
        if ($port < 1 || $port > 65535) {
            $port = $mode === 'homer' ? self::HOMER_PORT : self::BUILTIN_PORT;
        }
        $stored = self::decryptPassword((string) getSystemSetting('hep.password', ''));
        $captureId = (int) getSystemSetting('hep.capture_id', (string) self::DEFAULT_CAPTURE_ID);
        if ($captureId < 1 || $captureId > 999999) {
            $captureId = self::DEFAULT_CAPTURE_ID;
        }
        return [
            'mode'         => $mode,
            'enabled'      => $mode !== 'off',
            'host'         => $host,
            'port'         => $port,
            'password'     => $stored !== '' ? $stored : self::DEFAULT_PASSWORD,
            'password_set' => $stored !== '',
            'capture_id'   => $captureId,
            'homer_url'    => trim((string) getSystemSetting('hep.homer_url', '')),
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
        $homerUrl = trim((string) ($post['hep_homer_url'] ?? ''));

        $isLocal = in_array($mode, ['builtin', 'homer', 'off'], true);
        if ($isLocal) {
            $host = '127.0.0.1';
            if ($port === '') {
                $port = (string) ($mode === 'homer' ? self::HOMER_PORT : self::BUILTIN_PORT);
            }
        } elseif ($mode === 'external') {
            if ($host === '' || !preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $host)) {
                throw new CloudAiException(t('ai_echo.hep_err_host'));
            }
            if ($port === '') {
                $port = (string) self::HOMER_PORT;
            }
        }
        if (!ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
            throw new CloudAiException(t('ai_echo.hep_err_port'));
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
        if ($homerUrl !== '' && (!filter_var($homerUrl, FILTER_VALIDATE_URL)
                || !preg_match('#^https?://#i', $homerUrl))) {
            throw new CloudAiException(t('ai_echo.hep_err_url'));
        }

        $settings = [
            'mode'       => $mode,
            'enabled'    => $mode !== 'off',
            'host'       => $isLocal ? '127.0.0.1' : $host,
            'port'       => (int) $port,
            'password'   => $password !== '' ? $password : self::settings()['password'],
            'capture_id' => (int) $captureId,
            'homer_url'  => $homerUrl,
        ];

        self::apply($settings);

        $db = getDB();
        $up = $db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $up->execute(['hep.mode', $settings['mode']]);
        $up->execute(['hep.host', $settings['host']]);
        $up->execute(['hep.port', (string) $settings['port']]);
        $up->execute(['hep.capture_id', (string) $settings['capture_id']]);
        $up->execute(['hep.homer_url', $settings['homer_url']]);
        if (!empty($post['hep_password'])) {
            $up->execute(['hep.password', SecretBox::encrypt($settings['password'])]);
        }
        writeAuditLog('echo', 'hep_settings', 'hep',
            'HEP capture target: ' . self::describe($settings),
            'update', $_SESSION['user_id'] ?? null);
        GregJournal::append('HEP capture target applied', array_filter([
            self::describe($settings),
            $settings['homer_url'] !== '' ? 'HOMER UI: ' . $settings['homer_url'] : '',
        ]));
        return ['message' => t('ai_echo.hep_saved')];
    }

    /** One line for the audit log, the page and Greg. */
    public static function describe(array $s): string
    {
        if (empty($s['enabled'])) {
            return 'off';
        }
        $label = ['builtin' => 'built-in capture', 'homer' => 'HOMER', 'external' => 'external HEP'][$s['mode']] ?? $s['mode'];
        return $label . ' → ' . $s['host'] . ':' . $s['port'];
    }

    /** The generated file. Pure: no writes, no reloads — testable as-is. */
    public static function renderConf(array $s): string
    {
        $enabled = !empty($s['enabled']) ? 'yes' : 'no';
        $conf  = "; HEP capture configuration (generated by AiPBX — AI → Echo)\n";
        $conf .= "; Manual edits are LOST the next time the target is applied.\n";
        $conf .= ";\n";
        $conf .= "; " . self::describe($s) . "\n";
        if (($s['mode'] ?? '') === 'homer') {
            $conf .= "; A HOMER collector on this server: its UI is usually :9080 and\n";
            $conf .= "; its HEP listener udp/9060. HEP is plain UDP — keep it on loopback\n";
            $conf .= "; or a private network.\n";
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
     * proven", because plain HEP may be UDP-only.
     */
    public static function probe(array $s): array
    {
        if (empty($s['enabled'])) {
            return ['ok' => false, 'message' => t('ai_echo.hep_probe_off')];
        }
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($s['host'], (int) $s['port'], $errno, $errstr, 2);
        if ($fp) {
            fclose($fp);
            return ['ok' => true, 'message' => sprintf(t('ai_echo.hep_probe_ok'), $s['host'], (string) $s['port'])];
        }
        return ['ok' => false, 'message' => sprintf(t('ai_echo.hep_probe_no_tcp'), $s['host'], (string) $s['port'], (string) $errno)];
    }

    /** The local HOMER command, shown on the page — not run by the portal. */
    public static function homerCommand(): string
    {
        return 'git clone https://github.com/sipcapture/homer7-docker && '
            . 'cd homer7-docker/heplify-server/hom7-prom-all && docker compose up -d';
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
