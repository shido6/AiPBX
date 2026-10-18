<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/GregHealthService.php';
require_once __DIR__ . '/../../src/services/HealthService.php';
require_once __DIR__ . '/../../src/services/SipProbeService.php';

/**
 * Greg asking the health-check questions and filling the form in for the user.
 *
 * The server answers most of a health check itself, so the interview asks only
 * what a machine cannot see. These tests pin the parts that must not drift:
 * the five questions and their order, the phrase that starts it in both
 * languages, that a bad answer re-asks instead of being guessed at, that a
 * skipped optional answer stays empty, that cancelling saves nothing, and that
 * the verdict is the worst signal rather than the last one.
 */
final class GregHealthServiceTest extends TestCase
{
    private array $session = [];
    private array $rows = [];

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? [];
        $_SESSION = ['user_id' => null, 'username' => 'tester', 'user_role' => 'admin'];
        GregHealthService::cancel();
        // Something is failing, so "health check" asks its questions. disk has
        // no safe fix, so no privileged action is even attempted.
        $this->seed(['disk' => 'fail'], true);
    }

    /**
     * Stored check results, as the cron run would have left them.
     *
     * @param array<string, string> $statuses key => status
     * @param bool $fillOthers set every other check to ok first
     */
    private function seed(array $statuses, bool $fillOthers = false): void
    {
        if ($fillOthers) {
            foreach (array_keys(HealthChecks::DEFAULTS) as $key) {
                $statuses[$key] = $statuses[$key] ?? 'ok';
            }
        }
        $stmt = getDB()->prepare('INSERT INTO health_checks (check_key, status, message, checked_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), message = VALUES(message), checked_at = NOW()');
        foreach ($statuses as $key => $status) {
            $stmt->execute([$key, $status, '']);
        }
    }

    protected function tearDown(): void
    {
        GregHealthService::cancel();
        foreach ($this->rows as $id) {
            try {
                getDB()->prepare('DELETE FROM greg_health_reports WHERE id = ?')->execute([$id]);
            } catch (\Throwable $e) {
                // the table is created by the migration; nothing to clean otherwise
            }
        }
        $_SESSION = $this->session;
    }

    private function state(): ?array
    {
        return $_SESSION[GregHealthService::SESSION_KEY] ?? null;
    }

    private function answer(string $text): string
    {
        return (string) GregHealthService::handle($text);
    }

    // ---------------------------------------------------------- the questions

    public function testFiveQuestionsInTheOrderAPersonWouldAsk(): void
    {
        $this->assertSame(['symptom', 'scope', 'since', 'audio', 'frequency'], array_keys(GregHealthService::questions()));
        foreach (GregHealthService::questions() as $key => $def) {
            $this->assertNotSame('', $def['q'], $key . ' asks something');
        }
        $this->assertTrue(GregHealthService::questions()['symptom']['required'], 'what is broken is required');
        $this->assertFalse(GregHealthService::questions()['scope']['required'], 'scope may be skipped');
    }

    public function testItStartsOnAHealthCheckPhraseInEnglishOrTurkish(): void
    {
        foreach (['run a health check', 'healthcheck please', 'check up on the PBX', 'sağlık kontrolü yapalım'] as $phrase) {
            $this->assertTrue(GregHealthService::looksLikeStart($phrase), $phrase);
        }
        foreach (['the carrier is down', 'calls fail at 14:00', 'what is a health check?'] as $other) {
            $this->assertFalse(GregHealthService::looksLikeStart($other), $other);
        }
    }

    public function testAnOrdinaryQuestionIsNotSwallowedWhileNoInterviewIsRunning(): void
    {
        $this->assertNull(GregHealthService::handle('how many extensions do we have?'));
        $this->assertFalse(GregHealthService::active(), 'nothing started');
    }

    // -------------------------------------------------------------- the flow

    public function testItAsksTheNextQuestionAfterEachAnswer(): void
    {
        $reply = $this->answer('health check');
        $this->assertStringContainsString(GregHealthService::questions()['symptom']['q'], $reply);
        $this->assertStringContainsString(sprintf(t('health.greg_progress'), 1, 5), $reply);
        $this->assertTrue(GregHealthService::active());

        $reply = $this->answer('incoming calls go straight to voicemail');
        $this->assertStringContainsString(GregHealthService::questions()['scope']['q'], $reply);
        $this->assertSame('incoming calls go straight to voicemail', $this->state()['answers']['symptom']);
        $this->assertSame(1, $this->state()['step']);
    }

    public function testABadAnswerIsReAskedAndTheStepDoesNotMove(): void
    {
        $this->answer('health check');
        $this->answer('   ');            // required, cannot be empty
        $this->answer('x');              // too short to be a symptom
        $reply = $this->answer('incoming calls fail');
        $this->assertSame(1, $this->state()['step']);

        // The audio question takes a numbered choice or its label, not prose.
        $this->answer('extensions 200-210');
        $this->answer('yesterday');
        $invalid = $this->answer('it is a bit crackly');
        $this->assertStringContainsString(t('health.greg_invalid'), $invalid);
        $this->assertStringContainsString(GregHealthService::questions()['audio']['q'], $invalid);
        $this->assertSame(3, $this->state()['step'], 'still on the audio question');

        $reply = $this->answer('2');
        $this->assertSame('oneway', $this->state()['answers']['audio']);
        $this->assertSame(4, $this->state()['step']);
        $this->assertNotSame('', $reply);
    }

    public function testAChoiceAcceptsItsLabelAsWellAsItsNumber(): void
    {
        $this->answer('health check');
        $this->answer('no audio');
        $this->answer('skip');
        $this->answer('skip');
        $this->assertSame('', $this->state()['answers']['scope'], 'skip leaves an optional answer empty');
        $this->answer(t('health.greg_audio_none'));
        $this->assertSame('none', $this->state()['answers']['audio']);
        $reply = $this->answer(t('health.greg_frequency_some'));
        $this->assertFalse(GregHealthService::active(), 'the label completes the interview');
        $this->assertStringContainsString(t('health.greg_frequency_some'), $reply, 'the form shows the chosen label');
        if (preg_match('/#(\d+)/', $reply, $m)) {
            $this->rows[] = (int) $m[1];
        }
    }

    public function testCancelStopsWithoutFinishingOrSaving(): void
    {
        $this->answer('health check');
        $this->answer('outgoing calls fail');
        $reply = $this->answer('cancel');
        $this->assertSame(t('health.greg_cancelled'), $reply);
        $this->assertFalse(GregHealthService::active());
    }

    public function testTurkishCancelAlsoWorks(): void
    {
        $this->answer('sağlık kontrolü');
        $this->assertStringContainsString(t('health.greg_cancelled'), $this->answer('iptal edelim'));
        $this->assertFalse(GregHealthService::active());
    }

    public function testAHealthySystemIsAnsweredWithoutASingleQuestion(): void
    {
        $this->seed([], true);   // every check ok
        $reply = $this->answer('health check');

        $this->assertFalse(GregHealthService::active(), 'nothing to ask');
        $this->assertStringContainsString(t('health.greg_healthy_no_questions'), $reply);
        $this->assertStringContainsString(t('health.greg_verdict_ok'), $reply);
        $this->assertStringNotContainsString(t('health.greg_q_symptom'), $reply, 'no questionnaire');
        $this->assertSame(1, preg_match('/#(\d+)/', $reply, $m), 'the run is still recorded');
        $this->rows[] = (int) $m[1];
    }

    public function testWarningsAreReportedWithoutQuestions(): void
    {
        $this->seed(['disk' => 'warn'], true);
        $reply = $this->answer('health check');

        $this->assertFalse(GregHealthService::active(), 'a warning is not an interrogation');
        $this->assertStringContainsString(t('health.greg_no_questions_warn'), $reply);
        $this->assertStringNotContainsString(t('health.greg_q_symptom'), $reply);
        if (preg_match('/#(\d+)/', $reply, $m)) {
            $this->rows[] = (int) $m[1];
        }
    }

    // ------------------------------------------------------------ the fixes

    public function testOnlyTheSafeFixesArePlanned(): void
    {
        $this->assertSame(['service', 'restart', 'aipbx-chat'], GregHealthService::plan('chat', 'fail')['args']);
        $this->assertSame(['ai', 'restart'], GregHealthService::plan('local_ai', 'fail')['args']);
        $this->assertSame(['service', 'reload', 'asterisk'], GregHealthService::plan('asterisk', 'fail')['args']);
        $this->assertSame(['service', 'reload', 'apache2'], GregHealthService::plan('web', 'fail')['args']);
        $this->assertSame(['service', 'restart', 'postfix'], GregHealthService::plan('mail', 'fail')['args'],
            'postfix down is a restart');
        $this->assertSame(['postfix', 'flush'], GregHealthService::plan('mail', 'warn')['args'],
            'a stuck queue is a flush');
        $this->assertNull(GregHealthService::plan('disk', 'fail'), 'a full disk is not Greg\'s to fix');
        $this->assertNull(GregHealthService::plan('backup', 'warn'), 'a missing backup needs a person');
        $this->assertNull(GregHealthService::plan('trunks', 'fail'), 'a carrier is not a local service');
        $this->assertNull(GregHealthService::plan('chat', 'ok'), 'nothing to do when the check is fine');
    }

    public function testThePrefaceSaysWhetherAFixWorkedAndAsksNothingWhenHealthy(): void
    {
        $fixes = [
            ['key' => 'chat', 'label' => 'restart the chat service', 'ok' => true, 'output' => '', 'after' => 'ok'],
            ['key' => 'local_ai', 'label' => 'restart the local AI service', 'ok' => true, 'output' => '', 'after' => 'fail'],
        ];
        $t = GregHealthService::preface(['ran' => true], $fixes, 'fail');
        $this->assertStringContainsString(t('health.greg_ran_now'), $t);
        $this->assertStringContainsString(t('health.greg_fix_worked'), $t);
        $this->assertStringContainsString(t('health.greg_fix_failed'), $t);
        $this->assertStringNotContainsString(t('health.greg_healthy_no_questions'), $t);

        $ok = GregHealthService::preface(['ran' => false], [], 'ok');
        $this->assertStringContainsString(t('health.greg_ran_stale'), $ok);
        $this->assertStringContainsString(t('health.greg_healthy_no_questions'), $ok);
    }

    public function testQuestionsAreOnlyForActualFailures(): void
    {
        $noTest = ['source' => 'none'];
        $this->assertFalse(GregHealthService::hasFailure(['a' => ['status' => 'ok'], 'b' => ['status' => 'warn']], $noTest),
            'a warning is reported, not interrogated');
        $this->assertTrue(GregHealthService::hasFailure(['a' => ['status' => 'fail']], $noTest));
        $this->assertTrue(GregHealthService::hasFailure([], ['source' => 'check', 'status' => 'fail']),
            'a failed call test counts');
        $this->assertFalse(GregHealthService::hasFailure([], ['source' => 'check', 'status' => 'warn']));
        $this->assertTrue(GregHealthService::hasFailure([], ['source' => 'sipfront', 'latest' => ['is_failed' => 1]]));
    }

    public function testCancellingAfterAFixSaysWhatWasAlreadyDone(): void
    {
        $_SESSION[GregHealthService::SESSION_KEY] = [
            'step' => 0, 'answers' => [],
            'fixes' => [['key' => 'chat', 'label' => 'restart the chat service', 'ok' => true, 'output' => '', 'after' => 'ok']],
        ];
        $reply = (string) GregHealthService::handle('cancel');
        $this->assertStringContainsString('restart the chat service', $reply);
        $this->assertStringNotContainsString(t('health.greg_cancelled'), $reply, 'not "nothing was saved"');
        $this->assertFalse(GregHealthService::active());
    }

    public function testASummaryWithNoAnswersSkipsTheFormAndSaysWhatWasDone(): void
    {
        $fixes = [['key' => 'chat', 'label' => 'restart the chat service', 'ok' => true, 'output' => '', 'after' => 'ok']];
        $out = GregHealthService::summary([], ['chat' => ['status' => 'ok', 'message' => '', 'checked_at' => null]],
            ['source' => 'none'], 'ok', true, $fixes);
        $this->assertStringNotContainsString(t('health.greg_answers_title'), $out, 'no empty form');
        $this->assertStringContainsString(t('health.greg_fixed_title'), $out);
        $this->assertStringContainsString(t('health.greg_fix_worked'), $out);
        $this->assertStringContainsString(t('health.greg_verdict_ok'), $out);
    }

    // ------------------------------------------------------------- the form

    public function testTheVerdictIsTheWorstSignalNotTheLast(): void
    {
        $ok = ['status' => 'ok'];
        $warn = ['status' => 'warn'];
        $fail = ['status' => 'fail'];
        $noTest = ['source' => 'none'];

        $this->assertSame('ok', GregHealthService::verdict(['a' => $ok, 'b' => $ok], $noTest, []));
        $this->assertSame('warn', GregHealthService::verdict(['a' => $ok, 'b' => $warn], $noTest, []));
        $this->assertSame('fail', GregHealthService::verdict(['a' => $warn, 'b' => $fail, 'c' => $ok], $noTest, []));
        $this->assertSame('warn', GregHealthService::verdict(['a' => $ok], $noTest, ['audio' => 'oneway']),
            'a person hearing one-way audio is never "healthy"');
        $this->assertSame('ok', GregHealthService::verdict(['a' => $ok], $noTest, ['audio' => 'both']));
        $this->assertSame('warn', GregHealthService::verdict(['a' => ['status' => 'unknown']], $noTest, []),
            'checks that never ran are not a pass');
    }

    public function testTheCallTestCanTurnAQuietServerIntoAFailureOrAWarning(): void
    {
        $this->assertSame('fail', GregHealthService::verdict([], ['source' => 'sipfront', 'latest' => ['is_failed' => 1]], []));
        $this->assertSame('warn', GregHealthService::verdict([], ['source' => 'sipfront', 'latest' => null, 'error' => null], []));
        $this->assertSame('fail', GregHealthService::verdict([], ['source' => 'check', 'status' => 'fail'], []));
        $this->assertSame('warn', GregHealthService::verdict([], ['source' => 'check', 'status' => 'warn'], []));
        $this->assertSame('ok', GregHealthService::verdict([], ['source' => 'check', 'status' => 'ok'], []));
    }

    public function testTheSummaryIsTheFilledFormWithTheServersOwnAnswers(): void
    {
        $checks = [
            'web' => ['status' => 'ok', 'message' => '', 'checked_at' => null],
            'trunks' => ['status' => 'warn', 'message' => 'trunk HOMER not registered', 'checked_at' => null],
            'disk' => ['status' => 'fail', 'message' => '/var is 96% full', 'checked_at' => null],
        ];
        $test = ['source' => 'check', 'status' => 'ok', 'message' => 'SIPp OPTIONS → 200; 2 SIP message(s), capture: pcap',
            'checked_at' => '2026-10-18 19:40:00', 'latest' => null, 'error' => null, 'runs' => []];
        $answers = ['symptom' => 'incoming calls fail', 'scope' => '', 'since' => '', 'audio' => 'oneway', 'frequency' => 'some'];

        $admin = GregHealthService::summary($answers, $checks, $test, 'fail', true);
        $this->assertStringContainsString('incoming calls fail', $admin);
        $this->assertStringContainsString(t('health.greg_not_answered'), $admin, 'skipped questions are shown, not hidden');
        $this->assertStringContainsString(t('health.greg_audio_oneway'), $admin, 'a choice is shown as its label');
        $this->assertStringContainsString('trunk HOMER not registered', $admin);
        $this->assertStringContainsString('/var is 96% full', $admin);
        $this->assertStringContainsString('capture: pcap', $admin);
        $this->assertStringContainsString(t('health.greg_verdict_fail'), $admin);

        $user = GregHealthService::summary($answers, $checks, $test, 'fail', false);
        $this->assertStringNotContainsString('/var is 96% full', $user, 'a non-admin does not see server detail');
        $this->assertStringContainsString(t('health.greg_nonadmin'), $user);
    }

    public function testNoCallTestIsSaidPlainlyRatherThanLeftBlank(): void
    {
        $out = GregHealthService::summary([], [], ['source' => 'none'], 'warn', true);
        $this->assertStringContainsString(t('health.greg_sipp_missing'), $out);
        $out = GregHealthService::summary([], [], ['source' => 'sipfront', 'error' => 'Sipfront returned 403', 'latest' => null],
            'warn', true);
        $this->assertStringContainsString('Sipfront returned 403', $out);
    }

    // ------------------------------------------------------- the saved report

    public function testACompletedInterviewIsSavedAndReadBackForTheHealthPage(): void
    {
        $this->answer('health check');
        $this->answer('incoming calls fail');
        $this->answer('200-210');
        $this->answer('skip');
        $this->answer('2');   // one way only
        $reply = $this->answer('2');  // only some calls

        $this->assertStringContainsString(t('health.greg_answers_title'), $reply);
        $this->assertMatchesRegularExpression('/greg_health_reports|Health check saved/', $reply);
        $this->assertFalse(GregHealthService::active(), 'the interview ends with the last answer');

        $this->assertSame(1, preg_match('/#(\d+)/', $reply, $m), 'the reply carries the report id');
        $id = (int) $m[1];
        $this->rows[] = $id;

        $row = getDB()->prepare('SELECT * FROM greg_health_reports WHERE id = ?');
        $row->execute([$id]);
        $saved = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($saved);
        $answers = json_decode((string) $saved['answers'], true);
        $this->assertSame('incoming calls fail', $answers['symptom']);
        $this->assertSame('oneway', $answers['audio']);
        $this->assertSame('some', $answers['frequency']);
        $this->assertSame('tester', $saved['username']);
        $this->assertContains($saved['verdict'], ['ok', 'warn', 'fail']);
        $this->assertNotSame('', (string) $saved['summary']);

        $latest = GregHealthService::reports(5);
        $this->assertSame($id, $latest[0]['id'], 'newest report first');

        // Greg's captain's log gets one readable line, with the actor once.
        require_once __DIR__ . '/../../src/services/GregJournal.php';
        $logged = GregJournal::recentText();
        $this->assertStringContainsString('Health check', $logged);
        $this->assertStringContainsString('verdict:', $logged);
        $this->assertStringNotContainsString('tester (tester)', $logged);
    }
}
