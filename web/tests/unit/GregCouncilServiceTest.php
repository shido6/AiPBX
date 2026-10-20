<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregCouncilService.php';

/**
 * Two engineers, one answer.
 *
 * These tests pin the contract the administrator relies on: the on-duty
 * engineer's draft is read by the other before a human sees it, a disagreement
 * is settled in the on-duty engineer's voice, and a reviewer that is down or
 * cannot follow the format costs a second opinion — never the answer itself.
 */
final class GregCouncilServiceTest extends TestCase
{
    private const DUTY = ['key' => 'greg', 'name' => 'Greg', 'source' => 'bigpickle'];
    private const RELIEF = ['key' => 'relief', 'name' => 'Mahir Gul', 'source' => 'system'];

    /** @return array<int, array{source: string, prompt: string}> */
    private function recorder(array $replies): array
    {
        $calls = [];
        $ask = function (string $source, string $prompt) use (&$calls, $replies): string {
            $calls[] = ['source' => $source, 'prompt' => $prompt];
            $next = array_shift($replies);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return (string) $next;
        };
        return [$calls, $ask];
    }

    public function testAnAgreeingReviewerLeavesTheDraftUntouched(): void
    {
        $calls = [];
        $ask = function (string $source, string $prompt) use (&$calls): string {
            $calls[] = [$source, $prompt];
            return '```json' . "\n" . '{"agree": true, "notes": "Supported by the CDRs."}' . "\n" . '```';
        };

        $out = GregCouncilService::deliberate(
            'Why did calls drop?', 'CDR context', 'The trunk lost registration at 03:10.',
            self::DUTY, self::RELIEF, $ask
        );

        $this->assertSame('The trunk lost registration at 03:10.', $out['text']);
        $this->assertTrue($out['reviewed']);
        $this->assertTrue($out['agreed']);
        $this->assertFalse($out['reconciled']);
        $this->assertSame('Mahir Gul', $out['reviewer']);
        $this->assertCount(1, $calls, 'an agreeing reviewer needs no second pass');
        $this->assertSame('system', $calls[0][0], 'the reviewer answers on his own model source');
        $this->assertStringContainsString('Why did calls drop?', $calls[0][1]);
        $this->assertStringContainsString('The trunk lost registration at 03:10.', $calls[0][1]);
    }

    public function testADisagreementIsSettledByTheEngineerOnDuty(): void
    {
        $calls = [];
        $ask = function (string $source, string $prompt) use (&$calls): string {
            $calls[] = [$source, $prompt];
            if (count($calls) === 1) {
                return '{"agree": false, "notes": "The CDR shows the trunk was up; the gateway rejected INVITEs.",'
                    . ' "answer": "The gateway rejected the INVITEs."}';
            }
            return 'The gateway rejected the INVITEs after 03:10 — the carrier trunk itself stayed registered.';
        };

        $out = GregCouncilService::deliberate(
            'Why did calls drop?', 'CDR context', 'The trunk lost registration at 03:10.',
            self::DUTY, self::RELIEF, $ask
        );

        $this->assertSame(
            'The gateway rejected the INVITEs after 03:10 — the carrier trunk itself stayed registered.',
            $out['text']
        );
        $this->assertTrue($out['reviewed']);
        $this->assertTrue($out['agreed'], 'the settled answer is the shared one');
        $this->assertTrue($out['reconciled']);
        $this->assertStringContainsString('gateway rejected INVITEs', $out['disagreement']);
        $this->assertCount(2, $calls);
        $this->assertSame('system', $calls[0][0], 'the other engineer reviews first');
        $this->assertSame('bigpickle', $calls[1][0], 'the on-duty engineer has the last word');
        $this->assertStringContainsString('gateway rejected INVITEs', $calls[1][1]);
        $this->assertStringContainsString('Final answer:', $calls[1][1]);
    }

    public function testAReviewerThatCannotFollowTheFormatDoesNotRewriteTheAnswer(): void
    {
        $ask = fn(string $source, string $prompt): string => 'Well, it depends on the carrier, really.';
        $out = GregCouncilService::deliberate(
            'q', 'ctx', 'draft', self::DUTY, self::RELIEF, $ask
        );
        $this->assertSame('draft', $out['text']);
        $this->assertFalse($out['reviewed']);
        $this->assertFalse($out['agreed']);
    }

