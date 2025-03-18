<?php

declare(strict_types=1);

namespace flight\commands;

use flight\commands\AbstractBaseCommand;
use Ahc\Cli\IO\Interactor;
use PDO;
use PDOException;

class MigrateCommand extends AbstractBaseCommand
{
    /**
     * Construct
     *
     * @param array<string,mixed> $config JSON config from .runway-config.json
     */
    public function __construct(array $config)
    {
        parent::__construct('apm:migrate', 'Run database migrations for APM', $config);
        
        // Add option for config file path
        $this->option('-c --config-file path', 'Path to the runway config file', null, getcwd() . '/.runway-config.json');
    }

    public function interact(Interactor $io): void
    {
        // No interaction needed before execute
    }

    public function execute()
    {
        $configFile = $this->configFile;
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

        // Set up migrations directory
        $migrationsDir = __DIR__ . '/../apm/migration/' . $storageType;
        if (!is_dir($migrationsDir)) {
            $io->error("Migrations directory not found for {$storageType}: {$migrationsDir}", true);
            return;
        }

        // Get executed migrations
        $executedMigrations = $apmConfig['executed_migrations'] ?? [];

        // Get all migration files
        $migrationFiles = $this->getMigrationFiles($migrationsDir);
        
        if (empty($migrationFiles)) {
            $io->info("No migration files found in {$migrationsDir}", true);
            return;
        }

        // Get database connection
        try {
            $db = $this->getDatabaseConnection($apmConfig);
        } catch (PDOException $e) {
            $io->error("Failed to connect to database: " . $e->getMessage(), true);
            return;
        }

        $io->boldCyan("Running migrations for {$storageType} storage", true);

        $newExecutedMigrations = [];

        // Run each migration that hasn't been executed yet
        foreach ($migrationFiles as $migrationFile) {
            $filename = basename($migrationFile);
            
            if (in_array($filename, $executedMigrations)) {
                $io->comment("Skipping {$filename} (already executed)", true);
                $newExecutedMigrations[] = $filename;
                continue;
            }

            $io->info("Running migration: {$filename}", true);
            
            try {
                // Get SQL from migration file
                $sql = file_get_contents($migrationFile);
                if (empty($sql)) {
                    $io->warn("Empty migration file: {$filename}", true);
                    continue;
                }

                // Execute the SQL
                $db->exec($sql);
                
                // Add to executed migrations
                $newExecutedMigrations[] = $filename;
                $io->boldGreen("Successfully executed {$filename}", true);
            } catch (PDOException $e) {
                $io->error("Failed to execute {$filename}: " . $e->getMessage(), true);
                // Continue with other migrations
            }
        }

        // Update config with executed migrations
        $config['apm']['executed_migrations'] = $newExecutedMigrations;
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($configFile, $json);

        $io->boldGreen("Migration completed successfully!", true);
    }

    /**
     * Get all migration files in the migrations directory
     * 
     * @param string $migrationsDir
     * @return array<string>
     */
    protected function getMigrationFiles(string $migrationsDir): array
    {
        $files = glob($migrationsDir . '/*.sql');
        return $files !== false ? $files : [];
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
				var_dump($dsn);
                return new PDO($dsn, null, null, [
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES => false,
				]);
            
            default:
                throw new \InvalidArgumentException("Unsupported storage type: {$storageType}");
        }
    }
}
