<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/AsteriskDocsService.php';

/**
 * The upstream Asterisk reference: a fixed catalog, a cache that survives a
 * failed refresh, and retrieval that only fires when the question shares
 * words with the manual.
 */
final class AsteriskDocsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        AsteriskDocsService::remove();
    }

    protected function tearDown(): void
    {
        AsteriskDocsService::remove();
    }

    public function testCatalogIsFixedAndSafe(): void
    {
        $this->assertGreaterThanOrEqual(20, count(AsteriskDocsService::PAGES));
        foreach (AsteriskDocsService::PAGES as $slug => [$title, $path]) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
            $this->assertStringEndsWith('.md', $path);
            $this->assertStringNotContainsString('..', $path);
            $this->assertNotSame('', trim($title));
        }
        $this->assertSame(
            'https://docs.asterisk.org/Configuration/Channel-Drivers/SIP/Configuring-res_pjsip/',
            AsteriskDocsService::url('Configuration/Channel-Drivers/SIP/Configuring-res_pjsip/index.md')
        );
        $this->assertSame(
            'https://docs.asterisk.org/Configuration/Dialplan/Pattern-Matching/',
            AsteriskDocsService::url('Configuration/Dialplan/Pattern-Matching.md')
        );
    }

    public function testSyncWritesPagesAndIndex(): void
    {
        $result = AsteriskDocsService::sync($this->fetcher());

        $this->assertSame(count(AsteriskDocsService::PAGES), $result['saved']);
        $this->assertSame([], $result['failed']);
        $this->assertFileExists(AsteriskDocsService::indexPath());
        $index = json_decode((string) file_get_contents(AsteriskDocsService::indexPath()), true);
        $this->assertSame(AsteriskDocsService::LICENSE, $index['license']);
        $this->assertCount(count(AsteriskDocsService::PAGES), $index['pages']);
        $this->assertArrayHasKey('pjsip-nat', $index['pages']);

        $status = AsteriskDocsService::status();
        $this->assertTrue($status['ready']);
        $this->assertSame(count(AsteriskDocsService::PAGES), $status['pages']);
        $this->assertSame([], $status['missing']);
        $this->assertGreaterThan(0, $status['updated']);
        $this->assertGreaterThan(0, $status['bytes']);
    }

    public function testAFailedRefreshKeepsThePreviousCopy(): void
    {
        AsteriskDocsService::sync($this->fetcher());
        $before = (string) file_get_contents(AsteriskDocsService::pagePath('pjsip-nat'));

        $result = AsteriskDocsService::sync(static fn(string $url): array =>
            ['ok' => false, 'body' => '', 'error' => 'offline']);

        $this->assertSame(0, $result['saved']);
        $this->assertSame(count(AsteriskDocsService::PAGES), $result['kept']);
        $this->assertSame($before, (string) file_get_contents(AsteriskDocsService::pagePath('pjsip-nat')));
        $this->assertTrue(AsteriskDocsService::status()['ready']);
    }

    public function testOneBadPageDoesNotStopTheOthers(): void
    {
        $result = AsteriskDocsService::sync($this->fetcher([
            'PJSIP-Authentication.md' => null, // null means: fail this URL
        ]));

        $this->assertContains('pjsip-auth', $result['failed']);
        $this->assertSame(count(AsteriskDocsService::PAGES) - 1, $result['saved']);
        $this->assertSame(count(AsteriskDocsService::PAGES) - 1, AsteriskDocsService::status()['pages']);
    }

    public function testNothingReachableAndNoCacheIsAnHonestError(): void
    {
        $this->expectException(\CloudAiException::class);
        AsteriskDocsService::sync(static fn(string $url): array =>
            ['ok' => false, 'body' => '', 'error' => 'offline']);
    }

    public function testRemoveDeletesTheCache(): void
    {
        AsteriskDocsService::sync($this->fetcher());
        AsteriskDocsService::remove();

        $this->assertFileDoesNotExist(AsteriskDocsService::indexPath());
        $this->assertFileDoesNotExist(AsteriskDocsService::pagePath('pjsip-nat'));
        $this->assertFalse(AsteriskDocsService::status()['ready']);
        $this->assertSame('', AsteriskDocsService::contextFor('one-way audio behind NAT'));
    }

    public function testRetrievalFindsTheRightPage(): void
    {
        AsteriskDocsService::sync($this->fetcher());

        $hits = AsteriskDocsService::search('Why do calls have one-way audio behind NAT?');
        $this->assertNotEmpty($hits);
        $this->assertSame('pjsip-nat', $hits[0]['slug']);
        $this->assertStringContainsString('one-way audio', $hits[0]['text']);
        $this->assertStringStartsWith('https://docs.asterisk.org/', $hits[0]['url']);
    }

    public function testATurkishQuestionReachesTheEnglishDocs(): void
    {
        AsteriskDocsService::sync($this->fetcher());

        $hits = AsteriskDocsService::search('Dış hattan gelen çağrıda tek yönlü ses var, neden?');
        $this->assertNotEmpty($hits);
        $this->assertSame('pjsip-nat', $hits[0]['slug']);
    }

    public function testContextCitesTheSourceAndStaysBounded(): void
    {
        AsteriskDocsService::sync($this->fetcher());

        $context = AsteriskDocsService::contextFor('PJSIP endpoint does not register, gets 401');
        $this->assertNotSame('', $context);
        $this->assertStringContainsString('docs.asterisk.org', $context);
        $this->assertStringContainsString(AsteriskDocsService::LICENSE, $context);
        $this->assertStringContainsString('https://docs.asterisk.org/', $context);
        $this->assertLessThanOrEqual(AsteriskDocsService::MAX_CONTEXT, mb_strlen($context));
    }

    public function testAGreetingDoesNotDragTheManualIntoThePrompt(): void
    {
        AsteriskDocsService::sync($this->fetcher());
        $this->assertSame('', AsteriskDocsService::contextFor('Good morning, how are you?'));
        $this->assertSame('', AsteriskDocsService::contextFor('Merhaba, nasılsın?'));
    }

    public function testCleanDropsFrontmatterAndMarkupButKeepsProse(): void
    {
        $raw = "---\ntitle: NAT\npageid: 1\n---\n\nNAT\n===\n\n"
            . "See [the guide](/Configuration/Dialplan/) and ![diagram](/img/nat.png).\n\n"
            . "/// warning | Careful\nA one-way audio call is usually NAT.\n///\n";
        $clean = AsteriskDocsService::clean($raw);
        $this->assertStringNotContainsString('pageid', $clean);
        $this->assertStringNotContainsString('](/', $clean);
        $this->assertStringNotContainsString('///', $clean);
        $this->assertStringContainsString('See the guide and diagram.', $clean);
        $this->assertStringContainsString('warning: Careful', $clean);
        $this->assertStringContainsString('one-way audio', $clean);
    }

    public function testSectionsUnderstandBothHeadingStyles(): void
    {
        $md = "Overview\n===\n\nOne-way audio is a symptom of a media path that never opened, "
            . "not of a dialplan that failed to route the call.\n\n"
            . "## Registrations\n\nA 401 means the password was refused; a 403 means the endpoint "
            . "matched but the request was rejected by the configuration.\n\n"
            . "Hangup\n------\n\nCause 21 is a call rejection, not a network failure; "
            . "read the cause code before you blame the carrier or the firewall.";
        $sections = AsteriskDocsService::sections($md, 'PJSIP');
        $headings = array_column($sections, 'heading');
        $this->assertSame(['Overview', 'Registrations', 'Hangup'], $headings);
        $this->assertStringContainsString('401', $sections[1]['text']);
    }

    /**
     * A fetcher that answers every catalog URL with a small valid page, except
     * the keys in $overrides (null = fail).
     *
     * @param array<string, string|null> $overrides basename => markdown|null
     */
    private function fetcher(array $overrides = []): callable
    {
        return static function (string $url) use ($overrides): array {
            $base = basename($url);
            if (array_key_exists($base, $overrides)) {
                if ($overrides[$base] === null) {
                    return ['ok' => false, 'body' => '', 'error' => 'HTTP 404'];
                }
                return ['ok' => true, 'body' => $overrides[$base], 'error' => ''];
            }
            if (str_contains($url, 'Configuring-res_pjsip-to-work-through-NAT.md')) {
                return ['ok' => true, 'body' => "---\ntitle: PJSIP through NAT\n---\n\n"
                    . "NAT\n===\n\n"
                    . "One-way audio is the classic NAT symptom: the signalling arrives, the media does not. "
                    . "A call with one-way audio usually has a NAT problem: Asterisk sends RTP to a private address "
                    . "and the far end never hears it. Inbound calls behind NAT lose audio the same way. "
                    . "Fix the transport, the external address and the local network list before touching the dialplan.\n\n"
                    . "## Media\n\n"
                    . "One-way audio and no audio both point at NAT or at a firewall dropping RTP. "
                    . "A call that connects but carries no audio is a media-path problem, not a dialplan problem. "
                    . "Check the SDP with 'pjsip set logger on' and confirm the advertised address is public.\n",
                    'error' => ''];
            }
            if (str_contains($url, 'PJSIP-Authentication.md')) {
                return ['ok' => true, 'body' => "---\ntitle: PJSIP authentication\n---\n\n"
                    . "Authentication\n===\n\n"
                    . "A 401 challenge means the endpoint has no matching auth object, or the password is wrong. "
                    . "A 403 means the endpoint matched but the request was refused. Read the challenge, then the auth section.\n\n"
                    . "## Endpoint\n\n"
                    . "Every endpoint needs an aor, an auth and the endpoint section itself. "
                    . "Registration also needs an outbound registration object with server_uri and client_uri.\n",
                    'error' => ''];
            }
            if (str_contains($url, 'Pattern-Matching.md')) {
                return ['ok' => true, 'body' => "---\ntitle: Pattern matching\n---\n\n"
                    . "Patterns\n===\n\n"
                    . "The underscore starts a pattern: _2XX matches 200 to 299. "
                    . "The dot matches one digit and the bang matches one or more digits. "
                    . "Order matters: the first matching extension in a context wins.\n",
                    'error' => ''];
            }
            $title = ucwords(str_replace(['-', '_', '.md'], ' ', $base));
            return ['ok' => true, 'body' => "---\ntitle: {$title}\n---\n\n{$title}\n===\n\n"
                . str_repeat('Asterisk documentation text about telephony, configuration and operations. ', 6) . "\n",
                'error' => ''];
        };
    }
}
