<?php

class DB
{
    static private $predicted_connections = []; // for predicted connections (will be opened on the first use)
    static public  $connections = [];   // for active connections

    static public  $insert_id       = null;
    static public  $affected_rows   = null;


    // open a PDO connection and add it to the $connections[$name]
    static private function add_connection ($name, $db_host, $db_user, $db_pass, $db_base, $unbuffered = false)
    {
        // first, let's check if there's no connection like this open Already
        if(!$unbuffered) { // don't check unbuffered connections for duplicates
            foreach(self::$connections as $c_name => $c)
            {
                if($c['info'] == ["db_host" => $db_host, "db_user" => $db_user, "db_pass" => $db_pass, "db_base" => $db_base] ) // the arrays match!
                {
                    // there Already IS a connection open with exactly same credentials
                    if($c_name == $name) return self::$connections[$name]['connection']; // wow! this is exactly same connection that was requested! nothing to do, exiting
                    self::$connections[$name] = self::$connections[$c_name]; // copy the connection, no need to open a new one
                    return self::$connections[$name]['connection'];
                }
            }
        }

        $con_info = ['db_host' => $db_host, 'db_user' => $db_user, 'db_pass' => $db_pass, 'db_base' => $db_base];

        $options = [
            PDO::ATTR_TIMEOUT => 10,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ];
        if($unbuffered) {
            $options[ PDO::MYSQL_ATTR_USE_BUFFERED_QUERY ] = false;
        }

        // creating a new database
        try {
            $db = new PDO('mysql:host='.$db_host.';dbname='.$db_base, $db_user, $db_pass, $options);
        }
        catch(PDOException $e)
        {
            $array = [
                'connection' => $con_info,
                'debug' => trim(print_r(debug_backtrace(), 1))
            ];
            throw new DBException("Can not connect to database server: ". $e->getMessage(), $array );
        }


        // adding the new connection to array $connections
        // don't add unbuffered connections to this array: they are all one-time connections
        if(!$unbuffered) self::$connections[$name]  = [ "connection" => $db, "info" => $con_info ];
        return $db;
    }

    // creates a new active connection from a predicted one
    static private function add_predicted($name)
    {
        if(self::is_open($name)) {
            return;
        } // the connection is open already
        if(!self::is_predicted($name)) throw new DBException("DB class: trying to open a predicted connection '".$name."' which is not predicted!");
        $c = self::$predicted_connections[$name];
        self::add_connection($name, $c['db_host'], $c['db_user'], $c['db_pass'], $c['db_base']);
    }


    // Get a connection from the array by $name
    static private function connection($name) { return self::$connections[$name]['connection']; }

    // Create a one-time connection for an unbuffered query
    static private function connection_unbuffered($name)
    {
        if(!self::is_open($name))
        {
            throw new DBException("DB class error: Can't created an unbuffered connection: it has to be connected or predicted!");
        }
        $c = self::$connections[$name]['info'];
        return self::add_connection($name, $c['db_host'], $c['db_user'], $c['db_pass'], $c['db_base'], true);
    }


    // alias of ->open
    static public function create($name, $db_host, $db_user, $db_pass, $db_base) { self::open($name, $db_host, $db_user, $db_pass, $db_base); }
    static public function open  ($name, $db_host, $db_user, $db_pass, $db_base)
    {
        self::add_connection($name, $db_host, $db_user, $db_pass, $db_base );
    }

    static public function predict($name, $db_host, $db_user, $db_pass, $db_base)
    {
        self::$predicted_connections[$name] = ["db_host" => $db_host, "db_user" => $db_user, "db_pass" => $db_pass, "db_base" => $db_base];
    }

    // make sure the default connection is open
    static private function open_default()
    {
        if(!self::is_open('default')) self::open('default', DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_BASE );
    }

    // check if a connection $name is open
    static public function is_open($name)
    {
        return isset(self::$connections[$name]);
    }


    // check if a connection $name is predicted
    static public function is_predicted($name)
    {
        return isset(self::$predicted_connections[$name]);
    }

