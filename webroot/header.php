<?php

require_once("const.php");
require_once("func.php");
require_once("func.unix.php");
require_once("func.shell.php");
require_once("func.net.php");
require_once("func.images.php");
require_once("func.qemu.php");
require_once("func.qemu.low.php");
require_once("func.qemu.autocompleter.php");
require_once("func.spec.php");
require_once("func.vnc.php");
require_once("func.form.php");
require_once("raintpl/rain.tpl.class.php");

include("load_config.php");

//ini_set('max_execution_time', 5);
//set_time_limit(5);


if(!preg_match('/\/$/', $ini["qemu"]["raintpl_cache_dir"]))
{
	$ini["qemu"]["raintpl_cache_dir"] .= "/";
}
if(preg_match('/^(?:\.\.\/)+(.+)/', $ini["qemu"]["raintpl_cache_dir"], $grp))
{
	/* Qualify relative path manually avoiding unneccessary symlink resolving. */
	$pwd = dirname($_SERVER['SCRIPT_FILENAME']);
	$dirs = explode('/', $pwd);
	$dir = implode('/', array_slice($dirs, 0, -substr_count($ini["qemu"]["raintpl_cache_dir"], '../')));
	$ini["qemu"]["raintpl_cache_dir"] = $dir . '/' . $grp[1];
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


if(!getenv('PATH') and isset($ini["system"]["path"]))
{
	putenv('PATH='.$ini["system"]["path"]);
}
