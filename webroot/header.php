<?php

require_once("const.php");
require_once("func.php");
require_once("func.spec.php");
require_once("func.qemu.php");
require_once("raintpl/rain.tpl.class.php");


$ini = parse_ini_file("config.ini", true);


raintpl::configure("base_url", NULL);
raintpl::configure("tpl_dir", "template/");
raintpl::configure("cache_dir", "cache/");

$tmpl = new RainTPL;

