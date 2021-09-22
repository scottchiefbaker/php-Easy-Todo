<?php

$debug = $_GET['debug'] ?? "";
$start = microtime(1);

$base_dir = dirname(__FILE__);

require("$base_dir/include/todo.class.php");
require("$base_dir/include/xhtml.class.php");

$xhtml = new html;
$xhtml->css[] = "css/todo.css";
$xhtml->js[]  = "js/jquery.js";
$xhtml->js[]  = "js/todo.js";
$xhtml->title = "TODO List";

$todo  = new todo;

handle_cli_commands();

$detail_id = $_GET['details'] ?? null;
if ($detail_id) {
	$out = $todo->show_detail($detail_id);
} else {
	$out = $todo->show_todo_list();
}

// Output some debug info if requested
if ($debug) {
	$total = sprintf("%0.3f", microtime(1) - $start);
	$out .= "<p>Rendered in $total seconds</p>";
	$out .= $todo->dbq->query_summary();
}

print $xhtml->output($out);

/////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////

function handle_cli_commands() {
	global $todo;

	if (!$todo->is_cli()) {
		return null;
	}

	$arg = $argv[1] ?? "";

	if ($arg === "--to") {
		$to = $argv[2];
	}

	$to = "scott@perturb.org";
	$ok = send_reminder_email($to);

	if (!$ok) {
		print "Error!\n";
	}

	exit;
}

function send_reminder_email($to) {
	global $todo;
	global $base_dir;

	$headers  = "From: no-reply@perturb.org\r\n";
	$headers .= "MIME-Version: 1.0\r\n";
	$headers .= "Content-Type: text/html; charset=UTF-8\r\n";

	$css  = "<style>" . file_get_contents("$base_dir/css/todo.css") . "</style>";
	$html = $todo->todo_html_output();
	$subj = "TODO reminder list";
	$body = $css . $html;

	$ok = mail($to, $subj, $body, $headers);

	return $ok;
}
