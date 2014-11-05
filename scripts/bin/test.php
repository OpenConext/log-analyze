#!/usr/bin/env php
<?php

#############
### INPUT ###
#############

# read args
parse_str(implode('&', array_slice($argv, 1)), $ARGS);

if (! array_key_exists("from", $ARGS) || ! array_key_exists("to", $ARGS)) {
	echo "USAGE $argv[0] from=\"YYYY-MM-DD\" to=\"YYYY-MM-DD\" \n";
	exit;
}

############
### INIT ###
############

# define roots
# - script is in /bin, so one up for the real root
$user_root = getcwd();
$script_root = dirname(realpath(__FILE__));
$script_root .= "/..";

# read config & libs
require $script_root."/etc/config.php";
require $script_root."/lib/libs.php";
global $LA;

# ARGS
$entry_from = $ARGS['from'];
$entry_to = $ARGS['to'];
if (! checkDateMine($entry_from) || ! checkDateMine($entry_to) ) {
	echo "Arguments are not valid DATE. Format: YYYY-MM-DD\n";
	print_r($ARGS);
	exit;	
}

# open log
openLogFile($script_root);

# open database
$LA['mysql_link_logins'] = openMysqlDb("DB_logins");
$LA['mysql_link_stats']  = openMysqlDb("DB_stats");

############
### MAIN ###
############

$numberOfSucces = 0;
$numberOfFailure = 0;

# get total number of entries (or NULL)
$count_in = getTotalNumberOfEntries($entry_from." 00:00:00", $entry_to." 23:59:59");
$count_out_days = getNumberOfEntriesInDay($entry_from, $entry_to);
$count_out_stats = getNumberOfEntriesInStats($entry_from, $entry_to);

# get number of entries per day
$day = getRandomEntry($count_in, $entry_from." 00:00:00", $entry_to." 23:59:59");
$count_in_day = getNumberOfEntriesFromLogins($day['timestamp']." 00:00:00", $day['timestamp']." 23:59:59");
$count_out_day = getNumberOfEntriesInDay($day['timestamp'], $day['timestamp']);

# get number of entries per provider
$provider = getRandomEntry($count_in, $entry_from." 00:00:00", $entry_to." 23:59:59");
$count_in_provider = getNumberOfEntriesPerProvider($provider['sp'], $provider['idp'], $entry_from." 00:00:00", $entry_to." 23:59:59", null);
$count_out_provider = getNumberOfEntriesInStatsPerProvider($provider['sp'], $provider['idp'], $provider['sp_name'], $provider['idp_name'], $entry_from, $entry_to, null);

# get number of entries per provider per day
$provider_day = getRandomEntry($count_in, $entry_from." 00:00:00", $entry_to." 23:59:59");
$count_in_provider_day = getNumberOfEntriesPerProvider($provider_day['sp'], $provider_day['idp'], $provider_day['timestamp']." 00:00:00", $provider_day['timestamp']." 23:59:59", null);
$count_out_provider_day = getNumberOfEntriesInStatsPerProvider($provider_day['sp'], $provider_day['idp'], $provider_day['sp_name'], $provider_day['idp_name'], $provider_day['timestamp'], $provider_day['timestamp'], null);

if (! $LA['disable_user_count']) {
	# get number of unique users per provider per day
	$user = getRandomEntry($count_in, $entry_from." 00:00:00", $entry_to." 23:59:59");
	$count_in_user = getNumberOfEntriesPerProvider($user['sp'], $user['idp'], $user['timestamp']." 00:00:00", $user['timestamp']." 23:59:59", "user");
	$count_out_user = getNumberOfEntriesInStatsPerProvider($user['sp'], $user['idp'], $user['sp_name'], $user['idp_name'], $user['timestamp'], $user['timestamp'], "user");
}

##############:
### OUTPUT ###
##############

# COUNT
echo "COUNT TEST\n";
echo "total in: ".$count_in."\n";
echo "total out (day table): ".$count_out_days."\n";
echo "total out (stats table): ".$count_out_stats."\n";
if ($count_in == $count_out_days && $count_in == $count_out_stats) {
	echo "COUNT TEST: succes\n";
	$numberOfSucces++;
}
else {
	echo "COUNT TEST: failure\n";
	$numberOfFailure++;
}

# DAY
echo "\nDAY TEST\n";
echo "day: ".$day['timestamp']."\n";
echo "total in: ".$count_in_day."\n";
echo "total out: ".$count_out_day."\n";
if ($count_in_day == $count_out_day) {
	echo "DAY TEST: succes\n";
	$numberOfSucces++;
}
else {
	echo "DAY TEST: failure\n";
	$numberOfFailure++;
}

# PROVIDER
echo "\nPROVIDER TEST\n";
echo "sp: ".$provider['sp']."\n";
echo "idp: ".$provider['idp']."\n";
echo "total in: ".$count_in_provider."\n";
echo "total out: ".$count_out_provider."\n";
if ($count_in_provider == $count_out_provider) {
	echo "PROVIDER TEST: succes\n";
	$numberOfSucces++;
}
else {
	echo "PROVIDER TEST: failure\n";
	$numberOfFailure++;
}

# PROVIDER && DAY
echo "\nPROVIDER PER DAY TEST\n";
echo "day: ".$provider_day['timestamp']."\n";
echo "sp: ".$provider_day['sp']."\n";
echo "idp: ".$provider_day['idp']."\n";
echo "total in: ".$count_in_provider_day."\n";
echo "total out: ".$count_out_provider_day."\n";
if ($count_in_provider_day == $count_out_provider_day) {
	echo "PROVIDER PER DAY TEST: succes\n";
	$numberOfSucces++;
}
else {
	echo "PROVIDER PER DAY TEST: failure\n";
	$numberOfFailure++;
}

if (! $LA['disable_user_count']) {
	# USER
	echo "\nUSER TEST\n";
	echo "day: ".$user['timestamp']."\n";
	echo "sp: ".$user['sp']."\n";
	echo "idp: ".$user['idp']."\n";
	echo "total in: ".$count_in_user."\n";
	echo "total out: ".$count_out_user."\n";
	if ($count_in_user == $count_out_user) {
		echo "USER TEST: succes\n";
		$numberOfSucces++;
	}
	else {
		echo "USER TEST: failure\n";
		$numberOfFailure++;
	}
}

# SUMMARY
echo "\nSUMMARY\n";
echo "number of tests: ".($numberOfSucces + $numberOfFailure)."\n";
echo "test succeses: ".$numberOfSucces."\n";
echo "test failures: ".$numberOfFailure."\n";
if ($numberOfFailure == 0) {
	echo "SUMMARY succes (100%)\n";
}
else {
	echo "SUMMARY: *** failure *** (".round(($numberOfSucces/($numberOfSucces + $numberOfFailure))*100)."% succes)\n";
}

#############
### CLOSE ###
#############

# close database
closeMysqlDb($LA['mysql_link_logins']);
closeMysqlDb($LA['mysql_link_stats']);

# close log
closeLogFile();

?>
