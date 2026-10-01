<?php
/* =============================================================================
   /faculty/dashboard.php  —  Faculty / Professor Dashboard
   -----------------------------------------------------------------------------
   Contains ONLY faculty features:
     - Profile  (name, faculty ID, email, department, role)
     - Encode Grade  (attendance, quizzes, prelim, midterm, final)
     - Edit Grade    (resets is_released = 0 so Admin re-reviews)
     - Delete/Clear faculty view only  (cleared_by_faculty = 1)
     - Submit Grade Records to Admin   (faculty_submissions + notification)
     - View "System Submitted Records" + per-row GWA
     - View latest notifications, chat with Admin
   ============================================================================= */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/* -------------------------------------------------------------------------
   SERVER-SIDE ROLE GUARD
   ------------------------------------------------------------------------- */
require_role(['faculty', 'professor']);

/* -------------------------------------------------------------------------
   AJAX ENDPOINTS (chat + notifications) — same query strings as before.
   ------------------------------------------------------------------------- */
require_once __DIR__ . '/../includes/chat.php';
require_once __DIR__ . '/../includes/notifications.php';

/* -------------------------------------------------------------------------
   GRADING CONSTANTS
   ------------------------------------------------------------------------- */
$grade_limit = GRADE_LIMIT;   // 3.00

