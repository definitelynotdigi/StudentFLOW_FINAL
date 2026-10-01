<?php
/* =============================================================================
   /includes/notifications.php
   -----------------------------------------------------------------------------
   Handles the ?notification_action= JSON endpoints.
   Required AFTER auth.php (needs $pdo and $_SESSION['user_id']).
   ============================================================================= */

if (isset($_GET['notification_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['notification_action'];
    $uid    = (int)($_SESSION['user_id'] ?? 0);

    if ($uid <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
        exit();
    }

    /* ---------- Shared CSRF check for every POST action ---------- */
    $csrf_ok = function() {
        $posted = $_POST['csrf_token'] ?? '';
        return is_string($posted) && $posted !== ''
            && hash_equals($_SESSION['csrf_token'] ?? '', $posted);
    };

    if ($action === 'count') {
        $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0");
        $s->execute([$uid]);
        echo json_encode(['status' => 'success', 'count' => (int)$s->fetchColumn()]);
        exit();
    }

    if ($action === 'list') {
        $s = $pdo->prepare("
            SELECT n.*, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS sender_name
            FROM notifications n
            LEFT JOIN users u ON n.sender_user_id = u.id
            WHERE n.recipient_user_id = ?
            ORDER BY n.created_at DESC
            LIMIT 20
        ");
        $s->execute([$uid]);
        echo json_encode(['status' => 'success', 'notifications' => $s->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    if ($action === 'read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$csrf_ok()) {
            http_response_code(419);
            echo json_encode(['status' => 'error', 'message' => 'Security validation failed.']);
            exit();
        }
        $notif_id = (int)($_POST['id'] ?? 0);          // ← RESTORED
        if ($notif_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid notification ID.']);
            exit();
        }
        $s = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_user_id = ?");
        $s->execute([$notif_id, $uid]);
        if ($s->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Notification not found or access denied.']);
            exit();
        }
        echo json_encode(['status' => 'success', 'message' => 'Notification marked as read.']);
        exit();
    }

    if ($action === 'read_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$csrf_ok()) {
            http_response_code(419);
            echo json_encode(['status' => 'error', 'message' => 'Security validation failed.']);
            exit();
        }
        $s = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_user_id = ? AND is_read = 0");
        $s->execute([$uid]);
        echo json_encode([
            'status'  => 'success',
            'message' => 'All notifications marked as read.',
            'updated' => (int)$s->rowCount()
        ]);
        exit();
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$csrf_ok()) {
            http_response_code(419);
            echo json_encode(['status' => 'error', 'message' => 'Security validation failed.']);
            exit();
        }
        $notif_id = (int)($_POST['id'] ?? 0);
        if ($notif_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid notification ID.']);
            exit();
        }
        $s = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND recipient_user_id = ?");
        $s->execute([$notif_id, $uid]);
        if ($s->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Notification not found or access denied.']);
            exit();
        }
        echo json_encode(['status' => 'success', 'message' => 'Notification deleted.']);
        exit();
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
    exit();
}