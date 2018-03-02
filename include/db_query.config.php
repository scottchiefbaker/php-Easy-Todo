<?PHP

class db_query extends db_core {

	public function __construct($dsn = "",$user = "",$pass = "",$opts = array()) {
		$this->init_db_core();

		if ($dsn) {
			$ret = $this->db_connect($dsn,$user,$pass,$opts);

			return $ret;
		}

		return true;
	}

	public function db_connect($name,$u = "",$p = "",$opts = array()) {
		$name = strtolower($name);

		$this->debug_log("db_connect() on '$name'");

		/////////////////////////////////////////////////////////
		// If all you connect to is a single DB then just
		// use 'default' as your connect name and return
		// an array of the DSN object and the name of the DB
		//
		// return array(new PDO('sqlite:/path/mydb.sqlite'),array('default'));
		//
		// If you use multiple DBs then break each connect
		// apart in to different functions and connect with a different
		// name.
		//
		// db_query will cache the DB connections and switch between
		// them appropriately when requested

		try {
			$dbh = new PDO($name,$u,$p);
		} catch (Exception $e) {
			$msg  = "<p>Unable to connect to database server DSN: \"$name\"</p>";
			$msg .= "<p><b>Error Message:</b> " . $e->getMessage() . "</p>";
			$msg .= "<p><b>Error Code:</b> " . $e->getCode() . "</p>";

			$this->error_out($msg);
			$dbh = null;
		}

		// Build an array of the DB names to cache
		if (!is_array($name)) { $name = array($name); }

		$this->dbh = $dbh;

		// Cache the DB handle
		$this->db($name,$u,$p,$dbh);

		return array($dbh,$name);
	}
}
