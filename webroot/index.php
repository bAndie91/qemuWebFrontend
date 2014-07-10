<?php

session_start();

require_once("header.php");
require_once("raintpl/rain.tpl.class.php");

/* ================================================================================ */

$tmplvar = array(
	"page" => array(
		"title" => "Qemu Manager",
	),
	"main_menu" => array(
		array(
			"title"=>"List",
			"link"=>"?act=list",
		),
		array(
			"title"=>"Add",
			"link"=>"?act=new",
		),
	),
);
$GLOBALS["error"] = array();
if(isset($_SESSION["error"]))
{
	foreach($_SESSION["error"] as $str) add_error($str);
	unset($_SESSION["error"]);
}
$Messages = array();
if(isset($_SESSION["redirect_msg"]))
{
	$Messages[] = $_SESSION["redirect_msg"];
	unset($_SESSION["redirect_msg"]);
}
$is_xhr = (strtolower(@$_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
if($is_xhr) $ExpectedContentType = "json";


/* ================================================================================ */

$Action = @$_REQUEST["act"];
$vmname = NULL;
if(is_vmname(@$_REQUEST["name"]))
{
	$vmname = $_REQUEST["name"];
}

switch($Action)
{
case "new":
case "edit":
	$act_ok = false;

	if(isset($_REQUEST["submit"]))
	{
		if(isset($vmname))
		{
			$opt = array();
			foreach($_REQUEST as $key => $val)
			{
				if(preg_match('/^opt(key|val)_(\d+)$/', $key, $m))
				{
					$opt[$m[2]][$m[1]] = $val;
				}
			}
			$options = array();
			foreach($opt as $a)
			{
				if($a["key"]=="") continue;
				$options[$a["key"]][] = $a["val"];
			}
			$prm = array(
				"machine" => array(
					"name" => $vmname,
					"opt" => $options,
				),
			);
			
			if($Action == "edit")
			{
				$act_ok = qemu_save_opt($ini, $prm);
			}
			else
			{
				$act_ok = qemu_new($ini, $prm);
			}
		}
		else
		{
			add_error("invalid name");
		}
	}
	else
	{
		if($Action == "edit")
		{
			$prm = array(
				"machine" => qemu_load($ini, $vmname),
			);
		}
		elseif(isset($_REQUEST["copy"]))
		{
			if(is_vmname($_REQUEST["copy"]) and is_vmname($_REQUEST["copy"]."-copy"))
			{
				$prm = array(
					"machine" => qemu_load($ini, $_REQUEST["copy"]),
				);
				if(isset($prm["machine"]["name"]))
				{
					$prm["machine"]["name"] = $_REQUEST["copy"]."-copy";
				}
				else
				{
					add_error("could not copy, no named VM found");
				}
			}
			else
			{
				add_error("could not copy, invalid VM name");
			}
		}
		else
		{
			$new_id = 1;
			foreach(qemu_load($ini, NULL, false) as $vm)
			{
				if(preg_match('/^vm-(\d+)$/', $vm["name"], $m) and $m[1]+1 > $new_id) $new_id = $m[1]+1;
			}
			$prm = array(
				"machine" => array(
					"name" => "vm-$new_id",
					"opt" => qemu_load_opt("./options_default"),
				),
			);
		}
		
		foreach($prm['machine']['opt'] as $key => $values)
		{
			if($key == 'display' or $key == 'vnc')
			{
				$regex = '/((\d+\.){3}\d+):\*/';
				if($key == 'display') $regex = '/(vnc=(\d+\.){3}\d+):\*/';
				foreach($values as &$str)
				{
					if(preg_match($regex, $str))
					{
						$num = find_free_vnc_display_num($ini);
						if($num !== false) $str = preg_replace($regex, "\\1:$num", $str);
						else add_error("not found free tcp port for vnc");
					}
				}
			}
			$prm['machine']['opt'][$key] = $values;
		}
	}

	if($act_ok)
	{
		$Redirect = array(
			"msg" => ($Action == "edit" ? "options saved for: $vmname" : "new VM is created: $vmname"),
			"act" => "edit",
			"name" => $vmname,
		);
	}
	else
	{
		$tmplvar["vm"] = array();
		$tmplvar["vm"]["name"] = $prm['machine']['name'];
		ksort($prm['machine']['opt']);
		foreach($prm['machine']['opt'] as $opt_name => $opts)
		{
			if(empty($opts))
			{
				$tmplvar["vm"]["opt"][] = array("name"=>$opt_name, "value"=>"");
			}
			else
			{
				foreach($opts as $value)
				{
					$tmplvar["vm"]["opt"][] = array("name"=>$opt_name, "value"=>$value);
				}
			}
		}
		$tmplvar["act"] = $Action;
		
		$tmplvar["content_tmpl"] = "edit";
		$tmplvar["page"]["h1"] = ($Action == "edit" ? "Edit parameters" : "Add new");
	}
break;
case "delete":
	$tmplvar["page"]["h1"] = "Delete";
	if(isset($vmname))
	{
		$tmplvar["vm"]["name"] = $vmname;
		$ok = qemu_delete($ini, array("machine"=>array("name"=>$vmname)));
		if($ok)
		{
			$Redirect = array(
				"msg" => "VM is deleted: $vmname",
				"act" => "list",
			);
		}
	}
	else add_error("invalid name");
break;
case "start":
	$act_ok = false;
	if(isset($vmname))
	{
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);
		$act_ok = qemu_start($ini, $prm);
	}
	else
	{
		add_error("invalid name");
	}
	
	if($act_ok)
	{
		$Redirect = array(
			"msg" => "VM is started: $vmname",
			"act" => "view",
			"name" => $vmname,
		);
	}
	else
	{
		$Redirect = array(
			"act" => "list",
		);
	}
break;
case "shutdown":
case "poweroff":
case "reset":
case "pause":
case "resume":
	if(isset($vmname))
	{
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);
		
		if($Action == "poweroff")	{ $execute = "quit"; $redirect_msg = "VM is turned off"; }
		elseif($Action == "reset")	{ $execute = "system_reset"; $redirect_msg = "VM is reset"; }
		elseif($Action == "shutdown")	{ $execute = "system_powerdown"; $redirect_msg = "shutdown is initiated"; }
		elseif($Action == "pause")	{ $execute = "stop"; $redirect_msg = "VM is paused"; }
		elseif($Action == "resume")	{ $execute = "cont"; $redirect_msg = "VM is resumed"; }

		$reply = qemu_single_cmd($ini, $prm, $execute);
		if($reply !== false)
		{
			$Redirect = array(
				"act" => "view",
				"name" => $vmname,
			);
			if(isset($reply['return']))
			{
				$Redirect["msg"] = $redirect_msg;
			}
			else
			{
				add_error(print_r($reply, true));
			}
		}
		else
		{
			add_error("$Action failed");
		}
	}
break;
case "view":
	if(isset($vmname))
	{
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);

		if(isset($_REQUEST["getinfo"]["vnc"]))
		{
			$prm['machine']['vnc'] = qemu_info_vnc($ini, $prm);
		}
		
		if(isset($_REQUEST["getinfo"]["screenshot"]))
		{
			$prm['machine']["screenshot"] = qemu_load_screenshot($ini, $vmname);
			
			if(!file_exists(@$prm['machine']["screenshot"]["file"]))
			{
				if($prm['machine']["state"]["running"])
				{
					$sh = qemu_refresh_screenshot($ini, $prm);
					if($sh !== false)
					{
						$prm['machine']["screenshot"] = $sh;
					}
				}
			}
		}
		
		$tmplvar["vm"] = $prm['machine'];
		
		$tmplvar["content_tmpl"] = "view";
		$tmplvar["page"]["h1"] = "View";
		if(isset($_REQUEST["template"]) and is_alnum_str($_REQUEST["template"]))
		{
			$tmplvar["content_tmpl"] = $_REQUEST["template"];
		}
	}
	else add_error("invalid name");
