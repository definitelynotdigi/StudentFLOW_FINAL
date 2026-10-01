/* =============================================================================
   Faculty Clear Fix
   Adds `cleared_by_faculty` to faculty_submissions so the faculty's
   "Clear" button no longer touches the admin's queue flag.
   Non-destructive. Safe to re-run.
   ============================================================================= */

USE student_portal_db;

SET @db := DATABASE();

/* Add cleared_by_faculty column if missing */
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @db
       AND TABLE_NAME = 'faculty_submissions'
       AND COLUMN_NAME = 'cleared_by_faculty') = 0,
  'ALTER TABLE faculty_submissions ADD COLUMN cleared_by_faculty TINYINT(1) NOT NULL DEFAULT 0 AFTER cleared_by_admin',
  'SELECT 1'
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* Confirm */
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = @db
   AND TABLE_NAME   = 'faculty_submissions'
   AND COLUMN_NAME IN ('cleared_by_admin', 'cleared_by_faculty');