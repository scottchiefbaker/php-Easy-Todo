<?php

require_once("include/Parsedown.php");

class todo {
	public $back_burner_id = -1;

	public $date_format = "F jS Y";
	public $time_format = "F jS Y @ g:iA";

	function __construct() {
		$dsn = "sqlite:/home/bakers/database/todo.sqlite";

		require('include/db_query.inc.php');
		$this->dbq = new db_query($dsn);

		$action = $_REQUEST['action'] ?? "";

		$person_info     = $this->get_person();
		$this->person    = $person_info['PersonName'];
		$this->person_id = $person_info['PersonID'];

		global $xhtml;
		$this->xhtml = &$xhtml;

		if ($action == "add_person") {
			$person = $_POST['person_name'];
			$email  = $_POST['person_email'];
			$this->set_person($person,$email);

			// Otherwise it's not set because it didn't exist above
			$person_info     = $this->get_person();
			$this->person    = $person_info['PersonName'];
			$this->person_id = $person_info['PersonID'];

			$PHP_SELF = $_SERVER['PHP_SELF'];
			header("Location: $PHP_SELF");
		} elseif($action == "add_todo") {
			$desc = $_REQUEST['todo_desc'];

			$this->add_todo_item($desc);

			$PHP_SELF = $_SERVER['PHP_SELF'];
			header("Location: $PHP_SELF");
		} elseif($action == "complete_todo") {
			$percent = $_GET['percent'];
			$tid = $_GET['todo_id'];

			$ret = $this->update_todo_percent($tid,$percent);

			if (!$ret) { $this->error_out("Couldn't update the percentage!?!"); }

			$PHP_SELF = $_SERVER['PHP_SELF'];
			header("Location: $PHP_SELF");
		} elseif($action == "add_note") {
			$id = $_GET['note_id'];
			$note = $_GET['note'];
			if (!$this->add_todo_note($id,$note,1)) {
				$this->error_out("Error adding note");
			}

			$PHP_SELF = $_SERVER['PHP_SELF'];
			header("Location: $PHP_SELF");
		} elseif($action == "detail_view") {
			$todo_id = $_GET['todo_id'];

			$html = $this->show_detail_view($todo_id);

			// Add the sql output if it's requested
			if (!empty($_GET['debug'])) {
				$html .= "<br />" . $this->dbq->summary();
			}

			print $this->xhtml->output($html);
			exit;
		}

		///////////////////////////////////////////////////

		if (!$this->person && empty($_GET['user_search'])) {
			$ret = $this->show_set_person();
			$this->xhtml->body_props = ("onload='set_person_focus();'");
			print $this->xhtml->output($ret);
			exit;
		}
	}

	function sanity_check() {
		$db_file = $this->db_file;
		$dir_name = dirname($db_file);

		if (!is_readable($db_file)) { $this->error_out("Cannot open DB '$db_file'"); }
		if (!is_writable($db_file)) { $this->error_out("Cannot open DB '$db_file' for writing"); }
		if (!is_writable($dir_name)) { $this->error_out("Cannot open directory for writing"); }

		return 1;
	}

	function error_out($msg) {
		print $msg;
		exit;
	}

	function person_search($name,$email = 0) {
		$sql = "SELECT * FROM Person WHERE";

		if (!$name && !$email) { return 0; }

		if ($name) { $sql .= " PersonName = '$name'"; }
		elseif ($email) { $sql .= " PersonEmailAddress = '$email'"; }

		$ret = $this->dbq->query($sql,'one_row');

		return $ret;
	}

	function set_person($name,$email) {
		$uniq_id = null;
		if ($_REQUEST['person_id']) {
			$uniq_id = $_REQUEST['person_id'];
		} else {
			$person_info = $this->person_search(NULL,$email);
			if ($person_info) {
				$uniq_id = $person_info['PersonUniqID'];
				print "Found uniq_id after an email search: $uniq_id<br />\n";
			}
		}

		if (!$uniq_id) {
			$uniq_id = rand(0,99999999);
		}

		$expire = date("U") + (86400 * 120);
		setcookie("todo_unique_id",$uniq_id,$expire);

		if ($_REQUEST['person_id']) {
			return 1;
		}

		$sql = "INSERT INTO Person (PersonUniqID, PersonName, PersonEmailAddress) VALUES ($uniq_id,'$name','$email');";

		$ret = $this->dbq->query($sql);

		return $ret;
	}