    public function testAFailingReviewerNeverBreaksTheAnswer(): void
    {
        $ask = function (): string {
            throw new \RuntimeException('provider unavailable');
        };
        $out = GregCouncilService::deliberate(
            'q', 'ctx', 'draft', self::DUTY, self::RELIEF, $ask
        );
        $this->assertSame('draft', $out['text']);
        $this->assertFalse($out['reviewed']);
    }

    public function testIfTheSettlingPassFailsTheReviewersCorrectionIsUsedInsteadOfTheDraft(): void
    {
        $calls = 0;
        $ask = function (string $source, string $prompt) use (&$calls): string {
            $calls++;
            if ($calls === 1) {
                return '{"agree": false, "notes": "Wrong trunk.", "answer": "It was the secondary trunk."}';
            }
            throw new \RuntimeException('provider unavailable');
        };
        $out = GregCouncilService::deliberate(
            'q', 'ctx', 'draft', self::DUTY, self::RELIEF, $ask
        );
        $this->assertSame('It was the secondary trunk.', $out['text']);
        $this->assertTrue($out['reviewed']);
        $this->assertFalse($out['agreed'], 'not settled by both, so not presented as agreed');
        $this->assertFalse($out['reconciled']);
    }

    public function testIfBothTheSettlingPassAndTheCorrectionFailTheDraftStands(): void
    {
        $calls = 0;
        $ask = function (string $source, string $prompt) use (&$calls): string {
            $calls++;
            if ($calls === 1) {
                return '{"agree": false, "notes": "Wrong trunk."}';
            }
            return '   ';
        };
        $out = GregCouncilService::deliberate(
            'q', 'ctx', 'draft', self::DUTY, self::RELIEF, $ask
        );
        $this->assertSame('draft', $out['text']);
        $this->assertTrue($out['reviewed']);
        $this->assertFalse($out['agreed']);
    }

    public function testOneEngineerIsNotACouncil(): void
    {
        $called = false;
        $ask = function () use (&$called): string {
            $called = true;
            return '{"agree": true}';
        };
        $same = ['name' => 'Greg', 'source' => 'system'];
        $out = GregCouncilService::deliberate('q', 'ctx', 'draft', self::DUTY, $same, $ask);
        $this->assertSame('draft', $out['text']);
        $this->assertFalse($out['reviewed']);
        $this->assertFalse($called);

        $out = GregCouncilService::deliberate('q', 'ctx', '', self::DUTY, self::RELIEF, $ask);
        $this->assertSame('', $out['text']);
        $this->assertFalse($called);
    }

    public function testParseReviewAcceptsFencesAndPlainAgreement(): void
    {
        $this->assertTrue(GregCouncilService::parseReview('AGREE — the CDR supports it')['agree']);
        $this->assertFalse(GregCouncilService::parseReview('```json
{"agree": "false", "notes": "n"}
```')['agree'], 'the string "false" must not be truthy');

        $no = GregCouncilService::parseReview('DISAGREE: the trunk was up');
        $this->assertFalse($no['agree']);
        $this->assertSame('the trunk was up', $no['notes']);

        $this->assertNull(GregCouncilService::parseReview('I think it is fine.'));
        $this->assertNull(GregCouncilService::parseReview(''));
    }

    public function testTheReviewContextIsBounded(): void
    {
        $calls = [];
        $ask = function (string $source, string $prompt) use (&$calls): string {
            $calls[] = $prompt;
            return '{"agree": true}';
        };
        $out = GregCouncilService::deliberate(
            'q', str_repeat('c', 50000), str_repeat('d', 50000),
            self::DUTY, self::RELIEF, $ask
        );
        $this->assertSame(50000, mb_strlen($out['text']), 'the draft itself is not clipped');
        $this->assertLessThan(40000, mb_strlen($calls[0]), 'the reviewer gets a bounded context');
    }
}
