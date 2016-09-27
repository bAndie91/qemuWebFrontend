<?php

function qemu_start($ini, $prm)
{
	if(qemu_running($ini, $prm))
	{
		add_error("already running");
		return false;
	}
	
	$pwd = getcwd();
	$dir = $ini['qemu']['machine_dir']."/".$prm['machine']["name"];
	$ok = chdir($dir);
	if(!$ok)
	{
		add_error("chdir($dir) failed");
		return false;
	}
	
	$return = false;

	$sockets = array();
	$prm['machine']['opt']['daemonize'] = array();
	$prm['machine']['opt']['qmp'] = array("unix:$dir/qmp.sock,server,nowait");
	
	//stderr(print_r($prm['machine']['opt'], 1));
	$parsed_options = parse_opt($prm['machine']['opt']);
	//stderr(print_r($parsed_options, 1));
	foreach($parsed_options as $key => &$value)
	{
		if($key == 'net')
		{
			foreach($value as &$suboption)
			{
				if(isset($suboption["smb"]))
				{
					$smb_dir = $suboption["smb"];
					if(!is_dir($smb_dir))
					{
						$ok = installdir($smb_dir, 0770);
						if(!$ok)
						{
							add_error("directory error: $smb_dir", LOG_NOTICE);
						}
					}
					$suboption["smb"] = realpath($smb_dir);
				}
			}
		}
		elseif($key == 'monitor' or $key == 'qmp')
		{
			foreach($value as &$suboption)
			{
				foreach($suboption as $suboption_key => &$suboption_val)
				{
					if(preg_match('/^unix:(.+)$/', $suboption_key, $m))
					{
						$sockets[] = $m[1];
					}
				}
			}
		}
		elseif($key == 'vnc')
		{
			foreach($value as &$suboption)
			{
				foreach($suboption as $suboption_key => &$suboption_val)
				{
					if(preg_match('/^unix:([^,]+)$/', $suboption_key, $m))
					{
						$path = $m[1];
						// It is useless to set permissions on socket in this phase,
						// while qemu does recreate socket file.
						//qemu_fix_vnc_socket($ini, $prm, $path);
						$dirname = dirname($path);
						if($dirname != '.')
						{
							qemu_mkdir_vnc($ini, $prm, dirname($path));
						}
					}
				}
			}
		}
		elseif($key == 'hda')
		{
			foreach($value as &$suboption)
			{
				foreach($suboption as $suboption_key => &$suboption_val)
				{
					$hda_file = $suboption_key;
					$hda_size = $ini['qemu']["default_hda_size"];
					if(isset($prm['machine']['hda_size'])) $hda_size = $prm['machine']['hda_size'];
					if(!file_exists($hda_file))
					{
						$umask = umask();
						umask(0007);
						$cmd = execve("qemu-img", array("create", "-f", "qcow2", $hda_file, $hda_size));
						umask($umask);
					}
				}
			}
		}
	}
	//stderr(print_r($parsed_options, 1));

	$ok = qemu_fix_sockets($sockets);
	if($ok)
	{
		//stderr(implode(' ', qemu_mk_opt($prm['machine']['opt'])));
		//stderr(implode(' ', qemu_mk_popt($parsed_options)));
		//add_error(var_export(qemu_mk_popt($parsed_options), 1));
		$umask = umask();
		umask(0007);
		//$cmd = execve("strace", array_merge(array("-fq","-o","/tmp/q.strace","qemu"), qemu_mk_opt($prm['machine']['opt'])), array(/* "stdout"=>"/dev/null", "stderr"=>"/dev/null" */));
		//$cmd = execve("true", array(), array("stdin"=>"/dev/null"));
		$cmd = execve("qemu", qemu_mk_popt($parsed_options), array("stdin"=>"/dev/null"));
		umask($umask);
		//add_error(var_export($cmd, 1));
		if($cmd["code"] === 0)
		{
			$return = true;
		}
		else
		{
			$str = $cmd["stderr"];
			if(empty($str)) $str = "Error code: ".$cmd["code"];
			add_error($str);
		}
	}
	chdir($pwd);
	return $return;
}

