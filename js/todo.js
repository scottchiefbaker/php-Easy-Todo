$(document).ready(function() {
	init_add_note();
	init_change_percent();
});

function set_person_focus() {
	document.getElementById('person_name').focus();
}

function add_note(selector) {
	var elem = $(selector);
	var id   = elem.data("todo_id");

	// If this item already has a note box open don't add a second
	var has_note = $(".add_note_wrapper", elem).length > 0;

	if (has_note || !id) { return false; }

	// Only one note box open at at time, remove all the other ones
	$(".add_note_wrapper").remove();

	var note = $("<div class=\"add_note_wrapper\"><form class=\"\" method=\"get\" action=\"index.php\"> <input class=\"form-control add_note\" placeholder=\"Add notes...\" type=\"text\" id=\"ta_" + id + "\" name=\"note\" /> <input type=\"hidden\" name=\"note_id\" value=\"" + id + "\"> <input type=\"hidden\" name=\"action\" value=\"add_note\"></form></div>");

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

function search_name(str) {
	if (str.length <= 3) {
		return;
	}

	$.ajax({
		url: "search.php?user_search=" + str,
		success: function(result) {
			var id = result.PersonUniqID ?? 0;

			if (id > 0) {
				var name  = result.PersonName;
				var email = result.PersonEmailAddress;

				var conf = confirm("Are you: \nName: " + name + "\nEmail: " + email);

				if (conf) {
					$('#person_name').val(name);
					$('#person_email').val(email);
					$('#person_id').val(id);
				}
			}
		}
	});
}

function get_eid(name) {
	var eid = document.getElementById(name);

	return eid;
}

function init_add_note() {
	$(".todo-desc-box").on("click", function() {
		var box = $(this);
		console.log(box);
		add_note(box);
	});
}

function init_change_percent() {
	$(".edit_percent").css("cursor","pointer").on("click",function() {
		$(".hide_percent").show();          // Show all the hide percents
		$(".percent").hide();               // Hide any other input boxes we have open
		$(".percent",this).show().select(); // Show the input box
		$(".hide_percent",this).hide();     // Hide the HTML display percent
	});
}
