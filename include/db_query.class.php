<?PHP

////////////////////////////////////////////////////////////////////

class db_query {
	function __construct($dsn,$un='',$pwd='') {
		$this->connect($dsn,$un,$pwd);
	}

	private function connect($dsn,$user,$pass) {
		if (preg_match("/sqlite/i",$dsn)) { $db_type = "Sqlite"; }
		elseif (preg_match("/mysql/i",$dsn)) { $db_type = "MySQL"; }
		elseif (preg_match("/pgsql/i",$dsn)) { $db_type = "Postgres"; }

		#print "<div><b>DB Type:</b> $db_type</div>";
		$this->db_type = $db_type;

		$persist = 1;
		if ($persist) { $opts = array(PDO::ATTR_PERSISTENT => true); }
	
		try {
			$this->dbh = new PDO($dsn,$user,$pass,$opts);

			$db = preg_replace("/sqlite:/",'',$dsn);
			if (preg_match("/sqlite/",$dsn)) { $this->sanity_check($db); }
		} catch (PDOException $e) {
			$this->error("<b>Connect Error:</b> " . $e->getMessage());
		}
	}

	function sanity_check($db) {
		if (!is_writable($db)) {
			$this->error("Database is not writable: '$db'");
		} elseif (!is_writable(dirname($db))) {
			$this->error("Database directory is not writable: '" . dirname($db) . "'");
		} elseif (!is_readable($db)) {
			$this->error("Database is not readable: '$db'");
		}
	}

	function error($msg) {
		print "<div style=\"text-align: center;\">$msg</div>";
		exit;
	}

	function sql_clean($sql,$htmlize = 1) {
		$ret = preg_replace("/\n|\r/","",$sql); // Make it all one line
		$ret = preg_replace("/\s+/"," ",$ret); // Remove double spaces
	
		$words = array("FROM","WHERE","INNER JOIN","LEFT JOIN","GROUP BY","LIMIT","VALUES","SET","ORDER BY","OFFSET");
	
		foreach ($words as $word) {
			$ret = preg_replace("/\b$word\b/i","\n$word",$ret); // Add a \n before each of the "words"
		}

		if ($htmlize) {
			$highlight_words = array("SELECT","INSERT INTO","UPDATE","DELETE","REPLACE INTO","OR","AND","LIKE","HAVING","AS","SHOW TABLES","DROP","BETWEEN","IS","NULL","NOT","ASC","DESC","USING","ON","CREATE","TABLE");
			$words = array_merge($words,$highlight_words);
			foreach ($words as $word) {
				$ret = preg_replace("/\b$word\b/i","<span style=\"color: #010170;\"><b>$word</b></span>",$ret); // Add a \n before each of the "words"
			}
		}
	
		return $ret;
	}

	function summary() {
		if (!$this->query_info) { return ""; }
		
		foreach ($this->query_info as $item) {
			$count++;
		
			$ret .= "<table style=\"margin-bottom: 1em; width: 100%; border-collapse: collapse;\">\n";
			$ret .= "\t<tr style=\"background-color: #dadbff;\">\n";
			$ret .= "\t\t<td style=\"width: 10%; border: 1px solid;\">#$count</td>\n";
			$ret .= "\t\t<td style=\"width: 50%; border: 1px solid;\">Execution Time: {$item['exec_time']} seconds</td>\n";
			$ret .= "\t\t<td style=\"width: 20%; border: 1px solid;\">Return: <b>{$item['return_type']}</b></td>\n";
			$ret .= "\t\t<td style=\"width: 20%; border: 1px solid;\">Records returned: {$item['records_returned']}</td>\n";
			$ret .= "\t</tr>\n";
			$ret .= "\t<tr>\n";
			$ret .= "\t\t<td colspan=\"4\" style=\"width: 20%; border: 1px solid;\"><div style=\"font-family: monospace; margin: 0;\">" . nl2br($this->sql_clean($item['sql'])) . "</div></td>\n";
			$ret .= "\t</tr>\n";
			
			if ($item['error']) {
				$ret .= "\t<tr>\n";
				$ret .= "\t\t<td colspan=\"4\"><span class=\"error\">Error:</span> " . $item['error'] . "</td>\n";
				$ret .= "\t</tr>\n";
			}

			$ret .= "</table>\n";

			$total_time += $item['exec_time'];
		}

		$ret .= "<div><b>Total Queries:</b> $count</div>\n";
		$ret .= "<div><b>Total SQL Execution:</b> $total_time seconds</div>\n";

		return $ret;
	}

