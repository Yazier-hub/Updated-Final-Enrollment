<?php
// classes/Model.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • filterFillable(): use array_key_exists() so null values survive
//     (needed for updateSection($id, null) to actually clear section_id)
//   • create() / getLastInsertId() / beginTransaction() / commit() / rollBack()
//     now use $this->connection (PDO) directly instead of $this->db (wrapper)
//   • executeQuery(): robust positional vs named param detection
//   • create(): don't overwrite caller-supplied created_at

abstract class Model {
    protected $db;
    protected $connection;
    protected $table;
    protected $primaryKey = 'id';
    protected $softDelete = false;
    protected $deletedAt  = 'deleted_at';
    protected $fillable   = [];
    protected $guarded    = ['id'];
    protected $timestamps = true;
    protected $createdAt  = 'created_at';
    protected $updatedAt  = 'updated_at';
    protected $queryLog   = [];
    protected $enableLogging = false;
    protected $fetchMode = PDO::FETCH_ASSOC;

    public function __construct() {
        $this->db         = Database::getInstance();
        $this->connection = $this->db->getConnection();

        if (empty($this->table)) {
            throw new Exception('Table name must be defined in ' . get_class($this));
        }
    }

    /* ============================================================
       FIND
    ============================================================ */

    public function findAll($conditions = [], $orderBy = null, $limit = null) {
        try {
            $sql    = "SELECT * FROM {$this->table}";
            $params = [];

            if (!empty($conditions)) {
                $sql .= " WHERE ";
                $whereClauses = [];
                foreach ($conditions as $key => $value) {
                    if (is_array($value)) {
                        $placeholders   = implode(',', array_fill(0, count($value), '?'));
                        $whereClauses[] = "{$key} IN ({$placeholders})";
                        $params         = array_merge($params, $value);
                    } elseif (stripos($key, ' LIKE') !== false) {
                        $field          = trim(str_ireplace(['LIKE', 'like'], '', $key));
                        $whereClauses[] = "{$field} LIKE ?";
                        $params[]       = $value;
                    } else {
                        $whereClauses[] = "{$key} = ?";
                        $params[]       = $value;
                    }
                }
                $sql .= implode(' AND ', $whereClauses);
            }

            if ($this->softDelete) {
                $sql .= empty($conditions) ? " WHERE " : " AND ";
                $sql .= "{$this->deletedAt} IS NULL";
            }

            if ($orderBy) {
                $sql .= " ORDER BY {$orderBy}";
            }

            if ($limit) {
                $sql .= " LIMIT {$limit}";
            }

            $stmt = $this->executeQuery($sql, $params);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error in findAll: ' . $e->getMessage());
            return [];
        }
    }

    public function findById($id) {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?";
            if ($this->softDelete) {
                $sql .= " AND {$this->deletedAt} IS NULL";
            }
            $stmt = $this->executeQuery($sql, [$id]);
            return $stmt->fetch($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error in findById: ' . $e->getMessage());
            return null;
        }
    }

