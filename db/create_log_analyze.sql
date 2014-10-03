SET storage_engine=InnoDB;

# CHUNK

CREATE TABLE log_analyze_chunk (
	chunk_id INT NOT NULL AUTO_INCREMENT,
	chunk_from TIMESTAMP NULL DEFAULT NULL,
	chunk_to TIMESTAMP NULL DEFAULT NULL,
	chunk_status VARCHAR(128) NOT NULL DEFAULT 'new',
	chunk_created TIMESTAMP NULL DEFAULT NULL,
	chunk_updated TIMESTAMP DEFAULT NOW() ON UPDATE NOW(),
	chunk_in INT DEFAULT NULL,
	chunk_out INT DEFAULT NULL,
	PRIMARY KEY (chunk_id),
	INDEX from_index (chunk_from),
	INDEX to_index (chunk_to),
	INDEX status_index (chunk_status)
) CHARACTER SET 'utf8';
/* trigger to automatically update chunk_created (necessary for MySQL<5.6) */
DELIMITER ;;
CREATE trigger log_analyze_chunk__trg_create
BEFORE INSERT ON log_analyze_chunk
FOR EACH ROW BEGIN
	IF ISNULL(NEW.chunk_created)
		THEN SET NEW.chunk_created = NOW();
	END IF;
END;;
DELIMITER ;

# STATS

CREATE TABLE log_analyze_day (
	day_id INT NOT NULL AUTO_INCREMENT,
	day_day DATE DEFAULT NULL,
	day_environment CHAR(2) NOT NULL,
	day_logins INT DEFAULT 0,
	day_users INT DEFAULT 0,
	day_created TIMESTAMP NULL DEFAULT NULL,
	day_updated TIMESTAMP DEFAULT NOW() ON UPDATE NOW(),
	PRIMARY KEY (day_id),
	UNIQUE  KEY day_index (day_day,day_environment)
) CHARACTER SET 'utf8';
/* trigger to automatically update day_created (necessary for MySQL<5.6) */
DELIMITER ;;
CREATE trigger log_analyze_day__trg_create
BEFORE INSERT ON log_analyze_day
FOR EACH ROW BEGIN
	IF ISNULL(NEW.day_created)
		THEN SET NEW.day_created = NOW();
	END IF;
END;;
DELIMITER ;

CREATE TABLE log_analyze_sp (
	sp_id          INT NOT NULL AUTO_INCREMENT,
	sp_name        VARCHAR(4096) DEFAULT NULL,
	sp_entityid    VARCHAR(4096) DEFAULT NULL,
	sp_environment CHAR(2) DEFAULT NULL,
	sp_meta        CHAR(64) DEFAULT NULL,
	sp_datefrom    DATETIME NULL,
	sp_dateto      DATETIME NULL,
	PRIMARY KEY (sp_id),
	UNIQUE  KEY entity_index (sp_entityid(128),sp_environment,sp_meta)
) CHARACTER SET 'utf8';
/* trigger to support 'null' values in dates */
DELIMITER ;;
CREATE trigger log_analyze_sp__trg_create
BEFORE INSERT ON log_analyze_sp
FOR EACH ROW BEGIN
	IF NEW.sp_datefrom='00-00-00'
		THEN SET NEW.sp_datefrom = NULL;
	END IF;
	IF NEW.sp_dateto='00-00-00'
		THEN SET NEW.sp_dateto = NULL;
	END IF;
END;;
DELIMITER ;

CREATE TABLE log_analyze_idp (
	idp_id          INT NOT NULL AUTO_INCREMENT,
	idp_name        VARCHAR(4096) DEFAULT NULL,
	idp_entityid    VARCHAR(4096) DEFAULT NULL,
	idp_environment CHAR(2) DEFAULT NULL,
	idp_meta        CHAR(64) DEFAULT NULL,
	idp_datefrom    DATETIME NULL,
	idp_dateto      DATETIME NULL,
	PRIMARY KEY (idp_id),
	UNIQUE  KEY entity_index (idp_entityid(128),idp_environment,idp_meta)
) CHARACTER SET 'utf8';
/* trigger to support 'null' values in dates */
DELIMITER ;;
CREATE trigger log_analyze_idp__trg_create
BEFORE INSERT ON log_analyze_idp
FOR EACH ROW BEGIN
	IF NEW.idp_datefrom='00-00-00'
		THEN SET NEW.idp_datefrom = NULL;
	END IF;
	IF NEW.idp_dateto='00-00-00'
		THEN SET NEW.idp_dateto = NULL;
	END IF;
