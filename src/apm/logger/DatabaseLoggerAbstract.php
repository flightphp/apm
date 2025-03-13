<?php

declare(strict_types=1);

namespace flight\apm\logger;

use PDO;
use PDOException;

/**
 * Abstract class for database-based APM logging
 *
 * Handles storing application performance metrics in a database table
 */
abstract class DatabaseLoggerAbstract
{
    /**
     * PDO database connection instance
     *
     * @var PDO
     */
    protected PDO $pdo;
    
    /**
     * Flag indicating whether the required database table exists
     *
     * @var bool
     */
    protected bool $tableCreated = false;

    /**
     * Constructor for the database logger
     *
     * Initializes the PDO connection and ensures the required table exists
     *
     * @param PDO $pdo Database connection instance
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTableExists();
    }

    /**
     * Abstract method to ensure the metrics table exists
     *
     * Implementations should create the table if it doesn't exist
     *
     * @return void
     */
    abstract protected function ensureTableExists(): void;

    /**
     * Logs performance metrics to the database
     *
     * Stores URL, execution time, memory usage, route pattern, and the full metrics JSON
     * Falls back to file logging if database insertion fails
     *
     * @param array $metrics Performance metrics to log
     * @return void
     */
    public function log(array $metrics): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO apm_metrics_log (added_dt, metrics_json)
                VALUES (:added_dt, :metrics_json)
            ");
            $stmt->execute([
                ':added_dt' => gmdate('Y-m-d H:i:s'),
                ':metrics_json' => json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            ]);
        } catch (PDOException $e) {
            error_log("Failed to log APM metrics: " . $e->getMessage());
            // Fallback to file if DB fails
            file_put_contents('apm_fallback.log', json_encode($metrics) . PHP_EOL, FILE_APPEND);
        }
    }
}