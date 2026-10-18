<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregJournal.php';

/**
 * Greg's memory on disk: AGENTS.md plus a captain's log per UTC day.
 *
 * These tests pin the things an assistant's notes must get right — they are
 * outside git, survive updates, never accept a browser-supplied path, refuse
 * rather than silently truncate, keep one entry per line so a title cannot
 * forge a heading, and never break the action they describe when the disk
 * is not writable.
 */
final class GregJournalTest extends TestCase
{
    private string $dir;
    private string $previous;

    protected function setUp(): void
    {
        // Remember the bootstrap's directory: unsetting it here would send the
        // tests that run after this one into the live /var/lib/aipbx/greg.
        $this->previous = (string) (getenv('AIPBX_GREG_DIR') ?: '');
        $this->dir = sys_get_temp_dir() . '/greg-journal-' . bin2hex(random_bytes(4));
        putenv('AIPBX_GREG_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        putenv('AIPBX_GREG_DIR=' . $this->previous);
        $this->remove($this->dir);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    $this->remove($path . '/' . $f);
                }
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    // --------------------------------------------------------------- AGENTS.md

    public function testAFreshInstallHasNoNotesSoThePromptIsUnchanged(): void
    {
        $this->assertSame('', GregJournal::agents());
        $this->assertSame('', GregJournal::context());
        $this->assertSame('', GregJournal::recentText());
    }

    public function testAgentsRoundTripsAndIsReadBackIntoContext(): void
    {
        GregJournal::saveAgents("HOMER sits behind the VPN at 10.8.0.5.\nThe carrier drops OPTIONS after 30 s.");
        $this->assertStringContainsString('HOMER sits behind the VPN', GregJournal::agents());
        $this->assertStringContainsString('HOMER sits behind the VPN', GregJournal::context());
        $this->assertSame([], glob(GregJournal::agentsPath() . '.tmp.*') ?: [], 'no temp file is left behind');
        $this->assertSame('0640', substr(sprintf('%o', fileperms(GregJournal::agentsPath())), -4));
    }

    public function testTooLongIsRefusedAndTheOldNotesSurvive(): void
    {
        GregJournal::saveAgents('keep me');
        try {
            GregJournal::saveAgents(str_repeat('x', GregJournal::MAX_AGENTS + 1));
            $this->fail('oversized notes must not be written');
        } catch (CloudAiException $e) {
            $this->assertStringContainsString((string) GregJournal::MAX_AGENTS, $e->getMessage());
        }
        $this->assertStringContainsString('keep me', GregJournal::agents());
    }

    public function testContextCapsWhatIsHandedToTheModel(): void
    {
        GregJournal::saveAgents(str_repeat('a', GregJournal::MAX_AGENTS));
        $ctx = GregJournal::context();
        $this->assertLessThan(GregJournal::MAX_AGENTS, strlen($ctx));
        $this->assertGreaterThan(GregJournal::PROMPT_AGENTS - 200, strlen($ctx));
    }

    // --------------------------------------------------------- captain's log

    public function testAnEntryIsDatedAndReadableInTheLog(): void
    {
        GregJournal::append('HOMER moved behind the VPN', ['10.8.0.5:9080', 'firewall now only allows the PBX'], 'kaiel');
        $path = GregJournal::todayPath();
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString(gmdate('Y-m-d'), $body);
        $this->assertStringContainsString('HOMER moved behind the VPN', $body);
        $this->assertStringContainsString('(kaiel)', $body);
        $this->assertStringContainsString('10.8.0.5:9080', GregJournal::recentText());
        $this->assertStringContainsString('HOMER moved behind the VPN', GregJournal::context());
    }

    public function testATitleCannotForgeAHeadingOrASecondEntry(): void
    {
        GregJournal::append("Real note\n# forged heading", ["line one\n- forged bullet"], 'x');
        $body = (string) file_get_contents(GregJournal::todayPath());
        $this->assertStringNotContainsString("\n# forged heading", $body);
        $this->assertStringNotContainsString("\n- forged bullet", $body);
        $this->assertSame(1, substr_count($body, '- **'), 'one entry only');
    }

    public function testAnUnwritableLocationNeverBreaksTheAction(): void
    {
        putenv('AIPBX_GREG_DIR=/proc/aipbx-must-fail');
        GregJournal::append('should not throw');
        $this->assertSame('', GregJournal::recentText());
        putenv('AIPBX_GREG_DIR=' . $this->dir);
    }

    public function testSavingAgentsAlsoLeavesATrailInTheLog(): void
    {
        GregJournal::saveAgents('a fact', 'kaiel');
        $this->assertStringContainsString('AGENTS.md updated', GregJournal::recentText());
    }
}
