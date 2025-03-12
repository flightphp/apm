<?php

declare(strict_types=1);

namespace flight\apm\reader;

use PDO;
use PDOException;

class MysqlReader implements ReaderInterface
{
    private PDO $pdo;
    private string $tableName;
    private bool $hasMore = true;

    /**
     * Create a new MySQL reader
     *
     * @param string $dsn MySQL DSN
     * @param string $username Database username
     * @param string $password Database password
     * @param string $tableName Name of the table to read from
     */
    public function __construct(string $dsn, string $username, string $password, string $tableName = 'apm_metrics_log')
    {
        // MySQL-specific options
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->pdo = new PDO($dsn, $username, $password, $pdoOptions);
        $this->tableName = $tableName;
        
        // Check if the table exists, if not create it
        $this->ensureTableExists();
    }

    /**
     * Read metrics from the MySQL source
     *
     * @param int $limit Maximum number of records to read
     * @return array Array of metric records
     */
    public function read(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tableName} WHERE processed = 0 ORDER BY id ASC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $this->hasMore = count($results) === $limit;
        
        return $results;
    }

    /**
     * Mark metrics as processed
     *
     * @param array $ids IDs of the processed metrics
     * @return bool Success status
     */
    public function markProcessed(array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("UPDATE {$this->tableName} SET processed = 1 WHERE id IN ($placeholders)");
        
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, $id);
        }
        
        return $stmt->execute();
    }

    /**
     * Check if there are more metrics to process
     *
     * @return bool True if there are more metrics
     */
    public function hasMore(): bool
    {
        return $this->hasMore;
    }
    
    /**
     * Ensure the metrics table exists
     */
    private function ensureTableExists(): void
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$this->tableName} LIMIT 1");
        } catch (PDOException $e) {
            // Table doesn't exist, create it
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->tableName} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                metrics_json TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed TINYINT(1) DEFAULT 0
            )");
        }
    }
}
