#!/usr/bin/env php
<?php

global $LA;
global $dbh;

date_default_timezone_set("UTC");

function query($q)
{
	global $dbh;

	$result = mysql_query($q,$dbh);
	if ($result===false)
	{
		catchMysqlError("Query failed:\n$q", $dbh);
		exit(1);
	}
	return $result;
}

############
### INIT ###
############

# define roots
# - script is in /bin, so one up for the real root
$user_root = getcwd();
$script_root = dirname(realpath(__FILE__));
$script_root .= "/../../scripts/";

# read config & libs
require $script_root."/etc/config.php";
require $script_root."/lib/libs.php";

# open log
openLogFile($script_root);

# open database
$dbh = openMysqlDb("DB_stats");
$LA['mysql_link_stats'] = $dbh;

query("SET time_zone='+0:00';",$dbh);
query("SET storage_engine=InnoDB;",$dbh);


############
### MAIN ###
############

#########################################################################
# check for database version
#########################################################################
# this should fail (no such table)
print "Checking version";
$result = mysql_query("select * FROM log_analyze__meta",$dbh);
$error = false;
if ($result!==false or ($error=mysql_errno())!=1146)
{
	print "Sorry, log_analyze__meta table shouldn't exist\n";
	if ($error)
	{
		print "Query failed with error $error (expected 1146)\n";
	}
	exit(1);
}
print "\n";

