<?php

function rmdir_recursive($dir)
{
	$dh = opendir($dir);
	while(($entry = readdir($dh)) !== false)
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


function fd($relfile)
{
	$return = NULL;
	$absfile = realpath($relfile);
	$dir = "/proc/self/fd";
	$dh = opendir($dir);
	while(($entry = readdir($dh)) !== false)
	{
		$f = "$dir/$entry";
		if(filetype($f) == 'link' and realpath($f) == $absfile)
		{
			$return = $entry;
			break;
		}
	}
	closedir($dh);
	return $return;
}


function execve($cmd, $args = array(), $redir = array())
{
	$return = array();

	if(function_exists('pcntl_exec') and function_exists('posix_pipe') and function_exists('posix_dup2'))
	{
		$executable = which($cmd);
		if($executable === false)
		{
			add_error("command not found: $cmd");
			return NULL;
		}
	
		$fdname = array(1=>"stdout", 2=>"stderr");
		$inner_pipes = array();
		for($fd=1; $fd<=2; $fd++)
		{
			if(isset($redir[$fdname[$fd]]))
			{
				$inner_pipes[$fd] = array("reader" => NULL);
				$inner_pipes[$fd]["writer"] = array("stream" => fopen($redir[$fdname[$fd]], 'w'));
				// $inner_pipes[$fd]["writer"]["fd"] = fd($redir[$fdname[$fd]]);
			}
			else
			{
				$p = posix_pipe();
				if(!is_array($p))
				{
					add_error("pipe() failed");
					return NULL;
				}
				$inner_pipes[$fd] = array(
					"reader" => array(
						"stream" => $p[0],
						// "fd"=>$p[2]
					),
					"writer" => array(
						"stream"=>$p[1],
						// "fd"=>$p[3]
					)
				);
			}
		}
		
		$pid = pcntl_fork();
		if($pid == -1)
		{
			add_error("fork() failed");
			return NULL;
		}
		elseif($pid == 0)
		{
			// TODO: close stdin
			foreach($inner_pipes as $fd => $pipe)
			{
				if(isset($pipe["reader"]["stream"])) fclose($pipe["reader"]["stream"]);
				posix_dup2($pipe["writer"]["stream"], $fd);
			}
			pcntl_exec($executable, $args);
			exit(127);
		}
		else
		{
			foreach($inner_pipes as $fd => $pipe)
			{
				fclose($pipe["writer"]["stream"]);
				$pipes[$fd] = $pipe["reader"]["stream"];
			}
		}
	}
	else
	{
		add_error("Can not bypass shell while executing command: $cmd", LOG_NOTICE);
		
		$descriptors = array();
		$descriptors[0] = array('file', "/dev/null", 'r');
		$descriptors[1] = array('pipe', 'w');
		$descriptors[2] = array('pipe', 'w');
		if(isset($redir["stdout"])) $descriptors[1] = array('file', $redir["stdout"], 'w');
		if(isset($redir["stderr"])) $descriptors[2] = array('file', $redir["stderr"], 'w');

		$safe_cmd = escapeshellarg($cmd);
		$safe_args = implode(" ", array_escapeshellarg($args));
		
		$proc = proc_open("$safe_cmd $safe_args", $descriptors, $pipes);
	}

	$t0 = microtime(true);
	pcntl_wait($proc_status);

	$return["microtime"] = microtime(true) - $t0;
	if(!isset($redir["stdout"])) $return["stdout"] = pipe_get_contents($pipes[1]);
	if(!isset($redir["stderr"])) $return["stderr"] = pipe_get_contents($pipes[2]);

	$return["code"] = pcntl_wexitstatus($proc_status);
	return $return;
}

function pipe_get_contents($fh)
{
	$buf = '';
	while(isset($fh) and $fh !== false and !feof($fh))
	{
		$buf .= fread($fh, 4096);
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

function which($name)
{
	foreach(explode(':', getenv('PATH')) as $path)
	{
		if(is_executable("$path/$name"))
		{
			return "$path/$name";
		}
	}
	return false;
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

