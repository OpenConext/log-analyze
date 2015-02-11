#!/usr/bin/env php
<?php

class TimelineNode
{
	private $prev = null;
	private $next = null;
	private $date;
	private $status;

	function __construct($prev,$next,$date,$status)
	{
		$this->prev   = $prev;
		$this->next   = $next;
		$this->date   = $date;
		$this->status = $status;

		if ($prev) $prev->setNext($this);
		if ($next) $next->setPrev($this);
	}
	function remove()
	{
		if ($this->prev()) $this->prev()->setNext( $this->next() );
		if ($this->next()) $this->next()->setPrev( $this->prev() );
	}
	function prev()   { return $this->prev; }
	function next()   { return $this->next; }
	function date()   { return $this->date; }
	function status() { return $this->status; }

	function setNext($nextNode) { $this->next = $nextNode; }
	function setPrev($prevNode) { $this->prev = $prevNode; }
	function setStatus($status) { $this->status = $status; }
}


class TimeLine
{
	private $firstNode = null;
	private $lastNode  = null;
	private $debug     = false;

	function __construct($start_date,$stop_date,$status)
	{
		$this->firstNode = new TimelineNode(null,null,$start_date,$status);
		$this->lastNode = $this->firstNode;
		$this->addAfter( $this->firstNode, $stop_date, 'X' );
	}

	private function addAfter($afterThisNode,$date,$status)
	{
		$beforeThisNode = $afterThisNode->next();
		$node = new TimelineNode($afterThisNode, $beforeThisNode, $date, $status);
		if ($beforeThisNode===null) $this->lastNode = $node;
		return $node;
	}

	# remove superfluous nodes from the timeline, starting at $startNode
	private function cleanup($startNode)
	{
		if (!$startNode) $startNode = $this->firstNode;

		$node1 = $startNode; 
		while ($node1->prev() and $node1->prev()->status()==$node1->status())
		{
			$node1 = $node1->prev();
		}

		$node2 = $startNode; 
		while ($node2->next() and $node2->next()->status()==$node2->status())
		{
			$node2 = $node2->next();
		}

		while ( $node1!==$node2 and $node1->status()==$node2->status() ) 
		{
			$node2->remove();
			$node2 = $node2->prev();
		}
	}

	function addSegment($date_from,$date_to,$status)
	{
		if ($date_to  < $date_from              ) throw new Exception("Date `$date_to` out of range");
		if ($date_from< $this->firstNode->date()) throw new Exception("Date `$date_from` out of range");
		if ($date_to  > $this->lastNode ->date()) throw new Exception("Date `$date_to` out of range");

		# find correct place to insert
		$node = $this->firstNode;
		while ( $node->next() and $date_from >= $node->next()->date() ) $node = $node->next();

		if ($this->debug)
		{
			if ($node->next())
				print "Going to insert node [$date_from,$date_to] ".
				      "between {$node->date()} and {$node->next()->date()}\n";
			else
				print "Going to insert node [$date_from,$date_to] after {$node->date()}\n";
		}

		if ( ($node->status()!='U') or $node->next() and $node->next()->date()<=$date_to)
		{
			throw new Exception("Inserted segment overlaps with existing segment!");
		}

		# save old status, to set after the end of the segment
		$old_status = $node->status();

		if ($node->date()==$date_from) 
		{
			# new segment begins exactly where the previous one ended, so 
			# replace instead of insert
			$node->setStatus($status);
			$node_from = $node;
		}
		else
		{
			$node_from = $this->addAfter($node,$date_from,$status);
		}

		# if the new segment ends exactly where the next one begins, we don't 
		# have to do anything as the status will be determined by the next 
		# segment
		if ( !($node->next() and $node->next()->date()==$date_to+1) )
		{
			$node_to = $this->addAfter($node_from,$date_to+1,$old_status);
		}

		$this->cleanup($node);
	}

	# return array of ($starttime,$endtime) of segments with specified status
	function findStatus($status)
	{
		$result = array();
		$node = $this->firstNode;
		while ($node)
		{
			if ($node->status()==$status)
			{
				$end = time();
				if ($node->next()) $end = $node->next()->date()-1;
					
				$result[] = array($node->date(),$end);
			}
			$node = $node->next();
		}
		return $result;
	}

