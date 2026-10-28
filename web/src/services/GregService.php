<?php
/**
 * AI → VoIP Engineer: "Greg", the entity that watches over the phone system.
 *
 * The administrator enables him on /ai-voip-engineer. From then on his
 * avatar sits top-right in every screen (logo top-left, Greg top-right),
 * carries the token counter, shows his notices in a scrolling panel, and
 * answers questions in /chat.
 *
 * He informs — concisely, and never nags:
 *   - notices are deduplicated (one per key per 6-hour window),
 *   - e-mail only for warn/critical, never inside quiet hours,
 *   - info notices stay in-app only.
 *
 * Model sources: "system" is the portal's own chain — AssistantModel's
 * local-runtime-then-cloud ladder, i.e. whatever LLM the system is set up
 * for. "ling" is OpenRouter's free Ling model (strict daily request
 * limits, shown next to the selector). "bigpickle" is the free model that
 * ships with the opencode CLI — no key and no account, and the default as
 * soon as Greg is enabled.
 *
 * Everything Greg does is tracked: settings and notices write the audit
 * log, and PBX changes must go through AssistantPlanService, whose
 * pbx_ai_changes rows the Dialplan editor already rolls back one click.
 */

require_once __DIR__ . '/ai/AssistantModel.php';
require_once __DIR__ . '/ai/EchoService.php';
require_once __DIR__ . '/CallQualityEvidenceService.php';
require_once __DIR__ . '/ai/ResearchService.php';
require_once __DIR__ . '/AsteriskDocsService.php';
require_once __DIR__ . '/ai/OpencodeService.php';
require_once __DIR__ . '/GregJournal.php';
require_once __DIR__ . '/GregBrief.php';
require_once __DIR__ . '/GregSystems.php';
require_once __DIR__ . '/GregOnboardingService.php';
require_once __DIR__ . '/GregHandoffService.php';
require_once __DIR__ . '/GregCouncilService.php';
require_once __DIR__ . '/GregDelegationService.php';
require_once __DIR__ . '/GregObservabilityService.php';
require_once __DIR__ . '/GregHealthService.php';
require_once __DIR__ . '/ai/CloudAiService.php';
require_once __DIR__ . '/tts/TtsProviders.php';
require_once __DIR__ . '/MailTemplateService.php';
require_once __DIR__ . '/../audit_log.php';

class GregService
{
    public const DEFAULT_NAME = 'Greg';
    public const DEFAULT_RELIEF = 'Mahir Gul';
    public const SOURCES = ['bigpickle', 'system', 'ling'];
    public const QUIET_DEFAULT = '22:00-07:00';
    public const LING_MODEL = 'inclusionai/ling-3.0-flash:free';
    private const DEDUPE_WINDOW = 21600;   // 6 h — one notice per key per window
    private const CHAT_HISTORY_MAX = 12;

    // ------------------------------------------------------------- settings