/* ==========================================================================
   POST HANDLERS — all faculty features
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['submit_prof_grades'])) {
    $input_student_id = trim($_POST['student_id_code']);
    $student_fullname = trim($_POST['student_fullname']);
    $subject_code     = trim($_POST['subject_code']);
    $semester         = trim($_POST['semester']);
    $academic_year    = trim($_POST['academic_year']);

    $attendance = (float)$_POST['attendance'];
    $quizzes    = (float)$_POST['quizzes'];
    $prelim     = (float)$_POST['prelim_exam'];
    $midterm    = (float)$_POST['midterm_exam'];
    $final      = (float)$_POST['final_exam'];

    $scale_mode = ($_POST['scale_mode'] ?? 'percent') === 'grade_scale'
        ? 'grade_scale' : 'percent';

    $user_lookup = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR id = ?");
    $user_lookup->execute([$input_student_id, $input_student_id]);
    $target_user = $user_lookup->fetch();

    if ($target_user) {
        $student_db_id = (int)$target_user['id'];
        $final_grade   = compute_final_grade(
            $attendance, $quizzes, $prelim, $midterm, $final, $scale_mode
        );

        $check_stmt = $pdo->prepare(
            "SELECT id FROM academic_records WHERE student_id = ? AND subject_code = ?"
        );
        $check_stmt->execute([$student_db_id, $subject_code]);
        $existing = $check_stmt->fetch();

        if ($existing) {
            $update_stmt = $pdo->prepare("
                UPDATE academic_records
                   SET grade = ?, semester = ?, academic_year = ?, faculty_id = ?,
                       is_released = 0, cleared_by_faculty = 0, cleared_by_student = 0
                 WHERE id = ?
            ");
            $update_stmt->execute([
                $final_grade, $semester, $academic_year, $_SESSION['user_id'], $existing['id']
            ]);
        } else {
            $insert_stmt = $pdo->prepare("
                INSERT INTO academic_records
                    (faculty_id, student_id, subject_code, subject_title,
                     grade, semester, academic_year,
                     is_released, cleared_by_faculty, cleared_by_student)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0)
            ");
            $insert_stmt->execute([
                $_SESSION['user_id'], $student_db_id, $subject_code, $subject_code,
                $final_grade, $semester, $academic_year
            ]);
        }

        $_SESSION['msg'] = "Grade record (" . number_format($final_grade, 2) . ") saved for "
                         . htmlspecialchars($student_fullname) . ".";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['msg'] = "Error: Student ID (" . htmlspecialchars($input_student_id) . ") not found.";
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: dashboard.php#encode");
    exit();
}

    /* -------- 2. EDIT EXISTING GRADE -------- */
    if (isset($_POST['update_single_record'])) {
        $record_id        = (int)$_POST['record_id'];
        $student_id_code  = trim($_POST['student_id_code']);
        $subject_code     = trim($_POST['subject_code']);
        $semester         = trim($_POST['semester']);
        $academic_year    = trim($_POST['academic_year']);

        $attendance = (float)$_POST['edit_attendance'];
        $quizzes    = (float)$_POST['edit_quizzes'];
        $prelim     = (float)$_POST['edit_prelim_exam'];
        $midterm    = (float)$_POST['edit_midterm_exam'];
        $final      = (float)$_POST['edit_final_exam'];

        $user_lookup = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR id = ?");
        $user_lookup->execute([$student_id_code, $student_id_code]);
        $target_user = $user_lookup->fetch();

        if ($target_user) {
            $student_db_id = (int)$target_user['id'];
            $new_grade     = compute_final_grade($attendance, $quizzes, $prelim, $midterm, $final);

            // EDIT RESETS RELEASE STATE — Admin must re-review.
            $update_stmt = $pdo->prepare("
                UPDATE academic_records
                SET student_id = ?, subject_code = ?, subject_title = ?, grade = ?,
                    semester = ?, academic_year = ?,
                    is_released = 0, cleared_by_faculty = 0, cleared_by_student = 0
                WHERE id = ? AND faculty_id = ?
            ");
            $update_stmt->execute([$student_db_id, $subject_code, $subject_code, $new_grade, $semester, $academic_year, $record_id, $_SESSION['user_id']]);

            $_SESSION['msg']      = "Grade record updated. Re-release required from Admin.";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['msg']      = "Error: Student ID (" . htmlspecialchars($student_id_code) . ") not found.";
            $_SESSION['msg_type'] = "danger";
        }
        header("Location: dashboard.php");
        exit();
    }

            /* -------- 3. CLEAR ALL FACULTY VIEW (soft) -------- */
    if (isset($_POST['clear_all_records'])) {
        // Clear the faculty's own academic records view
        $clear_stmt = $pdo->prepare("
            UPDATE academic_records
               SET cleared_by_faculty = 1
             WHERE faculty_id = ?
        ");
        $clear_stmt->execute([$_SESSION['user_id']]);

        // Clear the faculty's own submissions view (NOT the admin's)
        if (isset($_POST['clear_submissions_too'])) {
            $clear_subs = $pdo->prepare("
                UPDATE faculty_submissions
                   SET cleared_by_faculty = 1
                 WHERE faculty_id = ?
            ");
            $clear_subs->execute([$_SESSION['user_id']]);
        }

        $_SESSION['msg']      = "Your submitted records and submission statuses have been cleared from your local view.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

        /* -------- 3b. DELETE SINGLE SUBMISSION STATUS -------- */
    if (isset($_POST['delete_submission_record'])) {
        $sub_id = (int)$_POST['submission_id'];
        // Soft delete ONLY from the faculty's own view.
        $del_sub = $pdo->prepare("
            UPDATE faculty_submissions
               SET cleared_by_faculty = 1
             WHERE id = ? AND faculty_id = ?
        ");
        $del_sub->execute([$sub_id, $_SESSION['user_id']]);

        $_SESSION['msg']      = "Submission status removed from your view.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    /* -------- 4. CLEAR ONE FACULTY RECORD (soft) -------- */
    if (isset($_POST['delete_single_record'])) {
        $record_id = (int)$_POST['record_id'];
        $delete_stmt = $pdo->prepare("UPDATE academic_records SET cleared_by_faculty = 1 WHERE id = ? AND faculty_id = ?");
        $delete_stmt->execute([$record_id, $_SESSION['user_id']]);

        $_SESSION['msg']      = "Grade record removed from your view.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    /* -------- 5. SUBMIT ALL UNRELEASED RECORDS TO AN ADMIN -------- */
    if (isset($_POST['send_all_grades_to_admin'])) {
        $admin_id_code = trim($_POST['admin_id']);

        if ($admin_id_code === '') {
            $_SESSION['msg']      = "Please enter a valid Target Admin ID.";
            $_SESSION['msg_type'] = "danger";
            header("Location: dashboard.php");
            exit();
        }

        $target_admin = find_user_by_code($pdo, $admin_id_code, ['admin', 'department']);
        if (!$target_admin) {
            $_SESSION['msg']      = "Target Admin ID '" . htmlspecialchars($admin_id_code) . "' was not found.";
            $_SESSION['msg_type'] = "danger";
            header("Location: dashboard.php");
            exit();
        }

        $admin_db_id   = (int)$target_admin['id'];
        $admin_display = trim($target_admin['first_name'] . ' ' . $target_admin['last_name']);
        if ($admin_display === '') $admin_display = $target_admin['username'];

        // Only unreleased, non-cleared records belonging to THIS faculty.
        $rec_stmt = $pdo->prepare("
            SELECT DISTINCT ar.student_id, u.student_id AS stud_code, u.first_name, u.last_name
            FROM academic_records ar
            JOIN users u ON ar.student_id = u.id
            WHERE ar.faculty_id = ?
              AND ar.is_released = 0
              AND (ar.cleared_by_faculty = 0 OR ar.cleared_by_faculty IS NULL)
        ");
        $rec_stmt->execute([$_SESSION['user_id']]);
        $students_to_submit = $rec_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($students_to_submit) === 0) {
            $_SESSION['msg']      = "No unreleased grade records found to send.";
            $_SESSION['msg_type'] = "info";
            header("Location: dashboard.php");
            exit();
        }

        $faculty_name = $display_name;

        $created = 0; $updated = 0;
        foreach ($students_to_submit as $st) {
            $student_db_id    = (int)$st['student_id'];
            $student_code     = $st['stud_code'];
            $student_fullname = trim($st['first_name'] . ' ' . $st['last_name']);
            if ($student_fullname === '') $student_fullname = 'Student #' . $student_db_id;

            // Upsert faculty_submissions row.
            $sub_check = $pdo->prepare("
                SELECT id FROM faculty_submissions
                WHERE faculty_id = ? AND student_id = ? AND admin_target_id = ?
                LIMIT 1
            ");
            $sub_check->execute([$_SESSION['user_id'], $student_db_id, $admin_id_code]);
            $existing_sub = $sub_check->fetch();

            if ($existing_sub) {
                $upd = $pdo->prepare("
                    UPDATE faculty_submissions
                    SET admin_id = ?, submission_status = 'submitted', cleared_by_admin = 0
                    WHERE id = ?
                ");
                $upd->execute([$admin_db_id, $existing_sub['id']]);
                $submission_id = (int)$existing_sub['id'];
                $updated++;
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO faculty_submissions
                        (faculty_id, student_id, admin_id, admin_target_id, submission_status, cleared_by_admin)
                    VALUES (?, ?, ?, ?, 'submitted', 0)
                ");
                $ins->execute([$_SESSION['user_id'], $student_db_id, $admin_db_id, $admin_id_code]);
                $submission_id = (int)$pdo->lastInsertId();
                $created++;
            }

            // Private notification to the admin.
            create_notification(
                $pdo,
                $admin_db_id,
                (int)$_SESSION['user_id'],
                'faculty_to_admin',
                "New Grade Submission",
                "Faculty {$faculty_name} submitted grade records for {$student_fullname} ({$student_code}) for your review.",
                'faculty_submission',
                $submission_id,
                $student_code
            );
        }

        $_SESSION['msg']      = "Routed to {$admin_display} ({$admin_id_code}). New: {$created}, updated: {$updated}.";
        $_SESSION['msg_type'] = "success";
        header("Location: dashboard.php");
        exit();
    }
}

/* ==========================================================================
   DATA FOR THE FACULTY VIEW
   ========================================================================== */

// Announcements (read-only for faculty).
$announcements_list = $pdo->query("SELECT * FROM announcements ORDER BY id DESC LIMIT 6")->fetchAll();
$ann_count = count($announcements_list);

// Unread notification count (bell).
$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0");
$unread_stmt->execute([$user_id]);
$total_notifications = (int)$unread_stmt->fetchColumn();

// Notification drawer list (latest 15).
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

// System submitted records for THIS faculty (latest 6, non-cleared).
$recent_stmt = $pdo->prepare("
    SELECT ar.id, ar.subject_code, ar.grade, ar.semester, ar.academic_year,
           u.student_id,
           CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS student_fullname,
           (
              SELECT AVG(ar_sub.grade) FROM academic_records ar_sub
              WHERE ar_sub.student_id = ar.student_id
                AND ar_sub.faculty_id = ar.faculty_id
                AND (ar_sub.cleared_by_faculty = 0 OR ar_sub.cleared_by_faculty IS NULL)
           ) AS student_gwa
    FROM academic_records ar
    JOIN users u ON ar.student_id = u.id
    WHERE ar.faculty_id = ?
      AND (ar.cleared_by_faculty = 0 OR ar.cleared_by_faculty IS NULL)
    ORDER BY ar.id DESC
    LIMIT 6
");
$recent_stmt->execute([$_SESSION['user_id']]);
$recent_records = $recent_stmt->fetchAll();

// Overall system GWA for THIS faculty.
$overall_gwa_stmt = $pdo->prepare("
    SELECT AVG(grade) AS overall_gwa, COUNT(*) AS record_count
    FROM academic_records
    WHERE faculty_id = ?
      AND student_id IS NOT NULL
      AND grade IS NOT NULL
      AND (cleared_by_faculty = 0 OR cleared_by_faculty IS NULL)
");
$overall_gwa_stmt->execute([$_SESSION['user_id']]);
$overall_gwa_row   = $overall_gwa_stmt->fetch(PDO::FETCH_ASSOC);
$overall_gwa_val   = $overall_gwa_row['overall_gwa'] ?? null;
$overall_gwa_count = (int)($overall_gwa_row['record_count'] ?? 0);
$overall_gwa       = ($overall_gwa_val !== null && $overall_gwa_val !== false)
    ? number_format((float)$overall_gwa_val, 2)
    : '—';
$overall_gwa_high  = ($overall_gwa_val !== null && $overall_gwa_val !== false)
    && round((float)$overall_gwa_val, 2) >= $grade_limit;

// Submission status overview for THIS faculty (faculty's own view).
$my_subs_stmt = $pdo->prepare("
    SELECT fs.id, fs.submission_status, fs.cleared_by_faculty, fs.created_at,
           fs.admin_target_id,
           u.student_id AS stud_code,
           CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS student_fullname
    FROM faculty_submissions fs
    JOIN users u ON fs.student_id = u.id
    WHERE fs.faculty_id = ?
      AND (fs.cleared_by_faculty = 0 OR fs.cleared_by_faculty IS NULL)
    ORDER BY fs.id DESC
    LIMIT 10
");
$my_subs_stmt->execute([$_SESSION['user_id']]);
$my_submissions = $my_subs_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentFLOW | Faculty Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard-layout.css">
    <style>
        :root{--grc-primary:#800000;--grc-secondary:#ab0a0a;--grc-hover:#ff1e1e;--grc-dark:#120202;--grc-bg:#f8fafc;--grc-border:#e2e8f0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background-color:var(--grc-bg);color:#1e293b;}
        .navbar-grc{background:linear-gradient(135deg,var(--grc-dark) 0%,#3a0000 100%);border-bottom:3px solid var(--grc-hover);}
        .dashboard-hero{background:linear-gradient(135deg,var(--grc-dark) 0%,var(--grc-primary) 60%,var(--grc-secondary) 100%);border-radius:20px;color:#fff;padding:2rem 2.5rem;box-shadow:0 20px 25px -5px rgba(128,0,0,0.15);}
        .card-custom{background:#fff;border:1px solid var(--grc-border);border-radius:18px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.02);}
        .btn-grc-action{background:linear-gradient(135deg,var(--grc-secondary) 0%,var(--grc-primary) 100%);color:#fff;border:none;border-radius:10px;font-weight:600;}
        .btn-grc-action:hover{background:var(--grc-hover);color:#fff;}
        .bell-btn{position:relative;background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.2);border-radius:12px;width:42px;height:42px;display:flex;align-items:center;justify-content:center;}
        .bell-badge{position:absolute;top:-3px;right:-3px;font-size:0.65rem;padding:3px 6px;border-radius:10px;border:2px solid var(--grc-dark);}
        .floating-toast-container{position:fixed;top:25px;right:25px;z-index:10000;min-width:320px;max-width:450px;background:#fff;border-left:6px solid var(--grc-primary);box-shadow:0 10px 30px rgba(0,0,0,0.2);border-radius:12px;}
        .notif-item.notif-unread{background:#fff8f8;border-left:4px solid var(--grc-secondary) !important;}
        .notif-item.notif-read{background:#fff;opacity:.85;}
        .table-modern thead th{background:#f8fafc;text-transform:uppercase;font-size:.725rem;letter-spacing:.06em;color:#64748b;font-weight:700;padding:1rem;}
        .table-modern tbody td{padding:1rem;border-bottom:1px solid #f1f5f9;font-size:.9rem;}
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
    <li class="nav-section-title">Record</li>
    <li><a href="#encode" class="nav-link" data-section="encode"><i class="fa-solid fa-pen-to-square"></i><span>Encode Grades</span></a></li>
    <li class="nav-section-title">Report</li>
    <li><a href="#submissions" class="nav-link" data-section="submissions"><i class="fa-solid fa-list-check"></i><span>My Submissions</span></a></li>
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
        <header class="top-header">
        <div class="header-left">
            <h1 class="header-title">Dashboard</h1>
        </div>
        <div class="header-right">
            <button class="bell-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#announcementsDrawer">
                <i class="fa-solid fa-bell fs-6"></i>
                <span id="notifBadge" class="badge bg-danger bell-badge" style="<?= $total_notifications > 0 ? '' : 'display:none;' ?>"><?= $total_notifications ?></span>
            </button>
        </div>
    </header>
    <div class="container-fluid p-0">

<div class="container-fluid px-lg-5 py-4">

    <div class="dashboard-hero mb-4 d-flex justify-content-between align-items-center">
        <div>
            <span class="badge bg-white text-danger fw-bold rounded-pill px-3 py-1 mb-2 shadow-sm">Faculty Module</span>
            <h2 class="fw-extrabold mb-1">Welcome back, <?= htmlspecialchars($display_name) ?>!</h2>
            <p class="mb-0 text-white-50 small">
                Faculty ID: <?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?> · Department: <?= htmlspecialchars($user_data['department_course'] ?? 'General') ?>
            </p>
        </div>
        <div class="text-end d-none d-md-block">
            <h6 class="mb-0 text-white-50 small text-uppercase fw-semibold"><?= date('l') ?></h6>
            <h3 class="fw-bold mb-0"><?= date('F j, Y') ?></h3>
        </div>
    </div>

    <div class="row g-4">
        <!-- ==================== FACULTY DASHBOARD OVERVIEW ==================== -->
    <div class="dashboard-section active" id="dashboard">
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3"><i class="fa-solid fa-book"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">My Records</span>
                        <h4 class="fw-bold text-dark mb-0"><?= $overall_gwa_count ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background:rgba(255,193,7,.15);color:#ca8a04;"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Pending Submissions</span>
                        <h4 class="fw-bold text-dark mb-0"><?= count($my_submissions) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background:rgba(25,135,84,.15);color:#198754;"><i class="fa-solid fa-chart-line"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Overall GWA</span>
                        <h4 class="fw-bold <?= $overall_gwa_high ? 'text-danger' : 'text-success' ?> mb-0"><?= $overall_gwa ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background:rgba(59,130,246,.15);color:#2563eb;"><i class="fa-solid fa-bullhorn"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Announcements</span>
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
                        <a href="#encode" class="btn btn-outline-danger text-start py-3" data-section="encode">
                            <i class="fa-solid fa-pen-to-square me-2"></i> Encode New Grade
                        </a>
                        <a href="#submissions" class="btn btn-outline-danger text-start py-3" data-section="submissions">
                            <i class="fa-solid fa-list-check me-2"></i> View My Submissions
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card-custom p-4 h-100">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-clock-rotate-left text-danger me-2"></i>Recent Notifications</h5>
                    <div class="list-group list-group-flush">
                        <?php
                        $recent_notifs = array_slice($notification_list, 0, 5);
                        if (count($recent_notifs) > 0):
                            foreach ($recent_notifs as $n):
                        ?>
                            <div class="list-group-item px-0 py-2 border-0">
                                <strong class="text-dark small"><?= htmlspecialchars($n['title']) ?></strong>
                                <p class="small text-muted mb-0"><?= htmlspecialchars(mb_strimwidth($n['message'], 0, 60, '...')) ?></p>
                            </div>
                        <?php endforeach; else: ?>
                            <p class="text-muted small text-center py-3">No recent notifications.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== ENCODE GRADES ==================== -->
    <div class="dashboard-section" id="encode">
        <div class="row g-4">

            <!-- ---------- Encode Student Grade Sheet ---------- -->
            <div class="col-lg-8">
                <div class="card-custom p-4">
                    <h5 class="fw-bold text-dark mb-4"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Encode Student Grade Sheet</h5>

                    <form action="dashboard.php" method="POST" id="profGradingForm">
                        <input type="hidden" name="submit_prof_grades" value="1">
                        <?= csrf_field() ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Student ID</label>
                                <input type="text" name="student_id_code" class="form-control" placeholder="e.g. 2024-01-00001" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted">Full Name of Student</label>
                                <input type="text" name="student_fullname" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-12">
                                <label class="form-label fw-semibold small text-muted">Subject Code</label>
                                <select name="subject_code" class="form-select" required>
                                    <option value="" disabled selected>-- Choose Subject --</option>
                                    <option value="SIA2">SIA2</option>
                                    <option value="SYSARC1">SYSARC1</option>
                                    <option value="PROFLEC2">PROFLEC2</option>
                                    <option value="BMC">BMC</option>
                                    <option value="SYSARC2">SYSARC2</option>
                                </select>
                            </div>
                        </div>

                                                <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small text-muted">Semester</label>
                                <select name="semester" class="form-select" required>
                                    <option value="1st Semester">1st Semester</option>
                                    <option value="2nd Semester">2nd Semester</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small text-muted">Academic Year</label>
                                <input type="text" name="academic_year" class="form-control" value="2025-2026" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small text-muted">Grading Mode</label>
                                <select name="scale_mode" class="form-select" required>
                                    <option value="percent">Percentage (0 – 100)</option>
                                    <option value="grade_scale">Grade Scale (1.00 – 5.00)</option>
                                </select>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-calculator me-2 text-danger"></i>Grade Breakdown Scores (0 – 100)</h6>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Attendance (10%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="attendance" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Quizzes (20%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="quizzes" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Prelim Exam (20%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="prelim_exam" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Midterm Exam (25%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="midterm_exam" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Final Exam (25%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="final_exam" class="form-control grade-input" required oninput="calculatePreview()">
                            </div>
                        </div>

                        <div class="p-3 bg-light rounded-3 border mb-4 d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small fw-semibold d-block">Calculated Transmuted Grade:</span>
                                <h3 class="fw-bold text-success mb-0" id="gradePreview">—</h3>
                            </div>
                            <div class="text-end">
                                <span class="text-muted small fw-semibold d-block">Weighted Score Total:</span>
                                <h4 class="fw-bold text-dark mb-0" id="scorePreview">0.00%</h4>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary w-50 py-3 fw-bold" onclick="clearGradeForm()"><i class="fa-solid fa-rotate-left me-2"></i>Clear Form</button>
                            <button type="submit" class="btn btn-grc-action w-50 py-3 fw-bold"><i class="fa-solid fa-floppy-disk me-2"></i>Save Grade Record</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ---------- System Submitted Records ---------- -->
            <div class="col-lg-4">
                <div class="card-custom p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h5 class="fw-bold text-dark mb-0 fs-6"><i class="fa-solid fa-list-check me-2 text-danger"></i>System Submitted Records</h5>
                            <div class="d-flex align-items-center gap-2">
    <!-- NEW: Print faculty grade records (uses shared print.php design) -->
    <a href="../print.php?type=faculty_grades" target="_blank"
       class="btn btn-outline-secondary btn-sm rounded-pill px-3"
       title="Print your grade records as an official document">
        <i class="fa-solid fa-print me-1"></i> Print
    </a>
                            <form method="POST" class="m-0 js-confirm-form"
                                  data-confirm-title="Clear All Records?"
                                  data-confirm-message="Are you sure you want to clear all submitted records and submission statuses from YOUR view? This will not affect the Admin's records."
                                  data-confirm-type="warning"
                                  data-confirm-button="Clear All">
                                  <?= csrf_field() ?>
                                <input type="hidden" name="clear_all_records" value="1">
                                <input type="hidden" name="clear_submissions_too" value="1">
                                <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-2 py-1 small" style="font-size:.75rem;">
                                    <i class="fa-solid fa-trash-can me-1"></i> Clear All
                                </button>
                            </form>
                        </div>
                        </div>

                        <div class="p-3 bg-light rounded-3 border mb-3 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark small"><i class="fa-solid fa-chart-line text-danger me-1"></i> Overall System GWA:</span>
                            <span class="badge <?= $overall_gwa_high ? 'bg-danger' : 'bg-success' ?> fs-6 fw-bold" title="Computed from <?= $overall_gwa_count ?> record(s)"><?= $overall_gwa ?></span>
                        </div>

                        <div class="table-responsive mb-3" style="max-height:280px;overflow-y:auto;">
                            <table class="table table-hover align-middle small mb-0">
                                <thead class="table-dark sticky-top">
                                    <tr><th>Subject / ID</th><th class="text-center">Grade / GWA</th><th class="text-end">Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recent_records) > 0): ?>
                                        <?php foreach ($recent_records as $rec): ?>
                                            <tr>
                                                <td class="text-break">
                                                    <span class="fw-bold text-dark"><?= htmlspecialchars($rec['subject_code']) ?></span><br>
                                                    <small class="text-muted"><?= htmlspecialchars($rec['student_id']) ?></small>
                                                </td>
                                                <td class="text-center text-nowrap">
                                                    <span class="fw-bold <?= grade_color_class($rec['grade'], $grade_limit) ?>"><?= number_format($rec['grade'], 2) ?></span><br>
                                                    <small class="fw-semibold <?= grade_color_class($rec['student_gwa'], $grade_limit) ?>">GWA: <?= number_format($rec['student_gwa'], 2) ?></small>
                                                </td>
                                                <td class="text-end text-nowrap">
                                                    <button type="button" class="btn btn-sm btn-outline-primary border-0 rounded-circle" title="Edit Grade"
                                                        onclick="openEditModal(
                                                            <?= $rec['id'] ?>,
                                                            '<?= htmlspecialchars($rec['student_id'], ENT_QUOTES) ?>',
                                                            '<?= htmlspecialchars(trim($rec['student_fullname']), ENT_QUOTES) ?>',
                                                            '<?= htmlspecialchars($rec['subject_code'], ENT_QUOTES) ?>',
                                                            '<?= htmlspecialchars($rec['semester'], ENT_QUOTES) ?>',
                                                            '<?= htmlspecialchars($rec['academic_year'], ENT_QUOTES) ?>',
                                                            '<?= number_format($rec['grade'], 2) ?>'
                                                        )"><i class="fa-solid fa-pen-to-square"></i></button>
                                                    <form method="POST" class="d-inline js-confirm-form"
                                                          data-confirm-title="Delete Grade Record"
                                                          data-confirm-message="Are you sure you want to remove this grade record from your view?"
                                                          data-confirm-type="danger"
                                                          data-confirm-button="Delete">
                                                          <?= csrf_field() ?>
                                                        <input type="hidden" name="delete_single_record" value="1">
                                                        <input type="hidden" name="record_id" value="<?= $rec['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger border-0 rounded-circle"><i class="fa-solid fa-trash-can"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                                        <?php else: ?>
                                        <tr><td colspan="3" class="text-center text-muted py-3">No submitted grades found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- End of top block (header + GWA + table) -->

                    <!-- ---------- Send Records to Admin (bottom of card) ---------- -->
                    <div class="mt-3 pt-3 border-top">
                        <h6 class="fw-bold text-dark small mb-2"><i class="fa-solid fa-paper-plane text-danger me-1"></i>Send Records to Admin</h6>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="send_all_grades_to_admin" value="1">
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-muted">Target Admin ID</label>
                                <input type="text" name="admin_id" class="form-control form-control-sm" placeholder="e.g. ADM-01-01111" required>
                            </div>
                            <button type="submit" class="btn btn-grc-action w-100 py-2 fw-bold btn-sm">
                                <i class="fa-solid fa-paper-plane me-1"></i> Send All Records to Admin
                            </button>
                        </form>
                    </div>

                </div>
            </div>

        </div><!-- /.row -->
    </div><!-- /#encode -->


    <!-- ==================== MY SUBMISSIONS ==================== -->
    <div class="dashboard-section" id="submissions">
        <div class="row g-4">
            <div class="col-lg-12">
                <div class="card-custom p-4">

                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-inbox text-danger me-2"></i>Submission Status</h5>
                    <?php if (count($my_submissions) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle small mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Student</th>
                                        <th>Admin ID</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($my_submissions as $s):
                                    $status = $s['submission_status'] ?: 'submitted';
                                    $badge = 'bg-secondary';
                                    if ($status === 'submitted')   $badge = 'bg-warning text-dark';
                                    if ($status === 'released')    $badge = 'bg-success';
                                    if ($status === 'returned')    $badge = 'bg-info text-dark';
                                    if ($status === 'cleared')     $badge = 'bg-secondary';
                                ?>
                                    <tr>
                                        <td class="text-truncate" style="max-width:240px;">
                                            <?= htmlspecialchars($s['student_fullname'] ?: '—') ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($s['stud_code'] ?: '') ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($s['admin_target_id']) ?></span></td>
                                        <td><span class="badge <?= $badge ?>"><?= strtoupper(htmlspecialchars($status)) ?></span></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline js-confirm-form"
                                                  data-confirm-title="Remove Submission Status"
                                                  data-confirm-message="Are you sure you want to remove this submission status from your view?"
                                                  data-confirm-type="warning"
                                                  data-confirm-button="Remove">
                                                  <?= csrf_field() ?>
                                                <input type="hidden" name="delete_submission_record" value="1">
                                                <input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger border-0 rounded-circle" title="Delete Submission Status">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="small text-muted mb-0">No submissions yet.</p>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

<!-- ==================== EDIT GRADE MODAL ==================== -->
<div class="modal fade" id="editGradeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header text-white" style="background:var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Edit Grade Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="update_single_record" value="1">
                <input type="hidden" name="record_id" id="editRecordId">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Student ID</label>
                            <input type="text" name="student_id_code" id="editStudentId" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Student Full Name</label>
                            <input type="text" name="student_fullname" id="editStudentFullname" class="form-control" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold text-muted">Subject Code</label>
                            <select name="subject_code" id="editSubjectCode" class="form-select" required>
                                <option value="SIA2">SIA2</option>
                                <option value="SYSARC1">SYSARC1</option>
                                <option value="PROFLEC2">PROFLEC2</option>
                                <option value="BMC">BMC</option>
                                <option value="SYSARC2">SYSARC2</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Semester</label>
                            <select name="semester" id="editSemester" class="form-select" required>
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Academic Year</label>
                            <input type="text" name="academic_year" id="editAcademicYear" class="form-control" required>
                        </div>
                    </div>
                    <hr class="my-4">
                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-calculator me-2 text-danger"></i>Grade Breakdown (0 – 100)</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><label class="form-label small fw-semibold">Attendance (10%)</label><input type="number" step="0.01" name="edit_attendance" id="editAttendance" class="form-control edit-grade-input" required oninput="calculateEditPreview()"></div>
                        <div class="col-md-4"><label class="form-label small fw-semibold">Quizzes (20%)</label><input type="number" step="0.01" name="edit_quizzes" id="editQuizzes" class="form-control edit-grade-input" required oninput="calculateEditPreview()"></div>
                        <div class="col-md-4"><label class="form-label small fw-semibold">Prelim (20%)</label><input type="number" step="0.01" name="edit_prelim_exam" id="editPrelim" class="form-control edit-grade-input" required oninput="calculateEditPreview()"></div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6"><label class="form-label small fw-semibold">Midterm (25%)</label><input type="number" step="0.01" name="edit_midterm_exam" id="editMidterm" class="form-control edit-grade-input" required oninput="calculateEditPreview()"></div>
                        <div class="col-md-6"><label class="form-label small fw-semibold">Final (25%)</label><input type="number" step="0.01" name="edit_final_exam" id="editFinal" class="form-control edit-grade-input" required oninput="calculateEditPreview()"></div>
                    </div>
                    <div class="p-3 bg-light rounded-3 border d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-semibold d-block">Calculated Transmuted Grade:</span>
                            <h3 class="fw-bold text-success mb-0" id="editGradePreview">—</h3>
                        </div>
                        <div class="text-end">
                            <span class="text-muted small fw-semibold d-block">Weighted Score Total:</span>
                            <h4 class="fw-bold text-dark mb-0" id="editScorePreview">0.00%</h4>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-grc-action btn-sm rounded-pill px-4">Update Grade Record</button>
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
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-id-card me-2 text-danger"></i>Faculty ID:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-at me-2 text-danger"></i>Username:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['username']) ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-envelope me-2 text-danger"></i>Email:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['email']) ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-phone me-2 text-danger"></i>Phone:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['phone'] ?? 'N/A') ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <span class="text-muted small fw-semibold"><i class="fa-solid fa-graduation-cap me-2 text-danger"></i>Course / Dept:</span>
                        <span class="fw-bold small"><?= htmlspecialchars($user_data['department_course'] ?? 'N/A') ?></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
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
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-semibold text-dark py-2" data-bs-toggle="tab" data-bs-target="#drawerNotifications" type="button">
                <i class="fa-solid fa-bell me-1 text-danger"></i> For You
                <span id="tabNotifBadge" class="badge bg-danger rounded-pill ms-1" style="<?= $total_notifications > 0 ? '' : 'display:none;' ?>"><?= $total_notifications ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold text-dark py-2" data-bs-toggle="tab" data-bs-target="#drawerAnnouncements" type="button">
                <i class="fa-solid fa-bullhorn me-1 text-danger"></i> Broadcasts
            </button>
        </li>
    </ul>
    <div class="offcanvas-body bg-light">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="drawerNotifications">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-inbox me-2 text-danger"></i>Your Notifications</h6>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="markAllNotificationsRead()"><i class="fa-solid fa-check-double me-1"></i> Mark all read</button>
                </div>
                <div id="notifListContainer" class="overflow-auto" style="max-height:560px;">
                    <?php if (count($notification_list) > 0): ?>
                        <?php foreach ($notification_list as $n):
                            $unread = empty($n['is_read']);
                        ?>
                            <div class="card mb-2 border-0 shadow-sm rounded-3 notif-item <?= $unread ? 'notif-unread' : 'notif-read' ?>" data-notif-id="<?= (int)$n['id'] ?>">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1 gap-2">
                                        <strong class="text-dark small"><?= htmlspecialchars($n['title']) ?><?php if ($unread): ?> <span class="badge bg-danger ms-1" style="font-size:.6rem;">NEW</span><?php endif; ?></strong>
                                        <small class="text-muted" style="font-size:.7rem;"><?= date('M d, h:i A', strtotime($n['created_at'])) ?></small>
                                    </div>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($n['message']) ?></p>
                                    <div class="d-flex justify-content-end gap-1">
                                        <?php if ($unread): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" onclick="markNotificationRead(<?= (int)$n['id'] ?>)"><i class="fa-solid fa-check me-1"></i>Mark read</button>
                                        <?php endif; ?>
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
            <div class="tab-pane fade" id="drawerAnnouncements">
                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-bullhorn me-2 text-danger"></i>System Broadcasts</h6>
                <div class="overflow-auto" style="max-height:500px;">
                    <?php foreach ($announcements_list as $ann):
                        $p = $ann['priority'] ?? 'Normal';
                        $pClass = $p === 'Urgent' ? 'bg-danger' : ($p === 'Important' ? 'bg-warning text-dark' : 'bg-secondary');
                    ?>
                        <div class="card mb-3 border-0 shadow-sm rounded-3">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center mb-1 gap-1 flex-wrap">
                                    <strong class="text-dark small"><?= htmlspecialchars($ann['title']) ?></strong>
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
    <?php unset($_SESSION['msg'], $_SESSION['msg_type']); ?>
<?php endif; ?>

const GRADE_LIMIT = <?= json_encode($grade_limit) ?>;

/* ---------- Grade color helpers ---------- */
function getGradeStatus(value){
    if (value === null || value === undefined || value === '' || value === '—' || value === '-') return {className:'text-muted'};
    const n = parseFloat(value);
    if (Number.isNaN(n)) return {className:'text-muted'};
    const r = Math.round(n*100)/100;
    return { className: r >= GRADE_LIMIT ? 'text-danger' : 'text-success' };
}
function gradeColorClass(v){ return getGradeStatus(v).className; }
function applyGradeColor(el,v){ if(!el) return; el.classList.remove('text-success','text-danger','text-muted'); el.classList.add(gradeColorClass(v)); }
function escapeHtml(t){ if(!t) return ''; return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

/* ---------- Grade preview ---------- */
function clearGradeForm(){
    const f = document.getElementById('profGradingForm');
    if(!f) return;
    f.reset();
    document.getElementById('gradePreview').textContent = '—';
    document.getElementById('scorePreview').textContent = '0.00%';
    applyGradeColor(document.getElementById('gradePreview'), '—');
}

document.addEventListener('DOMContentLoaded', () => {
    const modeSel = document.querySelector('select[name="scale_mode"]');
    if (modeSel) modeSel.addEventListener('change', () => {
        if (typeof calculatePreview === 'function') calculatePreview();
        if (typeof calculateEditPreview === 'function') calculateEditPreview();
    });
});

function _computePreview(inputs, scoreEl, gradeEl){
    if (!inputs || inputs.length === 0) return;
    
    // 1. Properly read values from the inputs passed to the function
    const a  = parseFloat(inputs[0].value) || 0;
    const q  = parseFloat(inputs[1].value) || 0;
    const p  = parseFloat(inputs[2].value) || 0;
    const m  = parseFloat(inputs[3].value) || 0;
    const fn = parseFloat(inputs[4].value) || 0;

    // 2. Check if the grading mode is "Scale" or "Percentage"
    // We look for the select element inside the same form
    const form = inputs[0].closest('form');
    const modeEl = form ? form.querySelector('select[name="scale_mode"]') : null;
    const scaleMode = modeEl ? modeEl.value === 'grade_scale' : false;

    // 3. Calculate the Weighted Score
    const score = (a * 0.10) + (q * 0.20) + (p * 0.20) + (m * 0.25) + (fn * 0.25);
    
    let grade = '5.00';
    const scoreDisplay = document.getElementById(scoreEl);
    const gradeDisplay = document.getElementById(gradeEl);

    if (scaleMode){
        // If in Scale Mode (1.00 - 5.00), the weighted score IS the grade
        grade = score.toFixed(2);
        if(scoreDisplay) scoreDisplay.textContent = 'Scale Mode';
    } else {
        // If in Percentage Mode (0 - 100), use the transmutation table
        if (score >= 97)      grade = '1.00';
        else if (score >= 94) grade = '1.25';
        else if (score >= 91) grade = '1.50';
        else if (score >= 88) grade = '1.75';
        else if (score >= 85) grade = '2.00';
        else if (score >= 82) grade = '2.25';
        else if (score >= 79) grade = '2.50';
        else if (score >= 76) grade = '2.75';
        else if (score >= 75) grade = '3.00';
        else if (score >= 70) grade = '4.00';
        else                  grade = '5.00';
        
        if(scoreDisplay) scoreDisplay.textContent = score.toFixed(2) + '%';
    }

    // 4. Update the UI with the calculated grade
    if(gradeDisplay) {
        gradeDisplay.textContent = grade;
        applyGradeColor(gradeDisplay, grade);
    }
}

function calculatePreview(){ _computePreview(document.querySelectorAll('.grade-input'), 'scorePreview', 'gradePreview'); }
function calculateEditPreview(){ _computePreview(document.querySelectorAll('.edit-grade-input'), 'editScorePreview', 'editGradePreview'); }

/* ---------- Edit modal ---------- */
function openEditModal(id, studId, fullName, subject, semester, ay, grade){
    document.getElementById('editRecordId').value = id;
    document.getElementById('editStudentId').value = studId;
    document.getElementById('editStudentFullname').value = fullName;
    document.getElementById('editSubjectCode').value = subject;
    document.getElementById('editSemester').value = semester;
    document.getElementById('editAcademicYear').value = ay;
    ['editAttendance','editQuizzes','editPrelim','editMidterm','editFinal'].forEach(i => document.getElementById(i).value = '');
    document.getElementById('editGradePreview').textContent = grade;
    applyGradeColor(document.getElementById('editGradePreview'), grade);
    document.getElementById('editScorePreview').textContent = 'Loaded';
    new bootstrap.Modal(document.getElementById('editGradeModal')).show();
}

/* ---------- Notifications ---------- */
function updateNotifBadge(count){
    ['notifBadge','tabNotifBadge'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (count > 0){ el.textContent = count; el.style.display = ''; } else { el.style.display = 'none'; }
    });
}
function markNotificationRead(id){
    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    fetch('dashboard.php?notification_action=read', { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success'){
                const c = document.querySelector(`.notif-item[data-notif-id="${id}"]`);
                if (c){
                    c.classList.remove('notif-unread'); c.classList.add('notif-read');
                    const b = c.querySelector('.badge.bg-danger'); if (b && b.textContent === 'NEW') b.remove();
                    const btn = c.querySelector('button.btn-outline-success'); if (btn) btn.remove();
                }
                refreshNotifCount();
            }
        });
}
function markAllNotificationsRead(){
    const fd = new FormData();
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    fetch('dashboard.php?notification_action=read_all', {
        method:'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success'){
                document.querySelectorAll('.notif-item.notif-unread').forEach(c => {
                    c.classList.remove('notif-unread'); c.classList.add('notif-read');
                    const b = c.querySelector('.badge.bg-danger'); if (b && b.textContent === 'NEW') b.remove();
                    const btn = c.querySelector('button.btn-outline-success'); if (btn) btn.remove();
                });
                refreshNotifCount();
            }
        });
}
async function deleteNotification(id){
    const confirmed = await showConfirmDialog({
        title: 'Remove Notification',
        message: 'Are you sure you want to remove this notification? The underlying record is not affected.',
        type: 'warning',
        confirmText: 'Remove'
    });
    if (!confirmed) return;

    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    fetch('dashboard.php?notification_action=delete', { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success'){
                const c = document.querySelector(`.notif-item[data-notif-id="${id}"]`);
                if (c) c.remove();
                refreshNotifCount();
            }
        });
}
function refreshNotifCount(){
    fetch('dashboard.php?notification_action=count')
        .then(r => r.json())
        .then(d => { if (d.status === 'success') updateNotifBadge(d.count); })
        .catch(() => {});
}
setInterval(refreshNotifCount, 20000);

/* Auto-close toast after 4s */
document.addEventListener('DOMContentLoaded', () => {
    const toast = document.querySelector('.floating-toast-container');
    if (toast) setTimeout(() => bootstrap.Alert.getOrCreateInstance(toast).close(), 4000);
});
</script>
<script src="../assets/js/dashboard-navigation.js"></script>
</body>
</html>