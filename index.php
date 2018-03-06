<?php

$debug = $_GET['debug'] ?? "";
$start = microtime(1);

require('include/todo.class.php');
require('include/xhtml.class.php');

$xhtml = new html;
$xhtml->css[] = "css/todo.css";
$xhtml->js[]  = "js/jquery.js";
$xhtml->js[]  = "js/todo.js";
$xhtml->title = "TODO List";

$todo  = new todo;

$out          = $todo->show_todo_list();
$bookmarklet  = $todo->get_bookmarklet();
$out         .= "<div class=\"footer\"><a href=\"javascript: $bookmarklet\">Bookmarklet</a></div>";

// Output some debug info if requested
if ($debug) {
	$total = sprintf("%0.3f", microtime(1) - $start);
	$out .= "<p>Rendered in $total seconds</p>";
	$out .= $todo->dbq->query_summary();
}

print $xhtml->output($out);
