<?php

define("DB_QUERY_VERSION","1.0.0");

//////////////////////////////////////////////////////////////////////////////

class DBQuery {
	var $debug                   = 0;
	var $show_errors             = 1;   // If this is set to zero be silent about all errors
	var $slow_query_time         = 0.1; // Highlight queries that takes longer than this
	var $external_error_function = "";  // Override the built in error function
	var $db_name                 = "";  // Placeholder
	var $dbh_cache               = [];
	var $dbh                     = null;
	var $query_log               = "";

	public function __construct($dsn,$user = "",$pass = "") {
		$ret = new PDO($dsn,$user,$pass);

		if ($ret) {
			$this->dbh = $ret;
		}
	}

	public function query($sql = "",$return_type = "",$third = '') {
		$start = microtime(1);
		$sql   = trim($sql);

		$dbh = $this->dbh;

		// Test if the DB connection is there
		if (!$dbh) {
			$this->error_out('DBH connection not present (Error: #1559)');
		}

		$prepare_values = array();
		if (is_array($return_type)) {
			$prepare_values = $return_type;
			$return_type    = $third;
		}

		if ($return_type === "no_error" || !$this->show_errors) {
			$show_errors = 0;
		} else {
			$show_errors = 1;
		}

		if (!$sql && !$this->sth) { return array(); } // Nothing to do and no cached STH

		$has_error = false;
		$err_text  = '';
		$err_code  = 0;

		// If there is a SQL command, run it
		if ($sql) {
			// Prepare the command
			$sth = $dbh->prepare($sql);
			if (!$sth) {
				list($sql_error_code,$driver_error_code,$err_text) = $dbh->errorInfo();

				// We couldn't make a STH, probably bad SQL
				if (!$show_errors) {
					$info = array(
						'sql'              => $sql,
						'error_text'       => $err_text,
						'error_code'       => intval($driver_error_code),
						'return_type'      => $return_type,
						'db_name'          => $this->db_name,
						'parameter_values' => $prepare_values
					);

					$i = debug_backtrace();

					if (isset($i[1])) { $num = 1; } // Called from my external class
					else { $num = 0; } // Called direct

					$info['called_from_file'] = $i[$num]['file'];
					$info['called_from_line'] = $i[$num]['line'];

					$this->db_query_info[] = $info;

					return false;
				}

				$this->error_out(array("Unable to create a <b>\$DBH</b> handle", $err_text));
			}

			// Execute the command with the appropriate replacement variables (if any)
			$sth->execute($prepare_values);
			$affected_rows = $sth->rowCount();
			$err = $sth->errorInfo();

			if ($err[0] !== "00000") {
				$has_error = true;
			}

			unset($this->sth); // Remove any cached statement handles (from fetches)
		// No SQL, but a cached statement handle (i.e. it's a fetch)
		} elseif (!$sql && $this->sth) {
			// Do nothing we already have the statement handle
		}

		// Check for non "00000" error status
		if ($show_errors && $has_error) {
			$html_sql = "<pre>" . $this->sql_clean($sql) . "</pre>";
			$err_text = $err[2];

			$this->error_out("<span>Syntax Error</span>\n" . $html_sql . "\n" . $err_text);
		}

		if (preg_match("/info_hash[:|](\w+)(\[\])?/",$return_type,$m)) {
			$key_field   = $m[1];
			$return_type = "info_hash";

			$wants_array = 0;
			if (isset($m[2])) {
				$wants_array = 1;
			}
		}

		// If it's a looping fetch, just get the query started
		if (preg_match("/fetch:?(.+)?/",$return_type,$m)) {
			if (isset($m[1])) {
				$this->fetch_num = intval($m[1]);
			} else {
				$this->fetch_num = 500;
			}

			$this->sth = $sth; // Cache the $sth
			$this->sql = $sql;

			$total = microtime(1) - $start;

			$info = array(
				'sql'              => $sql,
				'exec_time'        => $total,
				'records_returned' => 0,
				'error_text'       => $err_text,
				'error_code'       => intval($err_code),
				'return_type'      => $return_type,
				'db_name'          => $this->db_name,
				'parameter_values' => $prepare_values
			);

			$i = debug_backtrace();
			if (isset($i[1])) { $num = 1; } // Called from my external class
			else { $num = 0; } // Called direct

			$info['called_from_file'] = $i[$num]['file'];
			$info['called_from_line'] = $i[$num]['line'];
			$this->db_query_info[] = $info;

			return true;
		}

		$is_fetch = 0;
		if (!$sql && $this->sth) {
			$sth = $this->sth;
			$sql = $this->sql;
			$is_fetch = 1;
		}

		$count = 0;
		global $DB_QUERY_REC_LIMIT;
		$rec_limit = $DB_QUERY_REC_LIMIT;
		$rec_limit || $rec_limit = 4000;
		/////////////////////////////////////////////
		// Be smart about what we're going to return
		/////////////////////////////////////////////
		if (!$show_errors && $has_error) { // Has to be the first to test for error status
			return false;
		} elseif ($return_type == 'one_data') {
			$ret = $sth->fetch(PDO::FETCH_NUM); // Get the first row
			$ret = $ret[0]; // Get the first column of the first row

			// If nothing is in the record set, return an empty string
			if (!isset($ret)) { $ret = ''; }
			$return_recs = 1;
		} elseif ($return_type == 'info_list') {
			while ($data = $sth->fetch(PDO::FETCH_NUM)) {
				$ret[] = $data;

				$count++;
				if ($count > $rec_limit) { $this->error_out(array("Too many records returned (> $rec_limit)",$sql)); }
			}
		// Key/Value where the first field is the key, and the second field is the value
		} elseif ($return_type == 'key_value') {
			while ($data = $sth->fetch(PDO::FETCH_NUM)) {
				$ret[$data[0]] = $data[1];
			}
		// Key/Value where we get a specific key/value from a larger list
		} elseif (preg_match("/key_pair:(.+?),(.+)/",$return_type,$m)) {
			while ($data = $sth->fetch(PDO::FETCH_ASSOC)) {
				$key = $data[$m[1]];
				$value = $data[$m[2]];

				if ($key) { $ret[$key] = $value; }
			}

			$return_type = 'key_pair';
		} elseif ($return_type == 'one_column') {
			while ($data = $sth->fetch(PDO::FETCH_NUM)) { $ret[] = $data[0]; }
		} elseif ($return_type == 'one_row_list') {
			$ret = $sth->fetch(PDO::FETCH_NUM);
			$return_recs = 1;
		} elseif (($return_type == 'one_row' || preg_match("/^SELECT.*LIMIT 1;?$/si",$sql)) && $return_type != 'info_hash') {
			$ret = $sth->fetch(PDO::FETCH_ASSOC);

			// For some reason if you do a query with 'one_row' that returns no matches
			// you get false from PDO::FETCH_ASSOC. This works around what may be a PDO bug
			if ($ret === false) {
				$ret = array();
			}

			$return_type = 'one_row';
			$return_recs = 1;
		} elseif ($return_type == 'info_hash' && isset($key_field)) {
			while ($data = $sth->fetch(PDO::FETCH_ASSOC)) {
				$key = $data[$key_field];

				if ($wants_array) {
					$ret[$key][] = $data;
				} else {
					$ret[$key] = $data;
				}

				$count++;
				if (isset($this->fetch_num) && ($count >= $this->fetch_num)) { break; }
				if ($count > $rec_limit) { $this->error_out(array("Too many records returned (> $rec_limit)",$sql)); }
			}

			$return_type = 'info_hash_with_key';
		// If it's an info_hash or nothing (auto detect) and it's known SQL run this
		} elseif ($is_fetch || (($return_type == 'info_hash' || $return_type == "") && preg_match("/^(SELECT|SHOW|EXECUTE)/i",$sql))) {
			if (isset($this->sth)) {
				$sth = $this->sth;
				$sql = $this->sql;
			}

			// Loop through the data and return an info hash
			while ($data = $sth->fetch(PDO::FETCH_ASSOC)) {
				$ret[] = $data;

				$count++;
				if (isset($this->fetch_num) && ($count >= $this->fetch_num)) { break; }
				if ($count > $rec_limit) { $this->error_out(array("Too many records returned (> $rec_limit)",$sql)); }
			}

			// If we have a cache sth and nothing to return it means
			// we hit the end of the record set so we need to zero
			// out all the fetch related vars
			if (isset($this->sth) && !isset($ret)) {
				$this->sql = $this->sth = $this->fetch_num = NULL;
				return array();
			}

			$return_type = 'info_hash';
		} elseif ($return_type == 'insert_id' || preg_match("/^INSERT/i",$sql)) {
			// Get the ID from the inserted record, and convert it to an integer
			$insert_id = $ret = $dbh->lastInsertId();
			if (is_numeric($ret)) { $ret = intval($ret); }

			if (!$ret) { $ret = true; }

			$return_type = 'insert_id';
			$return_recs = 1;
		} elseif ($return_type == 'affected_rows' || preg_match("/^(DELETE|UPDATE|REPLACE|TRUNCATE)/i",$sql)) {
			$ret         = $affected_rows;
			$return_type = 'affected_rows';
			$return_recs = 1;
		} elseif (preg_match("/^(LOCK|UNLOCK)/i",$sql)) {
			$return_type = 'lock/unlock';
			$ret         = 1;
			$return_recs = 1;
		} elseif (preg_match("/^(CREATE|DROP)/i",$sql)) {
			$return_type = 'create/drop';
			$ret         = 1;
			$return_recs = 1;
		} else {
			// If we're not showing errors, just return false
			if (!$show_errors) { return false; }

			$html_sql = "<pre>" . $this->sql_clean($sql) . "</pre>";
			$error    = "<div>Not sure about the return type for this SQL</div><br >\n<div>";
			$error   .= "<b>SQL:</b> $html_sql</div>";
			$error   .= "<b>ReturnType:</b> $return_type</div>";

			$this->error_out($error);
		}

		$total = microtime(1) - $start;

		// Store some info about this query
		if (!isset($return_recs)) {
			if (!isset($ret)) {
				$return_recs = 0;
			} else {
				if (!is_array($ret)) {
					print "$sql\n";
					print_r($ret);
					print "\n";
				}
				$return_recs = sizeof($ret);
			}
			if (isset($insert_id)) { $return_recs .= " (#$insert_id)"; }
		}

		if (isset($err[0]) && intval($err[0])) {
			$err_code = intval($err[1]);
			$err_text = $err[2];
		}

		$info = array(
			'sql'              => $sql,
			'exec_time'        => $total,
			'records_returned' => $return_recs,
			'error_text'       => $err_text,
			'error_code'       => $err_code,
			'return_type'      => $return_type,
			'db_name'          => $this->db_name,
			'parameter_values' => $prepare_values
		);

		$i = debug_backtrace();
		if (isset($i[1])) { $num = 1; } // Called from my external class
		else { $num = 0; } // Called direct

		$info['called_from_file'] = $i[$num]['file'];
		$info['called_from_line'] = $i[$num]['line'];
		$this->db_query_info[] = $info;

		// Log to a file if need be
		if (!empty($this->query_log) && is_writable($this->query_log)) {
			$sql_log = $this->query_log;
			$fp      = @fopen($sql_log,"a");

			$sql = preg_replace("/\n|\r/","",$sql); // Make it all one line
			$sql = preg_replace("/\s+/"," ",$sql); // Remove double spaces

			$date = date("Y-m-d H:i:s");
			$str  = "\"$date\",\"$sql\",\"$total\",\"$return_recs\"\n";

			if ($fp) {
				fwrite($fp,$str);
				fclose($fp);
			}
		}

		if (!isset($ret)) { $ret = array(); }

		return $ret;
	}

