/* =============================================================================
   StudentFLOW — Complete Database Schema
   -----------------------------------------------------------------------------
   This is the ONE definitive database file for the entire StudentFLOW system.
   It contains every table, column, index, and foreign key required by the
   application, including the complete Finance workflow.

   Safe to run on a fresh MySQL/MariaDB instance. Do NOT run any other
   migration or setup files afterwards.
   ============================================================================= */

CREATE DATABASE IF NOT EXISTS student_portal_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE student_portal_db;

SET FOREIGN_KEY_CHECKS = 0;

/* =============================================================================
   1. USERS
   Core table for authentication and profile information. Includes the
   `finance` role and all fields used by registration, login, and OTP.
   ============================================================================= */
CREATE TABLE IF NOT EXISTS users (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username           VARCHAR(50)  NOT NULL UNIQUE,
    email              VARCHAR(150) NOT NULL UNIQUE,
    password_hash      VARCHAR(255) NOT NULL,
    role               ENUM('Admin','Department','Professor','Faculty','Student','finance')
                       NOT NULL DEFAULT 'Student',
    student_id         VARCHAR(50)  DEFAULT NULL,
    first_name         VARCHAR(100) DEFAULT NULL,
    last_name          VARCHAR(100) DEFAULT NULL,
    phone              VARCHAR(30)  DEFAULT NULL,
    department         VARCHAR(100) DEFAULT NULL,
    course             VARCHAR(100) DEFAULT NULL,
    department_course  VARCHAR(150) DEFAULT NULL,
    otp_code           VARCHAR(20)  DEFAULT NULL,
    otp_expires        DATETIME     DEFAULT NULL,
    is_verified        TINYINT(1)   NOT NULL DEFAULT 0,
    failed_attempts    INT          NOT NULL DEFAULT 0,
    lockout_until      DATETIME     DEFAULT NULL,
    created_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_student_id (student_id),
    INDEX idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   2. STUDENT MASTER LIST
   Admin-controlled allow-list for student registration verification.
   ============================================================================= */
CREATE TABLE IF NOT EXISTS student_masterlist (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    student_id   VARCHAR(50)  NOT NULL UNIQUE,
    first_name   VARCHAR(100) NOT NULL,
    last_name    VARCHAR(100) NOT NULL,
    department   VARCHAR(100) DEFAULT NULL,
    course       VARCHAR(100) DEFAULT NULL,
    status       ENUM('pending', 'active') DEFAULT 'pending',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   3. ACADEMIC RECORDS
   Grades encoded by faculty and released to students by admin.
   Includes release tracking and soft-clear flags.
   ============================================================================= */
CREATE TABLE IF NOT EXISTS academic_records (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    faculty_id          INT UNSIGNED NOT NULL,
    student_id          INT UNSIGNED NOT NULL,
    subject_code        VARCHAR(50)  NOT NULL,
    subject_title       VARCHAR(150) NOT NULL,
    grade               DECIMAL(5,2) DEFAULT NULL,
    semester            VARCHAR(50)  NOT NULL,
    academic_year       VARCHAR(50)  NOT NULL,
    is_released         TINYINT(1) NOT NULL DEFAULT 0,
    cleared_by_faculty   TINYINT(1) NOT NULL DEFAULT 0,
    cleared_by_student   TINYINT(1) NOT NULL DEFAULT 0,
    released_by_admin_id INT UNSIGNED DEFAULT NULL,
    released_at          DATETIME     DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_academic_student (student_id),
    INDEX idx_academic_faculty (faculty_id),
    INDEX idx_academic_subject (subject_code),
    CONSTRAINT fk_academic_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_academic_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_academic_released_by FOREIGN KEY (released_by_admin_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_student_subject_term (student_id, subject_code, semester, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   4. ANNOUNCEMENTS
   Global announcements posted by administrators.
   ============================================================================= */
CREATE TABLE IF NOT EXISTS announcements (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    content     TEXT NOT NULL,
    posted_by   VARCHAR(150) DEFAULT NULL,
    category    VARCHAR(60)  DEFAULT 'General',
    priority    VARCHAR(20)  DEFAULT 'Normal',
    is_pinned   TINYINT(1)   DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_announcements_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   5. SUPPORT / CHAT MESSAGES
   Student <-> Admin chat threads.
   ============================================================================= */
CREATE TABLE IF NOT EXISTS support_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    sender_role ENUM('student', 'admin') NOT NULL DEFAULT 'student',
    message     TEXT NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_support_user (user_id),
    INDEX idx_support_read (is_read),
    CONSTRAINT fk_support_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   6. FACULTY SUBMISSIONS
   Tracks the routing of grades from Faculty to Admin for review.
   ============================================================================= */
CREATE TABLE IF NOT EXISTS faculty_submissions (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    faculty_id         INT UNSIGNED NOT NULL,
    student_id         INT UNSIGNED NOT NULL,
    admin_id           INT UNSIGNED DEFAULT NULL,
    admin_target_id    VARCHAR(50) NOT NULL,
    submission_status  VARCHAR(20) NOT NULL DEFAULT 'submitted',
    cleared_by_admin   TINYINT(1)  NOT NULL DEFAULT 0,
    cleared_by_faculty TINYINT(1)  NOT NULL DEFAULT 0,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_submission_faculty (faculty_id),
    INDEX idx_submission_student (student_id),
    INDEX idx_submission_admin (admin_target_id),
    INDEX idx_submission_status (cleared_by_admin),
    CONSTRAINT fk_submission_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_submission_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_submission_admin   FOREIGN KEY (admin_id)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   7. FINANCIAL LEDGERS
   The complete, final structure for all financial transactions.
   Includes the entire workflow (draft/submitted/approved/returned/voided)
   and all related tracking fields.
   ============================================================================= */
CREATE TABLE IF NOT EXISTS financial_ledgers (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id        INT UNSIGNED NOT NULL,
    finance_id        INT UNSIGNED DEFAULT NULL,
    description       VARCHAR(255) NOT NULL,
    fee_category      VARCHAR(60)  DEFAULT NULL,
    reference_no      VARCHAR(50)  DEFAULT NULL,
    amount            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    transaction_type  ENUM('Charge', 'Payment') NOT NULL DEFAULT 'Charge',
    payment_method    VARCHAR(30)  DEFAULT NULL,
    semester          VARCHAR(50)  DEFAULT NULL,
    school_year       VARCHAR(20)  DEFAULT NULL,
    transaction_date  DATE NOT NULL,
    remarks           TEXT DEFAULT NULL,
    status            ENUM('draft','submitted','approved','returned','voided') NOT NULL DEFAULT 'approved',
    admin_id          INT UNSIGNED DEFAULT NULL,
    submitted_at      DATETIME DEFAULT NULL,
    reviewed_at       DATETIME DEFAULT NULL,
    reviewed_by       INT UNSIGNED DEFAULT NULL,
    admin_remarks     TEXT DEFAULT NULL,
    created_by        INT UNSIGNED DEFAULT NULL,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    voided            TINYINT(1) NOT NULL DEFAULT 0,
    void_reason       VARCHAR(255) DEFAULT NULL,
    voided_by         INT UNSIGNED DEFAULT NULL,
    voided_at         DATETIME DEFAULT NULL,
    INDEX idx_ledger_student (student_id),
    INDEX idx_ledger_finance (finance_id),
    INDEX idx_ledger_status (status),
    INDEX idx_ledger_admin (admin_id),
    INDEX idx_ledger_ref (reference_no),
    INDEX idx_ledger_date (transaction_date),
    INDEX idx_ledger_voided (voided),
    CONSTRAINT fk_ledger_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ledger_finance FOREIGN KEY (finance_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_admin   FOREIGN KEY (admin_id)   REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_voided_by FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   8. NOTIFICATIONS
   Private notifications for all user roles.
   ============================================================================= */
CREATE TABLE IF NOT EXISTS notifications (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_user_id  INT UNSIGNED NOT NULL,
    sender_user_id     INT UNSIGNED DEFAULT NULL,
    notification_type  VARCHAR(50) NOT NULL,
    title              VARCHAR(255) NOT NULL,
    message            TEXT NOT NULL,
    reference_type     VARCHAR(50) DEFAULT NULL,
    reference_id       INT UNSIGNED DEFAULT NULL,
    target_code        VARCHAR(50) DEFAULT NULL,
    is_read            TINYINT(1) NOT NULL DEFAULT 0,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_recipient (recipient_user_id),
    INDEX idx_notif_read (is_read),
    CONSTRAINT fk_notif_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_sender   FOREIGN KEY (sender_user_id)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =============================================================================
   9. AUDIT LOGS
   Tracks important actions for security and auditing purposes.
   ============================================================================= */
CREATE TABLE IF NOT EXISTS audit_logs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED DEFAULT NULL,
    action       VARCHAR(100) NOT NULL,
    details      TEXT DEFAULT NULL,
    target_type  VARCHAR(50) DEFAULT NULL,
    target_id    INT UNSIGNED DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

/* =============================================================================
   SEED DATA
   All demo accounts use the password: Grc@Portal123
   ============================================================================= */

-- Admin Account
INSERT INTO users (username, email, password_hash, role, student_id, first_name, last_name, department, department_course, is_verified)
SELECT 'admin1', 'admin@grcportal.com', '$2b$12$3ACuFcXq46HSnVEDFT1HnudbZQ/ckMkFYPGbWWWGrlRCb8PhfSAkm', 'Admin', 'ADM-01-01111', 'System', 'Administrator', 'Administration', 'Administration', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin1');

-- Faculty/Professor Account
INSERT INTO users (username, email, password_hash, role, student_id, first_name, last_name, phone, department, department_course, is_verified)
SELECT 'prof_smith', 'smith@grcportal.com', '$2b$12$3ACuFcXq46HSnVEDFT1HnudbZQ/ckMkFYPGbWWWGrlRCb8PhfSAkm', 'Professor', 'FAC-001', 'John', 'Smith', '555-0192', 'College of Computer Studies', 'College of Computer Studies', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'prof_smith');

-- Student Account
INSERT INTO users (username, email, password_hash, role, student_id, first_name, last_name, phone, department, course, department_course, is_verified)
SELECT 'student_jane', 'jane@grcportal.com', '$2b$12$3ACuFcXq46HSnVEDFT1HnudbZQ/ckMkFYPGbWWWGrlRCb8PhfSAkm', 'Student', '2024-01-00002', 'Jane', 'Doe', '555-0143', 'College of Computer Studies', 'BSIT', 'College of Computer Studies - BSIT', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'student_jane');

-- Finance Account
INSERT INTO users (username, email, password_hash, role, student_id, first_name, last_name, phone, department, department_course, is_verified)
SELECT 'finance01', 'finance@grcportal.com', '$2b$12$3ACuFcXq46HSnVEDFT1HnudbZQ/ckMkFYPGbWWWGrlRCb8PhfSAkm', 'finance', 'FIN-001', 'Maria', 'Santos', '555-0100', 'Finance Office', 'Finance Office', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'finance01');

-- Master List Entries
INSERT INTO student_masterlist (student_id, first_name, last_name, department, course, status)
SELECT '2024-01-00002', 'Jane', 'Doe', 'College of Computer Studies', 'BSIT', 'active'
WHERE NOT EXISTS (SELECT 1 FROM student_masterlist WHERE student_id = '2024-01-00002');

INSERT INTO student_masterlist (student_id, first_name, last_name, department, course, status)
SELECT '2024-01-00001', 'Juan', 'Dela Cruz', 'College of Computer Studies', 'BSIT', 'pending'
WHERE NOT EXISTS (SELECT 1 FROM student_masterlist WHERE student_id = '2024-01-00001');

-- Sample Announcement
INSERT INTO announcements (title, content, posted_by, category, priority, is_pinned)
SELECT 'Welcome to StudentFLOW', 'Welcome to the Global Reciprocal Colleges Student and Faculty Portal.', 'System Administrator', 'General', 'Normal', 0
WHERE NOT EXISTS (SELECT 1 FROM announcements WHERE title = 'Welcome to StudentFLOW');

-- Sample Academic Records for Jane
INSERT INTO academic_records (faculty_id, student_id, subject_code, subject_title, grade, semester, academic_year, is_released, released_by_admin_id, released_at)
SELECT f.id, s.id, 'CS101', 'Introduction to Computer Science', 1.50, '1st Semester', '2026-2027', 1, a.id, NOW()
FROM users f, users s, users a
WHERE f.username = 'prof_smith' AND s.username = 'student_jane' AND a.username = 'admin1'
  AND NOT EXISTS (SELECT 1 FROM academic_records ar WHERE ar.student_id = s.id AND ar.subject_code = 'CS101');

INSERT INTO academic_records (faculty_id, student_id, subject_code, subject_title, grade, semester, academic_year, is_released, released_by_admin_id, released_at)
SELECT f.id, s.id, 'DB201', 'Database Management Systems', 1.75, '1st Semester', '2026-2027', 1, a.id, NOW()
FROM users f, users s, users a
WHERE f.username = 'prof_smith' AND s.username = 'student_jane' AND a.username = 'admin1'
  AND NOT EXISTS (SELECT 1 FROM academic_records ar WHERE ar.student_id = s.id AND ar.subject_code = 'DB201');