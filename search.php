<?php

if (!empty($_REQUEST['user_search'])) {
	$todo->user_search($_REQUEST['user_search']);
	exit;
}
