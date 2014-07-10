<?php

function qemu_new($ini, $prm)
{
	$dir = $ini['qemu']['machine_dir']."/".$prm['machine']["name"];
	$ok = mkdir($dir);
	if(!$ok)
	{
		add_error("mkdir($dir) failed");
		return false;
	}

	$ok = qemu_save_opt($ini, $prm);
	if(!$ok) return false;
	
	return true;
}

function qemu_delete($ini, $prm)
{
	if(qemu_running($ini, $prm))
	{
		add_error("VM is running");
		return false;
	}
	if(str_replace(array(".", "/"), "", $prm['machine']["name"]) == "")
	{
		add_error("VM name is empty");
		return false;
	}
	else
	{
		$ok = rmdir_recursive($ini['qemu']['machine_dir']."/".$prm['machine']["name"]);
		if(!$ok) return false;
	}
	
	return true;
}

function qemu_load($ini, $vmname = NULL, $load_opts = true)
{
	$vmlist = array();
	
	$dir = $ini['qemu']['machine_dir'];
	$dh = @opendir($dir);
	while(($entry = @readdir($dh)) !== false)
	{
		if($entry == '.' or $entry == '..') continue;
		if(is_dir("$dir/$entry"))
		{
			if(isset($vmname) and $vmname != $entry) continue;
			$vmlist[$entry] = array(
				"name" => $entry,
			);
			if($load_opts)
			{
				$prm = array('machine'=>$vmlist[$entry]);
				$vmlist[$entry]["opt"] = qemu_load_opt("$dir/$entry/options");
				$vmlist[$entry]["state"] = qemu_vmstate($ini, $prm);
			}
		}
	}
	@closedir($dh);
	
	if(isset($vmname)) return $vmlist[$vmname];
	return $vmlist;
}

function qemu_load_opt($opt_dir)
{
	$return = array();
	$dh = @opendir($opt_dir);
	while(($entry = @readdir($dh)) !== false and $entry !== NULL)
	{
		$file = "$opt_dir/$entry";
		if(is_file($file))
		{
			$return[$entry] = array_map(
				function($s)
				{
					return trim($s);
				},
				file($file));
		}
	}
	@closedir($dh);
	return $return;
}


