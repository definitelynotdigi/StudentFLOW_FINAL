<?php
/* =============================================================================
   /print.php  —  Unified Official Print Document System
   -----------------------------------------------------------------------------
   Supported types:
     - grades               (existing, student — unchanged output)
     - ledger               (student's own financial ledger)          [NEW]
     - enrollment           (existing, student — unchanged output)
     - faculty_grades       (faculty's own records only)              [NEW]
     - admin_grade_review   (admin's selected student — auth-checked) [NEW]

   All types share ONE visual document (maroon GRC header, address,
   "OFFICIAL STUDENT PORTAL" badge, account block, official title, table).
   ============================================================================= */

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("Access Denied.");
}

$type = $_GET['type'] ?? 'grades';
require_once 'database.php';

/* -------------------------------------------------------------------------
   Load the current user (used for student + faculty print types)
   ------------------------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, username, email, role, student_id, first_name, last_name, department_course
    FROM users WHERE id = ? LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$me) { http_response_code(403); die("Access Denied."); }

$display_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($display_name === '') $display_name = $me['username'] ?? 'User';

$current_role = strtolower($me['role']);

/* -------------------------------------------------------------------------
   Helper: render the shared document header
   ------------------------------------------------------------------------- */
function grc_print_header(string $accountLabel, string $accountValue, string $dateLabel = 'Date Generated') {
    $today = date('F d, Y');
    ?>
    <div class="print-header text-center">
        <h2 class="fw-bold text-uppercase college-title mb-1">Global Reciprocal Colleges</h2>
        <p class="text-muted small mb-1">
            <i class="fa-solid fa-location-dot me-1"></i>
            454 Rizal Ave Ext Cor 9th Ave, Grace Park, Caloocan City
        </p>
        <span class="badge bg-dark mt-1">OFFICIAL STUDENT PORTAL</span>
    </div>

    <div class="row mb-4 bg-light p-3 rounded small">
        <div class="col-6">
            <strong><?= htmlspecialchars($accountLabel) ?>:</strong>
            <?= htmlspecialchars($accountValue) ?>
        </div>
        <div class="col-6 text-end">
            <strong><?= htmlspecialchars($dateLabel) ?>:</strong> <?= $today ?>
        </div>
    </div>
    <?php
}

/* =========================================================================
   TYPE-SPECIFIC DATA RESOLUTION (with AUTHORIZATION)
   ========================================================================= */

$doc_title  = '';
$table_head = '';
$table_body = '';
$table_foot = '';