break;
case "change":
	if(isset($vmname))
	{
		switch($_REQUEST["change"])
		{
		case "vnc":
			$prm = array(
				"name" => $vmname,
				"machine" => qemu_load($ini, $vmname),
			);
			
			if(isset($_REQUEST["enable"]) and strtolower($_REQUEST["enable"]) == 'on')
			{
				if(preg_match('/^[a-z0-9\._-]+$/i', @$_REQUEST["host"]))
				{
					$host = $_REQUEST["host"];
					if(isset($_REQUEST["port"])) $display = intval($_REQUEST["port"]) - 5900;
					if(isset($_REQUEST["display"])) $display = intval($_REQUEST["display"]);
					if(isset($_REQUEST["password"]) and $_REQUEST["password"] != "") $password = $_REQUEST["password"];
					if(!empty($_REQUEST["share"])) $share = preg_replace('/[^a-z0-9_-]/i', '', $_REQUEST["share"]);
				}
				else add_error("invalid 'host' parameter");
			}
			else
			{
				$host = "";
			}
			$reply = qemu_change_vnc($ini, $prm, $host, @$display, @$password, @$share);
			$ok = true;
			foreach($reply as $r)
			{
				if(isset($r['error']))
				{
					$ok = false;
					add_error($r['error']['class'].": ".$r['error']['desc']);
				}
			}
			if($ok)
			{
				$Messages[] = "VNC settings changed";
			}
			$prm['machine']['vnc'] = qemu_info_vnc($ini, $prm);

			$tmplvar["vm"] = $prm['machine'];
			$tmplvar["content_tmpl"] = "vnc";
			$tmplvar["page"]["h1"] = "View";
		break;
		}
	}
	else add_error("invalid name");
