<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregOnboardingService.php';

/**
 * Greg's first week: the six questions a new engineer asks, one at a time,
 * and the answers written into the same onboarding brief he would be handed.
 *
 * These tests pin what must not drift: the order of the questions, that the
 * file is written as the conversation goes rather than at the end, that a real
 * question mid-interview is answered normally, that "later" keeps what was
 * written, that a second run asks only what is missing, and that a non-admin
 * gets none of it.
 */
final class GregOnboardingServiceTest extends TestCase
{
    private PDO $db;
    private array $session = [];

    private const ANSWERS = [
        'We run a dental clinic in Moncton, about forty calls a day.',
        'Monday to Friday, 8 to 5. The emergency line matters most.',
        'The after-hours emergency line must always reach the on-call dentist.',
        'Bell for the main line, Twilio for the SMS reminders. Bell owns the account.',
        'Ana at extension 101 for anything urgent; she approves changes. Wake her at 3 a.m.',
        'Answer rate above 95 percent, and the queue at lunch keeps overflowing.',
    ];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->session = $_SESSION ?? [];
        $_SESSION = ['user_id' => 1, 'username' => 'tester', 'user_role' => 'admin'];
        GregOnboardingService::cancel();
        @unlink(GregBrief::path());
        $this->db->exec("DELETE FROM sys_audit_log WHERE entity_type IN ('brief', 'onboarding')");
    }

    protected function tearDown(): void
    {
        GregOnboardingService::cancel();
        @unlink(GregBrief::path());
        $_SESSION = $this->session;
        $this->db->exec("DELETE FROM sys_audit_log WHERE entity_type IN ('brief', 'onboarding')");
    }

    private function answerAll(): void
    {
        GregOnboardingService::start();
        foreach (self::ANSWERS as $answer) {
            GregOnboardingService::handle($answer);
        }
    }

    private function counter(int $step, int $total): string
    {
        return sprintf(t('ai_voip_engineer.onb_count'), $step, $total);
    }

    // ------------------------------------------------------------- the flow

    public function testHeAsksOneQuestionAtATimeAndWritesEachAnswer(): void
    {
        $opening = GregOnboardingService::start();
        $this->assertTrue(GregOnboardingService::active());
        $this->assertStringContainsString($this->counter(1, 6), $opening);
        $this->assertStringContainsString(t('ai_voip_engineer.onb_q_business'), $opening);
        $this->assertStringNotContainsString(t('ai_voip_engineer.onb_q_hours'), $opening,
            'a new engineer asks one thing at a time');
        $this->assertFalse(GregBrief::exists(), 'nothing is written before the first answer');

        $reply = GregOnboardingService::handle(self::ANSWERS[0]);
        $this->assertStringContainsString($this->counter(2, 6), $reply);
        $this->assertStringContainsString(t('ai_voip_engineer.onb_q_hours'), $reply);
        $this->assertTrue(GregBrief::exists(), 'the answer is on disk before the conversation ends');
        $this->assertStringContainsString('### business —', GregBrief::text());
        $this->assertSame(self::ANSWERS[0], GregOnboardingService::saved()['business']);
        $this->assertSame(1, GregOnboardingService::status()['answered']);
        $this->assertSame(2, GregOnboardingService::status()['step']);
    }

    public function testTheLastAnswerFinishesTheBriefAsSections(): void
    {
        GregOnboardingService::start();
        $reply = '';
        foreach (self::ANSWERS as $answer) {
            $reply = (string) GregOnboardingService::handle($answer);
        }

        $this->assertFalse(GregOnboardingService::active());
        $this->assertStringContainsString(sprintf(t('ai_voip_engineer.onb_done'), 6, 6), $reply);

        $text = GregBrief::text();
        foreach (array_keys(GregOnboardingService::questions()) as $key) {
            $this->assertStringContainsString('### ' . $key . ' —', $text, $key);
        }
        $this->assertStringContainsString('on-call dentist', $text);
        $this->assertStringContainsString('Wake her at 3 a.m.', $text);
        $this->assertSame(6, count(GregOnboardingService::saved()));
        $this->assertSame(6, GregOnboardingService::status()['answered']);

        // Only two audit rows: the conversation starts and it finishes. What
        // was said is in the brief, not in the log.
        $actions = $this->db->query("SELECT action FROM sys_audit_log WHERE entity_type = 'onboarding' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['create', 'update'], $actions);
    }

    public function testARealQuestionMidInterviewIsAnsweredNormallyAndTheInterviewWaits(): void
    {
        GregOnboardingService::start();
        $this->assertNull(GregOnboardingService::handle('Why is the trunk down?'));
        $this->assertNull(GregOnboardingService::handle('what does a trunk even mean'));
        $this->assertTrue(GregOnboardingService::active());
        $this->assertSame(1, GregOnboardingService::status()['step'], 'the question is still the first one');

        $reply = (string) GregOnboardingService::handle(self::ANSWERS[0]);
        $this->assertStringContainsString(t('ai_voip_engineer.onb_q_hours'), $reply);
    }

    public function testLaterStopsAndKeepsWhatWasWritten(): void
    {
        GregOnboardingService::start();
        GregOnboardingService::handle(self::ANSWERS[0]);

        $reply = (string) GregOnboardingService::handle('later');
        $this->assertStringContainsString(sprintf(t('ai_voip_engineer.onb_paused_saved'), 1), $reply);
        $this->assertFalse(GregOnboardingService::active());
        $this->assertArrayHasKey('business', GregOnboardingService::saved());
        $this->assertStringContainsString('dental clinic', GregBrief::text());
    }

    public function testSkipMovesOnWithoutWritingAnAnswer(): void
    {
        GregOnboardingService::start();
        $reply = (string) GregOnboardingService::handle('skip');
        $this->assertStringContainsString(t('ai_voip_engineer.onb_q_hours'), $reply);
        $this->assertFalse(GregBrief::exists(), 'a skipped question writes nothing');
        $this->assertTrue(GregOnboardingService::active());
        $this->assertSame(0, GregOnboardingService::status()['answered']);
    }

    public function testASecondRunAsksOnlyWhatIsMissing(): void
    {
        GregOnboardingService::start();
        GregOnboardingService::handle(self::ANSWERS[0]);
        GregOnboardingService::handle(self::ANSWERS[1]);
        GregOnboardingService::handle('later');

        $reply = GregOnboardingService::start();
        $this->assertStringContainsString($this->counter(1, 4), $reply, 'four questions are still blank');
        $this->assertStringContainsString(t('ai_voip_engineer.onb_q_flows'), $reply);
        $this->assertStringNotContainsString(t('ai_voip_engineer.onb_q_business'), $reply);
        $this->assertStringNotContainsString(t('ai_voip_engineer.onb_q_hours'), $reply);
    }

    public function testWhenEverythingIsAnsweredHeSaysSoInsteadOfAskingAgain(): void
    {
        $this->answerAll();
        $reply = GregOnboardingService::start();
        $this->assertSame(t('ai_voip_engineer.onb_already'), $reply);
        $this->assertFalse(GregOnboardingService::active());
    }

    public function testTheBriefIsStillHisToEditAndTheSectionIsReplacedNotDuplicated(): void
    {
        GregBrief::save("# Handover\n\nWritten by hand.\n");
        GregOnboardingService::start();
        GregOnboardingService::handle(self::ANSWERS[0]);
        GregOnboardingService::handle('later');

        $text = GregBrief::text();
        $this->assertStringContainsString('Written by hand.', $text);
        $this->assertSame(1, substr_count($text, '<!-- greg:onboarding -->'));

        GregOnboardingService::start();
        GregOnboardingService::handle(self::ANSWERS[1]);
        GregOnboardingService::handle('later');

        $text = GregBrief::text();
        $this->assertSame(1, substr_count($text, '<!-- greg:onboarding -->'), 'no second copy of the block');
        $this->assertSame(1, substr_count($text, 'dental clinic'));
        $this->assertStringContainsString('8 to 5', $text);
        $this->assertStringContainsString('Written by hand.', $text);
    }

    public function testOnlyAnAdministratorIsOnboarded(): void
    {
        $_SESSION['user_role'] = 'user';
        $this->assertSame(t('ai_voip_engineer.onb_admin_only'), GregOnboardingService::handle('onboard me'));
        $this->assertFalse(GregOnboardingService::active());
        $this->assertNull(GregOnboardingService::handle('We run a clinic.'), 'ordinary chat reaches the model');
    }

    public function testAnAnswerIsBoundedRatherThanUnbounded(): void
    {
        GregOnboardingService::start();
        GregOnboardingService::handle(str_repeat('a', 2000));
        $this->assertSame(GregOnboardingService::MAX_ANSWER, mb_strlen(GregOnboardingService::saved()['business']));
    }

    public function testItStartsOnThePhraseAndNotOnAQuestionAboutIt(): void
    {
        $this->assertTrue(GregOnboardingService::looksLikeStart('onboard me'));
        $this->assertTrue(GregOnboardingService::looksLikeStart('Start onboarding'));
        $this->assertFalse(GregOnboardingService::looksLikeStart('What is the onboarding brief?'));
        $this->assertFalse(GregOnboardingService::looksLikeStart('Why does onboarding matter?'));
    }
}
