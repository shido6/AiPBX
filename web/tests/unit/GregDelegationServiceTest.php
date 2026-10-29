<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregDelegationService.php';

/**
 * Two engineers, one problem: only multi-part questions are split, only a
 * valid two-owner plan is used, and the final answer is built from both sets
 * of findings instead of one engineer's guess.
 */
final class GregDelegationServiceTest extends TestCase
{
    private static function duty(): array
    {
        return ['key' => 'greg', 'name' => 'Greg', 'source' => 'bigpickle', 'relief' => false];
    }

    private static function other(): array
    {
        return ['key' => 'relief', 'name' => 'Mahir Gul', 'source' => 'system', 'relief' => true];
    }

    private static function planJson(string $otherOwner = 'Mahir Gul'): string
    {
        return '```json
{"sections":[
 {"title":"Trunk registration","question":"Are the PJSIP trunks registered?","owner":"Greg","finding":"Both trunks registered at 09:12 UTC."},
 {"title":"Outbound route","question":"Which route sends calls to the old carrier?","owner":"' . $otherOwner . '","finding":""}
]}
```';
    }

    public function testAShortSingleQuestionIsNotSplit(): void
    {
        $this->assertFalse(GregDelegationService::shouldDelegate('Why is the trunk down?'));
        $this->assertFalse(GregDelegationService::shouldDelegate('Restart the queue.'));
    }

    public function testAMultiPartQuestionOrAnExplicitRequestIsSplit(): void
    {
        $this->assertTrue(GregDelegationService::shouldDelegate(
            'The sales queue is dropping calls. The trunk shows one-way audio on some calls. What should I check first?'
        ));
        $this->assertTrue(GregDelegationService::shouldDelegate('Split this: check the trunk and the dialplan.'));
        $this->assertTrue(GregDelegationService::shouldDelegate('Bölüştür: trunk ve dialplan.'));
    }

    public function testCanDelegateNeedsTwoDifferentNamedEngineers(): void
    {
        $this->assertTrue(GregDelegationService::canDelegate(self::duty(), self::other()));
        $this->assertFalse(GregDelegationService::canDelegate(self::duty(), self::duty()));
        $same = self::other();
        $same['name'] = 'Greg';
        $this->assertFalse(GregDelegationService::canDelegate(self::duty(), $same));
        $noSource = self::other();
        $noSource['source'] = '';
        $this->assertFalse(GregDelegationService::canDelegate(self::duty(), $noSource));
    }

    public function testPlanReadsBothOwnersAndCodeFences(): void
    {
        $sections = GregDelegationService::parsePlan(self::planJson(), self::duty(), self::other());
        $this->assertCount(2, $sections);
        $this->assertSame('duty', $sections[0]['owner']);
        $this->assertSame('Greg', $sections[0]['owner_name']);
        $this->assertSame('other', $sections[1]['owner']);
        $this->assertSame('Mahir Gul', $sections[1]['owner_name']);
        $this->assertSame('Both trunks registered at 09:12 UTC.', $sections[0]['finding']);
    }

    public function testPlanAcceptsTheOtherNameAsTheOwner(): void
    {
        $sections = GregDelegationService::parsePlan(self::planJson('Mahir Gul'), self::duty(), self::other());
        $this->assertSame('other', $sections[1]['owner']);
    }

    public function testOneSectionOrAnAllDutyPlanIsRefused(): void
    {
        $one = '{"sections":[{"title":"Only one","question":"Check it","owner":"Greg"}]}';
        $this->assertSame([], GregDelegationService::parsePlan($one, self::duty(), self::other()));

        $allDuty = '{"sections":[
            {"title":"A","question":"First part","owner":"Greg"},
            {"title":"B","question":"Second part","owner":"Greg"}
        ]}';
        $this->assertSame([], GregDelegationService::parsePlan($allDuty, self::duty(), self::other()));
    }

    public function testGarbageIsNotAPlan(): void
    {
        $this->assertSame([], GregDelegationService::parsePlan('I would check the trunk first.', self::duty(), self::other()));
        $this->assertSame([], GregDelegationService::parsePlan('{"sections":"two"}', self::duty(), self::other()));
    }

    public function testRunSharesFindingsAndBuildsTheSynthesisPrompt(): void
    {
        $calls = [];
        $answer = function (string $source, string $prompt) use (&$calls): string {
            $calls[] = $source;
            if ($source === 'bigpickle') {
                return self::planJson();
            }
            $this->assertStringContainsString('Outbound route', $prompt);
            return 'Route 4 still points at the old carrier; calls leave on the retired trunk.';
        };

        $result = GregDelegationService::run(
            'The sales queue is dropping calls. The trunk shows one-way audio on some calls. What should I check first?',
            'WORKING CONTEXT',
            self::duty(),
            self::other(),
            $answer
        );

        $this->assertTrue($result['used']);
        $this->assertSame(['bigpickle', 'system'], $calls, 'the plan is asked once, and only the colleague section is investigated');
        $this->assertCount(2, $result['sections']);
        $this->assertCount(2, $result['findings']);
        $this->assertStringContainsString('Both trunks registered at 09:12 UTC.', $result['prompt']);
        $this->assertStringContainsString('Route 4 still points at the old carrier', $result['prompt']);
        $this->assertStringContainsString('Trunk registration', $result['prompt']);
        $this->assertStringContainsString('Final answer:', $result['prompt']);
    }

    public function testRunFallsBackWhenTheColleagueFindsNothing(): void
    {
        $answer = function (string $source, string $prompt = ''): string {
            return $source === 'bigpickle' ? self::planJson() : '   ';
        };
        $result = GregDelegationService::run(
            'The sales queue is dropping calls. The trunk shows one-way audio on some calls. What should I check first?',
            'WORKING CONTEXT',
            self::duty(),
            self::other(),
            $answer
        );
        $this->assertFalse($result['used']);
        $this->assertSame('', $result['prompt']);
    }

    public function testRunFallsBackWhenThePlanIsBroken(): void
    {
        $answer = static fn(string $source, string $prompt = ''): string => 'I would check the trunk first.';
        $result = GregDelegationService::run(
            'Split this: check the trunk and the dialplan.',
            'WORKING CONTEXT',
            self::duty(),
            self::other(),
            $answer
        );
        $this->assertFalse($result['used']);
    }
}
