<?php

# Open database connection
function openMysqlDb($db) {
    global $LA;

	$mysql_link = mysql_connect($LA[$db]['mysql_host'], $LA[$db]['mysql_user'], $LA[$db]['mysql_pass'],true);

	if ($mysql_link===false) {
		echoProgramException('mysql.php',10,1,"Couldn't connecto to mysql server {$LA[$db]['mysql_host']}\n");
		exit(1);
	}

	mysql_select_db($LA[$db]['mysql_db'], $mysql_link);
	mysql_query("SET NAMES 'utf8';", $mysql_link)
		or catchMysqlError("Failed to set charset", $mysql_link);
	mysql_query("SET time_zone = '{$LA['timezone']}';", $mysql_link) 
		or catchMysqlError("Failed to set time zone", $mysql_link);
	mysql_query("SET storage_engine=InnoDB;", $mysql_link)
		or catchMysqlError("Failed to set storage engine", $mysql_link);

	#log2file("Opened {$LA[$db]['mysql_user']}@{$LA[$db]['mysql_host']}/{$LA[$db]['mysql_db']}: ".print_r($mysql_link,1));
	
	return $mysql_link;
}

# close database
function closeMysqlDb(&$mysql_link) {
	mysql_close($mysql_link);
	$mysql_link = null;
}

# other mysql functions
function safeInsert($value){
	return mysql_real_escape_string($value);
} 

function safeListInsert($list) {
	foreach ($list as $key => $val) {
		$list[$key] = mysql_real_escape_string($val);
	}
	return $list;
}

function checkDateTime($date) {
    if (date('Y-m-d H:i:s', strtotime($date)) == $date) {
        return true;
    } 
    else {
        return false;
    }
}

function checkDateMine($date) {
    if (date('Y-m-d', strtotime($date)) == $date) {
        return true;
    } 
    else {
        return false;
    }
}

function checkStatsDBVersion()
{
	global $LA;

	$VERSION = $LA['DB_VERSION'];
	$dbh     = $LA['mysql_link_stats'];

	$q = 'SELECT MAX(meta_version) FROM log_analyze__meta';
	$result = mysql_query($q,$dbh);
	if (!$result) {
		throw new Exception("Query for database version failed (maybe you need to update the database?)");
	}
	$version = mysql_fetch_array($result);
	$version = $version[0];
	if ($version==$VERSION) return true;

	throw new Exception("Database version mismatch: got $version, expected $VERSION (maybe you need to update the database?)");

}

?>
