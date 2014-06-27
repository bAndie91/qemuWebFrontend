qemu = {
	call: function(post, args, cb_success, cb_fail)
	{
		$.post("index.php", post,
			function(responseText, textStatus, xhr)
			{
				if(typeof cb_success == 'function')
				{
					cb_success(responseText, textStatus, xhr, args);
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
	screenshot: function(vmname, img_element, noimg_element, time_element)
	{
		this.call({
			act: "refresh_screenshot",
			name: vmname,
		},
		{
			img_element: img_element,
			noimg_element: noimg_element,
			time_element: time_element,
		},
		function(responseText, textStatus, xhr, a)
		{
			json = JSON.parse(responseText);
			if(json.error && json.error.length > 0)
			{
				alert(json.error.join("\n"));
			}
			else
			{
				a.time_element.text(json.vm.screenshot.timestr);
				a.img_element.attr('src', json.vm.screenshot.url + '?ts=' + json.vm.screenshot.timestamp);
				a.img_element.show();
				a.noimg_element.hide();
			}
		},
		function(xhr, a)
		{
			a.img_element.hide();
			a.noimg_element.show();
			alert("screenshot failed");
		});
	},
	act: function(act, vmname)
	{
		this.call({
			act: act,
			name: vmname,
		},
		{},
		function(responseText, textStatus, xhr, a)
		{
			json = JSON.parse(responseText);
			if(json.error && json.error.length > 0)
			{
				alert(json.error.join("\n"));
			}
		});
	}
}