    public function findBy($field, $value) {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE {$field} = ?";
            if ($this->softDelete) {
                $sql .= " AND {$this->deletedAt} IS NULL";
            }
            $stmt = $this->executeQuery($sql, [$value]);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error in findBy: ' . $e->getMessage());
            return [];
        }
    }

    public function findWhere($where, $params = [], $orderBy = null, $limit = null) {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE {$where}";
            if ($this->softDelete) {
                $sql .= " AND {$this->deletedAt} IS NULL";
            }
            if ($orderBy) $sql .= " ORDER BY {$orderBy}";
            if ($limit)   $sql .= " LIMIT {$limit}";

            $stmt = $this->executeQuery($sql, $params);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error in findWhere: ' . $e->getMessage());
            return [];
        }
    }

    public function findFirst($conditions = []) {
        $results = $this->findAll($conditions, null, 1);
        return !empty($results) ? $results[0] : null;
    }

    public function count($conditions = []) {
        try {
            $sql    = "SELECT COUNT(*) as count FROM {$this->table}";
            $params = [];

            if (!empty($conditions)) {
                $sql .= " WHERE ";
                $whereClauses = [];
                foreach ($conditions as $key => $value) {
                    if (is_array($value)) {
                        $placeholders   = implode(',', array_fill(0, count($value), '?'));
                        $whereClauses[] = "{$key} IN ({$placeholders})";
                        $params         = array_merge($params, $value);
                    } elseif (stripos($key, ' LIKE') !== false) {
                        $field          = trim(str_ireplace(['LIKE', 'like'], '', $key));
                        $whereClauses[] = "{$field} LIKE ?";
                        $params[]       = $value;
                    } else {
                        $whereClauses[] = "{$key} = ?";
                        $params[]       = $value;
                    }
                }
                $sql .= implode(' AND ', $whereClauses);
            }

            if ($this->softDelete) {
                $sql .= empty($conditions) ? " WHERE " : " AND ";
                $sql .= "{$this->deletedAt} IS NULL";
            }

            $stmt   = $this->executeQuery($sql, $params);
            $result = $stmt->fetch($this->fetchMode);
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error in count: ' . $e->getMessage());
            return 0;
        }
    }

    /* ============================================================
       CREATE
       FIX: don't overwrite caller-supplied created_at
       FIX: use $this->connection->lastInsertId()
    ============================================================ */

    public function create($data) {
        try {
            $data = $this->filterFillable($data);

            if ($this->timestamps) {
                $now = date('Y-m-d H:i:s');
                if (!isset($data[$this->createdAt])) {
                    $data[$this->createdAt] = $now;
                }
                $data[$this->updatedAt] = $now;
            }

            $columns      = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";

            $this->executeQuery($sql, $data);

            $id = $this->connection->lastInsertId();

            return $id ? $this->findById($id) : null;
        } catch (Exception $e) {
            error_log('Error in create: ' . $e->getMessage());
            return false;
        }
    }

    public function createMultiple($records) {
        $results = [];
        $this->beginTransaction();

        try {
            foreach ($records as $data) {
                $result = $this->create($data);
                if (!$result) {
                    $this->rollBack();
                    return false;
                }
                $results[] = $result;
            }
            $this->commit();
            return $results;
        } catch (Exception $e) {
            $this->rollBack();
            error_log('Error in createMultiple: ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       UPDATE
    ============================================================ */

    public function update($id, $data) {
        try {
            $data = $this->filterFillable($data);

            if ($this->timestamps) {
                $data[$this->updatedAt] = date('Y-m-d H:i:s');
            }

            $set = [];
            foreach ($data as $key => $value) {
                $set[] = "{$key} = :{$key}";
            }
            $set = implode(', ', $set);

            $data[$this->primaryKey] = $id;
            $sql = "UPDATE {$this->table} SET {$set} WHERE {$this->primaryKey} = :{$this->primaryKey}";

            $stmt = $this->executeQuery($sql, $data);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Error in update: ' . $e->getMessage());
            return false;
        }
    }

    public function updateMultiple($ids, $data) {
        if (empty($ids)) return false;

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE {$this->table} SET ";

            $set = [];
            foreach ($data as $key => $value) {
                $set[] = "{$key} = ?";
            }
            $sql .= implode(', ', $set);
            $sql .= " WHERE {$this->primaryKey} IN ({$placeholders})";

            $params = array_merge(array_values($data), $ids);
            $stmt = $this->executeQuery($sql, $params);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Error in updateMultiple: ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       DELETE
    ============================================================ */

    public function delete($id) {
        try {
            if ($this->softDelete) {
                return $this->update($id, [$this->deletedAt => date('Y-m-d H:i:s')]);
            }

            $sql  = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
            $stmt = $this->executeQuery($sql, [$id]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Error in delete: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteMultiple($ids) {
        if (empty($ids)) return false;

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            if ($this->softDelete) {
                $sql    = "UPDATE {$this->table} SET {$this->deletedAt} = ?
                           WHERE {$this->primaryKey} IN ({$placeholders})";
                $params = array_merge([date('Y-m-d H:i:s')], $ids);
            } else {
                $sql    = "DELETE FROM {$this->table}
                           WHERE {$this->primaryKey} IN ({$placeholders})";
                $params = $ids;
            }

            $stmt = $this->executeQuery($sql, $params);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Error in deleteMultiple: ' . $e->getMessage());
            return false;
        }
    }

    public function forceDelete($id) {
        try {
            $sql  = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
            $stmt = $this->executeQuery($sql, [$id]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Error in forceDelete: ' . $e->getMessage());
            return false;
        }
    }

    public function restore($id) {
        if (!$this->softDelete) return false;
        try {
            $sql  = "UPDATE {$this->table} SET {$this->deletedAt} = NULL
                     WHERE {$this->primaryKey} = ?";
            $stmt = $this->executeQuery($sql, [$id]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Error in restore: ' . $e->getMessage());
            return false;
        }
    }

    public function getTrashed() {
        if (!$this->softDelete) return [];
        try {
            $sql  = "SELECT * FROM {$this->table} WHERE {$this->deletedAt} IS NOT NULL";
            $stmt = $this->executeQuery($sql);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error in getTrashed: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       PAGINATION
    ============================================================ */

    public function paginate($page = 1, $perPage = 10, $conditions = [], $orderBy = null) {
        $offset  = ($page - 1) * $perPage;
        $results = $this->findAll($conditions, $orderBy, "{$offset}, {$perPage}");
        $total   = $this->count($conditions);

        return [
            'data'         => $results,
            'current_page' => (int) $page,
            'per_page'     => (int) $perPage,
            'total'        => (int) $total,
            'last_page'    => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            'from'         => $total > 0 ? $offset + 1 : 0,
            'to'           => $total > 0 ? min($offset + $perPage, $total) : 0
        ];
    }

    /* ============================================================
       QUERY EXECUTION
       FIX: cleanly separates positional vs named params
    ============================================================ */

    protected function executeQuery($sql, $params = []) {
        try {
            $this->logQuery($sql, $params);
            $stmt = $this->connection->prepare($sql);

            $hasNamed = false;
            foreach ($params as $k => $_) {
                if (!is_int($k)) { $hasNamed = true; break; }
            }

            if ($hasNamed) {
                foreach ($params as $key => $value) {
                    $this->bindParamValue($stmt, $key, $value);
                }
            } else {
                $i = 1;
                foreach ($params as $value) {
                    $this->bindParamValue($stmt, $i, $value);
                    $i++;
                }
            }

            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            error_log('Query Error: ' . $e->getMessage());
            error_log('SQL: ' . $sql);
            error_log('Params: ' . print_r($params, true));
            throw $e;
        }
    }

    private function bindParamValue($stmt, $key, $value) {
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

    /* ============================================================
       FILLABLE
       FIX: array_key_exists() so null survives
    ============================================================ */

    protected function filterFillable($data) {
        if (!empty($this->fillable)) {
            $filtered = [];
            foreach ($this->fillable as $field) {
                if (array_key_exists($field, $data)) {
                    $filtered[$field] = $data[$field];
                }
            }
            return $filtered;
        }
        return $data;
    }

    /* ============================================================
       TRANSACTIONS / ID
       FIX: use $this->connection (PDO) directly
    ============================================================ */

    public function getLastInsertId() {
        return $this->connection->lastInsertId();
    }

    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollBack() {
        return $this->connection->rollBack();
    }

    /* ============================================================
       LOGGING
    ============================================================ */

    public function enableLogging()  { $this->enableLogging = true; }
    public function disableLogging() { $this->enableLogging = false; }
    public function getQueryLog()    { return $this->queryLog; }
    public function clearQueryLog()  { $this->queryLog = []; }

    protected function logQuery($sql, $params = []) {
        if ($this->enableLogging) {
            $this->queryLog[] = [
                'sql'    => $sql,
                'params' => $params,
                'time'   => microtime(true)
            ];
        }
    }

    /* ============================================================
       GETTERS / SETTERS
    ============================================================ */

    public function getTable()      { return $this->table; }
    public function getPrimaryKey() { return $this->primaryKey; }

    public function exists($conditions) {
        return $this->count($conditions) > 0;
    }

    public function firstOrCreate($conditions, $data = []) {
        $record = $this->findFirst($conditions);
        if ($record) {
            return $record;
        }
        return $this->create(array_merge($conditions, $data));
    }

    public function updateOrCreate($conditions, $data) {
        $record = $this->findFirst($conditions);
        if ($record) {
            $this->update($record[$this->primaryKey], $data);
            return $this->findById($record[$this->primaryKey]);
        }
        return $this->create(array_merge($conditions, $data));
    }

    public function getColumns() {
        try {
            $sql  = "SHOW COLUMNS FROM {$this->table}";
            $stmt = $this->executeQuery($sql);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log('Error in getColumns: ' . $e->getMessage());
            return [];
        }
    }

    public function getTableInfo() {
        try {
            $sql  = "SHOW TABLE STATUS WHERE Name = ?";
            $stmt = $this->executeQuery($sql, [$this->table]);
            return $stmt->fetch($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error in getTableInfo: ' . $e->getMessage());
            return null;
        }
    }

    public function setFillable($fillable) { $this->fillable = $fillable; return $this; }
    public function setGuarded($guarded)   { $this->guarded  = $guarded;  return $this; }
    public function enableSoftDelete()     { $this->softDelete = true;    return $this; }
    public function disableSoftDelete()    { $this->softDelete = false;   return $this; }
    public function setFetchMode($mode)    { $this->fetchMode = $mode;    return $this; }

    public function rawQuery($sql, $params = []) {
        try {
            $stmt = $this->executeQuery($sql, $params);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error in rawQuery: ' . $e->getMessage());
            return [];
        }
    }

    public function rawExecute($sql, $params = []) {
        try {
            return $this->executeQuery($sql, $params);
        } catch (Exception $e) {
            error_log('Error in rawExecute: ' . $e->getMessage());
            return false;
        }
    }

    public function getConnection() { return $this->connection; }
    public function getDb()         { return $this->db; }
}