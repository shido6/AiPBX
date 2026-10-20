<?php
/**
 * How ready is Greg, really?
 *
 * The model is not the bottleneck — it is already there, chosen when the
 * entity was set up. The bottleneck is what it has been told. A new engineer
 * with two good maps (what the business is, what the system is attached to)
 * beats a better model with neither, so this turns the handover into a number
 * the administrator can act on: 0–100, shown as a temperature.
 *
 * The score is deliberately local and cheap — file reads, no model call, no
 * database write on a page view. Greg reads the same documents with the model
 * when he answers; this only says whether they exist and how much of the
 * template they cover.
 *
 *   50  business brief (6 onboarding answers, or a substantial file)
 *   35  systems map (sections of the template, or a substantial file)
 *   15  Greg's own notes (AGENTS.md)
 */

require_once __DIR__ . '/GregBrief.php';
require_once __DIR__ . '/GregSystems.php';
require_once __DIR__ . '/GregOnboardingService.php';
require_once __DIR__ . '/GregJournal.php';

final class GregReadinessService
{
    public const BUSINESS_MAX = 50;
    public const SYSTEMS_MAX = 35;
    public const NOTES_MAX = 15;

    public static function report(): array
    {
        [$business, $businessDetail] = self::business();
        [$systems, $systemsDetail] = self::systems();
        $notes = trim(GregJournal::agents()) !== '';
        $notesPoints = $notes ? self::NOTES_MAX : 0;

        $score = min(100, $business + $systems + $notesPoints);
        $missing = [];
        if ($business < self::BUSINESS_MAX) {
            $missing[] = t('ai_voip_engineer.ready_doc_business');
        }
        if ($systems < self::SYSTEMS_MAX) {
            $missing[] = t('ai_voip_engineer.ready_doc_systems');
        }
        if (!$notes) {
            $missing[] = t('ai_voip_engineer.ready_doc_notes');
        }

        return [
            'score' => $score,
            'temperature' => self::temperature($score),
            'missing' => $missing,
            'parts' => [
                [
                    'key' => 'business',
                    'label' => t('ai_voip_engineer.ready_business'),
                    'points' => $business,
                    'max' => self::BUSINESS_MAX,
                    'detail' => $businessDetail,
                    'done' => $business >= self::BUSINESS_MAX,
                ],
                [
                    'key' => 'systems',
                    'label' => t('ai_voip_engineer.ready_systems'),
                    'points' => $systems,
                    'max' => self::SYSTEMS_MAX,
                    'detail' => $systemsDetail,
                    'done' => $systems >= self::SYSTEMS_MAX,
                ],
                [
                    'key' => 'notes',
                    'label' => t('ai_voip_engineer.ready_notes'),
                    'points' => $notesPoints,
                    'max' => self::NOTES_MAX,
                    'detail' => $notes ? t('ai_voip_engineer.ready_present') : t('ai_voip_engineer.ready_missing'),
                    'done' => $notes,
                ],
            ],
        ];
    }

    /** cold | cool | warm | hot */
    public static function temperature(int $score): string
    {
        if ($score >= 90) {
            return 'hot';
        }
        if ($score >= 70) {
            return 'warm';
        }
        if ($score >= 40) {
            return 'cool';
        }
        return 'cold';
    }

    /** @return array{0: int, 1: string} points, detail */
    private static function business(): array
    {
        $answered = count(GregOnboardingService::saved());
        if ($answered > 0) {
            $answered = min(6, $answered);
            return [
                (int) round(self::BUSINESS_MAX * $answered / 6),
                sprintf(t('ai_voip_engineer.ready_business_answers'), $answered),
            ];
        }
        if (!GregBrief::exists()) {
            return [0, t('ai_voip_engineer.ready_missing')];
        }
        $chars = mb_strlen(trim(GregBrief::text()));
        // A one-line "we do dental" is not a handover; it is a start.
        return $chars >= 400
            ? [self::BUSINESS_MAX, t('ai_voip_engineer.ready_provided')]
            : [30, t('ai_voip_engineer.ready_short')];
    }

    /** @return array{0: int, 1: string} points, detail */
    private static function systems(): array
    {
        if (!GregSystems::exists()) {
            return [0, t('ai_voip_engineer.ready_missing')];
        }
        $chars = mb_strlen(trim(GregSystems::text()));
        $sections = GregSystems::sections();
        if ($sections >= 3) {
            return [self::SYSTEMS_MAX, sprintf(t('ai_voip_engineer.ready_sections'), $sections)];
        }
        if ($chars >= 300) {
            return [22, sprintf(t('ai_voip_engineer.ready_sections'), max(1, $sections))];
        }
        return [12, t('ai_voip_engineer.ready_short')];
    }
}
