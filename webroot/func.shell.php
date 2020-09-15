<?php

require_once("func.php");

function rmdir_recursive($dir)
{
	$dh = opendir($dir);
	while(($entry = readdir($dh)) !== false)
	{
		if($entry == '.' or $entry == '..') continue;
		// BEWARE the MountPoints!
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


function execve($cmd, $args = array(), $redir = array(), $input_data = array())
{
	$fdnames = array(0=>"stdin", 1=>"stdout", 2=>"stderr");
	$direction = array(
		"parent" => array(0=>"writer", 1=>"reader", 2=>"reader"),
		"child"  => array(0=>"reader", 1=>"writer", 2=>"writer"),
	);
	$forkmode = NULL;
	$return = array();

	/* Spawn process */
	if(function_exists('pcntl_exec') and function_exists('posix_pipe') and function_exists('posix_dup2') and function_exists('posix_close'))
	{
		$forkmode = "pipe2";
		$executable = which($cmd);
		if($executable === false)
		{
			add_error("command not found: $cmd (PATH=".getenv('PATH').")");
			return NULL;
		}
	
		$inner_pipes = array();
		foreach($fdnames as $fd => $fdname)
		{
			if(isset($redir[$fdname]))
			{
				$open_end = $direction["child"][$fd];
				$close_end = $direction["parent"][$fd];
				$inner_pipes[$fd][$open_end]["stream"] = fopen($redir[$fdname], substr($open_end, 0, 1));
				if($inner_pipes[$fd][$open_end]["stream"] === FALSE)
				{
					add_error("fopen({$redir[$fdname]}) failed");
					return NULL;
				}
				$inner_pipes[$fd][$close_end] = NULL;
			}
			else
			{
				$pipe = posix_pipe();
				if(!is_array($pipe))
				{
					add_error("pipe() failed");
					return NULL;
				}
				$inner_pipes[$fd] = array(
					"reader" => array("stream" => $pipe[0], /* "fd" => $pipe[2], */),
					"writer" => array("stream" => $pipe[1], /* "fd" => $pipe[3], */),
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
			/* Child process */
			foreach($inner_pipes as $fd => $pipe)
			{
				$open_end = $direction["child"][$fd];
				$close_end = $direction["parent"][$fd];
				/* Close parent's FD */
				if(isset($pipe[$close_end]["stream"])) fclose($pipe[$close_end]["stream"]);
				
				/* Close standard FD to be able to reopen it */
				posix_close($fd);
				/* Duplicate original FD to standard FD */
				posix_dup2($pipe[$open_end]["stream"], $fd);
				/* Close original FD */
				fclose($pipe[$open_end]["stream"]);
			}
			/* Close all other FDs */
			for($fd = 3; $fd <= 255; $fd++) posix_close($fd);
			posix_setsid();
			pcntl_exec($executable, $args);
			exit(127);
		}
		else
		{
			/* Parent process */
			foreach($inner_pipes as $fd => $pipe)
			{
				$open_end = $direction["parent"][$fd];
				$close_end = $direction["child"][$fd];
				fclose($pipe[$close_end]["stream"]);
				$pipes[$fd] = $pipe[$open_end]["stream"];
			}
		}
	}
	else
	{
		$forkmode = "proc";
		add_error("Can not bypass shell while executing command: $cmd", LOG_NOTICE);
		
		$descriptors = array();
		foreach($fdnames as $fd => $fdname)
		{
			$open_end = $direction["child"][$fd];
			$close_end = $direction["parent"][$fd];
			if(isset($redir[$fdname]))
			{
				$descriptors[$fd] = array('file', $redir[$fdname], substr($open_end, 0, 1));
			}
			else
			{
				$descriptors[$fd] = array('pipe', substr($open_end, 0, 1));
			}
		}
		
		$safe_cmd = escapeshellarg($cmd);
		$safe_args = implode(" ", array_escapeshellarg($args));
		
		$proc = proc_open("$safe_cmd $safe_args", $descriptors, $pipes);
		if($proc === FALSE)
		{
			add_error("proc_open($cmd) failed");
			return NULL;
		}
		foreach($pipes as $fd => $stream) stream_set_blocking($stream, 1);
		$proc_status = proc_get_status($proc);
		$pid = $proc_status['pid'];
	}

	$t0 = microtime(true);
	//file_put_contents("php://stderr", print_r(array(`ls -l /proc/self/fd`, `ls -l /proc/$pid/fd`, $pipes), 1));

	/* Write input */
	foreach($pipes as $fd => $stream)
	{
		if($direction["parent"][$fd] == "writer" and $stream !== NULL)
		{
			if(isset($input_data[$fdnames[$fd]]))
			{
				fwrite($stream, $input_data[$fdnames[$fd]]);
			}
			fclose($stream);
		}
	}
	
	/* Read output */
	foreach($pipes as $fd => $stream)
	{
		// TODO: stream_select
		if($direction["parent"][$fd] == "reader" and $stream !== NULL)
		{
			$return[$fdnames[$fd]] = stream_get_contents($stream);
			fclose($stream);
		}
	}

	/* Close process */	
	if($forkmode == "pipe2")
	{
		pcntl_waitpid($pid, $proc_status);
		$return["code"] = pcntl_wexitstatus($proc_status);
	}
	elseif($forkmode == "proc")
	{
		$return["code"] = proc_close($proc);
	}

	$return["microtime"] = microtime(true) - $t0;
	
	
	return $return;
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
		$path = preg_replace('/\/*$/', '', $path);
		if(!is_dir("$path/$name") and is_executable("$path/$name"))
		{
			return "$path/$name";
		}
	}
	return false;
}

function mkdir_recursive($dir, $mode = NULL)
{
	$pi = pathinfo($dir);
	if(!is_dir($pi['dirname']))
	{
		mkdir_recursive($pi['dirname'], $mode);
	}
	return installdir($dir, $mode);
}

function installdir($dir, $mode = NULL)
{
	if(!isset($mode))
	{
		$mode = umask() ^ 0777;
	}

	if(!file_exists($dir))
	{
		mkdir($dir, $mode);
	}
	if(is_dir($dir))
	{
		$st = stat($dir);
		if(($st['mode'] & 07777) == ($mode & 07777))
			return true;
		else
			return chmod($dir, $mode);
	}
	return false;
}


function wait_file($file, $msec)
{
	$t0 = microtime(true);
	while(!@filesize_long($file))
	{
		if(microtime(true) > $t0 + $msec / 1000)
		{
			return false;
		}
	}
	return true;
}


function facl($file, $acl = NULL)
{
	if(isset($acl))
	{
		$args = "--mask";
		if(!$acl)
		{
			$args = array("-b");
		}
		else
		{
			$args = array("-m", $acl);
		}
		$args[] = $file;
		$cmd = execve("setfacl", $args);
		if($cmd)
		{
			if($cmd['code'] == 0)
			{
				return true;
			}
			else
			{
				add_error($cmd['stderr']);
			}
		}
	}
	else
	{
		// FIXME: getfacl
	}
	return false;
}

function stderr($str)
{
	return file_put_contents("php://stderr", $str);
}
function stderrln($str)
{
	return stderr($str . (substr($str, -1) == "\n" ? '' : "\n"));
}
