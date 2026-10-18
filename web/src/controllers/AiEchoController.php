<?php
require_once __DIR__ . '/../services/ai/EchoService.php';
require_once __DIR__ . '/../services/ai/HepSettingsService.php';
require_once __DIR__ . '/../services/ai/OpencodeService.php';

/**
 * /ai-echo — AI → Echo (admin only): the SIP correlation tool, its own section.
 *
 * Echo is what Greg reads when a call goes wrong: this server's own counts
 * plus Sipfront's test runs, correlated by time and session. The page holds
 * the switch, the Sipfront keys (secret stored encrypted, never shown back),
 * an optional test that can be started on demand, and the last runs.
 */
class AiEchoController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        $message = '';
        $error = '';
        $run = null;

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                try {
                    if (isset($_POST['save_echo'])) {
                        $message = EchoService::save($_POST)['message'];
                    } elseif (isset($_POST['test_connection'])) {
                        $res = EchoService::probe();
                        $message = t('ai_echo.connected') . ' (' . (int) $res['count'] . ')';
                    } elseif (isset($_POST['run_test'])) {
                        $run = EchoService::runTest();
                        $message = sprintf(t('ai_echo.test_started'), (string) ($run['session_id'] ?? '—'));
                    } elseif (isset($_POST['save_hep'])) {
                        $message = HepSettingsService::save($_POST)['message'];
                    } elseif (isset($_POST['save_opencode'])) {
                        $message = OpencodeService::save($_POST)['message'];
                    } elseif (isset($_POST['propose_opencode'])) {
                        $kind = ($_POST['opencode_kind'] ?? 'tool') === 'research' ? 'research' : 'tool';
                        $proposal = OpencodeService::propose((string) ($_POST['opencode_task'] ?? ''), $kind);
                        $message = sprintf(t('ai_echo.oc_proposed'), (string) $proposal['id']);
                        $run = ['proposal' => $proposal];
                    } elseif (isset($_POST['decide_proposal'])) {
                        $id = (int) ($_POST['proposal_id'] ?? 0);
                        $approve = (string) ($_POST['decide_proposal'] ?? '') === 'approve';
                        OpencodeService::decide($id, $approve);
                        $message = sprintf(t('ai_echo.oc_decided'), (string) $id);
                    } elseif (isset($_POST['hep_test'])) {
                        $probe = HepSettingsService::probe(HepSettingsService::settings());
                        if ($probe['ok']) {
                            $message = $probe['message'];
                        } else {
                            $error = $probe['message'];
                        }
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $recent = EchoService::recentRuns(5);
        static::renderPage('ai_echo/index', [
            'echo'        => EchoService::settings(),
            'setup_steps' => EchoService::setupSteps(),
            'runs'        => $recent['runs'],
            'runs_error'  => $recent['error'],
            'last_run'    => $run,
            'hep'         => HepSettingsService::settings(),
            'hep_current' => HepSettingsService::currentFile(),
            'homer_cmd'   => HepSettingsService::homerCommand(),
            'oc'          => OpencodeService::settings(),
            'oc_recent'   => OpencodeService::recent(8),
            'csrf_token'  => getCSRFToken(),
        ], ['title' => t('ai_echo.title'), 'message' => $message, 'error' => $error]);
    }
}
