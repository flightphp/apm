<?php

declare(strict_types=1);

namespace flight\apm\logger;

use PDOException;

class PgsqlApmLogger extends DatabaseApmLoggerAbstract implements ApmLoggerInterface {

    protected function ensureTableExists(): void
	{
        if ($this->tableCreated) {
            return;
        }

        try {
            $this->pdo->exec("
				CREATE TABLE IF NOT EXISTS apm_metrics_log (
					id SERIAL PRIMARY KEY,
					added_dt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
					metrics_json TEXT NOT NULL
				);
				CREATE INDEX idx_timestamp ON apm_metrics_log (timestamp);
			");
            $this->tableCreated = true;
        } catch (PDOException $e) {
            error_log("Failed to create apm_metrics_log table: " . $e->getMessage());
            throw $e;
        }
    }
}