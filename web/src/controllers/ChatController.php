<?php

require_once __DIR__ . '/../../api/mobile/auth_helper.php';
require_once __DIR__ . '/../services/ChatSettingsService.php';
require_once __DIR__ . '/../services/GregService.php';
require_once __DIR__ . '/../services/GregReadinessService.php';
require_once __DIR__ . '/../services/AnamService.php';

/**
 * Chat page (/chat).
 * Renders templates/views/chat/index.php with assets/js/chat.js.
 * Logic: ChatSettingsService.
 * POST actions: save_chat_settings.
 * Access by role module (getModuleKeyForPage() in auth.php, Admin → Roles).
 */
class ChatController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireModule('chat', 'view');

        // A shift change is noticed the first time somebody opens the phones,
        // so the handoff is in AGENTS.md before the greeting is rendered.
        GregHandoffService::ensure();

        $user = getCurrentUser();
        $ext = trim($user['extension'] ?? '');

        if ($ext === '') {
            static::notifyError(t('chat.err_no_extension'));
            static::redirect('/dashboard');
            return;
        }

        // Chat settings: administrators only.
        $isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
        $notices = $isAdmin
            ? static::handlePost([
                'save_chat_settings' => fn() => static::verifyCsrf()
                    ? ChatSettingsService::saveDeleteWindow($_POST['chat_delete_window_minutes'] ?? '')
                    : ['success' => false, 'error' => t('common.invalid_csrf')],
            ])
            : ['message' => '', 'error' => ''];

        $token = generateMobileToken($user, 86400); // valid for 24 hours for the web chat session
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('chat_token', $token, [
            'expires' => time() + 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isSecure,
        ]);
        $page_title = function_exists('t') ? t('sidebar.item_chat') : 'Sohbet';

        static::renderPage('chat/index', [
            'user' => $user,
            'ext' => $ext,
            'token' => $token,
            'page_title' => $page_title,
            'is_admin' => $isAdmin,
            // The same temperature as the settings card, so an administrator
            // working in Greg's chat sees the handover gap where he is used.
            // Not computed for ordinary users: it is not their job to fix it.
            'greg_readiness' => $isAdmin ? GregReadinessService::report() : null,
            'delete_window' => ChatSettingsService::deleteWindowMinutes(),
        ], ['title' => $page_title] + $notices);
    }
}
