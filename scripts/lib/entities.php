<?php

################
### ENTITIES ###
################

# adjust log_analyze_idp and log_analyze_sp tables to store the custom metadata 
# defined in la.ini
function fixIdPSPTables()
{
	global $LA;

	foreach ($LA['extra_metadata'] as $metadata)
	{
		$key = $metadata['metadata_key'];

		# IdP
		$q1 = "ALTER TABLE `log_analyze_idp` ADD COLUMN `idp_m_$key` TEXT DEFAULT NULL";
		$q2 = "ALTER TABLE `log_analyze_sp`  ADD COLUMN `sp_m_$key`  TEXT DEFAULT NULL";

		$result = mysql_query($q1,$LA['mysql_link_stats']);
		# duplicate column (error 1060) is actually ok, as it implies the
		# column already exists
		if (!$result && mysql_errno($LA['mysql_link_stats'])!=1060) {
			catchMysqlError("fixIdPSPTables ($q1)", $LA['mysql_link_stats']);
		}

		$result = mysql_query($q2,$LA['mysql_link_stats']);
		# duplicate column (error 1060) is actually ok, as it implies the
		# column already exists
		if (!$result && mysql_errno($LA['mysql_link_stats'])!=1060) {
			catchMysqlError("fixIdPSPTables ($q2)", $LA['mysql_link_stats']);
		}
	}
}

function _entityRowsEqual($row1,$row2,$meta)
{
	if (count($row1)==0 or count($row2)==0) return false;
	if ($row1['entityid']!=$row2['entityid']) return false;
	if ($row1['state']   !=$row2['state']   ) return false;
	if ($row1['eid']     !=$row2['eid']     ) return false;
	foreach ($meta as $col) if ($row1[$col]!=$row2[$col]) return false;
	return true;
}

# fetch all entities from SR and return an index by entityid and date.
function getAllEntities()
{
	global $LA;

	$future = new DateTime($LA['FUTURE']);

	# create the required query, including custom metadata fields
	$meta_col   = '';
	$meta_names = array();
	$meta_join  = '';
	foreach ($LA['extra_metadata'] as $i => $m)
	{
		$t = "m{$i}";
		$meta_col .= "{$t}.value AS 'm{$i}',";
		$meta_names[] = "m${i}";
		$meta_join .= "
			LEFT JOIN {$LA['table_metadata']} AS {$t} ON  {$t}.connectionRevisionId=cr.id
			                                          AND {$t}.key='{$m['metadata_key']}'
		";
	}

	# this query returns all unique (entityid,eid,state,metadata) combinations from SR
	# ordering is by eid and revision in order to see which entries are still active,
	# and which are superseded
        $query = "
             SELECT
                 eid,
                 rev,
                 state,
                 CASE WHEN `type` like 'saml20_idp%' THEN 'saml20_idp' WHEN `type` like 'saml20_sp%' THEN 'saml20_sp' END as `type`,
                 created,
                 institutionid as m0,
                 entityid
              FROM manage_revisions order by eid,rev
        ";

	#print $query . "\n";

	$result = mysql_query($query, $LA['mysql_link_manage']);
	if ($result === false)
	{
		catchMysqlError("getAllEntities", $LA['mysql_link_manage']);
	}
	#$num = mysql_num_rows($result);

	$entities_by_eid = array();
	$prev_entityid   = '';
	$prev_type       = '';
	$prev_eid        = -1;
	$prev_rev        = -1;
	$prev_row        = array();
	while ($row = mysql_fetch_assoc($result))
	{

		##if ($eid>2) break;

		$entityid = $row['entityid'];
		$type     = $row['type'];
		$eid      = $row['eid'];
		$rev      = $row['rev'];

		# check for uniqueness
		if (_entityRowsEqual($row, $prev_row, $meta_names)) continue;

		# replace creation time by a proper time object
		# note: SR includes an explciit timezone in the `created` field
		$created        = new DateTime($row['created']);
		$row['created'] = $created;

		# save the data
		$entities_by_eid[$eid][$rev] = $row;

		# assume entity is still active until we see its successor
		$entities_by_eid[$eid][$rev]['active']  = true;
		$entities_by_eid[$eid][$rev]['enddate'] = $future;

		# set the end date of the current entry's predecessor (ie idp or sp with identical entityid)
		if (($prev_entityid == $entityid or $prev_eid == $eid) and $prev_type == $type)
		{
			$entities_by_eid[$prev_eid][$prev_rev]['active']  = false;
			$entities_by_eid[$prev_eid][$prev_rev]['enddate'] = $created;
		}

		# put the metadata fields in a separate subarray
		$entities_by_eid[$eid][$rev]['metadata'] = array();
		foreach ($LA['extra_metadata'] as $i => $m)
		{
			##print "--> $i / {$m['metadata_key']}\n";
			$name = "m{$i}";
			$key  = $m['metadata_key'];

			$entities_by_eid[$eid][$rev]['metadata'][$key] = $entities_by_eid[$eid][$rev][$name];
			unset($entities_by_eid[$eid][$rev][$name]);
		}

		$prev_entityid = $entityid;
		$prev_type     = $type;
		$prev_eid      = $eid;
		$prev_rev      = $rev;

		$prev_row = $row;
	}

	#print_r($entities_by_eid);
	#exit;
	#print "================================================\n";

	# merge back metadata fields, if requested
	# ie, of a metdata field for an entity was null for early revisions but has been set later,
	# replace all the null values by the first non-null value
	foreach (array_keys($entities_by_eid) as $eid)
	{
		# get array of revisions for this eid
		$revisions = array_keys($entities_by_eid[$eid]);
		sort($revisions, SORT_NUMERIC);

		# keep track of metadata of older entries
		$prev_meta = array();

		# loop over revisions from high to low
		foreach (array_reverse($revisions) as $rev)
		{
			$entity = $entities_by_eid[$eid][$rev];

			##print("eid $eid rev $rev\n");

			# check each of the defined metadata fields
			foreach ($LA['extra_metadata'] as $m)
			{
				if (!$m['backmerge']) continue;

				$n = $m['metadata_key'];
				if (isset($prev_meta[$n]) and $prev_meta[$n] !== null and $entity['metadata'][$n] === null)
				{
					$entities_by_eid[$eid][$rev]['metadata'][$n] = $prev_meta[$n];
				}
				else
				{
					$prev_meta[$n] = $entity['metadata'][$n];
				}
			}
		}

		##break;
	}

	#print_r($entities_by_eid);
	#print "================================================\n";

	# now resort/refile to get rid of eid/rev, which are SR-specific and should not be exposed in stats
	$entities = array();
	foreach (array_keys($entities_by_eid) as $eid)
	{
		foreach ($entities_by_eid[$eid] as $rev => $entity)
		{
			$entityid = $entity['entityid'];
			$state    = $entity['state'];
			$type     = $entity['type'];
			$date     = array($entity['created'], $entity['enddate']);
			$metastr  = sha1(serialize($entity['metadata']));

			##print "entities[$type][$entityid][$state][$metastr]\n";

			if (isset($entities[$type][$entityid][$state][$metastr]))
			{
				$entities[$type][$entityid][$state][$metastr]['dates'][] = $date;
			}
			else
			{
				$entities[$type][$entityid][$state][$metastr] = array(
						'entityid' => $entityid,
						'type'     => $type,
						'state'    => $state,
						'metadata' => $entity['metadata'],
						'metahash' => $metastr,
						'dates'    => array($date),
				);
			}
		}
	}

	#print_r($entities);

	# one last pass to build indices by date
	$index = array();
	foreach (array_keys($entities) as $type)
	{
		foreach (array_keys($entities[$type]) as $entityid)
		{
			foreach (array_keys($entities[$type][$entityid]) as $state)
			{
				foreach (array_keys($entities[$type][$entityid][$state]) as $metastr)
				{
					# references, becaus we want the merging (see below) to be reflected in the original data
					$entity = &$entities[$type][$entityid][$state][$metastr];
					$dates  = &$entity['dates'];

					# make sure date intervals are correctly ordered for merge below
					# this sorting order is also required for the index search in searchEntity
					ksort($dates);
					for ($i = 0; $i < count($dates); $i++)
					{
						# merge consequetive intervals
						if ($i + 1 < count($dates))
						{
							if ($dates[$i][1] == $dates[$i + 1][0])
							{
								$dates[$i + 1][0] = $dates[$i][0];
								unset($dates[$i]);
								$i++;
							}
						}
					}
					# renumber dates array since unset() might have fucked up the order
					$dates = array_values($dates);

					# add each date range of the current entityid to the index
					for ($i = 0; $i < count($dates); $i++)
					{
						$record['_start']          = &$dates[$i][0];
						$record['_end']            = &$dates[$i][1];
						$record['_entity']         = &$entity;
						$index[$type][$entityid][] = $record;
					}
				}
			}

			# now make sure the index array for each entity is sorted by date
			usort($index[$type][$entityid], function ($a, $b)
			{
				if ($a['_start'] > $b['_start']) return 1;
				if ($a['_start'] < $b['_start']) return -1;

				return 0;
			});


			#print_r($index[$type][$entityid]);
			#print "-------\n";
		}
	}

	#print "==================================\n";
	#print_r($index);

	return $index;
}

