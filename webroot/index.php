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


/* ================================================================================ */

switch(@$_REQUEST["act"])
{
case "new":
case "edit":
	$act_ok = false;

	if(is_vmname(@$_REQUEST["name"]))
	{
		$vmname = $_REQUEST["name"];
	}
	else
	{
		if(isset($_REQUEST["name"])) add_error("invalid VM name");
		$vmname = "";
	}
	
	if(isset($_REQUEST["submit"]))
	{
		$opt = array();
		foreach($_REQUEST as $key => $val)
		{
			if(preg_match('/^opt(key|val)_(\d+)$/', $key, $m))
			{
				if(!isset($opt[$m[2]])) $opt[$m[2]] = array();
				$opt[$m[2]][$m[1]] = $val;
			}
		}
		$options = array();
		foreach($opt as $a)
		{
			if($a["key"]=="") continue;
			if(!isset($options[$a["key"]])) $options[$a["key"]] = array();
			$options[$a["key"]][] = $a["val"];
		}
		$prm = array(
			"machine" => array(
				"name" => $vmname,
				"opt" => $options,
			),
		);
		
		if($_REQUEST["act"] == "edit")
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
		if($_REQUEST["act"] == "edit")
		{
			$prm = array(
				"machine" => qemu_load($ini, $vmname),
			);
		}
		elseif(isset($_REQUEST["copy"]))
		{
			if(is_vmname($_REQUEST["copy"]))
			{
				$prm = array(
					"machine" => qemu_load($ini, $_REQUEST["copy"]),
				);
				if(isset($prm["machine"]["name"]))
				{
					$prm["machine"]["name"] .= "-copy";
				}
				else
				{
					add_error("no named VM found");
				}
			}
			else
			{
				add_error("invalid VM name");
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
		
		foreach($prm['machine']['opt'] as $key => $value)
		{
			if($key == 'display' or $key == 'vnc')
			{
				$regex = '/((\d+\.){3}\d+):\*/';
				if($key == 'display') $regex = '/(vnc=(\d+\.){3}\d+):\*/';
				foreach($value as &$str)
				{
					if(preg_match($regex, $str))
					{
						$num = find_free_vnc_display_num($ini);
						if($num !== false) $str = preg_replace($regex, "\\1:$num", $str);
						else add_error("not found free tcp port for vnc");
					}
				}
			}
			$prm['machine']['opt'][$key] = $value;
		}
	}

	if($act_ok)
	{
		$tmplvar["content_tmpl"] = "redirect";
		$tmplvar["redirect"] = array(
			"location" => "?act=view&name=".$prm['machine']["name"],
		);
		$tmplvar["redirect"]["msg"] = ($_REQUEST["act"] == "edit" ? "options saved for: $vmname" : "new VM is created: $vmname");
	}
	else
	{
		$tmplvar["vm"] = array();
		$tmplvar["vm"]["name"] = $prm['machine']['name'];
		ksort($prm['machine']['opt']);
		foreach($prm['machine']['opt'] as $opt_name => $opts)
		{
			foreach($opts as $value)
			{
				$tmplvar["vm"]["opt"][] = array("name"=>$opt_name, "value"=>$value);
			}
		}
		$tmplvar["act"] = $_REQUEST["act"];
		
		$tmplvar["content_tmpl"] = "edit";
		$tmplvar["page"]["h1"] = ($_REQUEST["act"] == "edit" ? "Edit VM" : "Add new VM");
	}
break;
case "delete":
	if(is_vmname($_REQUEST["name"]))
	{
		$vmname = $_REQUEST["name"];
		$ok = qemu_delete($ini, array("machine"=>array("name"=>$vmname)));
		if($ok)
		{
			$tmplvar["content_tmpl"] = "redirect";
			$tmplvar["redirect"] = array(
				"msg" => "VM is deleted: $vmname",
				"location" => "?act=list",
			);
		}
	}
	else
	{
		add_error("VM name is invalid");
	}
break;
case "start":
	$act_ok = false;
	if(is_vmname(@$_REQUEST["name"]))
	{
		$vmname = $_REQUEST["name"];
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);
		$act_ok = qemu_start($ini, $prm);
	}
	else
	{
		add_error("invalid VM name");
	}
	
	$tmplvar["content_tmpl"] = "redirect";
	if($act_ok)
	{
		$tmplvar["redirect"] = array(
			"msg" => "VM is started: $vmname",
			"location" => "?act=view&name=$vmname",
		);
	}
	else
	{
		$tmplvar["redirect"] = array(
			"location" => "?act=list",
		);
	}
break;
case "shutdown":
break;
case "poweroff":
case "reset":
	if(is_vmname($_REQUEST["name"]))
	{
		$vmname = $_REQUEST["name"];
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);
		
		$cmds = array(
		  array(
		    "execute"=>($_REQUEST["act"]=="poweroff" ? "quit" : "system_reset"),
		  ),
		);
		$reply = qemu_cmd($ini, $prm, $cmds);
		if($reply !== false)
		{
			$reply = $reply[0];
			$tmplvar["content_tmpl"] = "redirect";
			$tmplvar["redirect"] = array(
				"location" => "?act=list",
			);
			if(isset($reply['return']))
			{
				$tmplvar["redirect"]["msg"] = "VM is ".($_REQUEST["act"]=="poweroff" ? "powered off" : "reset").": $vmname";
			}
			else
			{
				add_error(print_r($reply, true));
			}
		}
		else
		{
			add_error(($_REQUEST["act"]=="poweroff" ? "poweroff" : "reset")." failed");
		}
	}
break;
case "view":
	if(is_vmname($_REQUEST["name"]))
	{
		$vmname = $_REQUEST["name"];
		$prm = array(
			"name" => $vmname,
			"machine" => qemu_load($ini, $vmname),
		);
		
		$vncpass = rand(0,100000000);
		if(isset($prm['machine']["vncpass"])) $vncpass = $prm['machine']["vncpass"];
		$cmds = array(
		  array(
		    "execute"=>"change",
		    "arguments"=>array(
		      "device"=>"vnc",
		      "target"=>"password",
		      "arg"=>$vncpass,
		    ),
		  ),
		);
		$reply = qemu_cmd($ini, $prm, $cmds);

		$tmplvar["content_tmpl"] = "view";
		$tmplvar["page"]["h1"] = $vmname;
	}
	else
	{
		add_error("VM name is invalid");
	}
break;
case "list":
default:
	$tmplvar["content_tmpl"] = "list";
	$tmplvar["page"]["h1"] = "Machines";
	
	$machines = qemu_load($ini);
	$tmplvar["machines"] = $machines;
}


/* ================================================================================ */

foreach($tmplvar as $key => $val) $tmpl->assign($key, $val);
if(isset($GLOBALS["error"])) $tmpl->assign("errors", $GLOBALS["error"]);
$output = $tmpl->draw("index", $return_string = true);
echo $output;

