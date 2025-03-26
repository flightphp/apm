<?php

declare(strict_types=1);

namespace flight\commands;

use flight\commands\AbstractBaseCommand;
use Ahc\Cli\IO\Interactor;
use PDO;
use PDOException;

class PurgeCommand extends AbstractBaseCommand
{
    /**
     * Construct
     *
     * @param array<string,mixed> $config JSON config from .runway-config.json
     */
    public function __construct(array $config)
    {
        parent::__construct('apm:purge', 'Purge old APM data from storage', $config);
        
        // Add option for config file path
        $this->option('-c --config-file path', 'Path to the runway config file', null, getcwd() . '/.runway-config.json');
        
        // Add option for days to keep (default 30)
        $this->option('-d --days int', 'Number of days of data to keep (older data will be purged)', null, 30);
    }

    public function interact(Interactor $io): void
    {
        // No interaction needed before execute
    }

    public function execute()
    {
        $configFile = $this->configFile;
        $daysToKeep = (int)$this->days;
        $io = $this->app()->io();

        // Check if config file exists
        if (file_exists($configFile) === false) {
            $io->error("Config file not found at {$configFile}", true);
            return;
        }

        // Load config
        $config = json_decode(file_get_contents($configFile), true) ?? [];
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
            $stmt = $db->prepare("DELETE FROM apm_requests WHERE timestamp < :cutoff_date");
            $stmt->bindParam(':cutoff_date', $cutoffDate);
            $stmt->execute();
            
            $rowCount = $stmt->rowCount();
            
            $io->boldGreen("Successfully purged {$rowCount} old records from apm_requests table", true);
            
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
    protected function getDatabaseConnection(array $config): PDO
    {
        $storageType = $config['storage_type'];
        
        switch ($storageType) {
            case 'sqlite':
                $dsn = $config['dest_db_dsn'];
                return new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            
            case 'mysql':
                $dsn = $config['dest_db_dsn'];
                $user = $config['dest_db_user'];
                $pass = $config['dest_db_pass'];
                return new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                
            case 'timescaledb':
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
