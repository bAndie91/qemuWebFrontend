
function setupAutoCompleter()
{
	var type = '';
	var plus = '';
	var cnt = $(this).data('counter');
	
	if($(this).is('.option_key'))
	{
		type = 'option';
	}
	else if($(this).is('.ac_path'))
	{
		type = 'path';
	}
	else
	{
		type = 'parameter';
		var v = $('[name="optkey_' + cnt + '"]').val();
		plus = '&option=' + v;
	}
	
	var url = 'index.php?act=autocomplete&name=' + document.qemuvm.name + '&type=' + type + plus;
	var ac = $(this).data('autocompleter');
	if(ac == undefined)
	{
		$(this).autocomplete({
			url: url,
			field_type: type,
			field_cnt: cnt,
			remoteDataType: 'json',
			delay: 700,
			queryParamName: 's',
			maxItemsToShow: 0,
			minChars: 0,
			mustMatch: false,
			filterResults: false,
			cellSeparator: null,
			useCache: false,
			delimiterKeyCode: 0,
			preventDefaultTab: true,
			sortResults: false,
			onItemSelect: acSetItem,
		});
	}
	else
	{
		ac.options.url = url;
	}
}

function setupAutoCompleter2()
{
	var c = $(this).data('counter');
	$('[name="optval_' + c + '"]').each(function()
	{
		setupAutoCompleter.call(this);
	});
}

function acSetItem(item, ac)
{
	if(item.data.raw_value != undefined)
	{
		ac.setValue(item.data.raw_value);
	}
	if(ac.options.field_type == 'option')
	{
		var obj = $('.option_val[data-counter=' + ac.options.field_cnt + ']');
		if(item.data.parameter_spec == '' || item.data.no_parameters)
		{
			obj.hide();
		}
		else
		{
			obj.show();
			if(window.options_default != undefined && window.options_default[item.value] != undefined)
			{
				var values = window.options_default[item.value];
				obj.val(values[0]);
				for(var idx = 1; values.length > idx; idx++)
				{
					addOption(ac.options.field_cnt, item.value, values[idx]);
				}
			}
		}
	}
}
