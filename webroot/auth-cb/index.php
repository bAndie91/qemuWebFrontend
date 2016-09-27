<?php

require_once("../func.vnc.php");
include("../load_config.php");


$key = $_REQUEST["key"];
$data = get_novnc($ini, $key);

if($data)
{
	if($data['host'])
	{
		$part1 = $data['host'];
		$part2 = $data['port'];
	}
	else
	{
		$part1 = "unix";
		$part2 = $data['socket'];
	}
	
	header("Status: 200");
	header("X-Socket: $part1:$part2");
}
else
{
	header("Status: 403");
}
