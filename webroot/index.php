<?php

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
$is_xhr = (strtolower(@$_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');


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
		$tmplvar["content_tmpl"] = "redirect";
		$tmplvar["redirect"] = array(
			"act" => "edit",
			"name" => $prm['machine']["name"],
			"msg" => ($Action == "edit" ? "options saved for: $vmname" : "new VM is created: $vmname"),
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
			$tmplvar["content_tmpl"] = "redirect";
			$tmplvar["redirect"] = array(
				"msg" => "VM is deleted: $vmname",
				"act" => "list",
			);
		}
	}
	else
	{
		add_error("invalid name");
	}
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
	
	$tmplvar["content_tmpl"] = "redirect";
	if($act_ok)
	{
		$tmplvar["redirect"] = array(
			"msg" => "VM is started: $vmname",
			"act" => "view",
			"name" => $vmname,
		);
	}
	else
	{
		$tmplvar["redirect"] = array(
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
			$tmplvar["content_tmpl"] = "redirect";
			$tmplvar["redirect"] = array(
				"act" => "view",
				"name" => $vmname,
			);
			if(isset($reply['return']))
			{
				$tmplvar["redirect"]["msg"] = $redirect_msg;
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
		
		$cmds = array();
		if(isset($prm['machine']["vncpass"]))
		{
			$cmds[] = array(
				"execute" => "change",
				"arguments" => array(
					"device" => "vnc",
					"target" => "password",
					"arg" => $prm['machine']["vncpass"],
				),
			);
		}
		$reply = qemu_cmd($ini, $prm, $cmds);

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
		
		$tmplvar["vm"] = $prm['machine'];
		
		$tmplvar["content_tmpl"] = "view";
		$tmplvar["page"]["h1"] = "View";
	}
	else
	{
		add_error("invalid name");
	}
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
				$keyCode = $event["keyCode"];
				if(isset($ini["keys"][$keyCode])) $keySeq = $ini["keys"][$keyCode];
				elseif(is_alnum($keyCode)) $keySeq = chr($keyCode);
				if(isset($keySeq))
				{
					foreach(array("ctrl", "alt", "shift") as $mod)
					{
						if(@$event[$mod."Key"] == "true") $keySeq = "$mod-$keySeq";
					}
					$cmds[] = "sendkey $keySeq";
				}
			}
		}
		else
		{
			add_error("unknown event");
		}
		
		if(!empty($cmds))
		{
			$reply = qemu_human_cmd($ini, $prm, $cmds);
			if($reply !== false)
			{
			}
			else
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
		
		if($prm['machine']["state"]["running"])
		{
			$sh = qemu_refresh_screenshot($ini, $prm);
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
	else
	{
		add_error("invalid name");
	}
break;
case "list":
	$tmplvar["content_tmpl"] = "list";
	$tmplvar["page"]["h1"] = "Machines";
	
	$tmplvar["machines"] = qemu_load($ini);
break;
case "get":
	if($vmname)
	{
		$tmplvar["vm"] = qemu_load($ini, $vmname);
	}
	else
	{
		add_error("invalid name");
	}
break;
default:
	if($is_xhr)
	{
		add_error("invalid action");
	}
	else
	{
		$tmplvar["content_tmpl"] = "redirect";
		$tmplvar["redirect"] = array(
			"act" => "list",
			"msg" => "See VM list",
		);
	}
}


/* ================================================================================ */

if($is_xhr)
{
	$tmplvar["error"] = $GLOBALS["error"];
	// TODO // $tmplvar["result"] = ;
	echo json_encode($tmplvar);
}
else
{
	foreach($tmplvar as $key => $val) $tmpl->assign($key, $val);
	$tmpl->assign("errors", $GLOBALS["error"]);
	$tmpl->draw("index");
}