	function get_person_info($person_id) {
		if (!$person_id) { return 0; }

		$sql = "SELECT * FROM Person WHERE PersonID = $person_id;";
		$ret = $this->dbq->query($sql,'one_row');

		return $ret;
	}

	function get_person() {
		$uniq_id = $_COOKIE['todo_unique_id'] ?? "";
		if (!$uniq_id) { return 0; }

		$sql = "SELECT PersonName, PersonID FROM Person WHERE PersonUniqID = $uniq_id LIMIT 1;";

		$rs = $this->dbq->query($sql,'one_row');

		return $rs;
	}

	function show_set_person() {
		$PHP_SELF = $_SERVER['PHP_SELF'];
		$ret  = "<h2>I don't know who you are!</h2>\n";
		$ret .= "<div>Before you can access this site please provide your full name (and email address if you want to receive updates)</div>\n";

		$ret .= "<br />\n";
		$ret .= "<form action=\"$PHP_SELF\" method=\"post\" onsubmit=\"javascript: return check_login_form();\">\n";
		$ret .= "<label>Your Name:</label>\n";
		$ret .= "<input type=\"text\" name=\"person_name\" value=\"\" size=\"50\" id=\"person_name\" onkeyup=\"javascript: search_name(this.value)\" /><br />\n";
		$ret .= "<label>Email address:</label>\n";
		$ret .= "<input type=\"text\" name=\"person_email\" id=\"person_email\" value=\"\" size=\"50\" onkeyup=\"javascript: search_name(this.value)\" /><br />\n";
		//$ret .= "<label>Existing User:</label>\n";
		//$ret .= "<input type=\"checkbox\" name=\"existing_user\" id=\"existing\" value=\"\" /><br />\n";
		$ret .= "<input type=\"hidden\" name=\"action\" value=\"add_person\" />\n";
		$ret .= "<input type=\"hidden\" name=\"person_id\" id=\"person_id\" value=\"\" />\n";
		$ret .= "<br />\n";
		$ret .= "<input type=\"submit\" value=\"Submit\" />\n";
		$ret .= "</form>\n";

		return $ret;
	}

	function show_todo_list() {
		$person = $this->person;
		$person_id = $this->person_id;
		$ret = "<h2 class=\"large_header\">Logged in as <span Title=\"User #$person_id\">$person</span></h2>\n";
		//$ret .= "<h3 class=\"medium_header\">Showing TODO list</h3>\n\n";

		$ret .= $this->todo_html_output();

		$PHP_SELF = $_SERVER['PHP_SELF'];

		$todo_desc = "Add a task";

		$ret .= "<div class=\"enter_todo\">\n";
		//$ret .= "<h5 class=\"small_text bold italic\">Add a TODO item:</h5>\n";
		$ret .= "<form action=\"$PHP_SELF\" method=\"post\">\n";
		$ret .= "	<input type=\"text\" name=\"todo_desc\" placeholder=\"$todo_desc\" value=\"\" size=\"50\" onclick=\"javascript: this.value='';\" maxlength=\"100\" />\n";
		#$ret .= "<input type=\"text\" name=\"todo_due\" value=\"$todo_due\" size=\"10\" />\n";
		#$ret .= "<input type=\"text\" name=\"todo_priority\" value=\"$todo_prio\" size=\"10\" />\n";
		$ret .= "	<input type=\"submit\" value=\"Submit\" />\n";
		$ret .= "	<input type=\"hidden\" name=\"todo_id\" value=\"\" size=\"50\" />\n";
		$ret .= "	<input type=\"hidden\" name=\"action\" value=\"add_todo\" />\n";
		$ret .= "</form>\n";
		$ret .= "</div>";

		$end = time();
		$start = $end - (86400 * 30);

		$search_text = date("Y-m-d",$start) . " to " . date("Y-m-d",$end);

		$ret .= "<div class=\"search_todo\">\n";
		//$ret .= "<h5 class=\"small_text bold italic\">Search TODO List:</h5>\n";
		$ret .= "<form action=\"$PHP_SELF\" method=\"get\">\n";
		$ret .= "	<input type=\"text\" name=\"search\" value=\"$search_text\" size=\"50\" maxlength=\"100\" />\n";
		#$ret .= "<input type=\"text\" name=\"todo_due\" value=\"$todo_due\" size=\"10\" />\n";
		#$ret .= "<input type=\"text\" name=\"todo_priority\" value=\"$todo_prio\" size=\"10\" />\n";
		$ret .= "	<input type=\"submit\" value=\"Search\" />\n";
		$ret .= "</form>\n";
		$ret .= "</div>";

		return $ret;
	}

