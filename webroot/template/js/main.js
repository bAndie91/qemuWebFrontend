
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
	
	screenshot: function(vmobj, element)
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
				if(json.vm.screenshot.timestr != undefined)
				{
					a.element.time.text(json.vm.screenshot.timestr);
				}
				else
				{
					a.element.time.text(json.vm.screenshot.time);
				}

				a.element.img.load(function()
				{
					vmobj.screenshot = json.vm.screenshot;
				});
				var img0 = a.element.img[0];
				
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
						for(var idx=0; idx < imgdata0.data.length; idx++)
						{
							if(imgdata1.data[idx] == 0) continue;
							var b = imgdata0.data[idx] + imgdata1.data[idx];
							var max = (idx%4==3) ? 127 : 255;
							if(b > max)
							{
								imgdata0.data[idx] = Math.abs(max + 1 - b);
							}
							else
							{
								imgdata0.data[idx] = b;
							}
						}
						ctx0.putImageData(imgdata0, 0, 0);
						
						img0.src = cnv0.toDataURL();
						document.body.removeChild(cnv0);
						document.body.removeChild(cnv1);
						diff_img.remove();
					});
					
					diff_img.attr('src', 'index.php?act=download_screenshot&name=' + vmname + '&id=' + json.vm.screenshot.difference.id);
				}
				else
				{
					a.element.img.attr('src', 'index.php?act=download_screenshot&name=' + vmname + '&id=' + json.vm.screenshot.id);
				}
				
				a.element.show.show();
				a.element.hide.hide();
			}
		},
		function(xhr, a)
		{
			a.element.show.hide();
			a.element.hide.show();
			alert("screenshot failed");
		});
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
	
	refresh_state: function(vmobj, element)
	{
		element.progress_indicator.show();
		
		this.call({
			act: 'get',
			name: vmobj.name,
		},
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
			
			element.progress_indicator.hide();
		});
	}
}

