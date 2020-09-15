<?php

require_once("func.shell.php");

function find_free_vnc_display_num($ini)
{
	$ports_used = array();
	foreach(glob($ini['qemu']['machine_dir']."/*/options/display") as $file)
	{
		foreach(file($file) as $str)
		{
			if(preg_match('/vnc=[^:,]*:(\d+)/', $str, $m))
			{
				$ports_used[] = $m[1] + VNC_PORT_BASE;
			}
		}
	}
	foreach(file("/proc/net/tcp") as $line)
	{
		if(preg_match('/^\s\S+\s+[^:]+:([[:xdigit:]]+)\s+\S+\s+0A/', $line, $m))
		{
			$ports_used[] = hexdec($m[1]);
		}
	}
	for($num = 0; $num < VNC_DISPLAY_MAX; $num++)
	{
		if(!in_array($num + VNC_PORT_BASE, $ports_used)) break;
	}
	if($num >= VNC_DISPLAY_MAX) return false;
	return $num;
}

function mk_novnc_url($ini, $prm, $url_tmp)
{
	$key = mk_novnc_key($ini, $prm);
	if(!$key)
	{
		return false;
	}

	fix_vnc_socket_acl_mask($ini['qemu']['machine_dir']."/".$prm['machine']['name']."/vnc/vnc.sock");

	return str_replace(
		array(
			"{host}",
			"{port}",
			"{path}",
			"{key}",
			"{redir_failed}",
			"{go_back_url}",
		), 
		array(
			$_SERVER['HTTP_HOST'],
			$_SERVER['SERVER_PORT'],
			$GLOBALS['ini']["webserver"]["ws_path"],
			$key,
			urlencode($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']),
			urlencode($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].$_SERVER['DOCUMENT_URI']."?act=view&name=".$prm['machine']['name']),
		),
		$url_tmp);
}

function mk_novnc_key($ini, $prm)
{
	$key = bin2hex(mcrypt_create_iv(22, MCRYPT_DEV_URANDOM));
	$dir = $ini['qemu']['machine_dir']."/".$prm['machine']['name']."/novnc_key";
	if(!installdir($dir, 0770))
	{
		add_error("directory error: $dir");
		return false;
	}
	file_put_contents("$dir/$key", '');
	return $key;
}

function fix_vnc_socket_acl_mask($path)
{
	return facl($path, "mask::rwx");
}

function get_novnc($ini, $key)
{
	$data = false;
	foreach(scandir($ini['qemu']['machine_dir']) as $vmname)
	{
		$dir = $ini['qemu']['machine_dir']."/$vmname/novnc_key";
		if(is_dir($dir))
		{
			foreach(scandir($dir) as $keyname)
			{
				$file = "$dir/$keyname";
				if(is_file($file))
				{
					if(filemtime($file) < time() - $ini['webserver']['novnc_token_expire'])
					{
						unlink($file);
					}
					elseif($key == $keyname)
					{
						$data = array(
							"host" => NULL,
							"port" => NULL,
							"socket" => $ini['qemu']['machine_dir']."/$vmname/vnc/vnc.sock",
						);
						file_put_contents($file, '', FILE_APPEND);
					}
				}
			}
		}
	}
	return $data;
}
