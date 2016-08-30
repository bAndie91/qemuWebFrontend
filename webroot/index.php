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
			"title"=>"New",
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
$run_action = true;
if(is_vmname(@$_REQUEST["name"]))
{
	$vmname = $_REQUEST["name"];
}


switch($Action)
{
case "delete":
case "power_cycle":
case "start":
case "shutdown":
case "poweroff":
case "reset":
case "pause":
case "resume":
case "start":
case "view":
case "change":
case "event":
case "refresh_screenshot":
case "download_screenshot":
case "get":
	if(!isset($vmname))
	{
		add_error("invalid name");
		$run_action = false;
	}
}


if($run_action)
{
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
					"machine" => qemu_load($ini, $vmname, array("opts"=>true, "state"=>true)),
				);
			}
			elseif(isset($_REQUEST["copy"]))
			{
				if(is_vmname($_REQUEST["copy"]) and is_vmname($_REQUEST["copy"]."-copy"))
				{
					$prm = array(
						"machine" => qemu_load($ini, $_REQUEST["copy"], array("opts"=>true, "state"=>true)),
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
				foreach(qemu_load($ini, NULL, array("opts"=>true, "state"=>true)) as $vm)
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
				if($Action == "edit" and $opt_name == 'name')
				{
					continue;
				}
				if(empty($opts))
				{
					$tmplvar["vm"]["opt"][] = array("name"=>$opt_name, "value"=>"");
				}
				else
				{
					foreach((array)$opts as $value)
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
		$tmplvar["vm"]["name"] = $vmname;
		$ok = qemu_delete($ini, array("machine"=>array("name"=>$vmname)));
		if($ok)
		{
			$Redirect = array(
				"msg" => "VM is deleted: $vmname",
				"act" => "list",
			);
		}
	break;
	case "power_cycle":
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname, array("opts"=>true)),
		);
		$reply = qemu_single_cmd($ini, $prm, "quit");
		// TODO: error handle
	case "start":
	case "restorestate":
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname, array("opts"=>true)),
		);
		if($Action == "restorestate")
		{
			$prm['machine']['opt']['incoming'] = "exec:unxz<state.xz";
		}
		$ok = qemu_start($ini, $prm);
		
		if($ok)
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
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname, array("opts"=>true)),
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
	break;
	case "savestate":
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname, array("opts"=>true)),
		);
		
		$cmds = array(
			array(
				"execute" => "stop",
			),
			array(
				"execute" => "migrate",
				"arguments" => array(
					"blk" => false,
					"uri" => sprintf("exec:xz -9 >%s/%s/state.xz", $ini["qemu"]["machine_dir"], $vmname),
				),
			),
		);
		$errfail = true;
		$reply = qemu_cmd($ini, $prm, $cmds, NULL, $errfail);
		$Redirect = array(
			"act" => "view",
			"name" => $vmname,
		);
		$last_reply = @$reply[count($reply)-1];
		if(count($reply) == count($cmds) and isset($reply['return']))
		{
			$Redirect["msg"] = "Started to save state";
		}
		else
		{
			add_error(qmp_error_to_string($last_reply['error']));
		}
	break;
	case "get":
		$load_opts = array("opts"=>true, "state"=>true);
		if(isset($_REQUEST["getinfo"]["savestate"]))
		{
			$load_opts["migrate"] = true;
		}
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname, $load_opts),
		);
		if(isset($_REQUEST["getinfo"]["screenshot"]))
		{
			$prm['machine']["screenshot"] = qemu_load_screenshot($ini, $prm);
		}
		
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
	break;
	case "view":
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname, array("opts"=>true, "state"=>true)),
		);

		if(isset($_REQUEST["getinfo"]["vnc"]))
		{
			$prm['machine']['vnc'] = qemu_info_vnc($ini, $prm);
		}
		
		if(isset($_REQUEST["getinfo"]["block"]))
		{
			$reply = qemu_single_cmd($ini, $prm, "query-block");
			$prm['machine']['block'] = $reply["return"];
		}
		
		if(isset($_REQUEST["getinfo"]["screenshot"]))
		{
			$prm['machine']["screenshot"] = qemu_load_screenshot($ini, $prm);
			
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
	break;
	case "novnc":
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);
		$url = mk_novnc_url($ini, $prm, $ini["webserver"]["novnc_url"]);
		if($url)
		{
			header("Location: $url");
			exit();
		}
		else
		{
			$Redirect = array(
				"msg" => "Error preparing noVNC",
				"act" => "view",
				"name" => $vmname,
			);
		}
	break;
	case "change":
		switch($_REQUEST["change"])
		{
		case "vnc":
			$prm = array(
				"name" => $vmname,
				"machine" => qemu_load($ini, $vmname, array("opts"=>true, "state"=>true)),
			);
			
			if(ischecked("enable"))
			{
				if($_REQUEST["family"] == "unix")
				{
					$vmdir = $ini["qemu"]["machine_dir"]."/$vmname";
					$listen_uds_dir = "$vmdir/vnc";
					$listen_uds = "$listen_uds_dir/vnc.sock";
					$listen = "unix:$listen_uds";
					qemu_mkdir_vnc($ini, $prm, $listen_uds_dir);
				}
				elseif($_REQUEST["family"] == "inet")
				{
					if(preg_match('/^[a-z0-9\._-]+$/i', @$_REQUEST["host"]))
					{
						$host = $_REQUEST["host"];
						if(isset($_REQUEST["port"])) $display = intval($_REQUEST["port"]) - VNC_PORT_BASE;
						if(isset($_REQUEST["display"])) $display = intval($_REQUEST["display"]);
						$listen = "$host:$display";
					}
					else add_error("invalid 'host' parameter");
				}
				else add_error("unknown socket family: '{$_REQUEST['family']}'");

				if(ischecked("enable_auth")) $password = $_REQUEST["password"];
				if(!empty($_REQUEST["share"])) $share = preg_replace('/[^a-z0-9_-]/i', '', $_REQUEST["share"]);
			}
			else
			{
				$listen = "";
			}
			$reply = qemu_change_vnc($ini, $prm, $listen, @$password, @$share);
			$ok = ($reply !== false);
			if($ok)
			{
				foreach($reply as $r)
				{
					if(isset($r['error']))
					{
						$ok = false;
						add_error($r['error']['class'].": ".$r['error']['desc']);
					}
				}
			}
			if($ok)
			{
				if(isset($listen_uds))
				{
					qemu_fix_vnc_socket($ini, $prm, $listen_uds);
				}
				
				$Messages[] = "VNC settings changed";
			}
			$prm['machine']['vnc'] = qemu_info_vnc($ini, $prm);

			$tmplvar["vm"] = $prm['machine'];
			$tmplvar["content_tmpl"] = "vnc";
			$tmplvar["page"]["h1"] = "View";
		break;
		default:
			add_error("not yet supported");
		}
	break;
	case "event":
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname, array("opts"=>true)),
		);
		
		switch($_REQUEST["param"]["event"])
		{
		case "key":
			$cmds = array();
			foreach($_REQUEST["param"]["keys"] as $event)
			{
				$keySeq = NULL;
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
		break;
		case "click":
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
			/* you should setup mouse in guest OS not to get accelerated (eg. "xset m 1/1 0" in Xorg) */
			$delay[] = 200;
			$cmds[] = "mouse_move $x $y";
			$delay[] = 200;
			$cmds[] = "mouse_button $btn";
			$delay[] = 200;
			$cmds[] = "mouse_button 0";
		break;
		default:
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
	break;
	case "refresh_screenshot":
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname, array("opts"=>true, "state"=>true)),
		);
		$prm['machine']["screenshot"] = qemu_load_screenshot($ini, $prm);
		
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
	break;
	case "download_screenshot":
		$ExpectedContentType = "raw";
		if(is_shid($_REQUEST["id"]))
		{
			if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
			{
				header("Status: 304");
			}
			else
			{
				$shid = $_REQUEST["id"];
				$file = $ini["qemu"]["machine_dir"]."/$vmname/screenshot/".(@$_REQUEST['is_diff']?"diff/":"").$ini["qemu"]["screenshot_prefix"].$shid.$ini["qemu"]["screenshot_suffix"].".".$ini["qemu"]["screenshot_ext"];
				header("Content-Type: image/".$ini["qemu"]["screenshot_ext"]);
				header("Content-Length: ".filesize($file));
				$maxage = 10 * 365 * 24 * 60 * 60;
				header("Pragma: cache");
				header("Cache-Control: max-age=$maxage");
				header("Expires: ".gmdate("D, d M Y H:i:s", time() + $maxage)." GMT");
				readfile($file);
			}
			exit(0);
		}
		else
		{
			header("Status: 404");
			add_error("invalid screenshot id");
		}
	break;
	case "list":
		$tmplvar["content_tmpl"] = "list";
		$tmplvar["page"]["h1"] = "Machines";
		
		$tmplvar["machines"] = qemu_load($ini, NULL, array("opts"=>false, "state"=>true, "diskusage"=>true));
	break;
	case "autocomplete":
		$ExpectedContentType = "autocompleter";
		
		$tmplvar["lines"] = runAutoComplete($ini, $_REQUEST["s"], @$_REQUEST["type"], @$_REQUEST["option"]);
	break;
	case "help":
		$tmplvar["content_tmpl"] = "raw";
		$tmplvar["page"]["h1"] = "Help";
		
		$tmplvar["page"]["content"] = "<h2>Options</h2>\n";
		$tmplvar["page"]["content"] .= "<ul>\n";
		foreach($ini["option_type"] as $opt => $optdef)
		{
			$tmplvar["page"]["content"] .= "<li>$opt</li>\n";
		}
		$tmplvar["page"]["content"] .= "</ul>\n";
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
}

/* ================================================================================ */

switch(@$ExpectedContentType)
{
case "json":
	$tmplvar["error"] = $GLOBALS["error"];
	if(isset($Redirect)) $tmplvar["redirect"] = $Redirect;
	// TODO // $tmplvar["result"] = ;
	header("Content-Type: text/plain");
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

