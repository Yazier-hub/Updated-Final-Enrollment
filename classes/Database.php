<?php
// classes/Database.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • Database name changed from 'lms' to 'kms'   ← CRITICAL
//   • execute() now binds values with proper PDO types (INT / BOOL / NULL / STR)

class Database {
    private static $instance = null;
    private $connection;
    private $host     = 'localhost';
    private $username = 'root';
    private $password = '';
    private $database = 'kms';                     // ← FIXED
    private $port     = 3306;
    private $charset  = 'utf8mb4';
    private $options;
    private $connected = false;
    private $lastError = null;
    private $queryLog = [];
    private $enableLogging = false;
    private $transactionLevel = 0;

    private function __construct() {
        $this->options = [
            PDO::ATTR_ERRMODE               => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE    => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES      => false,
            PDO::ATTR_STRINGIFY_FETCHES     => false,
            PDO::MYSQL_ATTR_INIT_COMMAND    => "SET NAMES {$this->charset}",
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::ATTR_TIMEOUT               => 30,
        ];

        $this->connect();
    }

    /* ============================================================
       CONNECT
    ============================================================ */

    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};port={$this->port};"
                 . "dbname={$this->database};charset={$this->charset}";

            $this->connection = new PDO(
                $dsn,
                $this->username,
                $this->password,
                $this->options
            );

            $this->connected = true;
            $this->lastError = null;

        } catch (PDOException $e) {
            $this->connected = false;
            $this->lastError = $e->getMessage();
            error_log('Database Connection Error: ' . $e->getMessage());

            if ($this->isDevelopment()) {
                die("Database Connection Failed: " . $e->getMessage());
            }
            die("Database connection error. Please try again later.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        if (!$this->connected || $this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    public function isConnected() {
        return $this->connected;
    }

    public function getLastError() {
        return $this->lastError;
    }

    /* ============================================================
       PREPARE / QUERY / EXECUTE
    ============================================================ */

    public function prepare($sql) {
        try {
            if (!$this->connected || $this->connection === null) {
                $this->connect();
            }
            return $this->connection->prepare($sql);
        } catch (PDOException $e) {
            error_log('Prepare Error: ' . $e->getMessage());
            error_log('SQL: ' . $sql);
            throw $e;
        }
    }

    public function query($sql) {
        try {
            if (!$this->connected || $this->connection === null) {
                $this->connect();
            }
            $this->logQuery($sql);
            return $this->connection->query($sql);
        } catch (PDOException $e) {
            error_log('Query Error: ' . $e->getMessage());
            error_log('SQL: ' . $sql);
            throw $e;
        }
    }

    /**
     * Execute a prepared statement with typed parameter binding.
     * FIX: bind with proper PDO type (INT / BOOL / NULL / STR)
     */
    public function execute($sql, $params = []) {
        try {
            if (!$this->connected || $this->connection === null) {
                $this->connect();
            }

            $stmt = $this->connection->prepare($sql);
            $this->logQuery($sql, $params);

            foreach ($params as $key => $value) {
                $target = is_int($key) ? $key + 1 : $key;
                $this->bindValue($stmt, $target, $value);
            }

            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            error_log('Query Execution Error: ' . $e->getMessage());
            error_log('SQL: ' . $sql);
            error_log('Params: ' . print_r($params, true));
            throw $e;
        }
    }

    private function bindValue($stmt, $key, $value) {
        if (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } elseif (is_bool($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_BOOL);
        } elseif (is_null($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }

    public function lastInsertId() {
        if (!$this->connected || $this->connection === null) {
            $this->connect();
        }
        return $this->connection->lastInsertId();
    }

    /* ============================================================
       TRANSACTIONS
    ============================================================ */

    public function beginTransaction() {
        try {
            if (!$this->connected || $this->connection === null) {
                $this->connect();
            }
            if ($this->transactionLevel === 0) {
                $this->connection->beginTransaction();
            }
            $this->transactionLevel++;
            return true;
        } catch (PDOException $e) {
            error_log('Begin Transaction Error: ' . $e->getMessage());
            return false;
        }
    }

    public function commit() {
        try {
            if (!$this->connected || $this->connection === null) {
                $this->connect();
            }
            $this->transactionLevel--;
            if ($this->transactionLevel <= 0) {
                $this->transactionLevel = 0;
                return $this->connection->commit();
            }
            return true;
        } catch (PDOException $e) {
            error_log('Commit Error: ' . $e->getMessage());
            return false;
        }
    }

    public function rollBack() {
        try {
            if (!$this->connected || $this->connection === null) {
                $this->connect();
            }
            $this->transactionLevel = 0;
            if ($this->connection->inTransaction()) {
                return $this->connection->rollBack();
            }
            return true;
        } catch (PDOException $e) {
            error_log('Rollback Error: ' . $e->getMessage());
            return false;
        }
    }

    public function inTransaction() {
        if (!$this->connected || $this->connection === null) {
            $this->connect();
        }
        return $this->connection->inTransaction();
    }

    public function getTransactionLevel() {
        return $this->transactionLevel;
    }

    /* ============================================================
       UTILITIES
    ============================================================ */

    public function escape($string) {
        if (!$this->connected || $this->connection === null) {
            $this->connect();
        }
        return $this->connection->quote($string);
    }

    public function getTableColumns($table) {
        try {
            $sql  = "SHOW COLUMNS FROM `{$table}`";
            $stmt = $this->execute($sql);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error getting table columns: ' . $e->getMessage());
            return [];
        }
    }

    public function tableExists($table) {
        try {
            $sql  = "SHOW TABLES LIKE ?";
            $stmt = $this->execute($sql, [$table]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Error checking table existence: ' . $e->getMessage());
            return false;
        }
    }

    public function getDatabaseSize() {
        try {
            $sql = "SELECT
                        SUM(data_length + index_length) as size,
                        SUM(data_length) as data_size,
                        SUM(index_length) as index_size,
                        COUNT(*) as table_count
                    FROM information_schema.TABLES
                    WHERE table_schema = ?";
            $stmt = $this->execute($sql, [$this->database]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error getting database size: ' . $e->getMessage());
            return null;
        }
    }

    public function getTableStats() {
        try {
            $sql = "SELECT
                        table_name,
                        table_rows as row_count,
                        data_length + index_length as size,
                        data_length as data_size,
                        index_length as index_size,
                        create_time,
                        update_time
                    FROM information_schema.TABLES
                    WHERE table_schema = ?
                    ORDER BY table_name";
            $stmt = $this->execute($sql, [$this->database]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error getting table stats: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       LOGGING
    ============================================================ */

    public function enableLogging()  { $this->enableLogging = true; }
    public function disableLogging() { $this->enableLogging = false; }
    public function getQueryLog()    { return $this->queryLog; }
    public function clearQueryLog()  { $this->queryLog = []; }

    private function logQuery($sql, $params = []) {
        if ($this->enableLogging) {
            $this->queryLog[] = [
                'sql'    => $sql,
                'params' => $params,
                'time'   => microtime(true)
            ];
        }
    }

    /* ============================================================
       CONFIG / METADATA
    ============================================================ */

    private function isDevelopment() {
        $host  = $_SERVER['HTTP_HOST'] ?? '';
        $debug = defined('DEBUG_MODE') ? DEBUG_MODE : false;

        return $debug
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || strpos($host, 'local') !== false
            || strpos($host, 'dev')   !== false;
    }

    public function setConfig($host = null, $username = null, $password = null, $database = null) {
        if ($host     !== null) $this->host     = $host;
        if ($username !== null) $this->username = $username;
        if ($password !== null) $this->password = $password;
        if ($database !== null) $this->database = $database;

        $this->connected  = false;
        $this->connection = null;
        $this->connect();
    }

    public function getDatabaseName() { return $this->database; }
    public function getHost()         { return $this->host; }

    public function close() {
        $this->connection      = null;
        $this->connected       = false;
        $this->transactionLevel = 0;
    }

    public function __destruct() {
        $this->close();
    }

    private function __clone() {}
    public function __wakeup() {}
}