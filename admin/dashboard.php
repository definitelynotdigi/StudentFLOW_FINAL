<?php
/* =============================================================================
   /admin/dashboard.php  —  Admin / Department Dashboard
   -----------------------------------------------------------------------------
   Contains ONLY admin features:
     - Profile
     - Grade Review queue (faculty_submissions routed to THIS admin)
     - Review modal (student info + all academic records + GWA)
     - APPROVE & RELEASE  (is_released = 1, submission_status = 'released')
     - RETURN TO FACULTY  (submission_status = 'returned')
     - Delete academic record permanently
     - Clear submission from queue (soft)
     - Student Master List (add / delete / auto-sync)
     - Announcements (create / edit / delete)
     - User directory
     - Support chat inbox
     - Notifications
   ============================================================================= */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['admin', 'department']);

require_once __DIR__ . '/../includes/chat.php';
require_once __DIR__ . '/../includes/notifications.php';

$grade_limit = GRADE_LIMIT;

/* -------------------------------------------------------------------------
   Local helper: write an audit-log row, ignoring schema drift.
   ------------------------------------------------------------------------- */
function audit_log_safe(PDO $pdo, ?int $userId, string $action, string $details = '',
                        ?string $type = null, ?int $tid = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, target_type, target_id)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $action, $details, $type, $tid]);
    } catch (Throwable $e) {
        error_log('[audit_log_safe] ' . $e->getMessage());
    }
}



