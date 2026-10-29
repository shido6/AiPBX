<?php
/**
 * The two engineers confer before either of them speaks to a human.
 *
 * Greg and the relief engineer are two people on the same phones, not two
 * answers racing each other. When an administrator asks a question, the one on
 * duty drafts the answer and the other reads it first — the same way a night
 * engineer leaves a note and the day engineer checks it before touching
 * anything. Only when both are satisfied does the answer go out, in the voice
 * of the engineer on duty, so the human hears one engineer instead of two.
 *
 * The reviewer is given the draft, the question and the same working context
 * the draft was built from. It replies with JSON: agreement, or what is wrong
 * and the corrected answer. On a disagreement the engineer on duty gets one
 * more pass to settle it; if that pass fails, nothing is sent that the reviewer
 * called wrong — the reviewer's correction is used instead.
 *
 * Deliberately quiet and never fatal:
 *   - a second model that is unavailable, slow or cannot follow the format
 *     simply means the draft goes out unchanged (reviewed = false),
 *   - no relief engineer, or the same name on both sides, means no council,
 *   - every prompt and answer is bounded, so one long answer cannot eat the
 *     reviewer's whole context window.
 *
 * This service does not touch the database or the settings; GregService owns
 * both and passes the two engineers and a callable that asks a model source.
 */

class GregCouncilService
{
    private const MAX_DRAFT = 12000;
    private const MAX_CONTEXT = 16000;
    private const MAX_NOTES = 2000;
    private const MAX_ANSWER = 12000;

    /**
     * One answer, reviewed and (if needed) settled by the other engineer.
     *
     * @param string   $question the administrator's question, verbatim
     * @param string   $context  the working context the draft was built from
     * @param string   $draft    the on-duty engineer's draft
     * @param array    $duty     ['name' => ..., 'source' => ...]
     * @param array    $other    ['name' => ..., 'source' => ...]
     * @param callable $ask      fn(string $source, string $prompt): string
     *
     * @return array{
     *   text: string, duty: string, reviewer: string,
     *   reviewed: bool, agreed: bool, reconciled: bool,
     *   disagreement: string
     * }
     */
    public static function deliberate(
        string $question,
        string $context,
        string $draft,
        array $duty,
        array $other,
        callable $ask
    ): array {
        $base = [
            'text' => $draft,
            'duty' => (string) ($duty['name'] ?? ''),
            'reviewer' => (string) ($other['name'] ?? ''),
            'reviewed' => false,
            'agreed' => false,
            'reconciled' => false,
            'disagreement' => '',
        ];

        // One engineer is not a council. No answer to review, no reviewer, or
        // the same person on both sides of the table: the draft stands.
        if ($draft === '' || $base['duty'] === '' || $base['reviewer'] === ''
            || $base['duty'] === $base['reviewer']
            || !isset($other['source']) || (string) $other['source'] === '') {
            return $base;
        }

        try {
            $raw = $ask((string) $other['source'], self::reviewPrompt(
                $question, $context, $draft, $base['duty'], $base['reviewer']
            ));
        } catch (\Throwable $e) {
            // The reviewer is a second opinion, never a single point of
            // failure: the administrator still gets the answer.
            return $base;
        }

        $verdict = self::parseReview((string) $raw);
        if ($verdict === null) {
            // A reviewer that ignored the format has not reviewed anything we
            // can trust; do not let a parsing accident rewrite the answer.
            return $base;
        }

        $base['reviewed'] = true;
        if ($verdict['agree']) {
            $base['agreed'] = true;
            return $base;
        }

        $base['disagreement'] = $verdict['notes'];

        // They disagree. The engineer on duty gets the last word, because the
        // answer must sound like one engineer — not like a committee.
        try {
            $settled = trim($ask((string) $duty['source'], self::revisionPrompt(
                $question, $context, $draft, $verdict['notes'], $verdict['answer'],
                $base['duty'], $base['reviewer']
            )));
            if ($settled !== '') {
                $base['text'] = $settled;
                $base['agreed'] = true;
                $base['reconciled'] = true;
                return $base;
            }
        } catch (\Throwable $e) {
            // Fall through to the reviewer's correction below.
        }

        if ($verdict['answer'] !== '') {
            // The reviewer wrote the corrected answer; it is safer than a draft
            // the reviewer explicitly called wrong.
            $base['text'] = $verdict['answer'];
        }
        return $base;
    }

