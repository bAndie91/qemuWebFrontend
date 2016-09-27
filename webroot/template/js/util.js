
function click_on_off(checkbox, jselect)
{
	var a = checkbox.checked ? null : 'disabled';
	jselect.attr('disabled', a);
}

function click_off_on(checkbox, jselect)
{
	var a = checkbox.checked ? 'disabled' : null;
	jselect.attr('disabled', a);
}

function json2ul(data)
{
	if(typeof(data) == 'object')
	{
		var ul = $('<ul>');
		for (var i in data)
		{
			ul.append($('<li>').text(i).append(json2ul(data[i])));
		}
		return ul;
	}
	else
	{
		var textNode = document.createTextNode(' => ' + data);
		return textNode;
	}
}
