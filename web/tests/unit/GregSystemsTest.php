<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregSystems.php';

/**
 * The systems map: the second document Greg reads, about what the PBX is
 * attached to and what it should watch.
 *
 * Same rules as the business brief — one markdown file, outside git, refused
 * rather than truncated, audited without the content — plus the section count
 * the readiness card uses.
 */
final class GregSystemsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        @unlink(GregSystems::path());
        $this->db->exec("DELETE FROM sys_audit_log WHERE entity_type = 'systems'");
    }

    protected function tearDown(): void
    {
        @unlink(GregSystems::path());
        $this->db->exec("DELETE FROM sys_audit_log WHERE entity_type = 'systems'");
    }

    public function testTheTemplateAsksWhatTheEngineerWouldAsk(): void
    {
        $t = GregSystems::template();
        $this->assertTrue(mb_check_encoding($t, 'UTF-8'));
        $this->assertLessThan(GregSystems::MAX_CHARS, mb_strlen($t));
        $this->assertSame(6, preg_match_all('/^##\s+\S/m', $t), 'six parts, one per map');
        foreach ([
            '# Systems documentation',
            'What this PBX is for',
            'Carriers and trunks',
            'Connected systems',
            'What Greg should watch',
            'Access and ownership',
            'Constraints and history',
            'Do not put passwords',
            'CRM',
        ] as $needle) {
            $this->assertStringContainsString($needle, $t, $needle);
        }
    }

    public function testASavedMapIsReadBackAndCounted(): void
    {
        $doc = "# Systems\n\n## 1. What this PBX is for\nA dental clinic's phones.\n\n"
            . "## 2. Carriers and trunks\nBell, one trunk.\n\n"
            . "## 3. Connected systems\nDentrix via screen-pop.\n";
        GregSystems::save($doc);

        $this->assertTrue(GregSystems::exists());
        $this->assertStringContainsString('Dentrix', GregSystems::text());
        $status = GregSystems::status();
        $this->assertTrue($status['exists']);
        $this->assertSame(3, $status['sections']);
        $this->assertSame(mb_strlen(trim($doc)), $status['chars']);
        $this->assertGreaterThan(0, $status['updated']);
    }

    public function testTheContextIsBoundedForThePrompt(): void
    {
        $doc = "# Long\n\n## 1. What this PBX is for\n" . str_repeat("A line about the trunk and the CRM.\n", 900);
        GregSystems::save($doc);
        $this->assertGreaterThan(GregSystems::PROMPT_CHARS, mb_strlen(GregSystems::text()));

        $ctx = GregSystems::context();
        $this->assertStringContainsString('SYSTEMS DOCUMENTATION', $ctx);
        $this->assertStringContainsString('map of the environment', $ctx);
        $this->assertLessThan(GregSystems::PROMPT_CHARS + 700, mb_strlen($ctx));
        $this->assertStringContainsString('continues', $ctx);
        $this->assertSame(mb_strlen(trim($doc)), GregSystems::status()['chars']);
    }

    public function testAnEmptyContextUntilThereIsADocument(): void
    {
        $this->assertSame('', GregSystems::context(), 'no document, no prompt block, no nagging');
    }

    public function testAnEmptyOrOversizedDocumentIsRefusedRatherThanTruncated(): void
    {
        foreach (['', "  \n\n"] as $empty) {
            try {
                GregSystems::save($empty);
                $this->fail('an empty document must be refused');
            } catch (\CloudAiException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        try {
            GregSystems::save(str_repeat('a', GregSystems::MAX_CHARS + 1));
            $this->fail('an oversized document must be refused');
        } catch (\CloudAiException $e) {
            $this->assertStringContainsString(number_format(GregSystems::MAX_CHARS), $e->getMessage());
        }
        $this->assertFalse(GregSystems::exists(), 'a refused save leaves nothing behind');
    }

    public function testOnlyMarkdownAndTextUploadsAreAccepted(): void
    {
        try {
            GregSystems::saveUpload(['error' => UPLOAD_ERR_NO_FILE, 'name' => 'systems.md']);
            $this->fail('no file must be refused');
        } catch (\CloudAiException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        try {
            GregSystems::saveUpload(['error' => UPLOAD_ERR_OK, 'name' => 'systems.exe',
                                     'tmp_name' => __FILE__]);
            $this->fail('an .exe must be refused');
        } catch (\CloudAiException $e) {
            $this->assertStringContainsString('.md', $e->getMessage());
        }
        try {
            GregSystems::saveUpload(['error' => UPLOAD_ERR_OK, 'name' => 'systems.md',
                                     'tmp_name' => __FILE__]);
            $this->fail('a non-uploaded path must be refused');
        } catch (\CloudAiException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertFalse(GregSystems::exists());
    }

    public function testSavingAndRemovingAreAuditedWithoutTheContent(): void
    {
        GregSystems::save("# Systems\n\n## 1. What this PBX is for\nA clinic's phones.\n");
        $rows = $this->db->query(
            "SELECT action, entity_label FROM sys_audit_log WHERE entity_type = 'systems' ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertStringNotContainsString("clinic's phones", $rows[0]['entity_label']);

        GregSystems::remove();
        $this->assertFalse(GregSystems::exists());
        $actions = $this->db->query(
            "SELECT action FROM sys_audit_log WHERE entity_type = 'systems' ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['update', 'delete'], $actions);
    }
}