	function dump()
	{
		print "---\n";
		$i = 0;
		$node = $this->firstNode;
		while ($node)
		{
			$datestr = date('Y-m-d H:i:s',$node->date());
			printf(" - %3u: %s ==> %s\n", $i, $datestr, $node->status());
			$i++;
			$node = $node->next();
		}
		print "---\n";
	}
}

#############
### Script to show which chunks are still missing
#############

function get_status($str)
{
	if ($str=='new')     return 'C';
	if ($str=='process') return 'I';
	if ($str=='done')    return 'D';

	print "Unknown status $str\n";
	exit(1);
}

function format_date($timestamp)
{
	return date('Y-m-d H:i:s O',$timestamp);
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

date_default_timezone_set('UTC');

$dbh = openMysqlDb("DB_logins");
if ( mysql_query("SET time_zone='+0:00';",$dbh) === false )
{
	catchMysqlError("failed to set time zone", $dbh);
	exit(1);
}

$result = mysql_query("select UNIX_TIMESTAMP(loginstamp) as 'a' from log_logins order by id asc limit 1");
if ($result===false)
{
	catchMysqlError("error while fetching start time", $dbh);
}
$row = mysql_fetch_assoc($result);
$start = $row['a']+0;

$result = mysql_query("select UNIX_TIMESTAMP(loginstamp) as 'a' from log_logins order by id desc limit 1");
if ($result===false)
{
	catchMysqlError("error while fetching start time", $dbh);
}
$row = mysql_fetch_assoc($result);
$stop = $row['a']+0;

mysql_close($dbh);


$dbh = openMysqlDb("DB_stats");
$LA['mysql_link_stats'] = $dbh;
checkStatsDBVersion();
if ( mysql_query("SET time_zone='+0:00';",$dbh) === false )
{
	catchMysqlError("failed to set time zone", $dbh);
	exit(1);
}


# keep track of states in a timeline
# U = unprocessed
# C = chunked but not analyzed
# I = chunked and in progress
# D = done
# X = end
$timeline = new Timeline($start,$stop,'U');

# fetch the chunks
$q = '
	SELECT 
		chunk_status,
		UNIX_TIMESTAMP(chunk_from) as chunk_from,
		UNIX_TIMESTAMP(chunk_to  ) as chunk_to
	FROM log_analyze_chunk';
$result = mysql_query($q,$dbh);
if ($result===false)
{
	catchMysqlError("error while fetching chunks query was: $q", $dbh);
	exit(4);
}
while ($chunk = mysql_fetch_assoc($result))
{
	# insert the chunk in the timeline
	$status = get_status($chunk['chunk_status']);
	$from = $chunk['chunk_from'];
	$to   = $chunk['chunk_to'  ];

	if ( $from < $start )
	{
		print "Chunk out of range: $from < $start\n";
		exit(1);
	}
	if ( $to < $from )
	{
		print "invalid chunk $from to $to\n";
		exit(1);
	}

	# find correct position in the timeline
	$timeline->addSegment($from,$to,$status);
}

closeMysqlDb($dbh);


# interpret command line 
$options = getopt("c");

# print output
$done    = $timeline->findStatus('D');
$doing   = $timeline->findStatus('I');
$chunked = $timeline->findStatus('C');
$todo    = $timeline->findStatus('U');

# print in form ready to feed to chunk.php
if (isset($options['c']))
{
	foreach ($todo as $s)
	{
		$t0 = format_date($s[0]);
		$t1 = format_date($s[1]);
		print "--from='$t0' --to='$t1'\n";
	}
	exit(0);
}


print "Done: \n";
foreach ($done as $s)
{
	$t0 = format_date($s[0]);
	$t1 = format_date($s[1]);
	print " - $t0 - $t1\n";
}
print "In progress: \n";
foreach ($doing as $s)
{
	$t0 = format_date($s[0]);
	$t1 = format_date($s[1]);
	print " - $t0 - $t1\n";
}
print "To process: \n";
foreach ($chunked as $s)
{
	$t0 = format_date($s[0]);
	$t1 = format_date($s[1]);
	print " - $t0 - $t1\n";
}
print "Unallocated: \n";
foreach ($todo as $s)
{
	$t0 = format_date($s[0]);
	$t1 = format_date($s[1]);
	print " - $t0 - $t1\n";
}

?>
