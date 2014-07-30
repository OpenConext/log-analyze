<?php

##############
### LOGINS ###
##############

# for test
function getTotalNumberOfEntries($from, $to) {
    global $LA;

	$count = NULL;

	$result = mysql_query("SELECT count(*) as number FROM ".$LA['table_logins']." WHERE loginstamp BETWEEN '".$from."' AND '".$to."'", $LA['mysql_link_logins']);
	
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

	$result = mysql_query("SELECT loginstamp,userid,spentityid,idpentityid,spentityname,idpentityname FROM ".$LA['table_logins']." WHERE loginstamp BETWEEN '".$from."' AND '".$to."' LIMIT ".$offset.",1", $LA['mysql_link_logins']);
	
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

	$result = mysql_query("SELECT count(*) as number FROM ".$LA['table_logins']. " WHERE loginstamp BETWEEN '".$from."' AND '".$to."'", $LA['mysql_link_logins']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$count = $result_row['number'];
	}

	return $count;
}

function getTimestampOfEntryFromLogins($from, $to, $offset) {
    global $LA;

	$timestamp = NULL;

	$result = mysql_query("SELECT loginstamp FROM ".$LA['table_logins']. " WHERE loginstamp BETWEEN '".$from."' AND '".$to."' LIMIT ".$offset.",1", $LA['mysql_link_logins']);
	
	if (mysql_num_rows($result) == 1) {
		$result_row = mysql_fetch_assoc($result);
		$timestamp = $result_row['loginstamp'];
	}

	return $timestamp;
}

####################
### CHILD logins ###
####################
# used from within CHILD
# - use a $mysql_link parameter
# - use locking

