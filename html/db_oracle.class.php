<?php
/**
* ac_db.inc.php: Database class using the PHP OCI8 extension
* @package Oracle
*/
//namespace Oracle; // remed as apache2.2 for some reason didn't like it even when php was 5.4.4
  /**
  * Oracle Database access methods
  * @package Oracle
  * @subpackage Db
  */
  class Db {
    public $schema;
    public $password;
    public $database;
    public $charset;
    public $client_info;
    public $module;
    public $cid;

    /**
    * @var resource The connection resource
    * @access protected
    */
    public $conn = null;
    /**
    * @var resource The statement resource identifier
    * @access protected
    */
    protected $stid = null;
    /**
    * @var integer The number of rows to prefetch with queries
    * @access protected
    */
    protected $prefetch = 100;


    /**
    * Constructor opens a connection to the database
    * @param string $module Module text for End-to-End Application Tracing
    * @param string $cid Client Identifier for End-to-End Application Tracing
    */
    function __construct($schema, $password, $database, $charset, $client_info, $module, $cid) {
      // set default values
	  $this->logEntry = 0;
	  $this->keep_logs = true; // we keep the logs anyhow
      
      $start_time = microtime();
      $this->conn = oci_pconnect($schema, $password, $database, $charset);
      if (!$this->conn) {
        $m = oci_error();
        //throw new \Exception('Cannot connect to database: ' . $m['message']); // the \ is there to make sure that the Exception call is making a call outside of the Oracle namespace
        // not throwing exception, as that would be a fatal error stopping the php, instead we try to reconnect
	
	// reconnecting until there isn't a connection, but max 10 times
	$h = 0;
	while ($h<10 and !$this->conn) {
	  $h++;
	  usleep(100000); // sleep for 0.1 sec
	  $this->conn = oci_pconnect($schema, $password, $database, $charset);
	}
      }
      // Record the "name" of the web user, the client info and the module.
      // These are used for end-to-end tracing in the DB.
      oci_set_client_info($this->conn, $client_info);
      oci_set_module_name($this->conn, $module);
      oci_set_client_identifier($this->conn, $cid); // to register the username who generated this
      
	  $duration = $this->microtime_diff($start_time, microtime());
      $this->add2log("DB: Connection has been opened to the '". $schema ."' schema on '". $database ."' with client info of '".$client_info."' and '".$cid."' as user and it took ". $duration ."s");
    }

    /**
    * Destructor closes the statement and connection - not necesary because - Because of
    * PHP's reference counting mechanism, the destructor shown simply
    * emulates the default behavior when an instance of the object is destroyed. Statement
    * and connection resources will be terminated when variables referencing them are
    * destroyed. This particular implementation of the destructor could therefore be
    * omitted.
    */
    function __destruct() {
      if ($this->stid)
        oci_free_statement($this->stid);
      if ($this->conn)
        oci_close($this->conn);
    }
	


    /**
    * Run a SQL or PL/SQL statement
    *
    * Call like:
    * Db::execute("insert into mytab values (:c1, :c2)",
    * "Insert data", array(array(":c1", $c1, -1),
    * array(":c2", $c2, -1)))
    *
    * For returned bind values:
    * Db::execute("begin :r := myfunc(:p); end",
    * "Call func", array(array(":r", &$r, 20),
    * array(":p", $p, -1)))
    *
    * Note: this performs a commit. (auto-commit)
    *
    * @param string $sql The statement to run
    * @param string $action Action text for End-to-End Application Tracing
    * @param array $bindvars Binds. An array of (bv_name, php_variable, length)
    */
    public function execute($sql, $action, $bindvars = array(), $return_into = null, $return_stid = null) {
      $start_time = microtime(); // to measure the speed
      
      $this->stid = oci_parse($this->conn, $sql);
      if (!$this->stid) {
        $m = oci_error($this->conn);
        $this->add2log('Error with parsing the statement: ' . $m['message']);
        throw new \Exception('Error with parsing the statement: ' . $m['message']);
      }
      
      if ($this->prefetch >= 0) {
        oci_set_prefetch($this->stid, $this->prefetch);
      }
      foreach ($bindvars as $bv) {
      // oci_bind_by_name(resource, bv_name, php_variable, length)
        oci_bind_by_name($this->stid, $bv[0], $bv[1], $bv[2]);
      }
      if ($return_into != null) { // if it was requested to return a value into a variable (output value)
        oci_bind_by_name($this->stid, ":".$return_into, $returning_id, 32);
      }
      oci_set_action($this->conn, substr($action, 0, 32));
      
      $r = oci_execute($this->stid); // will auto commit
      //echo "SQL: ". $sql ."\n Bindvars: ". json_encode($bindvars);
      
      if (!$r) { // error handling in case if there is an sql error in execute
        $m = oci_error($this->stid);
        $this->add2log("Error with statement execution: " . $m['message'] . "\n SQL: ". $sql ."\n Bindvars: ". json_encode($bindvars));
        throw new \Exception("Error with statement execution: " . $m['message'] . "\n SQL: ". $sql ."\n Bindvars: ". json_encode($bindvars));
      }
      //oci_execute($this->stid, OCI_NO_AUTO_COMMIT); // this would not auto commit
      //oci_commit($this->conn); // this would execute the commit
      $num_rows_affected = oci_num_rows($this->stid); // num of rows affected
      
      $duration = $this->microtime_diff($start_time, microtime());
      //$this->add2log("DB: SQL has been parsed and executed: '". $sql ."'\n Bindvars: '". json_encode($bindvars) ."'\n and it took ". $duration ."s to execute");
      //echo "DB: SQL has been parsed and executed: '". $sql ."'\n Bindvars: '". json_encode($bindvars) ."'\n and it took ". $duration ."s to execute";
       
      if ($return_into != null) { // if it was requested to return a value into a variable
	  return $returning_id;
      }
	  
      if ($return_stid != null) { // if it was requested to return the stid (for example if they want to do oci_featch_array)
	  return $this->stid;
      }
    }


    /**
    * Run a query and return all rows.
    *
    * @param string $sql A query to run and return all rows
    * @param string $action Action text for End-to-End Application Tracing
    * @param array $bindvars Binds. An array of (bv_name, php_variable, length)
    * @return array An array of rows
    */
    public function execFetchAll($sql, $action, $bindvars = array()) {
      $r = $this->execute($sql, $action, $bindvars);
      
      $start_time = microtime(); // to measure the speed of fetching
      oci_fetch_all($this->stid, $res, 0, -1, OCI_FETCHSTATEMENT_BY_ROW);
      $this->stid = null; // free the statement resource, same as oci_free_statement()
      
      $duration = $this->microtime_diff($start_time, microtime());
      //$this->add2log("DB: SQL has been fetched and it took ". $duration ."s to fetch");
      //echo " ---- $sql DB: SQL has been fetched and it took ". $duration ."s to fetch";
      
      return($res);
    }


    /**
    * Run a query and return a subset of records. Used for paging through
    * a resultset.
    *
    * The query is used as an embedded subquery. Don't permit user
    * generated content in $sql because of the SQL Injection security issue
    * 
    * @param string $sql The query to run
    * @param string $action Action text for End-to-End Application Tracing
    * @param integer $firstrow The first row number of the dataset to return
    * @param integer $numrows The number of rows to return
    * @param array $bindvars Binds. An array of (bv_name, php_variable, length)
    * @return array Returns an array of rows
    */
    public function execFetchPage($sql, $action, $firstrow = 1, $numrows = 1, $bindvars = array()) {
      //
      $query = 'SELECT *
      FROM (SELECT a.*, ROWNUM AS rnum
      FROM (' . $sql . ') a
      WHERE ROWNUM <= :sq_last)
      WHERE :sq_first <= RNUM';
      
      // Set up bind variables.
      array_push($bindvars, array(':sq_first', $firstrow, -1));
      array_push($bindvars, array(':sq_last', $firstrow + $numrows - 1, -1));
      $res = $this->execFetchAll($query, $action, $bindvars);
      return($res);
    }


    /**
    * Run a call to a stored procedure that returns a REF CURSOR data
    * set in a bind variable. The data set is fetched and returned.
    *
    * Call like Db::refcurexecfetchall("begin myproc(:rc, :p); end",
    * "Fetch data", ":rc", array(array(":p", $p, -1)))
    * The assumption that there is only one refcursor is an artificial
    * limitation of refcurexecfetchall()
    *
    * @param string $sql A SQL string calling a PL/SQL stored procedure
    * @param string $action Action text for End-to-End Application Tracing
    * @param string $rcname the name of the REF CURSOR bind variable
    * @param array $otherbindvars Binds. Array (bv_name, php_variable, length)
    * @return array Returns an array of tuples
    */
    public function refcurExecFetchAll($sql, $action, $rcname, $otherbindvars = array()) {
      $start_time = microtime(); // to measure the speed
      
      $this->stid = oci_parse($this->conn, $sql);
      $rc = oci_new_cursor($this->conn);
      oci_bind_by_name($this->stid, $rcname, $rc, -1, OCI_B_CURSOR);
      foreach ($otherbindvars as $bv) {
        // oci_bind_by_name(resource, bv_name, php_variable, length)
        oci_bind_by_name($this->stid, $bv[0], $bv[1], $bv[2]);
      }
      oci_set_action($this->conn, substr($action,0,32));
      oci_execute($this->stid);
      if ($this->prefetch >= 0) {
        oci_set_prefetch($rc, $this->prefetch); // set on the REFCURSOR
      }
      oci_execute($rc); // run the ref cursor as if it were a statement id
      oci_fetch_all($rc, $res);
      $this->stid = null;
      
	  $duration = $this->microtime_diff($start_time, microtime());
      //$this->add2log("DB: refcurExecFetch has been parsed, executed and fetched: '". $sql ."'\n Bindvars: '". json_encode($otherbindvars) ."'\n and it took ". $duration ."s in total");
      
      return($res);
    }


    /**
    * Insert an array of values by calling a PL/SQL procedure - example:
    * CREATE OR REPLACE PACKAGE equip_pkg AS
    *  TYPE arrtype IS TABLE OF VARCHAR2(20) INDEX BY PLS_INTEGER;
    *  PROCEDURE insert_equip(eid_p IN NUMBER, eqa_p IN arrtype);
    *  END equip_pkg;
    *  /
    *  CREATE OR REPLACE PACKAGE BODY equip_pkg AS
    * PROCEDURE insert_equip(eid_p IN NUMBER, eqa_p IN arrtype) IS
    *  BEGIN
    *  FORALL i IN INDICES OF eqa_p
    *  INSERT INTO equipment (employee_id, equip_name)
    *  VALUES (eid_p, eqa_p(i));
    *  END insert_equip;
    *  END equip_pkg;
    *  /
    *
    * Call like Db::arrayinsert("begin myproc(:arn, :p); end",
    * "Insert stuff",
    * array(array(":arn", $dataarray, SQLT_CHR)),
    * array(array(":p", $p, -1)))
    *
    * @param string $sql PL/SQL anonymous block
    * @param string $action Action text for End-to-End Application Tracing
    * @param array $arraybindvars Bind variables. An array of tuples
    * @param array $otherbindvars Bind variables. An array of tuples
    */
    public function arrayInsert($sql, $action, $arraybindvars, $otherbindvars = array()) {
      $start_time = microtime(); // to measure the speed
      
      $this->stid = oci_parse($this->conn, $sql);
      foreach ($arraybindvars as $a) {
        // oci_bind_array_by_name(resource, bv_name, php_array, php_array_length, max_item_length, datatype)
        oci_bind_array_by_name($this->stid, $a[0], $a[1], count($a[1]), -1, $a[2]);
      }
      foreach ($otherbindvars as $bv) {
        // oci_bind_by_name(resource, bv_name, php_variable, length)
        oci_bind_by_name($this->stid, $bv[0], $bv[1], $bv[2]);
      }
      oci_set_action($this->conn, substr($action, 0, 32));  
      $r = oci_execute($this->stid); // will auto commit
      
      if (!$r) { // error handling in case if there is an sql error in execute
        $m = oci_error($this->stid);
        $this->add2log("Error with statement execution: " . $m['message'] . "\n SQL: ". $sql ."\n Bindvars: ". json_encode($bindvars));
        throw new \Exception("Error with statement execution: " . $m['message'] . "\n SQL: ". $sql ."\n Bindvars: ". json_encode($bindvars));
      }
      $this->stid = null;
      
	  $duration = $this->microtime_diff($start_time, microtime());
      //$this->add2log("DB: arrayInsert has been parsed and executed '". $sql ."'\n Bindvars: '". json_encode($arraybindvars) ."'\n and it took ". $duration ."s in total");
    }



    /**
    * Insert a CLOB
    *
    * $sql = 'INSERT INTO BTAB (BLOBID, BLOBDATA) VALUES(:MYBLOBID, EMPTY_BLOB()) RETURNING BLOBDATA INTO :BLOBDATA'
    * Db::insertblob($sql, 'do insert for X', 'myblobid', $blobdata, array(array(":p", $p, -1)));
    *
    * $sql = 'UPDATE MYBTAB SET blobdata = EMPTY_BLOB() RETURNING blobdata INTO :blobdata'
    * Db::insertblob($sql, 'do insert for X', 'blobdata', $blobdata);
    *
    * @param string $sql An INSERT or UPDATE statement that returns a LOB locator
    * @param string $action Action text for End-to-End Application Tracing
    * @param string $blobbindname Bind variable name of the BLOB in the statement
    * @param string $blob BLOB data to be inserted
    * @param array $otherbindvars Bind variables. An array of tuples
    */
    public function insertClob($sql, $action, $blobbindname, $clob, $otherbindvars = array()) {
      $start_time = microtime(); // to measure the speed
      
      $this->stid = oci_parse($this->conn, $sql);
      $dlob = oci_new_descriptor($this->conn, OCI_D_LOB);
      oci_bind_by_name($this->stid, $blobbindname, $dlob, -1, OCI_B_CLOB);
      foreach ($otherbindvars as $bv) {
        // oci_bind_by_name(resource, bv_name, php_variable, length)
        oci_bind_by_name($this->stid, $bv[0], $bv[1], $bv[2]);
      }
      oci_set_action($this->conn, substr($action, 0, 32));
      $dlob->writeTemporary($clob);
      $r = oci_execute($this->stid, OCI_NO_AUTO_COMMIT);
      //$dlob->save($clob); // if the sql is an update then for some reason writeTemporary is no good, but it needs save() - at this position
      // when using update like:
      // UPDATE ".$conf['conn1']['default_database'].".biapps_page_user a
      //	  SET LAST_FILTERS = EMPTY_CLOB()
      //	  WHERE a.PAGE_ID = :page_id
      //	    AND a.USER_ID = :userid
      //	  RETURNING LAST_FILTERS INTO :clobdata
      oci_commit($this->conn);
      
      if (!$r) { // error handling in case if there is an sql error in execute
        $m = oci_error($this->stid);
        $this->add2log("Error with statement execution: " . $m['message'] . "\n SQL: ". $sql ."\n Bindvars: ". json_encode($otherbindvars));
        throw new \Exception("Error with statement execution: " . $m['message'] . "\n SQL: ". $sql ."\n Bindvars: ". json_encode($otherbindvars));
      }
      
      $duration = $this->microtime_diff($start_time, microtime());
      //$this->add2log("DB: insertBlob has been parsed and executed '". $sql ."'\n Bindvars: '". json_encode($arraybindvars) ."'\n and it took ". $duration ."s in total");
      
      return true;
    }

    /**
    * Runs a query that fetches a LOB column
    * @param string $sql A query that include a LOB column in the select list
    * @param string $action Action text for End-to-End Application Tracing
    * @param string $lobcolname The column name of the LOB in the query
    * @param array $bindvars Bind variables. An array of tuples
    * @return string The LOB data
    */
    public function fetchOneLob($sql, $action, $lobcolname, $bindvars = array()) {
      $start_time = microtime(); // to measure the speed
      
      $col = strtoupper($lobcolname);
      $this->stid = oci_parse($this->conn, $sql);
      foreach ($bindvars as $bv) {
        // oci_bind_by_name(resource, bv_name, php_variable, length)
        oci_bind_by_name($this->stid, $bv[0], $bv[1], $bv[2]);
      }
      oci_set_action($this->conn, substr($action,0,32));
      $r = oci_execute($this->stid);
        
      if (!$r) { // error handling in case if there is an sql error in execute
        $m = oci_error($this->stid);
        $this->add2log("Error with statement execution: " . $m['message'] . "\n SQL: ". $sql ."\n Bindvars: ". json_encode($bindvars));
        throw new \Exception("Error with statement execution: " . $m['message'] . "\n SQL: ". $sql ."\n Bindvars: ". json_encode($bindvars));
      }
      $row = oci_fetch_array($this->stid, OCI_RETURN_NULLS);
      $lob = null;
      if (is_object($row[$col])) {
        $lob = $row[$col]->load();
        $row[$col]->free();
      }
      $this->stid = null;
      
	  $duration = $this->microtime_diff($start_time, microtime());
      //$this->add2log("DB: fetchOneLob has been parsed, executed and fetched '". $sql ."'\n Bindvars: '". json_encode($arraybindvars) ."'\n and it took ". $duration ."s in total");
      
      return($lob);
    }








    /**
     * UTILITY FUNCTIONS
     */
    
    /**
     * Used to log db object calls and errors
     */
	public function add2log($line)
	{
      if($this->keep_logs) { // if keep_logs is turned on
		$this->logLines[$this->logEntry] = $line;
		$this->logEntry++;
	  }
	}
	
	/**
     * Returns log as html
     */
	public function logToHtml()
	{
	  return nl2br(implode("<br/>",$this->logLines));
	}
	
	/**
     * Returns log as text
     */
	public function logToText()
	{
	  return implode("\n",$this->logLines);
	}
    
    private function microtime_diff($a, $b) 
	{
	  list($a_dec, $a_sec) = explode(" ", $a);
	  list($b_dec, $b_sec) = explode(" ", $b);
      $diff=number_format((float)(string)($b_sec - $a_sec + $b_dec - $a_dec), 6);
	  return $diff;
	}
  }
?>