#########################################################################
# get list of all days
#########################################################################
print "Getting days";
$result = query("
	SELECT day_id FROM log_analyze_day;
");
$days = array();
while ($row = mysql_fetch_row($result))
{
	$days[]=$row[0];
	if (count($days)%10==0) print ".";
}
print "\n";


#########################################################################
# make day_id UNSIGNED
#########################################################################

print "Changing day_id to unsigned";
# remove foreign keys from log_analyze_stats and log_analyze_days__%
query("
	ALTER TABLE `log_analyze_stats`
		DROP FOREIGN KEY `log_analyze_stats_ibfk_1`;
");
foreach ($days as $day)
{
	query("
		ALTER TABLE `log_analyze_days__{$day}`
			DROP FOREIGN KEY `log_analyze_days__{$day}_ibfk_1`;
	");
}
print ".";

#change columns
query("
	ALTER TABLE `log_analyze_day`
		CHANGE COLUMN `day_id` `day_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT;
");
query("
	ALTER TABLE `log_analyze_stats`
		CHANGE COLUMN `stats_day_id` `stats_day_id` INT(11) UNSIGNED NOT NULL;
");
foreach ($days as $day)
{
	query("
		ALTER TABLE `log_analyze_days__{$day}`
			CHANGE COLUMN `user_day_id` `user_day_id` INT(11) UNSIGNED NOT NULL;
	");
}
print ".";

# reinstate foreign keys
query("
	ALTER TABLE `log_analyze_stats`
		ADD CONSTRAINT `log_analyze_stats_ibfk_1`
		  FOREIGN KEY (`stats_day_id`)
		  REFERENCES `log_analyze_day` (`day_id`)
		  ON DELETE CASCADE
");
foreach ($days as $day)
{
	query("
		ALTER TABLE `log_analyze_days__{$day}`
			ADD CONSTRAINT `log_analyze_days__{$day}_ibfk_1`
			  FOREIGN KEY (`user_day_id`)
			  REFERENCES `log_analyze_day` (`day_id`)
			  ON DELETE CASCADE
	");
}
print "\n";

#########################################################################
# change DATETIME to TIMESTAMP
#########################################################################
print "Changing DATETIMEs to TIMESTAMPs";
query("
	ALTER TABLE `log_analyze_sp`
		CHANGE COLUMN `sp_datefrom` `sp_datefrom` TIMESTAMP NULL DEFAULT NULL ,
		CHANGE COLUMN `sp_dateto`   `sp_dateto`   TIMESTAMP NULL DEFAULT NULL ;
");
query("
	ALTER TABLE `log_analyze_idp`
		CHANGE COLUMN `idp_datefrom` `idp_datefrom` TIMESTAMP NULL DEFAULT NULL ,
		CHANGE COLUMN `idp_dateto`   `idp_dateto`   TIMESTAMP NULL DEFAULT NULL ;
");
print "\n";

#########################################################################
# move all IdP and SP totals from periodstats to periodidp and periodsp
#########################################################################
print "Moving total per SP and IdP per period to separate tables";

# 1.first IdP:
# 1a. create table
query("
	CREATE TABLE log_analyze_periodidp (
		`periodidp_period_id` int(10) unsigned NOT NULL,
		`periodidp_idp_id`    int(5) NULL DEFAULT NULL,
		`periodidp_logins`    int(7) unsigned DEFAULT NULL,
		`periodidp_users`     int(5) unsigned DEFAULT NULL,
		`periodidp_created`   timestamp NULL DEFAULT NULL,
		`periodidp_updated`   timestamp DEFAULT NOW() ON UPDATE CURRENT_TIMESTAMP ,
		PRIMARY KEY (`periodidp_period_id`,`periodidp_idp_id`),
		FOREIGN KEY (`periodidp_period_id`) REFERENCES `log_analyze_period` (`period_id`) ON DELETE CASCADE,
		FOREIGN KEY (`periodidp_idp_id`)    REFERENCES `log_analyze_idp`    (`idp_id`)     ON DELETE CASCADE
	) CHARACTER SET 'utf8';
");
query("
	CREATE trigger log_analyze_periodidp__trg_create
	BEFORE INSERT ON log_analyze_periodidp
	FOR EACH ROW BEGIN
	IF ISNULL(NEW.periodidp_created)
	THEN SET NEW.periodidp_created = CURRENT_TIMESTAMP;
		END IF;
	END
");
print ".";

# 1b. copy data
query("
	INSERT INTO log_analyze_periodidp
	    (periodidp_period_id,  periodidp_idp_id,   periodidp_logins,   periodidp_users,   periodidp_created,   periodidp_updated)
	SELECT
		periodstats_period_id, periodstats_idp_id, periodstats_logins, periodstats_users, periodstats_created, periodstats_updated
	FROM stats.log_analyze_periodstats
	WHERE periodstats_sp_id IS NULL
");
print ".";

# 2. then SP
# 2a. create table
query("
	CREATE TABLE log_analyze_periodsp (
		`periodsp_period_id` int(10) unsigned NOT NULL,
		`periodsp_sp_id`     int(5) NULL DEFAULT NULL,
		`periodsp_logins`    int(7) unsigned DEFAULT NULL,
		`periodsp_users`     int(5) unsigned DEFAULT NULL,
		`periodsp_created`   timestamp NULL DEFAULT NULL,
		`periodsp_updated`   timestamp DEFAULT NOW() ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (`periodsp_period_id`,`periodsp_sp_id`),
		FOREIGN KEY (`periodsp_period_id`) REFERENCES `log_analyze_period` (`period_id`) ON DELETE CASCADE,
		FOREIGN KEY (`periodsp_sp_id`)     REFERENCES `log_analyze_sp`     (`sp_id`)     ON DELETE CASCADE
	) CHARACTER SET 'utf8';
");
query("
	CREATE trigger log_analyze_periodsp__trg_create
	BEFORE INSERT ON log_analyze_periodsp
	FOR EACH ROW BEGIN
	IF ISNULL(NEW.periodsp_created)
	THEN SET NEW.periodsp_created = CURRENT_TIMESTAMP;
		END IF;
	END;
");
print ".";

# 2b. copy data
query("
	INSERT INTO log_analyze_periodsp
		(periodsp_period_id,  periodsp_sp_id,   periodsp_logins,   periodsp_users,   periodsp_created,   periodsp_updated)
	SELECT
		periodstats_period_id, periodstats_sp_id, periodstats_logins, periodstats_users, periodstats_created, periodstats_updated
	FROM stats.log_analyze_periodstats
	WHERE periodstats_idp_id IS NULL
");
print ".";

# 3. remove data
query("
	DELETE FROM log_analyze_periodstats
	WHERE periodstats_idp_id IS NULL  OR  periodstats_sp_id IS NULL
");
print ".";

# 4. change periodstats IdP and SP columns to be NOT NULL
query("
	ALTER TABLE `log_analyze_periodstats`
		CHANGE COLUMN `periodstats_idp_id` `periodstats_idp_id` INT(5) NOT NULL ,
		CHANGE COLUMN `periodstats_sp_id` `periodstats_sp_id` INT(5) NOT NULL ;
");
print "\n";

#########################################################################
# calculate totals per day per IdP/SP
#########################################################################
print "Calculating totals per day per SP and IdP";
# 1. create tables
# 1a. IdP
query("
	CREATE TABLE log_analyze_dayidp (
		`dayidp_day_id`    int(10) unsigned NOT NULL,
		`dayidp_idp_id`    int(5) NULL DEFAULT NULL,
		`dayidp_logins`    int(7) unsigned DEFAULT NULL,
		`dayidp_users`     int(5) unsigned DEFAULT NULL,
		`dayidp_created`   timestamp NULL DEFAULT NULL,
		`dayidp_updated`   timestamp DEFAULT NOW() ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (`dayidp_day_id`,`dayidp_idp_id`),
		FOREIGN KEY (`dayidp_day_id`) REFERENCES `log_analyze_day` (`day_id`) ON DELETE CASCADE,
		FOREIGN KEY (`dayidp_idp_id`) REFERENCES `log_analyze_idp` (`idp_id`) ON DELETE CASCADE
) CHARACTER SET 'utf8';
");
query("
	CREATE trigger log_analyze_dayidp__trg_create
	BEFORE INSERT ON log_analyze_dayidp
	FOR EACH ROW BEGIN
		IF ISNULL(NEW.dayidp_created)
		THEN SET NEW.dayidp_created = CURRENT_TIMESTAMP;
		END IF;
	END;;
");

# 1b. SP
query("
	CREATE TABLE log_analyze_daysp (
		`daysp_day_id`    int(10) unsigned NOT NULL,
		`daysp_sp_id`     int(5) NULL DEFAULT NULL,
		`daysp_logins`    int(7) unsigned DEFAULT NULL,
		`daysp_users`     int(5) unsigned DEFAULT NULL,
		`daysp_created`   timestamp NULL DEFAULT NULL,
		`daysp_updated`   timestamp DEFAULT NOW() ON UPDATE CURRENT_TIMESTAMP ,
		PRIMARY KEY (`daysp_day_id`,`daysp_sp_id`),
		FOREIGN KEY (`daysp_day_id`) REFERENCES `log_analyze_day` (`day_id`) ON DELETE CASCADE,
		FOREIGN KEY (`daysp_sp_id`)  REFERENCES `log_analyze_sp`  (`sp_id`)  ON DELETE CASCADE
	) CHARACTER SET 'utf8';
");
query("
	CREATE trigger log_analyze_daysp__trg_create
	BEFORE INSERT ON log_analyze_daysp
	FOR EACH ROW BEGIN
		IF ISNULL(NEW.daysp_created)
		THEN SET NEW.daysp_created = CURRENT_TIMESTAMP;
		END IF;
	END;;
");

foreach ($days as $day)
{
	agCalcDayTotals($day);
	print ".";
}
print "\n";

#########################################################################
# install version metadata
#########################################################################
query("
	CREATE TABLE log_analyze__meta (
		meta_version INT NOT NULL,
		meta_created TIMESTAMP DEFAULT NOW(),
		PRIMARY KEY (meta_version)
	);
");
query("
	INSERT INTO log_analyze__meta (meta_version) VALUES (1);
");


query("COMMIT");