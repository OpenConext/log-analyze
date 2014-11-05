#!/usr/bin/php
<?php

############
### INIT ###
############

$total_time_start = microtime(true);

# define roots
# - script is in /bin, so one up for the real root
$user_root = getcwd();
$script_root = dirname(realpath(__FILE__));
$script_root .= "/..";

# read config & libs
require "{$script_root}/etc/config.php";
require "{$script_root}/lib/libs.php";
require "{$script_root}/lib/aggregate.php";
global $LA;

# time zone
date_default_timezone_set($LA['timezone']);

# open log
openLogFile($script_root);

# keep track of child processes
$currentProcesses = array();

# lookup array for entity information
# - fetch entities later when database is open
$entities = array();
$entities_sp_index = array();
$entities_idp_index = array();

# file with info about processed chunks
$chunk_info_file = tempnam(sys_get_temp_dir(),'la_chunk_');

###############
### SIGNALS ###
###############

# handle child signals
# - figure out which process ended
# - remove it from the currentProcesses list
declare(ticks = 1);

function sig_handler ($signo) {
	global $currentProcesses;
	switch ($signo) {
		case SIGCHLD:
			while ( ($pid = pcntl_wait ( $signo, WNOHANG )) > 0 ) {
				$signal = pcntl_wexitstatus ( $signo );
				if ($signal != 0) {
					echo "WARNING: ".$pid." exited with status ".$signal."!\n";
				}
				if ( isset($currentProcesses[$pid]) ) {
					unset($currentProcesses[$pid]);
				}
				else {
					echo "WARNING: ".$pid." was not registered!\n";
				}

			}
			break;
	}
}

pcntl_signal(SIGCHLD, "sig_handler");


##################
### PROCESSING ###
##################

function processChunk($chunk,$dbh_logins,$dbh_stats)
{
	global $LA;
	$chunk_logins = 0;

	echo "processing chunk: ".$chunk['id']."\n";
	list($entries,$users) = getEntriesFromLogins($chunk['from'],$chunk['to'],$dbh_logins);

	#print_r($entries);
	#print_r($users);

	# entry fields to process
	# - $entries[$entry]['time']
	# - $entries[$entry]['sp_entityid']
	# - $entries[$entry]['idp_entityid']
	# - $entries[$entry]['sp_name']
	# - $entries[$entry]['idp_name']
	# - $entries[$entry]['sp_eid']
	# - $entries[$entry]['idp_eid']
	# - $entries[$entry]['sp_revision']
	# - $entries[$entry]['idp_revision']
	# - $entries[$entry]['sp_environment']
	# - $entries[$entry]['idp_environment']
	# - $entries[$entry]['environment']
	# - $entries[$entry]['sp_metadata']
	# - $entries[$entry]['idp_metadata']
	# - $entries[$entry]['count']
	foreach ($entries as $key => $entry) {

		# ignore entires form blacklisted entityids
		if ( in_array($entry['sp_entityid'], $LA['entity_blacklist']) ||
		     in_array($entry['idp_entityid'],$LA['entity_blacklist']) ) continue;

		# first, check the day
		# - note: two steps to make locking easier and faster
		$day_id = LaAnalyzeDayInsert($entry['time'],$entry['environment'],$dbh_stats);
		LaAnalyzeDayUpdate($day_id,$entry['count'],$dbh_stats);

		# second, check the SP and IDP
		$provider_id = LaAnalyzeProviderUpdate($entry,$dbh_stats);

		# third, update the main table with login count
		LaAnalyzeStatsUpdate($day_id,$provider_id,$entry['count'],$dbh_stats);

		# fourth, update users table & update main table with new user count
		# - note, this might be the performance killer...
		if (! $LA['disable_user_count']) {
			$chunk_users = LaAnalyzeUserUpdate($day_id,$provider_id,$users[$key],$dbh_stats);
			if ($chunk_users > 0) {
				LaAnalyzeStatsUpdateUser($day_id,$provider_id,$chunk_users,$dbh_stats);
			}
		}

		$chunk_logins = $chunk_logins + $entry['count'];
	}
	echo "finished chunk: ".$chunk['id']."\n";
	return $chunk_logins;
}

