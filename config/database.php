<?php
require_once __DIR__ . '/bootstrap.php';
class Database {
    public $pdo;
    private $tenant_id;

    public function __construct() {
        try {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $db   = $_ENV['DB_DATABASE'] ?? 'inventory';
            $user = $_ENV['DB_USERNAME'] ?? 'root';
            $pass = $_ENV['DB_PASSWORD'] ?? '';
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $this->pdo = new PDO($dsn, $user, $pass, $options);

            // تحميل tenant_id من الجلسة
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $this->tenant_id = $_SESSION['user']['tenant_id'] ?? null;

        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function insert($table, $data) {
        // إضافة tenant_id تلقائيًا إذا لم يكن موجودًا
        if ($this->tenant_id && !array_key_exists('tenant_id', $data)) {
            $data['tenant_id'] = $this->tenant_id;
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        return $stmt->execute();
    }

    public function update($table, $data, $where = []) {
        if ($this->tenant_id && !isset($where['tenant_id'])) {
            $where['tenant_id'] = $this->tenant_id;
        }

        $setParts = [];
        foreach ($data as $col => $val) {
            $setParts[] = "`$col` = :set_$col";
        }
        $setClause = implode(', ', $setParts);

        $whereParts = [];
        foreach ($where as $col => $val) {
            $whereParts[] = "`$col` = :where_$col";
        }
        $whereClause = implode(' AND ', $whereParts);

        $sql = "UPDATE `$table` SET $setClause WHERE $whereClause";
        $stmt = $this->pdo->prepare($sql);

        foreach ($data as $col => $val) {
            $stmt->bindValue(":set_$col", $val);
        }
        foreach ($where as $col => $val) {
            $stmt->bindValue(":where_$col", $val);
        }

        return $stmt->execute();
    }

    public function selectWhere($table, $where = []) {
        if ($this->tenant_id && !isset($where['tenant_id'])) {
            $where['tenant_id'] = $this->tenant_id;
        }

        $whereParts = [];
        foreach ($where as $col => $val) {
            $whereParts[] = "`$col` = :$col";
        }
        $whereClause = implode(' AND ', $whereParts);

        $sql = "SELECT * FROM `$table`";
        if ($whereClause) {
            $sql .= " WHERE $whereClause";
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($where as $col => $val) {
            $stmt->bindValue(":$col", $val);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function delete($table, $where = []) {
        if ($this->tenant_id && !isset($where['tenant_id'])) {
            $where['tenant_id'] = $this->tenant_id;
        }

        $whereParts = [];
        foreach ($where as $col => $val) {
            $whereParts[] = "`$col` = :$col";
        }
        $whereClause = implode(' AND ', $whereParts);

        $sql = "DELETE FROM `$table` WHERE $whereClause";
        $stmt = $this->pdo->prepare($sql);

        foreach ($where as $col => $val) {
            $stmt->bindValue(":$col", $val);
        }

        return $stmt->execute();
    }
}
