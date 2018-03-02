<?php

require('include/todo.class.php');
require('include/xhtml.class.php');

$xhtml = new html;
$todo  = new todo;

$xhtml->css_file = "css/todo.css";
$xhtml->script   = "js/todo.js";
$xhtml->script   = "js/jquery.js";
$xhtml->title    = "TODO List";

$out          = $todo->show_todo_list();
$bookmarklet  = $todo->get_bookmarklet();
$out         .= "<div class=\"footer\"><a href=\"javascript: $bookmarklet\">Bookmarklet</a></div>";

// Add the sql output if it's requested
if (!empty($_GET['debug'])) {
	$out .= "<br />" . $todo->dbq->query_summary();
}

print $xhtml->output($out);