function testEntityIndex($index)
{
	print "===\n";
	$i = 'https://login.avans.nl/nidp/saml2/metadata';
	$t = 'saml20-idp';
	$d = '2013-09-04 22:00:00 +0200';
	print "$t $d $i\n";
	print_r(searchEntity($index, $t, $i, new DateTime($d)));

	print "===\n";
	$i = 'https://login.avans.nl/nidp/saml2/metadata';
	$t = 'saml20-idp';
	$d = '2013-09-30 19:50:00 +0200';
	print "$t $d $i\n";
	print_r(searchEntity($index, $t, $i, new DateTime($d)));

	print "===\n";
	$i = 'https://login.avans.nl/nidp/saml2/metadata';
	$t = 'saml20-idp';
	$d = '2012-11-13 00:00:00 +0200';
	print "$t $d $i\n";
	print_r(searchEntity($index, $t, $i, new DateTime($d)));
}


# search the index for an entity by entityid and date
function searchEntity($index,$type,$entityid,$date)
{
	if (!isset($index[$type])) return null;
	if (!isset($index[$type][$entityid])) return null;

	$entities = &$index[$type][$entityid];
	if (count($entities)==0)  return 0;

	$first = 0;
	$last = count($entities)-1;
	$entity = array();

	# perform a binary search
	# loop is guaranteed to run at least once
	while ($first<=$last)
	{
		$i = intval( ceil( ($first+$last)/2 ) );
		$entity = $index[$type][$entityid][$i];

		# check if requested dates falls in the current interval
		if     ($date<$entity['_start']) $last  = $i-1;
		elseif ($date>$entity['_end']  ) $first = $i+1;
		else
		{
			$first = $last = $i;
			break;
		}
	}

	if ($first!=$last) return null;
	return $entity['_entity'];
}



?>
