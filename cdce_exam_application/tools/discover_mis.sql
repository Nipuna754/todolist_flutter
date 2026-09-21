-- Read-only discovery of the CDCE MIS schema.
--
-- Run it to find the real table and column names for config/config.php:
--
--   mysql -h 10.40.129.2 -u <user> -p -D cdcesys --table < tools/discover_mis.sql
--
-- Every statement is a SELECT or a SHOW. Nothing is created, altered or
-- deleted, and no student row is returned - only schema and counts, so the
-- output is safe to share.
--
-- If the database is not called cdcesys, change @db below.

SET @db := DATABASE();

SELECT '=== 1. Databases on this server ===' AS ``;
SHOW DATABASES;

SELECT '=== 2. Biggest tables (the student table is usually near the top) ===' AS ``;
SELECT table_name, table_rows, ROUND(((data_length + index_length) / 1024 / 1024), 1) AS mb
FROM information_schema.tables
WHERE table_schema = @db AND table_type = 'BASE TABLE'
ORDER BY table_rows DESC
LIMIT 15;

SELECT '=== 3. Columns that look like a NIC ===' AS ``;
SELECT table_name, column_name, column_type
FROM information_schema.columns
WHERE table_schema = @db
  AND (column_name LIKE '%nic%' OR column_name LIKE '%id_no%'
       OR column_name LIKE '%identity%' OR column_name LIKE '%national%')
ORDER BY table_name, column_name;

SELECT '=== 4. Columns that look like a registration number ===' AS ``;
SELECT table_name, column_name, column_type
FROM information_schema.columns
WHERE table_schema = @db
  AND (column_name LIKE '%reg%no%' OR column_name LIKE '%reg_num%'
       OR column_name LIKE '%regno%' OR column_name LIKE '%index%')
ORDER BY table_name, column_name;

SELECT '=== 5. Columns that look like a name ===' AS ``;
SELECT table_name, column_name, column_type
FROM information_schema.columns
WHERE table_schema = @db
  AND (column_name LIKE '%name%' OR column_name LIKE '%initial%')
ORDER BY table_name, column_name;

SELECT '=== 6. Columns that could drive the eligibility rule ===' AS ``;
SELECT table_name, column_name, column_type
FROM information_schema.columns
WHERE table_schema = @db
  AND (column_name LIKE '%level%' OR column_name LIKE '%course%'
       OR column_name LIKE '%crs%' OR column_name LIKE '%year%'
       OR column_name LIKE '%status%' OR column_name LIKE '%batch%'
       OR column_name LIKE '%prog%' OR column_name LIKE '%repeat%')
ORDER BY table_name, column_name;
