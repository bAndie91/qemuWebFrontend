<?php

function parse_opt($a)
{
	$return = array();
	foreach($a as $opt_name => $opt)
	{
		$return[$opt_name] = array();
		foreach((array)$opt as $o1)
		{
			$subopts = array();
			foreach(explode(",", $o1) as $o2)
			{
				preg_match('/^([^=]+)=?(.*)/', $o2, $m);
				$subopts[$m[1]] = $m[2];
			}
			$return[$opt_name][] = $subopts;
		}
	}
	return $return;
}

function is_vmname($str)
{
	return	preg_match('/^[a-z0-9\._-]+$/i', $str) and
		!preg_match('/^[\.-]/', $str) and
		!preg_match('/[\.-]$/', $str);
}

function is_shid($str)
{
	return preg_match('/^[0-9_\.-]+$/', $str);
}

