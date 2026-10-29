<?php
/**
 * SSH login posture — read-only.
 *
 * A centralized installation (one collector, portal or administrator for
 * several PBXs) means logging in to another host. That login must use a key,
 * never a password: a password can be guessed, reused or phished, and a
 * central host is worth more than any single PBX. This service only reports
 * what sshd is configured to accept; nothing here changes sshd, writes a key
 * or opens a connection. The administrator runs every step.
 *
 * `sshd -T` is authoritative (it resolves Include, defaults and Match
 * blocks). Only when it cannot be run — a hardened PHP without shell_exec, or
 * a container without host keys — the configuration files are read as a
 * best-effort fallback. Unknown is always reported as unknown, never as safe.
 */
final class SshAccessService
{
    /** The settings that decide whether a key is enough. */
    private const KEYS = [
        'passwordauthentication',
        'kbdinteractiveauthentication',
        'pubkeyauthentication',
        'permitrootlogin',
        'authenticationmethods',
    ];

    /** Is OpenSSH installed here? */
    public static function installed(): bool
    {
        return is_file('/etc/ssh/sshd_config');
    }

    /**
     * The effective configuration, or null when it cannot be read.
     *
     * @return array<string,string>|null
     */
    public static function effective(): ?array
    {
        if (!self::installed()) {
            return null;
        }
        $out = self::run('sshd -T 2>/dev/null');
        if (trim($out) === '') {
            $out = self::readConfigFiles();
        }
        $cfg = self::parse($out);
        return $cfg === [] ? null : $cfg;
    }

    /**
     * The sshd values this service cares about, lowercased. Without `-C`,
     * `sshd -T` prints its defaults too, so a missing line is not guessed at.
     *
     * @return array<string,string>
     */
    public static function parse(string $output): array
    {
        $cfg = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2) ?: [];
            $key = strtolower((string) ($parts[0] ?? ''));
            if (!in_array($key, self::KEYS, true) || array_key_exists($key, $cfg)) {
                continue;
            }
            $cfg[$key] = strtolower(trim((string) ($parts[1] ?? '')));
        }
        return $cfg;
    }

    /**
     * What the health page should say. Pure: no sshd, no files, no network.
     *
     * @param array<string,string> $cfg
     * @return array{0:string, 1:string}
     */
    public static function verdict(array $cfg): array
    {
        // OpenSSH defaults, used only when the key is absent (a fallback read
        // may not have reached every file). They are the safe assumption: with
        // no line saying otherwise, sshd accepts passwords and keys.
        $pub = ($cfg['pubkeyauthentication'] ?? 'yes') !== 'no';
        $pw = ($cfg['passwordauthentication'] ?? 'yes') !== 'no';
        $kbd = ($cfg['kbdinteractiveauthentication'] ?? 'yes') !== 'no';

        // AuthenticationMethods overrides the switches above when it names
        // methods explicitly: `publickey` alone means a password cannot be used
        // even if PasswordAuthentication still says yes.
        $methods = trim((string) ($cfg['authenticationmethods'] ?? ''));
        if ($methods !== ''
            && !str_contains($methods, 'password')
            && !str_contains($methods, 'keyboard-interactive')) {
            $pw = false;
            $kbd = false;
        }

        if (!$pub) {
            return ['fail', t('health.msg_ssh_no_pubkey')];
        }
        $open = [];
        if ($pw) {
            $open[] = 'PasswordAuthentication yes';
        }
        if ($kbd) {
            $open[] = 'KbdInteractiveAuthentication yes';
        }
        if ($open) {
            return ['warn', sprintf(t('health.msg_ssh_password'), implode(', ', $open))];
        }
        // Keys are the only way in. Root with a key is still a wider door than
        // a named account, but it satisfies the key-only rule; say so quietly.
        if (strtolower((string) ($cfg['permitrootlogin'] ?? 'prohibit-password')) === 'yes') {
            return ['info', t('health.msg_ssh_root_key')];
        }
        return ['ok', t('health.msg_ssh_keys_only')];
    }

    /** The three commands an administrator runs on their own machine. */
    public static function keySetup(): string
    {
        return 'ssh-keygen -t ed25519 -C aipbx'
            . '; ssh-copy-id -i ~/.ssh/id_ed25519.pub user@collector'
            . '; ssh -o PasswordAuthentication=no user@collector true';
    }

    /** The drop-in for the collector (or any central host), shown, never written. */
    public static function sshdDropIn(): string
    {
        return "PasswordAuthentication no\n"
            . "KbdInteractiveAuthentication no\n"
            . 'PubkeyAuthentication yes';
    }

    private static function run(string $cmd): string
    {
        if (!function_exists('shell_exec')) {
            return '';
        }
        $out = @shell_exec($cmd);
        return is_string($out) ? $out : '';
    }

    /**
     * Fallback only: drop-ins first, then the main file, because sshd keeps
     * the first value it obtains and Ubuntu's sshd_config includes the
     * drop-in directory at the top. A `Match` block is not evaluated here —
     * that is exactly why `sshd -T` is preferred.
     */
    private static function readConfigFiles(): string
    {
        $files = glob('/etc/ssh/sshd_config.d/*.conf') ?: [];
        sort($files);
        $files[] = '/etc/ssh/sshd_config';
        $out = '';
        foreach ($files as $file) {
            $text = @file_get_contents($file);
            if (is_string($text)) {
                $out .= $text . "\n";
            }
        }
        return $out;
    }
}