break;
case "event":
	if(isset($vmname))
	{
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);
		
		
		if($_REQUEST["param"]["event"] == "key")
		{
			$cmds = array();
			foreach($_REQUEST["param"]["keys"] as $event)
			{
				if(isset($event["keyName"]) and preg_match('/^[a-z0-9_]+$/', $event["keyName"]))
				{
					$keySeq = $event["keyName"];
				}
				else
				{
					$keyCode = $event["keyCode"];
					if(isset($ini["keys"][$keyCode]))
					{
						$keySeq = $ini["keys"][$keyCode];
					}
					elseif(is_alnum($keyCode))
					{
						$keySeq = chr($keyCode);
						if(preg_match('/[A-Z]/', $keySeq))
						{
							$keySeq = strtolower($keySeq);
							$event["shift"] = true;
						}
					}
				}
				if(isset($keySeq))
				{
					foreach(array("alt", "alt_r", "altgr", "altgr_r", "ctrl", "ctrl_r", "shift", "shift_r") as $mod)
					{
						if(@$event[$mod]) $keySeq = "$mod-$keySeq";
					}
					$cmds[] = "sendkey $keySeq";
				}
			}
		}
		elseif($_REQUEST["param"]["event"] == "click")
		{
			$x = intval($_REQUEST["param"]["pos"]["x"]);
			$y = intval($_REQUEST["param"]["pos"]["y"]);
			$btn = 0;
			if(@$_REQUEST["param"]["button"]["left"])   $btn |= 1;
			if(@$_REQUEST["param"]["button"]["middle"]) $btn |= 2;
			if(@$_REQUEST["param"]["button"]["right"])  $btn |= 4;

			$delay = array();
			/* it hopefully moves cursor to upper left corner */
			$delay[] = 0;
			$cmds[] = "mouse_move -1000 -1000";
			/* you should setup mouse in guest OS not to get accelerated (eg. "xset m 1/1 0" in X11) */
			$delay[] = 200;
			$cmds[] = "mouse_move $x $y";
			$delay[] = 200;
			$cmds[] = "mouse_button $btn";
			$delay[] = 200;
			$cmds[] = "mouse_button 0";
		}
		else
		{
			add_error("unknown event");
		}
		
		if(!empty($cmds))
		{
			$reply = qemu_human_cmd($ini, $prm, $cmds);
			if($reply === false)
			{
				add_error("dispatch failed");
			}
			$tmplvar["debug"]["cmds"] = $cmds;
			$tmplvar["debug"]["reply"] = $reply;
		}
	}
break;
case "refresh_screenshot":
	if(isset($vmname))
	{
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);
		$prm['machine']["screenshot"] = qemu_load_screenshot($ini, $vmname);
		
		if($prm['machine']["state"]["running"])
		{
			if(is_shid(@$_REQUEST["prev_id"]))
			{
				$prev_id = $_REQUEST["prev_id"];
			}
			$sh = qemu_refresh_screenshot($ini, $prm, @$prev_id);
			if($sh !== false)
			{
				$prm['machine']["screenshot"] = $sh;
			}
		}
		else
		{
			add_error("VM is not running");
		}
		
		$tmplvar["vm"] = $prm['machine'];
	}
	else add_error("invalid name");
break;
case "download_screenshot":
	$ExpectedContentType = "raw";
	if(isset($vmname))
	{
		if(is_shid($_REQUEST["id"]))
		{
			$shid = $_REQUEST["id"];
			$file = $ini["qemu"]["machine_dir"]."/$vmname/screenshot/".(@$_REQUEST['is_diff']?"diff/":"").$ini["qemu"]["screenshot_prefix"].$shid.$ini["qemu"]["screenshot_suffix"].".".$ini["qemu"]["screenshot_ext"];
			$cmd = execve("file", array("-ib", $file));
			header("Content-Type: ".trim($cmd["stdout"]));
			header("Content-Length: ".filesize($file));
			readfile($file);
			exit(0);
		}
		else add_error("invalid screenshot id");
	}
	else add_error("invalid name");