function qemu_save_opt($ini, $prm)
{
	$opt_names = array_keys($prm['machine']["opt"]);
	$dir = $ini['qemu']['machine_dir']."/".$prm['machine']["name"]."/options";
	if(!is_dir($dir))
	{
		$ok = mkdir($dir);
		if(!$ok)
		{
			add_error("mkdir($dir) failed");
			return false;
		}
	}
	$dh = opendir($dir);
	while(($entry = readdir($dh)) !== false)
	{
		if(!is_file("$dir/$entry")) continue;
		if(!in_array($entry, $opt_names) or !is_writable("$dir/$entry"))
		{
			$ok = unlink("$dir/$entry");
			if(!$ok)
			{
				add_error("unlink($dir/$entry) failed");
				return false;
			}
		}
	}
	foreach($prm['machine']["opt"] as $opt_name => $opt_values)
	{
		$ok = file_put_contents("$dir/$opt_name", implode("\n", (array)$opt_values));
		if($ok === false)
		{
			add_error("fwrite($dir/$opt_name) failed");
			return false;
		}
	}
	return true;
}

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
	$prm['machine']['opt']['qmp'] = array("unix:qmp.sock,server,nowait");
	
	$sub_opt = parse_opt($prm['machine']['opt']);
	foreach($sub_opt as $key => $value)
	{
		if($key == 'net')
		{
			foreach($value as $so)
			{
				if(isset($so["smb"]))
				{
					$smb_dir = $so["smb"];
					if(!is_dir($smb_dir))
					{
						$ok = mkdir($smb_dir);
						if(!$ok)
						{
							add_error("mkdir($smb_dir) failed", LOG_NOTICE);
						}
					}
				}
			}
		}
		elseif($key == 'monitor' or $key == 'qmp')
		{
			foreach($value as $so)
			{
				foreach($so as $so_key => $so_val)
				{
					if(preg_match('/^unix:(.+)$/', $so_key, $m))
					{
						$sockets[] = $m[1];
					}
				}
			}
		}
		elseif($key == 'hda')
		{
			foreach($value as $so)
			{
				foreach($so as $so_key => $so_val)
				{
					$hda_file = $so_key;
					$hda_size = "1024M";
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

	$ok = qemu_fix_sockets($sockets);
	if($ok)
	{
		//add_error( var_export(qemu_mk_opt($prm['machine']['opt']) ,1));
		$cmd = execve("qemu", qemu_mk_opt($prm['machine']['opt']), array("stdout"=>"/dev/null","stderr"=>"/dev/null"));
		if($cmd["code"] === 0)
		{
			$return = true;
		}
		else
		{
			add_error($cmd["stderr"]);
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
			$stat = stat($sockname);
			$mode = $stat['mode'];
			if(($mode & S_IFMT) == S_IFSOCK)
			{
				if(is_writable($sockname) and ($mode & S_IWOTH)==0)
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
				$ok = unlink($sockname);
				if(!$ok)
				{
					add_error("unlink($sockname) failed");
					return false;
				}
			}
		}
		
		$sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
		if(!$sock)
		{
			add_error("socket(AF_UNIX, SOCK_STREAM) failed");
			return false;
		}
		$ok = socket_bind($sock, $sockname);
		if(!$ok)
		{
			add_error("bind($sockname) failed");
			socket_close($sock);
			return false;
		}
		$ok = chmod($sockname, 0660);
		if(!$ok)
		{
			add_error("chmod($sockname) failed");
			socket_close($sock);
			return false;
		}
		socket_close($sock);
		/* this socket is ok */
	}
	
	return true;
}

function qemu_mk_opt($opt_array)
{
	$return = array();
	foreach($opt_array as $opt_name => $opt_value)
	{
		if(!is_array($opt_value) or count($opt_value)==0)
		{
			$return[] = '-'.$opt_name;
		}
		else
		{
			foreach($opt_value as $str)
			{
				$return[] = '-'.$opt_name;
				if(!empty($str)) $return[] = $str;
			}
		}
	}
	return $return;
}

function qemu_running($ini, $prm)
{
	$status = qemu_vmstate($ini, $prm);
	return $status["running"];
}

function qemu_vmstate($ini, $prm)
{
	$running = false;
	$qmp = $ini['qemu']['machine_dir']."/".$prm['machine']["name"]."/qmp.sock";
	
	$fd = @fsockopen("unix://$qmp", -1, $errno);
	if($fd)
	{
		$json = fgets($fd);
		$helo = json_decode($json, true);
		if(isset($helo['QMP']))
		{
			$running = true;
		}
	}
	else
	{
		// TODO // if($errno ...
		$cmd = execve("lsof", array("-n", "-Fpc", $qmp));
		if($cmd["code"] == 0)
		{
			foreach(explode("\n", $cmd["stdout"]) as $line)
			{
				if(preg_match('/^cqemu$/', $line, $m))
				{
					$running = true;
					break;
				}
			}
		}
	}
	@fclose($fd);
	if($running)
	{
		$reply = qemu_single_cmd($ini, $prm, "query-status");
		$paused = !$reply['return']['running'];
	}
	return array(
		"running" => $running,
		"paused" => @$paused,
	);
}

function qemu_info_vnc($ini, $prm)
{
	$reply = qemu_single_cmd($ini, $prm, "query-vnc");
	if(isset($reply['return']['host']))
	{
		$addr = $reply['return']['host'];
		if(ip2long($addr) == 0)
		{
			$cmd = execve("ip", array("route", "get", $_SERVER['REMOTE_ADDR']));
			preg_match('/src\s+(\S+)/', $cmd["stdout"], $grp);
			$addr = $grp[1];
		}
		$lans = get_LANs();
		$lan1 = get_LAN_for_address($addr, $lans);
		$lan2 = get_LAN_for_address($_SERVER['REMOTE_ADDR'], $lans);
		if($lan1['address'] == $lan2['address'])
		{
			$reply['return']['relative_address'] = $addr;
		}
	}
	else
	{
		$reply['return']['host'] = "0.0.0.0";
	}
	if(!isset($reply['return']['service'])) $reply['return']['service'] = find_free_vnc_display_num($ini) + VNC_PORT_BASE;
	return $reply['return'];
}

function qemu_change_vnc($ini, $prm, $host, $display = 0, $password = NULL, $share = NULL)
{
	$cmds = array();
	$cmds[] = array(
		"execute" => "change",
		"arguments" => array(
			"device" => "vnc",
			"target" => "none",
		),
	);
	$options = '';
	if($host != "")
	{
		if(isset($password))
		{
			$cmds[] = array(
				"execute" => "change",
				"arguments" => array(
					"device" => "vnc",
					"target" => "password",
					"arg" => $password,
				),
			);
			$options .= ",password";
		}
		if(isset($share))
		{
			$options .= ",share=$share";
		}
		$cmds[] = array(
			"execute" => "change",
			"arguments" => array(
				"device" => "vnc",
				"target" => "$host:$display$options",
			),
		);
	}

	$reply = qemu_cmd($ini, $prm, $cmds);
	return $reply;
}

function qemu_cmd($ini, $prm, $cmds, $delay = array())
{
	$return = array();
	$qmp = $ini['qemu']['machine_dir']."/".$prm['machine']["name"]."/qmp.sock";
	
	$fd = @fsockopen("unix://$qmp", -1, $errno);
	if($fd)
	{
		fgets($fd); /* HELO */
		fwrite($fd, json_encode(array("execute"=>"qmp_capabilities")));
		fgets($fd); /* reply for qmp_capabilities */
		foreach($cmds as $n => $cmd)
		{
			if(isset($delay[$n])) usleep($delay[$n] * 1000);
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
						if(isset($json["error"])) break;
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
		// TODO // if($errno ...
		$return = false;
	}
	@fclose($fd);
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

function qemu_human_cmd($ini, $prm, $cmds, $delay = array())
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
		$delay)
	);
}

/*
    Returns filename, mtime of the given screenshot, or the latest one if $shid omitted
*/
function qemu_load_screenshot($ini, $prm, $shid = NULL, $subdir = NULL)
{
	$vmname = $prm['machine']["name"];
	$path = $ini["qemu"]["machine_dir"]."/$vmname/screenshot";
	if(isset($subdir)) $path .= "/$subdir";
	if(isset($shid))
	{
		$filename = $ini["qemu"]["screenshot_prefix"].$shid.$ini["qemu"]["screenshot_suffix"].".".$ini["qemu"]["screenshot_ext"];
		$filepath = "$path/$filename";
	}
	else
	{
		$glob = glob("$path/".$ini["qemu"]["screenshot_prefix"]."*".$ini["qemu"]["screenshot_suffix"].".".$ini["qemu"]["screenshot_ext"]);
		usort($glob, function($f1, $f2)
		{
			$s1 = stat($f1);
			$s2 = stat($f2);
			return $s2['mtime'] - $s1['mtime'];
		});
		$filepath = @$glob[0];
		$pathinfo = pathinfo($filepath);
		preg_match('/^'.preg_quote($ini["qemu"]["screenshot_prefix"], '/').'(.*?)'.preg_quote($ini["qemu"]["screenshot_suffix"], '/').'\.'.preg_quote($ini["qemu"]["screenshot_ext"], '/').'$/', $pathinfo['basename'], $grp);
		$shid = $grp[1];
	}
	
	if(is_file($filepath))
	{
		$stat = stat($filepath);
		list($width, $height) = getimagesize($filepath);
		return array(
			"file" => $filepath,
			"id" => $shid,
			"timestamp" => $stat['mtime'],
			"size" => $stat['size'],
			"width" => $width,
			"height" => $height,
		);
	}
	else return false;
}

function qemu_refresh_screenshot($ini, $prm, $prev_shid = NULL)
{
	$return = false;
	$vmname = $prm['machine']["name"];
	$path = $ini["qemu"]["machine_dir"]."/$vmname/screenshot";
	if(!is_dir($path)) mkdir($path);
	
	$shid = str_replace(".", "-", microtime(true));
	$basename = $ini["qemu"]["screenshot_prefix"].$shid.$ini["qemu"]["screenshot_suffix"];
	$dumppath = "$path/$basename.ppm";
	$filename = "$basename.".$ini["qemu"]["screenshot_ext"];
	$filepath = "$path/$filename";
	
	$cmd = array(
		"execute" => "screendump",
		"arguments" => array(
			"filename" => $dumppath,
		),
	);
	$reply = qemu_cmd($ini, $prm, array($cmd));
	// TODO // error handle

	$cmd = execve("convert", array($dumppath, $filepath));
	if($cmd["code"] == 0)
	{
		list($width, $height) = getimagesize($filepath);

		if(isset($prev_shid))
		{
			$prev_sh = qemu_load_screenshot($ini, $prm, $prev_shid);
			if(isset($prev_sh['id']))
			{
				list($prev_width, $prev_height) = getimagesize($prev_sh['file']);
				if($prev_width == $width and $prev_height == $height)
				{
					$diff_shid = $shid."_".$prev_shid;
					$diff = $path."/diff/".$ini["qemu"]["screenshot_prefix"].$diff_shid.$ini["qemu"]["screenshot_suffix"].".".$ini["qemu"]["screenshot_ext"];
					$ok = img_compare($prev_sh['file'], $filepath, $diff);
					if(!$ok)
					{
						@unlink($diff);
						unset($diff);
						add_error("comparasion failed");
					}
				}
			}
			else
			{
				add_error("previous screenshot could not be loaded");
			}
		}
		
		$stat = stat($filepath);
		$return = array(
			"file" => $filepath,
			"id" => $shid,
			"timestamp" => $stat['mtime'],
			"size" => $stat['size'],
			"width" => $width,
			"height" => $height,
		);
		if(isset($diff))
		{
			$stat = stat($diff);
			$return["difference"] = array(
				"file" => $diff,
				"id" => $diff_shid,
				"timestamp" => $stat['mtime'],
				"size" => $stat['size'],
				"method" => $ini['qemu']['image_comparasion_method'],
			);
		}
	}
	else
	{
		@unlink($filepath);
		add_error("convert failed, ".$cmd["stderr"]);
	}
	unlink($dumppath);
	return $return;
}

