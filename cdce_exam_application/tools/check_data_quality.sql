-- Read-only health check of the MIS data the application is printed from.
--
-- Fill in the six names from tools/discover_mis.sql, then:
--
--   mysql -h 10.40.129.2 -u <user> -p -D cdcesys --table < tools/check_data_quality.sql
--
-- Returns counts only - no student row is ever selected - so the output is
-- safe to share. Nothing is written.

-- ---------------------------------------------------------------- EDIT THESE
SET @tbl        := 'tbl_student_master';
SET @reg_col    := 'reg_number';
SET @nic_col    := 'nic';
SET @init_col   := 'name_initials';
SET @full_col   := 'full_name';
-- The eligibility rule, exactly as it will appear in config.php:
SET @eligible   := "crs_code = 'BA' AND std_level = 100 AND acad_year = '2026' AND std_status = 'ACTIVE'";
-- ---------------------------------------------------------------------------

SET @nic_clean := CONCAT("UPPER(REPLACE(REPLACE(", @nic_col, ", ' ', ''), '-', ''))");

SELECT '=== 1. How many candidates does the eligibility rule select? ===' AS ``;
SET @s := CONCAT('SELECT COUNT(*) AS all_rows, SUM(', @eligible, ') AS eligible FROM ', @tbl);
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SELECT '=== 2. NIC formats among eligible candidates ===' AS ``;
SET @s := CONCAT(
  'SELECT CASE
     WHEN ', @nic_col, ' IS NULL OR TRIM(', @nic_col, ") = '' THEN 'MISSING'
     WHEN ", @nic_clean, " REGEXP '^[0-9]{9}[VX]$' THEN 'old 9+letter'
     WHEN ", @nic_clean, " REGEXP '^[0-9]{12}$' THEN 'new 12 digit'
     ELSE 'UNRECOGNISED'
   END AS nic_format,
   COUNT(*) AS candidates,
   SUM(", @nic_col, ' <> ', @nic_clean, ') AS needed_tidying
   FROM ', @tbl, ' WHERE ', @eligible, ' GROUP BY nic_format ORDER BY candidates DESC');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SELECT '=== 3. Eligible candidates who cannot be issued a form ===' AS ``;
SET @s := CONCAT(
  'SELECT
     SUM(', @reg_col,  ' IS NULL OR TRIM(', @reg_col,  ") = '') AS no_reg_no,
     SUM(", @nic_col,  ' IS NULL OR TRIM(', @nic_col,  ") = '') AS no_nic,
     SUM(", @init_col, ' IS NULL OR TRIM(', @init_col, ") = '') AS no_initials,
     SUM(", @full_col, ' IS NULL OR TRIM(', @full_col, ") = '') AS no_full_name
   FROM ", @tbl, ' WHERE ', @eligible);
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SELECT '=== 4. Duplicate NICs - each one blocks that student ===' AS ``;
SET @s := CONCAT(
  'SELECT COUNT(*) AS nics_with_more_than_one_eligible_candidate FROM (
     SELECT ', @nic_clean, ' AS n FROM ', @tbl, ' WHERE ', @eligible,
  ' AND ', @nic_col, " IS NOT NULL AND TRIM(", @nic_col, ") <> ''
     GROUP BY n HAVING COUNT(*) > 1) d");
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SELECT '=== 5. Would any 2000s NIC be matched by a 9-digit record? ===' AS ``;
-- Folding a 2000s number down to 9 digits yields a number that reads as a 1900
-- birth. Anything above zero here means a lookup that did that fold could
-- return the wrong student, and confirms the guard in src/Nic.php is needed.
SET @nic_a := CONCAT("UPPER(REPLACE(REPLACE(a.", @nic_col, ", ' ', ''), '-', ''))");
SET @nic_b := CONCAT("UPPER(REPLACE(REPLACE(b.", @nic_col, ", ' ', ''), '-', ''))");
SET @folded := CONCAT('CONCAT(SUBSTRING(', @nic_a, ',3,5), SUBSTRING(', @nic_a, ',9,4))');
SET @s := CONCAT(
  'SELECT COUNT(*) AS collisions FROM ', @tbl, ' a JOIN ', @tbl, ' b ON ',
  @nic_b, ' IN (', @folded, ', CONCAT(', @folded, ", 'V'), CONCAT(", @folded, ", 'X')) ",
  'WHERE ', @nic_a, " REGEXP '^20[0-9]{10}$'");
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SELECT '=== 6. Ready-made queries for the eligibility columns ===' AS ``;
-- Paste each line into the client to see what values that column really holds,
-- so the constants in config.php match the data rather than an assumption.
SET @s := CONCAT(
  "SELECT CONCAT('SELECT ', column_name, ', COUNT(*) FROM ', table_name,
                 ' GROUP BY ', column_name, ' ORDER BY 2 DESC LIMIT 10;') AS run_this
   FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = '", @tbl, "'
     AND (column_name LIKE '%level%' OR column_name LIKE '%course%'
          OR column_name LIKE '%crs%' OR column_name LIKE '%year%'
          OR column_name LIKE '%status%' OR column_name LIKE '%batch%')");
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
