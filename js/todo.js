function set_person_focus() {
	document.getElementById('person_name').focus();
}

function toggle_note(id) {
	var eid = document.getElementById('toggle_' + id);

	eid.innerHTML = "<br /> <form method=\"get\" action=\"index.php\"> <input type=\"text\" id=\"ta_" + id + "\" style=\"width: 98%;\" name=\"note\" /> <input type=\"hidden\" name=\"note_id\" value=\"" + id + "\"> <input type=\"hidden\" name=\"action\" value=\"add_note\"></form>";

	document.getElementById('ta_' + id).focus();

	return false;
}

function check_login_form() {
	var eid = document.getElementById('person_name');

	var err = "";
	if (eid.value.length <= 3) { err = err + "* You must enter your name" }

	if (err) {
		alert(err);
		return false;
	}

	return 1;
}

function search_name(name) {
	var xmlhttp;

	if (get_eid('existing').checked != true) {
		// alert('not existing');
		return 0;
	}

	if (window.XMLHttpRequest) {
		xmlhttp = new XMLHttpRequest();
		xmlhttp.overrideMimeType("text/xml");
	} else if (window.ActiveXObject) {
		xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
	}

	var search_term = name;
	var params = "user_search=" + search_term;

	xmlhttp.open("GET", "search.php?" + params, true);
	xmlhttp.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

	xmlhttp.onreadystatechange = function() {
		if (xmlhttp.readyState == 4) {
			var results = xmlhttp.responseText;

			data = results.split(":");
			var id = parseInt(data[0]);
			var name = data[1];
			var email = data[2];

			// alert(id + " " + name + " " + email);

			if (id > 0) {
				var conf = confirm("Are you: \nName: " + name + "\nEmail: " + email);
			
				if (conf) {
					get_eid('person_name').value = name;
					get_eid('person_email').value = email;
					get_eid('person_id').value = id;
				}
				// alert(name + " " + email);
			}
			
		}
	}

	xmlhttp.send(params);
}

function get_eid(name) {
	var eid = document.getElementById(name);

	return eid;
}