    // close the connection by $name
    static public function close($name)
    {
        self::$connections[$name]['connection'] = null;
        unset(self::$connections[$name]);
    }


    // =================================================================================================================
    // == NOW, QUERIES =================================================================================================
    // =================================================================================================================

    // Main internal DO_THE_QUERY function

    // possible usage:

    // ::query("SELECT *")
    // ::query("SELECT * ", [parameters] )
    // ::query('database_name', "SELECT * " )
    // ::query('database_name', "SELECT * ", [parameters] )

    static private function do_the_query($arg1, $arg2 = null, $arg3 = null)
    {
        self::open_default(); // just for sure

        if(!isset($arg1))                       throw new DBException("DB class error: empty query!");
        if(is_array($arg1))                     throw new DBException("DB class error: first argument can't ne array!");
        if(is_array($arg2) && is_array($arg3))  throw new DBException("DB class error: second and third arguments can't be arrays at the same time!");

        // by default = $query, [$params]
        $query          = $arg1;
        $params         = $arg2;

        if(isset($arg2) && !is_array($arg2)) // $db, $query, [$params] -> database name passed first
        {
            $database_name  = $arg1;
            $query          = $arg2;
            $params         = $arg3;
        }

        $unbuffered = strpos($database_name, ':unbuffered') !== false;
        $database_name = str_replace(':unbuffered', '', $database_name);
        if($database_name == '') $database_name = 'default';

        if (!self::is_open($database_name) && !self::is_predicted($database_name)) throw new DBException("Trying to query DB connection '{$database_name}' which does not exist and has not been predicted!");
        if (!self::is_open($database_name) && self::is_predicted($database_name)) {
            self::add_predicted($database_name);
            if(!self::is_open($database_name)) throw new DBException("Can't open a predicted '{$database_name}'!");
        }

        if($unbuffered) {
            $db = self::connection_unbuffered($database_name);
        }
        else {
            $db = self::connection($database_name);
        }


        // do the job!
        try {
            $statement = $db->prepare($query);

            $simple_params = null;
            foreach ((array)$params as $k => $v) {
                if ($k === 0) // support for '?' parameters
                {
                    foreach ($params as $param) {
                        if (is_object($param)) throw new DBException("Trying to send an object in \$params array!", ["query" => $query, "params" => $params]);
                        if (is_array($param))  throw new DBException("Trying to send an array in \$params array!", ["query" => $query, "params" => $params]);
                    }
                    $simple_params = $params;
                    break;
                }

                if (is_object($v)) throw new DBException("Trying to send an object in \$params array!", ["query" => $query, "params" => $params]);
                if (is_array($v))  throw new DBException("Trying to send an array in \$params array!", ["query" => $query, "params" => $params]);

                $statement->bindParam(':' . str_replace([' ', ':'], '', $k), $v); // support for ':name' parameters
            }
        }
        catch(Exception $e) { throw new DBException($e->getMessage(), ["query" => $query, "params" => $params] ); }

        self::try_execute_the_query($statement, $simple_params, $query, $params);

        self::$insert_id     = $db->lastInsertId();
        self::$affected_rows = $statement->rowCount();

        return $statement;
    }



    // Internal function, used by do_the_query()
    // tries to execute a statement multiple times to avoid deadlocks

    static private function try_execute_the_query($statement, $simple_params, $query, $params)
    {
        $repeats            = 0; // repeats counter
        $repeats_limit      = 5; // limit the number of repeats to this number
        $delay              = 0; // delay in seconds. First delay length, after this it starts incrementing
        $delay_increment    = 1; // delay increment, in seconds, after each try

        do {
            $repeat_again = false;

            try {
                $statement->execute($simple_params);
            }
            catch (Exception $e)
            {
                if (
                    $e instanceof PDOException  &&
                    $e->errorInfo[0] == 40001   &&  // (ISO/ANSI) Serialization failure, e.g. timeout or deadlock
                    $e->errorInfo[1] == 1213    &&  // (MySQL SQLSTATE) ER_LOCK_DEADLOCK
                    $repeats < $repeats_limit       // don't repeat indefinitely
                )
                {
                    if($delay > 0) sleep($delay);
                    $delay += $delay_increment;
                    $repeats ++;
                    $repeat_again = true;
                }
                else throw new DBException($e->getMessage(), ["query" => $query, "params" => $params]);
            }

        }
        while($repeat_again);
    }