	function add_todo_item($desc,$prio = 0) {
		//print "Adding: $desc Due: $due with $prio prio";

		if (!$desc || $desc == "null") { return 0; }

		#if (!$this->person_id == 1) { $this->error_out("You can't add TODO items you're not the owner"); }

		$desc = stripslashes($desc);
		$desc = $this->dbq->quote($desc);
		$person_id = $this->person_id;

		$now = date("U");

		$sql = "INSERT INTO Todo (TodoDateTimeAdded,TodoDesc,TodoPriority,PersonID, TodoLastUpdate) VALUES ($now,$desc,$prio,$person_id,$now);";

		$ret = $this->dbq->query($sql);

		if ($this->person_id != 1) {
			$person = $this->person;
			$todo_id = $ret;
			$this->notify_new_item($todo_id, $desc, $person, $now);
		}

		return $ret;
	}

	function todo_html_output() {
		$filter    = $this->parse_search();
		$search    = $_GET['search'] ?? "";
		$todo_info = $this->get_active_todo($filter);
		$PHP_SELF  = $_SERVER['PHP_SELF'];

		$ret  = "<table class=\"todo_list\">\n";
		$ret .= "<tr>\n";
		$ret .= "	<th width=\"23%\">Creation</th>\n";
		$ret .= "	<th width=\"52%\">Description of task</th>\n";
		$ret .= "	<th width=\"10%\">Complete</th>\n";
		$ret .= "</tr>\n";

		$completed_tasks = "";

		if ($todo_info) {
			foreach ($todo_info as $info) {
				$id     = $info['TodoID'];
				$added  = date($this->date_format,$info['TodoDateTimeAdded']);
				$addedt = date($this->time_format,$info['TodoDateTimeAdded']);
				$prio   = $info['TodoPriority'];

				$desc = $this->search_highlight($info['TodoDesc'],$search);
				$desc = utf8_encode($desc);

				$created_by       = $info['PersonName'];
				$comp_percent_raw = $info['TodoCompletePercent'];
				$comp_percent     = $comp_percent_raw . "%";

				$comp_admin = "<form style=\"display: inline;\" method=\"get\" action=\"$PHP_SELF\">";
				$comp_admin .= "	<input class=\"hidden percent\" type=\"text\" maxlength=\"3\" value=\"$comp_percent_raw\" name=\"percent\" size=\"2\" />";
				$comp_admin .= "	<input type=\"hidden\" name=\"todo_id\" value=\"$id\" />";
				$comp_admin .= "	<input type=\"hidden\" name=\"action\" value=\"complete_todo\" />";
				$comp_admin .= "</form>";

				if ($this->person_id != 1) { $comp_admin = ""; }

				$note_toggle = " <span id=\"toggle_$id\" class=\"small_text\"><a onclick=\"javascript: return toggle_note($id);\" href=\"$PHP_SELF\">[Add Note]</a> <a href=\"$PHP_SELF?action=detail_view&amp;todo_id=$id\">[Detail View]</a></span>";

				$notes_html = $this->note_html($id);

				if ($comp_percent_raw == 100) {
					$html_class = "todo_complete";
				} elseif ($comp_percent_raw == $this->back_burner_id) {
					$html_class = "back_burner";
					$comp_percent = "Back Burner";
				} else {
					$html_class = "todo_normal";
				}

				$row = "<tr>\n";
				$row .= "\t<td class=\"$html_class\"><b title=\"$addedt\">$added</b> by $created_by</td>\n";
				#$ret .= "\t<td><a href=\"index.php?action=detail_view&todo_id=$id\">$id</a> $desc $notes_html $note_toggle</td>\n";
				$row .= "\t<td data-todo_id=\"$id\" class=\"$html_class\"><div class=\"todo_desc\">$desc</div><div class=\"todo_notes\">$notes_html</div></td>\n";
				$row .= "\t<td class=\"$html_class edit_percent\"><div class=\"center hide_percent\">$comp_percent</div><div class=\"center\">$comp_admin</div></td>\n";
				$row .= "</tr>\n";

				if ($comp_percent_raw == 100) {
					$completed_tasks .= $row;
				} else {
					$ret .= $row;
				}
			}
		}

		$ret .= $completed_tasks;
		$ret .= "</table>\n\n";

		return $ret;
	}

