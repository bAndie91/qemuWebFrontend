<?php

function qemu_new($ini, $prm)
{
	$dir = $ini['qemu']['machine_dir']."/".$prm['machine']["name"];
	$ok = installdir($dir, 0771);
	if(!$ok)
	{
		add_error("directory error: $dir");
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

function qemu_rename($ini, $oldname, $newname)
{
	$prm_old = array('machine'=>array('name'=>$oldname));
	if(qemu_running($ini, $prm_old))
	{
		add_error("old VM is running");
		return false;
	}
	
	if(qemu_load($ini, $newname))
	{
		add_error("new VM already exists");
		return false;
	}
	
	$ok = rename($ini['qemu']['machine_dir'].'/'.$oldname, $ini['qemu']['machine_dir'].'/'.$newname);
	return $ok;
}

function qemu_load($ini, $vmname = NULL, $load = array())
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
			$vmlist[$entry]["name"] = $entry;
			$prm = array('machine' => $vmlist[$entry]);
			$vmlist[$entry]["saved_state"] = file_exists("$dir/$entry/state.xz");
			if(@$load["opts"])
			{
				$vmlist[$entry]["opt"] = qemu_load_opt("$dir/$entry/options");
				$vmlist[$entry]["opt"]["name"] = $entry;
			}
			if(@$load["state"])
			{
				$vmlist[$entry]["state"] = qemu_vmstate($ini, $prm);
			}
			if(@$load["diskusage"])
			{
				$vmlist[$entry]["diskusage"] = du("$dir/$entry");
			}
			if(@$load["memusage"])
			{
				$vmlist[$entry]["memusage"] = qemu_psmem($ini, $prm);
			}
			if(@$load["migrate"])
			{
				$vmlist[$entry]["savestate"] = qemu_info_migrate($ini, $prm);
			}
		}
	}
	@closedir($dh);
	
	if(isset($vmname))
	{
		if(isset($vmlist[$vmname]))
			return $vmlist[$vmname];
		else
			//stderr(print_r(debug_backtrace(), true));
			throw new Exception();
		return NULL;
	}
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
		$ok = installdir($dir, 0770);
		if(!$ok)
		{
			add_error("directory error: $dir");
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

function qemu_mk_opt($opt_array)
{
	$return = array();
	foreach($opt_array as $opt_name => $opt_value)
	{
		if(is_array($opt_value) and count($opt_value)>0)
		{
			foreach($opt_value as $str)
			{
				$return[] = '-'.$opt_name;
				if(!empty($str)) $return[] = $str;
			}
		}
		elseif(!is_array($opt_value))
		{
			$return[] = '-'.$opt_name;
			$return[] = $opt_value;
		}
		else
		{
			$return[] = '-'.$opt_name;
		}
	}
	return $return;
}

function qemu_mk_popt($opt_array)
{
	$return = array();
	foreach($opt_array as $opt_name => $opt_values)
	{
		if(count($opt_values)==0)
		{
			$return[] = '-'.$opt_name;
		}
		else
		{
			foreach($opt_values as $opt_value)
			{
				$return[] = '-'.$opt_name;
				$opt_value_strings = array();
				foreach((array)$opt_value as $subopt_key => $subopt_val)
				{
					$opt_value_strings[] = $subopt_key . ((isset($subopt_key) and $subopt_key!='' and isset($subopt_val) and $subopt_val!='') ? '=' : '') . $subopt_val;
				}
				$return[] = implode(',', $opt_value_strings);
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

function qemu_psmem($ini, $prm)
{
	$usage = array(
		"rss" => 0,
		"sz" => 0,
		"vsz" => 0,
	);
	$dir = $ini['qemu']['machine_dir']."/".$prm['machine']["name"];
	
	$cmd = execve("lsof", array("-n", "-Fp", "$dir/qmp.sock"));
	if($cmd["code"] == 0)
	{
		if(preg_match('/^p(\d+)/', $cmd["stdout"], $m))
		{
			$pid = $m[1];
			$cmd = execve("ps", array("-p", $pid, "-o", "rss=,sz=,vsz="));
			if($cmd["code"] == 0)
			{
				if(preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)/', $cmd["stdout"], $m))
				{
					$usage["rss"] = $m[1];
					$usage["sz"] = $m[2];
					$usage["vsz"] = $m[3];
				}
			}
		}
	}
	return $usage;
}

function qemu_vmstate($ini, $prm)
{
	$running = false;
	$dir = $ini['qemu']['machine_dir']."/".$prm['machine']["name"];
	$qmp = "$dir/qmp.sock";

	$fd = @fsockopen("unix://$qmp", -1, $errno, $errstr);
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
		if(!in_array($errno, $ini["system"]["acceptable_socket_errors"]))
			add_error("fsockopen($qmp) error #$errno $errstr", LOG_NOTICE);
		$pwd = getcwd();
		$ok = chdir($dir);
		if(!$ok)
		{
			add_error("chdir($dir) failed");
		}
		else
		{
			$cmd = execve("lsof", array("-n", "-Fpc", "$dir/qmp.sock"));
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
			chdir($pwd);
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

function qemu_info_migrate($ini, $prm)
{
	$reply = qemu_single_cmd($ini, $prm, "query-migrate");
	$return = array();
	if(count($reply['return']))
	{
		$return = $reply['return']['ram'];
		$return["status"] = $reply['return']['status'];
	}
	return $return;
}

function qemu_info_vnc($ini, $prm)
{
	$reply = qemu_single_cmd($ini, $prm, "query-vnc");
	if(substr($reply['return']['family'], 0, 2) == 'ip')
	{
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
		if(!isset($reply['return']['service']))
		{
			$reply['return']['service'] = find_free_vnc_display_num($ini) + VNC_PORT_BASE;
		}
	}
	if($reply['return']['family'] == 'unix')
	{
		unset($reply['return']['host']);
		unset($reply['return']['service']);		// it contains socket path, but may be truncated
	}
	return $reply['return'];
}

function qemu_change_vnc($ini, $prm, $listen, $password = NULL, $share = NULL)
{
	$cmds = array();
	if($listen == "")
	{
		$cmds[] = array(
			"execute" => "change",
			"arguments" => array(
				"device" => "vnc",
				"target" => "none",
			),
		);
	}
	else
	{
		$options = '';
		if(isset($password))
		{
			$options .= ",password";
			if($password != '')
			{
				$cmds[] = array(
					"execute" => "change",
					"arguments" => array(
						"device" => "vnc",
						"target" => "password",
						"arg" => $password,
					),
				);
			}
		}
		if(isset($share))
		{
			$options .= ",share=$share";
		}
		$cmds[] = array(
			"execute" => "change",
			"arguments" => array(
				"device" => "vnc",
				"target" => "$listen$options",
			),
		);
	}

	$reply = qemu_cmd($ini, $prm, $cmds);
	return $reply;
}


/*
    Returns filename, mtime of the given screenshot, or the latest one if $shid omitted
*/
function qemu_load_screenshot($ini, $prm, $shid = NULL, $subdir = NULL)
{
	$vmname = $prm['machine']["name"];
	if(!$vmname)
	{
		add_error("qemu_load_screenshot: no machine name");
		return NULL;
	}
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
	else
	{
		return false;
	}
}

function qemu_refresh_screenshot($ini, $prm, $prev_shid = NULL)
{
	$return = false;
	$vmname = $prm['machine']["name"];
	$path = $ini["qemu"]["machine_dir"]."/$vmname/screenshot";
	installdir($path, 0770);
	installdir("$path/diff", 0770);
	// TODO: error handle
	
	$shid = str_replace(".", "-", microtime(true));
	$basename = $ini["qemu"]["screenshot_prefix"].$shid.$ini["qemu"]["screenshot_suffix"];
	$dumppath = "$path/$basename.ppm";
	$filename = "$basename.".$ini["qemu"]["screenshot_ext"];
	$filepath = "$path/$filename";
	
	$reply = qemu_single_cmd($ini, $prm, "screendump", array("filename" => $dumppath));
	// TODO: error handle

	$msec = 1000;
	$file_ok = wait_file($dumppath, $msec);
	if(!$file_ok)
	{
		add_error("file was not created within $msec msec: $dumppath", LOG_WARNING);
		return false;
	}

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

function qmp_error_to_string($error)
{
	if(is_array($error))
	{
		$str = $error['class'].": ".$error['desc'];
		if(@$error['data'])
		{
			$str .= "; ".json_encode($error['data']);
		}
	}
	else
	{
		$str = $error;
	}
	return $str;
}
