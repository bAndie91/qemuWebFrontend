
$.fn.extend({
	disable: function()
	{
		return this.each(function()
		{
			this.disabled = true;
		});
	},
	enable: function()
	{
		return this.each(function()
		{
			this.disabled = false;
		});
	}
});


qemu = {
	call: function(post, args, cb_success, cb_fail)
	{
		$.post("index.php", post,
			function(responseText, textStatus, xhr)
			{
				if(typeof cb_success == 'function')
				{
					var json;
					try { json = JSON.parse(responseText); }
					catch(exception) { json = false; }
					cb_success(responseText, textStatus, xhr, json, args);
				}
			}
		)
		.fail(function(xhr)
			{
				if(typeof cb_fail == 'function')
				{
					cb_fail(xhr, args);
				}
			}
		);
	},
	
	refresh_screenshot: function(vmobj, element)
	{
		var vmname = vmobj.name;
		
		this.call({
			act: "refresh_screenshot",
			name: vmname,
			prev_id: (vmobj.screenshot && vmobj.screenshot.id ? vmobj.screenshot.id : ''),
		},
		{
			element: element,
		},
		function(responseText, textStatus, xhr, json, a)
		{
			if(json.error && json.error.length > 0)
			{
				alert(json.error.join("\n"));
			}
			else
			{
				qemu.set_screenshot(vmobj, a.element, json);
			}
		},
		function(xhr, a)
		{
			a.element.show.hide();
			a.element.hide.show();
			alert("screenshot failed");
		});
	},
	
	set_screenshot: function(vmobj, element, json)
	{
		if(json.vm.screenshot)
		{
			var dtime = new Date(json.vm.screenshot.timestamp * 1000);
			element.screenshot.time.text(dtime.toString());

			element.screenshot.img.load(function()
			{
				vmobj.screenshot = json.vm.screenshot;
			});
			var img0 = element.screenshot.img[0];
			
			if(json.vm.screenshot.difference != undefined &&
			   json.vm.screenshot.difference.size < json.vm.screenshot.size)
			{
				var diff_img = $('<img>');
				diff_img.hide();
				$('body').append(diff_img);
				
				diff_img.load(function()
				{
					var cnv0 = document.createElement('canvas');
					cnv0.style.display = 'none';
					document.body.appendChild(cnv0);
					
					var ctx0 = cnv0.getContext('2d');
					cnv0.width = img0.width;
					cnv0.height = img0.height;
					ctx0.drawImage(img0, 0, 0);	

					var cnv1 = document.createElement('canvas');
					cnv1.style.display = 'none';
					document.body.appendChild(cnv1);
					
					var ctx1 = cnv1.getContext('2d');
					cnv1.width = this.width;
					cnv1.height = this.height;
					ctx1.drawImage(this, 0, 0);
					
					var imgdata0 = ctx0.getImageData(0, 0, cnv0.width, cnv0.height);
					var imgdata1 = ctx1.getImageData(0, 0, cnv1.width, cnv1.height);
					
					var method = json.vm.screenshot.difference.method.toLowerCase();
					for(var idx=0; idx < imgdata0.data.length; idx++)
					{
						if(method == 'modulussubtract')
						{
							if(imgdata1.data[idx] == 0) continue;
							var b = imgdata0.data[idx] + imgdata1.data[idx];
							var max = (idx%4==3) ? 128 : 256;
							if(b >= max)	imgdata0.data[idx] = Math.abs(max - b);
							else		imgdata0.data[idx] = b;
						}
						else if(method == 'xor')
						{
							if(idx%4==3) continue; // bypass alpha channel
							imgdata0.data[idx] = imgdata0.data[idx] ^ imgdata1.data[idx];
						}
					}
					ctx0.putImageData(imgdata0, 0, 0);
					
					img0.src = cnv0.toDataURL();
					document.body.removeChild(cnv0);
					document.body.removeChild(cnv1);
					diff_img.remove();
				});
				
				diff_img.attr('src', '?act=download_screenshot&name=' + json.vm.name + '&is_diff=1&id=' + json.vm.screenshot.difference.id);
			}
			else
			{
				element.screenshot.img.attr('src', '?act=download_screenshot&name=' + json.vm.name + '&id=' + json.vm.screenshot.id);
			}
			
			element.screenshot.show.show();
			element.screenshot.hide.hide();
		}
		else
		{
			element.screenshot.show.hide();
			element.screenshot.hide.show();
		}
	},
	
	act: function(act, param, vmname)
	{
		this.call({
			act: act,
			name: vmname,
			param: param,
		},
		{},
		function(responseText, textStatus, xhr, json, a)
		{
			if(json.error && json.error.length > 0)
			{
				alert(json.error.join("\n"));
			}
		});
	},
	
	refresh_state: function(vmobj, element, param)
	{
		element.progress_indicator.show();
		
		var post = {
			act: 'get',
			name: vmobj.name,
		};
		if(param && param.refresh_screenshot)
		{
			post.refresh_screenshot_if_running = true;
			post.prev_id = (vmobj.screenshot && vmobj.screenshot.id ? vmobj.screenshot.id : '');
		}
		
		this.call(
			post,
			element,
			function(responseText, textStatus, xhr, json, element)
			{
				// for(var prop in vmobj) delete(vmobj[prop]);
				for(var prop in json.vm) vmobj[prop] = json.vm[prop];
				
				if(json.vm.state.running)
				{
					element.state_on.show();
					element.state_off.hide();
	
					element.enable_on.enable();
					element.enable_off.disable();
					
					if(json.vm.state.paused)
					{
						element.state_paused.show();
						element.state_unpaused.hide();
	
						element.enable_paused.enable();
						element.enable_unpaused.disable();
					}
					else
					{
						element.state_paused.hide();
						element.state_unpaused.show();
	
						element.enable_paused.disable();
						element.enable_unpaused.enable();
					}
				}
				else
				{
					element.state_on.hide();
					element.state_off.show();
					element.state_paused.hide();
					element.state_unpaused.hide();
					
					element.enable_on.disable();
					element.enable_off.enable();
				}
	
				qemu.set_screenshot(vmobj, element, json);
	
				element.progress_indicator.hide();
			}
		);
	}
}

