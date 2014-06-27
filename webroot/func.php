<?php

function rmdir_recursive($dir)
{
	$dh = opendir($dir);
	while($entry = readdir($dh))
	{
		if($entry == '.' or $entry == '..') continue;
		if(is_dir("$dir/$entry"))
		{
			$ok = rmdir_recursive("$dir/$entry");
			if(!$ok) return false;
		}
		else
		{
			$ok = unlink("$dir/$entry");
			if(!$ok)
			{
				add_error("unlink($dir/$entry) failed");
				return false;
			}
		}
	}
	closedir($dh);
	$ok = rmdir($dir);
	if(!$ok)
	{
		add_error("rmdir($dir) failed");
		return false;
	}
	return true;
}

function add_error($str, $level = NULL)
{
	if(!empty($str))
	{
		$GLOBALS['error'][] = $str;
	}
}

function execve($cmd, $args = array())
{
	$return = array();
	
	$safe_cmd = escapeshellarg($cmd);
	$safe_args = implode(" ", array_escapeshellarg($args));
	$descriptors = array(
		0 => array('file', "/dev/null", 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'));
	
	$proc = proc_open("$safe_cmd $safe_args", $descriptors, $pipes);
	pcntl_wait($proc_status);
	$return["stdout"] = pipe_get_contents($pipes[1]);
	$return["stderr"] = pipe_get_contents($pipes[2]);

	$return["code"] = pcntl_wexitstatus($proc_status);
	return $return;
}

function pipe_get_contents($fd)
{
	$buf = '';
	do $buf .= fread($fd, 4096);
	while(!feof($fd));
	return $buf;
}

function array_escapeshellarg($array)
{
	return array_map(
		function($s)
		{
			return escapeshellarg($s);
		},
		$array);
}

/*
function dfpc($str)
{
	file_put_contents("/tmp/qd", $str."\n", FILE_APPEND);
}
*/

function is_alnum($ord)
{
	return(($ord >= 0x30 and $ord <= 0x39) or ($ord >= 0x41 and $ord <= 0x5A) or ($ord >= 0x61 and $ord <= 0x7A));
	
}
