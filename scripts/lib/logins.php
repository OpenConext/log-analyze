<?php

##############
### LOGINS ###
##############

function array_index($array,$i)
{
	return $array[$i];
}

# for test
function getTotalNumberOfEntries($from, $to) {
    global $LA;

	$count = NULL;

	$result = mysql_query("SELECT count(*) as number FROM {$LA['table_logins']} WHERE loginstamp BETWEEN '{$from}' AND '{$to}'", $LA['mysql_link_logins']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$count = $result_row['number'];
	}

	return $count;
}

# for test
function getRandomEntry($max, $from, $to) {
    global $LA;

	$offset = rand(1,$max-1);
	$entry = array();

	$result = mysql_query("SELECT loginstamp,userid,spentityid,idpentityid,spentityname,idpentityname FROM log_logins WHERE loginstamp BETWEEN '{$from}' AND '{$to}' LIMIT {$offset},1", $LA['mysql_link_logins']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$dt = new DateTime($result_row['loginstamp']);
		$timestamp = $dt->format("Y-m-d");

		$entry['timestamp'] = $timestamp;
		$entry['user'] = sha1(trim($result_row['userid'].$LA['anonymous_user_string']));
		$entry['sp'] = $result_row['spentityid'];
		$entry['idp'] = $result_row['idpentityid'];
		$entry['sp_name'] = $result_row['spentityname'];
		$entry['idp_name'] = $result_row['idpentityname'];
	}

	return $entry;
}

# for test
# - from & to & counter are optional
function getNumberOfEntriesPerProvider($sp, $idp, $from, $to, $counter) {
    global $LA;

	$count = NULL;
	
	$extend = "";
	if (isset($from) && isset($to)) {
		$extend = " AND loginstamp BETWEEN '".$from."' AND '".$to."'";
	}
	
	$selector = "count(*)";
	if ($counter == "user") {
		$selector = "count(DISTINCT(userid))";
	}
	
	$result = mysql_query("SELECT ".$selector." as number FROM ".$LA['table_logins']. " WHERE spentityid = '".$sp."' AND idpentityid = '".$idp."'".$extend, $LA['mysql_link_logins']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$count = $result_row['number'];
	}

	return $count;
}

function getNumberOfEntriesFromLogins($from, $to) {
    global $LA;

	$count = NULL;

	$result = mysql_query("
		SELECT count(*) as number
		FROM {$LA['table_logins']}
		WHERE loginstamp BETWEEN '{$from}' AND '{$to}'
	", $LA['mysql_link_logins']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$count = $result_row['number'];
	}

	return $count;
}

function getTimestampOfEntryFromLogins($from, $to, $offset) {
    global $LA;

	$timestamp = NULL;

	$result = mysql_query("SELECT loginstamp FROM log_logins WHERE loginstamp BETWEEN '{$from}' AND '{$to}' LIMIT ".$offset.",1", $LA['mysql_link_logins']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$timestamp = $result_row['loginstamp'];
	}

	return $timestamp;
}

function _getEnvironment($env)
{
	switch ($env) {
		case 'PA':
		case 'prod':
		case 'production':
		case 'prodaccepted':
			return 'PA';
		
		case 'TA':
		case 'test':
		case 'testing':
		case 'testaccepted':
			return 'TA';
		
		case 'U':
		case 'unknown':
			return 'U';
	}
	throw new Exception("Unknown state/enviroment '$env'");
}

####################
### CHILD logins ###
####################
# used from within CHILD
# - use a $mysql_link parameter
# - use locking