/* ---------------- grades (student, unchanged) ---------------- */
if ($type === 'grades') {
    if ($current_role !== 'student') { http_response_code(403); die("Access Denied."); }

    // SECURITY: only released records belong to the student's transcript.
    $q = $pdo->prepare("
        SELECT subject_code, subject_title, grade
        FROM academic_records
        WHERE student_id = ? AND is_released = 1
        ORDER BY id ASC
    ");
    $q->execute([$me['id']]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    $doc_title = "Official Certificate of Academic Grades";
    $table_head = "<tr><th>Subject Code</th><th>Subject Description Title</th><th>Final Grade Mark</th></tr>";

    $total = 0.0; $count = count($rows);
    if ($count > 0) {
        foreach ($rows as $r) {
            $total += (float)$r['grade'];
            $table_body .= "<tr>
                <td><span class='fw-semibold'>".htmlspecialchars($r['subject_code'])."</span></td>
                <td>".htmlspecialchars($r['subject_title'])."</td>
                <td class='fw-bold ".($r['grade'] >= 3.00 ? 'text-danger' : 'text-success')."'>".number_format((float)$r['grade'],2)."</td>
            </tr>";
        }
        $table_foot = "<tfoot class='table-light border-top'><tr class='fw-bold'>
            <td colspan='2' class='text-end'>General Weighted Average (GWA):</td>
            <td class='".(($total/$count) >= 3.00 ? 'text-danger' : 'text-success')." fs-6'>".number_format($total/$count,2)."</td>
        </tr></tfoot>";
    } else {
        $table_body = "<tr><td colspan='3' class='text-center text-muted py-3'>No academic records found.</td></tr>";
    }
    grc_print_header('Account Profile Identification', $display_name);
}

/* ---------------- ledger (student, NEW) ---------------- */
elseif ($type === 'ledger') {
    if ($current_role !== 'student') { http_response_code(403); die("Access Denied."); }

    $q = $pdo->prepare("
        SELECT transaction_date, reference_no, description, transaction_type, amount, payment_method
        FROM financial_ledgers
        WHERE student_id = ? AND voided = 0 AND status = 'approved'
        ORDER BY transaction_date ASC, id ASC
    ");
    $q->execute([$me['id']]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    $doc_title = "Official Statement of Accounts Balance Ledger";
    $table_head = "<tr><th>Date</th><th>Reference</th><th>Description</th><th class='text-end'>Charge</th><th class='text-end'>Payment</th></tr>";

    $charges = 0.0; $payments = 0.0;
    if (count($rows) > 0) {
        foreach ($rows as $r) {
            $is_charge = ($r['transaction_type'] === 'Charge');
            if ($is_charge) $charges += (float)$r['amount']; else $payments += (float)$r['amount'];
            $table_body .= "<tr>
                <td>".date('m/d/y', strtotime($r['transaction_date']))."</td>
                <td><small>".htmlspecialchars($r['reference_no'] ?? '—')."</small></td>
                <td>".htmlspecialchars($r['description'])."</td>
                <td class='text-end'>".($is_charge ? '₱'.number_format((float)$r['amount'],2) : '')."</td>
                <td class='text-end'>".(!$is_charge ? '₱'.number_format((float)$r['amount'],2) : '')."</td>
            </tr>";
        }
        $balance = $charges - $payments;
        $table_foot = "<tfoot class='table-light border-top'>
            <tr class='fw-bold'><td colspan='3' class='text-end'>Totals:</td>
                <td class='text-end'>₱".number_format($charges,2)."</td>
                <td class='text-end'>₱".number_format($payments,2)."</td></tr>
            <tr class='fw-bold'><td colspan='4' class='text-end'>Outstanding Balance:</td>
                <td class='text-end ".($balance > 0 ? 'text-danger' : 'text-success')." fs-6'>₱".number_format($balance,2)."</td></tr>
        </tfoot>";
    } else {
        $table_body = "<tr><td colspan='5' class='text-center text-muted py-3'>No financial transactions found.</td></tr>";
    }
    grc_print_header('Account Profile Identification', $display_name);
}

/* ---------------- enrollment (student, unchanged) ---------------- */
elseif ($type === 'enrollment') {
    if ($current_role !== 'student') { http_response_code(403); die("Access Denied."); }
    $doc_title  = "Official Enrollment Status Verification Form";
    $table_body = "<p class='lh-lg text-center fs-5 my-5'>
        This certificate confirms that <strong>".htmlspecialchars($display_name)."</strong>
        is officially enrolled at <strong>Global Reciprocal Colleges</strong> for the current academic year.
    </p>
    <div class='mt-5 pt-5 text-center mx-auto' style='border-top: 1px dashed #000; width: 280px;'>
        <small class='fw-bold d-block text-uppercase'>Office of the College Registrar</small>
        <small class='text-muted'>Global Reciprocal Colleges</small>
    </div>";
    grc_print_header('Account Profile Identification', $display_name);
}

/* ---------------- faculty_grades (NEW) ---------------- */
elseif ($type === 'faculty_grades') {
    if (!in_array($current_role, ['faculty','professor'], true)) { http_response_code(403); die("Access Denied."); }

    // SECURITY: only THIS faculty's records.
    $q = $pdo->prepare("
        SELECT ar.subject_code, ar.subject_title, ar.semester, ar.academic_year, ar.grade,
               u.student_id AS stud_code,
               CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS student_name
        FROM academic_records ar
        JOIN users u ON ar.student_id = u.id
        WHERE ar.faculty_id = ?
          AND (ar.cleared_by_faculty = 0 OR ar.cleared_by_faculty IS NULL)
        ORDER BY u.student_id ASC, ar.id ASC
    ");
    $q->execute([$me['id']]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    $doc_title = "Official Faculty Grade Record";
    $table_head = "<tr>
        <th>Student ID</th><th>Student Name</th>
        <th>Subject Code</th><th>Subject Title</th>
        <th>Semester</th><th>A.Y.</th><th class='text-end'>Final Grade</th>
    </tr>";

    $sum = 0.0; $count = count($rows);
    if ($count > 0) {
        foreach ($rows as $r) {
            $sum += (float)$r['grade'];
            $table_body .= "<tr>
                <td><small>".htmlspecialchars($r['stud_code'])."</small></td>
                <td>".htmlspecialchars(trim($r['student_name']))."</td>
                <td><span class='badge bg-light text-dark border'>".htmlspecialchars($r['subject_code'])."</span></td>
                <td>".htmlspecialchars($r['subject_title'])."</td>
                <td><small>".htmlspecialchars($r['semester'])."</small></td>
                <td><small>".htmlspecialchars($r['academic_year'])."</small></td>
                <td class='text-end fw-bold ".($r['grade'] >= 3.00 ? 'text-danger' : 'text-success')."'>".number_format((float)$r['grade'],2)."</td>
            </tr>";
        }
        $gwa = $sum / $count;
        $table_foot = "<tfoot class='table-light border-top'><tr class='fw-bold'>
            <td colspan='6' class='text-end'>Average of Encoded Grades:</td>
            <td class='text-end ".($gwa >= 3.00 ? 'text-danger' : 'text-success')." fs-6'>".number_format($gwa,2)."</td>
        </tr></tfoot>";
    } else {
        $table_body = "<tr><td colspan='7' class='text-center text-muted py-3'>No grade records found for your faculty account.</td></tr>";
    }
    grc_print_header(
        'Faculty Profile Identification',
        $display_name . ' (' . ($me['student_id'] ?? 'N/A') . ')'
    );
}

/* ---------------- admin_grade_review (NEW) ---------------- */
elseif ($type === 'admin_grade_review') {
    if (!in_array($current_role, ['admin','department'], true)) { http_response_code(403); die("Access Denied."); }

    $submission_id = (int)($_GET['submission_id'] ?? 0);
    if ($submission_id <= 0) { http_response_code(400); die("Missing submission_id."); }

    /* SECURITY: the submission MUST belong to the logged-in admin
       (same ownership check used in admin/dashboard.php).
       This blocks URL tampering such as ?submission_id=999. */
    $this_admin_code = $me['student_id'] ?? '';

    $own = $pdo->prepare("
        SELECT fs.id, fs.student_id,
               u.student_id AS stud_code,
               CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS student_name
        FROM faculty_submissions fs
        JOIN users u ON fs.student_id = u.id
        WHERE fs.id = ?
          AND (fs.admin_id = ? OR fs.admin_target_id = ?)
        LIMIT 1
    ");
    $own->execute([$submission_id, $me['id'], $this_admin_code]);
    $sub = $own->fetch(PDO::FETCH_ASSOC);

    if (!$sub) { http_response_code(403); die("Access Denied — this submission is not routed to your Admin ID."); }

    $student_db_id = (int)$sub['student_id'];

    $q = $pdo->prepare("
        SELECT subject_code, subject_title, semester, academic_year, grade
        FROM academic_records
        WHERE student_id = ?
        ORDER BY id ASC
    ");
    $q->execute([$student_db_id]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    $doc_title = "Official Certificate of Academic Grades";
    $table_head = "<tr>
        <th>Subject Code</th><th>Subject Title</th>
        <th>Semester</th><th>A.Y.</th><th class='text-end'>Final Grade</th>
    </tr>";

    $sum = 0.0; $count = count($rows);
    if ($count > 0) {
        foreach ($rows as $r) {
            $sum += (float)$r['grade'];
            $table_body .= "<tr>
                <td><span class='badge bg-light text-dark border'>".htmlspecialchars($r['subject_code'])."</span></td>
                <td>".htmlspecialchars($r['subject_title'])."</td>
                <td><small>".htmlspecialchars($r['semester'])."</small></td>
                <td><small>".htmlspecialchars($r['academic_year'])."</small></td>
                <td class='text-end fw-bold ".($r['grade'] >= 3.00 ? 'text-danger' : 'text-success')."'>".number_format((float)$r['grade'],2)."</td>
            </tr>";
        }
        $gwa = $sum / $count;
        $table_foot = "<tfoot class='table-light border-top'><tr class='fw-bold'>
            <td colspan='4' class='text-end'>General Weighted Average (GWA):</td>
            <td class='text-end ".($gwa >= 3.00 ? 'text-danger' : 'text-success')." fs-6'>".number_format($gwa,2)."</td>
        </tr></tfoot>";
    } else {
        $table_body = "<tr><td colspan='5' class='text-center text-muted py-3'>No grade records found for this student.</td></tr>";
    }

    grc_print_header(
        'Student Account Identification',
        trim($sub['student_name']) . ' (' . $sub['stud_code'] . ')'
    );
}

/* ---------------- unknown type ---------------- */
else {
    http_response_code(400);
    die("Unknown print type.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Document Output — <?= htmlspecialchars($doc_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Inter', sans-serif; color: #212529; }
        .document-container {
            background: #ffffff;
            max-width: 800px;
            border: 2px solid #800000;
            padding: 45px;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            margin: auto;
        }
        .print-header { border-bottom: 2px solid #800000; padding-bottom: 20px; margin-bottom: 30px; }
        .college-title { font-family: 'Cinzel', serif; color: #800000; }
        .doc-title { text-transform: uppercase; font-weight: 700; text-decoration: underline; margin-bottom: 1.5rem; text-align: center; }
        @media print {
            body { background: #ffffff; }
            .no-print { display: none !important; }
            .document-container { border: 1px solid #000; box-shadow: none; padding: 20px; max-width: 100%; }
            @page { size: A4; margin: 12mm; }
        }
    </style>
</head>
<body class="p-4 p-md-5">

<div class="container text-center no-print mb-4">
    <button onclick="window.print()" class="btn btn-danger px-4 py-2 rounded-pill fw-bold">
        <i class="fa-solid fa-print me-2"></i>Print Document
    </button>
</div>

<div class="document-container">

    <?php /* Header already rendered by grc_print_header() at the top of each branch */ ?>

    <h4 class="doc-title"><?= htmlspecialchars($doc_title) ?></h4>

    <?php if (!empty($table_head)): ?>
        <table class="table table-bordered align-middle">
            <thead class="table-dark"><?= $table_head ?></thead>
            <tbody><?= $table_body ?></tbody>
            <?= $table_foot ?>
        </table>
    <?php else: ?>
        <?= $table_body /* used by enrollment */ ?>
    <?php endif; ?>

    <?php if (in_array($type, ['faculty_grades','admin_grade_review'], true)): ?>
        <div class="mt-5 pt-4 text-center mx-auto" style="border-top:1px dashed #000; width:280px;">
            <small class="fw-bold d-block text-uppercase">Office of the College Registrar</small>
            <small class="text-muted">Global Reciprocal Colleges</small>
        </div>
    <?php endif; ?>

</div>

</body>
</html>