	function query($sql,$type='',$ext='') {
		$sql = trim($sql);
		if (!$sql) { return array(); }

		if ($debug) { print "Type: $type SQL: $sql<br />"; }

		$sql_html = nl2br($this->sql_clean($sql));
		
		$start = microtime(1);

		// If it's any of these types (i.e. not select) exec() it instead
		if (preg_match("/^(DELETE|UPDATE|REPLACE|DROP|CREATE|INSERT)/i",$sql)) {
			$affected = @$this->dbh->exec($sql);

			$arr = $this->dbh->errorInfo();
			if ($debug) { print_r($arr); print "<br />"; }
			if ($arr[0] != '00000' && $type != 'no_error') {
				$this->error("<b>DB Error $type</b>: $arr[2]<br /><b>SQL:</b> <code>$sql_html</code>");
			}
		} else {
			$sth = @$this->dbh->prepare($sql);

			if (!$sth && $type != 'no_error' ) { 
				$info = $this->dbh->errorInfo();
				$this->error("Error creating <b>\$sth</b> handle.<br /><br /><b>Error Message:</b> $info[2]<br /><b>SQL:</b> <code>$sql_html</code>"); 
			}
			
			$sth->execute();
		}

		// If it's an info_hash with a requested return key
		if (preg_match("/(info_hash)[:\|](.+)/i",$type,$match)) {
			$type = $match[1];
			$hash_key = $match[2];
		}

		///////////////////////////////////////////////////////////
		// Here is where the intelligence of parsing the SQL to  //
		// determining the appropriate piece of data to return   //
		///////////////////////////////////////////////////////////

		if ($type == 'one_data') {
			$ret = $sth->fetch(PDO::FETCH_NUM);
			$ret = $ret[0];
		} elseif ($type == 'info_list') {
			while ($data = $sth->fetch(PDO::FETCH_NUM)) {
				$ret[] = $data;
			}
		} elseif ($type == 'one_column') {
			while ($data = $sth->fetch(PDO::FETCH_NUM)) {
				$ret[] = $data[0];
			}
		} elseif (($type == 'one_row' || preg_match("/^SELECT.*LIMIT 1;?$/si",$sql)) && $type != 'info_hash') {
			$ret = $sth->fetch(PDO::FETCH_ASSOC);

			$type || $type = 'one_row';
		} elseif ($return_type == 'info_hash' || preg_match("/^(SELECT|SHOW)/i",$sql)) {
			while ($data = $sth->fetch(PDO::FETCH_ASSOC)) {
				if ($hash_key) { // If there is a requested return key use that in the array
					$key = $data[$hash_key]; 
					$ret[$key] = $data;
				} else { 
					$ret[] = $data;
				}
			}
			
			$type || $type = 'info_hash';
		} elseif ($type == 'insert_id' || preg_match("/^INSERT/i",$sql)) {
			$_seq_name = $ext['sequence_name'];
			$ret = $this->dbh->lastInsertID($_seq_name);
			$type = 'insert_id';
		} elseif ($type == 'affected_rows' || preg_match("/^(DELETE|UPDATE|REPLACE)/i",$sql)) {
			$ret = $affected;
			$type || $type = 'affected_rows';
		} elseif (preg_match("/^(DROP|CREATE)/i",$sql)) {
			$type || $type = 'none';
		} else {
			$this->error("Don't know the return type for this<br />$sql");
		}
		
		$end = microtime(1);
		$total = sprintf("%.3f",$end - $start);

		// Store some info about this query
		if (!isset($return_recs)) { $return_recs = sizeof($ret); }
		$info = array(
			'sql' => $sql,
			'exec_time' => $total,
			'records_returned' => $return_recs,
			'error' => $err,
			'return_type' => $type,
		);

		$this->query_info[] = $info;

		if (!isset($ret)) { $ret = array(); }

		return $ret;
	}

	function self_test() {
		$sql = "DROP TABLE foo;";
		$dq->query($sql,'no_error');
		
		$sql1 = "CREATE TABLE foo (
			ID INTEGER PRIMARY KEY AUTOINCREMENT,
			First VarChar(30),
			Last VarChar(30) NOT NULL,
			Zip INTEGER
		);";
		
		$sql2 = "CREATE TABLE foo (
			ID INTEGER PRIMARY KEY AUTO_INCREMENT,
			First VarChar(30),
			Last VarChar(30) NOT NULL,
			Zip INTEGER
		);";
		$sql3 = "CREATE TABLE foo (
			ID serial,
			First VarChar(30),
			Last VarChar(30) NOT NULL,
			Zip INTEGER
		);";
		if (strtolower($dq->db_type) == 'sqlite') { $sql = $sql1; }
		if (strtolower($dq->db_type) == 'mysql') { $sql = $sql2; }
		if (strtolower($dq->db_type) == 'postgres') { 
			$_seq_name = 'foo_id_seq'; // Have to specify the field for insertid
			$sql = $sql3; 
		}
		
		$dq->query($sql);
		
		$sql = "INSERT INTO foo (First, Last, Zip) VALUES ('Jason','Doolis',97013);";
		print "Insert ID: " . $dq->query($sql) . "<br />\n";
		
		$sql = "INSERT INTO foo (First, Last, Zip) VALUES ('Thomas','Doolis',97267);";
		print "Insert ID: " . $dq->query($sql) . "<br />\n";
		
		$sql = "INSERT INTO foo (First, Last, Zip) VALUES ('Nicole','Glaven',90210);";
		print "Insert ID: " . $dq->query($sql) . "<br />\n";
		
		$sql = "INSERT INTO foo (Zip) VALUES (17423);";
		#$dq->query($sql);
		
		$sql = "UPDATE foo SET ID = 5 WHERE ID = 3;";
		print "Updated: " . $dq->query($sql) . "<br />\n";
		
		$sql = "DELETE FROM foo WHERE Last = 'Glaven';";
		print "Deleted: " . $dq->query($sql) . "<br />\n";
		
		$sql = "INSERT INTO foo (First, Last, Zip) VALUES ('Nicole','Glaven',90210);";
		print "Insert ID: " . $dq->query($sql) . "<br />\n";
		
		$sql = "SELECT * FROM foo;";
		print "<pre>" . print_r($dq->query($sql),1) . "</pre>";
		
		print $dq->summary();
	}

	function quote($str) {
		return $this->dbh->quote($str);
	}
}

?>
