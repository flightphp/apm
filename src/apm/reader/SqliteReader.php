<?php

declare(strict_types=1);

namespace flight\apm\reader;

use PDO;
use PDOException;

class SqliteReader implements ReaderInterface
{
    private PDO $pdo;
    private string $tableName;
    private bool $hasMore = true;

    /**
     * Create a new SQLite reader
     *
     * @param string $dsn SQLite DSN
     * @param string $tableName Name of the table to read from
     */
    public function __construct(string $dsn, string $tableName = 'apm_metrics_log')
    {
		$dsnParts = explode(':', $dsn);
		if (count($dsnParts) !== 2 || $dsnParts[0] !== 'sqlite') {
			throw new \InvalidArgumentException("Invalid SQLite DSN: $dsn");
		}

		$filePath = $dsnParts[1];

		// check that the database exists
		if (file_exists($filePath) === false) {
			throw new \InvalidArgumentException("Database file does not exist: $filePath");
		}
        // SQLite-specific options
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->pdo = new PDO($dsn, null, null, $pdoOptions);
        $this->tableName = $tableName;
    }

    /**
     * Read metrics from the SQLite source
     *
     * @param int $limit Maximum number of records to read
     * @return array Array of metric records
     */
    public function read(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tableName} ORDER BY id ASC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $this->hasMore = count($results) === $limit;
        
        return $results;
    }

    /**
     * Mark metrics as processed by deleting them
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
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE id IN ($placeholders)");
        
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
    
}
