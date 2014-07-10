<?php

require_once("const.php");
require_once("func.php");
require_once("func.shell.php");
require_once("func.net.php");
require_once("func.images.php");
require_once("func.qemu.php");
require_once("func.spec.php");
require_once("raintpl/rain.tpl.class.php");


//ini_set('max_execution_time', 5);
//set_time_limit(5);


$ini = parse_ini_file("config.ini", true, INI_SCANNER_RAW);

if(!preg_match('/\/$/', $ini["qemu"]["raintpl_cache_dir"]))
{
	$ini["qemu"]["raintpl_cache_dir"] .= "/";
}
if(!is_dir($ini["qemu"]["raintpl_cache_dir"]))
{
	$ok = mkdir($ini["qemu"]["raintpl_cache_dir"]);
	if(!$ok)
	{
		die("mkdir(".$ini["qemu"]["raintpl_cache_dir"].") failed");
	}
}

raintpl::configure("base_url", NULL);
raintpl::configure("tpl_dir", "template/");
raintpl::configure("cache_dir", $ini["qemu"]["raintpl_cache_dir"]);

$tmpl = new RainTPL;

