
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
		).fail(function(xhr)
			{
				if(typeof cb_fail == 'function')
				{
					cb_fail(xhr, args);
				}
			}
		);
	},
	screenshot: function(vmname, element)
	{
		this.call({
			act: "refresh_screenshot",
			name: vmname,
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
				a.element.time.text(json.vm.screenshot.timestr);
				a.element.img.attr('src', json.vm.screenshot.url + '?ts=' + json.vm.screenshot.timestamp);
				a.element.img.show();
				a.element.noimg.hide();
			}
		},
		function(xhr, a)
		{
			a.element.img.hide();
			a.element.noimg.show();
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

