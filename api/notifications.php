<?php
/**
 * DOCTOR CONNECT - In-App Notifications Polling API
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'notifications' => []]);
    exit;
}

$user = current_user();
$sinceId = filter_input(INPUT_GET, 'since', FILTER_VALIDATE_INT) ?: 0;

try {
    $db = get_db();
    
    // Fetch unread notifications
    $stmt = $db->prepare("
        SELECT id, message, type, is_read, created_at, appointment_id
        FROM notifications
        WHERE user_id = :uid AND id > :since AND is_read = 0
        ORDER BY id ASC
        LIMIT 10
    ");
    $stmt->execute([
        ':uid'   => $user['id'],
        ':since' => $sinceId
    ]);
    $notifs = $stmt->fetchAll();

    // Mark retrieved notifications as read
    if (!empty($notifs)) {
        $ids = array_column($notifs, 'id');
        $inClause = implode(',', array_map('intval', $ids));
        $db->exec("UPDATE notifications SET is_read = 1 WHERE id IN ($inClause)");
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifs
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