function getEntriesFromLogins($from, $to, $dbh_logins, $dbh_stats) {
    global $LA;

	$count = 1;
	$entries = array();
	$users = array();
	$seen = array();
	
	$result = mysql_query("SELECT loginstamp,userid,spentityid,idpentityid,spentityname,idpentityname FROM ".$LA['table_logins']. " WHERE loginstamp BETWEEN '".$from."' AND '".$to."'", $dbh_logins);
	
	if ($result) {
		while ($result_row = mysql_fetch_assoc($result)) {
			# add entity info: eid, revision & environment
			global $entities;
			global $entities_idp_index;
			global $entities_sp_index;
			
			# SP
			$sp_eid = 0;
			if (array_key_exists($result_row['spentityid'], $entities_sp_index)) {
				$sp_eid = $entities_sp_index[$result_row['spentityid']];
			}
			$sp_revision    = -1;
			$sp_environment = "";
			$sp_metadata    = array();
			$sp_datefrom    = null;
			$sp_dateto      = null;
			if ($sp_eid != 0) {
				$first = true;
				# find last matching entity that was created before the user's 
				# login time
				foreach ($entities[$sp_eid] as $revision => $value) {
					if ($first or $value['timestamp'] < $result_row['loginstamp'])
					{
						$sp_revision    = $revision;
						$sp_environment = $value['environment'];
						$sp_entityid    = $value['entityid'];
						$sp_metadata    = $value['metadata'];
						$sp_datefrom    = $value['date_from'];
						$sp_dateto      = $value['date_to'];
						$first = false;
					}
					else 
						{
						// $value['timestamp'] > $result_row['loginstamp']
						break;
					}
				}
			}
			else {
				$sp_revision = LaAnalyzeUnknownSPUpdate($result_row['spentityid'], $dbh_stats);
				$sp_entityid = $result_row['spentityid'];
				$sp_environment = "U";
			}
			
			# IDP
			$idp_eid = 0;
			if (array_key_exists($result_row['idpentityid'], $entities_idp_index)) {
				$idp_eid = $entities_idp_index[$result_row['idpentityid']];
			}
			$idp_revision    = -1;
			$idp_environment = "";
			$idp_metadata    = array();
			$idp_datefrom    = null;
			$idp_dateto      = null;
			if ($idp_eid != 0) {
				$first = true;
				# find last matching entity that was created before the user's 
				# login time
				foreach ($entities[$idp_eid] as $revision => $value) {
					if ($first or $value['timestamp'] < $result_row['loginstamp'])
					{
						$idp_revision    = $revision;
						$idp_environment = $value['environment'];
						$idp_entityid    = $value['entityid'];
						$idp_metadata    = $value['metadata'];
						$idp_datefrom    = $value['date_from'];
						$idp_dateto      = $value['date_to'];
						$first = false;
					}
					else {
						# $value['timestamp'] > $result_row['loginstamp']
						break;
					}
				}
			}
			else {
				$idp_revision = LaAnalyzeUnknownIDPUpdate($result_row['idpentityid'], $dbh_stats);
				$idp_entityid = $result_row['idpentityid'];
				$idp_environment = "U";
			}
			
			# sort per day:sp-eid:idp-eid:sp-revision:idp-revision:sp-environment:idp-environment
			$dt = new DateTime($result_row['loginstamp']);
			$timestamp = $dt->format("Y-m-d");
			
			$tag = $timestamp.":".$sp_eid.":".$idp_eid.":".$sp_revision.":".$idp_revision.":".$sp_environment.":".$idp_environment;
			
			# check entry
			# obsolete: unknown entries are stored as eid=0 and environment=U
			#if ($sp_eid == 0 || $idp_eid == 0 || $sp_revision == -1 || $idp_revision == -1 || $sp_environment != $idp_environment) {
			#	log2file("Entry not accepted: ".$tag. ". Login for SP: ".$result_row['spentityid']." from IDP: ".$result_row['idpentityid']." at time: ".$result_row['loginstamp']);
			#}
			
			# same entry
			if ( ($entry = array_search($tag, $seen)) !== false) {
				$entries[$entry]['count'] = $entries[$entry]['count'] + 1;
			}	
			# new entry
			else {
				$entry = $count;
				$count++;
				
				$seen[$entry] = $tag;
				
				$entries[$entry] = array();
				$entries[$entry]['time']            = $timestamp;
				$entries[$entry]['sp']              = $result_row['spentityid'];
				$entries[$entry]['idp']             = $result_row['idpentityid'];
				$entries[$entry]['sp_name']         = $result_row['spentityname'];
				$entries[$entry]['idp_name']        = $result_row['idpentityname'];
				$entries[$entry]['sp_entityid']     = $sp_entityid;
				$entries[$entry]['idp_entityid']    = $idp_entityid;
				$entries[$entry]['sp_eid']          = $sp_eid;
				$entries[$entry]['idp_eid']         = $idp_eid;
				$entries[$entry]['sp_revision']     = $sp_revision;
				$entries[$entry]['idp_revision']    = $idp_revision;
				$entries[$entry]['sp_datefrom']     = $sp_datefrom;
				$entries[$entry]['idp_datefrom']    = $idp_datefrom;
				$entries[$entry]['sp_dateto']       = $sp_dateto;
				$entries[$entry]['idp_dateto']      = $idp_dateto;
				$entries[$entry]['sp_environment']  = $sp_environment;
				$entries[$entry]['idp_environment'] = $idp_environment;
				$entries[$entry]['sp_metadata']     = $sp_metadata;
				$entries[$entry]['idp_metadata']    = $idp_metadata;
				$entries[$entry]['count'] = 1;
				
				$users[$entry] = array();
			}
			
			# always add new users
			$newUser = sha1(trim($result_row['userid'].$LA['anonymous_user_string']));
			if ((! $LA['disable_user_count']) && (! in_array($newUser, $users[$entry]))) {
				$users[$entry][] = $newUser;
			}

		}
	}
	else {
		catchMysqlError("getEntriesFromLogins", $dbh_logins);
	}

	return array($entries, $users);
}

?>
