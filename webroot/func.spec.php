<?php

function parse_opt($a)
{
	$return = array();
	foreach($a as $opt_name => $opt)
	{
		$return[$opt_name] = array();
		foreach($opt as $o1)
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

function find_free_vnc_display_num($ini)
{
	$ports_used = array();
	foreach(glob($ini['qemu']['machine_dir']."/*/options/display") as $file)
	{
		foreach(file($file) as $str)
		{
			if(preg_match('/vnc=(?:\d+\.)\d+:(\d+)/', $str, $m))
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

