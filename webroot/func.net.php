<?php

function get_LANs()
{
	$cmd = execve("ip", array("address", "show"));
	foreach(explode("\n", $cmd["stdout"]) as $line)
	{
		if(preg_match('/inet\s+([0-9\.]+)\/([0-9]+).*?(\S+)$/', $line, $m))
		{
			$LANs[] = array(
				"address" => $m[1],
				"mask" => $m[2],
				"iface" => $m[3],
			);
		}
	}
	return $LANs;
}

function get_LAN_for_address($addr, $LANs = NULL)
{
	if(!isset($LANs)) $LANs = get_LANs();
	foreach($LANs as $lan)
	{
		if(in_IPv4_space($addr, $lan["address"], $lan["mask"])) return $lan;
	}
	return false;
}

function in_IPv4_space($addr, $space_addr, $mask)
{
	$ADDR_LEN = 32;
	
	$address = ip2long($addr);
	$subnet = ip2long($space_addr);
	$subnet_len = $ADDR_LEN - $mask;
	return($mask == 0 or ($subnet & (0xFFFFFFFF << $subnet_len)) == ($address & (0xFFFFFFFF << $subnet_len)));
}

