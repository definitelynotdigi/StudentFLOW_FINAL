<?php
/* =============================================================================
   /student/dashboard.php  —  Student Dashboard
   -----------------------------------------------------------------------------
   Contains ONLY student features:
     - Profile
     - Academic Records  (is_released = 1, cleared_by_student = 0 ONLY)
     - GWA + grade status colors
     - Financial ledger (read-only)
     - Campus Broadcasts (read-only announcements)
     - Clear own grade records (soft — cleared_by_student = 1)
     - Notifications bell
     - Support chat
   ============================================================================= */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['student']);

require_once __DIR__ . '/../includes/chat.php';
require_once __DIR__ . '/../includes/notifications.php';

$grade_limit = GRADE_LIMIT;

/* ==========================================================================
   POST HANDLERS — student-only features
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    /* -------- Clear all my grades from my dashboard (soft) -------- */
    if (isset($_POST['clear_all_student_records'])) {
        $stmt = $pdo->prepare("
            UPDATE academic_records
            SET cleared_by_student = 1
            WHERE student_id = ? AND is_released = 1
        ");
        $stmt->execute([$_SESSION['user_id']]);

        $_SESSION['msg']      = "All grade records were cleared from your dashboard. Admin and faculty records are not affected.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    /* -------- Clear one grade from my dashboard (soft) -------- */
    if (isset($_POST['delete_student_academic_record'])) {
        $record_id = (int)$_POST['record_id'];
        $stmt = $pdo->prepare("
            UPDATE academic_records
            SET cleared_by_student = 1
            WHERE id = ? AND student_id = ? AND is_released = 1
        ");
        $stmt->execute([$record_id, $_SESSION['user_id']]);

        $_SESSION['msg']      = "Grade record removed from your dashboard.";
        $_SESSION['msg_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }
}

/* ==========================================================================
   DATA FOR THE STUDENT VIEW
   ========================================================================== */

// Account balance from financial_ledgers.
$bal = calculateStudentBalance($pdo, (int)$_SESSION['user_id']);
$balance = $bal['balance'];

// GWA across only my released, non-cleared grades.
$gwa_stmt = $pdo->prepare("
    SELECT AVG(grade) AS gwa, COUNT(*) AS total
    FROM academic_records
    WHERE student_id = ?
      AND is_released = 1
      AND (cleared_by_student = 0 OR cleared_by_student IS NULL)
");
$gwa_stmt->execute([$_SESSION['user_id']]);
$gwa_data = $gwa_stmt->fetch();
$gwa = $gwa_data['gwa'] ? number_format($gwa_data['gwa'], 2) : '—';
$subject_count = (int)($gwa_data['total'] ?? 0);
$gwa_is_high = ($gwa_data['gwa'] !== null && $gwa_data['gwa'] !== false)
    && round((float)$gwa_data['gwa'], 2) >= $grade_limit;

// My grades (released + not cleared by me).
$grades_stmt = $pdo->prepare("
    SELECT * FROM academic_records
    WHERE student_id = ?
      AND is_released = 1
      AND (cleared_by_student = 0 OR cleared_by_student IS NULL)
    ORDER BY id DESC
");
$grades_stmt->execute([$_SESSION['user_id']]);
$grades = $grades_stmt->fetchAll();

// Financial ledger rows.
$ledger_stmt = $pdo->prepare("
    SELECT * FROM financial_ledgers
    WHERE student_id = ?
      AND voided = 0
      AND status = 'approved'
    ORDER BY transaction_date ASC, id ASC
");
$ledger_stmt->execute([$_SESSION['user_id']]);
$ledger_rows = $ledger_stmt->fetchAll();

// Announcements (read-only).
$announcements_list = $pdo->query("SELECT * FROM announcements ORDER BY id DESC LIMIT 6")->fetchAll();
$ann_count = count($announcements_list);

// Notification bell.
$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0");
$unread_stmt->execute([$user_id]);
$total_notifications = (int)$unread_stmt->fetchColumn();

$notif_list_stmt = $pdo->prepare("
    SELECT n.*, CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,'')) AS sender_name
    FROM notifications n
    LEFT JOIN users s ON n.sender_user_id = s.id
    WHERE n.recipient_user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 15
");
$notif_list_stmt->execute([$user_id]);
$notification_list = $notif_list_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentFLOW | Student Dashboard</title>
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
        .stat-card-modern{background:#fff;border:1px solid var(--grc-border);border-radius:18px;padding:1.5rem;box-shadow:0 4px 6px -1px rgba(0,0,0,0.03);height:100%;}
        .icon-wrapper-gradient{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.35rem;background:linear-gradient(135deg,rgba(171,10,10,.1) 0%,rgba(128,0,0,.2) 100%);color:var(--grc-secondary);}
        .card-custom{background:#fff;border:1px solid var(--grc-border);border-radius:18px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.02);}
        .btn-grc-action{background:linear-gradient(135deg,var(--grc-secondary) 0%,var(--grc-primary) 100%);color:#fff;border:none;border-radius:10px;font-weight:600;}
        .btn-grc-action:hover{background:var(--grc-hover);color:#fff;}
        .bell-btn{position:relative;background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.2);border-radius:12px;width:42px;height:42px;display:flex;align-items:center;justify-content:center;}
        .bell-badge{position:absolute;top:-3px;right:-3px;font-size:.65rem;padding:3px 6px;border-radius:10px;border:2px solid var(--grc-dark);}
        .announcement-item{border-left:4px solid var(--grc-secondary);border-radius:8px;}
        .glass-chat-box{background:rgba(255,255,255,.95);border:1px solid rgba(226,232,240,.8);border-radius:20px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);}
        .floating-toast-container{position:fixed;top:25px;right:25px;z-index:10000;min-width:320px;max-width:450px;background:#fff;border-left:6px solid var(--grc-primary);box-shadow:0 10px 30px rgba(0,0,0,.2);border-radius:12px;}
        .notif-item.notif-unread{background:#fff8f8;border-left:4px solid var(--grc-secondary) !important;}
        .notif-item.notif-read{background:#fff;opacity:.85;}
.dashboard-section + .dashboard-section { margin-top: 1.5rem; }
#academic-records + #ledger            { margin-top: 1.5rem; }
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
        <li><a href="#dashboard" class="nav-link active" data-section="dashboard" data-overview="true"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
        <li class="nav-section-title">FINANCE</li>
        <li><a href="#ledger" class="nav-link" data-section="ledger"><i class="fa-solid fa-file-invoice-dollar"></i><span>Financial Ledger</span></a></li>
        <li class="nav-section-title">COMMUNICATION</li>
        <li><a href="#academic-records" class="nav-link" data-section="academic-records"><i class="fa-solid fa-book-open"></i><span>Academic Records</span></a></li>
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
            <span class="badge bg-white text-danger fw-bold rounded-pill px-3 py-1 mb-2 shadow-sm">Student Module</span>
            <h2 class="fw-extrabold mb-1">Welcome back, <?= htmlspecialchars($display_name) ?>!</h2>
            <p class="mb-0 text-white-50 small">
                Student ID: <?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?> · Course: <?= htmlspecialchars($user_data['department_course'] ?? 'General') ?>
            </p>
        </div>
        <div class="text-end d-none d-md-block">
            <h6 class="mb-0 text-white-50 small text-uppercase fw-semibold"><?= date('l') ?></h6>
            <h3 class="fw-bold mb-0"><?= date('F j, Y') ?></h3>
        </div>
    </div>
</div>
        <!-- ==================== DASHBOARD SECTION ==================== -->
    <div class="dashboard-section active" id="dashboard">
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">General Average</span>
                        <h2 class="fw-bold <?= $gwa === '—' ? 'text-muted' : ($gwa_is_high ? 'text-danger' : 'text-success') ?> mb-0"><?= $gwa ?></h2>
                        <small class="text-success fw-semibold"><i class="fa-solid fa-book-bookmark me-1"></i><?= $subject_count ?> Subjects Released</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background:rgba(234,179,8,.15);color:#ca8a04;"><i class="fa-solid fa-wallet"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Account Balance</span>
                        <h2 class="fw-bold mb-0 <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">₱<?= number_format($balance, 2) ?></h2>
                        <small class="text-muted"><?= $balance > 0 ? 'Pending Settlement' : 'Account Cleared' ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card-modern d-flex align-items-center">
                    <div class="icon-wrapper-gradient me-3" style="background:rgba(59,130,246,.15);color:#2563eb;"><i class="fa-solid fa-bullhorn"></i></div>
                    <div>
                        <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Active Bulletins</span>
                        <h2 class="fw-bold text-dark mb-0"><?= $ann_count ?></h2>
                        <small class="text-primary fw-semibold"><i class="fa-solid fa-bell me-1"></i>Campus Announcements</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <!-- ==================== ACADEMIC RECORDS SECTION ==================== -->
    <div class="dashboard-section active" id="academic-records">
        <div class="row g-4">
            <!-- Academic Records -->
            <div class="col-lg-8">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-book-open me-2 text-danger"></i>Academic Records</h5>
                            <span class="badge <?= $gwa_is_high ? 'bg-danger' : 'bg-success' ?> fs-6 fw-bold">GWA: <?= $gwa ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="../print.php?type=grades" target="_blank" class="btn btn-sm btn-outline-secondary fw-semibold">
                                <i class="fa-solid fa-print me-1"></i> Print
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-dark">
                                <tr><th>Code</th><th>Subject Title</th><th>Semester</th><th class="text-center">Grade</th></tr>
                            </thead>
                            <tbody>
                            <?php if (count($grades) > 0): ?>
                                <?php foreach ($grades as $g): ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark border fw-bold"><?= htmlspecialchars($g['subject_code']) ?></span></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($g['subject_title']) ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($g['semester']) ?></small></td>
                                        <td class="text-center fw-bold <?= grade_color_class($g['grade'], $grade_limit) ?> fs-6"><?= number_format($g['grade'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No academic records released yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!-- Campus Broadcasts (beside Academic Records) -->
            <div class="col-lg-4">
                <div class="card-custom p-4 h-100">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-bullhorn me-2 text-danger"></i>Campus Broadcasts</h5>
                    <div class="overflow-auto" style="max-height:520px;">
                        <?php if ($ann_count > 0): ?>
                            <?php foreach ($announcements_list as $row): ?>
                                <div class="announcement-item mb-3 p-3 bg-light rounded-3">
                                    <strong class="text-dark d-block mb-1"><i class="fa-solid fa-circle-info text-danger me-1 small"></i><?= htmlspecialchars($row['title']) ?></strong>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($row['content']) ?></p>
                                    <small class="text-secondary d-block text-end fw-semibold">— <?= htmlspecialchars($row['posted_by']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="small text-muted text-center py-5">No broadcasts posted yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
        <!-- ==================== FINANCIAL LEDGER SECTION ==================== -->
    <div class="dashboard-section active" id="ledger">
        <div class="row g-4">
            <!-- Financial Ledger -->
            <div class="col-lg-12">
                <div class="card-custom p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-file-invoice-dollar me-2 text-danger"></i>Financial Ledger
                        </h5>
                        <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold"
                                onclick="printLedger()" title="Print Financial Ledger">
                            <i class="fa-solid fa-print me-1"></i> Print
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead class="table-dark">
                                <tr><th>Date</th><th>Description</th><th>Type</th><th class="text-end">Amount</th></tr>
                            </thead>
                            <tbody>
                            <?php $total_balance = 0.00; ?>
                            <?php if (count($ledger_rows) > 0): ?>
                                <?php foreach ($ledger_rows as $l_row):
                                    $amt = (float)$l_row['amount'];
                                    $type = $l_row['transaction_type'];
                                    if ($type === 'Charge'){ $total_balance += $amt; $badge = 'bg-danger-subtle text-danger border border-danger-subtle'; }
                                    else { $total_balance -= $amt; $badge = 'bg-success-subtle text-success border border-success-subtle'; }
                                ?>
                                    <tr>
                                        <td><small class="text-muted fw-semibold"><?= date('M d, Y', strtotime($l_row['transaction_date'])) ?></small></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($l_row['description']) ?></td>
                                        <td><span class="badge rounded-pill px-3 <?= $badge ?>"><?= strtoupper(htmlspecialchars($type)) ?></span></td>
                                        <td class="text-end fw-bold">₱<?= number_format($amt, 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No financial records found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="fw-bold text-end pt-3">Current Balance:</td>
                                    <td class="text-end fw-bold text-danger fs-6 pt-3">₱<?= number_format($total_balance, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== FLOATING SUPPORT CHAT ==================== -->
<button id="studentChatToggle" class="btn btn-grc-action position-fixed bottom-0 end-0 m-4 rounded-circle shadow-lg p-3" style="z-index:1000;width:60px;height:60px;" onclick="toggleStudentChat()">
    <i class="fa-solid fa-comments fs-4"></i>
    <span id="studentChatBadge" class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" style="display:none;font-size:.65rem;">0</span>
</button>
<div id="studentChatBox" class="glass-chat-box position-fixed bottom-0 end-0 m-4 d-none" style="width:360px;z-index:1001;overflow:hidden;">
    <div class="p-3 text-white d-flex justify-content-between align-items-center" style="background:var(--grc-dark);border-bottom:2px solid var(--grc-secondary);">
        <h6 class="mb-0 fw-bold"><i class="fa-solid fa-headset text-danger me-2"></i>Help Desk</h6>
        <button type="button" class="btn-close btn-close-white btn-sm" onclick="toggleStudentChat()"></button>
    </div>
    <div class="p-3" id="studentChatContainer" style="height:320px;overflow-y:auto;background:rgba(248,250,252,.8);"></div>
    <div class="bg-white border-top p-2">
        <form onsubmit="sendStudentMessage(event)">
            <div class="input-group">
                <input type="text" id="studentChatMessage" class="form-control form-control-sm border-0 bg-light" placeholder="Type your message..." required>
                <button type="submit" class="btn btn-grc-action btn-sm px-3"><i class="fa-solid fa-paper-plane"></i></button>
            </div>
        </form>
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
                    <span class="badge px-3 py-1 rounded-pill mt-1" style="background:var(--grc-primary);">Student</span>
                </div>
                <div class="list-group list-group-flush border-top border-bottom">
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Student ID:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Username:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['username']) ?></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Email:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['email']) ?></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Course / Dept:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['department_course'] ?? 'N/A') ?></span></div>
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
    <div class="offcanvas-body bg-light">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-inbox me-2 text-danger"></i>Your Notifications</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="markAllNotificationsRead()"><i class="fa-solid fa-check-double me-1"></i> Mark all read</button>
        </div>
        <?php if (count($notification_list) > 0): ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/ui-feedback.js"></script>
<script>
<?php if (!empty($_SESSION['msg'])): ?>
    showToastFromSession(<?= json_encode($_SESSION['msg']) ?>, <?= json_encode($_SESSION['msg_type'] ?? 'info') ?>);
    <?php unset($_SESSION['msg'], $_SESSION['msg_type']); ?>
<?php endif; ?>

function escapeHtml(t){ if(!t)return''; return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

/* ---------- Print the Financial Ledger only ---------- */
function printLedger(){
    const ledger = document.getElementById('ledger');
    if (!ledger) { window.print(); return; }

    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Financial Ledger — StudentFLOW</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body{font-family:Arial, sans-serif;padding:24px;color:#1e293b;}
                h1{font-family:'Cinzel', serif;color:#800000;text-align:center;margin:0 0 4px;}
                .sub{text-align:center;color:#6b7280;font-size:12px;margin-bottom:20px;}
                .badge-danger-subtle{background:#fde8e8;color:#b91c1c;border:1px solid #f5c2c2;padding:2px 8px;border-radius:6px;font-size:11px;}
                .badge-success-subtle{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:2px 8px;border-radius:6px;font-size:11px;}
                table{width:100%;border-collapse:collapse;margin-top:8px;}
                th,td{border:1px solid #e5e7eb;padding:8px;font-size:13px;}
                th{background:#1e293b;color:#fff;text-align:left;}
                tfoot td{font-weight:bold;}
                .text-end{text-align:right;}
            </style>
        </head>
        <body>
            <h1>GLOBAL RECIPROCAL COLLEGES</h1>
            <div class="sub">Official Student Financial Ledger — Generated on ${new Date().toLocaleDateString()}</div>
            <div style="font-size:13px;margin-bottom:10px;">
                <strong>Student:</strong> <?= htmlspecialchars($display_name) ?> &nbsp;·&nbsp;
                <strong>ID:</strong> <?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?>
            </div>
            ${ledger.querySelector('.table-responsive').innerHTML}
        </body>
        </html>
    `);
    w.document.close();
    w.focus();
    setTimeout(() => { w.print(); }, 250);
}

/* =========================================================================
   STUDENT CHAT — synchronized with notifications
   ========================================================================= */
let studentChatInterval = null;

function updateStudentChatBadge(count){
    const badge = document.getElementById('studentChatBadge');
    if (!badge) return;
    if (count > 0) {
        badge.textContent = count;
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
    }
}

function toggleStudentChat(){
    const box = document.getElementById('studentChatBox');
    const opening = box.classList.contains('d-none');
    box.classList.toggle('d-none');

    if (opening) {
        // Student explicitly opened the chat → mark as read.
        fetchStudentMessages(true);
        studentChatInterval = setInterval(() => fetchStudentMessages(true), 3000);
    } else if (studentChatInterval) {
        clearInterval(studentChatInterval);
        studentChatInterval = null;
    }
}

async function fetchStudentMessages(markRead){
    try {
        const url = 'dashboard.php?chat_action=fetch' + (markRead ? '&mark_read=1' : '');
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (!res.ok || data.status !== 'success') return;

        const c = document.getElementById('studentChatContainer');
        let html = '';
        (data.messages || []).forEach(m => {
            const me = m.sender_role === 'student';
            html += `<div class="d-flex flex-column mb-2 ${me?'align-items-end':'align-items-start'}">
                <div class="p-2 rounded-3 text-white small ${me?'bg-danger':'bg-dark'}" style="max-width:80%;">${escapeHtml(m.message)}</div>
                <span class="text-muted" style="font-size:.65rem;">${m.time}</span>
            </div>`;
        });
        c.innerHTML = html;
        c.scrollTop = c.scrollHeight;

        // Chat badge always reflects the server's unread count for admin messages.
        if (typeof data.unread_count === 'number') {
            updateStudentChatBadge(data.unread_count);
        }

        // If we actually marked read, refresh the bell too.
        if (markRead) refreshNotifCount();
    } catch (err) {
        console.error('fetchStudentMessages:', err);
    }
}

async function sendStudentMessage(e){
    e.preventDefault();
    const input = document.getElementById('studentChatMessage');
    const msg   = input.value.trim();
    if (!msg) return;

    const fd = new FormData();
    fd.append('message', msg);
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);

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
        // Refresh the currently open chat view.
        fetchStudentMessages(true);
    } catch (err) {
        console.error('sendStudentMessage:', err);
        showFloatingToast('Error', err.message || 'Unable to send message.', 'danger');
    }
}

/* Poll the chat badge only — does NOT mark anything as read. */
async function refreshStudentChatBadge(){
    try {
        const res = await fetch('dashboard.php?chat_action=fetch', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (!res.ok || data.status !== 'success') return;
        if (typeof data.unread_count === 'number') {
            updateStudentChatBadge(data.unread_count);
        }
    } catch (err) {
        console.error('refreshStudentChatBadge:', err);
    }
}

/* ---------- Notifications ---------- */
function updateNotifBadge(count){
    const el = document.getElementById('notifBadge');
    if (!el) return;
    if (count > 0){ el.textContent = count; el.style.display = ''; } else { el.style.display = 'none'; }
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
                    const b = c.querySelector('.badge.bg-danger'); if (b && b.textContent.trim() === 'NEW') b.remove();
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
                    const b = c.querySelector('.badge.bg-danger'); if (b && b.textContent.trim() === 'NEW') b.remove();
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

/* Startup: initial counts + polling */
document.addEventListener('DOMContentLoaded', () => {
    const toast = document.querySelector('.floating-toast-container');
    if (toast) setTimeout(() => bootstrap.Alert.getOrCreateInstance(toast).close(), 4000);

    // Prime both badges.
    refreshNotifCount();
    refreshStudentChatBadge();

    // Poll both badges every 15 s. Only refreshStudentChatBadge runs when the
    // chat is closed; it never marks messages read.
    setInterval(() => {
        const chatOpen = !document.getElementById('studentChatBox').classList.contains('d-none');
        if (!chatOpen) {
            refreshNotifCount();
            refreshStudentChatBadge();
        }
    }, 15000);
});
</script>

<!--
    ⚠️  REMOVED: The old "STUDENT-ONLY OVERRIDE" <script> block that used to live here.

    Why it's gone:
      The shared script (assets/js/dashboard-navigation.js) now handles overview
      mode natively by checking `data-overview="true"` on the clicked nav link
      BEFORE hiding any sections. This makes the entire override unnecessary.

    What still works:
      • Clicking "Dashboard" → shows ALL sections (Dashboard + Ledger + Academic Records)
      • Clicking "Financial Ledger" → shows only the Ledger
      • Clicking "Academic Records" → shows only Academic Records
      • Hash routing (#dashboard, #ledger, #academic-records) works on load & refresh
      • Browser Back/Forward works
-->

<script src="../assets/js/dashboard-navigation.js"></script>
</body>
</html>