<?php
/**
* MySQLi class wrapper for the Database interface
* 
* @author		Clinton Alexander
* @version		3.0
*/

CleanPHP::import("database.Database");
CleanPHP::import("database.DatabaseConnectionException");
CleanPHP::import("database.DatabaseQueryException");

/**
* Each database instance holds onto a single connection
* and acts as a wrapper to MySQLi intending to 
* abstract out the PHP mess
*
* @package	database\mysqli
*/
class MySQLiDatabase implements Database {
	/**
	* A store of all queries run during this session
	*/
	private	static $queries 	= array();
	/**
	* A counter of the number of queries run during this session
	*/
	private	static $queryCount 	= 0;
	
	/**
	* Current MySQLi connection
	*/
	private $mySQLi;
	
	/** 
	* Create a new MySQLi database with the given connection details
	* 
	* @param	String		Username for database
	* @param	String		Password for database
	* @param	String		Database name
	* @param	String		Host of database
	*/
	public function __construct($username, $password, $dbname, $host = "localhost") {
		/* Add said DB to the arrray */
		$this->mySQLi = new MySQLi($host, $username, $password, $dbname);
	
		/* Check to see if the DB connection is working */
		if($this->mySQLi->connect_errno != 0) {
			throw new DatabaseConnectionException("Could not connect to database: " . $this->mySQLi->connect_error);
		}
	}
	
	//============================
	// Getter functions
	//============================
	
	/**
	* Database query
	* Sends a query. Does not return a result. Just true or false
	* Intended for non-select queries.
	* 
	* @throws	DatabaseQueryException	When the query is invalid
	* @param	String		SQL query to execute
	* @return	boolean		Result of query
	*/
	public function sendQuery($query) {
		self::$queries[] = $query;
		self::$queryCount++;
		
		$output = $this->mySQLi->query($query);
		// Checks for validity and returns true if the query was in any way
		// successful. 
		if($output === false) {
			// If 0 rows affected, return false
			if($this->mySQLi->affected_rows == 0) {
				return false;
			// 0 = no error
			} else if($this->mySQLi->errno != 0) {
				throw new DatabaseQueryException("Invalid query. Errorno: " . $this->mySQLi->errno .
												 ". Error text: " . $this->mySQLi->error . " for query . " . $query);
			}
			return false;
		} else if($output instanceof MySQLi_Result) {
			$output->free_result(); //Make no waste!	
			return true;
		} else if($this->mySQLi->affected_rows == 0) {
			return false;
		// 0 = no error
		} else {
			return true;
		}
	}
	
	/**
	* Prepares, then executes, a query and returns the whether a query was successful
	* Arguments for the prepared statement can either be given as an array in 
	* the second parameter or as n parameters. Requires PHP 5.3 or higher and mysqlnd.
	*
	* @throws	DatabaseQueryException	When a query fails
	* @param	string	query		Query to be sent
	* @param	mixed	param1		array of parameters or N individual parameters
	* @return	bool	True if the query successfully executed
	*/
	public function sendPreparedQuery($query, $param1 = array()) {
		if(is_array($param1)) {
			$args = $param1;
		} else {
			$args = func_get_args();
			array_shift($args);
		}
		
		$statement = $this->getBoundPreparedStatement($query, $args);
		if($statement->execute()) {
			$statement->get_result();
			
			$output = ($statement->affected_rows > 0);
			
			$statement->free_result();			
			return $output;
		} else {
			throw new DatabaseQueryException("Invalid query structure. Errorno: " . $statement->errno .
				". Error text: " . $statement->error . " for query . " . $query);
				
			return false;
		}
	}
	
	/** 
	* Database Query
	* Returns all results as an associative array
	* 
	* @throws	DatabaseQueryException	When a query is invalid
	* @param	string	query		MySQL Query to execute
	* @return	array		Array of results or false
	*/
	public function getQuery($query) {
		return $this->getQueryInternal($query, true);
	}
	
