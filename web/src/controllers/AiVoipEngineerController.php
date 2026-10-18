<?php
require_once __DIR__ . '/../services/GregService.php';

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
        $message = '';
        $error = '';
        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                try {
                    if (isset($_POST['save_greg'])) {
                        $message = GregService::save($_POST)['message'];
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        static::renderPage('ai_voip_engineer/index', [
            'greg' => GregService::settings(),
            'month_tokens' => GregService::monthTokens(),
            'unread' => GregService::unreadCount(),
            'csrf_token' => getCSRFToken(),
        ], ['title' => t('ai_voip_engineer.title'), 'message' => $message, 'error' => $error]);
    }
}