function qemu_fix_sockets($array)
{
	foreach($array as $sockname)
	{
		if(file_exists($sockname))
		{
			if(is_socket($sockname))
			{
				$stat = stat($sockname);
				$mode = $stat['mode'];
				if(is_writable($sockname) and ($mode & S_IWOTH) == 0)
				{
					/* this socket is ok */
					continue;
				}
				else
				{
					if($stat['uid'] == getmyuid())
					{
						$ok = chmod($sockname, 0660);
						if(!$ok) goto socket_unlink;
						/* this socket is ok */
						continue;
					}
					else
					{
						goto socket_unlink;
					}
				}
			}
			else
			{
				socket_unlink:
				add_error("unlink $sockname", LOG_DEBUG);
				$ok = unlink($sockname);
				if(!$ok)
				{
					add_error("unlink($sockname) failed");
					return false;
				}
			}
		}
		
		/* this socket is ok */
	}
	
	return true;
}

function qemu_fix_vnc_socket($ini, $prm, $path)
{
	if(file_exists($path) and !is_socket($path))
	{
		$ok = unlink($path);
		if(!$ok)
		{
			add_error("unlink($sockname) failed");
			return false;
		}
	}
	if(!file_exists($path))
	{
		mksockfile($path);
	}
	// grant access to webserver
	$ok = facl($path, "user:{$ini['webserver']['user']}:rw-");
	return $ok;
}

function qemu_mkdir_vnc($ini, $prm, $dir)
{
	if(!file_exists($dir))
	{
		if(!mkdir($dir))
		{
			add_error("unlink($sockname) failed");
			return false;
		}
	}
	// grant access to webserver
	$ok = facl($dir, "default:user:{$ini['webserver']['user']}:rw-,default:mask::rwx");
	return $ok;
}

function qemu_cmd($ini, $prm, $cmds, $delay = array(), $errfail = false)
{
	$return = array();
	$qmp = $ini['qemu']['machine_dir']."/".$prm['machine']["name"]."/qmp.sock";
	$helo = false;
	
	if(!isset($prm["machine"]["qmp_socket"]))
	{
		$prm["machine"]["qmp_socket"] = @fsockopen("unix://$qmp", -1, $errno, $errstr);
		$helo = true;
	}
	$fd = $prm["machine"]["qmp_socket"];
	if($fd)
	{
		if($helo)
		{
			fgets($fd); /* HELO */
			fwrite($fd, json_encode(array("execute"=>"qmp_capabilities")));
			fgets($fd); /* reply for qmp_capabilities */
		}
		$continue = true;
		foreach($cmds as $n => $cmd)
		{
			if(!$continue) break;
			if(isset($delay) and isset($delay[$n])) usleep($delay[$n] * 1000);
			//file_put_contents("php://stderr", json_encode($cmd)."\n");
			$ok = fwrite($fd, json_encode($cmd));
			if($ok !== false)
			{
				while(true)
				{
					$line = fgets($fd);
					if($line !== false)
					{
						$json = json_decode($line, true);
						if(isset($json["return"])) break;
						if(isset($json["error"]))
						{
							if($errfail) $continue = false;
							break;
						}
					}
					else
					{
						add_error("read from qmp socket failed, command was '{$cmd['execute']}'");
						$json = false;
						break;
					}
				}
				$return[] = $json;
			}
			else
			{
				add_error("write to qmp socket failed, command was '{$cmd['execute']}'");
				$return[] = $json;
			}
		}
	}
	else
	{
		if(!in_array($errno, $ini["system"]["acceptable_socket_errors"]))
		{
			add_error("fsockopen($qmp) error #$errno $errstr", LOG_NOTICE);
			add_error("failed to relay QMP request");
		}
		$return = false;
	}
	//@fclose($fd);
	return $return;
}

function qemu_single_cmd($ini, $prm, $cmd, $args = NULL)
{
	$cmds = array(array("execute"=>$cmd));
	if(isset($args)) $cmds[0]["arguments"] = $args;
	$reply = qemu_cmd($ini, $prm, $cmds);
	return $reply[0];
}

function qemu_single_human_cmd($ini, $prm, $cmd)
{
	return qemu_single_cmd($ini, $prm, "human-monitor-command", array("command-line" => $cmd));
}

function qemu_human_cmd($ini, $prm, $cmds, $delay = array(), $errfail = false)
{
	return qemu_cmd($ini, $prm, array_map(
		function($str)
		{
			return array(
				"execute" => "human-monitor-command",
				"arguments" => array(
					"command-line" => $str,
				),
			);
		},
		$cmds,
		$delay,
		$errfail)
	);
}
