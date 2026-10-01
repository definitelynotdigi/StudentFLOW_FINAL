<?php
/* =============================================================================
   /finance/dashboard.php  —  Finance Module (with Finance → Admin → Student)
   ============================================================================= */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($current_role !== 'finance') {
    header("Location: ../index.php?login_error=forbidden");
    exit();
}

require_once __DIR__ . '/../includes/chat.php';
require_once __DIR__ . '/../includes/notifications.php';

$grade_limit = GRADE_LIMIT;

/* -------------------------------------------------------------------------
   Local helpers
   ------------------------------------------------------------------------- */
function next_reference(PDO $pdo, string $prefix): string {
    $year = date('Y');

    // Retry up to 5 times with a random 4-digit suffix to avoid collisions.
    // Falls back to a guaranteed-unique hex string if all attempts collide.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = sprintf('%s-%s-%04d', $prefix, $year, random_int(1, 9999));
        $check = $pdo->prepare("SELECT 1 FROM financial_ledgers WHERE reference_no = ? LIMIT 1");
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
    }

    // Fallback — astronomically unlikely to be reached
    return sprintf('%s-%s-%s', $prefix, $year, strtoupper(bin2hex(random_bytes(3))));
}

function audit_log_fin(PDO $pdo, ?int $userId, string $action, string $details = '',
                       ?string $targetType = null, ?int $targetId = null): void {
    try {
        static $cols = null;
        if ($cols === null) {
            $cols = $pdo->query("SHOW COLUMNS FROM audit_logs")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        if (!$cols) return;

        $has = fn($c) => in_array($c, $cols, true);
        $fields = []; $values = [];
        if ($has('user_id'))     { $fields[]='user_id';     $values[]=$userId; }
        if ($has('action'))      { $fields[]='action';      $values[]=$action; }
        if ($has('details'))     { $fields[]='details';     $values[]=$details; }
        if ($has('target_type')) { $fields[]='target_type'; $values[]=$targetType; }
        if ($has('target_id'))   { $fields[]='target_id';   $values[]=$targetId; }
        if (!$fields) return;

        $ph = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO audit_logs (".implode(',', $fields).") VALUES ($ph)")
            ->execute($values);
    } catch (Throwable $e) { error_log('[audit_log_fin] '.$e->getMessage()); }
}

function fetch_ledger_for_student(PDO $pdo, int $studentUserId): array {
    $s = $pdo->prepare("
        SELECT fl.*,
               u.first_name AS creator_first,
               u.last_name  AS creator_last,
               u.username   AS creator_username,
               a.username   AS reviewer_username
        FROM financial_ledgers fl
        LEFT JOIN users u ON fl.created_by  = u.id
        LEFT JOIN users a ON fl.reviewed_by = a.id
        WHERE fl.student_id = ?
        ORDER BY fl.transaction_date ASC, fl.id ASC
    ");
    $s->execute([$studentUserId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function ledger_totals(array $rows): array {
    $charges = 0.0; $payments = 0.0;
    foreach ($rows as $r) {
        if (!empty($r['voided'])) continue;
        if (($r['status'] ?? 'approved') !== 'approved') continue;
        $amt = (float)$r['amount'];
        if ($r['transaction_type'] === 'Charge') $charges += $amt; else $payments += $amt;
    }
    return ['charges'=>$charges, 'payments'=>$payments, 'balance'=>$charges-$payments];
}

/* ==========================================================================
   POST HANDLERS
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    /* -------- ADD CHARGE -------- */
    if (isset($_POST['add_charge'])) {
        $student_code = trim($_POST['student_id'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $category     = trim($_POST['fee_category'] ?? '');
        $semester     = trim($_POST['semester'] ?? '');
        $school_year  = trim($_POST['school_year'] ?? '');
        $amount       = (float)($_POST['amount'] ?? 0);
        $tdate        = trim($_POST['transaction_date'] ?? '');
        $ref_no       = trim($_POST['reference_no'] ?? '');
        $remarks      = trim($_POST['remarks'] ?? '');
        $want_submit  = !empty($_POST['submit_to_admin']);

        $err = null;
        if ($student_code === '') $err = "Student ID is required.";
        elseif ($description === '') $err = "Description is required.";
        elseif ($amount <= 0) $err = "Amount must be greater than zero.";
        elseif ($tdate === '') $err = "Transaction date is required.";

        $student = $err ? null : find_user_by_code($pdo, $student_code, ['student']);
        if (!$err && !$student) $err = "Student ID '{$student_code}' not found.";

        if ($err) { $_SESSION['msg']=$err; $_SESSION['msg_type']='danger'; }
        else {
            if ($ref_no === '') $ref_no = next_reference($pdo, 'CHG');
            $status = $want_submit ? 'submitted' : 'draft';
            $submitted_at = $want_submit ? date('Y-m-d H:i:s') : null;

            $ins = $pdo->prepare("
                INSERT INTO financial_ledgers
                    (student_id, finance_id, description, fee_category, semester, school_year,
                     reference_no, amount, transaction_type, transaction_date, remarks,
                     status, submitted_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Charge', ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                (int)$student['id'], (int)$_SESSION['user_id'], $description,
                $category !== '' ? $category : null,
                $semester !== '' ? $semester : null,
                $school_year !== '' ? $school_year : null,
                $ref_no, $amount, $tdate,
                $remarks !== '' ? $remarks : null,
                $status, $submitted_at, (int)$_SESSION['user_id']
            ]);
            $new_id = (int)$pdo->lastInsertId();

            audit_log_fin($pdo, (int)$_SESSION['user_id'],
                $want_submit ? 'FINANCIAL_RECORD_SUBMITTED' : 'FINANCIAL_CHARGE_DRAFTED',
                "$ref_no | ₱".number_format($amount,2)." | student $student_code",
                'financial_ledger', $new_id);

            if ($want_submit) {
                $admins = $pdo->query("SELECT id FROM users WHERE LOWER(role) IN ('admin','department')")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $aid) {
                    create_notification(
                        $pdo, (int)$aid, (int)$_SESSION['user_id'], 'finance_to_admin',
                        'New Tuition/Fee Submission',
                        "Finance submitted {$ref_no} (₱".number_format($amount,2).") for student {$student_code}. Awaiting review.",
                        'financial_ledger', $new_id, $student_code
                    );
                }
                $_SESSION['msg'] = "Charge submitted to Admin (Ref: {$ref_no}).";
            } else {
                $_SESSION['msg'] = "Charge saved as draft (Ref: {$ref_no}). You can submit it later.";
            }
            $_SESSION['msg_type'] = 'success';
        }
        header("Location: dashboard.php?student=".urlencode($student_code));
        exit();
    }

    /* -------- RECORD PAYMENT -------- */
    if (isset($_POST['record_payment'])) {
        $student_code = trim($_POST['student_id'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $amount       = (float)($_POST['amount'] ?? 0);
        $tdate        = trim($_POST['transaction_date'] ?? '');
        $ref_no       = trim($_POST['reference_no'] ?? '');
        $method       = trim($_POST['payment_method'] ?? '');
        $remarks      = trim($_POST['remarks'] ?? '');
        $want_submit  = !empty($_POST['submit_to_admin']);

        $allowed = ['Cash','Bank Transfer','GCash','Card','Other'];

        $err = null;
        if ($student_code === '') $err = "Student ID is required.";
        elseif ($description === '') $err = "Description is required.";
        elseif ($amount <= 0) $err = "Amount must be greater than zero.";
        elseif ($tdate === '') $err = "Transaction date is required.";
        elseif ($method !== '' && !in_array($method, $allowed, true)) $err = "Invalid payment method.";

        $student = $err ? null : find_user_by_code($pdo, $student_code, ['student']);
        if (!$err && !$student) $err = "Student ID '{$student_code}' not found.";

        if ($err) { $_SESSION['msg']=$err; $_SESSION['msg_type']='danger'; }
        else {
            if ($ref_no === '') $ref_no = next_reference($pdo, 'PAY');
            $status = $want_submit ? 'submitted' : 'draft';
            $submitted_at = $want_submit ? date('Y-m-d H:i:s') : null;

            $ins = $pdo->prepare("
                INSERT INTO financial_ledgers
                    (student_id, finance_id, description, reference_no, amount, transaction_type,
                     payment_method, transaction_date, remarks, status, submitted_at, created_by)
                VALUES (?, ?, ?, ?, ?, 'Payment', ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                (int)$student['id'], (int)$_SESSION['user_id'], $description, $ref_no, $amount,
                $method !== '' ? $method : null, $tdate,
                $remarks !== '' ? $remarks : null,
                $status, $submitted_at, (int)$_SESSION['user_id']
            ]);
            $new_id = (int)$pdo->lastInsertId();

            audit_log_fin($pdo, (int)$_SESSION['user_id'],
                $want_submit ? 'FINANCIAL_RECORD_SUBMITTED' : 'FINANCIAL_PAYMENT_DRAFTED',
                "$ref_no | ₱".number_format($amount,2)." | student $student_code",
                'financial_ledger', $new_id);

            if ($want_submit) {
                $admins = $pdo->query("SELECT id FROM users WHERE LOWER(role) IN ('admin','department')")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $aid) {
                    create_notification(
                        $pdo, (int)$aid, (int)$_SESSION['user_id'], 'finance_to_admin',
                        'New Payment Submission',
                        "Finance submitted payment {$ref_no} (₱".number_format($amount,2).") for student {$student_code}. Awaiting review.",
                        'financial_ledger', $new_id, $student_code
                    );
                }
                $_SESSION['msg'] = "Payment submitted to Admin (Ref: {$ref_no}).";
            } else {
                $_SESSION['msg'] = "Payment saved as draft (Ref: {$ref_no}).";
            }
            $_SESSION['msg_type'] = 'success';
        }
        header("Location: dashboard.php?student=".urlencode($student_code));
        exit();
    }

    /* -------- SUBMIT EXISTING DRAFT/RETURNED -------- */
    if (isset($_POST['submit_existing'])) {
        $id = (int)($_POST['transaction_id'] ?? 0);
        $row = $pdo->prepare("SELECT * FROM financial_ledgers WHERE id = ? AND voided = 0");
        $row->execute([$id]);
        $rec = $row->fetch(PDO::FETCH_ASSOC);

        if ($rec && in_array($rec['status'], ['draft','returned'], true)) {
            $pdo->prepare("UPDATE financial_ledgers SET status='submitted', submitted_at=NOW(), admin_remarks=NULL WHERE id=?")
                ->execute([$id]);
            audit_log_fin($pdo, (int)$_SESSION['user_id'], 'FINANCIAL_RECORD_SUBMITTED',
                "Resubmitted ledger #{$id} ({$rec['reference_no']})", 'financial_ledger', $id);

            $admins = $pdo->query("SELECT id FROM users WHERE LOWER(role) IN ('admin','department')")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $aid) {
                create_notification($pdo, (int)$aid, (int)$_SESSION['user_id'], 'finance_to_admin',
                    'New Tuition/Fee Submission',
                    "Finance re-submitted {$rec['reference_no']} for review.",
                    'financial_ledger', $id, null);
            }
            $_SESSION['msg'] = "Record submitted to Admin."; $_SESSION['msg_type']='success';
        } else {
            $_SESSION['msg'] = "Only draft or returned records can be submitted."; $_SESSION['msg_type']='warning';
        }
        header("Location: dashboard.php"); exit();
    }

    /* -------- EDIT -------- */
    if (isset($_POST['edit_transaction'])) {
        $id = (int)($_POST['transaction_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $tdate = trim($_POST['transaction_date'] ?? '');
        $ref = trim($_POST['reference_no'] ?? '');
        $method = trim($_POST['payment_method'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        $row = $pdo->prepare("SELECT * FROM financial_ledgers WHERE id = ? LIMIT 1");
        $row->execute([$id]); $existing = $row->fetch(PDO::FETCH_ASSOC);

        $err = null;
        if (!$existing) $err = "Transaction could not be found.";
        elseif (!empty($existing['voided'])) $err = "Voided transactions cannot be edited.";
        elseif (($existing['status'] ?? '') === 'submitted') $err = "Submitted records can't be edited until Admin returns them.";
        elseif (($existing['status'] ?? '') === 'approved')  $err = "Approved records can't be edited. Void and recreate if needed.";
        elseif ($description === '') $err = "Description is required.";
        elseif ($amount <= 0) $err = "Amount must be greater than zero.";
        elseif ($tdate === '') $err = "Transaction date is required.";

        if ($err) { $_SESSION['msg']=$err; $_SESSION['msg_type']='danger'; }
        else {
            $pdo->prepare("UPDATE financial_ledgers
                              SET description=?, amount=?, transaction_date=?, reference_no=?,
                                  payment_method=?, remarks=?, status='draft'
                            WHERE id=?")
                ->execute([$description, $amount, $tdate,
                    $ref !== '' ? $ref : $existing['reference_no'],
                    $method !== '' ? $method : $existing['payment_method'],
                    $remarks !== '' ? $remarks : $existing['remarks'],
                    $id]);
            audit_log_fin($pdo, (int)$_SESSION['user_id'], 'FINANCIAL_RECORD_EDITED',
                "Edited ledger #{$id}", 'financial_ledger', $id);
            $_SESSION['msg']='Transaction updated.'; $_SESSION['msg_type']='success';
        }
        $back = $_POST['return_student'] ?? '';
        header("Location: dashboard.php".($back !== '' ? '?student='.urlencode($back) : '')); exit();
    }

    /* -------- VOID -------- */
    if (isset($_POST['void_transaction'])) {
        $id = (int)($_POST['transaction_id'] ?? 0);
        $reason = trim($_POST['void_reason'] ?? '');
        $pdo->prepare("UPDATE financial_ledgers SET voided=1, status='voided', void_reason=?, voided_by=?, voided_at=NOW() WHERE id=?")
            ->execute([$reason !== '' ? $reason : null, (int)$_SESSION['user_id'], $id]);
        audit_log_fin($pdo, (int)$_SESSION['user_id'], 'FINANCIAL_RECORD_VOIDED',
            "Voided ledger #{$id}", 'financial_ledger', $id);
        $_SESSION['msg']='Transaction voided.'; $_SESSION['msg_type']='warning';
        header("Location: dashboard.php"); exit();
    }

    /* -------- PRINT audit -------- */
    if (isset($_POST['log_print'])) {
        audit_log_fin($pdo, (int)$_SESSION['user_id'], 'PRINT STATEMENT',
            "Printed financial statement for ".trim($_POST['student_code'] ?? ''),
            'financial_ledger', null);
        echo json_encode(['status'=>'success']); exit();
    }
}

/* ==========================================================================
   DATA
   ========================================================================== */
$sum = $pdo->query("
    SELECT
      COALESCE(SUM(CASE WHEN transaction_type='Charge'  THEN amount END), 0) AS c,
      COALESCE(SUM(CASE WHEN transaction_type='Payment' THEN amount END), 0) AS p
    FROM financial_ledgers
    WHERE voided = 0 AND status = 'approved'
")->fetch(PDO::FETCH_ASSOC);
$total_charges  = (float)$sum['c'];
$total_payments = (float)$sum['p'];
$outstanding    = $total_charges - $total_payments;

$pending_count  = (int)$pdo->query("SELECT COUNT(*) FROM financial_ledgers WHERE status='submitted' AND voided=0")->fetchColumn();
$approved_count = (int)$pdo->query("SELECT COUNT(*) FROM financial_ledgers WHERE status='approved' AND voided=0")->fetchColumn();
$returned_count = (int)$pdo->query("SELECT COUNT(*) FROM financial_ledgers WHERE status='returned' AND voided=0")->fetchColumn();

$students_with_balances = (int)$pdo->query("
    SELECT COUNT(*) FROM (
        SELECT student_id,
               SUM(CASE WHEN transaction_type='Charge'  THEN amount ELSE 0 END)
             - SUM(CASE WHEN transaction_type='Payment' THEN amount ELSE 0 END) AS bal
        FROM financial_ledgers
        WHERE voided=0 AND status='approved'
        GROUP BY student_id
        HAVING bal > 0
    ) AS t
")->fetchColumn();

$search = trim($_GET['q'] ?? '');
$selected_code = trim($_GET['student'] ?? '');
$search_results = [];

if ($search !== '') {
    $like = '%'.$search.'%';
    $ss = $pdo->prepare("
        SELECT id, student_id, first_name, last_name, department, course
        FROM users
        WHERE LOWER(role)='student'
          AND (student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
        ORDER BY student_id LIMIT 25
    ");
    $ss->execute([$like, $like, $like]);
    $search_results = $ss->fetchAll(PDO::FETCH_ASSOC);
}

$selected_student = null;
$selected_ledger  = [];
$selected_totals  = ['charges'=>0.0, 'payments'=>0.0, 'balance'=>0.0];

if ($selected_code !== '') {
    $stu = $pdo->prepare("SELECT id, student_id, first_name, last_name, department, course, department_course
                          FROM users WHERE LOWER(role)='student' AND student_id=? LIMIT 1");
    $stu->execute([$selected_code]);
    $selected_student = $stu->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selected_student) {
        $selected_ledger = fetch_ledger_for_student($pdo, (int)$selected_student['id']);
        $selected_totals = ledger_totals($selected_ledger);
    }
}

$pending_rows = $pdo->query("
    SELECT fl.*, u.student_id AS stud_code,
           CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS stud_name
    FROM financial_ledgers fl
    JOIN users u ON fl.student_id = u.id
    WHERE fl.voided = 0 AND fl.status IN ('draft','submitted','returned')
    ORDER BY fl.id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$f_student = trim($_GET['f_student'] ?? '');
$f_type    = trim($_GET['f_type'] ?? 'All');
$f_status  = trim($_GET['f_status'] ?? 'All');
$f_from    = trim($_GET['f_date_from'] ?? '');
$f_to      = trim($_GET['f_date_to'] ?? '');
$f_ref     = trim($_GET['f_ref'] ?? '');

$where = ["fl.voided = 0"]; $params = [];
if ($f_student !== '') { $where[]="(u.student_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)"; $ls='%'.$f_student.'%'; array_push($params,$ls,$ls,$ls); }
if (in_array($f_type, ['Charge','Payment'], true)) { $where[]="fl.transaction_type = ?"; $params[]=$f_type; }
if (in_array($f_status, ['draft','submitted','approved','returned','voided'], true)) { $where[]="fl.status = ?"; $params[]=$f_status; }
if ($f_from !== '') { $where[]="fl.transaction_date >= ?"; $params[]=$f_from; }
if ($f_to   !== '') { $where[]="fl.transaction_date <= ?"; $params[]=$f_to; }
if ($f_ref  !== '') { $where[]="fl.reference_no LIKE ?"; $params[]='%'.$f_ref.'%'; }
$where_sql = implode(' AND ', $where);

$th = $pdo->prepare("
    SELECT fl.*, u.student_id AS stud_code,
           CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS stud_name,
           cu.username AS created_by_username,
           ru.username AS reviewed_by_username
    FROM financial_ledgers fl
    JOIN users u ON fl.student_id = u.id
    LEFT JOIN users cu ON fl.created_by  = cu.id
    LEFT JOIN users ru ON fl.reviewed_by = ru.id
    WHERE {$where_sql}
    ORDER BY fl.transaction_date DESC, fl.id DESC LIMIT 200
");
$th->execute($params);
$transactions = $th->fetchAll(PDO::FETCH_ASSOC);

$rep_from = trim($_GET['rep_from'] ?? date('Y-m-01'));
$rep_to   = trim($_GET['rep_to']   ?? date('Y-m-d'));
$rp = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN transaction_type='Charge'  THEN amount END),0) AS c,
           COALESCE(SUM(CASE WHEN transaction_type='Payment' THEN amount END),0) AS p
    FROM financial_ledgers
    WHERE voided=0 AND status='approved' AND transaction_date BETWEEN ? AND ?
");
$rp->execute([$rep_from,$rep_to]); $rep = $rp->fetch(PDO::FETCH_ASSOC);

$daily = $pdo->prepare("
    SELECT transaction_date AS d,
           SUM(CASE WHEN transaction_type='Charge'  THEN amount ELSE 0 END) AS c,
           SUM(CASE WHEN transaction_type='Payment' THEN amount ELSE 0 END) AS p
    FROM financial_ledgers
    WHERE voided=0 AND status='approved' AND transaction_date BETWEEN ? AND ?
    GROUP BY transaction_date ORDER BY transaction_date DESC
");
$daily->execute([$rep_from,$rep_to]); $daily_rows = $daily->fetchAll(PDO::FETCH_ASSOC);

$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_user_id=? AND is_read=0");
$unread_stmt->execute([$user_id]); $total_notifications = (int)$unread_stmt->fetchColumn();

$notif_list_stmt = $pdo->prepare("
    SELECT n.*, CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,'')) AS sender_name, s.role AS sender_role
    FROM notifications n LEFT JOIN users s ON n.sender_user_id=s.id
    WHERE n.recipient_user_id=? ORDER BY n.created_at DESC LIMIT 15
");
$notif_list_stmt->execute([$user_id]); $notification_list = $notif_list_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentFLOW | Finance Dashboard</title>
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
        .stat-card-modern{background:#fff;border:1px solid var(--grc-border);border-radius:18px;padding:1.5rem;box-shadow:0 4px 6px -1px rgba(0,0,0,0.03);height:100%;}
        .icon-wrapper-gradient{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.35rem;background:linear-gradient(135deg,rgba(171,10,10,.1) 0%,rgba(128,0,0,.2) 100%);color:var(--grc-secondary);}
        .floating-toast-container{position:fixed;top:25px;right:25px;z-index:10000;min-width:320px;max-width:450px;background:#fff;border-left:6px solid var(--grc-primary);box-shadow:0 10px 30px rgba(0,0,0,0.2);border-radius:12px;}
        .notif-item.notif-unread{background:#fff8f8;border-left:4px solid var(--grc-secondary) !important;}
        .notif-item.notif-read{background:#fff;opacity:.85;}
        .table-modern thead th{background:#f8fafc;text-transform:uppercase;font-size:.725rem;letter-spacing:.06em;color:#64748b;font-weight:700;padding:1rem;}
        .table-modern tbody td{padding:1rem;border-bottom:1px solid #f1f5f9;font-size:.9rem;}

        .status-badge{font-size:.7rem;letter-spacing:.03em;text-transform:uppercase;font-weight:700;}
        .status-draft{background:#e2e8f0;color:#475569;}
        .status-submitted{background:#fef3c7;color:#92400e;}
        .status-approved{background:#dcfce7;color:#166534;}
        .status-returned{background:#dbeafe;color:#1e40af;}
        .status-voided{background:#fee2e2;color:#991b1b;}

        @media print{
            body *{visibility:hidden !important;}
            #printable-statement, #printable-statement *{visibility:visible !important;}
            #printable-statement{position:absolute;left:0;top:0;width:100%;padding:20px !important;background:#fff !important;}
            .no-print{display:none !important;}
        }
        body.print-mode { background:#f8f9fa !important; }
        body.print-mode #sidebar,
        body.print-mode .top-header,
        body.print-mode .dashboard-hero,
        body.print-mode .no-print,
        body.print-mode .dashboard-section,
        body.print-mode .modal,
        body.print-mode .offcanvas { display:none !important; }

        body.print-mode .main-content { margin:0 !important; padding:0 !important; }
        body.print-mode .container-fluid { padding:0 !important; }

        body.print-mode #printable-statement {
            position: static;
            max-width: 800px;
            margin: 24px auto;
            border: 2px solid #800000 !important;
            padding: 45px !important;
            border-radius: 8px;
            background: #fff !important;
            box-shadow: 0 10px 25px rgba(0,0,0,.05);
        }

        @media print {
            body.print-mode { background:#fff !important; }
            body.print-mode #printable-statement {
                border:1px solid #000 !important;
                box-shadow:none !important;
                padding:20px !important;
                margin:0 !important;
                max-width:100% !important;
            }
            @page { size: A4; margin: 12mm; }
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
        <li class="nav-section-title">ACCOUNTS</li>
        <li><a href="#dashboard" class="nav-link active" data-section="dashboard"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
        <li><a href="#accounts" class="nav-link" data-section="accounts"><i class="fa-solid fa-magnifying-glass"></i><span>Student Accounts</span></a></li>
        <li class="nav-section-title">WORKFLOW</li>
        <li><a href="#queue" class="nav-link" data-section="queue"><i class="fa-solid fa-hourglass-half"></i><span>Pending</span></a></li>
        <li><a href="#transactions" class="nav-link" data-section="transactions"><i class="fa-solid fa-list"></i><span>Transactions</span></a></li>
        <li class="nav-section-title">REPORTING</li>
        <li><a href="#reports" class="nav-link" data-section="reports"><i class="fa-solid fa-chart-column"></i><span>Reports</span></a></li>
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
            <button class="bell-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#notifDrawer">
                <i class="fa-solid fa-bell fs-6"></i>
                <span id="notifBadge" class="badge bg-danger bell-badge" style="<?= $total_notifications>0 ? '' : 'display:none;' ?>"><?= $total_notifications ?></span>
            </button>
        </div>
    </header>
    <div class="container-fluid p-0">
        <div class="container-fluid px-lg-5 py-4">

            <!-- HERO -->
            <div class="dashboard-hero mb-4 d-flex justify-content-between align-items-center no-print">
                <div>
                    <span class="badge bg-white text-danger fw-bold rounded-pill px-3 py-1 mb-2 shadow-sm">Finance Module</span>
                    <h2 class="fw-extrabold mb-1">Welcome, <?= htmlspecialchars($display_name) ?>!</h2>
                    <p class="mb-0 text-white-50 small">
                        Finance ID: <?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?> · Department: <?= htmlspecialchars($user_data['department_course'] ?? 'Finance Office') ?>
                    </p>
                </div>
                <div class="text-end d-none d-md-block">
                    <h6 class="mb-0 text-white-50 small text-uppercase fw-semibold"><?= date('l') ?></h6>
                    <h3 class="fw-bold mb-0"><?= date('F j, Y') ?></h3>
                </div>
            </div>

            <!-- ==================== DASHBOARD OVERVIEW ==================== -->
            <div class="dashboard-section active" id="dashboard">
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="stat-card-modern d-flex align-items-center">
                            <div class="icon-wrapper-gradient me-3"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                            <div>
                                <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Total Charges</span>
                                <h4 class="fw-bold text-dark mb-0">₱<?= number_format($total_charges, 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="stat-card-modern d-flex align-items-center">
                            <div class="icon-wrapper-gradient me-3" style="background:rgba(25,135,84,.15);color:#198754;"><i class="fa-solid fa-sack-dollar"></i></div>
                            <div>
                                <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Total Payments</span>
                                <h4 class="fw-bold text-success mb-0">₱<?= number_format($total_payments, 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="stat-card-modern d-flex align-items-center">
                            <div class="icon-wrapper-gradient me-3" style="background:rgba(220,53,69,.15);color:#dc3545;"><i class="fa-solid fa-scale-unbalanced"></i></div>
                            <div>
                                <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Outstanding</span>
                                <h4 class="fw-bold <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?> mb-0">₱<?= number_format($outstanding, 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="stat-card-modern d-flex align-items-center">
                            <div class="icon-wrapper-gradient me-3" style="background:rgba(255,193,7,.15);color:#ca8a04;"><i class="fa-solid fa-user-clock"></i></div>
                            <div>
                                <span class="text-uppercase text-muted fw-bold small" style="font-size:.7rem;">Students With Balances</span>
                                <h4 class="fw-bold text-dark mb-0"><?= $students_with_balances ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="p-3 bg-warning-subtle border border-warning rounded-3 d-flex justify-content-between">
                            <span class="fw-bold text-dark small">PENDING APPROVAL</span>
                            <span class="badge bg-warning text-dark fs-6"><?= $pending_count ?></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-success-subtle border border-success rounded-3 d-flex justify-content-between">
                            <span class="fw-bold text-dark small">APPROVED RECORDS</span>
                            <span class="badge bg-success fs-6"><?= $approved_count ?></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-info-subtle border border-info rounded-3 d-flex justify-content-between">
                            <span class="fw-bold text-dark small">RETURNED TO ME</span>
                            <span class="badge bg-info text-dark fs-6"><?= $returned_count ?></span>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="card-custom p-4 h-100">
                            <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-bolt text-danger me-2"></i>Quick Actions</h5>
                            <div class="d-grid gap-2">
                                <a href="#accounts" class="btn btn-outline-danger text-start py-3" data-section="accounts">
                                    <i class="fa-solid fa-magnifying-glass me-2"></i> Search Student Account
                                </a>
                                <a href="#queue" class="btn btn-outline-danger text-start py-3" data-section="queue">
                                    <i class="fa-solid fa-hourglass-half me-2"></i> View Pending Queue
                                    <?php if ($pending_count > 0): ?>
                                        <span class="badge bg-warning text-dark ms-2"><?= $pending_count ?></span>
                                    <?php endif; ?>
                                </a>
                                <a href="#reports" class="btn btn-outline-danger text-start py-3" data-section="reports">
                                    <i class="fa-solid fa-chart-column me-2"></i> Generate Reports
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
                                    <p class="text-muted small text-center py-3">No recent activity.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== ACCOUNTS (with search results AND selected account inside) ==================== -->
            <div class="dashboard-section" id="accounts">
                <div class="card-custom p-4 mb-4 no-print">
    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-magnifying-glass text-danger me-2"></i>Search Student</h5>
    <form method="GET" action="dashboard.php" class="row g-2 align-items-end">
        <!-- NEW: This hidden input preserves the section hash on search -->
        <input type="hidden" name="section" value="accounts">
        <div class="col-md-8">
            <label class="form-label small fw-semibold text-muted">Search by Student ID, First Name, or Last Name</label>
            <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="e.g. 2024-01-00002, Juan, or Dela Cruz">
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-grc-action flex-grow-1"><i class="fa-solid fa-search me-1"></i> Search</button>
            <a href="dashboard.php#accounts" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

                <?php if ($search !== ''): ?>
                    <div class="mb-4">
                        <?php if (count($search_results)===0): ?>
                            <div class="alert alert-warning small mb-0">No students matched "<?= htmlspecialchars($search) ?>".</div>
                        <?php else: ?>
                            <div class="card-custom p-3 mb-3">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle small mb-0">
                                        <thead class="table-light"><tr><th>Student ID</th><th>Name</th><th>Department</th><th>Course</th><th class="text-end">Action</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($search_results as $r): ?>
                                            <tr>
                                                <td class="fw-bold text-danger"><?= htmlspecialchars($r['student_id']) ?></td>
                                                <td><?= htmlspecialchars(trim($r['first_name'].' '.$r['last_name'])) ?></td>
                                                <td><small class="text-muted"><?= htmlspecialchars($r['department'] ?? '—') ?></small></td>
                                                <td><small class="text-muted"><?= htmlspecialchars($r['course'] ?? '—') ?></small></td>
                                                <td class="text-end"><a class="btn btn-sm btn-grc-action rounded-pill px-3" href="dashboard.php?student=<?= urlencode($r['student_id']) ?>#accounts"><i class="fa-solid fa-eye me-1"></i> Open Account</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($selected_student): ?>
                    <div class="card-custom p-4 mb-4 no-print">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                            <div>
                                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-user-graduate text-danger me-2"></i>Financial Account</h5>
                                <div class="small text-muted mt-1">
                                    Student ID: <strong><?= htmlspecialchars($selected_student['student_id']) ?></strong> ·
                                    Name: <strong><?= htmlspecialchars(trim($selected_student['first_name'].' '.$selected_student['last_name'])) ?></strong>
                                    <?php if (!empty($selected_student['department_course'])): ?> · <?= htmlspecialchars($selected_student['department_course']) ?><?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="printStatement('<?= htmlspecialchars($selected_student['student_id'], ENT_QUOTES) ?>')">
                                <i class="fa-solid fa-print me-1"></i> Print Statement
                            </button>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><div class="p-3 bg-light border rounded-3"><span class="text-muted small fw-semibold d-block">Total Charges</span>
                                <h5 class="fw-bold text-dark mb-0">₱<?= number_format($selected_totals['charges'], 2) ?></h5></div></div>
                            <div class="col-md-4"><div class="p-3 bg-light border rounded-3"><span class="text-muted small fw-semibold d-block">Total Payments</span>
                                <h5 class="fw-bold text-success mb-0">₱<?= number_format($selected_totals['payments'], 2) ?></h5></div></div>
                            <div class="col-md-4"><div class="p-3 bg-light border rounded-3"><span class="text-muted small fw-semibold d-block">Outstanding Balance</span>
                                <h5 class="fw-bold <?= $selected_totals['balance']>0?'text-danger':'text-success' ?> mb-0">₱<?= number_format($selected_totals['balance'], 2) ?></h5></div></div>
                        </div>

                        <!-- PRINTABLE STATEMENT -->
                        <div id="printable-statement" class="p-3 border rounded-3 mb-3 bg-white">
                            <div class="text-center mb-3">
                                <h4 class="fw-bold mb-1" style="color:#800000;">GLOBAL RECIPROCAL COLLEGES</h4>
                                <div class="small text-muted">454 Rizal Ave Ext Cor 9th Ave, Grace Park, Caloocan City</div>
                                <h6 class="fw-bold text-uppercase mt-3 mb-0" style="text-decoration:underline;">Student Financial Statement</h6>
                            </div>
                            <div class="row mb-2 small">
                                <div class="col-6"><strong>Student ID:</strong> <?= htmlspecialchars($selected_student['student_id']) ?></div>
                                <div class="col-6 text-end"><strong>Date Generated:</strong> <?= date('F d, Y') ?></div>
                                <div class="col-12"><strong>Student Name:</strong> <?= htmlspecialchars(trim($selected_student['first_name'].' '.$selected_student['last_name'])) ?></div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-2">
                                    <thead class="table-dark">
                                        <tr><th>Date</th><th>Reference</th><th>Description</th><th class="text-end">Charge</th><th class="text-end">Payment</th><th>Method</th><th>Status</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php if (count($selected_ledger)===0): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">No financial transactions yet.</td></tr>
                                    <?php else: foreach ($selected_ledger as $L):
                                        $isVoided = !empty($L['voided']);
                                        $isApproved = ($L['status'] ?? 'approved') === 'approved';
                                        $cAmt = ($L['transaction_type']==='Charge'  && !$isVoided && $isApproved) ? number_format((float)$L['amount'],2) : '';
                                        $pAmt = ($L['transaction_type']==='Payment' && !$isVoided && $isApproved) ? number_format((float)$L['amount'],2) : '';
                                    ?>
                                        <tr style="<?= $isVoided ? 'text-decoration:line-through;opacity:.5;' : '' ?>">
                                            <td><?= date('m/d/y', strtotime($L['transaction_date'])) ?></td>
                                            <td><small><?= htmlspecialchars($L['reference_no'] ?? '—') ?></small></td>
                                            <td><?= htmlspecialchars($L['description']) ?></td>
                                            <td class="text-end"><?= $cAmt!=='' ? '₱'.$cAmt : '' ?></td>
                                            <td class="text-end"><?= $pAmt!=='' ? '₱'.$pAmt : '' ?></td>
                                            <td><small><?= htmlspecialchars($L['payment_method'] ?? '—') ?></small></td>
                                            <td><small><?= strtoupper(htmlspecialchars($L['status'] ?? 'approved')) ?></small></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr><td colspan="3" class="text-end fw-bold">Totals (approved only):</td>
                                            <td class="text-end fw-bold">₱<?= number_format($selected_totals['charges'],2) ?></td>
                                            <td class="text-end fw-bold">₱<?= number_format($selected_totals['payments'],2) ?></td>
                                            <td colspan="2"></td></tr>
                                        <tr><td colspan="6" class="text-end fw-bold">Outstanding Balance:</td>
                                            <td class="fw-bold <?= $selected_totals['balance']>0?'text-danger':'text-success' ?>">₱<?= number_format($selected_totals['balance'],2) ?></td></tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div class="row small">
                                <div class="col-6"><strong>Prepared by:</strong> <?= htmlspecialchars($display_name) ?> (Finance)</div>
                                <div class="col-6 text-end"><strong>Approved by:</strong> Office of the Administrator</div>
                            </div>
                        </div>

                        <!-- ADD CHARGE / RECORD PAYMENT -->
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="card-custom p-3 h-100">
                                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-plus-circle text-danger me-2"></i>Add Tuition / Fee Charge</h6>
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="add_charge" value="1">
                                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($selected_student['student_id']) ?>">
                                        <div class="row g-2 mb-2">
                                            <div class="col-12"><label class="form-label small fw-semibold text-muted">Description</label>
                                                <input type="text" name="description" class="form-control form-control-sm" required placeholder="Tuition Fee - 1st Semester"></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6"><label class="form-label small fw-semibold text-muted">Fee Category</label>
                                                <select name="fee_category" class="form-select form-select-sm">
                                                    <option value="">— Optional —</option>
                                                    <option>Tuition Fee</option><option>Laboratory Fee</option><option>Miscellaneous Fee</option>
                                                    <option>Registration Fee</option><option>Library Fee</option><option>Other School Fee</option>
                                                </select></div>
                                            <div class="col-6"><label class="form-label small fw-semibold text-muted">Amount</label>
                                                <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" required></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-4"><label class="form-label small fw-semibold text-muted">Semester</label>
                                                <select name="semester" class="form-select form-select-sm"><option value="">—</option>
                                                    <option>1st Semester</option><option>2nd Semester</option><option>Summer</option></select></div>
                                            <div class="col-4"><label class="form-label small fw-semibold text-muted">School Year</label>
                                                <input type="text" name="school_year" class="form-control form-control-sm" placeholder="2026-2027"></div>
                                            <div class="col-4"><label class="form-label small fw-semibold text-muted">Txn Date</label>
                                                <input type="date" name="transaction_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6"><label class="form-label small fw-semibold text-muted">Reference No. (auto)</label>
                                                <input type="text" name="reference_no" class="form-control form-control-sm" placeholder="CHG-YYYY-NNNN"></div>
                                            <div class="col-6"><label class="form-label small fw-semibold text-muted">Remarks</label>
                                                <input type="text" name="remarks" class="form-control form-control-sm"></div>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" name="save_draft" value="1" class="btn btn-outline-secondary btn-sm flex-grow-1"><i class="fa-solid fa-floppy-disk me-1"></i> Save Draft</button>
                                            <button type="submit" name="submit_to_admin" value="1" class="btn btn-grc-action btn-sm flex-grow-1"><i class="fa-solid fa-paper-plane me-1"></i> Submit to Admin</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="card-custom p-3 h-100">
                                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-money-bill-wave text-success me-2"></i>Record Payment</h6>
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="record_payment" value="1">
                                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($selected_student['student_id']) ?>">
                                        <div class="row g-2 mb-2">
                                            <div class="col-12"><label class="form-label small fw-semibold text-muted">Description</label>
                                                <input type="text" name="description" class="form-control form-control-sm" required placeholder="Tuition Payment"></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6"><label class="form-label small fw-semibold text-muted">Amount</label>
                                                <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" required></div>
                                            <div class="col-6"><label class="form-label small fw-semibold text-muted">Txn Date</label>
                                                <input type="date" name="transaction_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6"><label class="form-label small fw-semibold text-muted">OR / Reference No.</label>
                                                <input type="text" name="reference_no" class="form-control form-control-sm" placeholder="PAY-YYYY-NNNN"></div>
                                            <div class="col-6"><label class="form-label small fw-semibold text-muted">Payment Method</label>
                                                <select name="payment_method" class="form-select form-select-sm">
                                                    <option value="">— Select —</option><option>Cash</option><option>Bank Transfer</option>
                                                    <option>GCash</option><option>Card</option><option>Other</option>
                                                </select></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-12"><label class="form-label small fw-semibold text-muted">Remarks</label>
                                                <input type="text" name="remarks" class="form-control form-control-sm"></div>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" name="save_draft" value="1" class="btn btn-outline-secondary btn-sm flex-grow-1"><i class="fa-solid fa-floppy-disk me-1"></i> Save Draft</button>
                                            <button type="submit" name="submit_to_admin" value="1" class="btn btn-grc-action btn-sm flex-grow-1"><i class="fa-solid fa-paper-plane me-1"></i> Submit to Admin</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div><!-- /#accounts -->

            <!-- PENDING QUEUE -->
            <div class="dashboard-section" id="queue">
                <div class="card-custom p-4 mb-4 no-print">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-hourglass-half text-danger me-2"></i>Pending / Returned Submissions</h5>
                    <div class="table-responsive" style="max-height:380px;overflow:auto;">
                        <table class="table table-modern align-middle">
                            <thead class="table-dark sticky-top">
                                <tr><th>Date</th><th>Reference</th><th>Student</th><th>Description</th><th>Type</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php if (count($pending_rows)===0): ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">Nothing pending. 🎉</td></tr>
                            <?php else: foreach ($pending_rows as $r):
                                $badge = 'status-'.$r['status'];
                            ?>
                                <tr>
                                    <td><small class="text-muted"><?= date('M d, Y', strtotime($r['transaction_date'])) ?></small></td>
                                    <td><small class="fw-bold"><?= htmlspecialchars($r['reference_no']) ?></small></td>
                                    <td><span class="fw-bold"><?= htmlspecialchars(trim($r['stud_name'])) ?></span><br><small class="text-muted"><?= htmlspecialchars($r['stud_code']) ?></small></td>
                                    <td><?= htmlspecialchars($r['description']) ?></td>
                                    <td><span class="badge bg-secondary"><?= strtoupper($r['transaction_type']) ?></span></td>
                                    <td class="text-end fw-bold">₱<?= number_format((float)$r['amount'], 2) ?></td>
                                    <td><span class="badge status-badge <?= $badge ?>"><?= strtoupper($r['status']) ?></span>
                                        <?php if ($r['status']==='returned' && !empty($r['admin_remarks'])): ?>
                                            <div class="small text-muted mt-1"><strong>Admin:</strong> <?= htmlspecialchars($r['admin_remarks']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if (in_array($r['status'], ['draft','returned'], true)): ?>
                                            <a class="btn btn-sm btn-outline-primary border-0 rounded-circle" title="Edit" href="dashboard.php?student=<?= urlencode($r['stud_code']) ?>#accounts"><i class="fa-solid fa-pen-to-square"></i></a>
                                            <form method="POST" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="submit_existing" value="1">
                                                <input type="hidden" name="transaction_id" value="<?= (int)$r['id'] ?>">
                                                <button class="btn btn-sm btn-outline-success border-0 rounded-circle" title="Submit to Admin"><i class="fa-solid fa-paper-plane"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline js-confirm-form"
                                              data-confirm-title="Void Transaction"
                                              data-confirm-message="Are you sure you want to void this transaction? This action cannot be undone."
                                              data-confirm-type="danger"
                                              data-confirm-button="Void">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="void_transaction" value="1">
                                            <input type="hidden" name="transaction_id" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger border-0 rounded-circle" title="Void"><i class="fa-solid fa-ban"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TRANSACTION HISTORY -->
            <div class="dashboard-section" id="transactions">
                <div class="card-custom p-4 mb-4 no-print">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list text-danger me-2"></i>Transaction History</h5>

                    <form method="GET" action="dashboard.php" class="row g-2 mb-3">
    <!-- NEW: preserve the Transactions section on filter submit -->
    <input type="hidden" name="section" value="transactions">
    <div class="col-md-3"><label class="form-label small fw-semibold text-muted">Student</label><input type="text" name="f_student" class="form-control form-control-sm" value="<?= htmlspecialchars($f_student) ?>"></div>
    <div class="col-md-2"><label class="form-label small fw-semibold text-muted">Type</label>
        <select name="f_type" class="form-select form-select-sm">
            <?php foreach (['All','Charge','Payment'] as $t): ?><option <?= $f_type===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-md-2"><label class="form-label small fw-semibold text-muted">Status</label>
        <select name="f_status" class="form-select form-select-sm">
            <?php foreach (['All','draft','submitted','approved','returned','voided'] as $s): ?>
                <option <?= $f_status===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="col-md-2"><label class="form-label small fw-semibold text-muted">From</label><input type="date" name="f_date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($f_from) ?>"></div>
    <div class="col-md-2"><label class="form-label small fw-semibold text-muted">To</label><input type="date" name="f_date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($f_to) ?>"></div>
    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-grc-action btn-sm w-100"><i class="fa-solid fa-filter"></i></button></div>
</form>

                    <div class="table-responsive" style="max-height:520px;overflow:auto;">
                        <table class="table table-modern align-middle">
                            <thead class="table-dark sticky-top">
                                <tr><th>Date</th><th>Reference</th><th>Student</th><th>Description</th><th>Type</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th>Reviewed By</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php if (count($transactions)===0): ?>
                                <tr><td colspan="10" class="text-center text-muted py-4">No transactions found.</td></tr>
                            <?php else: foreach ($transactions as $t):
                                $badge = 'status-'.($t['status'] ?? 'approved');
                                $typeBadge = $t['transaction_type']==='Charge' ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-success-subtle text-success border border-success-subtle';
                            ?>
                                <tr>
                                    <td><small class="text-muted fw-semibold"><?= date('M d, Y', strtotime($t['transaction_date'])) ?></small></td>
                                    <td><small class="fw-bold"><?= htmlspecialchars($t['reference_no'] ?? '—') ?></small></td>
                                    <td><span class="fw-bold"><?= htmlspecialchars(trim($t['stud_name'])) ?></span><br><small class="text-muted"><?= htmlspecialchars($t['stud_code']) ?></small></td>
                                    <td><?= htmlspecialchars($t['description']) ?></td>
                                    <td><span class="badge rounded-pill px-3 <?= $typeBadge ?>"><?= strtoupper($t['transaction_type']) ?></span></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($t['payment_method'] ?? '—') ?></small></td>
                                    <td class="text-end fw-bold">₱<?= number_format((float)$t['amount'], 2) ?></td>
                                    <td><span class="badge status-badge <?= $badge ?>"><?= strtoupper($t['status'] ?? 'approved') ?></span></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($t['reviewed_by_username'] ?? '—') ?></small></td>
                                    <td class="text-end text-nowrap">
                                        <?php if (in_array($t['status'], ['draft','returned'], true)): ?>
                                            <button class="btn btn-sm btn-outline-primary border-0 rounded-circle" title="Edit"
                                                onclick='openEditTx(<?= json_encode([
                                                    "id"=>(int)$t["id"], "description"=>$t["description"], "amount"=>(float)$t["amount"],
                                                    "date"=>$t["transaction_date"], "ref"=>$t["reference_no"] ?? "",
                                                    "method"=>$t["payment_method"] ?? "", "remarks"=>$t["remarks"] ?? "", "student"=>$t["stud_code"],
                                                ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-pen-to-square"></i></button>
                                        <?php endif; ?>
                                        <?php if (($t['status'] ?? '') !== 'voided'): ?>
                                            <button class="btn btn-sm btn-outline-danger border-0 rounded-circle" title="Void"
                                                onclick='openVoidTx(<?= (int)$t["id"] ?>, <?= json_encode($t["reference_no"] ?? "", JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-ban"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- REPORTS -->
            <div class="dashboard-section" id="reports">
                <div class="card-custom p-4 mb-4 no-print">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-chart-column text-danger me-2"></i>Reports (Approved Only)</h5>
                    <form method="GET" action="dashboard.php" class="row g-2 align-items-end mb-3">
    <!-- NEW: preserve the Reports section on refresh -->
    <input type="hidden" name="section" value="reports">
    <div class="col-md-3"><label class="form-label small fw-semibold text-muted">From</label><input type="date" name="rep_from" class="form-control form-control-sm" value="<?= htmlspecialchars($rep_from) ?>"></div>
    <div class="col-md-3"><label class="form-label small fw-semibold text-muted">To</label><input type="date" name="rep_to" class="form-control form-control-sm" value="<?= htmlspecialchars($rep_to) ?>"></div>
    <div class="col-md-2"><button class="btn btn-grc-action btn-sm w-100"><i class="fa-solid fa-rotate me-1"></i> Refresh</button></div>
</form>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><div class="p-3 bg-light border rounded-3"><span class="text-muted small fw-semibold d-block">Total Charges in Range</span><h5 class="fw-bold mb-0">₱<?= number_format((float)$rep['c'], 2) ?></h5></div></div>
                        <div class="col-md-4"><div class="p-3 bg-light border rounded-3"><span class="text-muted small fw-semibold d-block">Total Payments in Range</span><h5 class="fw-bold text-success mb-0">₱<?= number_format((float)$rep['p'], 2) ?></h5></div></div>
                        <div class="col-md-4"><div class="p-3 bg-light border rounded-3"><span class="text-muted small fw-semibold d-block">Net in Range</span><h5 class="fw-bold <?= ((float)$rep['c']-(float)$rep['p'])>0?'text-danger':'text-success' ?> mb-0">₱<?= number_format((float)$rep['c']-(float)$rep['p'], 2) ?></h5></div></div>
                    </div>
                    <div class="table-responsive" style="max-height:380px;overflow:auto;">
                        <table class="table table-sm align-middle">
                            <thead class="table-dark sticky-top"><tr><th>Date</th><th class="text-end">Charges</th><th class="text-end">Payments</th><th class="text-end">Net</th></tr></thead>
                            <tbody>
                            <?php if (count($daily_rows)===0): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No approved transactions in range.</td></tr>
                            <?php else: foreach ($daily_rows as $d): $net=(float)$d['c']-(float)$d['p']; ?>
                                <tr><td><?= date('M d, Y', strtotime($d['d'])) ?></td>
                                    <td class="text-end">₱<?= number_format((float)$d['c'], 2) ?></td>
                                    <td class="text-end text-success">₱<?= number_format((float)$d['p'], 2) ?></td>
                                    <td class="text-end fw-bold <?= $net>0?'text-danger':'text-success' ?>">₱<?= number_format($net, 2) ?></td></tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- /container-fluid -->
    </div><!-- /container-fluid -->
</div><!-- /main-content -->

<!-- EDIT MODAL -->
<div class="modal fade" id="editTxModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header text-white" style="background:var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Edit Transaction</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="edit_transaction" value="1">
                <input type="hidden" name="transaction_id" id="editTxId">
                <input type="hidden" name="return_student" id="editTxReturn">
                <div class="modal-body p-4">
                    <div class="mb-3"><label class="form-label small fw-semibold">Description</label><input type="text" name="description" id="editTxDesc" class="form-control" required></div>
                    <div class="row g-3 mb-3">
                        <div class="col-6"><label class="form-label small fw-semibold">Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="editTxAmount" class="form-control" required></div>
                        <div class="col-6"><label class="form-label small fw-semibold">Transaction Date</label><input type="date" name="transaction_date" id="editTxDate" class="form-control" required></div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6"><label class="form-label small fw-semibold">Reference No.</label><input type="text" name="reference_no" id="editTxRef" class="form-control"></div>
                        <div class="col-6"><label class="form-label small fw-semibold">Payment Method</label>
                            <select name="payment_method" id="editTxMethod" class="form-select">
                                <option value="">—</option><option>Cash</option><option>Bank Transfer</option><option>GCash</option><option>Card</option><option>Other</option>
                            </select></div>
                    </div>
                    <div class="mb-2"><label class="form-label small fw-semibold">Remarks</label><input type="text" name="remarks" id="editTxRemarks" class="form-control"></div>
                    <div class="small text-muted">Saving an edit will reset the record to <strong>Draft</strong>.</div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-grc-action btn-sm rounded-pill px-4">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VOID MODAL -->
<div class="modal fade" id="voidTxModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header text-white" style="background:var(--grc-dark);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-ban me-2 text-danger"></i>Void Transaction</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="void_transaction" value="1">
                <input type="hidden" name="transaction_id" id="voidTxId">
                <div class="modal-body p-4">
                    <p class="small">You are about to void <strong id="voidTxRef"></strong>. The record is preserved but excluded from totals.</p>
                    <label class="form-label small fw-semibold">Reason</label>
                    <textarea name="void_reason" class="form-control" rows="3" placeholder="e.g. duplicate entry"></textarea>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm rounded-pill px-4">Void transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- PROFILE MODAL -->
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
                    <span class="badge px-3 py-1 rounded-pill mt-1" style="background:var(--grc-primary);">Finance</span>
                </div>
                <div class="list-group list-group-flush border-top border-bottom">
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Finance ID:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['student_id'] ?? 'N/A') ?></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Username:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['username']) ?></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Email:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['email']) ?></span></div>
                    <div class="list-group-item d-flex justify-content-between py-2 px-0"><span class="text-muted small">Department:</span><span class="fw-bold small"><?= htmlspecialchars($user_data['department_course'] ?? 'Finance Office') ?></span></div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0"><button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- NOTIF OFFCANVAS -->
<div class="offcanvas offcanvas-end" style="width:480px;" tabindex="-1" id="notifDrawer">
    <div class="offcanvas-header text-white" style="background:var(--grc-dark);border-bottom:3px solid var(--grc-secondary);">
        <h5 class="offcanvas-title fw-bold"><i class="fa-solid fa-bell text-danger me-2"></i>Notifications</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body bg-light">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0 text-dark">Your Notifications</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="markAllNotificationsRead()"><i class="fa-solid fa-check-double me-1"></i> Mark all read</button>
        </div>
        <?php if (count($notification_list)>0): foreach ($notification_list as $n): $unread = empty($n['is_read']); ?>
            <div class="card mb-2 border-0 shadow-sm rounded-3 notif-item <?= $unread?'notif-unread':'notif-read' ?>" data-notif-id="<?= (int)$n['id'] ?>">
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
        <?php endforeach; else: ?>
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

const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

function openEditTx(data){
    document.getElementById('editTxId').value      = data.id;
    document.getElementById('editTxDesc').value    = data.description;
    document.getElementById('editTxAmount').value  = data.amount;
    document.getElementById('editTxDate').value    = data.date;
    document.getElementById('editTxRef').value     = data.ref;
    document.getElementById('editTxMethod').value  = data.method;
    document.getElementById('editTxRemarks').value = data.remarks;
    document.getElementById('editTxReturn').value  = data.student;
    new bootstrap.Modal(document.getElementById('editTxModal')).show();
}
function openVoidTx(id, ref){
    document.getElementById('voidTxId').value = id;
    document.getElementById('voidTxRef').textContent = ref || ('#'+id);
    new bootstrap.Modal(document.getElementById('voidTxModal')).show();
}
function printStatement(studentCode){
    try {
        const fd = new FormData();
        fd.append('log_print','1');
        fd.append('student_code', studentCode);
        fd.append('csrf_token', CSRF_TOKEN);
        fetch('dashboard.php', { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} }).catch(()=>{});
    } catch(e){}
    window.open('dashboard.php?student=' + encodeURIComponent(studentCode) + '&print=1', '_blank');
}

function updateNotifBadge(count){
    const el = document.getElementById('notifBadge'); if (!el) return;
    if (count > 0){ el.textContent = count; el.style.display = ''; } else { el.style.display = 'none'; }
}
function markNotificationRead(id){
    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('dashboard.php?notification_action=read', { method:'POST', body: fd })
      .then(r=>r.json())
      .then(d=>{
        if (d.status==='success'){
            const c = document.querySelector(`.notif-item[data-notif-id="${id}"]`);
            if (c){ c.classList.remove('notif-unread'); c.classList.add('notif-read');
                const b = c.querySelector('.badge.bg-danger'); if (b && b.textContent==='NEW') b.remove();
                const btn = c.querySelector('button.btn-outline-success'); if (btn) btn.remove(); }
            refreshNotifCount();
        }
      });
}
function markAllNotificationsRead(){
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('dashboard.php?notification_action=read_all', { method:'POST', body: fd })
      .then(r=>r.json())
      .then(d=>{
        if (d.status==='success'){
            document.querySelectorAll('.notif-item.notif-unread').forEach(c=>{
                c.classList.remove('notif-unread'); c.classList.add('notif-read');
                const b = c.querySelector('.badge.bg-danger'); if (b && b.textContent==='NEW') b.remove();
                const btn = c.querySelector('button.btn-outline-success'); if (btn) btn.remove();
            });
            refreshNotifCount();
        }
      });
}
async function deleteNotification(id){
    const confirmed = await showConfirmDialog({
        title: 'Remove Notification',
        message: 'Are you sure you want to remove this notification?',
        type: 'warning',
        confirmText: 'Remove'
    });
    if (!confirmed) return;

    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('dashboard.php?notification_action=delete', { method:'POST', body: fd })
      .then(r=>r.json())
      .then(d=>{
        if (d.status==='success'){
            const c = document.querySelector(`.notif-item[data-notif-id="${id}"]`); if (c) c.remove();
            refreshNotifCount();
        }
      });
}
function refreshNotifCount(){
    fetch('dashboard.php?notification_action=count').then(r=>r.json()).then(d=>{ if (d.status==='success') updateNotifBadge(d.count); }).catch(()=>{});
}
setInterval(refreshNotifCount, 20000);
document.addEventListener('DOMContentLoaded', () => {
    const toast = document.querySelector('.floating-toast-container');
    if (toast) setTimeout(() => bootstrap.Alert.getOrCreateInstance(toast).close(), 4000);
});
document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('print') === '1') {
        document.body.classList.add('print-mode');
        setTimeout(() => window.print(), 400);
    }
});
</script>
<script>
(function () {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const section = urlParams.get('section');
        if (section && !window.location.hash) {
            // Rewrite the URL so the hash is present before any other
            // script reads window.location.hash.
            const newUrl = window.location.pathname + window.location.search + '#' + section;
            history.replaceState(null, '', newUrl);
        }
    } catch (e) {
        // Silently ignore — navigation script will still work.
    }
})();
</script>
<script src="../assets/js/dashboard-navigation.js"></script>
</body>
</html>