END;;
DELIMITER ;


CREATE TABLE log_analyze_provider (
	provider_id INT NOT NULL AUTO_INCREMENT,
	provider_sp_id INT NOT NULL,
	provider_idp_id INT NOT NULL,
	PRIMARY KEY (provider_id),
	FOREIGN KEY (provider_sp_id) REFERENCES log_analyze_sp (sp_id) ON DELETE CASCADE,
	FOREIGN KEY (provider_idp_id) REFERENCES log_analyze_idp (idp_id) ON DELETE CASCADE
) CHARACTER SET 'utf8';

/* 
* do not use an auto_increment id on the stats and users table
* use a clustered index for better performance
*/
CREATE TABLE log_analyze_stats (
	stats_day_id INT NOT NULL,
	stats_provider_id INT NOT NULL,
	stats_logins INT DEFAULT NULL,
	stats_users INT DEFAULT NULL,
	PRIMARY KEY (stats_day_id,stats_provider_id),
	FOREIGN KEY (stats_day_id     ) REFERENCES log_analyze_day (day_id) ON DELETE CASCADE,
	FOREIGN KEY (stats_provider_id) REFERENCES log_analyze_provider (provider_id) ON DELETE CASCADE
) CHARACTER SET 'utf8';

CREATE TABLE log_analyze_semaphore (
	semaphore_id INT NOT NULL,
	semaphore_name VARCHAR(128) NOT NULL,
	semaphore_value INT NOT NULL,
	PRIMARY KEY (semaphore_id),
	KEY (semaphore_name),
	KEY (semaphore_value)
) CHARACTER SET 'utf8';

INSERT INTO log_analyze_semaphore VALUES(1,'provider',1);
INSERT INTO log_analyze_semaphore VALUES(2,'unknownSP',1);
INSERT INTO log_analyze_semaphore VALUES(3,'unknownIDP',1);
INSERT INTO log_analyze_semaphore VALUES(4,'user',1);
INSERT INTO log_analyze_semaphore VALUES(5,'day',1);

