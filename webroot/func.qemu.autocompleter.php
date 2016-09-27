<?php

function runAutoComplete($ini, $vmname, $Pattern, $acType, $Option)
{
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

		
	if($acType == "option")
	{
		foreach($ini["option_type"] as $opt => $param_spec)
		{
			if(preg_match("/$RE/", $opt))
			{
				$return[] = array(
					'value' => $opt, 
					'data' => array(
						// "parameter_spec" => $param_spec,
						"no_parameters" => empty($param_spec),
					)
				);
			}
		}
	}
	else
	{
		$lines = array();
		if($acType == "path")
		{
			$Expr = '%f';
		}
		else
		{
			$Expr = $ini["option_type"][$Option];
		}
		$alterns = (array)$Expr;
		do {
			$unresolved = false;
			$new = array();
			foreach($alterns as $n => $tmp)
			{
				if(preg_match('/^(.*?)\[([^\[\]]*)\](.*)/', $tmp, $grp))
				{
					$unresolved = true;
					$choices = explode('|', $grp[2]);
					if(count($choices) == 1)
					{
						$new[] = $grp[1].$grp[3];
					}
					foreach($choices as $w)
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
		
		$return = $lines_unique;
	}
	
	return $return;
}

