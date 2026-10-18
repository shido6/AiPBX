<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregBrief.php';

/**
 * The onboarding brief: one markdown file Greg asks for, once.
 *
 * These tests use the temporary AIPBX_GREG_DIR from the bootstrap, so nothing
 * is written next to the live installation's notes.
 */
final class GregBriefTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        @unlink(GregBrief::path());
        $this->db->exec("DELETE FROM sys_audit_log WHERE entity_type = 'brief'");
    }

    protected function tearDown(): void
    {
        @unlink(GregBrief::path());
        $this->db->exec("DELETE FROM sys_audit_log WHERE entity_type = 'brief'");
    }

    // ------------------------------------------------------------- the ask

    public function testWithoutABriefHeAsksOnceAndThenSaysNothing(): void
    {
        $this->assertFalse(GregBrief::exists());
        $this->assertSame('', GregBrief::text());

        $this->assertSame('', GregBrief::context(false), 'a later answer must not nag');

        $ask = GregBrief::context(true);
        $this->assertStringContainsString('do not have an onboarding brief', $ask);
        $this->assertStringContainsString('one markdown file', $ask);
        $this->assertStringContainsString('do not bring it up again', $ask);
    }

    // ------------------------------------------------------------ template

    public function testTheTemplateAsksWhatANewEngineerWouldAsk(): void
    {
        $t = GregBrief::template();
        $this->assertNotSame('', $t);
        $this->assertTrue(mb_check_encoding($t, 'UTF-8'));
        $this->assertLessThan(GregBrief::MAX_CHARS, mb_strlen($t));
        foreach ([
            '# Onboarding brief',
            '## 1. The business',
            '## 2. The phone system',
            '## 3. Carriers and network',
            '## 4. People',
            '## 5. Constraints and history',
            '## 6. What good looks like',
            'Do not put passwords',
            'What a missed call costs us',
        ] as $needle) {
            $this->assertStringContainsString($needle, $t, $needle);
        }
    }

    // --------------------------------------------------------------- saving

    public function testASavedBriefIsReadBackAndUsedInThePrompt(): void
    {
        $brief = "# Acme Dental\n\nWe are a dental clinic in Moncton. The emergency line is 700.\n";
        GregBrief::save($brief);

        $this->assertTrue(GregBrief::exists());
        $this->assertStringContainsString('Acme Dental', GregBrief::text());
        $status = GregBrief::status();
        $this->assertTrue($status['exists']);
        $this->assertSame(mb_strlen(trim($brief)), $status['chars']);
        $this->assertGreaterThan(0, $status['updated']);

        $ctx = GregBrief::context(true);
        $this->assertStringContainsString('ONBOARDING BRIEF', $ctx);
        $this->assertStringContainsString('Acme Dental', $ctx);
        $this->assertStringContainsString('emergency line is 700', $ctx);
        $this->assertStringNotContainsString('do not have an onboarding brief', $ctx,
            'with a brief in hand there is nothing to ask for');
        $this->assertStringContainsString('stale', $ctx, 'he is told what to do when it disagrees with live data');
    }

    public function testAnEmptyOrOversizedBriefIsRefusedRatherThanTruncated(): void
    {
        foreach (['', "   \n\n"] as $empty) {
            try {
                GregBrief::save($empty);
                $this->fail('an empty brief must be refused');
            } catch (\CloudAiException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        try {
            GregBrief::save(str_repeat('a', GregBrief::MAX_CHARS + 1));
            $this->fail('an oversized brief must be refused');
        } catch (\CloudAiException $e) {
            $this->assertStringContainsString(number_format(GregBrief::MAX_CHARS), $e->getMessage());
        }
        $this->assertFalse(GregBrief::exists(), 'a refused save must not leave half a file behind');
    }

    public function testOnlyMarkdownAndTextUploadsAreAccepted(): void
    {
        try {
            GregBrief::saveUpload(['error' => UPLOAD_ERR_NO_FILE, 'name' => 'brief.md']);
            $this->fail('no file must be refused');
        } catch (\CloudAiException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        try {
            GregBrief::saveUpload(['error' => UPLOAD_ERR_OK, 'name' => 'brief.exe',
                                   'tmp_name' => __FILE__]);
            $this->fail('an .exe must be refused');
        } catch (\CloudAiException $e) {
            $this->assertStringContainsString('.md', $e->getMessage());
        }

        // The happy path needs a real uploaded file; the service still refuses
        // one that is not, which is exactly what an attacker would try.
        try {
            GregBrief::saveUpload(['error' => UPLOAD_ERR_OK, 'name' => 'brief.md',
                                   'tmp_name' => __FILE__]);
            $this->fail('a non-upload path must be refused');
        } catch (\CloudAiException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertFalse(GregBrief::exists());
    }

    public function testALongBriefIsCutForThePromptButKeptWholeOnDisk(): void
    {
        $text = "# Long\n\n" . str_repeat("Line about the call centre and its queue.\n", 900);
        GregBrief::save($text);
        $this->assertGreaterThan(GregBrief::PROMPT_CHARS, mb_strlen(GregBrief::text()));

        $ctx = GregBrief::context();
        $this->assertLessThan(GregBrief::PROMPT_CHARS + 800, mb_strlen($ctx));
        $this->assertStringContainsString('The brief continues', $ctx);
        $this->assertSame(mb_strlen(trim($text)), GregBrief::status()['chars'],
            'the file itself is not truncated');
    }

    public function testSavingAndRemovingAreAudited(): void
    {
        GregBrief::save("# Brief\n\nSomething real.\n");
        $rows = $this->db->query("SELECT action FROM sys_audit_log WHERE entity_type = 'brief' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['update'], $rows);

        GregBrief::remove();
        $rows = $this->db->query("SELECT action FROM sys_audit_log WHERE entity_type = 'brief' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['update', 'delete'], $rows);
        $this->assertFalse(GregBrief::exists());
    }
}
