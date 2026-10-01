<?php
/* =============================================================================
   /includes/functions.php
   -----------------------------------------------------------------------------
   Shared helpers used by all three role dashboards.
   ============================================================================= */

/* -------------------------------------------------------------------------
   GRADE COLOR THRESHOLD
     grade <  3.00  →  PASSING  (green, text-success)
     grade >= 3.00  →  FAILING  (red,   text-danger)
   ------------------------------------------------------------------------- */
if (!defined('GRADE_LIMIT')) {
    define('GRADE_LIMIT', 3.00);
}

function grade_color_class($value, float $limit = 3.00, string $neutral = 'text-muted'): string {
    if ($value === null || $value === '' || $value === '—' || $value === '-') {
        return $neutral;
    }
    if (!is_numeric($value)) {
        return $neutral;
    }
    $n = round((float)$value, 2);
    return $n >= $limit ? 'text-danger' : 'text-success';
}

/* -------------------------------------------------------------------------
   CREATE NOTIFICATION
   ------------------------------------------------------------------------- */
function create_notification(
    PDO $pdo,
    int $recipientId,
    ?int $senderId,
    string $type,
    string $title,
    string $message,
    ?string $refType = null,
    ?int $refId = null,
    ?string $targetCode = null
) {
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $check->execute([$recipientId]);
    if (!$check->fetch()) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications
                (recipient_user_id, sender_user_id, notification_type, title, message,
                 reference_type, reference_id, target_code, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$recipientId, $senderId, $type, $title, $message, $refType, $refId, $targetCode]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[notification] insert failed: ' . $e->getMessage());
        return false;
    }
}

/* -------------------------------------------------------------------------
   FIND USER BY CODE
   ------------------------------------------------------------------------- */
function find_user_by_code(PDO $pdo, string $code, array $allowedRoles) {
    $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));
    $sql = "SELECT id, username, first_name, last_name, role, student_id, email
            FROM users
            WHERE student_id = ? AND LOWER(role) IN ($placeholders)
            LIMIT 1";
    $params = array_merge([$code], array_map('strtolower', $allowedRoles));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/* -------------------------------------------------------------------------
   GRADE CALCULATION
   ------------------------------------------------------------------------- */
function compute_final_grade(
    float $attendance, float $quizzes, float $prelim, float $midterm, float $final,
    string $scale_mode = 'percent'
): float {
    $final_score = ($attendance * 0.10) + ($quizzes * 0.20)
                 + ($prelim     * 0.20) + ($midterm * 0.25) + ($final * 0.25);

    if ($scale_mode === 'grade_scale') {
        return (float)$final_score;
    }

    if ($final_score >= 97) return 1.00;
    if ($final_score >= 94) return 1.25;
    if ($final_score >= 91) return 1.50;
    if ($final_score >= 88) return 1.75;
    if ($final_score >= 85) return 2.00;
    if ($final_score >= 82) return 2.25;
    if ($final_score >= 79) return 2.50;
    if ($final_score >= 76) return 2.75;
    if ($final_score >= 75) return 3.00;
    if ($final_score >= 70) return 4.00;
    return 5.00;
}

/* -------------------------------------------------------------------------
   CENTRALIZED FINANCIAL BALANCE  (FIXED)
   -------------------------------------------------------------------------
   balance = SUM(approved Charges) − SUM(approved Payments)
   Excludes voided rows and non-approved rows.
   Falls back gracefully if the workflow columns don't exist.
   ------------------------------------------------------------------------- */
function calculateStudentBalance(PDO $pdo, int $studentUserId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN transaction_type='Charge'  THEN amount END), 0) AS c,
              COALESCE(SUM(CASE WHEN transaction_type='Payment' THEN amount END), 0) AS p
            FROM financial_ledgers
            WHERE student_id = ?
              AND voided = 0
              AND status  = 'approved'
        ");
        $stmt->execute([$studentUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Schema fallback — no workflow columns
        $stmt = $pdo->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN transaction_type='Charge'  THEN amount END), 0) AS c,
              COALESCE(SUM(CASE WHEN transaction_type='Payment' THEN amount END), 0) AS p
            FROM financial_ledgers
            WHERE student_id = ?
        ");
        $stmt->execute([$studentUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $c = (float)($row['c'] ?? 0);
    $p = (float)($row['p'] ?? 0);
    return ['charges' => $c, 'payments' => $p, 'balance' => $c - $p];
}

/* -------------------------------------------------------------------------
   AUDIT LOG
   ------------------------------------------------------------------------- */
function audit_log(PDO $pdo, ?int $userId, string $action, string $details = '',
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
        $pdo->prepare("INSERT INTO audit_logs (" . implode(',', $fields) . ") VALUES ($ph)")
            ->execute($values);
    } catch (Throwable $e) {
        error_log('[audit_log] ' . $e->getMessage());
    }
}