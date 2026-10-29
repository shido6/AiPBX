<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregReadinessService.php';

/**
 * The temperature bar: the model is already configured, so the score is about
 * what it has been told — the business brief, the systems map, and Greg's own
 * notes. Local, cheap, and honest about thin documents.
 */
final class GregReadinessServiceTest extends TestCase
{
    private array $session = [];

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? [];
        $_SESSION = ['user_id' => 1, 'username' => 'tester', 'user_role' => 'admin'];
        @unlink(GregBrief::path());
        @unlink(GregSystems::path());
        @unlink(GregJournal::agentsPath());
    }

    protected function tearDown(): void
    {
        @unlink(GregBrief::path());
        @unlink(GregSystems::path());
        @unlink(GregJournal::agentsPath());
        $_SESSION = $this->session;
    }

    private function parts(array $report): array
    {
        return array_column($report['parts'], 'points', 'key');
    }

    public function testNothingProvidedIsColdAndNamesEveryGap(): void
    {
        $r = GregReadinessService::report();
        $this->assertSame(0, $r['score']);
        $this->assertSame('cold', $r['temperature']);
        $this->assertSame(['business', 'systems', 'notes'], array_column($r['parts'], 'key'));
        $this->assertCount(3, $r['missing']);
        $this->assertSame([0, 0, 0], array_values($this->parts($r)));
    }

    public function testASubstantialBriefIsTheWholeBusinessShare(): void
    {
        GregBrief::save("# Acme Dental\n\n" . str_repeat('The clinic answers about forty calls a day. ', 20));
        $r = GregReadinessService::report();
        $this->assertSame(50, $this->parts($r)['business']);
        $this->assertSame(50, $r['score']);
        $this->assertSame('cool', $r['temperature']);
        $this->assertNotContains(t('ai_voip_engineer.ready_doc_business'), $r['missing']);
    }

    public function testAThinBriefCountsLessThanASubstantialOne(): void
    {
        GregBrief::save("# Acme Dental\n\nWe do dental.\n");
        $this->assertSame(30, $this->parts(GregReadinessService::report())['business']);
    }

    public function testOnboardingAnswersCountTowardTheBusinessShare(): void
    {
        GregBrief::mergeSection('onboarding', 'Onboarding conversation',
            "### business — The business\nA dental clinic.\n\n"
            . "### hours — Hours and the line that matters\n8 to 5, emergency line.");
        $r = GregReadinessService::report();
        $this->assertSame(17, $this->parts($r)['business'], '2 of 6 answers is a third of the share');
        $this->assertSame(sprintf(t('ai_voip_engineer.ready_business_answers'), 2),
            $r['parts'][0]['detail']);
    }

    public function testTheSystemsMapIsTheSecondLargestShare(): void
    {
        GregSystems::save("# Systems\n\n## 1. What this PBX is for\nPhones.\n\n"
            . "## 2. Carriers and trunks\nBell.\n\n## 3. Connected systems\nDentrix.\n");
        $r = GregReadinessService::report();
        $this->assertSame(35, $this->parts($r)['systems']);
        $this->assertSame(sprintf(t('ai_voip_engineer.ready_sections'), 3), $r['parts'][1]['detail']);
        $this->assertNotContains(t('ai_voip_engineer.ready_doc_systems'), $r['missing']);
    }

    public function testEverythingTogetherIsHotAndOneHundred(): void
    {
        GregBrief::save("# Acme Dental\n\n" . str_repeat('Forty calls a day, one emergency line. ', 20));
        GregSystems::save("# Systems\n\n## 1. What this PBX is for\nPhones.\n\n"
            . "## 2. Carriers and trunks\nBell.\n\n## 3. Connected systems\nDentrix.\n");
        GregJournal::saveAgents("# House rules\n\nNever touch the main trunk before 9.\n");

        $r = GregReadinessService::report();
        $this->assertSame(100, $r['score']);
        $this->assertSame('hot', $r['temperature']);
        $this->assertSame([], $r['missing']);
        foreach ($r['parts'] as $part) {
            $this->assertTrue($part['done'], $part['key']);
        }
    }

    public function testTheTemperatureBandsAreStable(): void
    {
        $this->assertSame('cold', GregReadinessService::temperature(0));
        $this->assertSame('cold', GregReadinessService::temperature(39));
        $this->assertSame('cool', GregReadinessService::temperature(40));
        $this->assertSame('warm', GregReadinessService::temperature(70));
        $this->assertSame('hot', GregReadinessService::temperature(90));
        $this->assertSame('hot', GregReadinessService::temperature(100));
    }
}
