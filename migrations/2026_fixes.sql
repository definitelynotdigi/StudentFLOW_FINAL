USE student_portal_db;

/* 1. faculty_submissions: add cleared_by_faculty */
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='faculty_submissions' AND COLUMN_NAME='cleared_by_faculty') = 0,
  'ALTER TABLE faculty_submissions ADD COLUMN cleared_by_faculty TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* 2. faculty_submissions: unique per (faculty, student, admin_target_id) */
DELETE fs_old FROM faculty_submissions fs_old
JOIN faculty_submissions fs_new
  ON fs_old.faculty_id = fs_new.faculty_id
 AND fs_old.student_id = fs_new.student_id
 AND fs_old.admin_target_id = fs_new.admin_target_id
 AND fs_old.id < fs_new.id;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='faculty_submissions'
       AND INDEX_NAME='uniq_submission') = 0,
  'ALTER TABLE faculty_submissions ADD UNIQUE KEY uniq_submission (faculty_id, student_id, admin_target_id)',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* 3. financial_ledgers: covering indexes */
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='financial_ledgers'
       AND INDEX_NAME='idx_ledger_queue') = 0,
  'ALTER TABLE financial_ledgers ADD INDEX idx_ledger_queue (voided, status, submitted_at)',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='financial_ledgers'
       AND INDEX_NAME='idx_ledger_student_status') = 0,
  'ALTER TABLE financial_ledgers ADD INDEX idx_ledger_student_status (student_id, voided, status)',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* 4. support_messages: covering index */
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='support_messages'
       AND INDEX_NAME='idx_support_user_created') = 0,
  'ALTER TABLE support_messages ADD INDEX idx_support_user_created (user_id, created_at)',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* 5. notifications: unique chat dedup (purge duplicates first) */
DELETE n_old FROM notifications n_old
JOIN notifications n_new
  ON n_old.recipient_user_id = n_new.recipient_user_id
 AND n_old.notification_type = n_new.notification_type
 AND n_old.reference_type    = n_new.reference_type
 AND n_old.reference_id      = n_new.reference_id
 AND n_old.id < n_new.id
WHERE n_old.notification_type = 'chat_message';

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='notifications'
       AND INDEX_NAME='uniq_chat_notif') = 0,
  'ALTER TABLE notifications ADD UNIQUE KEY uniq_chat_notif (recipient_user_id, notification_type, reference_type, reference_id)',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT 'Migration complete' AS status;