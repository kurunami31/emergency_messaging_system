<?php

namespace App\Models;

use App\Config\Database;
use PDO;

abstract class BaseModel
{
    protected PDO $db;
    protected string $table;
    protected string $primaryKey = 'id';

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(string $orderBy = 'created_at', string $direction = 'DESC', int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} ORDER BY {$orderBy} {$direction} LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findBy(string $column, $value): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$column} = :value ORDER BY created_at DESC"
        );
        $stmt->execute([':value' => $value]);
        return $stmt->fetchAll();
    }

    public function findOneBy(string $column, $value): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$column} = :value LIMIT 1"
        );
        $stmt->execute([':value' => $value]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})"
        );
        $stmt->execute($data);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $sets = [];
        foreach ($data as $column => $value) {
            $sets[] = "{$column} = :{$column}";
        }
        $data[$this->primaryKey] = $id;
        $setClause = implode(', ', $sets);
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET {$setClause} WHERE {$this->primaryKey} = :{$this->primaryKey}"
        );
        return $stmt->execute($data);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id"
        );
        return $stmt->execute([':id' => $id]);
    }

    public function count(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) as cnt FROM {$this->table}");
        return (int)$stmt->fetch()['cnt'];
    }

    public function beginTransaction(): void { $this->db->beginTransaction(); }
    public function commit(): void { $this->db->commit(); }
    public function rollback(): void { $this->db->rollBack(); }
}