	public function error_out($msg) {
		// Don't print any errors if we're not showing errors
		if (!$this->show_errors) { return false; }

		if (is_callable($this->external_error_function)) {
			call_user_func($this->external_error_function,$msg);
		}

		if (!is_array($msg)) { $msg = array($msg); }
		$cli = $this->is_cli();

		foreach ($msg as $m) {
			if ($m) {
				$msg = "<div><b>Error:</b> $m</div>\n";

				// If we're in CLI mode, strip out any HTML tags
				if ($cli) { $msg = strip_tags($msg); }

				print $msg;
			}
		}

		exit;
	}

	public function number_ordinal($num) {
		$ones = $num % 100;

		if ($ones == 1) { $ret = "st"; }
		elseif ($ones == 2) { $ret = "nd"; }
		elseif ($ones == 3) { $ret = "rd"; }
		elseif ($ones >= 4) { $ret = "th"; }
		elseif ($ones >= 0) { $ret = "th"; } // For 100
		else { $ret = "??"; }

		return $ret;
	}

	public function query_summary() {
		if (empty($this->db_query_info)) {
			return "";
		}

		$count      = 0;
		$total_time = 0;
		$ret        = "";

		$func_call_count = array();
		foreach ($this->db_query_info as $item) {
			$count++;

			$dbn = '';
			if ($item['db_name']) {
				$dbn = " (" . $item['db_name'] . ")";
			}

			$call_location = $item['called_from_file'] . ":" . $item['called_from_line'];
			if (isset($func_call_count[$call_location])) {
				$func_call_count[$call_location]++;
			} else {
				$func_call_count[$call_location] = 1;
			}

			$this_count = $func_call_count[$item['called_from_file'] . ":" . $item['called_from_line']];
			if ($this_count > 1) { $my_count = " <span style=\"color: red; font-weight: bold;\">($this_count" . $this->number_ordinal($this_count) ." call)</span>"; }
			else { $my_count = ''; }

			if ($item['exec_time'] > $this->slow_query_time) {
				$row_color   = "#FF9FA1";
				$sql_bg      = "#FFE4E6";
				$query_title = "Warning: This query is above the Slow Query threshold of " . $this->slow_query_time . " seconds";
			} else {
				$row_color   = "#E7FFEB";
				$sql_bg      = "white";
				$query_title = "";
			}

			$query_time = sprintf("%0.3f",$item['exec_time']);

			$ret .= "<table title=\"$query_title\" style=\"width: 100%; border-collapse: collapse; border: 1px solid; margin-bottom: 1em;\">\n";
			$ret .= "\t<tr style=\"background-color: $row_color; color: black; text-align: center; font-size: 0.8em;\">\n";
			$ret .= "\t\t<td style=\"width: 8%; border: 1px solid;\"><b>#$count</b>$dbn</td>\n";
			$ret .= "\t\t<td style=\"width: 15%; border: 1px solid;\">Time: <b>$query_time seconds</b></td>\n";
			$ret .= "\t\t<td style=\"width: 37%; border: 1px solid;\"><b>{$item['called_from_file']}</b> line <b>#{$item['called_from_line']}</b>$my_count</td>\n";
			$ret .= "\t\t<td style=\"width: 20%; border: 1px solid;\">Return: <b>{$item['return_type']}</b></td>\n";
			$ret .= "\t\t<td style=\"width: 20%; border: 1px solid;\">Returned: <b>{$item['records_returned']}</b></td>\n";
			$ret .= "\t</tr>\n";
			$ret .= "\t<tr>\n";
			$ret .= "\t\t<td colspan=\"5\"><div style=\"font-family: monospace; background-color: $sql_bg; color: black; font-size: 1.2em; padding: .2em;\">" . nl2br($this->sql_clean($item['sql'])) . "</div></td>\n";
			if ($item['parameter_values']) {
				$colors = array('#DCDCDC','#F6F6F6');

				// Massage the values before we output them
				$value_count = 0;
				foreach ($item['parameter_values'] as &$item2) {
					if ($item2 === NULL) { $item2 = "<b style=\"color: #811D0D;\">NULL</b>"; }
					elseif ($item2 === "") { $item2 = "<b style=\"color: #811D0D;\">NULL_STRING</b>"; }
					else { $item2 = htmlentities($item2); }

					$value_count++;
					$color = $colors[$value_count % sizeof($colors)];
					$item2 = "<span style=\"background-color: $color\">$item2</span>";
				}
				$ret .= "\t\t<tr  style=\"border-top: 1px solid #bbb; background-color: $sql_bg;\"><td colspan=\"5\" style=\"font-size: 0.8em;\"><div class=\"wide\"><b>Values</b>: " . join(" ", $item['parameter_values']) . "</div></td></tr>\n";
			}
			$ret .= "\t</tr>\n";

			if ($item['error_code']) {
				$ret .= "\t<tr>\n";
				$ret .= "\t\t<td colspan=\"5\"><br /><span style=\"color: red; font-weight: bold;\">Error:</span> " . $item['error_text'] . "</td>\n";
				$ret .= "\t</tr>\n";
			}

			$ret .= "</table>\n";

			$total_time += $item['exec_time'];
		}

		$total_time = sprintf("%0.3f",$total_time);

		$ret .= "<div><b>Total Queries:</b> $count</div>\n";
		$ret .= "<div><b>Total SQL Execution:</b> $total_time seconds</div>\n";

		return $ret;
	}