break;
case "list":
	$tmplvar["content_tmpl"] = "list";
	$tmplvar["page"]["h1"] = "Machines";
	
	$tmplvar["machines"] = qemu_load($ini);
break;
case "get":
	if($vmname)
	{
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);
		$prm['machine']["screenshot"] = qemu_load_screenshot($ini, $vmname);

		// TODO: batch api calls
				
		if(@$_REQUEST["refresh_screenshot_if_running"])
		{
			if($prm['machine']["state"]["running"])
			{
				if(is_shid(@$_REQUEST["prev_id"]))
				{
					$prev_id = $_REQUEST["prev_id"];
				}
				$sh = qemu_refresh_screenshot($ini, $prm, @$prev_id);
				if($sh !== false)
				{
					$prm['machine']["screenshot"] = $sh;
				}
			}
		}

		$tmplvar["vm"] = $prm["machine"];
	}
	else add_error("invalid name");
break;
case "autocomplete":
	$ExpectedContentType = "autocompleter";
	$Pattern = $_REQUEST["s"];
	$REprepend = '';
	$REappend = '';
	if(preg_match('/^\^/', $Pattern))
	{
		$Pattern = substr($Pattern, 1);
		$REprepend = '^';
	}
	if(preg_match('/\$$/', $Pattern))
	{
		$Pattern = substr($Pattern, 0, strlen($Pattern)-1);
		$REappend = '$';
	}
	$RE = $REprepend . str_replace('\*', '.*?', preg_quote($Pattern, '/')) . $REappend;

		
	if(@$_REQUEST["type"] == "option")
	{
		foreach(array_keys($ini["option_type"]) as $str)
		{
			if(preg_match("/$RE/", $str)) $tmplvar["lines"][] = $str;
		}
	}
	else
	{
		$lines = array();
		$Expr = $ini["option_type"][$_REQUEST["option"]];
		$alterns = (array)$Expr;
		do {
			$unresolved = false;
			$new = array();
			foreach($alterns as $n => $tmp)
			{
				if(preg_match('/^(.*?)\[([^\[\]]*)\](.*)/', $tmp, $grp))
				{
					$unresolved = true;
					foreach(explode('|', $grp[2]) as $w)
					{
						$new[] = $grp[1].$w.$grp[3];
					}
				}
				else
				{
					$new[] = $tmp;
				}
			}
			$alterns = $new;
		}
		while($unresolved);

		if(!empty($Expr))
		{
			$lines[] = array(
				'value' => "<span class='option_prototype'>$Expr</span>",
				'data' => array(
					"raw_value" => $Expr
				),
			);
		}

		$psubs = explode(',', $Pattern);
		foreach($alterns as $expr_line)
		{
			$add_lines = array(array());
			$trailing_comma = array();
			
			$lsubs = explode(',', $expr_line);
			foreach($lsubs as $n => $lsub)
			{
				$psub = @$psubs[$n];
				if($n+1 < count($psubs))
				{
					$lsub_re = preg_quote($lsub, '/');
					$lsub_re = preg_replace_callback(
						'/%(\d*)([xsdfp])/',
						function($grp)
						{
							if($grp[2] == 'x')	return ')([[:xdigit:]]{'.$grp[1].'})(';
							elseif($grp[2] == 'd')	return ')([0-9]+)(';
							else /* s,f,p */	return ')(.+)(';
						},
						$lsub_re);
					$lsub_re = preg_replace_callback(
						'/\\\\\{(.*?)\\\\\}/', /* square brackets are escaped earlier */
						function($grp)
						{
							return ')(['.$grp[1].']+)(';
						},
						$lsub_re);
					$lsub_re = "($lsub_re)";
					
					if(preg_match("/^$lsub_re$/", $psub))
					{
						$label = preg_replace_callback(
							"/^$lsub_re$/",
							function($grp)
							{
								unset($grp[0]);
								foreach($grp as $n => &$g)
								{
									/* a non-literal string component (even empty) follows a literal and so on */
									if($n % 2 == 0) $g = "<span class=\"ac_nonliteral\">".$g."</span>";
								}
								return implode('', $grp);
							},
							$psub);
						$add_lines[0]['label'][] = $label;
						$add_lines[0]['value'][] = $psub;
					}
					else
					{
						continue(2);
					}
				}
				elseif($n+1 == count($psubs))
				{
					/* file and path name completion */
					if(preg_match('/^(.*?)%([fp])/', $lsub, $grp))
					{
						$str1 = substr($lsub, 0, strlen($grp[1]));
						$given = substr($psub, strlen($grp[1]));
						
						autocomplete_search_files:
						$absolute = (substr($given, 0, 1) == '/');
						if(!$absolute)
						{
							$pwd = getcwd();
							$chdir_ok = chdir($ini['qemu']["machine_dir"]."/$vmname/");
						}
						$glob_flags = GLOB_MARK;
						if($grp[2] == 'p') $glob_flags |= GLOB_ONLYDIR;
						$glob = glob($given.'*', $glob_flags);
						if(!$absolute and $chdir_ok)
						{
							chdir($pwd);
						}
						
						if(count($glob) == 1 and substr($glob[0], -1) == "/")
						{
							$given = $glob[0];
							goto autocomplete_search_files;
						}
						
						$current_line = $add_lines[0];
						foreach($glob as $n => $path1)
						{
							$add_lines[$n] = $current_line;
							list($dirname, $basename) = array_values(pathinfo($path1));
							if(substr($dirname, -1) != "/") $dirname .= "/";
							if($dirname == "./") $dirname = "";	// do not indicate working directory
							if(substr($path1, -1) == "/") $basename .= "/";	// pathinfo removes trailing slash
							$add_lines[$n]['label'][] = $str1."<span class=\"ac_path\">$dirname</span><span class=\"ac_file\">$basename</span>";
							$add_lines[$n]['value'][] = $str1.$path1;
						}
					}
					else
					{
						$add_lines[0]['label'][] = preg_replace_callback(
							'/(%\d*[a-z]|\{[^\{\}]+\})/i',
							function($grp)
							{
								return "<span class=\"ac_wildcard\">".$grp[1]."</span>";
							},
							$lsub);
						$add_lines[0]['value'][] = $lsub;
					}
				}
				else
				{
					$trailing_comma[$n] = true;
					goto autocomplete_add_lines;
				}
			}
			
			autocomplete_add_lines:
			foreach($add_lines as $n => $add_line)
			{
				$lines[] = array(
					'value' => implode(',', $add_line['label']) . (@$trailing_comma[$n] ? "," : ""),
					'data'  => array(
						'raw_value' => implode(',', $add_line['value']) . (@$trailing_comma[$n] ? "," : ""),
					),
				);
			}
		}
		
		$unique_values = array();
		$lines_unique = array();
		foreach($lines as $n => $line)
		{
			if(is_array($line)) $value = $line['value'];
			else $value = $line;
			
			if(in_array($value, $unique_values)) continue;
			$unique_values[] = $value;
			$lines_unique[] = $line;
		}

		$tmplvar["lines"] = $lines_unique;
	}
