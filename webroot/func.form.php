<?php

function ischecked($input_name)
{
	return(isset($_REQUEST[$input_name]) and strtolower($_REQUEST[$input_name]) == 'on');
}
