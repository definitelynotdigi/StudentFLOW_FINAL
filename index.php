<?php
session_start();
date_default_timezone_set('Asia/Manila');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

$config = require __DIR__ . '/config.php';
$smtp   = $config['smtp'] ?? [];

// Generate CSRF Token once per session lifecycle if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') . '">';
    }
}

// Database Connection
$conn = new mysqli("localhost", "root", "", "student_portal_db");
// Path to the StudentFLOW logo (used for CID embedding in emails).
$logo_path = __DIR__ . '/assets/student_flow.png';

// State flags for modal popups
$active_modal = ""; // 'login', 'register', 'forgot'
$msg = "";
$msg_type = "";

// Fetch GLOBAL announcements only (announcements table is global by design).
// Private notifications live in the `notifications` table and must NEVER
// appear on this public page.
$announcements = [];
try {
    $res_ann = $conn->query("
        SELECT title, content, category, priority, is_pinned, posted_by, created_at
        FROM announcements
        ORDER BY is_pinned DESC, created_at DESC
        LIMIT 6
    ");
    if ($res_ann) {
        $announcements = $res_ann->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $announcements = [];
}

// -----------------------------------------------------------------------------
// 1. FORGOT PASSWORD HANDLER
// -----------------------------------------------------------------------------
$forgot_step = $_SESSION['forgot_step'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_change_email') {
    $active_modal = 'forgot';
    $_SESSION['forgot_step'] = 1;
    $forgot_step = 1;
    unset($_SESSION['reset_email']);
    unset($_SESSION['otp_verified']);
}

// STEP 1: Send OTP to Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_send_otp') {
    $active_modal = 'forgot';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }

    $email = trim($_POST['email']);
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $otp = sprintf("%06d", random_int(100000, 999999));
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $update = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires = ? WHERE email = ?");
        $update->bind_param("sss", $otp, $expires, $email);
        $update->execute();

        $_SESSION['reset_email'] = $email;

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
$mail->Host       = $smtp['host']       ?? 'smtp.gmail.com';
$mail->SMTPAuth   = true;
$mail->Username   = $smtp['username']   ?? '';
$mail->Password   = $smtp['password']   ?? '';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = $smtp['port']       ?? 587;

$mail->setFrom($mail->Username, $smtp['from_name'] ?? 'StudentFLOW Portal');
            $mail->addAddress($email);
$mail->isHTML(true);
$mail->Subject = 'Password Reset OTP Verification Code';

if (file_exists($logo_path)) {
    $mail->addEmbeddedImage(
        $logo_path,
        'studentflow_logo',
        '',
        'base64',
        'image/png'
    );
}

$mail->Body = "
<table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='background:#f4f6f9;padding:32px 0;font-family:Arial,Helvetica,sans-serif;'>
  <tr>
    <td align='center'>
      <table role='presentation' cellpadding='0' cellspacing='0' border='0' width='520' style='max-width:520px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.05);'>

        <!-- Header / Logo -->
        <tr>
          <td align='center' style='padding:28px 24px 12px 24px;'>
            <img src='cid:studentflow_logo' alt='StudentFLOW' width='260' style='display:block;width:260px;max-width:100%;height:auto;border:0;' />
          </td>
        </tr>

        <!-- Brand -->
        <tr>
          <td align='center' style='padding:0 24px 8px 24px;'>
            <div style='font-size:14px;font-weight:700;color:#ab0a0a;letter-spacing:1px;text-transform:uppercase;'>Global Reciprocal Colleges</div>
          </td>
        </tr>

        <!-- Divider -->
        <tr>
          <td align='center' style='padding:8px 40px 0 40px;'>
            <div style='height:2px;background:#ab0a0a;width:60px;border-radius:2px;'></div>
          </td>
        </tr>

        <!-- Message -->
        <tr>
          <td align='center' style='padding:24px 32px 0 32px;'>
            <p style='margin:0;font-size:15px;color:#334155;line-height:1.55;'>Your OTP verification code to reset your password is:</p>
          </td>
        </tr>

        <!-- OTP -->
        <tr>
          <td align='center' style='padding:18px 32px 8px 32px;'>
            <div style='display:inline-block;padding:14px 28px;background:#fff7f7;border:1px dashed #ab0a0a;border-radius:12px;font-size:34px;font-weight:800;color:#ab0a0a;letter-spacing:8px;'>" . htmlspecialchars($otp) . "</div>
          </td>
        </tr>

        <!-- Expiration -->
        <tr>
          <td align='center' style='padding:16px 32px 32px 32px;'>
            <p style='margin:0;font-size:13px;color:#64748b;'>This code will expire in 10 minutes.</p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td align='center' style='padding:18px 24px;background:#120202;'>
            <div style='font-size:12px;color:#ffcccc;font-weight:600;letter-spacing:1px;'>StudentFLOW · Student Portal</div>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
";

$mail->AltBody = "Global Reciprocal Colleges\n\n"
    . "Your OTP verification code to reset your password is: " . $otp . "\n\n"
    . "This code will expire in 10 minutes.\n\n"
    . "StudentFLOW · Student Portal";

$mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
$mail->send();

            $_SESSION['forgot_step'] = 2;
            $forgot_step = 2;
            $msg = "A 6-digit OTP code has been sent to " . htmlspecialchars($email);
            $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Error sending OTP email: " . $mail->ErrorInfo;
            $msg_type = "danger";
        }
    } else {
        $_SESSION['reset_email'] = $email;
        $_SESSION['forgot_step'] = 2;
        $forgot_step = 2;
        $msg = "If that email exists in our records, an OTP code has been dispatched.";
        $msg_type = "info";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_verify_otp') {
    $active_modal = 'forgot';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }

    $email = $_SESSION['reset_email'] ?? '';
    $otp_input = trim($_POST['otp_code']);

        $stmt = $conn->prepare("
        SELECT id FROM users
        WHERE email = ? AND otp_code = ?
          AND otp_expires IS NOT NULL
          AND otp_expires > NOW()
        LIMIT 1
    ");
    $stmt->bind_param("ss", $email, $otp_input);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();
        $_SESSION['otp_verified'] = true;
        $_SESSION['forgot_step'] = 3;
        $forgot_step = 3;
        $msg = "OTP verified! Please set your new password.";
        $msg_type = "success";
    } else {
        $msg = "Invalid or expired OTP code.";
        $msg_type = "danger";
    }
}

// STEP 3: Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_reset_password') {
    $active_modal = 'forgot';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }

    if (empty($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        $forgot_step = 1;
        $_SESSION['forgot_step'] = 1;
        $msg = "Session invalid. Please start over.";
        $msg_type = "danger";
    } else {
        $email = $_SESSION['reset_email'] ?? '';
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];
        $forgot_step = 3;

        $email_username = explode('@', $email)[0];
        $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/';

        if ($new_pass !== $confirm_pass) {
            $msg = "Passwords do not match.";
            $msg_type = "danger";
        } elseif (!preg_match($pattern, $new_pass)) {
            $msg = "Password must be at least 8 characters with upper, lower, number, and special character.";
            $msg_type = "danger";
        } elseif (!empty($email_username) && stripos($new_pass, $email_username) !== false) {
            $msg = "Password must not include your email username.";
            $msg_type = "danger";
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT);
            $update = $conn->prepare("UPDATE users SET password_hash = ?, otp_code = NULL, otp_expires = NULL, failed_attempts = 0, lockout_until = NULL WHERE email = ?");
            $update->bind_param("ss", $hash, $email);
            $update->execute();

            unset($_SESSION['reset_email']);
            unset($_SESSION['forgot_step']);
            unset($_SESSION['otp_verified']);
            $active_modal = 'login';
            $msg = "Password reset successful! Please log in.";
            $msg_type = "success";

            session_regenerate_id(true);
        }
    }
}

// -----------------------------------------------------------------------------
// 2. REGISTER HANDLER WITH MASTERLIST VERIFICATION
// -----------------------------------------------------------------------------
$reg_step = $_SESSION['reg_step'] ?? 1;
$old = []; // holds previously submitted registration values (filled on a failed submit)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reg_back_step1') {
    $_SESSION['reg_step'] = 1;
    $reg_step = 1;
    $active_modal = 'register';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reg_submit_details') {
    $active_modal = 'register';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }

    // Keep what was typed so the form can be re-filled if validation fails
    $old = $_POST;

    // Only accept known roles. Only 'Student' needs Master List verification;
    // 'Professor' (Faculty) and 'Department' (Admin/Staff) register directly.
    $allowed_roles = ['Student', 'Professor', 'Department', 'finance'];
    $role = $_POST['role'] ?? 'Student';
