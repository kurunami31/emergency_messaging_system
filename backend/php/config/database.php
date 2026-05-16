<?php

namespace App\Config;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;

    private string $host;
    private string $dbName;
    private string $username;
    private string $password;
    private int $port;

    private function __construct()
    {
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->dbName = getenv('DB_NAME') ?: 'emergency_messaging_system';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: '';
        $this->port = (int)(getenv('DB_PORT') ?: 3306);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            try {
                $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbName};charset=utf8mb4";
                $this->connection = new PDO($dsn, $this->username, $this->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                throw $e;
            }
        }
        return $this->connection;
    }

    public function close(): void
    {
        $this->connection = null;
    }

    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
