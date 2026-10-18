<?php
/**
 * Anam avatar API (logged-in users; CSRF required).
 *
 *   POST action=session  → {success, sessionToken, limit}
 *                          one session, `limit` seconds (also inside the token)
 *   POST action=status   → {success, active}   is the face available now?
 *   POST action=test     → {success, name}     admin: does this key see the persona?
 *
 * The Anam API key never leaves the server: this endpoint is the only thing
 * that speaks to api.anam.ai, and it hands the browser a short-lived session
 * token instead. No response is cached, and neither the key nor the token is
 * written to a log.
 *
 * Who may start a face: any user who may talk to Greg — the avatar is Greg's
 * face, not an administrator tool. Who may test the key or change it: the
 * administrator, on /ai-voip-engineer. A persona is billed by the minute, so
 * one browser session may ask for a token once every few seconds; the session
 * length itself is capped in AnamService.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/AnamService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('common.invalid_csrf')]);
    exit;
}

try {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'session') {
        if (!AnamService::active()) {
            session_write_close();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => t('ai_voip_engineer.anam_not_configured')]);
            exit;
        }
        $last = (int) ($_SESSION['anam_mint_at'] ?? 0);
        if (time() - $last < 3) {
            // A double click must not buy two sessions.
            session_write_close();
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => t('ai_voip_engineer.anam_slow_down')]);
            exit;
        }
        $_SESSION['anam_mint_at'] = time();
        $r = AnamService::sessionToken();
        session_write_close();
        if (!$r['ok']) {
            http_response_code(503);
            echo json_encode(['success' => false, 'error' => $r['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['success' => true, 'sessionToken' => $r['token'], 'limit' => $r['limit']]);
        exit;
    }

    session_write_close();

    if ($action === 'status') {
        echo json_encode(['success' => true, 'active' => AnamService::active()]);
        exit;
    }
    if ($action === 'test') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => t('common.forbidden')]);
            exit;
        }
        $r = AnamService::verify();
        echo json_encode([
            'success' => $r['ok'],
            'name' => $r['name'],
            'error' => $r['error'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => mb_substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
}