    /** Greg's stored configuration with defaults; disabled until enabled. */
    public static function settings(): array
    {
        $stored = trim((string) getSystemSetting('greg.name', ''));
        $name = self::canonicalName($stored !== '' ? $stored : self::DEFAULT_NAME);
        $source = (string) getSystemSetting('greg.model_source', 'bigpickle');
        $quiet = (string) getSystemSetting('greg.quiet_hours', self::QUIET_DEFAULT);
        $reliefStored = trim((string) getSystemSetting('greg.relief_name', ''));
        $reliefSource = (string) getSystemSetting('greg.relief_model_source', 'system');
        return [
            'enabled'      => getSystemSetting('greg.enabled', '0') === '1',
            'name'         => $name,
            'model_source' => in_array($source, self::SOURCES, true) ? $source : 'bigpickle',
            // The relief engineer covers quiet hours. He is his own engineer:
            // his own name and his own model source, so the night is answered
            // by a different provider (and, where that provider is configured
            // separately, a different key) when the administrator wants one.
            'relief_enabled' => getSystemSetting('greg.relief_enabled', '1') === '1',
            'relief_name'    => $reliefStored !== '' ? $reliefStored : self::DEFAULT_RELIEF,
            'relief_model_source' => in_array($reliefSource, self::SOURCES, true) ? $reliefSource : 'system',
            // The two engineers confer before an answer reaches a human. On by
            // default: a second pair of eyes is the point of having a relief
            // engineer, and an administrator can turn the extra model call off.
            'council'      => getSystemSetting('greg.council', '1') === '1',
            // A multi-part question can be split between the two engineers:
            // each investigates a section, and the engineer on duty writes
            // one answer from both sets of findings.
            'delegate'     => getSystemSetting('greg.delegate', '1') === '1',
            'show_avatar'  => getSystemSetting('greg.show_avatar', '1') === '1',
            'show_tokens'  => getSystemSetting('greg.show_tokens', '1') === '1',
            'notice_email' => getSystemSetting('greg.notice_email', '1') === '1',
            // One safe, reversible change: a lossy trunk moves to the back of
            // an outbound route for new calls. Off here means observe only.
            'auto_fix'     => getSystemSetting('greg.auto_fix', '1') === '1',
            'quiet_hours'  => preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $quiet) ? $quiet : self::QUIET_DEFAULT,
        ];
    }

    public static function enabled(): bool
    {
        return self::settings()['enabled'];
    }

    /** Avatar top-right: only when enabled AND the administrator shows it. */
    public static function avatarVisible(): bool
    {
        $s = self::settings();
        return $s['enabled'] && $s['show_avatar'];
    }

    /**
     * Persist the form (admin only, CSRF checked by the controller).
     * Validation mirrors the portal's own: a bad value is an honest error,
     * never a silent drop.
     */
    public static function save(array $post): array
    {
        $s = self::settings();
        $name = self::canonicalName((string) ($post['greg_name'] ?? $s['name']));
        if (!preg_match('/^[\p{L}\p{N} .\'_-]{1,30}$/u', $name)) {
            throw new CloudAiException(t('ai_voip_engineer.err_name'));
        }
        $quiet = trim((string) ($post['greg_quiet_hours'] ?? $s['quiet_hours']));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/', $quiet)) {
            throw new CloudAiException(t('ai_voip_engineer.err_quiet'));
        }
        $source = (string) ($post['greg_model_source'] ?? $s['model_source']);
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'bigpickle';
        }
        $relief = trim((string) ($post['greg_relief_name'] ?? $s['relief_name']));
        if (!preg_match('/^[\p{L}\p{N} .\'_-]{1,30}$/u', $relief)) {
            throw new CloudAiException(t('ai_voip_engineer.err_relief_name'));
        }
        $reliefSource = (string) ($post['greg_relief_model_source'] ?? $s['relief_model_source']);
        if (!in_array($reliefSource, self::SOURCES, true)) {
            $reliefSource = 'system';
        }
        $up = getDB()->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $values = [
            'greg.enabled'      => !empty($post['greg_enabled']) ? '1' : '0',
            'greg.name'         => $name,
            'greg.model_source' => $source,
            'greg.show_avatar'  => !empty($post['greg_show_avatar']) ? '1' : '0',
            'greg.show_tokens'  => !empty($post['greg_show_tokens']) ? '1' : '0',
            'greg.notice_email' => !empty($post['greg_notice_email']) ? '1' : '0',
            'greg.auto_fix'     => !empty($post['greg_auto_fix']) ? '1' : '0',
            'greg.quiet_hours'  => $quiet,
            'greg.relief_enabled' => !empty($post['greg_relief_enabled']) ? '1' : '0',
            'greg.relief_name'    => $relief,
            'greg.relief_model_source' => $reliefSource,
            'greg.council'        => !empty($post['greg_council']) ? '1' : '0',
            'greg.delegate'       => !empty($post['greg_delegate']) ? '1' : '0',
        ];
        // Turning the relief engineer off ends any handoff standing in his name.
        if ($values['greg.relief_enabled'] !== '1') {
            GregHandoffService::clear();
        }
        foreach ($values as $k => $v) {
            $up->execute([$k, $v]);
        }
        writeAuditLog('greg', 'settings', 'greg',
            $name . ' settings saved (' . ($values['greg.enabled'] === '1' ? 'active' : 'disabled') . ')',
            'update', $_SESSION['user_id'] ?? null);
        GregJournal::append('Greg settings saved', [
            'brain: ' . $source,
            'notifications: ' . ($values['greg.notice_email'] === '1' ? 'e-mail for warn/critical' : 'in-app only'),
            'council: ' . ($values['greg.council'] === '1' ? 'the two engineers confer before answering' : 'answers go out unreviewed'),
            'delegation: ' . ($values['greg.delegate'] === '1' ? 'multi-part questions can be split between the two engineers' : 'each question is answered by one engineer'),
            'state: ' . ($values['greg.enabled'] === '1' ? 'active' : 'disabled'),
        ]);
        return ['message' => t('ai_voip_engineer.msg_saved')];
    }

    // ------------------------------------------------------- tokens / chat

    /** This month's LLM tokens across cloud providers (the chip's number). */
    public static function monthTokens(): int
    {
        try {
            $sum = 0;
            foreach (CloudAiService::usageThisMonth() as $row) {
                $sum += (int) $row['llm_tokens'];
            }
            return $sum;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Who is on duty right now, and on which model source.
     *
     * Greg by day, the relief engineer during quiet hours — whoever it is, the
     * name, the source and the persona change together, because two engineers
     * on the same provider are one perspective, and the point of a handoff is
     * the second one.
     */
    public static function onDuty(): array
    {
        $s = self::settings();
        $relief = $s['relief_enabled'] && GregHandoffService::onDuty() === 'relief';
        return [
            'key' => $relief ? 'relief' : 'greg',
            'name' => $relief ? $s['relief_name'] : $s['name'],
            'source' => $relief ? $s['relief_model_source'] : $s['model_source'],
            'relief' => $relief,
        ];
    }

    /**
     * The other engineer — the one who reviews the answer before a human sees
     * it. Whoever is on duty, the council is made of the two of them.
     */
    public static function counterpart(array $duty): array
    {
        $s = self::settings();
        if (($duty['key'] ?? 'greg') === 'relief') {
            return [
                'key' => 'greg',
                'name' => $s['name'],
                'source' => $s['model_source'],
                'relief' => false,
            ];
        }
        return [
            'key' => 'relief',
            'name' => $s['relief_name'],
            'source' => $s['relief_model_source'],
            'relief' => true,
        ];
    }

    /** Ask one named model source, without changing who is on duty. */
    public static function askSource(string $source, string $prompt): string
    {
        return self::brain($source, $prompt);
    }

    /** Who he is, in one prompt the model reads first. */
    public static function persona(?array $duty = null): string
    {
        $duty = $duty ?? self::onDuty();
        if ($duty['relief']) {
            // A nowdoc, so apostrophes and line breaks reach the model exactly
            // as written; sprintf fills in both engineers' names.
            $identity = sprintf(<<<'TXT'
You are %s, the relief VoIP engineer of this AiPBX phone system, covering for %s while he sleeps. You have his handover — the onboarding brief, AGENTS.md and the captain's log — and the same twenty-plus years of production telephony behind you: carriers and SIP trunks, SBCs, NAT and SDP, RTP and one-way audio, codecs and transcoding, IVRs, call centres, and the money a dropped call costs. You also wear a purple hat: careful with the system's data like a blue team, curious about how it could be broken like a red team.

You are the night shift, not a copy of the day shift. A second engineer on the same system sees what the first one missed: use the handover, but trust your own reading of the evidence, and say plainly when it disagrees with what was written before you.
TXT, $duty['name'], self::settings()['name']);
            $identity .= "\n\nBefore you finish, leave the night written down: what you changed, what you saw, and what the morning should check.";
            return $identity . "\n\n" . self::workingRules();
        }
        // The name is filled in by sprintf; the rest of the identity is a
        // nowdoc so apostrophes and line breaks reach the model unchanged.
        return sprintf(self::identityText(), $duty['name']) . "\n\n" . self::workingRules();
    }

    private static function identityText(): string
    {
        return <<<'TXT'
You are %s (VoIPMonk), the assistant of this AiPBX phone system and its built-in VoIP Engineer — twenty-plus years of production telephony behind you: carriers and SIP trunks, SBCs, NAT and SDP, RTP and one-way audio, codecs and transcoding, IVRs, call centres, and the money a dropped call costs. You also wear a purple hat: careful with the system's data like a blue team, curious about how it could be broken like a red team.
TXT;
    }

    /** The judgement both engineers share — same standards, different eyes. */
    private static function workingRules(): string
    {
        return <<<'TXT'
How a veteran works, in this order:
- Evidence before theory. Read what the system already knows — health checks, CDRs, trunks, captured SIP, logs — and say what you actually see.
- Name the single most likely cause, then the runner-up. Not twenty maybes.
- Give the cheapest next step that separates them, and say what result would prove you wrong.
- Say plainly what you cannot know from where you sit: one-way audio you cannot hear, a carrier you cannot query.
- Judge by cost and risk: what is broken, how many calls, how much money, how urgent.
- A colleague on their first week, not a consultant: ask for the handover you were not given, keep what you learn in your own notes, and never make someone repeat what they already told you.

Rules: plain language, no jargon without a one-line explanation; short and concrete — a few sentences, not essays; answer in the language the user wrote in; never invent numbers about calls or extensions; if the answer is not in the conversation or in what you can read, say so instead of guessing; you inform and act, you do not nag.
TXT;
    }

    /**
     * One question from /chat. History lives in the session (12 messages);
     * the whole conversation is handed to AssistantModel::ask()'s layer
     * chain — local runtime first, cloud second — exactly like the
     * Dialplan editor's "Ask", so Greg runs on whatever LLM the system is
     * set up for.
     *
     * @return array{success: bool, message?: string, error?: string}
     */
    public static function chatSend(string $question): array
    {
        $q = trim($question);
        if ($q === '') {
            return ['success' => false, 'error' => t('greg.err_empty')];
        }
        $s = self::settings();
        if (!$s['enabled']) {
            return ['success' => false, 'error' => t('greg.err_disabled')];
        }
        // If it is quiet hours and the relief engineer has not been introduced
        // to this page yet, the handoff is written down before anyone speaks.
        GregHandoffService::ensure();
        $duty = self::onDuty();
        $hist = $_SESSION['greg_chat'] ?? [];
        $hist[] = ['role' => 'user', 'content' => $q];
        $hist = array_slice($hist, -self::CHAT_HISTORY_MAX);

        // A health check in progress owns the conversation: every message is
        // an answer to the question Greg just asked, and the state machine —
        // not the model — decides what happens next.
        $healthReply = GregHealthService::handle($q);
        if ($healthReply !== null) {
            $hist[] = ['role' => 'assistant', 'content' => $healthReply];
            $_SESSION['greg_chat'] = array_slice($hist, -self::CHAT_HISTORY_MAX);
            return ['success' => true, 'message' => $healthReply];
        }

        // His first week: without a handover document he asks the six questions
        // a new engineer would ask, one at a time, and writes the answers into
        // the brief. A real question mid-interview is answered normally.
        $onboardingReply = GregOnboardingService::handle($q);
        if ($onboardingReply !== null) {
            $hist[] = ['role' => 'assistant', 'content' => $onboardingReply];
            $_SESSION['greg_chat'] = array_slice($hist, -self::CHAT_HISTORY_MAX);
            return ['success' => true, 'message' => $onboardingReply];
        }

        // Greg's workshop: when the question is plainly "add a tool" (or
        // "research this"), opencode drafts the plan and it is stored for
        // review instead of being answered from memory. Nothing is installed.
        $workshop = self::workshopIntent($q);
        if ($workshop !== null) {
            try {
                $proposal = OpencodeService::propose($q, $workshop);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
            $reply = sprintf(t('greg.oc_drafted'), (string) $proposal['id'])
                . "\n\n" . $proposal['output'] . "\n\n" . t('greg.oc_review');
            $hist[] = ['role' => 'assistant', 'content' => $reply];
            $_SESSION['greg_chat'] = array_slice($hist, -self::CHAT_HISTORY_MAX);
            return ['success' => true, 'message' => $reply];
        }

        $prompt = self::promptFor($q, $hist);

        // A multi-part question can be split: the engineer on duty plans the
        // sections, the other engineer investigates their own, and the answer
        // is synthesized from both findings. Simple questions never pay for
        // this; a broken plan falls back to the ordinary single answer.
        $delegation = null;
        $reply = null;
        $other = self::counterpart($duty);
        if ($s['delegate'] && $s['relief_enabled']) {
            $delegation = GregDelegationService::run(
                $q,
                $prompt,
                $duty,
                $other,
                [self::class, 'askSource']
            );
        }
        try {
            if ($delegation !== null && $delegation['used']) {
                $reply = self::brain($duty['source'], $delegation['prompt']);
            }
            if ($reply === null || trim($reply) === '') {
                $reply = self::brain($duty['source'], $prompt);
            }
        } catch (\Throwable $e) {
            // The synthesis prompt is an optimization, not a new way to fail:
            // if only it failed, answer normally. If the normal answer fails
            // too, the human gets the real error.
            if ($delegation !== null && $delegation['used']) {
                try {
                    $reply = self::brain($duty['source'], $prompt);
                } catch (\Throwable $e2) {
                    return ['success' => false, 'error' => $e2->getMessage()];
                }
            } else {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Before the human hears it: the other engineer reads the draft, and a
        // disagreement is settled between the two of them. A reviewer that is
        // down costs a second opinion, never the answer itself.
        $council = null;
        if ($s['council'] && $s['relief_enabled']) {
            $council = GregCouncilService::deliberate(
                $q,
                $prompt,
                $reply,
                $duty,
                self::counterpart($duty),
                [self::class, 'askSource']
            );
            $reply = $council['text'];
            if ($council['reviewed'] && !$council['agreed']) {
                // Unsettled: worth a line in the audit log, never in the
                // answer. The human still hears one engineer.
                writeAuditLog('greg', 'council', 0,
                    'Council: ' . $council['duty'] . ' and ' . $council['reviewer']
                        . ' could not settle an answer',
                    'update', $_SESSION['user_id'] ?? null);
            } elseif ($council['reconciled']) {
                writeAuditLog('greg', 'council', 0,
                    'Council: ' . $council['duty'] . ' and ' . $council['reviewer']
                        . ' settled a disputed answer',
                    'update', $_SESSION['user_id'] ?? null);
            }
        }

        $hist[] = ['role' => 'assistant', 'content' => $reply];
        $_SESSION['greg_chat'] = array_slice($hist, -self::CHAT_HISTORY_MAX);
        $out = ['success' => true, 'message' => $reply];
        if ($delegation !== null && $delegation['used']) {
            $sections = array_map(static fn(array $section): array => [
                'title' => (string) $section['title'],
                'by' => (string) $section['owner_name'],
            ], $delegation['sections']);
            $out['delegation'] = [
                'used' => true,
                'sections' => $sections,
                'with' => $other['name'],
                // One quiet line, never part of the answer: the human can see
                // that two engineers shared the work and what each covered.
                'note' => sprintf(
                    t('greg.delegation_note'),
                    $duty['name'],
                    $other['name'],
                    count($delegation['sections'])
                ),
            ];
            writeAuditLog('greg', 'delegation', 0,
                'Delegation: ' . $duty['name'] . ' and ' . $other['name']
                    . ' shared ' . count($delegation['findings']) . ' section finding(s)',
                'update', $_SESSION['user_id'] ?? null);
        }
        if ($council !== null) {
            $out['council'] = [
                'reviewed' => $council['reviewed'],
                'agreed' => $council['agreed'],
                'reconciled' => $council['reconciled'],
                'with' => $council['reviewer'],
                // The human can see that both engineers stood behind the answer.
                // Quiet, one line, and never part of the spoken reply.
                'note' => !$council['reviewed'] ? '' : ($council['reconciled']
                    ? sprintf(t('greg.council_settled'), $council['duty'], $council['reviewer'])
                    : ($council['agreed']
                        ? sprintf(t('greg.council_agreed'), $council['duty'], $council['reviewer'])
                        : '')),
            ];
        }
        return $out;
    }

    /**
     * Does this question ask Greg to reach for opencode? Deliberately narrow:
     * an explicit "research:" prefix, an explicit "ask opencode", or an
     * add/install/propose + tool/plugin/MCP sentence. Ordinary questions keep
     * going to the model exactly as before.
     *
     * @return string|null 'tool' | 'research' | null
     */
    public static function workshopIntent(string $q): ?string
    {
        // Drafting a tool proposal is administrator work: a normal user's
        // question is answered by the model, never turned into a job.
        if (($_SESSION['user_role'] ?? '') !== 'admin') {
            return null;
        }
        if (getSystemSetting('greg.opencode_enabled', '1') !== '1') {
            return null;
        }
        $text = trim($q);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(research|araştır)\s*[:\-]/iu', $text)) {
            return 'research';
        }
        if (preg_match('/\b(ask|use|with|via)\s+opencode\b/i', $text)) {
            return 'research';
        }
        if (preg_match('/\b(add|install|propose|integrate|wire|set\s*up|hook\s*up)\b.{0,60}?\b(tool|plugin|mcp|integration|connector|skill)\b/is', $text)) {
            return 'tool';
        }
        return null;
    }

    /** @return array<int, array{role: string, content: string}> */
    public static function history(): array
    {
        return $_SESSION['greg_chat'] ?? [];
    }

    /**
     * Everything Greg reads before he answers: who he is, what he remembers,
     * the onboarding brief, and the conversation so far.
     *
     * Public so the assembly can be checked without a model: a test asserts
     * that the brief reaches the prompt and that the request for one appears
     * on the first answer of a conversation and never again.
     *
     * @param array<int, array{role: string, content: string}> $hist
     */
    public static function promptFor(string $q, array $hist): string
    {
        $duty = self::onDuty();
        $prompt = self::persona($duty) . "\n\n";
        // Greg's own memory: AGENTS.md and the latest captain's-log entries.
        // Empty on a fresh install, so the prompt is unchanged until he learns
        // something or an administrator writes it down.
        $journal = GregJournal::context();
        if ($journal !== '') {
            $prompt .= $journal . "\n\n";
        }
        // The handover document an administrator wrote: one markdown file about
        // the business. Missing, Greg asks for it once — on the first answer of
        // a conversation, never again — and then works without it.
        $firstAnswer = !in_array('assistant', array_column($hist, 'role'), true);
        $brief = GregBrief::context($firstAnswer);
        if ($brief !== '') {
            $prompt .= $brief . "\n\n";
        }
        // The other map: what the PBX is attached to and what it should watch.
        // The readiness card asks for it; once written, it is read every time.
        $systems = GregSystems::context();
        if ($systems !== '') {
            $prompt .= $systems . "\n\n";
        }
        // Upstream Asterisk knowledge, when the administrator downloaded it and
        // the question actually touches a topic the docs cover. Empty string
        // otherwise, so an empty cache or a greeting leaves the prompt as it was.
        $docs = AsteriskDocsService::contextFor($q);
        if ($docs !== '') {
            $prompt .= $docs . "\n\n";
        }
        if (OpencodeService::settings()['enabled']) {
            $prompt .= t('greg.oc_capability') . "\n\n";
        }
        // ResearchService: a URL in the question becomes quotable text with
        // its source named. Off by default; unchanged prompt when off.
        $research = ResearchService::contextFor($q);
        if ($research !== '') {
            $prompt .= $research . "\n\n";
        }
        // What Greg can actually see: which collectors, test data and local
        // RTCP exist. It also states the limits — a configured HOMER is not a
        // queried HOMER — so a missing source becomes a named next step, not
        // an invented ladder.
        $prompt .= GregObservabilityService::promptContext() . "\n\n";
        // Echo: this server's own counts plus Sipfront's runs, when the
        // administrator enabled it. Empty string when Echo is off, so Greg
        // behaves exactly as before.
        $echo = EchoService::promptContext();
        if ($echo !== '') {
            $prompt .= $echo . "\n\n";
        }
        // RTCP call quality: packet loss per call, what was done about it, and
        // the rule that he never claims to have heard audio. Empty when the
        // capture target is remote, so the prompt is unchanged there.
        $quality = CallQualityEvidenceService::promptContext();
        if ($quality !== '') {
            $prompt .= $quality . "\n\n";
        }
        foreach ($hist as $m) {
            $prompt .= ($m['role'] === 'user' ? 'User' : $duty['name']) . ': ' . $m['content'] . "\n";
        }
        return $prompt . $duty['name'] . ':';
    }

    /** The chosen source's answer; a refused source throws an honest error. */
    private static function brain(string $source, string $prompt): string
    {
        if ($source === 'bigpickle') {
            // The free model that ships with the opencode CLI: no key, no
            // account. Read-only and in a throwaway workspace, like everything
            // else opencode does here.
            return OpencodeService::chat($prompt);
        }
        if ($source === 'ling') {
            return self::lingChat($prompt);
        }
        $reply = AssistantModel::ask($prompt);
        if ($reply === null || trim($reply) === '') {
            throw new CloudAiException(t('greg.err_no_model'));
        }
        return trim($reply);
    }

    /** OpenRouter's free Ling model, when an OpenRouter account exists. */
    private static function lingChat(string $prompt): string
    {
        $key = (string) (CloudAiService::config('openrouter')['api_key'] ?? '');
        if ($key === '') {
            throw new CloudAiException(t('greg.err_ling_key'));
        }
        $r = TtsHttp::request('POST', 'https://openrouter.ai/api/v1/chat/completions',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key],
            (string) json_encode([
                'model' => self::LING_MODEL,
                'max_tokens' => 500,
                'temperature' => 0.3,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]), 45);
        $status = (int) $r['status'];
        $d = json_decode($r['body'], true);
        if ($status >= 400 || !is_array($d)) {
            // The provider's own short answer — no key, no request body echoed.
            throw new CloudAiException(t('greg.err_ling') . ' (' . $status . ')');
        }
        $text = $d['choices'][0]['message']['content'] ?? '';
        if (!is_string($text) || trim($text) === '') {
            throw new CloudAiException(t('greg.err_no_model'));
        }
        return trim($text);
    }

    // ------------------------------------------------------------- notices

    /**
     * One notice: in-app always (the avatar panel), e-mail for warn/critical
     * outside quiet hours when e-mail is on. Deduped per key for 6 h so a
     * repeating problem informs once — the "do not annoy" rule made code.
     *
     * @return array{id: int} | array{deduped: true} | array{skipped: string}
     */
    public static function notify(string $severity, string $title, string $body,
                                  string $dedupeKey = '', string $source = 'greg'): array
    {
        if (!self::enabled()) {
            return ['skipped' => 'disabled'];
        }
        if (!in_array($severity, ['info', 'warn', 'critical'], true)) {
            $severity = 'warn';
        }
        $dedupeKey = $dedupeKey !== '' ? substr($dedupeKey, 0, 64) : substr(sha1($severity . '|' . $title), 0, 64);
        $db = getDB();
        $st = $db->prepare(
            'SELECT id FROM pbx_greg_notices
             WHERE dedupe_key = ? AND created_at > (NOW() - INTERVAL ' . (int) self::DEDUPE_WINDOW . ' SECOND)
             LIMIT 1'
        );
        $st->execute([$dedupeKey]);
        if ($st->fetchColumn()) {
            return ['deduped' => true];
        }
        $ins = $db->prepare(
            'INSERT INTO pbx_greg_notices (created_at, severity, title, body, source, dedupe_key)
             VALUES (NOW(), ?, ?, ?, ?, ?)'
        );
        $ins->execute([$severity, mb_substr($title, 0, 150), $body, mb_substr($source, 0, 32), $dedupeKey]);
        $id = (int) $db->lastInsertId();
        writeAuditLog('greg', 'notice', $id, $title, 'create', null);

        $s = self::settings();
        if ($s['notice_email'] && in_array($severity, ['warn', 'critical'], true) && !self::quietHoursNow()) {
            if (self::emailNotice($severity, $title, $body)) {
                $db->prepare('UPDATE pbx_greg_notices SET emailed_at = NOW() WHERE id = ?')->execute([$id]);
            }
        }
        return ['id' => $id];
    }

    /** True inside the configured quiet window (may cross midnight). */
    public static function quietHoursNow(?int $ts = null): bool
    {
        $q = self::settings()['quiet_hours'];
        if (!preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $q, $m)) {
            return false;
        }
        $t = date('H:i', $ts ?? time());
        return $m[1] <= $m[2] ? ($t >= $m[1] && $t < $m[2]) : ($t >= $m[1] || $t < $m[2]);
    }

    /** @return array<int, array> newest notices for the top-bar panel */
    public static function recentNotices(int $limit = 12): array
    {
        $st = getDB()->prepare(
            'SELECT id, created_at, severity, title, body, read_at, emailed_at
             FROM pbx_greg_notices ORDER BY id DESC LIMIT ' . max(1, min(50, $limit))
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function unreadCount(): int
    {
        try {
            return (int) getDB()->query('SELECT COUNT(*) FROM pbx_greg_notices WHERE read_at IS NULL')->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** The configured display name (renamed on the Greg settings page). */
    public static function displayName(): string
    {
        return self::settings()['name'] ?: t('greg.default_name');
    }

    /**
     * Greg is Greg. "greg", "Greg" and "GREG" are the same entity, and the
     * interface always writes him with a capital G — how someone typed his
     * name in a settings row does not change who he is. A real rename (anything
     * that is not his name at all) is left exactly as the administrator typed
     * it.
     */
    public static function canonicalName(string $name): string
    {
        $name = trim($name);
        return mb_strtolower($name) === 'greg' ? self::DEFAULT_NAME : $name;
    }

    public static function markAllRead(): void
    {
        getDB()->exec('UPDATE pbx_greg_notices SET read_at = NOW() WHERE read_at IS NULL');
    }

    /**
     * One concise mail to every active admin, through the portal's own
     * health-alarm template (subject/body are already localized). A mail
     * failure must never break the operation — same contract as
     * writeAuditLog.
     */
    private static function emailNotice(string $severity, string $title, string $body): bool
    {
        try {
            $admins = getDB()->query(
                "SELECT id, email FROM sys_users
                 WHERE role = 'admin' AND email IS NOT NULL AND email != '' AND is_active = 1"
            )->fetchAll(PDO::FETCH_ASSOC);
            $name = self::settings()['name'];
            $ok = false;
            foreach ($admins as $a) {
                $lang = MailTemplateService::languageFor($a['email'], (int) $a['id']);
                $blocks = MailTemplateService::inLanguage($lang, fn() => [
                    'problems' => MailTemplateService::detailsBlock('', [
                        $name . ' — ' . t('ai_voip_engineer.sev_' . $severity) . ': ' . $title
                            => mb_substr($body, 0, 600),
                    ]),
                ]);
                $res = MailTemplateService::send('health_alarm', $a['email'], $lang, [
                    'count' => '1',
                    'checks' => $name,
                    'host' => gethostname() ?: 'aipbx',
                    'date' => date('d.m.Y H:i:s'),
                    'portal_link' => '/',
                ], $blocks);
                $ok = $ok || !empty($res['success']);
            }
            return $ok;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