	function note_html($id) {
		$note_info = $this->get_note_info($id);
		$search    = $_GET['search'] ?? "";

		if (!$note_info) { return ""; }

		$ret = "<ul>\n";

		foreach ($note_info as $info) {
			$text = $info['NoteText'];
			$text = htmlentities(nl2br($text));

			if ($search) {
				$text = $this->search_highlight($text,$search);
			}

			$login_id = intval($this->person_id);

			// Do inline Parsedown parsing (not a <p>)
			$text = Parsedown::instance()->line($text);

			$id        = $info['NoteID'];
			$person    = $info['PersonName'];
			$person_id = intval($info['PersonID']);
			$date      = date($this->date_format,$info['NoteDateTime']);
			$datet     = date($this->time_format,$info['NoteDateTime']);

			if ($login_id === $person_id) {
				$ret .= "<li class=\"note_text\"><span title=\"$datet\">$date</span>: $text</li>";
			} else {
				$ret .= "<li class=\"note_text\"><span title=\"$datet\">$date</span> - $person: $text</li>";
			}
		}
		$ret .= "</ul>\n";

		return $ret;
	}

	function get_note_info($id) {
		if (!$id) { return 0; }

		$sql = "SELECT NoteID, NoteDateTime, NoteText, PersonName, p.PersonID AS PersonID, PersonEmailAddress
			FROM Notes n, Person p
			WHERE p.PersonID = n.PersonID AND TodoID = $id;";
		//print "$sql<br />\n";

		$ret = $this->dbq->query($sql);

		return $ret;
	}

	function get_active_todo($filter) {
		$order_field = "TodoDateTimeAdded";

		$old_cutoff = date("U") - (86400 * 5);

		$start = $filter['start'] ?? "";
		$end   = $filter['end']   ?? "";

		// Date filtered
		if ($start && $end) {
			$sql = "SELECT TodoID, TodoDateTimeAdded, TodoPriority, TodoDesc, p.PersonID AS PersonID, PersonName, TodoCompletePercent
				FROM Todo t, Person p
				WHERE p.PersonID = t.PersonID AND ((TodoDateTimeAdded BETWEEN $start AND $end) OR (TodoLastUpdate BETWEEN $start AND $end))
				ORDER BY $order_field DESC;";
		// Search through old past entries
		} elseif ($search_term = $filter['search']) {
			$sql = "SELECT t.TodoID AS TodoID, TodoDateTimeAdded, TodoPriority, TodoDesc, p.PersonID AS PersonID, PersonName, TodoCompletePercent
				FROM Todo t
				INNER JOIN Person p USING (PersonID)
				LEFT  JOIN Notes n USING (TodoID)
				WHERE (TodoDesc LIKE '%$search_term%' OR PersonName LIKE '%$search_term%' OR NoteText LIKE '%$search_term%')
				GROUP BY t.TodoID
				ORDER BY $order_field DESC
				LIMIT 20";
		// Normal
		} else {
			// Get all non-completed items, OR get the completed items that were completed in the last X days (uses last update not start date)
			$sql = "SELECT TodoID, TodoDateTimeAdded, TodoPriority, TodoDesc, p.PersonID AS PersonID, PersonName, TodoCompletePercent
				FROM Todo t, Person p
				WHERE p.PersonID = t.PersonID AND (TodoCompletePercent < 100 OR (TodoCompletePercent == 100 AND TodoLastUpdate > $old_cutoff))
				ORDER BY $order_field DESC;";
		}

		//print "<pre>$sql</pre><br />\n";

		$rs = $this->dbq->query($sql);

		$non_complete = array();
		$back_burner  = array();
		$complete     = array();

		foreach ($rs as $data) {
			if ($data['TodoCompletePercent'] == $this->back_burner_id) {
				// print $data['TodoCompletePercent'] . "<br />";
				$back_burner[] = $data;
			} elseif ($data['TodoCompletePercent'] == 100) {
				$complete[] = $data;
			} else {
				$non_complete[] = $data;
			}
		}

		$ret = array_merge($non_complete,$back_burner,$complete);

		return $ret;
	}

