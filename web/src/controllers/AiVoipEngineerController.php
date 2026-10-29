<?php
require_once __DIR__ . '/../services/GregService.php';
require_once __DIR__ . '/../services/GregJournal.php';
require_once __DIR__ . '/../services/AnamService.php';
require_once __DIR__ . '/../services/GregBrief.php';
require_once __DIR__ . '/../services/GregOnboardingService.php';
require_once __DIR__ . '/../services/GregHandoffService.php';
require_once __DIR__ . '/../services/GregSystems.php';
require_once __DIR__ . '/../services/GregReadinessService.php';
require_once __DIR__ . '/../services/GregObservabilityService.php';
require_once __DIR__ . '/../services/AsteriskDocsService.php';

/**
 * /ai-voip-engineer — AI → AI VoIP Engineer (admin only): the Greg entity.
 * He is off until an administrator enables him here; this page also names
 * him, picks his model source (system / free tiers with their limits
 * shown), decides whether the top-bar avatar and token counter appear,
 * and sets the e-mail + quiet-hours notification policy.
 */
class AiVoipEngineerController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        // Opening the page is enough to notice that the shift changed while
        // nobody was looking: the handoff is written once, not per message.
        GregHandoffService::ensure();

        // The systems map: the other half of the handover, with its own
        // template and download. Same rule — admin only, no path from the URL.
        $systemsDownload = (string) ($_GET['systems'] ?? '');
        if ($systemsDownload === 'template' || $systemsDownload === 'current') {
            $body = $systemsDownload === 'template' ? GregSystems::template() : GregSystems::text();
            if ($body === '') {
                static::notifyError(t('ai_voip_engineer.err_sys_empty'));
                static::redirect('/ai-voip-engineer');
                return;
            }
            $name = $systemsDownload === 'template' ? GregSystems::FILENAME : 'systems-document-current.md';
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Cache-Control: no-store');
            echo $body;
            exit;
        }

        // Two small downloads: the template Greg hands over, and the brief as
        // it stands. Admin-only by the line above; no path comes from the URL.
        $download = (string) ($_GET['brief'] ?? '');
        if ($download === 'template' || $download === 'current') {
            $body = $download === 'template' ? GregBrief::template() : GregBrief::text();
            if ($body === '') {
                static::notifyError(t('ai_voip_engineer.err_brief_empty'));
                static::redirect('/ai-voip-engineer');
                return;
            }
            $name = $download === 'template' ? GregBrief::FILENAME : 'onboarding-brief-current.md';
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Cache-Control: no-store');
            echo $body;
            exit;
        }

        $message = '';
        $error = '';
        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                try {
                    if (isset($_POST['save_greg'])) {
                        $message = GregService::save($_POST)['message'];
                    } elseif (isset($_POST['start_greg_onboarding'])) {
                        // The conversation happens in the chat widget, where his
                        // history and the avatar already live; the question is
                        // rendered there from the session on arrival.
                        GregOnboardingService::start();
                        static::redirect('/chat');
                        return;
                    } elseif (isset($_POST['hand_off_greg'])) {
                        $change = GregHandoffService::handOff();
                        $message = sprintf(t('ai_voip_engineer.msg_handed_off'), $change['to']);
                    } elseif (isset($_POST['take_back_greg'])) {
                        $change = GregHandoffService::takeBack();
                        $message = sprintf(t('ai_voip_engineer.msg_taken_back'), $change['to']);
                    } elseif (isset($_POST['save_greg_anam'])) {
                        $message = AnamService::save($_POST)['message'];
                    } elseif (isset($_POST['save_greg_brief'])) {
                        $upload = $_FILES['greg_brief_file'] ?? null;
                        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                            GregBrief::saveUpload($upload);
                        } else {
                            GregBrief::save((string) ($_POST['greg_brief'] ?? ''));
                        }
                        $message = t('ai_voip_engineer.brief_saved');
                    } elseif (isset($_POST['delete_greg_brief'])) {
                        GregBrief::remove();
                        $message = t('ai_voip_engineer.brief_removed');
                    } elseif (isset($_POST['save_greg_systems'])) {
                        $upload = $_FILES['greg_systems_file'] ?? null;
                        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                            GregSystems::saveUpload($upload);
                        } else {
                            GregSystems::save((string) ($_POST['greg_systems'] ?? ''));
                        }
                        $message = t('ai_voip_engineer.sys_saved');
                    } elseif (isset($_POST['delete_greg_systems'])) {
                        GregSystems::remove();
                        $message = t('ai_voip_engineer.sys_removed');
                    } elseif (isset($_POST['sync_asterisk_docs'])) {
                        // Upstream knowledge, not the handover: fetched once on
                        // request, then read from disk — an offline box keeps
                        // the copy it already has.
                        $sync = AsteriskDocsService::sync();
                        $message = $sync['saved'] > 0
                            ? sprintf(t('ai_voip_engineer.ref_synced'), $sync['saved'])
                            : t('ai_voip_engineer.ref_kept');
                        if ($sync['failed']) {
                            $error = sprintf(t('ai_voip_engineer.ref_sync_partial'), count($sync['failed']));
                        }
                    } elseif (isset($_POST['delete_asterisk_docs'])) {
                        AsteriskDocsService::remove();
                        $message = t('ai_voip_engineer.ref_removed');
                    } elseif (isset($_POST['join_anam_meeting'])) {
                        $message = AnamService::joinMeeting($_POST)['message'];
                    } elseif (isset($_POST['save_greg_notes'])) {
                        GregJournal::saveAgents((string) ($_POST['greg_agents'] ?? ''));
                        $message = t('ai_voip_engineer.notes_saved');
                    } elseif (isset($_POST['append_greg_note'])) {
                        $title = trim((string) ($_POST['note_title'] ?? ''));
                        $body = trim((string) ($_POST['note_body'] ?? ''));
                        if ($title === '' && $body === '') {
                            throw new CloudAiException(t('ai_voip_engineer.err_note_empty'));
                        }
                        // A body without a title is the entry's title; both on
                        // one line, flattened, so the log stays a log.
                        GregJournal::append(
                            $title !== '' ? $title : mb_substr($body, 0, 60),
                            $title !== '' && $body !== '' ? [$body] : []
                        );
                        $message = t('ai_voip_engineer.notes_added');
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        static::renderPage('ai_voip_engineer/index', [
            'greg' => GregService::settings(),
            'anam' => AnamService::settings(),
            'brief' => GregBrief::status(),
            'brief_text' => GregBrief::text(),
            'systems' => GregSystems::status(),
            'systems_text' => GregSystems::text(),
            'readiness' => GregReadinessService::report(),
            'observability' => GregObservabilityService::report(),
            'asterisk_docs' => AsteriskDocsService::status(),
            'onboarding' => GregOnboardingService::status(),
            'duty' => GregService::onDuty(),
            'handoff' => GregHandoffService::state(),
            'month_tokens' => GregService::monthTokens(),
            'unread' => GregService::unreadCount(),
            'agents' => GregJournal::agents(),
            'journal_recent' => GregJournal::recentText(18),
            'journal_dir' => GregJournal::baseDir(),
            'journal_agents_path' => GregJournal::agentsPath(),
            'csrf_token' => getCSRFToken(),
        ], [
            // One name everywhere: the tab follows the name in settings, and
            // the role is stated once rather than spelled out in each string.
            'title' => GregService::displayName() . ' — ' . t('ai_voip_engineer.role_short'),
            'message' => $message,
            'error' => $error,
        ]);
    }
}
