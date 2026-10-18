<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/OpencodeService.php';
require_once __DIR__ . '/../../src/services/GregService.php';

/**
 * Greg's workshop: opencode may read and plan, never change anything. These
 * tests pin the gate (off by default), the input rules that keep a bad model
 * name or a too-short task away from the process, the honest "not installed"
 * answer, the read-only permission set, and the narrow intent detection that
 * decides when a chat question becomes a proposal.
 */
final class OpencodeServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'greg.%'");
        $this->db->exec('DELETE FROM pbx_greg_proposals');
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_role']);
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'greg.%'");
        $this->db->exec('DELETE FROM pbx_greg_proposals');
    }

    private function set(string $key, string $value): void
    {
        $st = $this->db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $st->execute(['greg.' . $key, $value]);
    }

    // --------------------------------------------------------------- defaults

    public function testOnUnlessSwitchedOffAndModelIsBigPickle(): void
    {
        $s = OpencodeService::settings();
        $this->assertTrue($s['enabled'], 'Greg can research and draft tools without extra setup');
        $this->assertSame('opencode/big-pickle', $s['model']);
        $this->assertSame('opencode', $s['bin']);
        $this->assertSame('/var/lib/aipbx/opencode', $s['workspace']);
        $this->assertSame(180, $s['timeout']);
    }

    public function testTimeoutAndWorkspaceAreBoundedNotTrusted(): void
    {
        $this->set('opencode_timeout', '9999');
        $this->set('opencode_workspace', 'relative/path');
        $s = OpencodeService::settings();
        $this->assertSame(180, $s['timeout'], 'a silly timeout falls back to the default');
        $this->assertSame('/var/lib/aipbx/opencode', $s['workspace'], 'a relative workspace is refused');
    }

    public function testAvailabilityFollowsTheFilesystemNotHope(): void
    {
        // A real executable, by absolute path and by PATH lookup.
        $shell = is_file('/bin/sh') ? '/bin/sh' : '/bin/bash';
        $this->assertTrue(OpencodeService::available($shell));
        $this->assertTrue(OpencodeService::available('sh'));
        $this->assertFalse(OpencodeService::available('definitely-not-a-real-binary-xyz'));
        // A directory is not a binary, however executable it is.
        $this->assertFalse(OpencodeService::available('/tmp'));
        // A file that exists but is not executable.
        $f = sys_get_temp_dir() . '/oc-test-' . bin2hex(random_bytes(3));
        file_put_contents($f, "#!/bin/sh\nexit 0\n");
        chmod($f, 0600);
        $this->assertFalse(OpencodeService::available($f));
        @unlink($f);
    }

    // ------------------------------------------------------------------ gate

    public function testProposeRefusesWhenTheAdministratorSwitchesItOff(): void
    {
        $this->set('opencode_enabled', '0');
        $this->expectException(CloudAiException::class);
        OpencodeService::propose('add a tool from https://github.com/owner/repo');
    }

    public function testAShortTaskNeverReachesTheRunner(): void
    {
        $this->set('opencode_enabled', '1');
        $this->set('opencode_bin', '/nonexistent/opencode');
        $this->expectException(CloudAiException::class);
        OpencodeService::propose('hi');
    }

    public function testMissingBinaryIsNamedHonestly(): void
    {
        $this->set('opencode_enabled', '1');
        $this->set('opencode_bin', '/nonexistent/opencode');
        try {
            OpencodeService::propose('add a tool from https://github.com/owner/repo');
            $this->fail('a missing binary must not be reported as success');
        } catch (CloudAiException $e) {
            $this->assertStringContainsString('/nonexistent/opencode', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM pbx_greg_proposals')->fetchColumn());
    }

    public function testModelNameIsValidatedBeforeItIsStored(): void
    {
        $bad = ['opencode', 'a/b; rm -rf /', 'a/b c', 'provider/'];
        foreach ($bad as $model) {
            try {
                OpencodeService::save(['opencode_model' => $model, 'opencode_enabled' => '1']);
                $this->fail('accepted bad model: ' . $model);
            } catch (CloudAiException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        OpencodeService::save(['opencode_model' => 'opencode/big-pickle', 'opencode_enabled' => '1']);
        $this->assertSame('1', (string) $this->db->query(
            "SELECT setting_value FROM sys_settings WHERE setting_key = 'greg.opencode_enabled'"
        )->fetchColumn());
    }

    public function testADecisionCanOnlyBeMadeOnce(): void
    {
        $ins = $this->db->prepare(
            "INSERT INTO pbx_greg_proposals (created_at, kind, task, output, status, model)
             VALUES (NOW(), 'tool', 'a task', 'an answer', 'pending', 'opencode/big-pickle')"
        );
        $ins->execute();
        $id = (int) $this->db->lastInsertId();
        OpencodeService::decide($id, true);
        $this->assertSame('approved', (string) $this->db->query(
            'SELECT status FROM pbx_greg_proposals WHERE id = ' . $id
        )->fetchColumn());
        $this->expectException(CloudAiException::class);
        OpencodeService::decide($id, false);
    }

    // ---------------------------------------------------------------- output

    public function testJsonEventsBecomeReadableText(): void
    {
        $raw = "{\"type\":\"text\",\"text\":\"Use the tool as follows\"}\n"
            . "{\"type\":\"step\",\"part\":{\"text\":\"Step two\"}}\n"
            . "{\"type\":\"noise\",\"other\":1}";
        $this->assertSame("Use the tool as follows\nStep two", OpencodeService::extractText($raw));
    }

    public function testPlainTextPassesThroughAndEmptyStaysEmpty(): void
    {
        $this->assertSame("A plain answer\nline two", OpencodeService::extractText("A plain answer\nline two\n"));
        $this->assertSame('', OpencodeService::extractText('   '));
    }

    // ------------------------------------------------------- Big Pickle brain

    public function testChatWithoutTheBinaryNamesItHonestly(): void
    {
        $this->set('opencode_bin', '/nonexistent/opencode');
        try {
            OpencodeService::chat('hello');
            $this->fail('a missing binary must not look like an answer');
        } catch (CloudAiException $e) {
            $this->assertStringContainsString('/nonexistent/opencode', $e->getMessage());
        }
    }

    /**
     * Big Pickle is Greg's brain with no extra setup: the workshop switch is
     * deliberately left off here. The stub records the argv and the permission
     * set, so this pins the model, --pure, and "read only, nothing else".
     */
    public function testChatRunsReadOnlyAndNeedsNoWorkshopSwitch(): void
    {
        $ws = sys_get_temp_dir() . '/oc-chat-' . bin2hex(random_bytes(3));
        $stub = __DIR__ . '/.oc-stub-' . bin2hex(random_bytes(3)) . '.sh';
        $script = <<<'SH'
#!/bin/sh
printf '%s' "$*" > "$HOME/args.txt"
printf '%s' "$OPENCODE_PERMISSION" > "$HOME/perm.json"
printf '{"type":"text","part":{"text":"stub answer"}}\n'
SH;
        file_put_contents($stub, $script);
        chmod($stub, 0755);
        try {
            $this->set('opencode_bin', $stub);
            $this->set('opencode_workspace', $ws);
            $this->assertSame('stub answer', OpencodeService::chat('hello'));
            $dirs = glob($ws . '/chat-*', GLOB_ONLYDIR) ?: [];
            $this->assertCount(1, $dirs, 'each chat gets its own throwaway workspace');
            $args = (string) file_get_contents($dirs[0] . '/.home/args.txt');
            $this->assertStringContainsString('--model opencode/big-pickle', $args);
            $this->assertStringContainsString('--pure', $args);
            $perm = json_decode((string) file_get_contents($dirs[0] . '/.home/perm.json'), true);
            $this->assertIsArray($perm);
            $this->assertSame('deny', $perm['*'], 'everything not named is denied');
            $this->assertSame('deny', $perm['edit']);
            $this->assertSame('deny', $perm['bash']);
            $this->assertSame('deny', $perm['external_directory']);
            $this->assertSame('allow', $perm['read']);
        } finally {
            @unlink($stub);
            foreach (glob($ws . '/chat-*/.home/*') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($ws . '/chat-*/.home') ?: [] as $d) {
                @rmdir($d);
            }
            foreach (glob($ws . '/chat-*') ?: [] as $d) {
                @rmdir($d);
            }
            @rmdir($ws);
        }
    }

    public function testReasoningNeverLeaksIntoTheAnswer(): void
    {
        $raw = '{"type":"text","part":{"type":"reasoning","text":"secret thoughts"}}' . "\n"
            . '{"type":"text","part":{"type":"text","text":"<think>hmm</think>The answer"}}';
        $this->assertSame('The answer', OpencodeService::extractText($raw));
    }

    public function testProviderRefusalIsReportedNotShown(): void
    {
        $raw = '{"type":"error","error":{"data":{"message":"free tier exhausted"}}}';
        $this->assertSame('free tier exhausted', OpencodeService::extractError($raw));
        $this->assertNull(OpencodeService::extractError('{"type":"text","part":{"type":"text","text":"hi"}}'));
        $this->assertNull(OpencodeService::extractError('plain text'));
    }

    public function testASecondJobIsRefusedInsteadOfForkingAnotherProcess(): void
    {
        $ws = sys_get_temp_dir() . '/oc-lock-' . bin2hex(random_bytes(3));
        mkdir($ws, 0700, true);
        $stub = __DIR__ . '/.oc-stub-' . bin2hex(random_bytes(3)) . '.sh';
        file_put_contents($stub, "#!/bin/sh\nprintf '{\"type\":\"text\",\"part\":{\"type\":\"text\",\"text\":\"ok\"}}\\n'\n");
        chmod($stub, 0755);
        $this->set('opencode_bin', $stub);
        $this->set('opencode_workspace', $ws);
        $held = fopen($ws . '/.run.lock', 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB), 'test could not take the lock');
        try {
            OpencodeService::chat('hello');
            $this->fail('a concurrent job must not start a second process');
        } catch (CloudAiException $e) {
            $this->assertNotSame('', $e->getMessage());
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
            @unlink($stub);
            @unlink($ws . '/.run.lock');
            @rmdir($ws);
        }
    }

    // ------------------------------------------------------------ Greg intent

    public function testIntentIsSilentForNonAdminsAndWhenSwitchedOff(): void
    {
        $_SESSION['user_role'] = 'user';
        $this->assertNull(GregService::workshopIntent('add a tool from github.com/a/b'),
            'a normal user must not start opencode jobs');
        $_SESSION['user_role'] = 'admin';
        $this->set('opencode_enabled', '0');
        $this->assertNull(GregService::workshopIntent('add a tool from github.com/a/b'));
        $this->assertNull(GregService::workshopIntent('research: homer hep setup'));
    }

    public function testIntentPicksToolResearchAndLeavesOrdinaryQuestionsAlone(): void
    {
        $_SESSION['user_role'] = 'admin';
        $this->assertSame('tool', GregService::workshopIntent('add a tool from https://github.com/sipcapture/homer'));
        $this->assertSame('tool', GregService::workshopIntent('please install a plugin that parses PCAPs'));
        $this->assertSame('research', GregService::workshopIntent('research: how does res_hep build its packets?'));
        $this->assertSame('research', GregService::workshopIntent('ask opencode about HEP over TLS'));
        $this->assertNull(GregService::workshopIntent('why did call 12 fail at 14:02?'));
        $this->assertNull(GregService::workshopIntent('how many extensions do I have?'));
    }
}