	function update_todo_percent($id,$percent) {
		$todo_info = $this->get_todo_info($id);
		if (!isset($percent)) { return 0; }

		if ((!$percent == $this->back_burner_id) && ($percent > 100 || $percent < 0)) { return 0; }

		$now = date("U");

		$sql = "UPDATE Todo SET TodoCompletePercent = $percent, TodoLastUpdate = $now WHERE TodoID = $id;";

		$this->dbq->query($sql);

		if ($percent == $this->back_burner_id) {
			$note = "Project was put on the back burner";
		} else {
			$note = "Percentage was changed to $percent%";
		}
		$this->add_todo_note($id,$note,0);

		$info['TodoCompletePercent'] = $percent . "%";
		$this->notify_of_change($id,'percent',$info);

		return 1;
	}

	function get_todo_info($id) {
		$sql = "SELECT TodoID, p.PersonID AS PersonID, PersonEmailAddress, TodoDateTimeAdded, TodoPriority, TodoDesc, PersonName, TodoCompletePercent, TodoLastUpdate FROM Todo t, Person p WHERE p.PersonID = t.PersonID AND TodoID = $id;";

		$ret = $this->dbq->query($sql,'one_row');

		return $ret;
	}

	function get_bookmarklet() {
		if ($_SERVER["HTTPS"]) { $http = "https://"; }
		else { $http = "http://"; }

		$real_url = $http . $_SERVER['SERVER_NAME'] . dirname($_SERVER['SCRIPT_NAME']) . "/";
		$real_url .= "js/bookmarklet.js.php";

		$html = "void(z=document.body.appendChild(document.createElement('script'))); void(z.language='javascript');void(z.type='text/javascript');void(z.src='$real_url');void(z.id='todo_bmlet');";

		$ret = preg_replace("/\n/","",$html);
		$ret = preg_replace("/\t/"," ",$ret);
		$ret = preg_replace("/\s+/"," ",$ret);
		$ret = preg_replace("/\"/","\\\"",$ret);

		return $ret;
	}

	function add_todo_note($id,$note,$notify_users) {
		if (!$id || !$note) { return 0; }

		$now = date("U");
		$person_id = $this->person_id;
		$note_text = trim($note);
		$note_text = $this->dbq->quote($note_text);

		$sql = "INSERT INTO Notes (TodoID, NoteDateTime, NoteText, PersonID) VALUES ($id,$now,$note_text,$person_id);";

		$ret = $this->dbq->query($sql);

		$info['NoteText'] = trim($note);
		$info['PersonName'] = $this->person;
		$info['NoteDateTime'] = $now;

		if ($notify_users) {
			$this->notify_of_change($id,'note',$info);
		}

		return 1;
	}