	public function sql_clean($sql,$htmlize = 1) {
		$ret = preg_replace("/\n|\r/"," ",$sql); // Make it all one line
		$ret = preg_replace("/\s+/"," ",$ret); // Remove double spaces

		$words = array("FROM","WHERE","INNER JOIN","LEFT JOIN","GROUP BY","LIMIT","VALUES","SET","ORDER BY","OFFSET");

		foreach ($words as $word) {
			$ret = preg_replace("/\b$word\b/i","\n$word",$ret); // Add a \n before each of the "words"
		}

		if ($htmlize) {
			$highlight_words = array("SELECT","INSERT INTO","UPDATE","DELETE","REPLACE INTO","OR","AND","LIKE","HAVING","AS","SHOW TABLES","DROP","BETWEEN","IS","NOT","ASC","DESC","USING","ON",'EXECUTE','UNIX_TIMESTAMP','FROM_UNIXTIME','CREATE');
			$words = array_merge($words,$highlight_words);
			foreach ($words as $word) {
				$ret = preg_replace("/\b$word\b/i","<span style=\"font-weight: bold; color: #0200AE;\"><b>$word</b></span>",$ret); // Add a \n before each of the "words"
			}
		}

		$word = "NULL";
		if ($htmlize) {
			$ret = preg_replace("/\b$word\b/i","<span style=\"font-weight: bold; color: #007804;\"><b>$word</b></span>",$ret); // Add a \n before each of the "words"
		}

		# htmlize = 2 includes the <pre> tags. If you do 1 you just get the HTML
		if ($htmlize == 2) { $ret = "<pre>$ret</pre>"; }

		return $ret;
	}

	public function debug_log($str) {
		if ($this->debug) { print "<div>$str</div>\n"; }
	}

	public function quote($str) {
		return $this->dbh->quote($str);
	}

	public function begin() {
		return $this->dbh->beginTransaction();
	}

	public function commit() {
		return $this->dbh->commit();
	}

	public function rollback() {
		return $this->dbh->rollback();
	}

	public function last_info() {
		// The last element of the info array
		$arr  = $this->db_query_info;
		$info = array_slice($arr,-1,1);

		// Array_slice returns an array, and we only want one
		$ret = $info[0];

		return $ret;
	}

	public function is_cli() {
		if (php_sapi_name() == 'cli') {
			return true;
		}

		return false;
	}
}