    /**
     * Read the reviewer's JSON (tolerating a code fence or a leading word),
     * or a plain AGREE / DISAGREE line.
     *
     * @return array{agree: bool, notes: string, answer: string}|null
     */
    public static function parseReview(string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }
        // Models sometimes wrap the object in ```json … ```.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $m)) {
            $text = trim($m[1]);
        }
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data) && array_key_exists('agree', $data)) {
                $flag = $data['agree'];
                $agree = $flag === true || $flag === 1
                    || (is_string($flag) && in_array(strtolower(trim($flag)), ['true', 'yes', 'agree'], true));
                $notes = self::clip((string) ($data['notes'] ?? ''), self::MAX_NOTES);
                $answer = self::clip((string) ($data['answer'] ?? ''), self::MAX_ANSWER);
                return ['agree' => $agree, 'notes' => $notes, 'answer' => $answer];
            }
        }
        if (preg_match('/^\s*AGREE\b/i', $text)) {
            return ['agree' => true, 'notes' => '', 'answer' => ''];
        }
        if (preg_match('/^\s*DISAGREE\b[:\-\x{2013}]?\s*(.*)$/isu', $text, $m)) {
            return ['agree' => false, 'notes' => self::clip(trim($m[1]), self::MAX_NOTES), 'answer' => ''];
        }
        return null;
    }

    // ------------------------------------------------------------ internals

    private static function reviewPrompt(
        string $question,
        string $context,
        string $draft,
        string $duty,
        string $reviewer
    ): string {
        return sprintf(<<<'TXT'
You are %1$s, the other VoIP engineer on this AiPBX phone system. %2$s is on duty and has drafted the answer below. Before it reaches a human, the two of you settle it between yourselves: the administrator must hear one answer, not two engineers thinking aloud.

Check %2$s's draft against the working context: is every claim supported by it, is anything important missing, and is any suggested step risky, destructive or unverified? Do not restyle the prose and do not answer the question separately.

Question:
%3$s

Working context %2$s used:
%4$s

%2$s's draft:
%5$s

Reply with JSON only.
When the draft can go out as written:
{"agree": true, "notes": "one or two sentences on why it is sound"}
When it cannot:
{"agree": false, "notes": "what is wrong or missing", "answer": "the corrected answer in %2$s's voice"}
TXT,
            $reviewer,
            $duty,
            trim($question),
            self::clip($context, self::MAX_CONTEXT),
            self::clip($draft, self::MAX_DRAFT)
        );
    }

    private static function revisionPrompt(
        string $question,
        string $context,
        string $draft,
        string $notes,
        string $correction,
        string $duty,
        string $reviewer
    ): string {
        $review = $notes !== '' ? $notes : 'The draft is not accurate or safe as written.';
        if ($correction !== '') {
            $review .= "\n\n" . $reviewer . '\'s corrected version:' . "\n" . $correction;
        }
        return sprintf(<<<'TXT'
You are %1$s, the VoIP engineer on duty. You drafted the answer below; %2$s, the other engineer, read it before it went out and did not agree:

%3$s

Settle it between you and write the final answer to the administrator. Keep every fact the draft got right, incorporate what %2$s is right about, and drop what neither of you can support. Do not mention the review, the reviewer, or that two engineers conferred. No greeting, no restating the question.

Question:
%4$s

Working context:
%5$s

Your draft:
%6$s

Final answer:
TXT,
            $duty,
            $reviewer,
            $review,
            trim($question),
            self::clip($context, self::MAX_CONTEXT),
            self::clip($draft, self::MAX_DRAFT)
        );
    }

    private static function clip(string $text, int $limit): string
    {
        $text = trim($text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }
}
