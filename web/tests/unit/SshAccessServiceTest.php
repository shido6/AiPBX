<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/SshAccessService.php';

/**
 * The SSH posture of a centralized installation: a key is the only way in.
 * These tests pin the verdict and the commands shown to the administrator —
 * nothing here runs sshd or touches /etc/ssh.
 */
final class SshAccessServiceTest extends TestCase
{
    /** @param list<string> $lines */
    private function verdict(array $lines): array
    {
        return SshAccessService::verdict(SshAccessService::parse(implode("\n", $lines)));
    }

    public function testKeysOnlyIsHealthy(): void
    {
        [$status, $message] = $this->verdict([
            'passwordauthentication no',
            'kbdinteractiveauthentication no',
            'pubkeyauthentication yes',
            'permitrootlogin prohibit-password',
        ]);
        $this->assertSame('ok', $status);
        $this->assertStringContainsString('Only SSH keys', $message);
    }

    public function testAPasswordPathIsAWarningThatNamesTheLine(): void
    {
        [$status, $message] = $this->verdict([
            'passwordauthentication yes',
            'kbdinteractiveauthentication no',
            'pubkeyauthentication yes',
        ]);
        $this->assertSame('warn', $status);
        $this->assertStringContainsString('PasswordAuthentication yes', $message);
        $this->assertStringContainsString('SSH keys only', $message);
    }

    public function testKeyboardInteractiveAloneStillWarns(): void
    {
        [$status, $message] = $this->verdict([
            'passwordauthentication no',
            'kbdinteractiveauthentication yes',
            'pubkeyauthentication yes',
        ]);
        $this->assertSame('warn', $status);
        $this->assertStringContainsString('KbdInteractiveAuthentication yes', $message);
        $this->assertStringNotContainsString('PasswordAuthentication yes', $message);
    }

    public function testAuthenticationMethodsPublickeyOverridesTheSwitches(): void
    {
        [$status] = $this->verdict([
            'passwordauthentication yes',
            'kbdinteractiveauthentication yes',
            'pubkeyauthentication yes',
            'authenticationmethods publickey',
        ]);
        $this->assertSame('ok', $status, 'publickey alone means a password cannot be used');
    }

    public function testRefusingPublicKeysFails(): void
    {
        [$status] = $this->verdict(['pubkeyauthentication no']);
        $this->assertSame('fail', $status);
    }

    public function testRootWithAKeyIsInformationNotAWarning(): void
    {
        [$status, $message] = $this->verdict([
            'passwordauthentication no',
            'kbdinteractiveauthentication no',
            'pubkeyauthentication yes',
            'permitrootlogin yes',
        ]);
        $this->assertSame('info', $status);
        $this->assertStringContainsString('prohibit-password', $message);
    }

    public function testParseKeepsTheFirstValueAndIgnoresComments(): void
    {
        $cfg = SshAccessService::parse(
            "# PasswordAuthentication yes\nPasswordAuthentication yes\n"
            . "passwordauthentication no\nPubkeyAuthentication yes\n"
            . "  X11Forwarding yes\n"
        );
        $this->assertSame('yes', $cfg['passwordauthentication'], 'sshd keeps the first value it obtains');
        $this->assertSame('yes', $cfg['pubkeyauthentication']);
        $this->assertArrayNotHasKey('x11forwarding', $cfg);
    }

    public function testTheShownCommandsCopyTheKeyAndProveItAloneWorks(): void
    {
        $cmd = SshAccessService::keySetup();
        $this->assertStringContainsString('ssh-keygen -t ed25519', $cmd);
        $this->assertStringContainsString('ssh-copy-id', $cmd);
        $this->assertStringContainsString('PasswordAuthentication=no', $cmd);
        $this->assertStringContainsString('true', $cmd);
    }

    public function testTheDropInTurnsEveryPasswordPathOff(): void
    {
        $conf = SshAccessService::sshdDropIn();
        $this->assertStringContainsString('PasswordAuthentication no', $conf);
        $this->assertStringContainsString('KbdInteractiveAuthentication no', $conf);
        $this->assertStringContainsString('PubkeyAuthentication yes', $conf);
        $this->assertStringNotContainsString('PasswordAuthentication yes', $conf);
    }
}
