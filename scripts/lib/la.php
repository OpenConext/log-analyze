<?php

#############
### CHUNK ###
#############

function LaChunkSave($chunkArray) {
    global $LA;

	$status = 1;
	$timestamp = date("Y-m-d H:i:s");

	foreach ($chunkArray as $chunk) {
		$result = mysql_query("
			INSERT INTO log_analyze_chunk 
			(`chunk_from`,`chunk_to`,`chunk_status`,`chunk_in`)
			VALUES('{$chunk['from']}','{$chunk['to']}','new',{$chunk['size']})
		", $LA['mysql_link_stats']);
		
		if (mysql_affected_rows($LA['mysql_link_stats']) != 1) {
			$status = 0;
			catchMysqlError("LaChunkSave", $LA['mysql_link_stats']);
		}

	}

	log2file("Generated ".count($chunkArray)." chunks with timestamp ".$timestamp);

	return $status;
}

function LaChunkNewCount() {
    global $LA;

	$count = 0;

	$result = mysql_query("SELECT count(*) as number FROM log_analyze_chunk WHERE chunk_status = 'new'", $LA['mysql_link_stats']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$count = $result_row['number'];
	}
	
	return $count;
}

###################
### CHILD chunk ###
###################
# used from within CHILD
# - use a $mysql_link parameter
# - use locking

function LaChunkNewGet($mysql_link) {
    global $LA;

	$chunk = array();
	$timestamp = date("Y-m-d H:i:s");
	
	# starting a transaction and lock using 'for update' on the selection of the row.
	mysql_query("START TRANSACTION", $mysql_link);
	
	# get id
	$result = mysql_query("SELECT chunk_id,chunk_from,chunk_to FROM log_analyze_chunk WHERE chunk_status = 'new' ORDER BY `chunk_from` LIMIT 1 FOR UPDATE", $mysql_link);
	
	if ($result && mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$chunk['id'] = $result_row['chunk_id'];
		$chunk['from'] = $result_row['chunk_from'];
		$chunk['to'] = $result_row['chunk_to'];
	
		# update status
		$query = "UPDATE log_analyze_chunk SET chunk_status = 'process' WHERE chunk_id = ".$chunk['id'];
		$result = mysql_query($query, $mysql_link);
		if (mysql_affected_rows($mysql_link) != 1) {
			catchMysqlError("LaChunkNewGet ($query): ".mysql_affected_rows($mysql_link), $mysql_link);
		}
	}
	
	# 'update' done
	mysql_query("COMMIT", $mysql_link);
	
	return $chunk;
}

function LaChunkProcessUpdate($chunk_id, $chunk_logins, $mysql_link) {
    global $LA;

	$status = 1;

	$query = "UPDATE log_analyze_chunk SET chunk_status = 'done', chunk_out = {$chunk_logins} WHERE chunk_id = {$chunk_id}";
	$result = mysql_query($query, $mysql_link);
	if ($result===false or mysql_affected_rows($mysql_link) != 1) {
		catchMysqlError("LaChunkProcessUpdate ($query)", $mysql_link);
		$status = 0;
	}
	
	return $status;
}

###############
### ANALYZE ###
###############

# for test
function getNumberOfEntriesInDay($from, $to) {
    global $LA;

	$count = 0;

	$result = mysql_query("SELECT sum(day_logins) as number FROM log_analyze_day WHERE day_day BETWEEN '".$from."' AND '".$to."'", $LA['mysql_link_stats']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$count = $result_row['number'];
	}
	
	return $count;
}

# for test
function getNumberOfEntriesInStats($from, $to) {
    global $LA;

	$count = 0;

	$result = mysql_query("SELECT sum(stats_logins) as number FROM log_analyze_stats WHERE stats_day_id IN (SELECT day_id FROM log_analyze_day WHERE day_day BETWEEN '".$from."' AND '".$to."')", $LA['mysql_link_stats']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$count = $result_row['number'];
	}
	
	return $count;
}

# for test
# - from, to & counter are optional
function getNumberOfEntriesInStatsPerProvider($sp, $idp, $sp_name, $idp_name, $from, $to, $counter) {
    global $LA;

	$count = 0;

	$extend = ""; 
	if (isset($from) && isset($to)) {
		$extend = " AND stats_day_id IN (SELECT day_id FROM log_analyze_day WHERE day_day BETWEEN '".$from."' AND '".$to."')";
	}

	$selector = "sum(stats_logins)";
	if ($counter == "user") {
		$selector = "sum(stats_users)";
	}

	$result = mysql_query("SELECT ".$selector." as number FROM log_analyze_stats WHERE stats_provider_id IN (SELECT provider_id FROM log_analyze_provider,log_analyze_sp, log_analyze_idp WHERE provider_sp_id = sp_id AND provider_idp_id = idp_id AND sp_name = '".$sp_name."' AND idp_name = '".$idp_name."')".$extend, $LA['mysql_link_stats']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$count = $result_row['number'];
	}
	else {
		# try a 'U' entry...
		$result = mysql_query("SELECT ".$selector." as number FROM log_analyze_stats WHERE stats_provider_id IN (SELECT provider_id FROM log_analyze_provider,log_analyze_sp, log_analyze_idp WHERE provider_sp_id = sp_id AND provider_idp_id = idp_id AND sp_name = '".$sp."' AND idp_name = '".$idp."')".$extend, $LA['mysql_link_stats']);
		
		if (mysql_num_rows($result) == 1) {
			$result_row = mysql_fetch_assoc($result);
			$count = $result_row['number'];
		}	
	}
	
	return $count;
}

#####################
### CHILD analyze ###
#####################

### Day ###
function LaAnalyzeDayInsert($day, $environment, $mysql_link) {
    global $LA;

	$user_table = "";

	# starting a transaction
	mysql_query("START TRANSACTION", $mysql_link);
	# use semaphore to prevent duplicate inserts
	mysql_query("SELECT semaphore_id FROM log_analyze_semaphore WHERE semaphore_name = 'day' LIMIT 1 FOR UPDATE", $mysql_link);

	# try to get id
	$result = mysql_query("
		SELECT day_id
		FROM log_analyze_day
		WHERE day_day = '{$day}'
		  AND day_environment = '{$environment}'
		LIMIT 1
	", $mysql_link);

	if (mysql_num_rows($result) == 1) {
		$row = mysql_fetch_assoc($result);
		$day_id = $row['day_id'];
	}
	elseif (mysql_num_rows($result) == 0)
	{
		# insert day
		$result = mysql_query("
			INSERT INTO log_analyze_day
			(`day_day`,`day_environment`)
			VALUES('{$day}','{$environment}')
		", $mysql_link);
		$day_id = mysql_insert_id($mysql_link);
		if (mysql_affected_rows($mysql_link) != 1)
		{
			catchMysqlError("LaAnalyzeDayInsert ($result): " . mysql_error($mysql_link), $mysql_link);
			$status = 0;
		}

		# create user table for this day, later...
		$user_table = "log_analyze_days__" . $day_id;
	}
	else
	{
		throw new Exception("Found more than one (date,env) combination; this can't happen");
	}

	# 'insert' done
	mysql_query("UPDATE log_analyze_semaphore SET semaphore_value = 1 WHERE semaphore_name = 'day'", $mysql_link);
	mysql_query("COMMIT", $mysql_link);

	# create user table after update...
	if ((! $LA['disable_user_count']) && ($user_table != "")) {
		$result = mysql_query("
			CREATE TABLE {$user_table} (
				user_day_id INT NOT NULL,
				user_provider_id INT NOT NULL,
				user_name VARCHAR(128) DEFAULT NULL,
				PRIMARY KEY (user_day_id,user_provider_id,user_name),
				FOREIGN KEY (user_day_id)      REFERENCES `log_analyze_day`      (day_id),
				FOREIGN KEY (user_provider_id) REFERENCES `log_analyze_provider` (provider_id)
			)
			", $mysql_link);
		if (!$result) {
			catchMysqlError("LaAnalyzeDayInsert (CREATE USER TABLE)", $mysql_link);
		}
	}

	return $day_id;
}

function LaAnalyzeDayUpdate($day_id, $logins, $mysql_link) {
    global $LA;

	$timestamp = date("Y-m-d H:i:s");
	
	# starting a transaction and lock using 'for update' on the selection of the row.
	mysql_query("START TRANSACTION", $mysql_link);

	# update day
	$query = "
		UPDATE log_analyze_day
		SET day_logins = day_logins + {$logins}
		WHERE day_id = {$day_id}
	";
	$result = mysql_query($query, $mysql_link);
	if (mysql_affected_rows($mysql_link) != 1) {
		catchMysqlError("LaAnalyzeDayUpdate (UPDATE), query was:\n$query", $mysql_link);
	}

	# 'update' done
	mysql_query("COMMIT", $mysql_link);

	return $day_id;
}

### PROVIDER ###
function LaAnalyzeProviderUpdate($entry, $mysql_link) {
    global $LA;

	$provider_id = 0;

	# starting a transaction
	mysql_query("START TRANSACTION", $mysql_link);
	# use semaphore to prevent duplicate inserts
	mysql_query("SELECT semaphore_id FROM log_analyze_semaphore WHERE semaphore_name = 'provider' LIMIT 1 FOR UPDATE", $mysql_link);
	
	# get id
	$result = mysql_query("
		SELECT provider_id
		FROM log_analyze_provider
		LEFT JOIN log_analyze_sp  ON provider_sp_id  = sp_id
		LEFT JOIN log_analyze_idp ON provider_idp_id = idp_id
		WHERE
			    sp_entityid     = '{$entry['sp_entityid']}'
			AND sp_environment  = '{$entry['sp_environment']}'
			AND sp_meta         = '{$entry['sp_metahash']}'
			AND idp_entityid    = '{$entry['idp_entityid']}'
			AND idp_environment = '{$entry['idp_environment']}'
			AND idp_meta        = '{$entry['idp_metahash']}'
		LIMIT 1
	", $mysql_link);

	if ($result===false) {
		catchMysqlError("LaAnalyzeProviderUpdate",$mysql_link);
	}
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$provider_id = $result_row['provider_id'];
	}
	else {
		$sp_id = 0;
		$idp_id = 0;
		
		# first, lookup SP id

		$result = mysql_query("
			SELECT sp_id
			FROM log_analyze_sp
			 WHERE
				    sp_entityid     = '{$entry['sp_entityid']}'
				AND sp_environment  = '{$entry['sp_environment']}'
				AND sp_meta         = '{$entry['sp_metahash']}'
			LIMIT 1
		", $mysql_link);
		
		if (mysql_num_rows($result) == 1) {
			$result_row = mysql_fetch_assoc($result);
			$sp_id = $result_row['sp_id'];
		}
		else {
			# insert SP

			if ($entry['sp_environment']=='U') {
				# type=unknown entities don't have a start or end date
				$date_from = 'NULL';
				$date_to   = 'NULL';
				$meta      = '';
			}
			else {
				$date_from = $entry['sp_datefrom']->getTimeStamp();
				$date_to   = $entry['sp_dateto'  ]->getTimeStamp();
				$meta      = $entry['sp_metahash'];
			}
			$query = "
				INSERT INTO log_analyze_sp 
					(sp_name,sp_entityid,sp_environment,sp_meta,sp_datefrom,sp_dateto)
				VALUES(
					'".safeInsert($entry['sp_name'])."',
					'".safeInsert($entry['sp_entityid'])."',
					'".safeInsert($entry['sp_environment'])."',
					'{$meta}',
					FROM_UNIXTIME({$date_from}),
					FROM_UNIXTIME({$date_to})
				)
			";
			#print "Inserting SP:\n$query\n";
			$result = mysql_query($query, $mysql_link);
			$sp_id = mysql_insert_id($mysql_link);
			if (mysql_affected_rows($mysql_link) != 1) {
				catchMysqlError("LaAnalyzeProviderUpdate (SP)", $mysql_link);
			}

			# insert additional metadata
			foreach ($entry['sp_metadata'] as $key => $value) {
				$query = "UPDATE log_analyze_sp SET `sp_m_$key`='$value' WHERE `sp_id`=$sp_id";
				mysql_query($query, $mysql_link);
				if (mysql_affected_rows($mysql_link) != 1) {
					catchMysqlError("LaAnalyzeProviderUpdate (SP extra metadata)", $mysql_link);
				}
			}
		}

		# second, lookup IDP id
		$query = "
			SELECT idp_id
			FROM log_analyze_idp
			 WHERE
				    idp_entityid     = '{$entry['idp_entityid']}'
				AND idp_environment  = '{$entry['idp_environment']}'
				AND idp_meta         = '{$entry['idp_metahash']}'
			LIMIT 1
		";
		$result = mysql_query($query, $mysql_link);
		#print "searching idp:\n$query\n";

		if (mysql_num_rows($result) == 1) {
			$result_row = mysql_fetch_assoc($result);
			$idp_id = $result_row['idp_id'];
		}
		else {
			# insert IDP
			#

			if ($entry['idp_environment']=='U') {
				# type=unknown entities don't have a start or end date
				$date_from = 'NULL';
				$date_to   = 'NULL';
				$meta      = '';
			}
			else {
				$date_from = $entry['idp_datefrom']->getTimeStamp();
				$date_to   = $entry['idp_dateto'  ]->getTimeStamp();
				$meta      = $entry['idp_metahash'];
			}
			$query = "
				INSERT INTO log_analyze_idp
					(idp_name,idp_entityid,idp_environment,idp_meta,idp_datefrom,idp_dateto)
				VALUES(
					'".safeInsert($entry['idp_name'])."',
					'".safeInsert($entry['idp_entityid'])."',
					'".safeInsert($entry['idp_environment'])."',
					'{$meta}',
					FROM_UNIXTIME({$date_from}),
					FROM_UNIXTIME({$date_to  })
				)
			";
			#print "Inserting IdP:\n$query\n";
			$result = mysql_query($query, $mysql_link);
			$idp_id = mysql_insert_id($mysql_link);
			if (mysql_affected_rows($mysql_link) != 1) {
				catchMysqlError("LaAnalyzeProviderUpdate (IDP)", $mysql_link);
			}

			# insert additional metadata
			foreach ($entry['idp_metadata'] as $key => $value) {
				if ($value)
				{
					$query = "UPDATE log_analyze_idp SET `idp_m_{$key}`='$value' WHERE `idp_id`={$idp_id}";
					mysql_query($query, $mysql_link);
					if (mysql_affected_rows($mysql_link) != 1)
					{
						catchMysqlError("LaAnalyzeProviderUpdate (IDP extra metadata): $query", $mysql_link);
					}
				}
			}
		}

		# third, insert provider
		$result = mysql_query("
			INSERT INTO log_analyze_provider
			(provider_sp_id,provider_idp_id)
			VALUES({$sp_id},{$idp_id})
		", $mysql_link);
		$provider_id = mysql_insert_id($mysql_link);
		if (mysql_affected_rows($mysql_link) != 1) {
			catchMysqlError("LaAnalyzeProviderUpdate (PROVIDER)", $mysql_link);
		}
	}

	# done
	mysql_query("UPDATE log_analyze_semaphore SET semaphore_value = 1 WHERE semaphore_name = 'provider'", $mysql_link);
	mysql_query("COMMIT", $mysql_link);
	
	return $provider_id;
}

### STATS ###
function LaAnalyzeStatsUpdate($day_id, $provider_id, $logins, $mysql_link) {
    global $LA;

	$status = 1;
	
	# starting a transaction
	mysql_query("START TRANSACTION", $mysql_link);
	
	# insert or update stats
	$result = mysql_query("INSERT INTO log_analyze_stats (stats_day_id,stats_provider_id,stats_logins,stats_users) VALUES(".$day_id.",".$provider_id.",".$logins.",0) ON DUPLICATE KEY UPDATE stats_logins = stats_logins + ".$logins, $mysql_link);
	if (!(mysql_affected_rows($mysql_link) == 1 || mysql_affected_rows($mysql_link) == 2)) {
		catchMysqlError("LaAnalyzeStatsUpdate (INSERT/UPDATE)", $mysql_link);
		$status = 0;
	}

	# 'update' or 'insert' done
	mysql_query("COMMIT", $mysql_link);
	
	return $status;
}

function LaAnalyzeStatsUpdateUser($day_id, $provider_id, $users, $mysql_link) {
    global $LA;

	$status = 1;
	
	# starting a transaction and lock using 'for update' on the selection of the row.
	mysql_query("START TRANSACTION", $mysql_link);
	
	# get id
	$result = mysql_query("SELECT stats_users FROM log_analyze_stats WHERE stats_day_id = ".$day_id." AND stats_provider_id = ".$provider_id." LIMIT 1 FOR UPDATE", $mysql_link);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$users_update = $result_row['stats_users'] + $users;
		
		# update stats
		$result = mysql_query("UPDATE log_analyze_stats SET stats_users = ".$users_update." WHERE stats_day_id = ".$day_id." AND stats_provider_id = ".$provider_id, $mysql_link);
		if (mysql_affected_rows($mysql_link) != 1) {
			catchMysqlError("LaAnalyzeStatsUpdateUser (UPDATE)", $mysql_link);
			$status = 0;
		}
	}
	else {
		catchMysqlError("LaAnalyzeStatsUpdateUser (SELECT)", $mysql_link);
		$status = 0;
	}
		
	# 'update' done
	mysql_query("COMMIT", $mysql_link);
	
	return $status;
}

### USER ###
function LaAnalyzeUserUpdate($day_id, $provider_id, $users, $mysql_link) {
    global $LA;

	$user_count = 0;
	$user_list = array();
	$user_table = "log_analyze_days__".$day_id;

	# starting a transaction and lock with the semaphore
	mysql_query("START TRANSACTION", $mysql_link);
	mysql_query("SELECT semaphore_id FROM log_analyze_semaphore WHERE semaphore_name = 'user' LIMIT 1 FOR UPDATE", $mysql_link);

	$result = mysql_query("
		SELECT user_name FROM {$user_table}
		WHERE user_day_id = {$day_id}
		  AND user_provider_id = {$provider_id}
	", $mysql_link);
	
	if ($result) {
		while ($result_row = mysql_fetch_assoc($result)) {
			$user_list[] = $result_row['user_name'];
		}

		$new_users = array_diff($users, $user_list);
		$user_count = count($new_users);
		$first = 1;
		$insert_values = "";
		foreach ($new_users as $user) {
			if ($first) {
				$insert_values .= "($day_id,$provider_id,'$user')";
				$first = 0;
			}
			else {
				$insert_values .= ",($day_id,$provider_id,'$user')";
			}
		}
			
		if ($insert_values != "") {
			# insert user list
			$query  = "INSERT INTO {$user_table} VALUES {$insert_values}";
			$result = mysql_query($query, $mysql_link);

			if (mysql_affected_rows($mysql_link) < 1) {
				catchMysqlError("LaAnalyzeUserUpdate (INSERT)", $mysql_link);
			}
		}
	}
	else {
		catchMysqlError("LaAnalyzeUserUpdate", $mysql_link);
	}

	# save the semaphore, 'update' done.
	mysql_query("UPDATE log_analyze_semaphore SET semaphore_value = 1 WHERE semaphore_name = 'user'", $mysql_link);
	mysql_query("COMMIT", $mysql_link);
	
	return $user_count;
}

?>
