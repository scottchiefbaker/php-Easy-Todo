<?php

$base_dir = dirname(__FILE__);
require("$base_dir/include/todo.class.php");

$todo = new todo;

if (!empty($_REQUEST['user_search'])) {
	$todo->user_search($_REQUEST['user_search']);
	exit;
}
