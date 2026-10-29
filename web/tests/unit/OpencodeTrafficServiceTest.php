<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/OpencodeTrafficService.php';

/**
 * What leaves the server when opencode uses a remote model — and what does
 * not. The payload must be encrypted at rest, local providers must be skipped,
 * and the admin list must not need to decrypt every row just to render.
 */
final class OpencodeTrafficServiceTest extends TestCase
{
    private array $session = [];

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? [];
        $_SESSION = ['user_id' => 7, 'username' => 'auditor', 'user_role' => 'admin'];
        getDB()->exec('DELETE FROM pbx_opencode_exchanges');
    }

    protected function tearDown(): void
    {
        getDB()->exec('DELETE FROM pbx_opencode_exchanges');
        $_SESSION = $this->session;
    }

    public function testLocalProvidersAreNotRecorded(): void
    {
        $this->assertTrue(OpencodeTrafficService::isLocalModel('ollama/qwen2.5:7b'));
        $this->assertTrue(OpencodeTrafficService::isLocalModel('lmstudio/local-model'));
        $this->assertFalse(OpencodeTrafficService::isLocalModel('opencode/big-pickle'));
        $this->assertFalse(OpencodeTrafficService::isLocalModel('acme/remote-model'));

        $this->assertSame(0, OpencodeTrafficService::record(
            'chat', 'ollama/qwen2.5:7b', 'stay on this box', 'local answer', null, 'ok', 20
        ));
        $this->assertSame(0, OpencodeTrafficService::stats()['total']);
    }

    public function testRemoteExchangeIsEncryptedAndReadable(): void
    {
        $prompt = "Customer says the line is down.\nAccount: 12345.";
        $id = OpencodeTrafficService::record(
            'chat', 'opencode/big-pickle', $prompt, 'Ask for the Call-ID.', null, 'ok', 1234
        );
        $this->assertGreaterThan(0, $id);

        $row = getDB()->query('SELECT * FROM pbx_opencode_exchanges WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('opencode', $row['provider']);
        $this->assertSame('chat', $row['purpose']);
        $this->assertSame('ok', $row['status']);
        $this->assertSame(strlen($prompt), (int) $row['request_bytes']);
        $this->assertSame(hash('sha256', $prompt), $row['request_sha256']);
        $this->assertStringNotContainsString('Customer says', $row['request_enc'], 'payloads are encrypted at rest');
        $this->assertStringNotContainsString('Call-ID', $row['response_enc']);

        $found = OpencodeTrafficService::find($id);
        $this->assertNotNull($found);
        $this->assertSame($prompt, $found['request']);
        $this->assertSame('Ask for the Call-ID.', $found['response']);
        $this->assertSame('auditor', $found['user_name']);
        $this->assertSame(7, (int) $found['created_by']);
    }

    public function testErrorIsRecordedWithoutAResponse(): void
    {
        $id = OpencodeTrafficService::record(
            'proposal', 'acme/remote-model', 'draft a tool', null,
            'free tier exhausted (Bearer abc.def)', 'error', 500
        );
        $found = OpencodeTrafficService::find($id);
        $this->assertSame('error', $found['status']);
        $this->assertSame('free tier exhausted (Bearer ***)', $found['error']);
        $this->assertSame(0, (int) $found['response_bytes']);
    }

    public function testRecentDoesNotDecryptPayloads(): void
    {
        OpencodeTrafficService::record('chat', 'opencode/a', 'one', 'first', null, 'ok', 10);
        OpencodeTrafficService::record('chat', 'opencode/b', 'two', 'second', null, 'ok', 20);

        $recent = OpencodeTrafficService::recent(2);
        $this->assertCount(2, $recent);
        $this->assertSame('opencode/b', $recent[0]['model']);
        $this->assertArrayNotHasKey('request', $recent[0]);
        $this->assertArrayNotHasKey('response', $recent[0]);
        $older = OpencodeTrafficService::recent(1, 1);
        $this->assertCount(1, $older);
        $this->assertSame('opencode/a', $older[0]['model'], 'offset pages through the ledger');
    }

    public function testRetentionRemovesOldRowsOnTheNextRecord(): void
    {
        $old = OpencodeTrafficService::record('chat', 'opencode/old', 'old prompt', 'old', null, 'ok', 10);
        getDB()->exec('UPDATE pbx_opencode_exchanges SET created_at = (NOW() - INTERVAL 100 DAY) WHERE id = ' . $old);
        OpencodeTrafficService::record('chat', 'opencode/new', 'new prompt', 'new', null, 'ok', 10);

        $ids = getDB()->query('SELECT id FROM pbx_opencode_exchanges ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains($old, array_map('intval', $ids));
        $this->assertCount(1, $ids);
        $this->assertSame(1, OpencodeTrafficService::stats()['last_day']);
    }

    public function testPurgeRemovesEverything(): void
    {
        OpencodeTrafficService::record('chat', 'opencode/a', 'one', 'first', null, 'ok', 10);
        OpencodeTrafficService::record('proposal', 'opencode/b', 'two', 'second', null, 'ok', 20);
        $this->assertSame(2, OpencodeTrafficService::purge());
        $this->assertSame(0, OpencodeTrafficService::stats()['total']);
    }
}
