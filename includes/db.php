<?php

// require_once __DIR__ . '/config.php';
// ============================================================
//  Classe Database avec PDO (singleton)
// ============================================================

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $env = parse_ini_file(__DIR__ . '/../.env');

        $host = $env['DB_HOST'];
        $dbname = $env['DB_NAME'];
        $user = $env['DB_USER'];
        $password = $env['DB_PASSWORD'];

        // Construction du DSN
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $password, $options);
        } catch (PDOException $e) {
            die('Erreur de connexion à la base de données : ' . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        $sql = "INSERT INTO `$table` (" . implode(',', array_map(function($f) { return "`$f`"; }, $fields)) . ") VALUES (" . implode(',', $placeholders) . ")";
        $this->query($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = []) {
        $sets = [];
        $values = [];
        foreach ($data as $key => $value) {
            $sets[] = "`$key` = ?";
            $values[] = $value;
        }
        $sql = "UPDATE `$table` SET " . implode(',', $sets) . " WHERE $where";
        $this->query($sql, array_merge($values, $whereParams));
    }

    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM `$table` WHERE $where";
        $this->query($sql, $params);
    }

    public function count($table, $where = '', $params = []) {
        $sql = "SELECT COUNT(*) FROM `$table`";
        if ($where) $sql .= " WHERE $where";
        $stmt = $this->query($sql, $params);
        return (int)$stmt->fetchColumn();
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}

// Détermine la racine du projet (ex: http://localhost/mon_dossier/)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$base_url = $protocol . $host . $uri;
// Si on est à la racine du serveur, $uri sera "/", on le laisse
define('BASE_URL', $base_url);