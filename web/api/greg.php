<?php
/**
 * AI → VoIP Engineer API (any logged-in user; CSRF required).
 *   POST action=chat message=  → Greg's reply (session history, 12 deep)
 *   POST action=mark_read      → clear the notices badge
 * Disabled Greg answers honestly for chat and skips nothing elsewhere —
 * the top-bar widget only renders when he is enabled.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/GregService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('common.invalid_csrf')]);
    exit;
}
try {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'chat') {
        // The session stays open: chatSend() stores the 12-message history in
        // it, and a write after session_write_close() would be discarded.
        $result = GregService::chatSend((string) ($_POST['message'] ?? ''));
        session_write_close();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'mark_read') {
        session_write_close();
        GregService::markAllRead();
        echo json_encode(['success' => true]);
    } else {
        session_write_close();
        throw new CloudAiException('Unknown action');
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => mb_substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
}