/* ==========================================================================
   POST HANDLERS — admin-only features
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    /* -------- AJAX: Release all unreleased records for one student -------- */
    if (isset($_POST['send_record_target_id'])) {
        header('Content-Type: application/json');

        $target_student_code = trim($_POST['send_record_target_id'] ?? '');
        if ($target_student_code === '') {
            echo json_encode(['status'=>'error','message'=>'Student ID is required.']); exit();
        }

        $target_user = find_user_by_code($pdo, $target_student_code, ['student']);
        if (!$target_user) {
            echo json_encode(['status'=>'error','message'=>'Target Student ID was not found.']); exit();
        }
        $student_db_id = (int)$target_user['id'];

        $this_admin_code = $user_data['student_id'] ?? '';

        // Ownership check — this admin must own a submission for this student.
        $sub_stmt = $pdo->prepare("
            SELECT fs.id, fs.student_id, fs.admin_id, fs.admin_target_id
            FROM faculty_submissions fs
            WHERE fs.student_id = ?
              AND (fs.admin_id = ? OR fs.admin_target_id = ?)
            ORDER BY fs.id DESC
            LIMIT 1
        ");
        $sub_stmt->execute([$student_db_id, $user_data['id'], $this_admin_code]);
        $submission = $sub_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$submission) {
            echo json_encode([
                'status'=>'error',
                'message'=>'Unauthorized grade release request. This student is not part of a submission routed to your Admin ID.'
            ]);
            exit();
        }

        // Unreleased records for this student.
        $rec_stmt = $pdo->prepare("
            SELECT id, subject_code FROM academic_records
            WHERE student_id = ? AND (is_released = 0 OR is_released IS NULL)
        ");
        $rec_stmt->execute([$student_db_id]);
        $to_release = $rec_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($to_release) === 0) {
            $already = $pdo->prepare("SELECT COUNT(*) FROM academic_records WHERE student_id = ?");
            $already->execute([$student_db_id]);
            $total = (int)$already->fetchColumn();

            if ($total > 0) {
                $pdo->prepare("UPDATE faculty_submissions SET submission_status='released' WHERE id = ?")
                    ->execute([$submission['id']]);
                echo json_encode(['status'=>'info','message'=>'All grade records have already been released to this student.']);
            } else {
                echo json_encode(['status'=>'error','message'=>'No grade records found for this student.']);
            }
            exit();
        }

        $admin_name = $display_name;

        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("
                UPDATE academic_records
                SET is_released = 1,
                    cleared_by_student = 0,
                    released_by_admin_id = ?,
                    released_at = NOW()
                WHERE student_id = ? AND (is_released = 0 OR is_released IS NULL)
            ");
            $upd->execute([$_SESSION['user_id'], $student_db_id]);

            $pdo->prepare("UPDATE faculty_submissions SET submission_status='released' WHERE id = ?")
                ->execute([$submission['id']]);

            $subjectCount = count($to_release);
            $subjectList  = implode(', ', array_map(fn($r) => $r['subject_code'], $to_release));

            create_notification(
                $pdo,
                $student_db_id,
                (int)$_SESSION['user_id'],
                'grade_released',
                'Grade Record Released',
                "Your final grade record" . ($subjectCount>1 ? "s ({$subjectList})" : " ({$subjectList})") .
                    " " . ($subjectCount>1 ? "have" : "has") .
                    " been reviewed and released by {$admin_name}.",
                'academic_record',
                (int)$to_release[0]['id'],
                $target_student_code
            );

            $pdo->commit();
            echo json_encode([
                'status'=>'success',
                'message'=>"Released {$subjectCount} grade record" . ($subjectCount>1 ? 's':''). " to Student {$target_student_code}."
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[grade_release] ' . $e->getMessage());
            echo json_encode(['status'=>'error','message'=>'Unable to release grade records. Please try again.']);
        }
        exit();
    }

    /* -------- Return a submission to faculty (no release) -------- */
    if (isset($_POST['return_submission_to_faculty'])) {
        $sub_id = (int)($_POST['submission_id'] ?? 0);
        $reason = trim($_POST['return_reason'] ?? 'Returned for revision.');
        $this_admin_code = $user_data['student_id'] ?? '';

        // Ownership check.
        $own = $pdo->prepare("
            SELECT fs.id, fs.faculty_id, fs.student_id, u.student_id AS stud_code
            FROM faculty_submissions fs
            JOIN users u ON fs.student_id = u.id
            WHERE fs.id = ? AND (fs.admin_id = ? OR fs.admin_target_id = ?)
            LIMIT 1
        ");
        $own->execute([$sub_id, $user_data['id'], $this_admin_code]);
        $row = $own->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $pdo->prepare("UPDATE faculty_submissions SET submission_status='returned' WHERE id = ?")->execute([$sub_id]);

            create_notification(
                $pdo,
                (int)$row['faculty_id'],
                (int)$_SESSION['user_id'],
                'system',
                'Grade Submission Returned',
                "Your submission for student {$row['stud_code']} was returned: {$reason}",
                'faculty_submission',
                $sub_id,
                $row['stud_code']
            );

            $_SESSION['msg']      = "Submission returned to Faculty.";
            $_SESSION['msg_type'] = "warning";
        } else {
            $_SESSION['msg']      = "Submission not found or not owned by your Admin ID.";
            $_SESSION['msg_type'] = "danger";
        }
        header("Location: dashboard.php");
        exit();
    }

if (isset($_POST['delete_academic_record_permanent'])) {
    $record_id = (int)($_POST['record_id'] ?? 0);
    $this_admin_code = $user_data['student_id'] ?? '';

    if ($record_id <= 0) {
        $_SESSION['msg'] = "Invalid record.";
        $_SESSION['msg_type'] = "danger";
        header("Location: dashboard.php"); exit();
    }

    try {
        $pdo->beginTransaction();

        // Ownership: this record's student must be part of a submission
        // that was routed to THIS admin.
        $own = $pdo->prepare("
            SELECT ar.id, ar.student_id, ar.subject_code
            FROM academic_records ar
            WHERE ar.id = ?
              AND EXISTS (
                  SELECT 1 FROM faculty_submissions fs
                  WHERE fs.student_id = ar.student_id
                    AND (fs.admin_id = ? OR fs.admin_target_id = ?)
              )
            LIMIT 1
        ");
        $own->execute([$record_id, $user_data['id'], $this_admin_code]);
        $rec = $own->fetch(PDO::FETCH_ASSOC);

        if (!$rec) {
            throw new RuntimeException('Unauthorized or missing record.');
        }

        $pdo->prepare("DELETE FROM academic_records WHERE id = ?")->execute([$record_id]);

        $remaining = $pdo->prepare("SELECT COUNT(*) FROM academic_records WHERE student_id = ?");
        $remaining->execute([$rec['student_id']]);
        $stillHasRecords = ((int)$remaining->fetchColumn()) > 0;

        if ($stillHasRecords) {
    $pdo->prepare("UPDATE faculty_submissions
                     SET cleared_by_admin = 0, submission_status = 'submitted'
                   WHERE student_id = ?
                     AND (admin_id = ? OR admin_target_id = ?)
                     AND submission_status <> 'released'")
        ->execute([$rec['student_id'], $user_data['id'], $this_admin_code]);
} else {
            $pdo->prepare("UPDATE faculty_submissions
                             SET cleared_by_admin = 1, submission_status = 'cleared'
                           WHERE student_id = ? AND (admin_id = ? OR admin_target_id = ?)")
                ->execute([$rec['student_id'], $user_data['id'], $this_admin_code]);
        }

        $pdo->commit();
        $_SESSION['msg'] = "Grade record for {$rec['subject_code']} was permanently deleted.";
        $_SESSION['msg_type'] = "warning";

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[admin_delete_record] ' . $e->getMessage());
        $_SESSION['msg'] = "Unable to delete grade record. Please try again.";
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: dashboard.php"); exit();
}

    /* -------- Clear faculty submission from queue (soft) -------- */
    if (isset($_POST['delete_faculty_submission'])) {
        $sub_id = (int)($_POST['submission_id'] ?? 0);
        $this_admin_code = $user_data['student_id'] ?? '';
        $del = $pdo->prepare("
            UPDATE faculty_submissions SET cleared_by_admin = 1
            WHERE id = ? AND (admin_id = ? OR admin_target_id = ?)
        ");
        $del->execute([$sub_id, $user_data['id'], $this_admin_code]);
        $_SESSION['msg']      = "Submission cleared from your Admin Dashboard.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    

    /* -------- Student Master List: add -------- */
    if (isset($_POST['add_student_masterlist'])) {
        $student_id = trim($_POST['student_id']);
        $first_name = trim($_POST['first_name']);
        $last_name  = trim($_POST['last_name']);
        $department = trim($_POST['department']);
        $course     = trim($_POST['course']);

        if ($student_id !== '' && $first_name !== '' && $last_name !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO student_masterlist (student_id, first_name, last_name, department, course, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE first_name=VALUES(first_name), last_name=VALUES(last_name),
                                        department=VALUES(department), course=VALUES(course)
            ");
            if ($stmt->execute([$student_id, $first_name, $last_name, $department, $course])) {
                $_SESSION['msg']      = "Student ID ($student_id) added to Master List.";
                $_SESSION['msg_type'] = "success";
            } else {
                $_SESSION['msg']      = "Failed to add student.";
                $_SESSION['msg_type'] = "danger";
            }
        } else {
            $_SESSION['msg']      = "Please fill in all required fields.";
            $_SESSION['msg_type'] = "danger";
        }
        header("Location: dashboard.php");
        exit();
    }

    /* -------- Student Master List: delete -------- */
    if (isset($_POST['delete_masterlist_student'])) {
        $ml_id = (int)$_POST['masterlist_id'];
        $pdo->prepare("DELETE FROM student_masterlist WHERE id = ?")->execute([$ml_id]);
        $_SESSION['msg']      = "Student record removed from Master List.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    /* -------- Announcements: create -------- */
    if (isset($_POST['post_announcement'])) {
        $title    = trim($_POST['title'] ?? '');
        $content  = trim($_POST['content'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $priority = trim($_POST['priority'] ?? 'Normal');
        $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;

        $allowed_cat = ['General','Academic','Enrollment','Examination','Event','Scholarship','System Maintenance','Emergency'];
        $allowed_pri = ['Normal','Important','Urgent'];
        if (!in_array($category, $allowed_cat, true)) $category = 'General';
        if (!in_array($priority, $allowed_pri, true)) $priority = 'Normal';

        if ($title === '' || $content === '') {
            $_SESSION['msg']      = "Title and content are required.";
            $_SESSION['msg_type'] = "danger";
        } else {
            $pdo->prepare("
                INSERT INTO announcements (title, content, posted_by, category, priority, is_pinned)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$title, $content, $_SESSION['username'], $category, $priority, $is_pinned]);
            $_SESSION['msg']      = "Announcement published.";
            $_SESSION['msg_type'] = "success";
        }
        header("Location: dashboard.php");
        exit();
    }

    /* -------- Announcements: edit -------- */
    if (isset($_POST['edit_announcement'])) {
        $pdo->prepare("UPDATE announcements SET title = ?, content = ? WHERE id = ?")
            ->execute([$_POST['ann_title'], $_POST['ann_content'], $_POST['ann_id']]);
        $_SESSION['msg']      = "Announcement updated.";
        $_SESSION['msg_type'] = "success";
        header("Location: dashboard.php");
        exit();
    }

    /* -------- Announcements: delete -------- */
    if (isset($_POST['delete_announcement'])) {
        $pdo->prepare("DELETE FROM announcements WHERE id = ?")->execute([$_POST['ann_id']]);
        $_SESSION['msg']      = "Announcement deleted.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    /* ========== FINANCIAL WORKFLOW ACTIONS ========== */
if (isset($_POST['financial_action'])) {
    $id      = (int)($_POST['transaction_id'] ?? 0);
    $action  = $_POST['financial_action'];
    $remarks = trim($_POST['admin_remarks'] ?? '');

    $row = $pdo->prepare("
        SELECT fl.*, u.student_id AS stud_code,
               CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS stud_name
        FROM financial_ledgers fl
        JOIN users u ON fl.student_id = u.id
        WHERE fl.id = ?
        LIMIT 1
    ");
    $row->execute([$id]);
    $rec = $row->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        $_SESSION['msg'] = "Financial record not found.";
        $_SESSION['msg_type'] = 'danger';
    } elseif ($action === 'approve') {
        $pdo->prepare("
            UPDATE financial_ledgers
               SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), admin_remarks=NULL
             WHERE id = ?
        ")->execute([(int)$_SESSION['user_id'], (int)$_SESSION['user_id'], $id]);

        if (!empty($rec['finance_id'])) {
            create_notification(
                $pdo, (int)$rec['finance_id'], (int)$_SESSION['user_id'],
                'system', 'Financial Record Approved',
                "Your record {$rec['reference_no']} for {$rec['stud_name']} ({$rec['stud_code']}) was approved.",
                'financial_ledger', $id, $rec['stud_code']
            );
        }
        create_notification(
            $pdo, (int)$rec['student_id'], (int)$_SESSION['user_id'],
            'system', 'New Approved Tuition/Fee Posted',
            "A new approved {$rec['transaction_type']} of ₱"
            . number_format((float)$rec['amount'], 2)
            . " has been posted to your financial account. Ref: {$rec['reference_no']}.",
            'financial_ledger', $id, $rec['stud_code']
        );

        audit_log_safe($pdo, (int)$_SESSION['user_id'],
            'FINANCIAL_RECORD_APPROVED', "Approved ledger #{$id}",
            'financial_ledger', $id);

        $_SESSION['msg'] = "Financial record approved.";
        $_SESSION['msg_type'] = 'success';

    } elseif ($action === 'return') {
        $pdo->prepare("
            UPDATE financial_ledgers
               SET status='returned', admin_id=?, reviewed_by=?, reviewed_at=NOW(), admin_remarks=?
             WHERE id = ?
        ")->execute([
            (int)$_SESSION['user_id'],
            (int)$_SESSION['user_id'],
            $remarks !== '' ? $remarks : 'Returned for review.',
            $id
        ]);

        if (!empty($rec['finance_id'])) {
            create_notification(
                $pdo, (int)$rec['finance_id'], (int)$_SESSION['user_id'],
                'system', 'Financial Record Returned',
                "Your record {$rec['reference_no']} was returned: "
                . ($remarks !== '' ? $remarks : 'See admin remarks.'),
                'financial_ledger', $id, $rec['stud_code']
            );
        }

        audit_log_safe($pdo, (int)$_SESSION['user_id'],
            'FINANCIAL_RECORD_RETURNED', "Returned ledger #{$id}: {$remarks}",
            'financial_ledger', $id);

        $_SESSION['msg'] = "Financial record returned to Finance.";
        $_SESSION['msg_type'] = 'warning';

    } elseif ($action === 'void') {
        $wasApproved = (($rec['status'] ?? '') === 'approved');

        $pdo->prepare("
            UPDATE financial_ledgers
               SET voided=1, status='voided', void_reason=?, voided_by=?, voided_at=NOW()
             WHERE id = ?
        ")->execute([
            $remarks !== '' ? $remarks : 'Voided by Admin',
            (int)$_SESSION['user_id'],
            $id
        ]);

        if ($wasApproved) {
            create_notification(
                $pdo, (int)$rec['student_id'], (int)$_SESSION['user_id'],
                'system', 'Financial Record Voided',
                "A previously approved {$rec['transaction_type']} of ₱"
                . number_format((float)$rec['amount'], 2)
                . " (Ref: {$rec['reference_no']}) has been voided. Reason: "
                . ($remarks !== '' ? $remarks : 'N/A'),
                'financial_ledger', $id, $rec['stud_code']
            );
        }

        audit_log_safe($pdo, (int)$_SESSION['user_id'],
            'FINANCIAL_RECORD_VOIDED', "Voided ledger #{$id}",
            'financial_ledger', $id);

        $_SESSION['msg'] = "Financial record voided.";
        $_SESSION['msg_type'] = 'warning';
    }
    header("Location: dashboard.php"); exit();
}
}

/* ==========================================================================
   DATA FOR THE ADMIN VIEW
   ========================================================================== */

// Auto-sync masterlist status.
$pdo->exec("
    UPDATE student_masterlist sm
    JOIN users u ON sm.student_id = u.student_id
    SET sm.status='active'
    WHERE sm.status='pending' AND LOWER(u.role)='student'
");

$current_admin_code = $user_data['student_id'] ?? '';

$sub_stmt = $pdo->prepare("
    SELECT fs.*, u.id as user_db_id, u.student_id as stud_code, u.first_name, u.last_name, f.username as faculty_name
    FROM faculty_submissions fs
    JOIN users u ON fs.student_id = u.id
    JOIN users f ON fs.faculty_id = f.id
    WHERE fs.cleared_by_admin = 0
      AND (fs.admin_id = ? OR fs.admin_target_id = ?)
    ORDER BY fs.id DESC
    LIMIT 15
");
$sub_stmt->execute([$user_data['id'], $current_admin_code]);
$submissions_list = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($submissions_list)) {
    $ids = array_column($submissions_list, 'user_db_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $rec_stmt = $pdo->prepare("
        SELECT student_id, id, subject_code, subject_title, semester, academic_year, grade, is_released
        FROM academic_records
        WHERE student_id IN ($ph)
        ORDER BY id DESC
    ");
    $rec_stmt->execute($ids);
    $all_records = [];
    foreach ($rec_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $all_records[(int)$row['student_id']][] = $row;
    }

    $gwa_stmt = $pdo->prepare("
        SELECT student_id, AVG(grade) AS gwa
        FROM academic_records
        WHERE student_id IN ($ph)
        GROUP BY student_id
    ");
    $gwa_stmt->execute($ids);
    $gwas = [];
    foreach ($gwa_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gwas[(int)$row['student_id']] = $row['gwa'];
    }

    foreach ($submissions_list as &$sub) {
        $uid = (int)$sub['user_db_id'];
        $sub['records'] = $all_records[$uid] ?? [];
        $avg = $gwas[$uid] ?? null;
        $sub['gwa'] = ($avg !== null && $avg !== false) ? number_format((float)$avg, 2) : '—';
    }
}
unset($sub);

// Master list.
$masterlist_students = $pdo->query("
    SELECT sm.id, sm.student_id, sm.first_name, sm.last_name, sm.department, sm.course, sm.status, sm.created_at,
           IF(u.id IS NOT NULL, 1, 0) AS is_registered
    FROM student_masterlist sm
    LEFT JOIN users u ON sm.student_id = u.student_id AND LOWER(u.role)='student'
    ORDER BY sm.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Financial submissions (pending + history) — queried here so the
// Dashboard section below can use $financial_pending / $financial_history.
$fin_stmt = $pdo->query("
    SELECT fl.*,
           u.student_id AS stud_code,
           CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS stud_name,
           cu.username AS finance_username,
           cu.student_id AS finance_code
    FROM financial_ledgers fl
    JOIN users u ON fl.student_id = u.id
    LEFT JOIN users cu ON fl.finance_id = cu.id
    WHERE fl.voided = 0 AND fl.status = 'submitted'
    ORDER BY fl.submitted_at ASC, fl.id ASC
    LIMIT 50
");
$financial_pending = $fin_stmt->fetchAll(PDO::FETCH_ASSOC);

$fin_hist_stmt = $pdo->query("
    SELECT fl.*,
           u.student_id AS stud_code,
           CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS stud_name,
           cu.username AS finance_username
    FROM financial_ledgers fl
    JOIN users u ON fl.student_id = u.id
    LEFT JOIN users cu ON fl.finance_id = cu.id
    WHERE fl.voided = 0 AND fl.status IN ('approved','returned')
    ORDER BY fl.reviewed_at DESC, fl.id DESC
    LIMIT 25
");
$financial_history = $fin_hist_stmt->fetchAll(PDO::FETCH_ASSOC);

// Announcements.
$announcements_list = $pdo->query("SELECT * FROM announcements ORDER BY id DESC")->fetchAll();
$ann_count = count($announcements_list);

// Notification bell.
$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0");
$unread_stmt->execute([$user_id]);
$total_notifications = (int)$unread_stmt->fetchColumn();

$notif_list_stmt = $pdo->prepare("
    SELECT n.*, CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,'')) AS sender_name, s.role AS sender_role
    FROM notifications n
    LEFT JOIN users s ON n.sender_user_id = s.id
    WHERE n.recipient_user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 15
");
$notif_list_stmt->execute([$user_id]);
$notification_list = $notif_list_stmt->fetchAll(PDO::FETCH_ASSOC);

// Support-chat inbox stats.
$unread_msg_count = (int)$pdo->query("SELECT COUNT(*) FROM support_messages WHERE is_read = 0 AND sender_role='student'")->fetchColumn();
$pending_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM faculty_submissions fs
    WHERE fs.cleared_by_admin = 0
      AND fs.submission_status = 'submitted'    -- only genuinely pending
      AND (fs.admin_id = ? OR fs.admin_target_id = ?)
");
$pending_stmt->execute([$user_data['id'], $current_admin_code]);
$pending_submissions_count = (int)$pending_stmt->fetchColumn();

// User directory.
$users_list = $pdo->query("
    SELECT id, username, email, role, student_id, first_name, last_name, phone, department_course
    FROM users ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

// Chat threads (student <-> admin).
$threads = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.student_id,
        (SELECT message FROM support_messages WHERE user_id=u.id ORDER BY created_at DESC LIMIT 1) as last_msg,
        (SELECT created_at FROM support_messages WHERE user_id=u.id ORDER BY created_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM support_messages WHERE user_id=u.id AND is_read=0 AND sender_role='student') as unread_count
    FROM users u
    WHERE EXISTS (SELECT 1 FROM support_messages WHERE user_id=u.id)
    ORDER BY last_time DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentFLOW | Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard-layout.css">
    <style>
        :root{--grc-primary:#800000;--grc-secondary:#ab0a0a;--grc-hover:#ff1e1e;--grc-dark:#120202;--grc-bg:#f8fafc;--grc-border:#e2e8f0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--grc-bg);color:#1e293b;}
        .navbar-grc{background:linear-gradient(135deg,var(--grc-dark) 0%,#3a0000 100%);border-bottom:3px solid var(--grc-hover);}
        .dashboard-hero{background:linear-gradient(135deg,var(--grc-dark) 0%,var(--grc-primary) 60%,var(--grc-secondary) 100%);border-radius:20px;color:#fff;padding:2rem 2.5rem;box-shadow:0 20px 25px -5px rgba(128,0,0,0.15);}
        .card-custom{background:#fff;border:1px solid var(--grc-border);border-radius:18px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.02);}
        .btn-grc-action{background:linear-gradient(135deg,var(--grc-secondary) 0%,var(--grc-primary) 100%);color:#fff;border:none;border-radius:10px;font-weight:600;}
        .btn-grc-action:hover{background:var(--grc-hover);color:#fff;}
        .bell-btn{position:relative;background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.2);border-radius:12px;width:42px;height:42px;display:flex;align-items:center;justify-content:center;}
        .bell-badge{position:absolute;top:-3px;right:-3px;font-size:.65rem;padding:3px 6px;border-radius:10px;border:2px solid var(--grc-dark);}
        .floating-toast-container{position:fixed;top:25px;right:25px;z-index:10000;min-width:320px;max-width:450px;background:#fff;border-left:6px solid var(--grc-primary);box-shadow:0 10px 30px rgba(0,0,0,0.2);border-radius:12px;}
        .notif-item.notif-unread{background:#fff8f8;border-left:4px solid var(--grc-secondary) !important;}
        .notif-item.notif-read{background:#fff;opacity:.85;}
        .selectable-row{cursor:pointer;}
        .selectable-row.selected-row{background:#fff3f3 !important;box-shadow:inset 3px 0 0 var(--grc-secondary);}
.stat-card-modern {
    background: #fff;
    border: 1px solid var(--grc-border);
    border-radius: 18px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03);
    height: 100%;
}
.icon-wrapper-gradient {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    background: linear-gradient(135deg, rgba(171,10,10,.1) 0%, rgba(128,0,0,.2) 100%);
    color: var(--grc-secondary);
}
    </style>
</head>
<body>

<!-- SIDEBAR -->
<nav id="sidebar" aria-label="Main navigation">
        <div class="sidebar-header">
        <button type="button" id="sidebarLogoToggle" class="sidebar-logo-toggle" aria-label="Toggle sidebar">
            <img src="../assets/grc2.png" alt="GRC Logo" class="logo-expanded">
            <img src="../assets/grc4.png" alt="GRC Logo" class="logo-collapsed">
        </button>
    </div>
    <ul class="sidebar-nav">
        <li class="nav-section-title">ACADEMIC</li>
        <li><a href="#dashboard" class="nav-link active" data-section="dashboard"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
        <li><a href="#review" class="nav-link" data-section="review"><i class="fa-solid fa-clipboard-list"></i><span>Grade Review</span></a></li>
        <li><a href="#masterlist" class="nav-link" data-section="masterlist"><i class="fa-solid fa-users"></i><span>Master List</span></a></li>
        <li><a href="#financial-submissions" class="nav-link" data-section="financial-submissions"><i class="fa-solid fa-money-check-dollar"></i><span>Financial Submissions</span></a></li>
        <li class="nav-section-title">COMMUNICATION</li>
        <li><a href="#announcements" class="nav-link" data-section="announcements"><i class="fa-solid fa-bullhorn"></i><span>Announcements</span></a></li>
        <li><a href="#directory" class="nav-link" data-section="directory"><i class="fa-solid fa-users-gear"></i><span>Directory</span></a></li>
    </ul>
    <div class="sidebar-footer">
        <ul class="sidebar-nav">
             <li><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="fa-solid fa-user-circle"></i><span>Profile</span></a></li>
             <li><a href="../logout.php" class="nav-link"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a></li>
        </ul>
    </div>
</nav>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- TOP HEADER -->
        <header class="top-header">
        <div class="header-left">
            <h1 class="header-title">Dashboard</h1>
        </div>
        <div class="header-right">
            <button class="bell-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#announcementsDrawer">
                <i class="fa-solid fa-bell fs-6"></i>
                <span id="notifBadge" class="badge bg-danger bell-badge" style="<?= $total_notifications > 0 ? '' : 'display:none;' ?>"><?= $total_notifications ?></span>
            </button>
            <!-- Profile dropdown is now in the sidebar footer, so this can be removed or kept simple -->
        </div>
    </header>

    <!-- This is where your old content container started -->
    <div class="container-fluid p-0">
<div class="container-fluid px-lg-5 py-4">

    <div class="dashboard-hero mb-4 d-flex justify-content-between align-items-center">
        <div>
            <span class="badge bg-white text-danger fw-bold rounded-pill px-3 py-1 mb-2 shadow-sm">Admin Module</span>
            <h2 class="fw-extrabold mb-1">Welcome back, <?= htmlspecialchars($display_name) ?>!</h2>
            <p class="mb-0 text-white-50 small">
                Admin ID: <?= htmlspecialchars($current_admin_code ?: 'N/A') ?> · Department: <?= htmlspecialchars($user_data['department_course'] ?? 'Administration') ?>
            </p>
        </div>
        <div class="text-end d-none d-md-block">
            <h6 class="mb-0 text-white-50 small text-uppercase fw-semibold"><?= date('l') ?></h6>
            <h3 class="fw-bold mb-0"><?= date('F j, Y') ?></h3>
        </div>
    </div>

    <!-- ==================== ADMIN DASHBOARD OVERVIEW ==================== -->
    <div class="dashboard-section active" id="dashboard">
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3"><i class="fa-solid fa-clipboard-list"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Pending Grade Reviews</span>
                        <h4 class="fw-bold text-dark mb-0"><?= $pending_submissions_count ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background:rgba(255,193,7,.15);color:#ca8a04;"><i class="fa-solid fa-money-check-dollar"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Pending Financial</span>
                        <h4 class="fw-bold text-dark mb-0"><?= count($financial_pending) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background:rgba(59,130,246,.15);color:#2563eb;"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Master List Students</span>
                        <h4 class="fw-bold text-dark mb-0"><?= count($masterlist_students) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background:rgba(25,135,84,.15);color:#198754;"><i class="fa-solid fa-bullhorn"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Active Announcements</span>
                        <h4 class="fw-bold text-dark mb-0"><?= $ann_count ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card-custom p-4 h-100">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-bolt text-danger me-2"></i>Quick Actions</h5>
                    <div class="d-grid gap-2">
                        <a href="#review" class="btn btn-outline-danger text-start py-3" data-section="review">
                            <i class="fa-solid fa-clipboard-list me-2"></i> Review Grade Submissions
                            <?php if ($pending_submissions_count > 0): ?>
                                <span class="badge bg-danger ms-2"><?= $pending_submissions_count ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="#financial-submissions" class="btn btn-outline-danger text-start py-3" data-section="financial-submissions">
                            <i class="fa-solid fa-money-check-dollar me-2"></i> Review Financial Submissions
                            <?php if (count($financial_pending) > 0): ?>
                                <span class="badge bg-warning text-dark ms-2"><?= count($financial_pending) ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="#announcements" class="btn btn-outline-danger text-start py-3" data-section="announcements">
                            <i class="fa-solid fa-bullhorn me-2"></i> Post New Announcement
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card-custom p-4 h-100">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-clock-rotate-left text-danger me-2"></i>Recent Activity</h5>
                    <div class="list-group list-group-flush">
                        <?php
                        $recent_notifs = array_slice($notification_list, 0, 5);
                        if (count($recent_notifs) > 0):
                            foreach ($recent_notifs as $n):
                        ?>
                            <div class="list-group-item px-0 py-2 border-0">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong class="text-dark small"><?= htmlspecialchars($n['title']) ?></strong>
                                        <p class="small text-muted mb-0"><?= htmlspecialchars(mb_strimwidth($n['message'], 0, 60, '...')) ?></p>
                                    </div>
                                    <small class="text-muted"><?= date('M d', strtotime($n['created_at'])) ?></small>
                                </div>
                            </div>
                        <?php endforeach; else: ?>
                            <p class="text-muted small text-center py-3">No recent activity.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== GRADE REVIEW QUEUE ==================== -->
    <div class="dashboard-section" id="review">
    <div class="card-custom p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-clipboard-list text-danger me-2"></i>Faculty Submissions for Admin ID: <?= htmlspecialchars($current_admin_code ?: 'N/A') ?></h5>
            <?php if ($pending_submissions_count > 0): ?>
                <span class="badge bg-danger fs-6"><?= $pending_submissions_count ?> pending</span>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle small border rounded">
                <thead class="table-light">
                    <tr>
                        <th>Student Full Name & ID</th>
                        <th>Faculty</th>
                        <th>Target Admin ID</th>
                        <th>Submitted At</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($submissions_list) > 0): ?>
                    <?php foreach ($submissions_list as $sub):
                        $st_full = trim($sub['first_name'] . ' ' . $sub['last_name']);
                        $sub_json = htmlspecialchars(json_encode($sub), ENT_QUOTES, 'UTF-8');
                        $status = $sub['submission_status'] ?: 'submitted';
                        $badge = $status === 'submitted' ? 'bg-warning text-dark' : ($status === 'released' ? 'bg-success' : 'bg-secondary');
                    ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-dark"><?= htmlspecialchars($st_full) ?></span><br>
                                <small class="text-muted">ID: <?= htmlspecialchars($sub['stud_code']) ?></small>
                            </td>
                            <td><small class="fw-semibold"><?= htmlspecialchars($sub['faculty_name']) ?></small></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($sub['admin_target_id']) ?></span></td>
                            <td><small class="text-muted"><?= date('M d, Y h:i A', strtotime($sub['created_at'])) ?></small></td>
                            <td><span class="badge <?= $badge ?>"><?= strtoupper(htmlspecialchars($status)) ?></span></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold view-record-btn" data-record='<?= $sub_json ?>'>
                                    <i class="fa-solid fa-eye me-1"></i> Review
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">No submissions routed to your Admin ID yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== FINANCIAL SUBMISSIONS (ADMIN) ==================== -->
<div class="dashboard-section" id="financial-submissions">
    <div class="card-custom p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-dark mb-0">
            <i class="fa-solid fa-money-check-dollar text-danger me-2"></i>Financial Submissions
        </h5>
        <span class="badge bg-warning text-dark fs-6"><?= count($financial_pending) ?> pending</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle small">
            <thead class="table-light">
                <tr>
                    <th>Submitted</th><th>Student</th><th>Fee</th><th>Description</th>
                    <th class="text-end">Amount</th><th>Finance</th><th>Status</th><th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($financial_pending) === 0): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No pending financial submissions.</td></tr>
            <?php else: foreach ($financial_pending as $f): ?>
                <tr>
                    <td><small class="text-muted"><?= $f['submitted_at'] ? date('M d, Y h:i A', strtotime($f['submitted_at'])) : '—' ?></small></td>
                    <td>
                        <span class="fw-bold"><?= htmlspecialchars($f['stud_name']) ?></span><br>
                        <small class="text-muted"><?= htmlspecialchars($f['stud_code']) ?></small>
                    </td>
                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($f['fee_category'] ?? $f['transaction_type']) ?></span></td>
                    <td><?= htmlspecialchars($f['description']) ?></td>
                    <td class="text-end fw-bold">₱<?= number_format((float)$f['amount'], 2) ?></td>
                    <td><small><?= htmlspecialchars($f['finance_username'] ?? '—') ?></small></td>
                    <td><span class="badge bg-warning text-dark">SUBMITTED</span></td>
                    <td class="text-end text-nowrap">
                        <form method="POST" class="d-inline js-confirm-form"
      data-confirm-title="Approve Financial Record"
      data-confirm-message="The student will see this financial record immediately upon approval."
      data-confirm-type="warning"
      data-confirm-button="Approve">
                            <?= csrf_field() ?>
                            <input type="hidden" name="financial_action" value="approve">
                            <input type="hidden" name="transaction_id" value="<?= (int)$f['id'] ?>">
                            <button class="btn btn-sm btn-success rounded-pill px-3"><i class="fa-solid fa-check me-1"></i>Approve</button>
                        </form>
                        <button class="btn btn-sm btn-outline-info rounded-pill px-3"
                                onclick="openReturnFin(<?= (int)$f['id'] ?>, <?= json_encode($f['reference_no'] ?? '', JSON_HEX_APOS|JSON_HEX_QUOT) ?>)">
                            <i class="fa-solid fa-rotate-left me-1"></i>Return
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <h6 class="fw-bold text-dark mt-4 mb-2">Recently Reviewed</h6>
    <div class="table-responsive" style="max-height:300px;overflow:auto;">
        <table class="table table-sm align-middle small">
            <thead class="table-light"><tr><th>Reviewed</th><th>Student</th><th>Description</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                        <tbody>
            <?php if (count($financial_history) === 0): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-3">No reviewed records yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($financial_history as $f):
                    $b = $f['status'] === 'approved' ? 'bg-success' : 'bg-info text-dark';
                ?>
                    <tr>
                        <td>
                            <small class="text-muted">
                                <?= $f['reviewed_at']
                                        ? date('M d, Y h:i A', strtotime($f['reviewed_at']))
                                        : '—' ?>
                            </small>
                        </td>
                        <td>
                            <?= htmlspecialchars($f['stud_name']) ?>
                            <small class="text-muted">(<?= htmlspecialchars($f['stud_code']) ?>)</small>
                        </td>
                        <td><?= htmlspecialchars($f['description']) ?></td>
                        <td class="text-end">₱<?= number_format((float)$f['amount'], 2) ?></td>
                        <td>
                            <span class="badge <?= $b ?>"><?= strtoupper($f['status']) ?></span>
                            <?php if ($f['status'] === 'returned' && !empty($f['admin_remarks'])): ?>
                                <div class="small text-muted mt-1"><?= htmlspecialchars($f['admin_remarks']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- RETURN MODAL (append to the bottom of admin/dashboard.php) -->
<div class="modal fade" id="returnFinModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header text-white" style="background:var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-rotate-left me-2 text-warning"></i>Return Financial Record to Finance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="financial_action" value="return">
                <input type="hidden" name="transaction_id" id="returnFinId">
                <div class="modal-body p-4">
                    <p class="small mb-2">Record: <strong id="returnFinRef"></strong></p>
                    <label class="form-label small fw-semibold">Admin Remarks</label>
                    <textarea name="admin_remarks" class="form-control" rows="3" required
                              placeholder="e.g. Incorrect tuition amount. Please verify the student's assessment."></textarea>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm rounded-pill px-4">Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- ==================== MASTER LIST ==================== -->
    <div class="dashboard-section" id="masterlist">
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card-custom p-4 h-100">
                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-user-plus text-danger me-2"></i>Add Student to Master List</h5>
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Student ID Number</label>
                        <input type="text" name="student_id" class="form-control form-control-sm" required placeholder="e.g. 2024-01-00001">
                    </div>
                    <div class="mb-3"><label class="form-label small fw-semibold text-dark">First Name</label><input type="text" name="first_name" class="form-control form-control-sm" required></div>
                    <div class="mb-3"><label class="form-label small fw-semibold text-dark">Last Name</label><input type="text" name="last_name" class="form-control form-control-sm" required></div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Department</label>
                        <select name="department" class="form-select form-select-sm" required>
                            <option value="">-- Select Department --</option>
                            <option value="College of Computer Studies">College of Computer Studies</option>
                            <option value="College of Business Administration">College of Business Administration</option>
                            <option value="College of Education">College of Education</option>
                            <option value="College of Accountancy">College of Accountancy</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label small fw-semibold text-dark">Course</label><input type="text" name="course" class="form-control form-control-sm" placeholder="e.g. BSIT"></div>
                    <button type="submit" name="add_student_masterlist" value="1" class="btn btn-grc-action w-100 py-2"><i class="fa-solid fa-plus me-1"></i> Register to Master List</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card-custom p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-people-fill text-danger me-2"></i>Master List Students</h5>
                    <span class="badge bg-danger"><?= count($masterlist_students) ?> Total</span>
                </div>
                <div class="mb-3">
                    <input type="text" id="masterlistSearch" class="form-control" placeholder="Search by ID, Name, Department, or Course...">
                </div>
                <div class="table-responsive" style="max-height:400px;overflow:auto;">
                    <table class="table table-hover align-middle small mb-0" id="masterlistTable">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th>Student ID</th><th>Name</th><th>Department</th><th>Course</th><th>Status</th><th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($masterlist_students as $student): ?>
                            <tr>
                                <td class="fw-bold text-danger"><?= htmlspecialchars($student['student_id']) ?></td>
                                <td><?= htmlspecialchars($student['first_name'].' '.$student['last_name']) ?></td>
                                <td><?= htmlspecialchars($student['department'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($student['course'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if ($student['is_registered']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="POST" class="m-0 js-confirm-form"
      data-confirm-title="Remove Student"
      data-confirm-message="Are you sure you want to remove this student from the Master List?"
      data-confirm-type="danger"
      data-confirm-button="Remove">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="masterlist_id" value="<?= $student['id'] ?>">
                                        <button type="submit" name="delete_masterlist_student" class="btn btn-outline-danger btn-sm rounded-circle"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

    <!-- ==================== ANNOUNCEMENTS ==================== -->
    <div class="dashboard-section" id="announcements">
    <div class="card-custom p-4 mb-4">
        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-bullhorn me-2 text-danger"></i>Broadcast New Announcement</h5>
        <form method="POST" class="js-confirm-form"
      data-confirm-title="Publish Announcement"
      data-confirm-message="This will publish the announcement to all portal users. Continue?"
      data-confirm-type="warning"
      data-confirm-button="Publish">
            <?= csrf_field() ?>
            <input type="hidden" name="post_announcement" value="1">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-dark">Category</label>
                    <select name="category" class="form-select form-select-sm">
                        <?php foreach (['General','Academic','Enrollment','Examination','Event','Scholarship','System Maintenance','Emergency'] as $c): ?>
                            <option value="<?= $c ?>"><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-dark">Priority</label>
                    <select name="priority" class="form-select form-select-sm">
                        <option value="Normal">Normal</option><option value="Important">Important</option><option value="Urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <div class="mb-3"><label class="form-label small fw-semibold text-dark">Title</label><input type="text" name="title" class="form-control form-control-sm" required maxlength="180"></div>
            <div class="mb-3"><label class="form-label small fw-semibold text-dark">Content</label><textarea name="content" class="form-control form-control-sm" rows="4" required></textarea></div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_pinned" id="pinAnn" value="1">
                <label class="form-check-label small fw-semibold" for="pinAnn"><i class="fa-solid fa-pin text-danger me-1"></i>Pin this announcement</label>
            </div>
            <button type="submit" class="btn btn-grc-action btn-sm px-4 py-2 fw-bold"><i class="fa-solid fa-share-from-square me-1"></i> Publish</button>
        </form>
    </div>

    <div class="card-custom p-4 mb-4">
        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list text-danger me-2"></i>Existing Announcements</h5>
        <?php foreach ($announcements_list as $ann):
            $p = $ann['priority'] ?? 'Normal';
            $pClass = $p === 'Urgent' ? 'bg-danger' : ($p === 'Important' ? 'bg-warning text-dark' : 'bg-secondary');
        ?>
            <div class="card mb-2 border-0 shadow-sm rounded-3">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-1 gap-1 flex-wrap">
                        <strong class="text-dark small">
                            <?php if (!empty($ann['is_pinned'])): ?><i class="fa-solid fa-pin text-danger me-1"></i><?php endif; ?>
                            <?= htmlspecialchars($ann['title']) ?>
                        </strong>
                        <div class="d-flex gap-1">
                            <span class="badge bg-light text-dark border" style="font-size:.65rem;"><?= htmlspecialchars($ann['category'] ?? 'General') ?></span>
                            <span class="badge <?= $pClass ?>" style="font-size:.65rem;"><?= htmlspecialchars($p) ?></span>
                        </div>
                    </div>
                    <p class="small text-muted mb-2"><?= htmlspecialchars($ann['content']) ?></p>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-secondary fw-semibold" style="font-size:.72rem;">— <?= htmlspecialchars($ann['posted_by']) ?> · <?= date('M d, Y', strtotime($ann['created_at'])) ?></small>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2 text-dark" style="font-size:.75rem;" onclick="editAnnouncement(<?= $ann['id'] ?>, '<?= htmlspecialchars($ann['title'], ENT_QUOTES) ?>', '<?= htmlspecialchars($ann['content'], ENT_QUOTES) ?>')"><i class="fa-solid fa-pen"></i></button>
                            <form method="POST" class="d-inline js-confirm-form"
      data-confirm-title="Delete Announcement"
      data-confirm-message="Are you sure you want to permanently delete this announcement?"
      data-confirm-type="danger"
      data-confirm-button="Delete">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_announcement" value="1">
                                <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.75rem;"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

    <!-- ==================== USER DIRECTORY ==================== -->
    <div class="dashboard-section" id="directory">
    <div class="card-custom p-4">
        <h5 class="fw-bold text-dark mb-4"><i class="fa-solid fa-users-gear me-2 text-danger"></i>User Directory</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle small">
                <thead class="table-dark">
                    <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>ID Code</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users_list as $u): ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border">#<?= $u['id'] ?></span></td>
                        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="badge rounded-pill px-3" style="background:var(--grc-primary);"><?= htmlspecialchars($u['role']) ?></span></td>
                        <td><small class="fw-bold"><?= htmlspecialchars($u['student_id'] ?? '—') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== STUDENT RECORD REVIEW MODAL ==================== -->
<div class="modal fade" id="studentRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">

            <!-- Header -->
            <div class="modal-header text-white" style="background:var(--grc-dark);">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-file-lines me-2 text-danger"></i>Grade Record Review
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <!-- Body — populated by JS -->
            <div class="modal-body p-4" id="printableModalContent"></div>

            <!-- Footer -->
            <div class="modal-footer bg-light justify-content-between flex-wrap gap-2">

                <!-- Approve & Release -->
<form id="sendRecordForm" class="d-flex align-items-center gap-2"
      onsubmit="sendRecordToStudentDashboard(event)">

    <!-- NEW: carries the selected submission's id for the Print button -->
    <input type="hidden" id="modalSubmissionId" value="">

    <input type="text" id="targetStudentIdInput" name="target_student_id"
           class="form-control form-control-sm"
           placeholder="Enter Target Student ID"
           required style="min-width:200px;">
    <button type="submit" class="btn btn-grc-action btn-sm text-nowrap fw-semibold">
        <i class="fa-solid fa-paper-plane me-1"></i> APPROVE &amp; RELEASE
    </button>
</form>

                <!-- Delete / Print / Close -->
                <div class="d-flex align-items-center gap-2">
                    <form id="deletePermanentForm" method="POST" class="m-0 js-confirm-form"
      data-confirm-title="Permanently Delete Grade Record"
      data-confirm-message="This action will permanently delete the selected academic record. This cannot be undone."
      data-confirm-type="danger"
      data-confirm-button="Delete Permanently">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_academic_record_permanent" value="1">
                        <input type="hidden" name="record_id" id="modalDeleteRecordId" value="">
                        <button type="submit"
                                class="btn btn-danger btn-sm rounded-pill px-3 fw-semibold"
                                id="modalDeleteBtn"
                                disabled
                                title="Select a row first">
                            <i class="fa-solid fa-trash-can me-1"></i> Delete
                        </button>
                    </form>

                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-semibold"
        onclick="printSelectedSubmission()">
    <i class="fa-solid fa-print me-1"></i> Print
</button>

                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4"
                            data-bs-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== RETURN TO FACULTY MODAL ==================== -->
<div class="modal fade" id="returnFacultyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header text-white" style="background:var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-rotate-left me-2 text-warning"></i>Return Submission to Faculty</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="return_submission_to_faculty" value="1">
                <input type="hidden" name="submission_id" id="returnSubmissionId">
                <div class="modal-body p-4">
                    <label class="form-label small fw-semibold">Reason (optional)</label>
                    <textarea name="return_reason" class="form-control" rows="3" placeholder="e.g. Please re-check the midterm score."></textarea>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm rounded-pill px-4 fw-semibold">Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== EDIT ANNOUNCEMENT MODAL ==================== -->
<div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header text-white" style="background:var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Edit Announcement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="edit_announcement" value="1">
                <input type="hidden" name="ann_id" id="editAnnId">
                <div class="modal-body p-4">
                    <div class="mb-3"><label class="form-label small fw-semibold">Title</label><input type="text" name="ann_title" id="editAnnTitle" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Content</label><textarea name="ann_content" id="editAnnContent" class="form-control" rows="4" required></textarea></div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-grc-action btn-sm rounded-pill px-4">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== PROFILE MODAL ==================== -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <div class="modal-header text-white" style="background:linear-gradient(135deg,var(--grc-dark) 0%,var(--grc-secondary) 100%);border-radius:20px 20px 0 0;">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-gear me-2"></i>My Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-4">
                    <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex p-3 text-danger mb-2"><i class="fa-solid fa-user-circle fa-3x"></i></div>
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($display_name) ?></h5>
                    <span class="badge px-3 py-1 rounded-pill mt-1" style="background:var(--grc-primary);"><?= htmlspecialchars($user_data['role']) ?></span>
                </div>
                <div class="list-group list-group-flush border-top border-bottom">
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Admin / Dept ID:</span><span class="fw-bold small"><?= htmlspecialchars($current_admin_code ?: 'N/A') ?></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Username:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['username']) ?></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Email:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['email']) ?></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Phone:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['phone'] ?? 'N/A') ?></span></div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0"><button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- ==================== NOTIFICATIONS OFFCANVAS ==================== -->
<div class="offcanvas offcanvas-end" style="width:480px;" tabindex="-1" id="announcementsDrawer">
    <div class="offcanvas-header text-white" style="background:var(--grc-dark);border-bottom:3px solid var(--grc-secondary);">
        <h5 class="offcanvas-title fw-bold"><i class="fa-solid fa-bell text-danger me-2"></i>Notifications</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <ul class="nav nav-tabs nav-justified bg-light border-bottom" role="tablist">
        <li class="nav-item"><button class="nav-link active fw-semibold text-dark py-2" data-bs-toggle="tab" data-bs-target="#drawerNotifications" type="button"><i class="fa-solid fa-bell me-1 text-danger"></i> For You <span id="tabNotifBadge" class="badge bg-danger rounded-pill ms-1" style="<?= $total_notifications > 0 ? '' : 'display:none;' ?>"><?= $total_notifications ?></span></button></li>
<li class="nav-item"><button class="nav-link fw-semibold text-dark py-2" data-bs-toggle="tab" data-bs-target="#drawerInbox" type="button"><i class="fa-solid fa-comments me-1 text-danger"></i> Messages <span id="tabMessagesBadge" class="badge bg-danger rounded-pill ms-1" style="<?= $unread_msg_count > 0 ? '' : 'display:none;' ?>"><?= $unread_msg_count ?></span></button></li>
        <li class="nav-item"><button class="nav-link fw-semibold text-dark py-2" data-bs-toggle="tab" data-bs-target="#drawerBroadcasts" type="button"><i class="fa-solid fa-bullhorn me-1 text-danger"></i> Broadcasts</button></li>
    </ul>
    <div class="offcanvas-body bg-light">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="drawerNotifications">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0 text-dark">Your Notifications</h6>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="markAllNotificationsRead()"><i class="fa-solid fa-check-double me-1"></i> Mark all read</button>
                </div>
                <div id="notifListContainer" class="overflow-auto" style="max-height:560px;">
                    <?php if (count($notification_list)>0): ?>
                        <?php foreach ($notification_list as $n): $unread = empty($n['is_read']); ?>
                            <div class="card mb-2 border-0 shadow-sm rounded-3 notif-item <?= $unread ? 'notif-unread' : 'notif-read' ?>" data-notif-id="<?= (int)$n['id'] ?>">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between mb-1 gap-2">
                                        <strong class="text-dark small"><?= htmlspecialchars($n['title']) ?><?php if ($unread): ?> <span class="badge bg-danger ms-1" style="font-size:.6rem;">NEW</span><?php endif; ?></strong>
                                        <small class="text-muted" style="font-size:.7rem;"><?= date('M d, h:i A', strtotime($n['created_at'])) ?></small>
                                    </div>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($n['message']) ?></p>
                                    <div class="d-flex justify-content-end gap-1">
                                        <?php if ($unread): ?><button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" onclick="markNotificationRead(<?= (int)$n['id'] ?>)"><i class="fa-solid fa-check me-1"></i>Mark read</button><?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-circle" onclick="deleteNotification(<?= (int)$n['id'] ?>)"><i class="fa-solid fa-trash"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="small text-muted text-center py-5">No notifications yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="drawerInbox">
                <div id="drawerStudentListView">
                    <h6 class="fw-bold mb-3 text-dark">Student Requests</h6>
                    <div class="list-group shadow-sm overflow-auto" style="max-height:450px;">
                        <?php foreach ($threads as $th): $st_name = trim($th['first_name'].' '.$th['last_name']); if ($st_name==='') $st_name = "Student #".$th['id']; ?>
    <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-start p-3"
         role="button"
         style="cursor:pointer;"
         onclick="openAdminChatThread(<?= $th['id'] ?>, '<?= htmlspecialchars($st_name, ENT_QUOTES) ?>')">
        <div class="ms-2 me-auto text-truncate" style="max-width:260px;">
            <div class="fw-bold text-dark"><?= htmlspecialchars($st_name) ?></div>
            <small class="text-muted text-truncate d-block"><?= htmlspecialchars($th['last_msg'] ?? 'No messages') ?></small>
        </div>
        <div class="d-flex align-items-center gap-2" onclick="event.stopPropagation();">
            <?php if ($th['unread_count']>0): ?>
                <span class="badge bg-danger rounded-pill"><?= (int)$th['unread_count'] ?></span>
            <?php endif; ?>
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-circle"
                    title="Delete conversation"
                    onclick="deleteStudentConversation(event, <?= (int)$th['id'] ?>)">
                <i class="fa-solid fa-trash"></i>
            </button>
        </div>
    </div>
<?php endforeach; ?>
                        <?php if (count($threads) === 0): ?><div class="p-4 text-center text-muted small">No message threads found.</div><?php endif; ?>
                    </div>
                </div>
                <div id="drawerChatThreadView" class="d-none">
                    <button class="btn btn-sm btn-link text-danger p-0 mb-3 fw-semibold text-decoration-none" onclick="closeAdminChatThread()"><i class="fa-solid fa-arrow-left me-1"></i> Back to Messages</button>
                    <h6 class="fw-bold text-dark mb-2" id="adminThreadTitle">Chat Session</h6>
                    <div class="p-3 bg-white rounded border mb-3 overflow-auto" id="adminChatContainer" style="height:320px;"></div>
                    <form onsubmit="sendAdminMessage(event)">
                        <div class="input-group">
                            <input type="text" id="adminChatMessage" class="form-control form-control-sm" placeholder="Type reply..." required maxlength="2000">
                            <button type="submit" class="btn btn-grc-action btn-sm px-3"><i class="fa-solid fa-paper-plane"></i></button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="tab-pane fade" id="drawerBroadcasts">
                <h6 class="fw-bold text-dark mb-3">System Broadcasts</h6>
                <div class="overflow-auto" style="max-height:500px;">
                    <?php foreach ($announcements_list as $ann): $p = $ann['priority'] ?? 'Normal'; $pClass = $p === 'Urgent' ? 'bg-danger' : ($p === 'Important' ? 'bg-warning text-dark' : 'bg-secondary'); ?>
                        <div class="card mb-3 border-0 shadow-sm rounded-3">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between mb-1 gap-1 flex-wrap">
                                    <strong class="text-dark small"><?php if (!empty($ann['is_pinned'])): ?><i class="fa-solid fa-pin text-danger me-1"></i><?php endif; ?><?= htmlspecialchars($ann['title']) ?></strong>
                                    <div class="d-flex gap-1">
                                        <span class="badge bg-light text-dark border" style="font-size:.65rem;"><?= htmlspecialchars($ann['category'] ?? 'General') ?></span>
                                        <span class="badge <?= $pClass ?>" style="font-size:.65rem;"><?= htmlspecialchars($p) ?></span>
                                    </div>
                                </div>
                                <p class="small text-muted mb-2"><?= htmlspecialchars($ann['content']) ?></p>
                                <small class="text-secondary fw-semibold" style="font-size:.72rem;">— <?= htmlspecialchars($ann['posted_by']) ?> · <?= date('M d, Y', strtotime($ann['created_at'])) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/ui-feedback.js"></script>
<script>
<?php if (!empty($_SESSION['msg'])): ?>
    showToastFromSession(<?= json_encode($_SESSION['msg']) ?>, <?= json_encode($_SESSION['msg_type'] ?? 'info') ?>);
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    <?php unset($_SESSION['msg'], $_SESSION['msg_type']); ?>
<?php endif; ?>

const GRADE_LIMIT = <?= json_encode($grade_limit) ?>;

/* ---------- Grade helpers ---------- */
function getGradeStatus(v){
    if (v === null || v === undefined || v === '' || v === '—' || v === '-') return {className:'text-muted'};
    const n = parseFloat(v);
    if (Number.isNaN(n)) return {className:'text-muted'};
    const r = Math.round(n * 100) / 100;
    return { className: r >= GRADE_LIMIT ? 'text-danger' : 'text-success' };
}
function gradeColorClass(v){ return getGradeStatus(v).className; }

function escapeHtml(t){
    if (!t) return '';
    return String(t)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

/* Click a row → mark it selected, update the hidden record_id,
   enable the Delete button, and show the subject code in its title. */
function selectGradeRow(el){
    if (!el) return;
    document.querySelectorAll('#printableModalContent .selectable-row')
            .forEach(r => r.classList.remove('selected-row'));
    el.classList.add('selected-row');

    const id    = el.getAttribute('data-record-id') || '';
    const code  = el.getAttribute('data-subject-code') || '';
    const input = document.getElementById('modalDeleteRecordId');
    const btn   = document.getElementById('modalDeleteBtn');

    if (input) input.value = id;
    if (btn) {
        btn.disabled = (id === '');
        btn.title    = code ? ('Permanently delete ' + code) : 'Delete the selected record';
    }
}

/* ---------- Admin Grade Record Review: open official print document ---------- */
function printSelectedSubmission(){
    const subId = document.getElementById('modalSubmissionId')?.value;
    if (!subId) {
        showFloatingToast('No Record', 'Select a submission first.', 'warning');
        return;
    }
    // Server-side auth re-checks ownership: only the admin who received the
    // submission (or whose admin_target_id matches) can print it.
    window.open('../print.php?type=admin_grade_review&submission_id=' + encodeURIComponent(subId), '_blank');
}

/* ---------- Grade Record Review modal ---------- */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.view-record-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const data = JSON.parse(btn.getAttribute('data-record'));

            const fullName     = `${data.first_name} ${data.last_name}`;
            const studentId    = data.stud_code;
            const gwa          = data.gwa;
            const records      = data.records || [];

            const targetInput = document.getElementById('targetStudentIdInput');
            if (targetInput) targetInput.value = studentId;

            let recordsHtml = '';
            if (records.length > 0) {
                records.forEach((r, idx) => {
                    const released = Number(r.is_released) === 1;
                    const badge = released
                        ? '<span class="badge bg-success-subtle text-success border border-success-subtle ms-2" style="font-size:.65rem;">RELEASED</span>'
                        : '<span class="badge bg-warning-subtle text-dark border border-warning-subtle ms-2" style="font-size:.65rem;">PENDING</span>';

                    recordsHtml += `
                        <tr class="selectable-row${idx === 0 ? ' selected-row' : ''}"
                            data-record-id="${Number(r.id)}"
                            data-subject-code="${escapeHtml(r.subject_code)}"
                            onclick="selectGradeRow(this)">
                            <td><span class="badge bg-light text-dark border fw-bold">${escapeHtml(r.subject_code)}</span>${badge}</td>
                            <td class="fw-semibold text-dark">${escapeHtml(r.subject_title)}</td>
                            <td><small class="text-muted">${escapeHtml(r.semester)}</small></td>
                            <td><small class="text-muted">${escapeHtml(r.academic_year || '')}</small></td>
                            <td class="text-end fw-bold ${gradeColorClass(r.grade)} fs-6">${Number(r.grade).toFixed(2)}</td>
                        </tr>`;
                });
            } else {
                recordsHtml = `<tr><td colspan="5" class="text-center text-muted py-4">No grade records found for this student.</td></tr>`;
            }

            const body = document.getElementById('printableModalContent');
            body.innerHTML = `
                <div class="p-3 bg-light rounded-3 border mb-4 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-semibold d-block">Student Name &amp; ID:</span>
                        <h5 class="fw-bold text-dark mb-0">${escapeHtml(fullName)} (${escapeHtml(studentId)})</h5>
                    </div>
                    <div class="text-end">
                        <span class="text-muted small fw-semibold d-block">Computed GWA:</span>
                        <h3 class="fw-bold ${gradeColorClass(gwa)} mb-0">${escapeHtml(String(gwa))}</h3>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle small mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Title</th>
                                <th>Semester</th>
                                <th>Academic Year</th>
                                <th class="text-end">Final Grade</th>
                            </tr>
                        </thead>
                        <tbody>${recordsHtml}</tbody>
                    </table>
                </div>`;
            
            // Store the submission id so the Print button can open the correct document.
            document.getElementById('modalSubmissionId').value = data.id;

            const modalEl = document.getElementById('studentRecordModal');
            new bootstrap.Modal(modalEl).show();
            modalEl.addEventListener('shown.bs.modal', () => {
                const firstRow = body.querySelector('.selectable-row');
                if (firstRow) selectGradeRow(firstRow);
            }, { once: true });
        });
    });
});

/* ---------- Delete academic record permanently ---------- */
document.getElementById('deletePermanentForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('modalDeleteRecordId').value;
    if (!id) return;

    const confirmed = await showConfirmDialog({
        title: 'Permanently Delete Grade Record',
        message: 'This action cannot be undone. Are you sure you want to continue?',
        type: 'danger',
        confirmText: 'Delete Permanently'
    });
    if (!confirmed) return;

    const fd = new FormData(this);
    fetch('dashboard.php', { method: 'POST', body: fd })
        .then(() => {
            const modalEl = document.getElementById('studentRecordModal');
            const inst = bootstrap.Modal.getInstance(modalEl);
            if (inst) inst.hide();
            location.reload();
        })
        .catch(err => {
            console.error(err);
            showFloatingToast('Network Error', 'Network error while deleting. Please try again.', 'danger');
        });
});

/* ---------- Financial return modal ---------- */
function openReturnFin(id, ref){
    document.getElementById('returnFinId').value = id;
    document.getElementById('returnFinRef').textContent = ref || ('#'+id);
    new bootstrap.Modal(document.getElementById('returnFinModal')).show();
}

/* ---------- Release (AJAX) ---------- */
function sendRecordToStudentDashboard(e){
    e.preventDefault();
    const studentId = document.getElementById('targetStudentIdInput').value.trim();
    if (!studentId){
        showFloatingToast('Input Required', 'Please enter the Target Student ID.', 'warning');
        return;
    }
    const fd = new FormData();
    fd.append('send_record_target_id', studentId);
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    fetch('dashboard.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                showFloatingToast('Success', d.message, 'success');
            } else if (d.status === 'info') {
                showFloatingToast('Information', d.message, 'info');
            } else {
                showFloatingToast('Error', d.message, 'danger');
            }
            if (d.status === 'success' || d.status === 'info'){
                const modal = bootstrap.Modal.getInstance(document.getElementById('studentRecordModal'));
                if (modal) modal.hide();
                location.reload();
            }
        })
        .catch(err => {
            console.error(err);
            showFloatingToast('Network Error', 'A network error occurred. Please try again.', 'danger');
        });
}

/* ---------- Announcements edit ---------- */
function editAnnouncement(id, title, content){
    document.getElementById('editAnnId').value = id;
    document.getElementById('editAnnTitle').value = title;
    document.getElementById('editAnnContent').value = content;
    new bootstrap.Modal(document.getElementById('editAnnouncementModal')).show();
}

/* =========================================================================
   ADMIN CHAT — synchronized with notifications
   ========================================================================= */
let currentAdminChatUserId = null;
let adminChatInterval = null;

function openAdminChatThread(userId, name){
    currentAdminChatUserId = userId;
    document.getElementById('drawerStudentListView').classList.add('d-none');
    document.getElementById('drawerChatThreadView').classList.remove('d-none');
    document.getElementById('adminThreadTitle').textContent = `Chat with ${name}`;

    fetchAdminMessages();                       // marks thread read + renders
    refreshAdminChatThreads();                  // refresh thread list + Messages badge
    refreshNotifCount();                        // refresh bell (chat notifications just became read)

    if (adminChatInterval) clearInterval(adminChatInterval);
    adminChatInterval = setInterval(fetchAdminMessages, 3000);
}

function closeAdminChatThread(){
    if (adminChatInterval) { clearInterval(adminChatInterval); adminChatInterval = null; }
    currentAdminChatUserId = null;
    document.getElementById('drawerChatThreadView').classList.add('d-none');
    document.getElementById('drawerStudentListView').classList.remove('d-none');
    refreshAdminChatThreads();
}

async function fetchAdminMessages(){
    if (!currentAdminChatUserId) return;
    try {
        const res  = await fetch(`dashboard.php?chat_action=fetch&target_user_id=${currentAdminChatUserId}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (!res.ok || data.status !== 'success') return;

        const c = document.getElementById('adminChatContainer');
        let html = '';
        (data.messages || []).forEach(m => {
            const me = m.sender_role === 'admin';
            html += `<div class="d-flex flex-column mb-2 ${me?'align-items-end':'align-items-start'}">
                <div class="p-2 rounded-3 text-white small ${me?'bg-danger':'bg-dark'}" style="max-width:80%;">${escapeHtml(m.message)}</div>
                <span class="text-muted" style="font-size:.65rem;">${m.time}</span>
            </div>`;
        });
        c.innerHTML = html;
        c.scrollTop = c.scrollHeight;

        // Server told us the total unread student messages; keep the badge in sync.
        if (typeof data.unread_count === 'number') updateMessagesBadge(data.unread_count);

        // Chat notifications were just marked read on the server.
        refreshNotifCount();
    } catch (err) {
        console.error('fetchAdminMessages:', err);
    }
}

async function sendAdminMessage(e){
    e.preventDefault();
    const input = document.getElementById('adminChatMessage');
    const msg   = input.value.trim();
    if (!msg || !currentAdminChatUserId) return;

    const fd = new FormData();
    fd.append('message', msg);
    fd.append('target_user_id', currentAdminChatUserId);
    fd.append('csrf_token', CSRF_TOKEN);

    try {
        const res  = await fetch('dashboard.php?chat_action=send', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (!res.ok || data.status !== 'success') {
            throw new Error(data.message || 'Unable to send message.');
        }
        input.value = '';
        fetchAdminMessages();
        refreshAdminChatThreads();
    } catch (err) {
        console.error('sendAdminMessage:', err);
        showFloatingToast('Error', err.message || 'Unable to send message.', 'danger');
    }
}

/* =========================================================================
   NOTIFICATIONS — single, clean implementation
   ========================================================================= */
function updateNotifBadge(count){
    ['notifBadge','tabNotifBadge'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (count > 0){ el.textContent = count; el.style.display = ''; }
        else { el.style.display = 'none'; }
    });
}

async function markNotificationRead(id){
    const card = document.querySelector(`.notif-item[data-notif-id="${id}"]`);
    const btn  = card ? card.querySelector('button.btn-outline-success') : null;
    if (btn) btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
        const res = await fetch('dashboard.php?notification_action=read', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        let data;
        try {
            data = await res.json();
        } catch (parseErr) {
            console.error('markNotificationRead: invalid JSON', parseErr);
            throw new Error('Server returned an invalid response.');
        }

        if (!res.ok || data.status !== 'success') {
            throw new Error(data.message || 'Unable to mark notification as read.');
        }

        if (card){
            card.classList.remove('notif-unread');
            card.classList.add('notif-read');
            const badge = card.querySelector('.badge.bg-danger');
            if (badge && badge.textContent.trim() === 'NEW') badge.remove();
            if (btn) btn.remove();
        }
        refreshNotifCount();
    } catch (err) {
        console.error('markNotificationRead:', err);
        showFloatingToast('Error', err.message || 'Unable to mark notification as read.', 'danger');
        if (btn) btn.disabled = false;
    }
}

async function markAllNotificationsRead(){
    const button = document.querySelector('button[onclick="markAllNotificationsRead()"]');
    if (button) button.disabled = true;

    try {
        const fd = new FormData();
        fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
        const res = await fetch('dashboard.php?notification_action=read_all', {
            method: 'POST',
            body: fd,                                          // ← ADD THIS LINE
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        let data;
        try {
            data = await res.json();
        } catch (parseErr) {
            console.error('markAllNotificationsRead: invalid JSON', parseErr);
            throw new Error('Server returned an invalid response.');
        }

        if (!res.ok || data.status !== 'success') {
            throw new Error(data.message || 'Unable to mark notifications as read.');
        }

        document.querySelectorAll('.notif-item.notif-unread').forEach(c => {
            c.classList.remove('notif-unread');
            c.classList.add('notif-read');
            const b = c.querySelector('.badge.bg-danger');
            if (b && b.textContent.trim() === 'NEW') b.remove();
            const btn = c.querySelector('button.btn-outline-success');
            if (btn) btn.remove();
        });

        updateNotifBadge(0);
    } catch (err) {
        console.error('markAllNotificationsRead:', err);
        showFloatingToast('Error', err.message || 'Unable to mark notifications as read.', 'danger');
    } finally {
        if (button) button.disabled = false;
    }
}

async function deleteNotification(id){
    const card = document.querySelector(`.notif-item[data-notif-id="${id}"]`);
    const btn  = card ? card.querySelector('button.btn-outline-danger') : null;

    let confirmed = true;
    if (typeof showConfirmDialog === 'function') {
        confirmed = await showConfirmDialog({
            title: 'Remove Notification',
            message: 'Are you sure you want to remove this notification? The underlying record is not affected.',
            type: 'warning',
            confirmText: 'Remove'
        });
    } else {
        confirmed = window.confirm('Remove this notification?');
    }
    if (!confirmed) return;

    if (btn) btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
        const res = await fetch('dashboard.php?notification_action=delete', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        let data;
        try {
            data = await res.json();
        } catch (parseErr) {
            console.error('deleteNotification: invalid JSON', parseErr);
            throw new Error('Server returned an invalid response.');
        }

        if (!res.ok || data.status !== 'success') {
            throw new Error(data.message || 'Unable to delete notification.');
        }

        if (card) card.remove();
        const list = document.getElementById('notifListContainer');
        if (list && list.querySelectorAll('.notif-item').length === 0){
            list.innerHTML = '<p class="small text-muted text-center py-5">No notifications yet.</p>';
        }
        refreshNotifCount();
    } catch (err) {
        console.error('deleteNotification:', err);
        showFloatingToast('Error', err.message || 'Unable to delete notification.', 'danger');
        if (btn) btn.disabled = false;
    }
}

async function refreshNotifCount(){
    try {
        const res = await fetch('dashboard.php?notification_action=count', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (data && data.status === 'success') updateNotifBadge(data.count);
    } catch (err) {
        console.error('refreshNotifCount:', err);
    }
}
setInterval(refreshNotifCount, 20000);

/* ---------- Messages badge + thread refresh ---------- */
function updateMessagesBadge(count){
    const badge = document.getElementById('tabMessagesBadge');
    if (!badge) return;
    if (count > 0) {
        badge.textContent = count;
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
    }
}

let adminThreadRefreshInterval = null;

async function refreshAdminChatThreads(){
    if (!document.getElementById('drawerStudentListView')) return;
    try {
        const res  = await fetch('dashboard.php?chat_action=threads', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (!res.ok || data.status !== 'success') return;

        const listContainer = document.querySelector('#drawerStudentListView .list-group');
        if (listContainer) {
            let html = '';
            (data.threads || []).forEach(th => {
                const st_name = ((th.first_name || '') + ' ' + (th.last_name || '')).trim() || `Student #${th.id}`;
                html += `
                    <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-start p-3"
                         role="button" style="cursor:pointer;"
                         onclick="openAdminChatThread(${th.id}, '${escapeHtml(st_name)}')">
                        <div class="ms-2 me-auto text-truncate" style="max-width:260px;">
                            <div class="fw-bold text-dark">${escapeHtml(st_name)}</div>
                            <small class="text-muted text-truncate d-block">${escapeHtml(th.last_msg || 'No messages')}</small>
                        </div>
                        <div class="d-flex align-items-center gap-2" onclick="event.stopPropagation();">
                            ${th.unread_count > 0 ? `<span class="badge bg-danger rounded-pill">${th.unread_count}</span>` : ''}
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger rounded-circle"
                                    title="Delete conversation"
                                    onclick="deleteStudentConversation(event, ${th.id})">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>`;
            });
            listContainer.innerHTML = html || '<div class="p-4 text-center text-muted small">No message threads found.</div>';
        }
        updateMessagesBadge(data.unread_count);
    } catch (err) {
        console.error('refreshAdminChatThreads:', err);
    }
}

/* =========================================================================
   PERMANENT DELETE — an entire student conversation
   ========================================================================= */
async function deleteStudentConversation(event, studentUserId){
    // 1. Never bubble up to openAdminChatThread().
    if (event) event.stopPropagation();

    // 2. Snapshot the row so we can remove it after the server confirms.
    const trigger = event ? event.currentTarget : null;
    const row     = trigger ? trigger.closest('.list-group-item') : null;

    // 3. Reuse the project's existing confirmation dialog.
    const confirmed = await showConfirmDialog({
        title: 'Delete Conversation?',
        message: 'This will permanently delete the entire conversation with this student, including all sent and received messages. This action cannot be undone.',
        type: 'danger',
        confirmText: 'Delete Permanently'
    });
    if (!confirmed) return;

    // 4. Disable the button to prevent double-click.
    if (trigger) trigger.disabled = true;

    try {
        const fd = new FormData();
        fd.append('student_user_id', studentUserId);
        fd.append('csrf_token', CSRF_TOKEN);

        const res = await fetch('dashboard.php?chat_action=delete_thread', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        let data;
        try {
            data = await res.json();
        } catch (parseErr) {
            console.error('deleteStudentConversation: invalid JSON', parseErr);
            throw new Error('Server returned an invalid response.');
        }

        if (!res.ok || data.status !== 'success') {
            throw new Error(data.message || 'Unable to delete the conversation.');
        }

        // 5. If the deleted thread was the one currently open, close it cleanly.
        if (currentAdminChatUserId === Number(studentUserId)) {
            if (adminChatInterval) { clearInterval(adminChatInterval); adminChatInterval = null; }
            currentAdminChatUserId = null;

            const threadView = document.getElementById('drawerChatThreadView');
            const listView   = document.getElementById('drawerStudentListView');
            if (threadView) threadView.classList.add('d-none');
            if (listView)   listView.classList.remove('d-none');
            const chatBox = document.getElementById('adminChatContainer');
            if (chatBox) chatBox.innerHTML = '';
        }

        // 6. Remove the row from the list immediately (no page reload).
        if (row) row.remove();
        const listContainer = document.querySelector('#drawerStudentListView .list-group');
        if (listContainer && listContainer.querySelectorAll('.list-group-item').length === 0) {
            listContainer.innerHTML = '<div class="p-4 text-center text-muted small">No message threads found.</div>';
        }

        // 7. Recalculate badges from the server (never subtract a hard-coded number).
        await refreshAdminChatThreads();   // updates Messages badge via unread_count
        await refreshNotifCount();         // updates the bell

        showFloatingToast('Deleted', 'Conversation permanently deleted.', 'success');

    } catch (err) {
        console.error('deleteStudentConversation:', err);
        showFloatingToast('Error', 'Unable to delete the conversation.', 'danger');
        if (trigger) trigger.disabled = false;
    }
}

function startAdminThreadRefresh(){
    if (adminThreadRefreshInterval) return;
    adminThreadRefreshInterval = setInterval(refreshAdminChatThreads, 4000);
}
window.addEventListener('beforeunload', () => {
    if (adminThreadRefreshInterval) clearInterval(adminThreadRefreshInterval);
});

/* ---------- Master list search ---------- */
document.getElementById('masterlistSearch')?.addEventListener('keyup', function(){
    const f = this.value.toLowerCase();
    document.querySelectorAll('#masterlistTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(f) ? '' : 'none';
    });
});

/* ---------- Startup ---------- */
document.addEventListener('DOMContentLoaded', () => {
    startAdminThreadRefresh();
    refreshNotifCount();
    refreshAdminChatThreads();
});
</script>
<script src="../assets/js/dashboard-navigation.js"></script>
</body>
</html>