<?php

declare(strict_types=1);

namespace flight\commands;

use flight\commands\AbstractBaseCommand;
use Ahc\Cli\IO\Interactor;
use PDO;
use PDOException;

class PurgeCommand extends AbstractBaseCommand {
    /**
     * Construct
     *
     * @param array<string,mixed> $config JSON config from .runway-config.json
     */
    public function __construct(array $config) {
        parent::__construct('apm:purge', 'Purge old APM data from storage', $config);

        // Add option for config file path
        $this->option('-c --config-file path', 'Path to the runway config file (deprecated, use config.php instead)', null, getcwd() . '/.runway-config.json');

        // Add option for days to keep (default 30)
        $this->option('-d --days int', 'Number of days of data to keep (older data will be purged)', null, 30);
    }

    public function interact(Interactor $io): void {
        // No interaction needed before execute
    }

    public function execute() {
        if (empty($this->config['runway'])) {
            $configFile = $this->configFile;
            $io = $this->app()->io();
            $io->warn('The --config-file option is deprecated. Move your config values to the \'runway\' key in the config.php file for configuration.', true);
            $config = json_decode(file_get_contents($configFile), true) ?? [];
        } else {
            $config = $this->config['runway'];
        }

        $daysToKeep = (int)$this->days;
        $io = $this->app()->io();

        // Load config
        $config = $this->config['runway'];
        if (empty($config['apm'])) {
            $io->error('APM configuration not found. Please run apm:init first.', true);
            return;
        }

        $apmConfig = $config['apm'];
        $storageType = $apmConfig['storage_type'] ?? null;

        if (empty($storageType)) {
            $io->error('Storage type not configured. Please run apm:init first.', true);
            return;
        }

        // Get database connection
        try {
            $db = $this->getDatabaseConnection($apmConfig);
        } catch (PDOException $e) {
            $io->error("Failed to connect to database: " . $e->getMessage(), true);
            return;
        }

        // Calculate the date threshold
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));

        $io->boldCyan("Purging APM data older than {$daysToKeep} days ({$cutoffDate})", true);

        try {
            // Delete old records from apm_requests
            $stmt = $db->prepare("DELETE FROM apm_requests WHERE request_dt < :cutoff_date");
            $stmt->bindParam(':cutoff_date', $cutoffDate);
            $stmt->execute();

            $rowCount = $stmt->rowCount();

            $io->boldGreen("Successfully purged {$rowCount} old records from apm_requests table", true);

            // Clean up any orphaned child records in case foreign keys were disabled in the past
            $childTables = [
                'apm_routes',
                'apm_middleware',
                'apm_views',
                'apm_db_connections',
                'apm_db_queries',
                'apm_errors',
                'apm_cache',
                'apm_custom_events',
                'apm_custom_event_data',
                'apm_raw_metrics'
            ];

            $orphanedCount = 0;
            foreach ($childTables as $table) {
                try {
                    $io->info("Scanning {$table} for orphaned records...", true);
                    $orphansDeleted = $db->exec("DELETE FROM {$table} WHERE NOT EXISTS (SELECT 1 FROM apm_requests WHERE id = {$table}.request_id)");
                    if ($orphansDeleted > 0) {
                        $orphanedCount += (int)$orphansDeleted;
                        $io->boldGreen("Purged {$orphansDeleted} orphaned records from {$table}", true);
                    }
                } catch (PDOException $e) {
                    // Table might not exist in an older schema version, continue
                }
            }
            
            if ($orphanedCount > 0) {
                $io->boldGreen("Successfully cleaned up a total of {$orphanedCount} orphaned child records", true);
            }

            // Clean up JSON buffer tables if they exist using JSON extraction
            try {
                $cutoffUnix = strtotime("-{$daysToKeep} days");
                $jsonExtractFunc = $storageType === 'mysql' ? 'JSON_EXTRACT' : 'json_extract';
                
                $stmt = $db->prepare("DELETE FROM apm_metrics_log WHERE {$jsonExtractFunc}(metrics_json, '$.start_time') < :cutoff_unix");
                $stmt->bindParam(':cutoff_unix', $cutoffUnix);
                if ($stmt->execute()) {
                    $logCount = $stmt->rowCount();
                    if ($logCount > 0) {
                        $io->info("Purged {$logCount} old records from apm_metrics_log table by JSON timestamp", true);
                    }
                }
            } catch (PDOException $e) {
                // Table might not exist, continue smoothly
            }

            // If SQLite, vacuum the database to reclaim space
            if ($storageType === 'sqlite') {
                $db->exec('VACUUM');
                $io->info("Database vacuumed to reclaim space", true);
            }
        } catch (PDOException $e) {
            $io->error("Failed to purge data: " . $e->getMessage(), true);
            return;
        }

        $io->boldGreen("Data purge completed successfully!", true);
    }

    /**
     * Get database connection based on storage type
     * 
     * @param array<string,mixed> $config
     * @return PDO
     */
    protected function getDatabaseConnection(array $config): PDO {
        $storageType = $config['storage_type'];

        switch ($storageType) {
            case 'sqlite':
                $dsn = $config['dest_db_dsn'];
                $pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                // Enable foreign keys explicitly so CASCADE works correctly
                $pdo->exec('PRAGMA foreign_keys = ON;');
                return $pdo;

            case 'mysql':
                $dsn = $config['dest_db_dsn'];
                $user = $config['dest_db_user'];
                $pass = $config['dest_db_pass'];
                return new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

            default:
                throw new \InvalidArgumentException("Unsupported storage type: {$storageType}");
        }
    }
}
