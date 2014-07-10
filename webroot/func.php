<?php

/*
function dfpc($str)
{
	file_put_contents("/tmp/qd", $str."\n", FILE_APPEND);
}
*/

function add_error($str, $level = NULL)
{
	if(!empty($str))
	{
		$GLOBALS['error'][] = $str;
	}
}

function is_alnum($ord)
{
	return(($ord >= 0x30 and $ord <= 0x39) or ($ord >= 0x41 and $ord <= 0x5A) or ($ord >= 0x61 and $ord <= 0x7A));
	
}

function is_alnum_str($str)
{
	return(preg_match('/^[a-z0-9_]+$/i', $str));
	
}

