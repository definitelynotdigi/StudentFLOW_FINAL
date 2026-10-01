<?php
/* =============================================================================
   /includes/chat.php
   -----------------------------------------------------------------------------
   Handles the ?chat_action=send|fetch|threads JSON endpoints.
   Required AFTER auth.php (needs $pdo and $user_data).

   Chat notifications are stored in the `notifications` table using:
       notification_type = 'chat_message'
       reference_type    = 'support_message'
       reference_id      = support_messages.id

   This lets us reliably:
       - mark only chat notifications as read
       - avoid duplicates (one notification per message per recipient)
       - synchronize the bell badge with the Messages tab badge
   ============================================================================= */

if (!isset($_GET['chat_action'])) {
    return;
}

header('Content-Type: application/json');

$action           = $_GET['chat_action'];
$current_user_id  = (int)($_SESSION['user_id'] ?? 0);
$csrf_ok = function() {
    $posted = $_POST['csrf_token'] ?? '';
    return is_string($posted) && $posted !== ''
        && hash_equals($_SESSION['csrf_token'] ?? '', $posted);
};
$is_admin_or_dept = in_array($GLOBALS['current_role'] ?? '', ['admin', 'department'], true);
$is_student       = (($GLOBALS['current_role'] ?? '') === 'student');

if ($current_user_id <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit();
}

/* -------------------------------------------------------------------------
   Helper: create ONE chat notification per (recipient, message_id).
   Uses the existing create_notification() from functions.php.
   Idempotent — polling or retries will never duplicate.
   ------------------------------------------------------------------------- */
