/* Drop procedures */
drop procedure if exists getUniqueUserCount;
drop procedure if exists getUniqueUserCountPerSP;
drop procedure if exists getUniqueUserCountPerIdP;

/* Always drop */
DROP TABLE IF EXISTS log_analyze__meta;
DROP TABLE IF EXISTS log_analyze_semaphore;
DROP TABLE IF EXISTS log_analyze_chunk;

/* Drop on the fly created tables: log_analyze_days__% and log_analyze_periods_% */

-- Increase memory to avoid truncating string, adjust according to your needs
SET group_concat_max_len = 1024 * 1024 * 10;
-- Generate drop command and assign to variable
SET @dropcmd = (SELECT CONCAT('DROP TABLE IF EXISTS ',GROUP_CONCAT(CONCAT(table_schema,'.',table_name)),';') 
	FROM information_schema.tables WHERE table_schema='stats' AND table_name LIKE 'log_analyze_days__%');
-- Drop tables
PREPARE stmt FROM @dropcmd;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Generate drop command and assign to variable
SET @dropcmd = (SELECT CONCAT('DROP TABLE IF EXISTS ',GROUP_CONCAT(CONCAT(table_schema,'.',table_name)),';') 
	FROM information_schema.tables WHERE table_schema='stats' AND table_name LIKE 'log_analyze_periods__%');
-- Drop tables
PREPARE stmt FROM @dropcmd;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS log_analyze_periodstats;
DROP TABLE IF EXISTS log_analyze_stats;
DROP TABLE IF EXISTS log_analyze_provider;
DROP TABLE IF EXISTS log_analyze_idp;
DROP TABLE IF EXISTS log_analyze_sp;
DROP TABLE IF EXISTS log_analyze_day;
DROP TABLE IF EXISTS log_analyze_period;