	/**
	* Database query
	* Returns a numeric array
	*
	* @throws	DatabaseQueryException	When a query is invalid
	* @param	string	query		MySQL query to execute
	* @return	Array		Numeric array of results, or false
	*/
	public function getQueryNumeric($query) {
		return $this->getQueryInternal($query, false);
	}
	
	
	/**
	* Prepares, then executes, a query and returns the results as a numeric array
	* of results which contain associative data fields. Arguments for the prepared statement
	* can either be given as an array in the second parameter or as n parameters.
	* Requires PHP 5.3 or higher and mysqlnd.
	*
	* @throws	DatabaseQueryException	When a query fails
	* @param	string	query		Query to be sent
	* @param	mixed	param1		array of parameters or N individual parameters
	* @return	array	Array of results
	*/
	public function getPreparedQuery($query, $param1 = array()) {
		if(is_array($param1)) {
			$args = $param1;
		} else {
			$args = func_get_args();
			array_shift($args);
		}
		
		return $this->getPreparedQueryInternal($query, $args, true);
	}
	
	/**
	* Prepares, then executes, a query and returns the results as a numeric array
	* of results which contain numeric data fields. Arguments for the prepared statement
	* can either be given as an array in the second parameter or as n parameters.
	* Requires PHP 5.3 or higher and mysqlnd.
	*
	* @throws	DatabaseQueryException	When a query fails
	* @param	string	query		Query to be sent
	* @param	mixed	param1		array of parameters or N individual parameters
	* @return	array	Array of results
	*/
	public function getPreparedQueryNumeric($query, $param1 = array()) {
		if(is_array($param1)) {
			$args = $param1;
		} else {
			$args = func_get_args();
			array_shift($args);
		}
		
		return $this->getPreparedQueryInternal($query, $args, false);
	}
	
	/**
	* The internal generalise query processing
	*
	* @throws	DatabaseQueryException	When a query fails
	* @param	string	query		Query to be sent
	* @param	mixed	param1		array of parameters
	* @param	bool	assoc		True if associative
	* @return	Array of results
	*/
	private function getPreparedQueryInternal($query, $args, $assoc) {
		$statement = $this->getBoundPreparedStatement($query, $args);
		if($statement->execute()) {
			if($assoc) {
				$type = MYSQLI_ASSOC;
			} else {
				$type = MYSQLI_NUM;	
			}
			
			// If you get an error here, you might need mysqlnd
			$result = $statement->get_result();
			
			$output = $result->fetch_all($type);
			
			$result->free();
			
			return $output;
		} else {
			throw new DatabaseQueryException("Invalid query structure. Errorno: " . $this->statement->errno .
				". Error text: " . $statement->mySQLi->error . " for query . " . $query);
		}
	}
	
