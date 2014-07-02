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
	
	// TODO: dont use sh
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
	while(!feof($fd))
	{
		$buf .= fread($fd, 4096);
	}
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

function imagecreatefromany($file)
{
	return imagecreatefromstring(file_get_contents($file));
}

function img_compare($file_a, $file_b, $file_c)
{
	/*
	$cmd = execve("composite", array($prev_sh['file'], $filepath, "-compose", "subtract", $diff));
	if($cmd["code"] != 0)
	{
		add_error("comparasion failed, ".$cmd["stderr"]);
		return false;
	}
	else return true;
	*/
	
	$img_a = imagecreatefromany($file_a);
	$img_b = imagecreatefromany($file_b);
	$width = imagesx($img_a);
	$height = imagesy($img_a);
	$img_c = imagecreatetruecolor($width, $height);
	imagealphablending($img_c, false);
	imagesavealpha($img_c, true);
	
	for($y=0; $y<$height; $y++)
	{
		for($x=0; $x<$width; $x++)
		{
			$px_a = imagecolorsforindex($img_a, imagecolorat($img_a, $x, $y));
			$px_b = imagecolorsforindex($img_b, imagecolorat($img_b, $x, $y));
			
			foreach(array('red', 'green', 'blue', 'alpha') as $chan)
			{
				$px_c[$chan] = $px_b[$chan] - $px_a[$chan];
				if($px_c[$chan] < 0) $px_c[$chan] = ($chan == 'alpha' ? 128 : 256) + $px_c[$chan];
			}
			
			imagesetpixel($img_c, $x, $y, imagecolorallocatealpha($img_c, $px_c['red'], $px_c['green'], $px_c['blue'], $px_c['alpha']));
		}
	}
	
	$pathinfo = pathinfo($file_c);
	if(!is_dir($pathinfo['dirname']))
	{
		mkdir_recursive($pathinfo['dirname']);
	}
	
	switch(strtolower($pathinfo['extension']))
	{
	case "gif":
		$ok = imagegif($img_c, $file_c);
	break;
	case "jpg":
	case "jpeg":
		$ok = imagejpeg($img_c, $file_c, 90);
	break;
	case "png":
	default:
		$ok = imagepng($img_c, $file_c, 9);
	break;
	}
	
	return $ok;
}

function mkdir_recursive($dir, $mode = NULL)
{
	if(!isset($mode))
	{
		$mode = umask() ^ 0777;
	}
	$pi = pathinfo($dir);
	if(!is_dir($pi['dirname']))
	{
		mkdir_recursive($pi['dirname'], $mode);
	}
	return mkdir($dir, $mode);
}