/* aggregation tables */
CREATE TABLE `log_analyze_period` (
	`period_id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
	`period_type`        char(1) NOT NULL,
	`period_period`      int(2) unsigned NOT NULL,
	`period_year`        int(4) unsigned NOT NULL,
	`period_environment` char(2) NOT NULL,
	`period_from`        timestamp NULL,
	`period_to`          timestamp NULL,
	`period_logins`      int(10) unsigned DEFAULT NULL,
	`period_users`       int(10) unsigned DEFAULT NULL,
	`period_created`     timestamp NULL DEFAULT NULL,
	`period_updated`     timestamp DEFAULT NOW() ON UPDATE NOW(),
	PRIMARY KEY (`period_id`),
	UNIQUE KEY (`period_period`,`period_year`,`period_environment`,`period_type`),
	KEY (`period_period`,`period_year`),
	KEY (`period_type`),
	KEY (`period_environment`)
) CHARACTER SET 'utf8';
/* trigger to automatically update period_created (necessary for MySQL<5.6) */
DELIMITER ;;
CREATE trigger log_analyze_period__trg_create
BEFORE INSERT ON log_analyze_period
FOR EACH ROW BEGIN
	IF ISNULL(NEW.period_created)
		THEN SET NEW.period_created = NOW();
	END IF;
END;;
DELIMITER ;

CREATE TABLE log_analyze_periodstats (
	`periodstats_period_id` int(10) unsigned NOT NULL,
	`periodstats_idp_id`    int(5) NULL DEFAULT NULL,
	`periodstats_sp_id`     int(5) NULL DEFAULT NULL,
	`periodstats_logins`    int(7) unsigned DEFAULT NULL,
	`periodstats_users`     int(5) unsigned DEFAULT NULL,
	`periodstats_created`   timestamp NULL DEFAULT NULL,
	`periodstats_updated`   timestamp DEFAULT NOW() ON UPDATE NOW(),
	UNIQUE  KEY (`periodstats_period_id`,`periodstats_idp_id`,`periodstats_sp_id`),
	FOREIGN KEY (`periodstats_period_id`) REFERENCES `log_analyze_period` (`period_id`) ON DELETE CASCADE,
	FOREIGN KEY (`periodstats_idp_id`)    REFERENCES `log_analyze_idp`    (`idp_id`)    ON DELETE CASCADE,
	FOREIGN KEY (`periodstats_sp_id`)     REFERENCES `log_analyze_sp`     (`sp_id`)     ON DELETE CASCADE
) CHARACTER SET 'utf8';
/* trigger to automatically update periodstats_created (necessary for MySQL<5.6) */
DELIMITER ;;
CREATE trigger log_analyze_periodstats__trg_create
BEFORE INSERT ON log_analyze_periodstats
FOR EACH ROW BEGIN
	IF ISNULL(NEW.periodstats_created)
		THEN SET NEW.periodstats_created = NOW();
	END IF;
END;;
DELIMITER ;


/* creating stored procedure to get unique user count over multiple days */

DELIMITER //
CREATE PROCEDURE getUniqueUserCount (IN fromDay DATE, IN toDay DATE, IN environment VARCHAR(8))
    BEGIN
		SET group_concat_max_len = 1024 * 1024 * 10;
		SET @a = (select group_concat('select * from log_analyze_days__' , day_id SEPARATOR ' UNION ') from log_analyze_day where (day_day BETWEEN fromDay AND toDay) AND (day_environment = environment) );
		SET @x := CONCAT('select count(distinct(user_name)) as user_count from ( ', @a, ' ) e');
		Prepare stmt FROM @x;
		Execute stmt;
		DEALLOCATE PREPARE stmt;
    END //
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE `getUniqueUserCountPerSP`(IN fromDay DATE, IN toDay DATE, IN environment VARCHAR(8), IN sp_id INT)
BEGIN
		SET group_concat_max_len = 1024 * 1024 * 1024;
		
		SET @fromDay = fromDay; 
		SET @toDay = toDay; 
		SET @environment = environment; 
		SET @sp_id = sp_id;
		SET @a = (select group_concat('(select user_name from stats.log_analyze_user__' , day_id, ' LEFT JOIN stats.log_analyze_provider p ON stats.log_analyze_user__' , day_id,'.user_provider_id= p.provider_id LEFT JOIN stats.log_analyze_sp sp ON p.provider_sp_id = sp.sp_id WHERE sp.sp_id = ' , @sp_id,')' SEPARATOR ' UNION ') from log_analyze_day where (day_day BETWEEN @fromDay AND @toDay) AND (day_environment = @environment) );
		SET @x := CONCAT('select count(distinct(user_name)) as user_count from ( ', @a, ' ) e');
		Prepare stmt FROM @x;
		Execute stmt;
END $$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE `getUniqueUserCountPerIdP`(IN fromDay DATE, IN toDay DATE, IN environment VARCHAR(8), IN idp_id INT)
BEGIN
		SET group_concat_max_len = 1024 * 1024 * 1024;
		SET @a = (select group_concat('(select user_name from stats.log_analyze_user__' , day_id, ' LEFT JOIN stats.log_analyze_provider p ON stats.log_analyze_user__' , day_id,'.user_provider_id= p.provider_id LEFT JOIN stats.log_analyze_idp idp ON p.provider_idp_id = idp.idp_id WHERE idp.idp_id = ' , sp_id,')' SEPARATOR ' UNION ') 
		from log_analyze_day where (day_day BETWEEN fromDay AND toDay) AND (day_environment = environment) );
		SET @x := CONCAT('select count(distinct(user_name)) as user_count from ( ', @a, ' ) e');
		Prepare stmt FROM @x;
		Execute stmt;
END $$
DELIMITER ;
