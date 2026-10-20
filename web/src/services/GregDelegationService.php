<?php
/**
 * Two engineers, one problem: the engineer on duty can split a question into
 * independent sections, hand some to the other engineer, and put what both
 * learned back into one answer.
 *
 * This is deliberately separate from GregCouncilService. The council reviews a
 * finished draft; delegation happens before the answer exists. The on-duty
 * engineer proposes the split, each engineer investigates only their own
 * section, and the on-duty engineer writes the final answer from both sets of
 * findings. The human still hears one engineer, in one voice.
 *
 * What keeps it safe and affordable:
 *   - a cheap gate: only multi-part or explicitly "split this" questions are
 *     considered, so an ordinary question costs no extra call;
 *   - at most three sections, each bounded, and at least one must go to the
 *     other engineer;
 *   - a malformed plan, a silent model or a failed section falls back to the
 *     normal single-engineer answer rather than breaking the chat;
 *   - no database table and no new path from the browser: the conversation is
 *     still GregService's, this service only builds prompts and reads replies.
 *
 * The model is never trusted with a section that was not validated: every
 * section has a bounded title, question and finding, and the owner is reduced
 * to "duty" or "other" before it is used.
 */

final class GregDelegationService
{
    /** More than this is a meeting, not a phone problem. */
    public const MAX_SECTIONS = 3;

    private const MAX_QUESTION = 2000;
    private const MAX_SECTION = 600;
    private const MAX_FINDING = 1200;
    private const MAX_CONTEXT = 14000;

    /**
     * Is this question worth the extra calls? A short single question is not.
     * The explicit words win even when the question is short, because the
     * administrator asked for the split by name.
     */
    public static function shouldDelegate(string $question): bool
    {
        $q = trim($question);
        if ($q === '') {
            return false;
        }
        if (preg_match('/\b(split|break\s+(this|it)\s+(in|into)|delegate|divide|böl|bölüştür|parçala|paylaş)\b/iu', $q)) {
            return true;
        }
        if (mb_strlen($q) < 100) {
            return false;
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($sentences) >= 2) {
            return true;
        }
        // Two question marks or a semicolon list is a multi-part question in
        // practice, even when it was typed as one sentence.
        return (bool) preg_match('/\?[^?]*\?/u', $q) || (bool) preg_match('/;\s*\S/u', $q);
    }

    /** Two named engineers with different model sources are a delegation pair. */
    public static function canDelegate(array $duty, array $other): bool
    {
        $dutyName = trim((string) ($duty['name'] ?? ''));
        $otherName = trim((string) ($other['name'] ?? ''));
        return $dutyName !== '' && $otherName !== ''
            && $dutyName !== $otherName
            && trim((string) ($duty['source'] ?? '')) !== ''
            && trim((string) ($other['source'] ?? '')) !== '';
    }

    /**
     * Plan, investigate and build the final synthesis prompt.
     *
     * @param callable(string,string):string $ask fn(source, prompt): reply
     * @return array{used:bool, sections:array<int,array>, findings:array<int,array>, prompt:string}
     */
    public static function run(
        string $question,
        string $context,
        array $duty,
        array $other,
        callable $ask
    ): array {
        $empty = ['used' => false, 'sections' => [], 'findings' => [], 'prompt' => ''];
        if (!self::canDelegate($duty, $other) || !self::shouldDelegate($question)) {
            return $empty;
        }

        $sections = self::plan($question, $context, $duty, $other, $ask);
        if (count($sections) < 2) {
            return $empty;
        }

        $findings = [];
        $otherFound = false;
        foreach ($sections as $i => $section) {
            $text = (string) $section['finding'];
            if ($section['owner'] === 'other') {
                try {
                    $text = trim((string) $ask((string) $other['source'], self::sectionPrompt(
                        $question, $context, $section, $duty, $other
                    )));
                } catch (\Throwable $e) {
                    // A failed section is a gap in the answer, never a failed
                    // chat: keep going and name what is unresolved.
                    $text = '';
                }
                if ($text !== '') {
                    $otherFound = true;
                }
            }
            $text = self::clip($text, self::MAX_FINDING);
            $sections[$i]['finding'] = $text;
            if ($text !== '') {
                $findings[] = [
                    'title' => (string) $section['title'],
                    'by' => (string) $section['owner_name'],
                    'text' => $text,
                ];
            }
        }

        // If the colleague produced nothing, there is no delegation to show:
        // fall back to the normal answer instead of pretending there was one.
        if (!$otherFound || !$findings) {
            return $empty;
        }

        return [
            'used' => true,
            'sections' => $sections,
            'findings' => $findings,
            'prompt' => self::synthesisPrompt($question, $context, $sections, $duty, $other),
        ];
    }

    /**
     * Ask the engineer on duty for a JSON split. Anything invalid means no
     * delegation, not a half-parsed plan.
     *
     * @return array<int, array{title:string,question:string,owner:string,owner_name:string,finding:string}>
     */
    public static function plan(
        string $question,
        string $context,
        array $duty,
        array $other,
        callable $ask
    ): array {
        if (!self::canDelegate($duty, $other)) {
            return [];
        }
        try {
            $raw = (string) $ask((string) $duty['source'], self::planPrompt($question, $context, $duty, $other));
        } catch (\Throwable $e) {
            return [];
        }
        return self::parsePlan($raw, $duty, $other);
    }