	function show_detail_view($todo_id) {
		$note_html = $this->note_html($todo_id);
		$todo_info = $this->get_todo_info($todo_id);
		$PHP_SELF  = $_SERVER['PHP_SELF'];
		$todo_desc = $todo_info['TodoDesc'];

		$todo_date   = date("Y-m-d",$todo_info['TodoDateTimeAdded']);
		$person      = $todo_info['PersonName'];
		$percent     = $todo_info['TodoCompletePercent'] . "%";
		$last_update = date("Y-m-d",$todo_info['TodoLastUpdate']);

		$ret  = "<div class=\"detail_view_task\">Task #$todo_id: $todo_desc</div>\n";
		$ret .= "<div class=\"detail_view_assigned\">Assigned by: $person on $todo_date</div>\n";
		$ret .= "<div class=\"detail_view_complete\"><b>Status:</b> $percent complete</div>\n";
		$ret .= "<div class=\"detail_view_last_update\"><b>Last Updated:</b> $last_update</div>\n";

		if ($note_html) {
			$ret .= "<div class=\"detail_view\">\n";
			$ret .= "<h3 class=\"medium_header\">Notes:</h3>\n";
			$ret .= $note_html;
			$ret .= "</div>\n";
		}

		$PHP_SELF = $_SERVER['PHP_SELF'];
		$ret .= "<div class=\"footer\"><span id=\"toggle_$todo_id\"><a onclick=\"javascript: return toggle_note($todo_id);\" href=\"$PHP_SELF\">Add Note</a> | </span><a href=\"$PHP_SELF\">Return to list</a></div>";

		return $ret;
	}

	function get_email_headers ($subject) {
		$headers  = 'MIME-Version: 1.0' . "\n";
		$headers .= 'Content-type: text/html; charset=UTF-8' . "\n";
		$headers .= "From: \"Nobody\" <no-reply@web-ster.com>\n";
		$headers .= "Date: " . date("r") . "\n";
		$headers .= "Subject: $subject\n";

		return $headers;
	}

	function notify_new_item($todo_id, $desc, $person, $date) {
		$date = date("Y-m-d",$date);

		$msg = "$person added a new task '$desc' on $date";

		$admin_info = $this->get_admin_info();
		$admin_email = $admin_info['PersonEmailAddress'];

		if ($_SERVER["HTTPS"]) { $http = "https://"; }
		else { $http = "http://"; }

		$real_url = $http . $_SERVER['SERVER_NAME'] . dirname($_SERVER['SCRIPT_NAME']) . "/";
		$detail_link = "$real_url?action=detail_view&todo_id=$todo_id";

		$msg .= "<br /><br />View the whole task in more detail <a href=\"$detail_link\">here</a>.";
		$subj .= "$person added a new task '$desc'";

		$headers = $this->get_email_headers($subj);

		if ($this->valid_email($admin_email)) {
			$ret = mail($admin_email,$subj,$msg,$headers);
		}
	}

	function notify_of_change($todo_id,$type,$extra_info) {

		$todo_info = $this->get_todo_info($todo_id);
		$admin_info = $this->get_admin_info();
		$note_info = $this->get_note_info($todo_id);

		$owner_email = $todo_info['PersonEmailAddress'];
		$admin_email = $admin_info['PersonEmailAddress'];

		$todo_desc = $todo_info['TodoDesc'];

		if ($note_info) {
			foreach ($note_info as $info) {
				$email_list[$info['PersonEmailAddress']]++;
			}
		}

		$email_list[$owner_email]++;
		$email_list[$admin_email]++;

		if ($_SERVER["HTTPS"]) { $http = "https://"; }
		else { $http = "http://"; }

		$real_url = $http . $_SERVER['SERVER_NAME'] . dirname($_SERVER['SCRIPT_NAME']) . "/";
		$detail_link = "$real_url?action=detail_view&todo_id=$todo_id";

		if ($type == 'note') {
			$person = $this->person;
			$note_added = date("Y-m-d",$info['NoteDateTime']);
			$note_text = $extra_info['NoteText'];
			$msg = "$person added a note to '$todo_desc' on $note_added<br /><br />Note: $note_text";
			$subj = "Todo note added on '$todo_desc'";
		} elseif ($type == 'percent') {
			$person = $this->person;
			$percent = $extra_info['TodoCompletePercent'];
			$msg = "$person changed the complete percentage on '$todo_desc' to $percent";
			$subj = "Todo task '$todo_desc' is $percent complete";
		} else {
			$this->error_out("$weird type for notify '$type'");
		}

		// The person making the change doesn't need to be informed if they are in the list
		$person_info = $this->get_person_info($this->person_id);
		$person_email = $person_info['PersonEmailAddress'];

		// Take them out of the hash so they don't get the email
		unset($email_list[$person_email]);

		$msg .= "<br /><br />\n\nView the whole task in more detail <a href=\"$detail_link\">here</a>.";

		foreach (array_keys($email_list) as $email) {
			$count = $email_list[$email];
			if ($count == 0) { continue; }

			$headers = $this->get_email_headers($subj);

			if ($this->valid_email($email)) {
				$ret = mail($email,$subj,$msg,$headers);
				if (!$ret) {
					$this->error_out("Error emailing: $email,$subj,$msg,$headers");
				}
			}
		}
	}

