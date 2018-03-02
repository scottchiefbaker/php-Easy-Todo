$(document).ready(function() {
	init_add_note();
});

function set_person_focus() {
	document.getElementById('person_name').focus();
}

function add_note(selector) {
	var elem = $(selector);
	var id   = elem.data("todo_id");

	// If this item already has a note box open don't add a second
	var has_note = $(".adding_note", elem).length > 0;
	if (has_note || !id) { return false; }

	// Only one note box open at at time, remove all the other ones
	$(".add_note_wrapper").remove();

	var note = $("<div class=\"add_note_wrapper\"><form class=\"adding_note\" method=\"get\" action=\"index.php\"> <input class=\"add_note\" placeholder=\"Add notes...\" type=\"text\" id=\"ta_" + id + "\" name=\"note\" /> <input type=\"hidden\" name=\"note_id\" value=\"" + id + "\"> <input type=\"hidden\" name=\"action\" value=\"add_note\"></form></div>");

	note.appendTo(elem);
	$(".add_note",elem).focus();

	return true;
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

function init_add_note() {
	var target = $(".todo_desc, .todo_notes");
	target.css("cursor","pointer").attr("title","Click to add notes to this item");

	$(".todo_normal, .todo_complete").on("click", target, function() {
		add_note($(this));
	});
}