function notify_chat_message(
    PDO $pdo,
    int $recipientId,
    int $senderId,
    int $supportMessageId,
    string $title,
    string $body,
    ?string $targetCode
): void {
    // Skip if we've already notified this recipient about this exact message.
    $dup = $pdo->prepare("
        SELECT id FROM notifications
        WHERE recipient_user_id = ?
          AND notification_type = 'chat_message'
          AND reference_type    = 'support_message'
          AND reference_id      = ?
        LIMIT 1
    ");
    $dup->execute([$recipientId, $supportMessageId]);
    if ($dup->fetch()) return;

    create_notification(
        $pdo,
        $recipientId,
        $senderId,
        'chat_message',
        $title,
        $body,
        'support_message',
        $supportMessageId,
        $targetCode
    );
}

/* -------------------------------------------------------------------------
   Helper: mark chat notifications read for one conversation.
   Only touches rows whose reference_id matches a support_message
   that now belongs to the given student and is from the given sender_role.
   ------------------------------------------------------------------------- */
function mark_chat_notifications_read(
    PDO $pdo,
    int $recipientId,
    int $studentUserId,
    string $senderRole
): int {
    // Find every support_message id in this conversation from this sender.
    $ids = $pdo->prepare("
        SELECT id FROM support_messages
        WHERE user_id = ? AND sender_role = ?
    ");
    $ids->execute([$studentUserId, $senderRole]);
    $messageIds = $ids->fetchAll(PDO::FETCH_COLUMN);
    if (!$messageIds) return 0;

    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $params = array_merge([$recipientId], $messageIds);
    $stmt = $pdo->prepare("
        UPDATE notifications
           SET is_read = 1
         WHERE recipient_user_id  = ?
           AND notification_type  = 'chat_message'
           AND reference_type     = 'support_message'
           AND reference_id IN ($placeholders)
           AND is_read = 0
    ");
    $stmt->execute($params);
    return (int)$stmt->rowCount();
}

/* -------------------------------------------------------------------------
   Helper: unread admin messages for a given student (sender_role = 'admin').
   ------------------------------------------------------------------------- */
function unread_admin_messages_for(PDO $pdo, int $studentUserId): int {
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM support_messages
        WHERE user_id = ? AND sender_role = 'admin' AND is_read = 0
    ");
    $s->execute([$studentUserId]);
    return (int)$s->fetchColumn();
}

/* -------------------------------------------------------------------------
   Helper: unread student messages for the admin inbox.
   ------------------------------------------------------------------------- */
function unread_student_messages(PDO $pdo): int {
    return (int)$pdo->query("
        SELECT COUNT(*) FROM support_messages
        WHERE sender_role = 'student' AND is_read = 0
    ")->fetchColumn();
}

/* =========================================================================
   ACTION: send
   ========================================================================= */
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrf_ok()) {
        http_response_code(419);
        echo json_encode(['status' => 'error', 'message' => 'Security validation failed.']);
        exit();
    }

    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        echo json_encode(['status' => 'error', 'message' => 'Empty message.']);
        exit();
    }

    /* ---------------- STUDENT sending to ADMIN ---------------- */
    if ($is_student) {
        // Students always write to their own thread. Ignore any target_user_id.
        $studentUserId = $current_user_id;

        $ins = $pdo->prepare("
            INSERT INTO support_messages (user_id, sender_role, message, is_read)
            VALUES (?, 'student', ?, 0)
        ");
        $ins->execute([$studentUserId, $message]);
        $messageId = (int)$pdo->lastInsertId();

        // Look up the student's display info for the notification body.
        $stu = $pdo->prepare("
            SELECT student_id, first_name, last_name, username
            FROM users WHERE id = ? LIMIT 1
        ");
        $stu->execute([$studentUserId]);
        $row = $stu->fetch(PDO::FETCH_ASSOC) ?: [];
        $studentCode = $row['student_id'] ?? null;
        $studentName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($studentName === '') $studentName = $row['username'] ?? "Student #{$studentUserId}";

        // Notify every admin/department user.
        $admins = $pdo->query("
            SELECT id FROM users
            WHERE LOWER(role) IN ('admin','department')
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($admins as $adminId) {
            notify_chat_message(
                $pdo,
                (int)$adminId,
                $studentUserId,
                $messageId,
                'New Message',
                "{$studentName} sent you a new message.",
                $studentCode
            );
        }

        echo json_encode(['status' => 'success', 'message_id' => $messageId]);
        exit();
    }

    /* ---------------- ADMIN replying to a STUDENT ---------------- */
    if ($is_admin_or_dept) {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        if ($target_user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Missing target_user_id.']);
            exit();
        }

        // Verify the target is a real student.
        $chk = $pdo->prepare("
            SELECT id, student_id, first_name, last_name, username
            FROM users
            WHERE id = ? AND LOWER(role) = 'student'
            LIMIT 1
        ");
        $chk->execute([$target_user_id]);
        $student = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            echo json_encode(['status' => 'error', 'message' => 'Student not found.']);
            exit();
        }

        $ins = $pdo->prepare("
            INSERT INTO support_messages (user_id, sender_role, message, is_read)
            VALUES (?, 'admin', ?, 0)
        ");
        $ins->execute([$target_user_id, $message]);
        $messageId = (int)$pdo->lastInsertId();

        $studentCode = $student['student_id'] ?? null;
        $adminName   = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
        if ($adminName === '') $adminName = $user_data['username'] ?? 'Admin';

        notify_chat_message(
            $pdo,
            $target_user_id,
            $current_user_id,
            $messageId,
            'New Message from Admin',
            "{$adminName} sent you a reply.",
            $studentCode
        );

        echo json_encode(['status' => 'success', 'message_id' => $messageId]);
        exit();
    }

    echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
    exit();
}

/* =========================================================================
   ACTION: fetch
   ========================================================================= */
if ($action === 'fetch') {

    /* ---------------- STUDENT fetching their own thread ---------------- */
    if ($is_student) {
        $studentUserId = $current_user_id;

        // Only mark as read when the student EXPLICITLY opened the chat box.
        // We add ?mark_read=1 to the fetch URL from the frontend to opt in.
        $shouldMarkRead = isset($_GET['mark_read']) && (int)$_GET['mark_read'] === 1;

        if ($shouldMarkRead) {
            // 1. Mark admin messages as read.
            $pdo->prepare("
                UPDATE support_messages
                   SET is_read = 1
                 WHERE user_id = ? AND sender_role = 'admin' AND is_read = 0
            ")->execute([$studentUserId]);

            // 2. Mark the corresponding chat notifications as read.
            mark_chat_notifications_read($pdo, $studentUserId, $studentUserId, 'admin');
        }

        // 3. Return the messages.
        $stmt = $pdo->prepare("
            SELECT sender_role, message,
                   DATE_FORMAT(created_at, '%h:%i %p') AS time
              FROM support_messages
             WHERE user_id = ?
             ORDER BY created_at ASC
        ");
        $stmt->execute([$studentUserId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Raw unread admin messages for this student (used for the chat badge).
        $unreadAdminMessages = unread_admin_messages_for($pdo, $studentUserId);

        echo json_encode([
            'status'             => 'success',
            'messages'           => $messages,
            'unread_count'       => $unreadAdminMessages,
            'notification_count' => (int)$pdo->query("
                SELECT COUNT(*) FROM notifications
                 WHERE recipient_user_id = {$studentUserId} AND is_read = 0
            ")->fetchColumn(),
        ]);
        exit();
    }

    /* ---------------- ADMIN fetching a student's thread ---------------- */
    if ($is_admin_or_dept) {
        $target_user_id = (int)($_GET['target_user_id'] ?? 0);
        if ($target_user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Missing target_user_id.']);
            exit();
        }

        // 1. Mark this student's messages as read (admin is viewing the thread).
        $pdo->prepare("
            UPDATE support_messages
               SET is_read = 1
             WHERE user_id = ? AND sender_role = 'student' AND is_read = 0
        ")->execute([$target_user_id]);

        // 2. Mark the corresponding chat notifications for THIS admin as read.
        mark_chat_notifications_read($pdo, $current_user_id, $target_user_id, 'student');

        // 3. Return the messages.
        $stmt = $pdo->prepare("
            SELECT sender_role, message,
                   DATE_FORMAT(created_at, '%h:%i %p') AS time
              FROM support_messages
             WHERE user_id = ?
             ORDER BY created_at ASC
        ");
        $stmt->execute([$target_user_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status'        => 'success',
            'messages'      => $messages,
            'unread_count'  => unread_student_messages($pdo),
        ]);
        exit();
    }

    echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
    exit();
}

/* =========================================================================
   ACTION: threads   (admin only — refreshes the Messages tab list)
   ========================================================================= */
if ($action === 'threads' && $is_admin_or_dept) {

    $threads = $pdo->query("
        SELECT u.id, u.first_name, u.last_name, u.student_id,
               (SELECT message    FROM support_messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) AS last_msg,
               (SELECT created_at FROM support_messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) AS last_time,
               (SELECT COUNT(*)   FROM support_messages
                 WHERE user_id = u.id AND is_read = 0 AND sender_role = 'student') AS unread_count
          FROM users u
         WHERE EXISTS (SELECT 1 FROM support_messages WHERE user_id = u.id)
         ORDER BY last_time DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'       => 'success',
        'threads'      => $threads,
        'unread_count' => unread_student_messages($pdo),
    ]);
    exit();
}

/* =========================================================================
   ACTION: delete_thread   (admin/department only — PERMANENTLY deletes
   an entire student↔admin chat conversation, plus its chat notifications)
   ========================================================================= */
if ($action === 'delete_thread' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrf_ok()) {
        http_response_code(419);
        echo json_encode(['status' => 'error', 'message' => 'Security validation failed.']);
        exit();
    }

    /* -------- 1. AUTHORIZATION: admin or department ONLY -------- */
    if (!$is_admin_or_dept) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
        exit();
    }

    /* -------- 2. VALIDATE INPUT -------- */
    $student_user_id = (int)($_POST['student_user_id'] ?? 0);
    if ($student_user_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing student_user_id.']);
        exit();
    }

    /* -------- 3. VERIFY TARGET IS A REAL STUDENT -------- */
    $stu = $pdo->prepare("
        SELECT id FROM users
        WHERE id = ? AND LOWER(role) = 'student'
        LIMIT 1
    ");
    $stu->execute([$student_user_id]);
    if (!$stu->fetch()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Student not found.']);
        exit();
    }

    /* -------- 4. ATOMIC PERMANENT DELETE -------- */
    try {
        $pdo->beginTransaction();

        // 4a. Find every support_message id belonging to this student's thread.
        $idStmt = $pdo->prepare("
            SELECT id FROM support_messages WHERE user_id = ?
        ");
        $idStmt->execute([$student_user_id]);
        $messageIds = $idStmt->fetchAll(PDO::FETCH_COLUMN);

        // 4b. Delete ONLY the chat notifications that reference those messages.
        //     Grade / financial / announcement / system notifications are untouched.
        if (!empty($messageIds)) {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));

            $notifDel = $pdo->prepare("
                DELETE FROM notifications
                 WHERE notification_type = 'chat_message'
                   AND reference_type    = 'support_message'
                   AND reference_id IN ($placeholders)
            ");
            $notifDel->execute($messageIds);
        }

        // 4c. Delete the conversation itself (student + admin messages).
        //     NEVER touches users, grades, financials, announcements, etc.
        $msgDel = $pdo->prepare("
            DELETE FROM support_messages WHERE user_id = ?
        ");
        $msgDel->execute([$student_user_id]);

        $pdo->commit();

        try {
    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, details, target_type, target_id)
        VALUES (?, 'CHAT_THREAD_DELETED', ?, 'support_message', NULL)
    ")->execute([$current_user_id, "Deleted conversation with student #{$student_user_id}"]);
} catch (Throwable $e) {
    error_log('[chat audit] ' . $e->getMessage());
}

        echo json_encode([
            'status'  => 'success',
            'message' => 'Conversation permanently deleted.'
        ]);
        exit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[chat delete_thread] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Unable to delete the conversation.'
        ]);
        exit();
    }
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown chat action.']);
exit();