	/**
	* Get a prepared query
	*
	* @throws	DatabaseQueryException	When a query fails
	* @param	string		query		The SQL with which to build the prepared query
	* @return	mysqli_stmt		The prepared query
	*/
	private function getBoundPreparedStatement($query, $args) {
		self::$queryCount++;
		
		$statement = $this->mySQLi->prepare($query);
		if($statement instanceof mysqli_stmt) {
			$size = count($args);
			$types = "";
			for($i = 0; $i < $size; $i++) {
				$arg = $args[$i];
				if(is_double($arg)) {
					$types .= "d";
				} elseif(is_int($arg)) {
					$types .= "i";
				} else {
					$types .= "s";
					if(!is_object($arg)) {
						$args[$i] = (string) $arg;				
					} else if(method_exists($arg, '__toString')) {
						$args[$i] = $arg->__toString();
					} else {
						throw new DatabaseQueryException('Parameter ' . $i .' cannot
						be converted to a string, it is of type: ' . get_class($arg));
					}
				}
			}
			
			// Hack required to call the binding method. One step forwards, two
			// steps backwards.
			$refs = array();
			foreach($args as $key => $value) {
				$refs[$key] = &$args[$key];
			}
			
			array_unshift($refs, $types);
			if((count($args) > 0) && !call_user_func_array(array($statement, 'bind_param'), $refs)) {
				throw new DatabaseQueryException("Invalid query structure. Errorno: " . $this->mySQLi->errno .
					". Error text: " . $this->mySQLi->error . " for query . " . $query);
			}
			
		} else {
			throw new DatabaseQueryException("Invalid query structure. Errorno: " . $this->mySQLi->errno .
				". Error text: " . $this->mySQLi->error . " for query . " . $query);
		}
		
		return $statement;
	}
	
	/** 
	* Database Query
	* Returns all results as an associative array
	* 
	* @throws	DatabaseQueryException	When a query is invalid
	* @param	String		MySQL Query to execute
	* @param	Constant	MySQL Query type
	* @return	Array		Array of results or false
	*/
	private function getQueryInternal($query, $assoc) {
		self::$queries[] = $query;
		self::$queryCount++;
		
		// Var Declarations
		$resultArray = array();
		
		if($assoc) {
			$type = MYSQLI_ASSOC;
		} else {
			$type = MYSQLI_NUM;	
		}
		
		// Checks for validity and assigns $output it's value;
		if(($output = $this->mySQLi->query($query)) === false) {
			throw new DatabaseQueryException("Database error: " . 
								   $this->mySQLi->error . 
								   " for query: " . $query, false);
			return false;
		} elseif ($output === true) {
			return true;
		}
		
		//This section will just strip the mysql object
		// down to an array, associative and numeric.
		// PHP 5.3 
		if(method_exists($output, 'fetch_all')) {
			//This section will just strip the mysql object
			// down to an array, associative and numeric.
			$resultArray = $output->fetch_all($type);
		// PHP 5.2 hack
		} else {
			//This section will just strip the mysql object down to an array, associative and numeric. For both crowds.
			for($x = 0; $x < $output->num_rows; $x++) {
				$resultArray[] = $output->fetch_array($type);
			}
		}
		
		$output->free_result(); //Make no waste!
		
		if($resultArray !== NULL) {
			return $resultArray;
		} else {
			return array();
		}
	}
	
	/**
	* Returns the amount of affected rows of the last query
	*
	* @return	int		Count
	*/
	public function getAffectedRows() {
		// -1 indicates error, including "no query executed"
		if($this->mySQLi->affected_rows == -1) {
			return 0;	
		}
		
		return $this->mySQLi->affected_rows;
	}
	
	/**
	* Gets the last inputted ID
	* 
	* @return	int 	Last ID or NULL
	*/
	public function getInsertID() {
		if($this->mySQLi->insert_id == 0) {
			return NULL;
		}
		
		return $this->mySQLi->insert_id;
	}
	
	/**
	* Get the MySQLi object related to this database abstractor
	*
	* @return	Object related to this abstracted database
	*/
	public function getMySQLiObject() {
		return $this->mySQLi;	
	}
	
	/**
	* Get the number of queries executed on MySQLi databases
	*
	* @return	Number of queries executed on MySQLi databases
	*/
	public static function getTotalQueryCount() {
		return self::$queryCount;
	}
	
	/**
	* Get the array of query strings executed on all MySQLi databases
	*
	* @return	Array of query string executed
	*/
	public static function getAllQueries() {
		return self::$queries;
	}
	
	//============================
	// Processors
	//============================
	
	/** 
	* Sanitises the given input
	* 
	* @param	string		Data to be sanitised
	* @return	String		Sanitised data
	*/
	public function clean($string) {
		return new CoreString($this->mySQLi->real_escape_string((string) $string));	
	}
	
	/**
	* Close the connection to the database
	*
	* @return	void
	*/
	public function closeConnection() {
		$this->mySQLi->close();
	}
}

?>