    // =================================================================================================================
    // == Now, other useful functions
    // =================================================================================================================

    static public function query($a1, $a2 = null, $a3 = null)
    {
        return new DBIterator( self::do_the_query($a1, $a2, $a3) );
    }

    // Insert: does exactly same as $query, but returns the LastInsertID
    static public function insert($a1, $a2 = null, $a3 = null)
    {
        self::query($a1, $a2, $a3);
        return self::$insert_id;
    }

    // Result: returns the only result value. Used for, say, SELECT COUNT(*)
    static public function result($a1, $a2 = null, $a3 = null)
    {
        try { return self::query($a1, $a2, $a3)->fetch_row()[0]; }
        catch(PDOException $e) { throw new DBException( $e->getMessage() ); return null; }
    }

    // Assoc: returns one associative array of data
    static public function assoc($a1, $a2 = null, $a3 = null)
    {
        try { return self::query($a1, $a2, $a3)->fetch_assoc(); }
        catch(PDOException $e) { throw new DBException($e->getMessage()); return null; }
    }

    // Object: returns one Object of data, one sigle row
    static public function obj($a1, $a2 = null, $a3 = null) { return self::object($a1,$a2,$a3); }
    static public function object($a1, $a2 = null, $a3 = null)
    {
        try { return self::query($a1, $a2, $a3)->fetch_obj(); }
        catch(PDOException $e) { throw new DBException($e->getMessage()); return null;  }
    }

    // Row: returns one numerated array of data, w/o keys association
    static public function row($a1, $a2 = null, $a3 = null)
    {
        try { return self::query($a1, $a2, $a3)->fetch_row(); }
        catch(PDOException $e) { throw new DBException($e->getMessage()); return null; }
    }

    // Rows, Assocs, Objects: returns a DBIterator for "foreach" returning associative arrays
    static public function rows     ($a1, $a2 = null, $a3 = null) { return new DBIterator( self::do_the_query($a1, $a2, $a3), PDO::FETCH_NUM ); }
    static public function assocs   ($a1, $a2 = null, $a3 = null) { return new DBIterator( self::do_the_query($a1, $a2, $a3), PDO::FETCH_ASSOC ); }
    static public function objects  ($a1, $a2 = null, $a3 = null) { return new DBIterator( self::do_the_query($a1, $a2, $a3), PDO::FETCH_OBJ ); }

    // All_rows: returns an array of numerated arrays
    static public function all_row ($a1, $a2, $a3 = null){ return self::all_rows($a1,$a2,$a3);}
    static public function all_rows($a1, $a2 = null, $a3 = null)
    {
        try {
            $q = self::rows($a1, $a2, $a3);
            $r = []; foreach($q as $o) $r[]= $o; return $r;
        }
        catch(PDOException $e) { throw new DBException($e->getMessage()); return null; }
    }

    // All_assoc: returns an array of associative arrays
    static public function all_assocs($a1, $a2 = null, $a3 = null){ return self::all_assoc($a1,$a2,$a3);}
    static public function all_assoc($a1, $a2 = null, $a3 = null)
    {
        try {
            $q = self::assocs($a1, $a2, $a3);
            $r = []; foreach($q as $o) $r[]= $o; return $r;
        }
        catch(PDOException $e) { throw new DBException($e->getMessage()); return null; }
    }

    // All_obj: returns an array of objects
    static public function all_object($a1, $a2 = null, $a3 = null){ return self::all_obj($a1,$a2,$a3);}
    static public function all_objects($a1, $a2 = null, $a3 = null){ return self::all_obj($a1,$a2,$a3);}
    static public function all_obj($a1, $a2 = null, $a3 = null)
    {
        try {
            $q = self::objects($a1, $a2, $a3);
            $r = []; foreach($q as $o) $r[]= $o; return $r;
        }
        catch(PDOException $e) { throw new DBException($e->getMessage()); return null; }
    }

