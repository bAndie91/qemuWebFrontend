<?php

$ini = parse_ini_file(__DIR__."/config.ini", true, INI_SCANNER_RAW);
$ini["system"]["acceptable_socket_errors"] = explode(',', $ini["system"]["acceptable_socket_errors"]);