    /**
     * Read the split. Tolerates a code fence and a leading sentence, but a
     * malformed object, one section, or a plan with nobody else in it is
     * refused: the fallback is the ordinary answer.
     *
     * @return array<int, array{title:string,question:string,owner:string,owner_name:string,finding:string}>
     */
    public static function parsePlan(string $raw, array $duty, array $other): array
    {
        $text = trim($raw);
        if ($text === '') {
            return [];
        }
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $m)) {
            $text = trim($m[1]);
        }
        if (!preg_match('/\{.*\}/s', $text, $m)) {
            return [];
        }
        $data = json_decode($m[0], true);
        if (!is_array($data) || !is_array($data['sections'] ?? null)) {
            return [];
        }

        $otherName = trim((string) ($other['name'] ?? ''));
        $dutyName = trim((string) ($duty['name'] ?? ''));
        $out = [];
        $hasOther = false;
        $seen = [];
        foreach (array_slice($data['sections'], 0, self::MAX_SECTIONS) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $title = self::clean((string) ($s['title'] ?? ''), 80);
            $q = self::clean((string) ($s['question'] ?? ''), self::MAX_SECTION);
            if ($q === '') {
                $q = $title;
            }
            if ($q === '') {
                continue;
            }
            if (isset($seen[mb_strtolower($q)])) {
                continue;
            }
            $seen[mb_strtolower($q)] = true;

            $ownerRaw = mb_strtolower(trim((string) ($s['owner'] ?? 'duty')));
            $owner = 'duty';
            if ($ownerRaw === 'other' || $ownerRaw === 'relief'
                || ($otherName !== '' && str_contains($ownerRaw, mb_strtolower($otherName)))
                || ($dutyName !== '' && !str_contains($ownerRaw, mb_strtolower($dutyName))
                    && str_contains($ownerRaw, 'engineer'))) {
                $owner = 'other';
            }
            if ($owner === 'other') {
                $hasOther = true;
            }
            $out[] = [
                'title' => $title !== '' ? $title : self::clip($q, 80),
                'question' => $q,
                'owner' => $owner,
                'owner_name' => $owner === 'other' ? ($otherName !== '' ? $otherName : 'other engineer')
                    : ($dutyName !== '' ? $dutyName : 'on-duty engineer'),
                'finding' => self::clip(trim((string) ($s['finding'] ?? '')), self::MAX_FINDING),
            ];
        }

        if (count($out) < 2 || !$hasOther) {
            return [];
        }
        return $out;
    }

    // ------------------------------------------------------------- prompts

    private static function planPrompt(string $question, string $context, array $duty, array $other): string
    {
        return sprintf(<<<'TXT'
You are %1$s, the VoIP engineer on duty. %2$s is the other VoIP engineer on this AiPBX phone system. You two can split a problem, investigate different parts, and share what you learned.

Decide whether this question has independent parts that are worth investigating separately. If it does, split it into 2 or 3 sections and assign each to "duty" (you) or "other" (%2$s). At least one section must go to %2$s. For a section you keep, put a concise finding in "finding"; leave "finding" empty for the section %2$s will investigate.

Return JSON only:
{"sections":[{"title":"short section name","question":"the exact part to investigate","owner":"duty|other","finding":"your finding, or empty"}]}

If this is one question, return {"sections":[]}.
Do not answer the whole question here. Keep every section under 600 characters and every finding under 700 characters.

Question:
%3$s

Working context:
%4$s
TXT,
            trim((string) $duty['name']),
            trim((string) $other['name']),
            self::clip(trim($question), self::MAX_QUESTION),
            self::clip(trim($context), self::MAX_CONTEXT)
        );
    }

    private static function sectionPrompt(
        string $question,
        string $context,
        array $section,
        array $duty,
        array $other
    ): string {
        return sprintf(<<<'TXT'
You are %1$s, the other VoIP engineer on this AiPBX phone system. %2$s is working on the whole question and has asked you to investigate only this section. Do not answer the whole question and do not repeat the context.

Return only what you found for this section: the evidence you can see, the most likely cause, the cheapest next check, and what you could not verify. Keep it under 700 characters.

Whole question:
%3$s

Section: %4$s
%5$s

Working context:
%6$s

Your finding:
TXT,
            trim((string) $other['name']),
            trim((string) $duty['name']),
            self::clip(trim($question), self::MAX_QUESTION),
            (string) $section['title'],
            (string) $section['question'],
            self::clip(trim($context), self::MAX_CONTEXT)
        );
    }

    private static function synthesisPrompt(
        string $question,
        string $context,
        array $sections,
        array $duty,
        array $other
    ): string {
        $lines = [];
        foreach ($sections as $section) {
            $finding = trim((string) ($section['finding'] ?? ''));
            $lines[] = '- ' . $section['title'] . ' — ' . $section['owner_name'] . ': '
                . ($finding !== '' ? $finding : 'no finding; this part is unresolved.');
        }
        return sprintf(<<<'TXT'
You are %1$s, the VoIP engineer on duty. You and %2$s split this question into sections so you could work faster. Here is what each of you found:

%3$s

Write the final answer to the administrator now. Use both sets of findings, keep every fact that is supported, and say plainly where a section is unresolved or where the two of you disagree. Do not mention the split, the sections, or the other engineer; the administrator hears one engineer, in your voice. No greeting and no restating of the question.

Question:
%4$s

Working context:
%5$s

Final answer:
TXT,
            trim((string) $duty['name']),
            trim((string) $other['name']),
            implode("\n", $lines),
            self::clip(trim($question), self::MAX_QUESTION),
            self::clip(trim($context), self::MAX_CONTEXT)
        );
    }

    // ------------------------------------------------------------ internals

    /** Strip markup and collapse whitespace; model output is never trusted. */
    private static function clean(string $text, int $limit): string
    {
        $text = trim(strip_tags($text));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return self::clip($text, $limit);
    }

    private static function clip(string $text, int $limit): string
    {
        $text = trim($text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }
}
