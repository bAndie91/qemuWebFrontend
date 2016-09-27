<?php

function is_socket($file)
{
	$stat = stat($file);
	return(($stat['mode'] & S_IFMT) == S_IFSOCK);
}

/*
function pipe_get_contents($fh)
{
	$buf = '';
	while(isset($fh) and $fh !== false and !feof($fh))
	{
		$buf .= fread($fh, 4096);
	}
	return $buf;
}
*/

function fd($relfile)
{
	/*
		arg1: file path (even relative)
		return: 1st file descriptor for the file
	*/
	$fds = fds($relfile);
	return isset($fds[0]) ? $fds[0] : NULL;
}

function fds($relfile)
{
	/*
		arg1: file path (even relative)
		return: array of all file descriptors for the file
	*/
	$return = array();
	$absfile = realpath($relfile);
	$dir = "/proc/self/fd";
	$dh = opendir($dir);
	while(($entry = readdir($dh)) !== false)
	{
		$f = "$dir/$entry";
		if(filetype($f) == 'link' and realpath($f) == $absfile)
		{
			$return[] = $entry;
			break;
		}
	}
	closedir($dh);
	return $return;
}

function du($dir)
{
	$return = 0;
	$dh = opendir($dir);
	while($ent = readdir($dh))
	{
		if($ent == '.' or $ent == '..') continue;
		if(is_dir("$dir/$ent")) $return += du("$dir/$ent");
		else $return += filesize("$dir/$ent");
	}
	closedir($dh);
	return $return;
}

function mksockfile($path, $type = NULL, $mode = NULL)
{
	if(!isset($type)) $type = SOCK_STREAM;

	$sock = socket_create(AF_UNIX, $type, 0);
	if(!$sock)
	{
		add_error("socket() failed");
		return false;
	}
	
	$ok = socket_bind($sock, $path);
	if(!$ok)
	{
		add_error("bind($path) failed");
		socket_close($sock);
		return false;
	}
	
	if(isset($mode))
	{
		$ok = chmod($path, $mode);
		if(!$ok)
		{
			$mode_oct = sprintf("%o", $mode);
			add_error("chmod($path, 0$mode_oct) failed");
			socket_close($sock);
			return false;
		}
	}

	socket_close($sock);
	return true;
}
