<?php

function imagecreatefromany($file)
{
	return imagecreatefromstring(file_get_contents($file));
}

function imagesavetoany($img, $file)
{
	$pathinfo = pathinfo($file);
	if(!is_dir($pathinfo['dirname']))
	{
		mkdir_recursive($pathinfo['dirname']);
	}
	
	$ok = false;
	switch(strtolower($pathinfo['extension']))
	{
	case "gif":
		$ok = imagegif($img, $file);
	break;
	case "jpeg":
		$ok = imagejpeg($img, $file, 90);
	break;
	case "png":
	default:
		$ok = imagepng($img, $file, 9);
	break;
	}
	
	return $ok;
}

function imagetransformation(&$img_a, &$img_b, $callback)
{
	$width = imagesx($img_a);
	$height = imagesy($img_a);

	$img_c = imagecreatetruecolor($width, $height);
	imagealphablending($img_c, false);
	imagesavealpha($img_c, true);
	
	for($y=0; $y<$height; $y++)
	{
		for($x=0; $x<$width; $x++)
		{
			$px_a = imagecolorsforindex($img_a, imagecolorat($img_a, $x, $y));
			$px_b = imagecolorsforindex($img_b, imagecolorat($img_b, $x, $y));
			
			foreach(array('red', 'green', 'blue', 'alpha') as $chan)
			{
				$px_c[$chan] = $callback($chan, $px_a[$chan], $px_b[$chan]);
			}
			
			imagesetpixel($img_c, $x, $y, imagecolorallocatealpha($img_c, $px_c['red'], $px_c['green'], $px_c['blue'], $px_c['alpha']));
		}
	}
	
	return $img_c;
}

function img_compare($file_a, $file_b, $file_c)
{
	switch(strtolower(@$GLOBALS['ini']['qemu']['image_comparasion_method']))
	{
	case "modulussubtract":
		/*
		$cmd = execve("composite", array($file_a, $file_b, "-compose", "ModulusSubtract", $file_c));
		if($cmd["code"] != 0)
		{
			add_error("comparasion failed, ".$cmd["stderr"]);
			return false;
		}
		else return true;
		*/

		$img_a = imagecreatefromany($file_a);
		$img_b = imagecreatefromany($file_b);
		
		$img_c = imagetransformation($img_a, $img_b,
			function($chan, $px_a, $px_b)
			{
				$px_c = $px_b - $px_a;
				if($px_c < 0) $px_c = ($chan == 'alpha' ? 128 : 256) + $px_c;
				return $px_c;
			});
		return imagesavetoany($img_c, $file_c);
	break;
	case "xor":
		$cmd = execve("convert", array("-evaluate-sequence", "xor", $file_a, $file_b, $file_c));
		if($cmd["code"] != 0)
		{
			add_error("comparasion failed, ".$cmd["stderr"]);
			return false;
		}
		else return true;
		
		/*
		$img_a = imagecreatefromany($file_a);
		$img_b = imagecreatefromany($file_b);
		
		$img_c = imagetransformation($img_a, $img_b,
			function($chan, $px_a, $px_b)
			{
				return $px_a ^ $px_b;
			});
		return imagesavetoany($img_c, $file_c);
		*/
	break;
	default:
		return false;
	}
}