function getEntriesFromLogins($from, $to, $dbh_logins) {
	global $LA;
	global $entities;

	$entries = array();
	$users = array();
	$seen = array();

	$result = mysql_query("
		SELECT
			UNIX_TIMESTAMP(loginstamp) as 'loginstamp',
			userid,
			spentityid,
			idpentityid,
			spentityname,
			idpentityname
		FROM log_logins
		WHERE loginstamp BETWEEN '{$from}' AND '{$to}'
	", $dbh_logins);

	if ($result===false) {
		catchMysqlError("getEntriesFromLogins", $dbh_logins);
	}

	while ($result_row = mysql_fetch_assoc($result))
	{
		$loginstamp = new DateTime("@".$result_row['loginstamp']);

		# SP

		# is this sp entityid known in SR at the specified time?
		$spentity = searchEntity($entities, 'saml20-sp', $result_row['spentityid'], $loginstamp);
		if ($spentity)
		{
			$sp_entityid    = $spentity['entityid'];
			$sp_environment = _getEnvironment($spentity['state']);
			$sp_metadata    = $spentity['metadata'];
			$sp_metahash    = $spentity['metahash'];
			$sp_datefrom    = $spentity['dates'][0][0];
			# array_index() because php5.3 doesn't support foo($bla)[1]
			$sp_dateto      = array_index( end($spentity['dates']), 1); 
		}
		else
		{ # entity unknown in SR
			$sp_entityid    = $result_row['spentityid'];
			$sp_environment = _getEnvironment('unknown');
			$sp_metadata    = array();
			$sp_metahash    = null;
			$sp_datefrom    = null;
			$sp_dateto      = null;
		}


		# IDP

		# is this sp entityid known in SR at the specified time?
		$idpentity = searchEntity($entities, 'saml20-idp', $result_row['idpentityid'], $loginstamp);
		if ($idpentity)
		{
			$idp_entityid    = $idpentity['entityid'];
			$idp_environment = _getEnvironment($idpentity['state']);
			$idp_metadata    = $idpentity['metadata'];
			$idp_metahash    = $idpentity['metahash'];
			$idp_datefrom    = $idpentity['dates'][0][0];
			$idp_dateto      = array_index( end($idpentity['dates']), 1);
		}
		else
		{ # entity unknown in SR
			$idp_entityid    = $result_row['idpentityid'];
			$idp_environment = _getEnvironment('unknown');
			$idp_metadata    = array();
			$idp_metahash    = null;
			$idp_datefrom    = null;
			$idp_dateto      = null;
		}

		# check if combination of SP and IdP environment is sane
		# determine environment to use for this entry
		if ($sp_environment==$idp_environment) {
			# normal situation (PA,PA) or (TA,TA) or (U,U)
			$environment = $sp_environment;
		}
		else {
			# either SP or IdP is U, environment is then determined by the other one
			if     ($sp_environment =='U') $environment = $idp_environment;
			elseif ($idp_environment=='U') $environment = $sp_environment;
			else {
				# ok, weirdness (TA IdP and PA SP, or vice versa)
				# the only way this can happen is because of flushes
				# a configuration may have been changed in SR, but still not be active until a flush
				# For now, report such entries and ignore them for logging purposes
				$dt = $loginstamp->format(DateTime::ISO8601);
				log2file("SP and IdP environment mismatch on {$dt}: "
						."IdP={$idp_environment}, SP={$sp_environment}\n"
						.print_r($result_row,1)
				);
				continue;
			}
		}



		# then record information about this IdP-SP combination and count logins and record users

		# sort per day:sp-eid:idp-eid:sp-revision:idp-revision:sp-environment:idp-environment
		# note: $loginstamp is a DateTime object, which records its own internal timezone (typically UTC)
		#       however, here we need the corresponding day in local (ie., Amsterdam) time.
		#       Therefore, we need to make an explicit conversion
		$loginstamp->setTimezone(new DateTimeZone($LA['timezone']));
		$logindate = $loginstamp->format("Y-m-d");

		$tag = "$logindate||"
				. "$idp_environment|$idp_entityid|$idp_metahash||"
				. "$sp_environment|$sp_entityid|$sp_metahash";


		# check if we have seen this (date,IdP,SP)-combination before
		if (isset($seen[$tag]))
		{
			# yes, seen before; simply count number of logins
			$n = $seen[$tag];
			$entries[$n]['count'] = $entries[$n]['count'] + 1;
		}
		else
		{
			# no, this is a new combination, record relevant info

			$record                    = array();
			$record['time']            = $logindate;
			$record['sp_name']         = $result_row['spentityname'];
			$record['idp_name']        = $result_row['idpentityname'];
			$record['sp_entityid']     = $sp_entityid;
			$record['idp_entityid']    = $idp_entityid;
			$record['sp_datefrom']     = $sp_datefrom;
			$record['idp_datefrom']    = $idp_datefrom;
			$record['sp_dateto']       = $sp_dateto;
			$record['idp_dateto']      = $idp_dateto;
			$record['sp_environment']  = $sp_environment;
			$record['idp_environment'] = $idp_environment;
			$record['environment']     = $environment;
			$record['sp_metadata']     = $sp_metadata;
			$record['idp_metadata']    = $idp_metadata;
			$record['sp_metahash']     = $sp_metahash;
			$record['idp_metahash']    = $idp_metahash;
			$record['count']           = 1;

			$entries[]  = $record;
			$n          = count($entries) - 1;
			$seen[$tag] = $n;
			$users[$n]  = array();

			# debug
			#if ($record['sp_environment']=='U' or $record['idp_environment']=='U') {
			#	print "Unknown: ";
			#	print_r($record);
			#}
		}

		# always add new users
		if (!$LA['disable_user_count'])
		{
			$newUser = sha1(trim($result_row['userid'] . $LA['anonymous_user_string']));
			# note: users are stored as array (well, hash) _keys_ rather than values
			# this is much more efficient than searching through the entire array every time
			# to check if we have encountered this user before
			$users[$n][$newUser] = true;
		}

	}

	# return users as flat arrays rather than hask keys
	foreach (array_keys($users) as $i)
	{
		$users[$i] = array_keys($users[$i]);
	}

	return array($entries, $users);
}



?>