function runChild()
{
	global $LA;
	global $chunk_info_file;

	##################
	### CHILD init ###
	##################

	$time_start = microtime(true);
	$child_link_stats  = openMysqlDb("DB_stats");
	$child_link_logins = openMysqlDb("DB_logins");

	##################
	### CHILD main ###
	##################

	$chunk = LaChunkNewGet($child_link_stats);
	if (isset($chunk['id'])) {
		$chunk_logins = processChunk($chunk,$child_link_logins,$child_link_stats);
		LaChunkProcessUpdate($chunk['id'], $chunk_logins, $child_link_stats);
		agSaveChunkInfo($chunk_info_file,$chunk);
	}
	else {
		echo "WARNING: no chunk processed\n";
	}

	###################
	### CHILD close ###
	###################

	closeMysqlDb($child_link_stats);
	closeMysqlDb($child_link_logins);
	$time_end = microtime(true);

	# keep track of child times
	$time_play = $time_end - $time_start;
	if ($time_play > $LA['max_allowed_process_time']) {
		log2file("WARNING: process time exceeded: ".$time_play);
	}

	# clean exit of child
	sleep(1);
	exit(0);
}

############
### MAIN ###
############

# check db version
echo "Opening DB...";
$LA['mysql_link_stats'] = openMysqlDb("DB_stats");
checkStatsDBVersion($LA['mysql_link_stats']);
echo "\n";

# get number of chunks from DB
echo "Retrieving chunks to process...";
$numberOfChunks = LaChunkNewCount();
fixIdPSPTables();
closeMysqlDb($LA['mysql_link_stats']);
echo "\n";


# get entity metadata from SR
echo "Fetching entity descriptions and metadata...";
$LA['mysql_link_sr'] = openMysqlDb("DB_sr");
$entities = getAllEntities();
closeMysqlDb($LA['mysql_link_sr']);
echo "\n";

# check for a max
if ($numberOfChunks > $LA['max_allowed_process_chunk'] && $LA['max_allowed_process_chunk'] != 0) {
	$numberOfChunks = $LA['max_allowed_process_chunk'];
}

# save for report
$total_numberOfChunks = $numberOfChunks;

echo "Start processing ".$total_numberOfChunks." chunks with ".$LA['max_processes']." parallel processes.\n";
log2file("Start processing ".$total_numberOfChunks." chunks with ".$LA['max_processes']." parallel processes.");

# loop while there are still chunks left
while ($numberOfChunks > 0) {
	$numberOfChunks--;

	# fork away
	$pid = pcntl_fork();
	if ($pid == -1) {
		# problem launching a new process
		echo "ERROR: could not launch a new process! Getting out of here...\n";
		break;
	}
	elseif ($pid) {
		# parent
		# - only register the new process and return to the main loop
		$currentProcesses[$pid] = 1;
	}
	else {
		# child
		runChild();
	}

	# run maximum number of processes
	while (count($currentProcesses) >= $LA['max_processes']) {
		echo ".";
		sleep(1);
	}

}

# wait for all processes to finish
while(count($currentProcesses)){
	echo ".";
	sleep(1);
}

#############
### CLOSE ###
#############

echo "\n";
$total_time_end = microtime(true);

# keep track of times
$total_time_play = $total_time_end - $total_time_start;
log2file("End processing ".$total_numberOfChunks." chunks in ".$total_time_play." seconds.");

##################
### AGGREGRATE ###
##################
log2file("Starting aggregation");
echo "Starting aggregation\n";
$total_time_start = microtime(true);

$LA['mysql_link_stats'] = openMysqlDb("DB_stats");
$num_days = agAggregate($chunk_info_file);
closeMysqlDb($LA['mysql_link_stats']);

$total_time_end = microtime(true);
$total_time_play = $total_time_end - $total_time_start;
log2file("End aggregation ".$num_days." days processed in ".$total_time_play." seconds.");

# clean up
closeLogFile();
unlink($chunk_info_file);

?>
