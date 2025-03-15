<?php

declare(strict_types=1);

namespace flight\apm\logger;

use PDO;
use PDOException;

class SqliteLogger extends DatabaseLoggerAbstract implements LoggerInterface {

	/**
     * Constructor for the database logger
     *
     * Initializes the PDO connection and ensures the required table exists
     *
     * @param string $dsn The Data Source Name (DSN) for the SQLite database
     */
    public function __construct(string $dsn)
    {
        $this->pdo = new PDO($dsn, null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		]);
        $this->ensureTableExists();
    }

	/**
	 * @inheritDoc
	 */
    protected function ensureTableExists(): void
	{
        if ($this->tableCreated) {
            return;
        }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS apm_metrics_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    added_dt DATETIME DEFAULT CURRENT_TIMESTAMP,
                    metrics_json TEXT NOT NULL
                )
            ");
			$this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_added_dt ON apm_metrics_log (added_dt)");
            $this->tableCreated = true;
        } catch (PDOException $e) {
            error_log("Failed to create apm_metrics_log table: " . $e->getMessage());
            throw $e;
        }
    }
}