	function get_admin_info() {
		$sql = "SELECT * FROM Person WHERE PersonID = 1;";

		$ret = $this->dbq->query($sql,'one_row');

		return $ret;
	}

	function user_search($search) {
		$sql = "SELECT PersonID, PersonUniqID, PersonName, PersonEmailAddress
			FROM Person
			WHERE PersonName LIKE '%$search%' OR PersonEmailAddress LIKE '%$search%';";

		if (!$search) {
			print "Error #8913";
			exit;
		}

		// print $sql;

		$rs = $this->dbq->query($sql);
		$rows = sizeof($rs);

		if ($rows == 1) {
			$data = $rs[0];
			$id = $data['PersonUniqID'];
			$name = $data['PersonName'];
			$email = $data['PersonEmailAddress'];

			print "$id:$name:$email";
		} elseif ($rows > 1) {
			print "-1:More than 1 result:foo@foo.com";
		} else {
			print "";
		}

		return 1;
	}

	function valid_email($email) {
		// First, we check that there's one @ symbol, and that the lengths are right
		if (!ereg("[^@]{1,64}@[^@]{1,255}", $email)) {
			// Email invalid because wrong number of characters in one section, or wrong number of @ symbols.
			return false;
		}
		// Split it into sections to make life easier
		$email_array = explode("@", $email);
		$local_array = explode(".", $email_array[0]);
		for ($i = 0; $i < sizeof($local_array); $i++) {
			if (!ereg("^(([A-Za-z0-9!#$%&'*+/=?^_`{|}~-][A-Za-z0-9!#$%&'*+/=?^_`{|}~\.-]{0,63})|(\"[^(\\|\")]{0,62}\"))$", $local_array[$i])) {
				return false;
			}
		}
		if (!ereg("^\[?[0-9\.]+\]?$", $email_array[1])) { // Check if domain is IP. If not, it should be valid domain name
			$domain_array = explode(".", $email_array[1]);
			if (sizeof($domain_array) < 2) {
				return false; // Not enough parts to domain
			}
			for ($i = 0; $i < sizeof($domain_array); $i++) {
				if (!ereg("^(([A-Za-z0-9][A-Za-z0-9-]{0,61}[A-Za-z0-9])|([A-Za-z0-9]+))$", $domain_array[$i])) {
					return false;
				}
			}
		}
		return true;
	}

	function parse_search() {
		$search = $_GET['search'] ?? "";

		if (preg_match("/(.+?) to (.+?)$/",$search,$match)) {
			$start = strtotime($match[1]);
			$end = strtotime($match[2]);

			$end += 86359; // Make it the last second of that date

			$ret['start'] = $start;
			$ret['end'] = $end;
		} else {
			$ret['search'] = $search;
		}

		return $ret;
	}

	function search_highlight($text,$search) {
		if (!$search) { return $text; }
		$text = preg_replace("/($search)/i","<span class=\"search_highlight\">$1</span>",$text);

		return $text;
	}
}
