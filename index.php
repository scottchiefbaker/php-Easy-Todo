<?php

$debug = $_GET['debug'] ?? "";
$start = microtime(1);

$base_dir = dirname(__FILE__);

require("$base_dir/include/todo.class.php");
require("$base_dir/include/sluz/sluz.class.php");

$sluz = new sluz;
$todo = new todo;

if (!empty($_GET['logout'])) {
	$todo->logout();
	header('Location: .');
}

handle_cli_commands();

$detail_id  = $_GET['details'] ?? null;
$todo_items = [];
$tpl        = "tpls/index.stpl";
if ($detail_id) {
	$x = $todo->show_detail($detail_id);

	$sluz->assign($x);
	$tpl = "tpls/detail.stpl";
} else {
	$todo_items = $todo->get_todo_list();
}

$total = sprintf("%0.3f", microtime(1) - $start);

$sluz->assign('render_time', $total);
$sluz->assign('dbq_summary', $todo->dbq->query_summary());
$sluz->assign('todo_item', $todo_items);
$sluz->assign('debug', $debug);

$sluz_vars     = $sluz->tpl_vars;
$sluz_var_html = k($sluz_vars, KRUMO_RETURN);
$sluz->assign('sluz_var_html', $sluz_var_html);

print $sluz->fetch($tpl);

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

function dformat($ut) {
	$ret = date("F jS Y", $ut);

	return $ret;
}

function dformat_time($ut) {
	$ret = date("F jS Y @ g:ia", $ut);

	return $ret;
}