    // escape: used for escaping strings for queries
    static public function real_escape_string($s) { return self::escape($s); } // alias for ->escape()
    static public function escape($s)
    {
        self::open_default();
        $s = self::connection('default')->quote($s);
        return substr($s, 1, count($s)-2); // removing single quotes 'around'
    }

}



class DBIterator implements Iterator
{
    private $result = null;
    private $key    = null;
    private $value  = null;

    function __construct($PDOresult, $mode = null )
    {
        $this->result = $PDOresult;
        if(isset($mode)) $this->result->setFetchMode($mode);
    }


    // PDO support =====================================================================================================
    function fetch($mode = PDO::FETCH_BOTH)     { try { return $this->result->fetch($mode); }           catch(PDOException $e) { throw new DBException($e->getMessage()); } }
    function fetchAll($mode = PDO::FETCH_BOTH)  { try { return $this->result->fetchAll($mode); }        catch(PDOException $e) { throw new DBException($e->getMessage()); } }
    function fetchObject($class)                { try { return $this->result->fetchObject($class); }    catch(PDOException $e) { throw new DBException($e->getMessage()); } }
    function fetchColumn($n = 0)                { try { return $this->result->fetchColumn($n); }        catch(PDOException $e) { throw new DBException($e->getMessage()); } }
    function rowCount()                         { try { return $this->result->rowCount(); }             catch(PDOException $e) { throw new DBException($e->getMessage()); } }


    // Negotiator PDO class support (until we refactor it!) ============================================================
    function fetch_obj($class = null)
    {
        if($class === null) { try { return $this->result->fetch(PDO::FETCH_OBJ); } catch(PDOException $e) { throw new DBException($e->getMessage()); } }
        $d = $this->fetch_assoc();
        if(!$d) return false;
        $o = new $class;
        foreach($d as $k=>$v) $o->$k = $v;
        if( method_exists($o, '__construct') ) $o->__construct();
        return $o;
    }
    function fetch_all_assoc(){ return $this->fetchAll(PDO::FETCH_ASSOC); }
    function fetch_all_obj($class = null)  {
        if(!isset($class)) return $this->fetchAll(PDO::FETCH_OBJ);
        $ret = [];
        while($v = $this->fetch_obj($class)) $ret[]= $v;
        return $ret;
    }


    // MySQLi support ==================================================================================================
    function fetch_object($class = null) { return $this->fetch_obj($class); }
    function fetch_assoc () { try { return $this->result->fetch(PDO::FETCH_ASSOC); }    catch(PDOException $e) { throw new DBException($e->getMessage()); } }
    function fetch_row   () { try { return $this->result->fetch(PDO::FETCH_NUM); }      catch(PDOException $e) { throw new DBException($e->getMessage()); } }
    function fetch_array () { try { return $this->result->fetch(PDO::FETCH_BOTH); }     catch(PDOException $e) { throw new DBException($e->getMessage()); } }


    //  Iterators (foreach) support ====================================================================================
    function rewind (){ $this->key = -1; }
    function valid  ()
    {
        $this->key++;
        try { $this->value = $this->result->fetch(); } catch(PDOException $e) { throw new DBException($e->getMessage()); }
        return $this->value !== false;
    }
    function next   () { }
    function key    (){ return $this->key; }
    function current(){ return $this->value; }
}




class DBException extends Exception
{
    public $message = '';
    public $array  = [];
    function __construct($message, $array = [])
    {
        $this->message  = $message;
        $this->array    = $array;
        $this->debug    = trim(print_r(debug_backtrace(), true));
        parent::__construct($message);
    }
    function __toString(){
        $s = 'Database error: '.$this->message;
        if($this->array) $s .= "\n".trim(print_r($this->array, true));
        $s .= $this->debug;
        return $s;
    }
}



if(!function_exists('SQLPrep')){
    function SQLPrep($s){ return DB::escape($s); }
}
