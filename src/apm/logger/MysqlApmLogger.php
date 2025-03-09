<?php

declare(strict_types=1);

namespace flight\apm\logger;

use PDOException;

class MysqlApmLogger extends DatabaseApmLoggerAbstract implements ApmLoggerInterface {

    protected function ensureTableExists(): void
	{
        if ($this->tableCreated) {
            return;
        }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS apm_metrics_log (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    added_dt DATETIME DEFAULT CURRENT_TIMESTAMP,
                    metrics_json TEXT NOT NULL,
                    INDEX idx_added_dt (added_dt)
                ) ENGINE=InnoDB
            ");
            $this->tableCreated = true;
        } catch (PDOException $e) {
            error_log("Failed to create apm_metrics_log table: " . $e->getMessage());
            throw $e;
        }
    }
}