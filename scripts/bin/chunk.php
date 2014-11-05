#!/usr/bin/php
<?php

function timestamp2datestr($timestamp)
{
	global $LA;

	# convert timestamp to same timezone as used in MySQL connections
	$dt = DateTime::createFromFormat('U',$timestamp);
	$dt->setTimezone(new DateTimeZone($LA['timezone']));
	return $dt->format('Y-m-d H:i:s');
}

date_default_timezone_set("UTC");


#############
### INPUT ###
#############

# read args
$ARGS = getopt('',array('from:','to:'));

if (! array_key_exists("from", $ARGS) || ! array_key_exists("to", $ARGS)) {
	echo "USAGE $argv[0] --from=\"YYYY-MM-DD HH:MM:SS\" --to=\"YYYY-MM-DD HH:MM:SS\" \n";
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
$entry_from = strtotime($ARGS['from']);
$entry_to   = strtotime($ARGS['to']  );
if ($entry_from===false || $entry_to===false ) {
	echo "Arguments are not valid DATETIME. Format: YYYY-MM-DD HH:MM:SS\n";
	print_r($ARGS);
	exit;	
}
$entry_from = timestamp2datestr($entry_from);
$entry_to   = timestamp2datestr($entry_to  );

# open log
openLogFile($script_root);

# open database
$LA['mysql_link_logins'] = openMysqlDb("DB_logins");
$LA['mysql_link_stats']  = openMysqlDb("DB_stats");

# check databse format
checkStatsDBVersion($LA['mysql_link_stats']);

############
### MAIN ###
############

# get number of entries (or NULL)
$count = getNumberOfEntriesFromLogins($entry_from,$entry_to);

if (isset($count)) {
	# calculate chunk count based on preferred chunk size
	$chunk_size = $LA['preferred_chunk_size'];
	$chunk_count = ceil($count/$chunk_size);
	if ($chunk_count > ($LA['max_chunk_count'] * $LA['max_processes'])) {
		# calculate new chunk size based on max chunk count
		$chunk_count = $LA['max_chunk_count'] * $LA['max_processes'];
		$chunk_size = ceil($count/$chunk_count);
	}
	if ($chunk_count<1) {
		print "Incorrect interval\n";
		exit(1);
	}
	
	# calculate chunk size in seconds
	$entry_from_seconds = strtotime($entry_from);
	$entry_to_seconds = strtotime($entry_to);
	$chunk_size_seconds = ceil(($entry_to_seconds - $entry_from_seconds)/$chunk_count);
}

##############
### OUTPUT ###
##############

# go
if (isset($count)) {
	if ($chunk_size < $LA['max_chunk_size']) {
		$chunkArray = array();
		
		# user info
		echo "Number of entries: ".$count."\n";
		echo "Chunk size: ".$chunk_size."\n";
		echo "Chunk count: ".$chunk_count."\n";
		echo "> Chunk size in seconds: ".$chunk_size_seconds."\n";

		if ($LA['use_preferred_chunk_size'] == 1) {
			echo "\nGenerating chunks based on size...\n";
			# save chunks based on preferred chunk sizes
			# - the limit option of mysql does not perform, but try it anyway...
			$date_from = $entry_from;
			for ($i=1;$i<=$chunk_count;$i++) {
				echo ".";
				$offset = $i * $chunk_size;
				if ($i == $chunk_count) {
					# last must match exactly
					$date_to = $entry_to;
				}
				else {
					$date_to = getTimestampOfEntryFromLogins($entry_from,$entry_to,$offset);
				}
				$count_sub = getNumberOfEntriesFromLogins($date_from,$date_to);
				
				# build array
				# - don't think this will run out of memory, otherwise should skip the array...
				$chunkArray[$i] = array();
				$chunkArray[$i]['from'] = $date_from;
				$chunkArray[$i]['to'] = $date_to;
				$chunkArray[$i]['size'] = $count_sub;

				# next
				$date_from = date("Y-m-d H:i:s", strtotime($date_to) + 1);
			}
		}
		else {
			echo "\nGenerating chunks based on count & seconds...\n";
			# save chunks based on chunk count & seconds
			# - this gives not exact chunk sizes...
			for ($i=1;$i<=$chunk_count;$i++) {
				echo ".";
				if ($i == $chunk_count) {
					# last must match exactly
					$date_from = date("Y-m-d H:i:s", $entry_from_seconds + ($i-1)*$chunk_size_seconds);
					$date_to = $entry_to;
				}
				else {
					$date_from = date("Y-m-d H:i:s", $entry_from_seconds + ($i-1)*$chunk_size_seconds);
					$date_to = date("Y-m-d H:i:s", $entry_from_seconds + $i*$chunk_size_seconds - 1);
				}
				$count_sub = getNumberOfEntriesFromLogins($date_from,$date_to);
				if ($count_sub > $LA['max_chunk_size']) {
					echo "WARNING: chunk size overflow: ".$count_sub."\n";
				}
				
				# build array
				# - don't think this will run out of memory, otherwise should skip the array...
				$chunkArray[$i] = array();
				$chunkArray[$i]['from'] = $date_from;
				$chunkArray[$i]['to'] = $date_to;
				$chunkArray[$i]['size'] = $count_sub;
			}
		}

		# now lets see if everything went ok
		mysql_query("START TRANSACTION", $LA['mysql_link_stats']);
		$success = true;

		# save the chunks
		$status = LaChunkSave($chunkArray);
		if ($status != 1) {
			echo "WARNING: not all chunks are saved, reverting!\n";
			$success = false;
		}

		# check if there are overlaps
		$result = mysql_query('
			SELECT 
			    a.chunk_id as ida, a.chunk_from as a1, a.chunk_to as a2,
			    b.chunk_id as idb, b.chunk_from as b1, b.chunk_to as b2
			FROM log_analyze_chunk as a, log_analyze_chunk as b
			WHERE (
			     a.chunk_from BETWEEN b.chunk_from AND b.chunk_to
			  OR a.chunk_to   BETWEEN b.chunk_from AND b.chunk_to
			  OR b.chunk_from BETWEEN a.chunk_from AND a.chunk_to
			  OR b.chunk_to   BETWEEN a.chunk_from AND a.chunk_to
			)
			AND a.chunk_id<b.chunk_id
		', $LA['mysql_link_stats']); 
		if (mysql_num_rows($result)>0)
		{
			print "ERROR: chunks are overlapping, reverting!\n";
			while ($row = mysql_fetch_assoc($result))
			{
				print " - overlap between {$row['ida']} ({$row['a1']} to {$row['a2']}) "
				     ."and {$row['idb']} ({$row['b1']} to {$row['b2']})\n";

			}
			$success = false;
		}

		if ($success)
		{
			mysql_query("COMMIT", $LA['mysql_link_stats']);
			echo "... done\n";
		}
		else
		{
			mysql_query("ROLLBACK", $LA['mysql_link_stats']);
			echo "...chunking failed\n";
		}

	}
	else {
		echo "Chunk size too big: ".$chunk_size."\n";
		echo "Calculated chunk count: ".$chunk_count."\n";
		echo "WARNING: maybe max_chunk_count was reached\n";
	}
}
else {
	echo "Could not fetch entries\n";
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