$role_map = ['student'=>'Student','professor'=>'Professor','department'=>'Department','finance'=>'finance'];
$role_key = strtolower($role);
$role = $role_map[$role_key] ?? 'Student';
    if (!in_array($role, $allowed_roles, true)) {
        $role = 'Student';
    }
    $requires_master_list = ($role === 'Student');

    $student_id = trim($_POST['student_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $department = trim($_POST['department'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $dept_course = $department . ($course ? ' - ' . $course : '');
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Verify if Student ID is registered in Admin Master List
    $master_check_passed = true;
    if ($requires_master_list) {
        $ml_stmt = $conn->prepare("SELECT * FROM student_masterlist WHERE student_id = ?");
        $ml_stmt->bind_param("s", $student_id);
        $ml_stmt->execute();
        $ml_res = $ml_stmt->get_result();

        if ($ml_res->num_rows === 0) {
            $master_check_passed = false;
            if (preg_match('/^(FAC|DEP)-/i', $student_id)) {
                // The person typed a Faculty/Staff ID while the Role is still "Student"
                $msg = "\"".$student_id."\" looks like a Faculty/Staff ID, but the Role is set to Student. Please change Role to \"Professor / Faculty\" (or \"Department / Admin\") and try again.";
            } else {
                $msg = "Student ID Number (".$student_id.") is not registered in the Master List. Please contact the Admin.";
            }
            $msg_type = "danger";
        }
    }

    if ($master_check_passed) {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? OR student_id = ?");
        $check->bind_param("sss", $username, $email, $student_id);
        $check->execute();
        $res = $check->get_result();

        $email_username = explode('@', $email)[0];
        $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/';

        if ($res->num_rows > 0) {
            $msg = "Username, Email, or ID Number is already registered in the system.";
            $msg_type = "danger";
        } elseif ($password !== $confirm_password) {
            $msg = "Passwords do not match.";
            $msg_type = "danger";
        } elseif (!preg_match($pattern, $password)) {
            $msg = "Password must be at least 8 characters with upper, lower, number, and special character.";
            $msg_type = "danger";
        } elseif (!empty($email_username) && stripos($password, $email_username) !== false) {
            $msg = "Password must not include your email username.";
            $msg_type = "danger";
        } else {
            $otp = sprintf("%06d", random_int(100000, 999999));
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $pass_hash = password_hash($password, PASSWORD_BCRYPT);

            $existing = $conn->prepare("SELECT id FROM users WHERE email = ? AND is_verified = 0");
            $existing->bind_param("s", $email);
            $existing->execute();
            $ex_res = $existing->get_result();

            if ($ex_res->num_rows > 0) {
                $user_id = $ex_res->fetch_assoc()['id'];
                $stmt = $conn->prepare("UPDATE users SET username = ?, password_hash = ?, role = ?, student_id = ?, first_name = ?, last_name = ?, phone = ?, department = ?, course = ?, department_course = ?, otp_code = ?, otp_expires = ? WHERE id = ?");
                $stmt->bind_param("ssssssssssssi", $username, $pass_hash, $role, $student_id, $first_name, $last_name, $phone, $department, $course, $dept_course, $otp, $expires, $user_id);
                $stmt->execute();
            } else {
                $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, student_id, first_name, last_name, phone, department, course, department_course, otp_code, otp_expires, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $stmt->bind_param("sssssssssssss", $username, $email, $pass_hash, $role, $student_id, $first_name, $last_name, $phone, $department, $course, $dept_course, $otp, $expires);
                $stmt->execute();
            }

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
$mail->Host       = $smtp['host']       ?? 'smtp.gmail.com';
$mail->SMTPAuth   = true;
$mail->Username   = $smtp['username']   ?? '';
$mail->Password   = $smtp['password']   ?? '';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = $smtp['port']       ?? 587;

$mail->setFrom($mail->Username, $smtp['from_name'] ?? 'StudentFLOW Portal');
                $mail->addAddress($email);
$mail->isHTML(true);
$mail->Subject = 'Account Registration OTP Code';

if (file_exists($logo_path)) {
    $mail->addEmbeddedImage(
        $logo_path,
        'studentflow_logo',
        '',
        'base64',
        'image/png'
    );
}

$mail->Body = "
<table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='background:#f4f6f9;padding:32px 0;font-family:Arial,Helvetica,sans-serif;'>
  <tr>
    <td align='center'>
      <table role='presentation' cellpadding='0' cellspacing='0' border='0' width='520' style='max-width:520px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.05);'>

        <!-- Header / Logo -->
        <tr>
          <td align='center' style='padding:28px 24px 12px 24px;'>
            <img src='cid:studentflow_logo' alt='StudentFLOW' width='260' style='display:block;width:260px;max-width:100%;height:auto;border:0;' />
          </td>
        </tr>

        <!-- Brand -->
        <tr>
          <td align='center' style='padding:0 24px 8px 24px;'>
            <div style='font-size:14px;font-weight:700;color:#ab0a0a;letter-spacing:1px;text-transform:uppercase;'>Global Reciprocal Colleges</div>
          </td>
        </tr>

        <!-- Divider -->
        <tr>
          <td align='center' style='padding:8px 40px 0 40px;'>
            <div style='height:2px;background:#ab0a0a;width:60px;border-radius:2px;'></div>
          </td>
        </tr>

        <!-- Message -->
        <tr>
          <td align='center' style='padding:24px 32px 0 32px;'>
            <p style='margin:0;font-size:15px;color:#334155;line-height:1.55;'>Welcome! Your OTP code to complete account registration is:</p>
          </td>
        </tr>

        <!-- OTP -->
        <tr>
          <td align='center' style='padding:18px 32px 8px 32px;'>
            <div style='display:inline-block;padding:14px 28px;background:#fff7f7;border:1px dashed #ab0a0a;border-radius:12px;font-size:34px;font-weight:800;color:#ab0a0a;letter-spacing:8px;'>" . htmlspecialchars($otp) . "</div>
          </td>
        </tr>

        <!-- Expiration -->
        <tr>
          <td align='center' style='padding:16px 32px 32px 32px;'>
            <p style='margin:0;font-size:13px;color:#64748b;'>This code is valid for 10 minutes.</p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td align='center' style='padding:18px 24px;background:#120202;'>
            <div style='font-size:12px;color:#ffcccc;font-weight:600;letter-spacing:1px;'>StudentFLOW · Student Portal</div>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
";

// Plain-text fallback — prevents the PNG filename from appearing in
// Gmail's inbox preview snippet.
$mail->AltBody = "Global Reciprocal Colleges\n\n"
    . "Welcome! Your OTP code to complete account registration is: " . $otp . "\n\n"
    . "This code is valid for 10 minutes.\n\n"
    . "StudentFLOW · Student Portal";

$mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
$mail->send();

                $_SESSION['reg_email'] = $email;
                $_SESSION['reg_step'] = 2;
                $reg_step = 2;
            } catch (Exception $e) {
                $msg = "Error sending OTP email: " . $mail->ErrorInfo;
                $msg_type = "danger";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reg_verify_otp') {
    $active_modal = 'register';
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }

    $email = trim($_SESSION['reg_email'] ?? '');
    $otp_input = trim($_POST['otp_code']);

    if (empty($email)) {
        $msg = "Session expired. Please re-enter your details.";
        $msg_type = "danger";
    } else {
        $stmt = $conn->prepare("
    SELECT id, student_id, role, first_name, last_name, department, course
    FROM users
    WHERE email = ? AND otp_code = ?
      AND otp_expires IS NOT NULL
      AND otp_expires > NOW()
    LIMIT 1
");
        $stmt->bind_param("ss", $email, $otp_input);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $user = $res->fetch_assoc();

            // Mark user account as verified
            $update_u = $conn->prepare("UPDATE users SET is_verified = 1, otp_code = NULL, otp_expires = NULL WHERE id = ?");
            $update_u->bind_param("i", $user['id']);
            $update_u->execute();

            session_regenerate_id(true);

            // Sync with Student Master List
if (strtolower($user['role']) === 'student' && !empty($user['student_id'])) {
    $student_id = $user['student_id'];
    $f_name = !empty($user['first_name']) ? $user['first_name'] : 'Student';
    $l_name = !empty($user['last_name']) ? $user['last_name'] : 'User';
    $dept   = !empty($user['department']) ? $user['department'] : 'College of Computer Studies';
    $crs    = !empty($user['course']) ? $user['course'] : 'BSIT';

    $check_ml = $conn->prepare("SELECT id FROM student_masterlist WHERE student_id = ?");
    $check_ml->bind_param("s", $student_id);
    $check_ml->execute();
    $ml_res = $check_ml->get_result();

    if ($ml_res->num_rows > 0) {
        $update_ml = $conn->prepare("UPDATE student_masterlist SET status = 'active', first_name = ?, last_name = ?, department = ?, course = ? WHERE student_id = ?");
        $update_ml->bind_param("sssss", $f_name, $l_name, $dept, $crs, $student_id);
        $update_ml->execute();
    } else {
        $ins_ml = $conn->prepare("INSERT INTO student_masterlist (student_id, first_name, last_name, department, course, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $ins_ml->bind_param("sssss", $student_id, $f_name, $l_name, $dept, $crs);
        $ins_ml->execute();
    }
}

            // Registration complete — clear the registration session state
            // and hand the person off to the Login modal.
            unset($_SESSION['reg_email']);
            unset($_SESSION['reg_step']);
            $reg_step = 1;
            $active_modal = 'login';
            $msg = "Account verified successfully! You can now log in.";
            $msg_type = "success";
        } else {
            $msg = "Invalid or expired OTP code. Please try again.";
            $msg_type = "danger";
        }
    }
}

if (isset($_GET['login_error'])) {
    $active_modal = 'login';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Reciprocal Colleges | Student & Faculty Portal</title>
    <!-- Google Fonts & Font Awesome & Bootstrap Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --grc-primary: #800000;
            --grc-secondary: #ab0a0a;
            --grc-hover: #ff1e1e;
            --grc-dark: #200404;
            --grc-bg: #f4f6f9;
        }
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--grc-bg); 
            color: #2d3748; 
        }
        .navbar-grc { 
            background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-secondary) 100%); 
            border-bottom: 3px solid var(--grc-hover); 
        }
        .navbar-grc .dropdown-menu {
            background-color: var(--grc-dark);
            border: 1px solid var(--grc-secondary);
            border-radius: 8px;
            padding: 0.5rem 0;
        }
        .navbar-grc .dropdown-item {
            color: #e0e0e0;
            font-size: 0.9rem;
            padding: 8px 16px;
            transition: all 0.2s ease;
        }
        .navbar-grc .dropdown-item:hover, 
        .navbar-grc .dropdown-item:focus {
            background-color: var(--grc-secondary);
            color: #ffffff;
            padding-left: 20px;
        }
        .hero-section {
            background: linear-gradient(135deg, #1e0202 0%, #4a0505 50%, #110101 100%);
            color: white;
            padding: 130px 0;
            border-bottom: 4px solid var(--grc-secondary);
        }
        .feature-card {
            background: #ffffff;
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        .text-grc { color: var(--grc-secondary); }
        .text-accent { color: #ff5e36; }
        .btn-grc-accent {
            background: linear-gradient(135deg, var(--grc-secondary) 0%, var(--grc-primary) 100%);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .btn-grc-accent:hover {
            background: var(--grc-hover);
            color: #ffffff;
            box-shadow: 0 6px 15px rgba(255, 30, 30, 0.4);
            transform: translateY(-1px);
        }
        .announcement-card {
            border-left: 4px solid var(--grc-secondary);
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .announcement-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }
        .announcement-card.ann-urgent    { border-left-color: #dc3545 !important; background: #fff5f5; }
.announcement-card.ann-important { border-left-color: #ffc107 !important; background: #fffdf3; }

        .modal-dark-custom {
            background-color: #212529;
            color: #f8f9fa;
            border: 1px solid #343a40;
            border-radius: 16px;
        }
        .about-card-dark {
            background: #181b1e;
            border: 1px solid #2b3036;
            border-radius: 12px;
        }
        .btn-grc { 
            background: linear-gradient(135deg, var(--grc-secondary) 0%, var(--grc-primary) 100%); 
            color: #ffffff; 
            border: none; 
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .btn-grc:hover { 
            background: var(--grc-hover); 
            color: #ffffff; 
            box-shadow: 0 6px 15px rgba(204, 0, 0, 0.4);
        }
        a.grc-link { color: var(--grc-secondary); text-decoration: none; font-weight: 600; cursor: pointer; }
        a.grc-link:hover { color: var(--grc-hover); text-decoration: underline; }

        @keyframes floatLogo {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        .hero-logo {
            max-width: 800px;
            width: 100%;
            height: auto;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.45));
            animation: floatLogo 4s ease-in-out infinite;
            transition: transform 0.3s ease, filter 0.3s ease;
        }
        .hero-logo:hover {
            transform: scale(1.05);
            filter: drop-shadow(0 15px 25px rgba(255, 30, 30, 0.35));
        }
        .btn-portal-hero {
            background: #ffffff;
            color: var(--grc-dark);
            border: 2px solid rgba(255, 255, 255, 0.8);
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            transition: all 0.35s ease;
        }
        .btn-portal-hero:hover {
            background-color: var(--grc-hover);
            color: #ffffff;
            border-color: var(--grc-hover);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(255, 30, 30, 0.4);
        }
        .btn-portal-hero .chevron-icon { transition: transform 0.3s ease; }
        .btn-portal-hero:hover .chevron-icon { transform: translateX(5px); }

        /* Show/Hide Password Toggle — integrated with existing input-group */
.password-toggle-btn {
    background: #fff;
    border: 1px solid #ced4da;
    border-left: none;
    color: #6c757d;
    padding: 0 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.2s ease, background 0.2s ease;
}
.password-toggle-btn:hover,
.password-toggle-btn:focus {
    background: #f8f9fa;
    color: var(--grc-secondary);
    box-shadow: none;
}
.password-toggle-btn:focus-visible {
    outline: 2px solid var(--grc-primary);
    outline-offset: 1px;
}
/* Keep rounded corners consistent with the input-group */
.input-group > .password-toggle-btn:last-child {
    border-top-right-radius: 0.375rem;
    border-bottom-right-radius: 0.375rem;
}
/* Show/Hide Password Toggle — integrated with input-group */
.password-toggle-btn {
    background: #fff;
    border: 1px solid #ced4da;
    border-left: none;
    color: #6c757d;
    padding: 0 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.2s ease, background 0.2s ease;
}
.password-toggle-btn:hover,
.password-toggle-btn:focus {
    background: #f8f9fa;
    color: var(--grc-secondary);
    box-shadow: none;
}
.password-toggle-btn:focus-visible {
    outline: 2px solid var(--grc-primary);
    outline-offset: 1px;
}
.input-group > .password-toggle-btn:last-child {
    border-top-right-radius: 0.375rem;
    border-bottom-right-radius: 0.375rem;
}
/* Small input-group variant (register modal uses form-control-sm) */
.input-group-sm > .password-toggle-btn {
    padding: 0 8px;
    font-size: 0.8rem;
}

        @media (min-width: 992px) {
            .navbar-grc .dropdown:hover .dropdown-menu {
                display: block;
                margin-top: 0;
            }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <!-- Navigation Bar -->
    <nav class="navbar navbar-grc navbar-dark navbar-expand-lg p-3 shadow-sm">
        <div class="container px-lg-4">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
                <img src="assets/grc3.png" alt="GRC Logo" height="35">
                <img src="assets/grc2.png" alt="GRC Logo" height="35">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item me-2"><a class="nav-link active fw-medium" href="index.php">Home</a></li>
                    
                    <!-- Dynamic Academics Dropdown -->
                    <li class="nav-item dropdown me-2">
                        <a class="nav-link dropdown-toggle fw-medium" href="#" id="academicsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Academics
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow" aria-labelledby="academicsDropdown">
                            <li><h6 class="dropdown-header text-warning">College of Computer Studies</h6></li>
                            <li><a class="dropdown-item" href="#">BS Information Technology (BSIT)</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-warning">College of Business Administration</h6></li>
                            <li><a class="dropdown-item" href="#">BSBA Financial Management</a></li>
                            <li><a class="dropdown-item" href="#">BSBA Marketing Management</a></li>
                            <li><a class="dropdown-item" href="#">BSBA Human Resource Management</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-warning">College of Education</h6></li>
                            <li><a class="dropdown-item" href="#">Bachelor of Secondary Education (BSED)</a></li>
                            <li><a class="dropdown-item" href="#">Bachelor of Elementary Education (BEED)</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header text-warning">College of Accountancy</h6></li>
                            <li><a class="dropdown-item" href="#">BS Accountancy (BSA)</a></li>
                        </ul>
                    </li>

                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <li class="nav-item me-2">
                            <a class="nav-link fw-medium" href="#" data-bs-toggle="modal" data-bs-target="#announcementsListModal">Announcements</a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item me-2">
                        <a class="nav-link fw-medium" href="#" data-bs-toggle="modal" data-bs-target="#contactModal">Contact Us</a>
                    </li>

                    <li class="nav-item me-3">
                        <a class="nav-link fw-medium" href="#" data-bs-toggle="modal" data-bs-target="#aboutModal">About</a>
                    </li>

                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li class="nav-item">
                            <a class="btn btn-grc-accent fw-semibold px-3 py-2" href="dashboard.php">Go to Dashboard</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="hero-section text-center text-lg-start">
        <div class="container px-4">
            <div class="row align-items-center g-4">
                <div class="col-lg-5 text-center">
                    <img src="assets/sf.png" alt="Student Flow Logo" class="hero-logo img-fluid d-block mx-auto">
                </div>
                <div class="col-lg-7">
                    <h1 class="display-4 fw-bold mb-3">Empowering Minds, Shaping Futures</h1>
                    <p class="lead mb-4 text-light opacity-75" style="max-width: 650px;">
                        Welcome to the official StudentFLOW (GRC Web Portal.) Access academic reports, view tuition statements, and manage account services seamlessly.
                    </p>
                    <div class="mt-4">
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <button class="btn btn-portal-hero btn-lg fw-bold px-4 py-3 shadow" data-bs-toggle="modal" data-bs-target="#modalQuestion1">
                                <span>Access Portal</span>
                                <i class="bi bi-arrow-right ms-2 chevron-icon"></i>
                            </button>
                        <?php else: ?>
                            <a href="dashboard.php" class="btn btn-portal-hero btn-lg fw-bold px-4 py-3 shadow">
                                <span>Open Dashboard</span>
                                <i class="bi bi-arrow-right ms-2 chevron-icon"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="modal fade" id="modalQuestionDepartment" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg text-center" style="border-radius: 16px;">
            <div class="p-4 text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-primary) 100%); border-top-left-radius: 16px; border-top-right-radius: 16px;">
                <i class="fa-solid fa-building-user fa-3x mb-2 text-warning"></i>
                <h4 class="fw-bold mb-0">Department Verification</h4>
            </div>
            <div class="modal-body p-4">
                <h5 class="fw-semibold text-dark mb-4">Are you a Admin?</h5>
                <div class="d-flex justify-content-center gap-3">
                    <!-- YES → Registered-department question -->
                    <button type="button"
                            class="btn btn-grc px-4 py-2 fw-bold"
                            onclick="chooseRegisteredDepartmentBranch()">
                        <i class="fa-solid fa-check me-1"></i> Yes
                    </button>
                    <!-- NO → Faculty question (faculty are neither Students nor Departments) -->
                    <button type="button"
                            class="btn btn-outline-danger px-4 py-2 fw-bold"
                            onclick="chooseFacultyBranch()">
                        <i class="fa-solid fa-xmark me-1"></i> No
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalQuestionFaculty" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg text-center" style="border-radius: 16px;">
            <div class="p-4 text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-primary) 100%); border-top-left-radius: 16px; border-top-right-radius: 16px;">
                <i class="fa-solid fa-chalkboard-user fa-3x mb-2 text-warning"></i>
                <h4 class="fw-bold mb-0">Faculty Verification</h4>
            </div>
            <div class="modal-body p-4">
                <h5 class="fw-semibold text-dark mb-4">Are you a Professor?</h5>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button"
                            class="btn btn-grc px-4 py-2 fw-bold"
                            onclick="chooseRegisteredFacultyBranch()">
                        <i class="fa-solid fa-check me-1"></i> Yes
                    </button>
                    <button type="button"
        class="btn btn-outline-danger px-4 py-2 fw-bold"
        onclick="chooseFinanceBranch()">
    <i class="fa-solid fa-xmark me-1"></i> No
</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL FLOW — STEP 4 (Faculty branch): "Are you a registered faculty?"
     ------------------------------------------------------------
     YES → #loginModal     (existing login)
     NO  → #registerModal  (existing register, role locked to Professor)
     ============================================================ -->
<div class="modal fade" id="modalQuestionFacultyReg" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg text-center" style="border-radius: 16px;">
            <div class="p-4 text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-primary) 100%); border-top-left-radius: 16px; border-top-right-radius: 16px;">
                <i class="fa-solid fa-chalkboard-user fa-3x mb-2 text-warning"></i>
                <h4 class="fw-bold mb-0">Faculty Verification</h4>
            </div>
            <div class="modal-body p-4">
                <h5 class="fw-semibold text-dark mb-4">Are you a registered faculty?</h5>
                <div class="d-flex justify-content-center gap-3">
                    <!-- YES → Existing login modal -->
                    <button type="button"
                            class="btn btn-grc px-4 py-2 fw-bold"
                            onclick="openLoginForFaculty()">
                        <i class="fa-solid fa-check me-1"></i> Yes
                    </button>
                    <!-- NO → Existing register modal, role = Professor -->
                    <button type="button"
                            class="btn btn-outline-danger px-4 py-2 fw-bold"
                            onclick="openRegisterForFaculty()">
                        <i class="fa-solid fa-xmark me-1"></i> No
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL FLOW — STEP 5 (Finance branch): "Are you a Finance user?"
     ------------------------------------------------------------
     YES → #modalQuestionFinanceReg ("Are you a registered Finance user?")
     NO  → Back to "Are you a student?"
     ============================================================ -->
<div class="modal fade" id="modalQuestionFinance" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg text-center" style="border-radius: 16px;">
            <div class="p-4 text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-primary) 100%); border-top-left-radius: 16px; border-top-right-radius: 16px;">
                <i class="fa-solid fa-coins fa-3x mb-2 text-warning"></i>
                <h4 class="fw-bold mb-0">Finance Verification</h4>
            </div>
            <div class="modal-body p-4">
                <h5 class="fw-semibold text-dark mb-4">Are you a Finance?</h5>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-grc px-4 py-2 fw-bold" onclick="chooseRegisteredFinanceBranch()">
                        <i class="fa-solid fa-check me-1"></i> Yes
                    </button>
                    <button type="button" class="btn btn-outline-danger px-4 py-2 fw-bold"
                            onclick="goBackToPortalQuestion('modalQuestionFinance')">
                        <i class="fa-solid fa-xmark me-1"></i> No
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalQuestionFinanceReg" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg text-center" style="border-radius: 16px;">
            <div class="p-4 text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-primary) 100%); border-top-left-radius: 16px; border-top-right-radius: 16px;">
                <i class="fa-solid fa-coins fa-3x mb-2 text-warning"></i>
                <h4 class="fw-bold mb-0">Finance Verification</h4>
            </div>
            <div class="modal-body p-4">
                <h5 class="fw-semibold text-dark mb-4">Are you a registered Finance?</h5>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-grc px-4 py-2 fw-bold" onclick="openLoginForFinance()">
                        <i class="fa-solid fa-check me-1"></i> Yes
                    </button>
                    <button type="button" class="btn btn-outline-danger px-4 py-2 fw-bold" onclick="openRegisterForFinance()">
                        <i class="fa-solid fa-xmark me-1"></i> No
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

    <!-- Portal Capabilities -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center fw-bold mb-5 text-dark">Portal Capabilities</h2>
            <div class="row g-4 text-center">
                <div class="col-md-6 col-lg-3">
                    <div class="card feature-card h-100 p-4">
                        <div class="card-body">
                            <i class="bi bi-mortarboard-fill text-grc display-4 mb-3 d-block"></i>
                            <h5 class="card-title fw-bold text-dark">Academic Grades</h5>
                            <p class="card-text text-muted">View subject scores, evaluation marks, and semester GPA performance metrics in real time.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="card feature-card h-100 p-4">
                        <div class="card-body">
                            <i class="bi bi-receipt-cutoff text-grc display-4 mb-3 d-block"></i>
                            <h5 class="card-title fw-bold text-dark">Financial Statements</h5>
                            <p class="card-text text-muted">Track tuition ledger histories, view account assessment details, and monitor running balances.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="card feature-card h-100 p-4">
                        <div class="card-body">
                            <i class="bi bi-printer-fill text-grc display-4 mb-3 d-block"></i>
                            <h5 class="card-title fw-bold text-dark">Print Manifests</h5>
                            <p class="card-text text-muted">Generate official printable PDF documents, grade certificates, and enrollment verifications.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="card feature-card h-100 p-4">
                        <div class="card-body">
                            <i class="bi bi-megaphone-fill text-grc display-4 mb-3 d-block"></i>
                            <h5 class="card-title fw-bold text-dark">Portal Announcements</h5>
                            <p class="card-text text-muted">Stay updated with official campus news, events, academic notices, and department updates.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- MODAL: ANNOUNCEMENTS LIST POPUP -->
    <div class="modal fade" id="announcementsListModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h4 class="fw-bold text-dark mb-0"><i class="bi bi-megaphone-fill me-2 text-grc"></i>Latest Announcements</h4>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <?php if (count($announcements) > 0): ?>
                            <?php foreach ($announcements as $announcement): 
    $priority = $announcement['priority'] ?? 'Normal';
    $priorityClass = 'bg-secondary';
    if ($priority === 'Urgent')      $priorityClass = 'bg-danger';
    elseif ($priority === 'Important') $priorityClass = 'bg-warning text-dark';

    $borderClass = 'announcement-card';
    if ($priority === 'Urgent')       $borderClass .= ' ann-urgent';
    elseif ($priority === 'Important')$borderClass .= ' ann-important';
?>
    <div class="col-12">
        <div class="card <?= $borderClass ?> p-2 shadow-sm" 
             style="cursor: pointer;"
             data-bs-toggle="modal" 
             data-bs-target="#announcementModal"
             data-title="<?= htmlspecialchars($announcement['title']) ?>"
             data-date="<?= date('M d, Y', strtotime($announcement['created_at'])) ?>"
             data-content="<?= htmlspecialchars($announcement['content']) ?>">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
                    <h5 class="card-title fw-bold text-dark mb-0">
                        <?php if (!empty($announcement['is_pinned'])): ?>
                            <i class="bi bi-pin-angle-fill text-danger me-1"></i>
                        <?php else: ?>
                            <i class="bi bi-megaphone-fill text-danger me-1"></i>
                        <?php endif; ?>
                        <?= htmlspecialchars($announcement['title']) ?>
                    </h5>
                    <div class="d-flex gap-1">
                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($announcement['category'] ?? 'General') ?></span>
                        <span class="badge <?= $priorityClass ?>"><?= htmlspecialchars($priority) ?></span>
                    </div>
                </div>
                <p class="card-text text-muted small mb-2"><?= htmlspecialchars(mb_strimwidth($announcement['content'], 0, 140, '...')) ?></p>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small grc-link">Read Full Announcement <i class="bi bi-arrow-right"></i></span>
                    <small class="text-muted" style="font-size: 0.72rem;">
                        <?= date('M d, Y', strtotime($announcement['created_at'])) ?>
                        <?php if (!empty($announcement['posted_by'])): ?>
                            · <?= htmlspecialchars($announcement['posted_by']) ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <div class="alert alert-info border-0 shadow-sm mb-0">No announcements published at this time.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: INDIVIDUAL ANNOUNCEMENT DETAILS -->
    <div class="modal fade" id="announcementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="modalAnnTitle">Announcement Title</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-muted small mb-3"><i class="bi bi-calendar3 me-1"></i> <span id="modalAnnDate"></span></div>
                    <p class="text-secondary" id="modalAnnContent" style="white-space: pre-wrap;"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: CONTACT US -->
    <div class="modal fade" id="contactModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-dark-custom shadow-lg">
                <div class="modal-header border-bottom border-secondary pb-3">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-envelope-fill me-2 text-danger"></i>Contact Administration</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <form>
                        <div class="mb-3">
                            <label class="form-label small text-light">Your Full Name</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary" placeholder="John Doe">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-light">Email Address</label>
                            <input type="email" class="form-control bg-dark text-white border-secondary" placeholder="name@example.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-light">Message / Inquiry</label>
                            <textarea class="form-control bg-dark text-white border-secondary" rows="4" placeholder="Type your message here..."></textarea>
                        </div>
                        <button type="button" class="btn btn-grc w-100 py-2 fw-bold" data-bs-dismiss="modal">Send Message</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: ABOUT GRC -->
    <div class="modal fade" id="aboutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content modal-dark-custom shadow-lg">
                <div class="modal-header border-bottom border-secondary pb-3">
                    <h4 class="modal-title fw-bold text-white"><i class="bi bi-building me-2 text-danger"></i>About Global Reciprocal Colleges</h4>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-light opacity-75"><b>Global Reciprocal Colleges is committed to providing accessible, high-quality education to empower young minds and nurture future industry leaders.</b></p>
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <div class="p-3 about-card-dark h-100">
                                <h6 class="fw-bold text-danger mb-2"><i class="bi bi-eye me-2"></i>Vision</h6>
                                <p class="small text-light opacity-75 mb-0"><b>“A global community of excellent individuals with values.”</b></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 about-card-dark h-100">
                                <h6 class="fw-bold text-danger mb-2"><i class="bi bi-compass me-2"></i>Mission</h6>
                                <p class="small text-light opacity-75 mb-0"><b>“GRC is creating a culture for successful, socially responsible, morally upright skilled workers and highly competent professionals through values-based quality education.”</b></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 about-card-dark h-100">
                                <h6 class="fw-bold text-danger mb-2"><i class="bi bi-postcard-heart me-2"></i>Core Values</h6>
                                <p class="small text-light opacity-75 mb-0"><b>God-Fearing, Reciprocating, Committing to Excellence.</b></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 about-card-dark h-100">
                                <h6 class="fw-bold text-danger mb-2"><i class="bi bi-bullseye me-2"></i>PHILOSOPHY</h6>
                                <p class="small text-light opacity-75 mb-0"><b>Touching Hearts, Renewing Minds, Transforming Lives.</b></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalQuestion1" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg text-center" style="border-radius: 16px;">
            <div class="p-4 text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-primary) 100%); border-top-left-radius: 16px; border-top-right-radius: 16px;">
                <i class="fa-solid fa-circle-question fa-3x mb-2 text-warning"></i>
                <h4 class="fw-bold mb-0">Portal Access Verification</h4>
            </div>
            <div class="modal-body p-4">
                <h5 class="fw-semibold text-dark mb-4">Are you a Student?</h5>
                <div class="d-flex justify-content-center gap-3">
                    <!-- YES → Student branch -->
                    <button type="button"
                            class="btn btn-grc px-4 py-2 fw-bold"
                            onclick="chooseStudentBranch()">
                        <i class="fa-solid fa-check me-1"></i> Yes
                    </button>
                    <!-- NO → Intermediate department question -->
                    <button type="button"
                            class="btn btn-outline-danger px-4 py-2 fw-bold"
                            onclick="chooseDepartmentBranch()">
                        <i class="fa-solid fa-xmark me-1"></i> No
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="modal fade" id="modalQuestionStudent" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg text-center" style="border-radius: 16px;">
                <div class="p-4 text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-primary) 100%); border-top-left-radius: 16px; border-top-right-radius: 16px;">
                    <i class="fa-solid fa-user-graduate fa-3x mb-2 text-warning"></i>
                    <h4 class="fw-bold mb-0">Student Verification</h4>
                </div>
                <div class="modal-body p-4">
                    <h5 class="fw-semibold text-dark mb-4">Are you a registered student?</h5>
                    <div class="d-flex justify-content-center gap-3">
                        <button class="btn btn-grc px-4 py-2 fw-bold" data-bs-target="#loginModal" data-bs-toggle="modal">
                            <i class="fa-solid fa-check me-1"></i> Yes
                        </button>
                        <button class="btn btn-outline-danger px-4 py-2 fw-bold" data-bs-target="#registerModal" data-bs-toggle="modal">
                            <i class="fa-solid fa-xmark me-1"></i> No
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalQuestionDept" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg text-center" style="border-radius: 16px;">
                <div class="p-4 text-white" style="background: linear-gradient(135deg, var(--grc-dark) 0%, var(--grc-primary) 100%); border-top-left-radius: 16px; border-top-right-radius: 16px;">
                    <i class="fa-solid fa-building-user fa-3x mb-2 text-warning"></i>
                    <h4 class="fw-bold mb-0">Department Verification</h4>
                </div>
                <div class="modal-body p-4">
                    <h5 class="fw-semibold text-dark mb-4">Are you a registered department?</h5>
                    <div class="d-flex justify-content-center gap-3">
                        <button class="btn btn-grc px-4 py-2 fw-bold" data-bs-target="#loginModal" data-bs-toggle="modal">
                            <i class="fa-solid fa-check me-1"></i> Yes
                        </button>
                        <button class="btn btn-outline-danger px-4 py-2 fw-bold" data-bs-target="#registerModal" data-bs-toggle="modal">
                            <i class="fa-solid fa-xmark me-1"></i> No
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL LOGIN -->
    <div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                <div class="p-4 text-center text-white" style="background: linear-gradient(135deg, var(--grc-primary) 0%, var(--grc-secondary) 100%); border-bottom: 4px solid var(--grc-hover);">
                    <button type="button" class="btn-close btn-close-white float-end" data-bs-dismiss="modal" aria-label="Close"></button>
                    <img src="assets/grc4.png" alt="GRC Logo" class="mb-2" style="height: 55px;">
                    <h4 class="mb-0 fw-bold">Global Reciprocal Colleges</h4>
                    <small style="color: #ffcccc; font-weight: 600;">STUDENT & FACULTY PORTAL LOGIN</small>
                </div>
                <div class="modal-body p-4">
                    <?php if (isset($_GET['login_error'])): ?>
    <div class="alert alert-danger py-2 bg-danger bg-opacity-10 text-danger border-0 rounded-3 small mb-3">
        <i class="fa-solid fa-circle-exclamation me-1"></i>
        <?php 
            $err = $_GET['login_error'];
            if ($err == 'invalid') echo "Invalid username or password.";
            elseif ($err == 'locked') echo "Your account has been locked.";
            elseif ($err == 'empty') echo "Please enter both username and password.";
            elseif ($err == 'expired') echo "Session expired. Please log in again.";
            elseif ($err == 'csrf') echo "Your session expired. Please refresh the page and try again.";
        ?>
    </div>
<?php endif; ?>

                    <?php if ($active_modal === 'login' && !empty($msg)): ?>
                        <div class="alert alert-<?= $msg_type; ?> py-2 border-0 bg-opacity-10 rounded-3 small mb-3">
                            <i class="fa-solid fa-circle-info me-1"></i> <?= htmlspecialchars($msg); ?>
                        </div>
                    <?php endif; ?>

                    <form action="authenticate.php" method="POST" id="loginForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark small">Username or Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                                <input type="text" class="form-control" name="identity" required placeholder="Enter username or email">
                            </div>
                        </div>
                        
                        <div class="mb-3">
    <label class="form-label fw-semibold text-dark small">Password</label>
    <div class="input-group">
        <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
        <input type="password" class="form-control" name="password" id="loginPassword" required placeholder="Enter password">
        <button type="button" class="btn btn-outline-secondary password-toggle-btn" id="togglePasswordBtn"
                aria-label="Show password" title="Show password">
            <i class="fa-solid fa-eye" id="togglePasswordIcon"></i>
        </button>
    </div>
</div>

                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="rememberMe" name="rememberMe">
                                <label class="form-check-label text-secondary small" for="rememberMe">Remember Me</label>
                            </div>
                            <a class="small grc-link" data-bs-target="#forgotModal" data-bs-toggle="modal">Forgot Password?</a>
                        </div>

                        <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-right-to-bracket me-1"></i> Login</button>
                    </form>

                    <div class="text-center mt-4 pt-2 border-top">
                        <span class="small text-secondary">Don't have an account?</span> 
                        <a class="small grc-link ms-1" data-bs-target="#registerModal" data-bs-toggle="modal">Register Here</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL REGISTER -->
    <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <div class="text-center mb-4">
                        <i class="fa-solid fa-user-plus fa-2x text-danger mb-2"></i>
                        <h4 class="fw-bold text-dark mb-1">Create Portal Account</h4>
                        <span class="badge bg-secondary">Global Reciprocal Colleges</span>
                    </div>

                    <?php if ($active_modal === 'register' && !empty($msg)): ?>
                        <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger py-2 small mb-3 rounded-3">
                            <i class="fa-solid fa-circle-exclamation me-1"></i> <?= htmlspecialchars($msg); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($reg_step === 1): ?>
                        <p class="text-secondary small text-center mb-3">Fill in your details to register. Students must match their Admin Master List ID.</p>

<!-- STEP 1: Registration Form inside index.php -->
<form method="POST">
    <input type="hidden" name="action" value="reg_submit_details">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
    
    <div class="row g-2 mb-3">
        <!-- Role Select Field -->
        <div class="col-md-6">
            <label class="form-label small fw-semibold text-dark">Role</label>
            <select name="role" id="roleSelect" class="form-select form-select-sm" required>
                <?php $sel_role = $old['role'] ?? 'Student'; ?>
                <option value="Student" <?= $sel_role === 'Student' ? 'selected' : '' ?>>Student</option>
<option value="Professor" <?= $sel_role === 'Professor' ? 'selected' : '' ?>>Professor / Faculty</option>
<option value="Department" <?= $sel_role === 'Department' ? 'selected' : '' ?>>Department / Admin</option>
<option value="finance" <?= $sel_role === 'finance' ? 'selected' : '' ?>>Finance Office</option>
            </select>
        </div>

        <!-- Student ID Field with Check ID Button -->
        <div class="col-md-6">
            <label id="idLabel" class="form-label small fw-semibold text-dark">Student ID Number</label>
            <div class="input-group input-group-sm">
                <input type="text" name="student_id" id="idInput" class="form-control" required placeholder="e.g. 2024-01-00001" value="<?= htmlspecialchars($old['student_id'] ?? ''); ?>">
                <button type="button" id="btnCheckID" class="btn btn-outline-danger fw-semibold px-2">
                    <i class="fa-solid fa-magnifying-glass me-1"></i> Check ID
                </button>
            </div>
            <span id="idHelpText" class="text-muted d-block mt-1" style="font-size: 0.72rem;">Must be registered in Admin Master List.</span>
        </div>
    </div>

    <!-- First Name and Last Name Inputs -->
    <div class="row g-2 mb-3">
        <div class="col-6">
            <label class="form-label small fw-semibold text-dark">First Name</label>
            <input type="text" name="first_name" id="firstNameInput" class="form-control form-control-sm" required placeholder="First Name" value="<?= htmlspecialchars($old['first_name'] ?? ''); ?>">
        </div>
        <div class="col-6">
            <label class="form-label small fw-semibold text-dark">Last Name</label>
            <input type="text" name="last_name" id="lastNameInput" class="form-control form-control-sm" required placeholder="Last Name" value="<?= htmlspecialchars($old['last_name'] ?? ''); ?>">
        </div>
    </div>

    <!-- Department & Course -->
    <div class="row g-2 mb-3">
        <div class="col-6">
            <label class="form-label small fw-semibold text-dark">Department</label>
            <select name="department" id="deptSelect" class="form-select form-select-sm" required>
                <option value="">-- Select Department --</option>
                <option value="College of Computer Studies">College of Computer Studies</option>
                <option value="College of Business Administration">College of Business Administration</option>
                <option value="College of Education">College of Education</option>
                <option value="College of Accountancy">College of Accountancy</option>
            </select>
        </div>
        <div class="col-6" id="courseContainer">
            <label class="form-label small fw-semibold text-dark">Course / Degree</label>
            <select name="course" id="courseSelect" class="form-select form-select-sm">
                <option value="">-- Select Course --</option>
            </select>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6">
            <label class="form-label small fw-semibold text-dark">Username</label>
            <input type="text" name="username" class="form-control form-control-sm" required placeholder="Username" value="<?= htmlspecialchars($old['username'] ?? ''); ?>">
        </div>
        <div class="col-6">
            <label class="form-label small fw-semibold text-dark">Phone Number</label>
            <input type="text" name="phone" class="form-control form-control-sm" required placeholder="09123456789" value="<?= htmlspecialchars($old['phone'] ?? ''); ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label small fw-semibold text-dark">Email Address</label>
        <input type="email" id="regEmailInput" name="email" class="form-control form-control-sm" required placeholder="user@grc.edu.ph" value="<?= htmlspecialchars($old['email'] ?? ($_SESSION['reg_email'] ?? '')); ?>">
    </div>

    <div class="mb-2">
    <label class="form-label small fw-semibold text-dark">Password</label>
    <div class="input-group input-group-sm">
        <input type="password" id="regPassInput" name="password" class="form-control form-control-sm" required>
        <button type="button" class="btn btn-outline-secondary password-toggle-btn" id="toggleRegPassBtn"
                aria-label="Show password" title="Show password">
            <i class="fa-solid fa-eye" id="toggleRegPassIcon"></i>
        </button>
    </div>
</div>

    <div class="progress mb-1" style="height: 6px;">
        <div id="regStrengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
    </div>
    <div class="d-flex justify-content-between mb-1">
        <span class="small text-muted" style="font-size: 0.72rem;">Strength:</span>
        <span id="regStrengthText" class="fw-bold small" style="font-size: 0.72rem; color: #888;">Weak</span>
    </div>
    <div class="text-muted mb-3" style="font-size: 0.72rem;">Password must be at least 8 characters with upper, lower, number, and special character.</div>

    <div class="mb-2">
    <label class="form-label small fw-semibold text-dark">Confirm Password</label>
    <div class="input-group input-group-sm">
        <input type="password" id="regConfirmPassInput" name="confirm_password" class="form-control form-control-sm" required>
        <button type="button" class="btn btn-outline-secondary password-toggle-btn" id="toggleRegConfirmBtn"
                aria-label="Show password" title="Show password">
            <i class="fa-solid fa-eye" id="toggleRegConfirmIcon"></i>
        </button>
    </div>
</div>

    <div class="progress mb-1" style="height: 6px;">
        <div id="regMatchBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
    </div>
    <div class="d-flex justify-content-between mb-3">
        <span class="small text-muted" style="font-size: 0.72rem;">Match:</span>
        <span id="regMatchStatusText" class="fw-bold small" style="font-size: 0.72rem; color: #888;">Not Matching</span>
    </div>

    <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Register & Send OTP</button>
</form>
                    <?php else: ?>
                        <p class="text-secondary small text-center mb-3">Enter the 6-digit OTP sent to <strong><?= htmlspecialchars($_SESSION['reg_email'] ?? ''); ?></strong></p>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="reg_verify_otp">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-4">
                                <label class="form-label small fw-semibold text-dark">OTP Verification Code</label>
                                <input type="text" name="otp_code" class="form-control text-center fw-bold fs-3" maxlength="6" required placeholder="123456">
                            </div>
                            <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold mb-2"><i class="fa-solid fa-shield-check me-1"></i> Verify OTP & Activate Account</button>
                        </form>

                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="reg_back_step1">
                            <button type="submit" class="btn btn-outline-secondary w-100 py-2 fw-semibold btn-sm">
                                <i class="fa-solid fa-pen-to-square me-1"></i> Change Email / Details
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="text-center mt-4 pt-2 border-top">
                        <a class="small grc-link" data-bs-target="#loginModal" data-bs-toggle="modal"><i class="fa-solid fa-arrow-left me-1"></i> Already have an account? Log in</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL FORGOT PASSWORD -->
    <div class="modal fade" id="forgotModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <div class="text-center mb-4">
                        <i class="fa-solid fa-key fa-2x text-danger mb-2"></i>
                        <h4 class="fw-bold text-dark mb-1">Password Recovery</h4>
                        <span class="badge bg-secondary">Global Reciprocal Colleges</span>
                    </div>

                    <?php if ($active_modal === 'forgot' && !empty($msg)): ?>
                        <div class="alert alert-<?= $msg_type === 'danger' ? 'danger' : ($msg_type === 'success' ? 'success' : 'info'); ?> border-0 bg-opacity-10 py-2 small mb-3 rounded-3">
                            <i class="fa-solid fa-circle-info me-1"></i> <?= htmlspecialchars($msg); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($forgot_step === 1): ?>
                        <p class="text-secondary small text-center mb-4">Enter your registered email address to receive a 6-digit OTP code.</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="forgot_send_otp">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-4">
                                <label class="form-label small fw-semibold text-dark">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                                    <input type="email" name="email" class="form-control" required placeholder="user@grc.edu.ph">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Send OTP Code</button>
                        </form>

                    <?php elseif ($forgot_step === 2): ?>
                        <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded border">
                            <small class="text-muted text-truncate me-2">
                                Sent to: <strong><?= htmlspecialchars($_SESSION['reset_email'] ?? ''); ?></strong>
                            </small>
                            <form method="POST" class="m-0">
                                <input type="hidden" name="action" value="forgot_change_email">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2 fw-semibold" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-pen-to-square me-1"></i> Change Email
                                </button>
                            </form>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="forgot_verify_otp">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-4">
                                <label class="form-label small fw-semibold text-dark">Enter 6-Digit OTP Code</label>
                                <input type="text" name="otp_code" class="form-control text-center fw-bold fs-3" maxlength="6" required placeholder="123456" autofocus>
                            </div>

                            <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-shield-check me-1"></i> Verify OTP Code</button>
                        </form>

                    <?php elseif ($forgot_step === 3): ?>
                        <p class="text-secondary small text-center mb-3">OTP verified. Please enter your new password below.</p>

                        <form method="POST">
                            <input type="hidden" name="action" value="forgot_reset_password">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-2">
    <label class="form-label small fw-semibold text-dark">New Password</label>
    <div class="input-group">
        <input type="password" id="forgotPassInput" name="new_password" class="form-control" required placeholder="Enter new password">
        <button type="button" class="btn btn-outline-secondary password-toggle-btn" id="toggleForgotPassBtn"
                aria-label="Show password" title="Show password">
            <i class="fa-solid fa-eye" id="toggleForgotPassIcon"></i>
        </button>
    </div>
</div>

                            <div class="progress mb-2" style="height: 6px;">
                                <div id="forgotStrengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span class="small text-muted" style="font-size: 0.75rem;">Strength:</span>
                                <span id="forgotStrengthText" class="fw-bold small" style="font-size: 0.75rem; color: #888;">Weak</span>
                            </div>

                            <div class="mb-2">
    <label class="form-label small fw-semibold text-dark">Confirm New Password</label>
    <div class="input-group">
        <input type="password" id="forgotConfirmPassInput" name="confirm_password" class="form-control" required placeholder="Confirm new password">
        <button type="button" class="btn btn-outline-secondary password-toggle-btn" id="toggleForgotConfirmBtn"
                aria-label="Show password" title="Show password">
            <i class="fa-solid fa-eye" id="toggleForgotConfirmIcon"></i>
        </button>
    </div>
</div>

                            <div class="progress mb-2" style="height: 6px;">
                                <div id="forgotMatchBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
                            </div>
                            <div class="d-flex justify-content-between mb-4">
                                <span class="small text-muted" style="font-size: 0.75rem;">Match:</span>
                                <span id="forgotMatchStatusText" class="fw-bold small" style="font-size: 0.75rem; color: #888;">Not Matching</span>
                            </div>

                            <button type="submit" class="btn btn-grc w-100 py-2.5 fw-bold"><i class="fa-solid fa-lock me-1"></i> Update Password</button>
                        </form>
                    <?php endif; ?>

                    <div class="text-center mt-4 pt-2 border-top">
                        <a class="small grc-link" data-bs-target="#loginModal" data-bs-toggle="modal"><i class="fa-solid fa-arrow-left me-1"></i> Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="mt-auto py-4" style="background-color: var(--grc-dark); color: #ffffff;">
        <div class="container text-center">
            <p class="mb-1">&copy; <?= date('Y') ?> Global Reciprocal Colleges. All rights reserved.</p>
            <small class="text-secondary">Student & Faculty Web Portal System</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
    // Handle Active Modal Trigger
    const activeModal = "<?= $active_modal ?>";
    if (activeModal === 'login') {
        new bootstrap.Modal(document.getElementById('loginModal')).show();
    } else if (activeModal === 'register') {
        new bootstrap.Modal(document.getElementById('registerModal')).show();
    } else if (activeModal === 'forgot') {
        new bootstrap.Modal(document.getElementById('forgotModal')).show();
    }

    // Dynamic Department & Course Setup
    const deptSelect = document.getElementById('deptSelect');
    const courseSelect = document.getElementById('courseSelect');
    const coursesByDept = {
        "College of Computer Studies": ["BSIT - BS Information Technology"],
        "College of Business Administration": [
            "BSBA Financial Management",
            "BSBA Marketing Management",
            "BSBA Human Resource Management"
        ],
        "College of Education": [
            "BSED - Bachelor of Secondary Education",
            "BEED - Bachelor of Elementary Education"
        ],
        "College of Accountancy": ["BSA - BS Accountancy"],
        "Non-Academic Department": [
            "Administration Office",
            "IT Support & Systems",
            "Registrar",
            "Accounting & Finance"
        ]
    };

    function updateCourses() {
        if (!deptSelect || !courseSelect) return;
        const selectedDept = deptSelect.value;
        courseSelect.innerHTML = '<option value="">-- Select Option --</option>';
        if (coursesByDept[selectedDept]) {
            coursesByDept[selectedDept].forEach(c => {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = c;
                courseSelect.appendChild(opt);
            });
        }
    }

    if (deptSelect) {
        deptSelect.addEventListener('change', updateCourses);
    }

    // 1. Dynamic Role Change Listener
const roleSelect = document.getElementById('roleSelect');
const idLabel = document.getElementById('idLabel');
const idInput = document.getElementById('idInput');
const idHelpText = document.getElementById('idHelpText');

if (roleSelect) {
    roleSelect.addEventListener('change', () => {
        const val = roleSelect.value;

        if (val === 'Department') {
            idLabel.textContent = 'Dept / Employee ID';
            idInput.placeholder = 'DEP-2024-001';
            idHelpText.textContent = 'No master list verification needed for this role.';
            idHelpText.className = 'text-muted d-block mt-1';
            if (btnCheckID) btnCheckID.classList.add('d-none');
            firstNameInput.readOnly = false;
            lastNameInput.readOnly = false;
            deptSelect.innerHTML = `<option value="Non-Academic Department">Non-Academic / Administrative Office</option>`;
            updateCourses();

        } else if (val === 'Professor') {
            idLabel.textContent = 'Faculty ID';
            idInput.placeholder = 'FAC-2024-001';
            idHelpText.textContent = 'No master list verification needed for this role.';
            idHelpText.className = 'text-muted d-block mt-1';
            if (btnCheckID) btnCheckID.classList.add('d-none');
            firstNameInput.readOnly = false;
            lastNameInput.readOnly = false;
            deptSelect.innerHTML = `
                <option value="">-- Select Faculty Department --</option>
                <option value="College of Computer Studies">College of Computer Studies</option>
                <option value="College of Business Administration">College of Business Administration</option>
                <option value="College of Education">College of Education</option>
                <option value="College of Accountancy">College of Accountancy</option>
            `;
            updateCourses();

        } else if (val === 'finance') {
            /* ============================================================
               FINANCE BRANCH — NEW
               ------------------------------------------------------------
               Finance staff do NOT have a Student ID, do NOT appear in
               the Student Master List, and therefore do NOT need the
               "Check ID" button. They only need a Finance ID + their
               office/department name.
               ============================================================ */
            idLabel.textContent = 'Finance ID';
            idInput.placeholder = 'FIN-08-2026';
            idHelpText.textContent = 'No master list verification needed for this role.';
            idHelpText.className = 'text-muted d-block mt-1';
            if (btnCheckID) btnCheckID.classList.add('d-none');

            firstNameInput.readOnly = false;
            lastNameInput.readOnly = false;

            // Finance belongs to a single office. Populate the department
            // dropdown with the finance-specific options and no course list.
            deptSelect.innerHTML = `
                <option value="Finance Office">Finance Office</option>
                <option value="Accounting Office">Accounting Office</option>
                <option value="Cashiering Office">Cashiering Office</option>
            `;

            // Course is not applicable to Finance. Hide the course container.
            const courseContainer = document.getElementById('courseContainer');
            if (courseContainer) courseContainer.style.display = 'none';
            courseSelect.innerHTML = '';

        } else {
            /* ------------------- Student branch (default) ------------------- */
            idLabel.textContent = 'Student ID Number';
            idInput.placeholder = 'e.g. 2024-01-00001';
            idHelpText.textContent = 'Must be registered in Admin Master List.';
            idHelpText.className = 'text-muted d-block mt-1';
            if (btnCheckID) btnCheckID.classList.remove('d-none');

            firstNameInput.readOnly = false;
            lastNameInput.readOnly = false;

            deptSelect.innerHTML = `
                <option value="">-- Select Department --</option>
                <option value="College of Computer Studies">College of Computer Studies</option>
                <option value="College of Business Administration">College of Business Administration</option>
                <option value="College of Education">College of Education</option>
                <option value="College of Accountancy">College of Accountancy</option>
            `;

            // Ensure the course container is visible again for Students.
            const courseContainer = document.getElementById('courseContainer');
            if (courseContainer) courseContainer.style.display = '';

            updateCourses();
        }
    });
}

// ... roleSelect change listener ...

/* ---------- Reusable Show/Hide Password Toggle ---------- */
function setupPasswordToggle(toggleBtnId, toggleIconId, passwordInputId) {
    const btn   = document.getElementById(toggleBtnId);
    const icon  = document.getElementById(toggleIconId);
    const input = document.getElementById(passwordInputId);
    if (!btn || !icon || !input) return;

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';

        icon.classList.toggle('fa-eye', !isHidden);
        icon.classList.toggle('fa-eye-slash', isHidden);

        btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        btn.setAttribute('title',       isHidden ? 'Hide password' : 'Show password');

        input.focus();
    });

    btn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            btn.click();
        }
    });
}

/* ---------- Apply to Login Modal ---------- */
setupPasswordToggle('togglePasswordBtn', 'togglePasswordIcon', 'loginPassword');

/* ---------- Apply to Register Modal ---------- */
setupPasswordToggle('toggleRegPassBtn',      'toggleRegPassIcon',      'regPassInput');
setupPasswordToggle('toggleRegConfirmBtn',   'toggleRegConfirmIcon',   'regConfirmPassInput');

/* ---------- Apply to Forgot Password Modal ---------- */
setupPasswordToggle('toggleForgotPassBtn',    'toggleForgotPassIcon',    'forgotPassInput');
setupPasswordToggle('toggleForgotConfirmBtn', 'toggleForgotConfirmIcon', 'forgotConfirmPassInput');

// 2. Check Student ID Handler
const btnCheckID = document.getElementById('btnCheckID');
const firstNameInput = document.getElementById('firstNameInput');
const lastNameInput = document.getElementById('lastNameInput');

if (btnCheckID) {
    btnCheckID.addEventListener('click', function(e) {
        e.preventDefault();
        const studentId = idInput.value.trim();

        if (!studentId) {
            idHelpText.textContent = 'Please enter a Student ID to verify.';
            idHelpText.className = 'text-danger d-block mt-1';
            return;
        }

        fetch(`check_student_id.php?student_id=${encodeURIComponent(studentId)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    firstNameInput.value = data.data.first_name || '';
                    lastNameInput.value = data.data.last_name || '';

                    if (data.data.department && deptSelect) {
                        deptSelect.value = data.data.department;
                        updateCourses();
                        if (data.data.course && courseSelect) {
                            courseSelect.value = data.data.course;
                        }
                    }

                    idHelpText.textContent = '✓ ' + data.message;
                    idHelpText.className = 'text-success d-block mt-1 fw-semibold';
                    firstNameInput.readOnly = true;
                    lastNameInput.readOnly = true;
                } else {
                    firstNameInput.value = '';
                    lastNameInput.value = '';
                    firstNameInput.readOnly = false;
                    lastNameInput.readOnly = false;

                    idHelpText.textContent = '✕ ' + data.message;
                    idHelpText.className = 'text-danger d-block mt-1 fw-semibold';
                }
            })
            .catch(err => {
                console.error('Error checking ID:', err);
                idHelpText.textContent = 'Error connecting to server. Please try again.';
                idHelpText.className = 'text-danger d-block mt-1';
            });
    });
}

// Restore role / department / course after a failed submit (page reload)
const oldReg = <?= json_encode([
    'role'       => $old['role'] ?? '',
    'department' => $old['department'] ?? '',
    'course'     => $old['course'] ?? '',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
if (oldReg.role && roleSelect) {
    roleSelect.value = oldReg.role;
    roleSelect.dispatchEvent(new Event('change')); // rebuilds labels + department list for that role
    if (oldReg.department && deptSelect) {
        deptSelect.value = oldReg.department;
        updateCourses();
        if (oldReg.course && courseSelect) courseSelect.value = oldReg.course;
    }
}

window.setPortalRole = function (type) {
    if (type === 'Student') {
        lockRoleTo('Student');
    } else {
        unlockRole();
    }
};

        // Dynamic Announcement Modal Populator
        const annCards = document.querySelectorAll('.announcement-card');
        annCards.forEach(card => {
            card.addEventListener('click', () => {
                document.getElementById('modalAnnTitle').textContent = card.dataset.title;
                document.getElementById('modalAnnDate').textContent = card.dataset.date;
                document.getElementById('modalAnnContent').textContent = card.dataset.content;
            });
        });

        // Real-Time Password Validation Functions
        function evaluatePasswordStrength(pass, emailVal, strengthBar, strengthText) {
            if (!strengthBar || !strengthText) return;
            let score = 0;
            const emailUser = emailVal ? emailVal.split('@')[0].toLowerCase() : '';

            if (pass.length >= 8) score++;
            if (/[A-Z]/.test(pass)) score++;
            if (/[a-z]/.test(pass)) score++;
            if (/[0-9]/.test(pass)) score++;
            if (/[!@#$%^&*(),.?":{}|<>]/.test(pass)) score++;

            if (emailUser && pass.toLowerCase().includes(emailUser)) {
                score = 0;
            }

            if (pass.length === 0) {
                strengthBar.style.width = '0%';
                strengthBar.className = 'progress-bar';
                strengthText.textContent = 'Weak';
                strengthText.style.color = '#888';
            } else if (score < 3) {
                strengthBar.style.width = '33%';
                strengthBar.className = 'progress-bar bg-danger';
                strengthText.textContent = 'Weak';
                strengthText.style.color = '#dc3545';
            } else if (score < 5) {
                strengthBar.style.width = '66%';
                strengthBar.className = 'progress-bar bg-warning';
                strengthText.textContent = 'Moderate';
                strengthText.style.color = '#ffc107';
            } else {
                strengthBar.style.width = '100%';
                strengthBar.className = 'progress-bar bg-success';
                strengthText.textContent = 'Strong';
                strengthText.style.color = '#198754';
            }
        }

        function evaluatePasswordMatch(pass, confirmPass, matchBar, matchStatusText) {
            if (!matchBar || !matchStatusText) return;
            if (!confirmPass) {
                matchBar.style.width = '0%';
                matchBar.className = 'progress-bar';
                matchStatusText.textContent = 'Not Matching';
                matchStatusText.style.color = '#888';
            } else if (pass === confirmPass) {
                matchBar.style.width = '100%';
                matchBar.className = 'progress-bar bg-success';
                matchStatusText.textContent = 'Matching';
                matchStatusText.style.color = '#198754';
            } else {
                matchBar.style.width = '50%';
                matchBar.className = 'progress-bar bg-danger';
                matchStatusText.textContent = 'Not Matching';
                matchStatusText.style.color = '#dc3545';
            }
        }

        // Attach Register Password Listeners
        const regPass = document.getElementById('regPassInput');
        const regConfirmPass = document.getElementById('regConfirmPassInput');
        const regEmail = document.getElementById('regEmailInput');
        if (regPass) {
            regPass.addEventListener('input', () => {
                evaluatePasswordStrength(
                    regPass.value, 
                    regEmail ? regEmail.value : '', 
                    document.getElementById('regStrengthBar'), 
                    document.getElementById('regStrengthText')
                );
                if (regConfirmPass) {
                    evaluatePasswordMatch(
                        regPass.value, 
                        regConfirmPass.value, 
                        document.getElementById('regMatchBar'), 
                        document.getElementById('regMatchStatusText')
                    );
                }
            });
        }
        if (regConfirmPass) {
            regConfirmPass.addEventListener('input', () => {
                evaluatePasswordMatch(
                    regPass ? regPass.value : '', 
                    regConfirmPass.value, 
                    document.getElementById('regMatchBar'), 
                    document.getElementById('regMatchStatusText')
                );
            });
        }

        // Attach Forgot Password Listeners
        const forgotPass = document.getElementById('forgotPassInput');
        const forgotConfirmPass = document.getElementById('forgotConfirmPassInput');
        if (forgotPass) {
            forgotPass.addEventListener('input', () => {
                evaluatePasswordStrength(
                    forgotPass.value, 
                    '', 
                    document.getElementById('forgotStrengthBar'), 
                    document.getElementById('forgotStrengthText')
                );
                if (forgotConfirmPass) {
                    evaluatePasswordMatch(
                        forgotPass.value, 
                        forgotConfirmPass.value, 
                        document.getElementById('forgotMatchBar'), 
                        document.getElementById('forgotMatchStatusText')
                    );
                }
            });
        }
        if (forgotConfirmPass) {
            forgotConfirmPass.addEventListener('input', () => {
                evaluatePasswordMatch(
                    forgotPass ? forgotPass.value : '', 
                    forgotConfirmPass.value, 
                    document.getElementById('forgotMatchBar'), 
                    document.getElementById('forgotMatchStatusText')
                );
            });
        }
    });

    window.hideModalThen = function (modalEl, callback) {
        if (!modalEl) { callback && callback(); return; }
        const instance = bootstrap.Modal.getInstance(modalEl);
        if (!instance) { callback && callback(); return; }

        let called = false;
        const done = () => {
            if (called) return;
            called = true;
            modalEl.removeEventListener('hidden.bs.modal', done);
            callback && callback();
        };
        modalEl.addEventListener('hidden.bs.modal', done);
        setTimeout(done, 350);

        instance.hide();
    };

    window.showModalById = function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        bootstrap.Modal.getOrCreateInstance(el).show();
    };

    window.lockRoleTo = function (roleValue) {
        const roleSelect = document.getElementById('roleSelect');
        if (!roleSelect) return;

        roleSelect.disabled = false;
        roleSelect.value = roleValue;

        const oldHidden = document.getElementById('hiddenRoleInput');
        if (oldHidden) oldHidden.remove();

        roleSelect.disabled = true;

        const hiddenRole = document.createElement('input');
        hiddenRole.type  = 'hidden';
        hiddenRole.id    = 'hiddenRoleInput';
        hiddenRole.name  = 'role';
        hiddenRole.value = roleValue;
        roleSelect.parentNode.appendChild(hiddenRole);

        roleSelect.dispatchEvent(new Event('change'));
    };

    window.unlockRole = function () {
        const roleSelect = document.getElementById('roleSelect');
        if (!roleSelect) return;

        const hiddenRole = document.getElementById('hiddenRoleInput');
        if (hiddenRole) hiddenRole.remove();

        roleSelect.disabled = false;
    };

    /* ---------- Student branch ---------- */

    window.chooseStudentBranch = function () {
        window.lockRoleTo('Student');
        window.hideModalThen(document.getElementById('modalQuestion1'), function () {
            window.showModalById('modalQuestionStudent');
        });
    };

    /* ---------- Department branch ---------- */

    window.chooseDepartmentBranch = function () {
        window.unlockRole();
        window.hideModalThen(document.getElementById('modalQuestion1'), function () {
            window.showModalById('modalQuestionDepartment');
        });
    };

    window.chooseRegisteredDepartmentBranch = function () {
        window.lockRoleTo('Department');
        window.hideModalThen(document.getElementById('modalQuestionDepartment'), function () {
            window.showModalById('modalQuestionDept');
        });
    };

    /* ---------- Faculty branch (NEW) ---------- */

    // "Are you a department?" → NO
    window.chooseFacultyBranch = function () {
        window.unlockRole();
        window.hideModalThen(document.getElementById('modalQuestionDepartment'), function () {
            window.showModalById('modalQuestionFaculty');
        });
    };

    // "Are you a faculty / professor?" → YES
    window.chooseRegisteredFacultyBranch = function () {
        window.lockRoleTo('Professor');
        window.hideModalThen(document.getElementById('modalQuestionFaculty'), function () {
            window.showModalById('modalQuestionFacultyReg');
        });
    };

    // "Are you a registered faculty?" → YES
    window.openLoginForFaculty = function () {
        window.hideModalThen(document.getElementById('modalQuestionFacultyReg'), function () {
            window.showModalById('loginModal');
        });
    };

    // "Are you a registered faculty?" → NO
    window.openRegisterForFaculty = function () {
        window.lockRoleTo('Professor');
        window.hideModalThen(document.getElementById('modalQuestionFacultyReg'), function () {
            window.showModalById('registerModal');
        });
    };

    /* ---------- Back to the first question ---------- */

    // Source modal id is optional. Defaults to the Department question
    // so existing callers keep working without modification.
    window.goBackToPortalQuestion = function (sourceModalId) {
        var srcId = sourceModalId || 'modalQuestionDepartment';
        var srcEl = document.getElementById(srcId);
        window.hideModalThen(srcEl, function () {
            window.showModalById('modalQuestion1');
        });
    };

    /* ---------- Finance branch ---------- */
window.chooseFinanceBranch = function () {
    window.unlockRole();
    window.hideModalThen(document.getElementById('modalQuestionFaculty'), function () {
        window.showModalById('modalQuestionFinance');
    });
};
window.chooseRegisteredFinanceBranch = function () {
    window.lockRoleTo('finance');
    window.hideModalThen(document.getElementById('modalQuestionFinance'), function () {
        window.showModalById('modalQuestionFinanceReg');
    });
};
window.openLoginForFinance = function () {
    window.hideModalThen(document.getElementById('modalQuestionFinanceReg'), function () {
        window.showModalById('loginModal');
    });
};
window.openRegisterForFinance = function () {
    window.lockRoleTo('finance');
    window.hideModalThen(document.getElementById('modalQuestionFinanceReg'), function () {
        window.showModalById('registerModal');
    });
};

    // Legacy helper kept for compatibility with older buttons.
    window.setPortalRole = function (type) {
        if (type === 'Student') {
            window.lockRoleTo('Student');
        } else {
            window.unlockRole();
        }
    };

    /* =========================================================================
       FACULTY / PROFESSOR BRANCH
       -------------------------------------------------------------------------
       Faculty members are neither Students nor Departments, so they get
       their own question. On YES → "Are you a registered faculty?".
       On that screen YES → Login, NO → Register with role = Professor.
       ========================================================================= */

    // "Are you a department?" → NO → open the Faculty question
    window.chooseFacultyBranch = function () {
        window.unlockRole();
        window.hideModalThen(document.getElementById('modalQuestionDepartment'), function () {
            window.showModalById('modalQuestionFaculty');
        });
    };

    // "Are you a faculty / professor?" → YES
    window.chooseRegisteredFacultyBranch = function () {
        window.lockRoleTo('Professor');
        window.hideModalThen(document.getElementById('modalQuestionFaculty'), function () {
            window.showModalById('modalQuestionFacultyReg');
        });
    };

    // "Are you a registered faculty?" → YES → open Login
    window.openLoginForFaculty = function () {
        window.hideModalThen(document.getElementById('modalQuestionFacultyReg'), function () {
            window.showModalById('loginModal');
        });
    };

    // "Are you a registered faculty?" → NO → open Register (role = Professor)
    window.openRegisterForFaculty = function () {
        // Ensure the role dropdown is locked to Professor before opening.
        window.lockRoleTo('Professor');
        window.hideModalThen(document.getElementById('modalQuestionFacultyReg'), function () {
            window.showModalById('registerModal');
        });
    };
    </script>
</body>
</html>