break;
default:
	if($is_xhr)
	{
		add_error("invalid action");
	}
	else
	{
		$Redirect = array(
			"act" => "list",
		);
	}
}


/* ================================================================================ */

switch(@$ExpectedContentType)
{
case "json":
	$tmplvar["error"] = $GLOBALS["error"];
	if(isset($Redirect)) $tmplvar["redirect"] = $Redirect;
	// TODO // $tmplvar["result"] = ;
	echo json_encode($tmplvar);
break;
case "lines":
	header("Content-Type: text/plain");
	echo implode("\n", array_map(function($a){ return rawurlencode(is_array($a) ? $a['value'] : $a); }, $tmplvar["lines"]));
break;
case "autocompleter":
	echo json_encode($tmplvar["lines"]);
break;
case "raw":
	header("Content-Type: text/plain");
	echo implode("\n", $GLOBALS["error"]);
break;
default:
	if(isset($Redirect))
	{
		header("Location: ".$_SERVER["SCRIPT_NAME"]."?act=".$Redirect["act"].(isset($Redirect["name"])?("&name=".$Redirect["name"]):""));
		$_SESSION["error"] = $GLOBALS["error"];
		$_SESSION["redirect_msg"] = $Redirect["msg"];
	}
	else
	{
		foreach($tmplvar as $key => $val) $tmpl->assign($key, $val);
		$tmpl->assign("messages", $Messages);
		$tmpl->assign("errors", $GLOBALS["error"]);
		$tmpl->draw("index");
	}
}

