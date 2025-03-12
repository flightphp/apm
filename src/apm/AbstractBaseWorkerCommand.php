<?php

declare(strict_types=1);

namespace flight\apm;

use flight\apm\writer\StorageInterface;
use flight\apm\reader\ReaderInterface;
use flight\commands\AbstractBaseCommand;
use Ahc\Cli\IO\Interactor;

abstract class AbstractBaseWorkerCommand extends AbstractBaseCommand
{
	protected array $storageConfig = [];
	// protected string $storageType;
	// protected string $sourceType;
	// protected string $storagePath;
	// protected string $sourcePath;
	// protected string $dbDsn;
	// protected string $dbUser;
	// protected string $dbPass;
	// protected string $sourceDbDsn;
	// protected string $sourceDbUser;
	// protected string $sourceDbPass;

	/**
	 * Creates and returns the appropriate storage worker based on storage type
	 *
	 * @param array $options Configuration options including storage settings
	 * @return StorageInterface The configured storage worker
	 * @throws \InvalidArgumentException If the storage type is invalid
	 */
	protected function getStorageWorker(array $options): StorageInterface
	{
		switch($options['storageType']) {
			case 'file':
				return new \flight\apm\writer\FileStorage($options['storagePath']);
			case 'sqlite':
				return new \flight\apm\writer\SqliteStorage($options['dbDsn']);
			case 'mysql':
				return new \flight\apm\writer\MysqlStorage($options['dbDsn'], $options['dbUser'], $options['dbPass']);
			case 'timescaledb':
				return new \flight\apm\writer\TimescaledbStorage($options['dbDsn'], $options['dbUser'], $options['dbPass']);
			default:
				throw new \InvalidArgumentException('Invalid storage type');
		}
	}

	protected function getSourceReader(array $options): ReaderInterface
	{
		switch($this->sourceType) {
			case 'file':
				return new \flight\apm\reader\FileReader($options['sourcePath']);
			case 'sqlite':
				return new \flight\apm\reader\SqliteReader($options['sourceDbDsn']);
			case 'mysql':
				return new \flight\apm\reader\MysqlReader($options['sourceDbDsn'], $options['sourceDbUser'], $options['sourceDbPass']);
			case 'timescaledb':
				return new \flight\apm\reader\TimescaledbReader($options['sourceDbDsn'], $options['sourceDbUser'], $options['sourceDbPass']);
			default:
				throw new \InvalidArgumentException('Invalid source type');
		}
	}

	protected function registerStorageWorkerOptions(): void
	{
		// Run the walkthrough if needed
		$this->runStorageConfigWalkthrough();

		// Use stored config values as defaults if available
		$storageType = $this->storageConfig['storage_type'] ?? 'sqlite';
		$storagePath = $this->storageConfig['storage_path'] ?? '/tmp/apm_metrics.json';
		$dbDsn = $this->storageConfig['dest_db_dsn'] ?? 'sqlite:/tmp/apm_metrics_log.sqlite';
		$dbUser = $this->storageConfig['dest_db_user'] ?? 'root';
		$dbPass = $this->storageConfig['dest_db_pass'] ?? '';
		
		$sourceType = $this->storageConfig['source_type'] ?? 'sqlite';
		$sourcePath = $this->storageConfig['source_path'] ?? '/tmp/apm_metrics.json';
		$sourceDbDsn = $this->storageConfig['source_db_dsn'] ?? 'sqlite:/tmp/apm_metrics.sqlite';
		$sourceDbUser = $this->storageConfig['source_db_user'] ?? 'root';
		$sourceDbPass = $this->storageConfig['source_db_pass'] ?? '';
		$sourceTable = $this->storageConfig['source_table'] ?? 'apm_metrics_log';

		// Destination storage options
		$this->option('--storage-type storage_type', "Destination storage type timescaledb, mysql, sqlite, file (default: $storageType)");
		$this->option('--storage-path storage_path', "Destination path to store the file (default: $storagePath)");
		$this->option('--dest-db-dsn dest_db_dsn', "Destination database connection string (default: $dbDsn)");
		$this->option('--dest-db-user dest_db_user', "Destination database user (default: $dbUser)");
		$this->option('--dest-db-pass dest_db_pass', "Destination database password (default: $dbPass)");
		
		// Source reader options
		$this->option('--source-type source_type', "Source type timescaledb, mysql, sqlite, file (default: $sourceType)");
		$this->option('--source-path source_path', "Source path to read the file (default: $sourcePath)");
		$this->option('--source-db-dsn source_db_dsn', "Source database connection string (default: $sourceDbDsn)");
		$this->option('--source-db-user source_db_user', "Source database user (default: $sourceDbUser)");
		$this->option('--source-db-pass source_db_pass', "Source database password (default: $sourceDbPass)");
		$this->option('--source-table source_table', "Source database table name (default: $sourceTable)");
	}
	
	protected function runStorageConfigWalkthrough(): void
	{
		$configFile = getcwd() . '/.runway-config.json';
		$config = [];
		
		// Load existing config if available
		if (file_exists($configFile) === true) {
			$config = json_decode(file_get_contents($configFile), true) ?? [];
		}
		
		// Check if storage config already exists
		if (!empty($config['apm']['source_type'])) {
			$this->storageConfig = $config['apm'];
			return;
		}

		$apmConfig = $config['apm'] ?? [];
		
		$interactor = new Interactor();
		$interactor->boldBlue('APM Configuration Wizard', true);
		$interactor->blue('This wizard will help you configure source and storage settings for APM.', true);
		
		// SOURCE CONFIGURATION
		$interactor->boldBlue('Source Configuration (where to read metrics from)', true);
		
		// Select source type
		$sourceTypes = [
			'1' => 'sqlite',
			'2' => 'file',
			'3' => 'mysql',
			'4' => 'timescaledb'
		];
		
		$choice = $interactor->choice('What type of source would you like to read from?', $sourceTypes, '1');
		$sourceType = $sourceTypes[$choice];
		$apmConfig['source_type'] = $sourceType;
		
		// Configure source based on type
		switch ($sourceType) {
			case 'file':
				$apmConfig['source_path'] = $interactor->prompt('Enter the path to read the metrics log file:', '/tmp/apm_metrics.json');
				if(!file_exists($apmConfig['source_path'])) {
					$interactor->red('The specified file does not exist. Please check the path and try again.', true);
					return;
				}
				break;
				
			case 'sqlite':
				$apmConfig['source_db_dsn'] = $interactor->prompt('Enter the SQLite DSN for the apm_metrics_log table:', 'sqlite:/tmp/apm_metrics.sqlite');
				if(strpos($apmConfig['source_db_dsn'], 'sqlite:') === false) {
					$apmConfig['source_db_dsn'] = 'sqlite:' . $apmConfig['source_db_dsn'];
				}
				$apmConfig['source_table'] = $interactor->prompt('Enter the SQLite table name containing the metrics:', 'apm_metrics_log');
				break;
				
			case 'mysql':
				$apmConfig['source_db_dsn'] = $interactor->prompt('Enter the MySQL DSN for the database that has the apm_metrics_log table:', 'mysql:host=localhost;dbname=apm');
				$apmConfig['source_db_user'] = $interactor->prompt('Enter the MySQL username for the database that has the apm_metrics_log table:', 'root');
				$apmConfig['source_db_pass'] = $interactor->prompt('Enter the MySQL password for the database that has the apm_metrics_log table:', '');
				$apmConfig['source_table'] = $interactor->prompt('Enter the MySQL table name containing the metrics:', 'apm_metrics_log');
				break;
				
			case 'timescaledb':
				$apmConfig['source_db_dsn'] = $interactor->prompt('Enter the source TimescaleDB DSN for the database that has the apm_metrics_log table:', 'pgsql:host=localhost;dbname=apm');
				$apmConfig['source_db_user'] = $interactor->prompt('Enter the source TimescaleDB username for the database that has the apm_metrics_log table:', 'postgres');
				$apmConfig['source_db_pass'] = $interactor->prompt('Enter the source TimescaleDB password for the database that has the apm_metrics_log table:', '');
				$apmConfig['source_table'] = $interactor->prompt('Enter the TimescaleDB table name containing the metrics:', 'apm_metrics_log');
				break;
		}
		
		// DESTINATION CONFIGURATION
		$interactor->boldBlue('Destination Configuration (where to store processed metrics)', true);
		
		// Select storage type
		$storageTypes = [
			'1' => 'sqlite',
			'2' => 'file',
			'3' => 'mysql',
			'4' => 'timescaledb'
		];
		
		$choice = $interactor->choice('What type of storage would you like to use for destination?', $storageTypes, '1');
		$storageType = $storageTypes[$choice];
		$apmConfig['storage_type'] = $storageType;
		
		// Configure storage based on type
		switch ($storageType) {
			case 'file':
				$apmConfig['storage_path'] = $interactor->prompt('Enter the path to store the metrics file:', '/tmp/apm_processed_metrics.json');
				if(!is_writable(dirname($apmConfig['storage_path']))) {
					$interactor->red('The specified directory is not writable. Please check the path and try again.', true);
					return;
				}
				break;
				
			case 'sqlite':
				$apmConfig['dest_db_dsn'] = $interactor->prompt('Enter the SQLite DSN for where the APM data will be stored:', 'sqlite:/tmp/apm_metrics_processed.sqlite');
				if(strpos($apmConfig['dest_db_dsn'], 'sqlite:') === false) {
					$apmConfig['dest_db_dsn'] = 'sqlite:' . $apmConfig['dest_db_dsn'];
				}
				break;
				
			case 'mysql':
				$apmConfig['dest_db_dsn'] = $interactor->prompt('Enter the MySQL DSN for where the APM data will be stored:', 'mysql:host=localhost;dbname=apm_processed');
				$apmConfig['dest_db_user'] = $interactor->prompt('Enter the MySQL username for where the APM data will be stored:', 'root');
				$apmConfig['dest_db_pass'] = $interactor->prompt('Enter the MySQL password for where the APM data will be stored:', '');
				break;
				
			case 'timescaledb':
				$apmConfig['dest_db_dsn'] = $interactor->prompt('Enter the TimescaleDB DSN for where the APM data will be stored:', 'pgsql:host=localhost;dbname=apm_processed');
				$apmConfig['dest_db_user'] = $interactor->prompt('Enter the TimescaleDB username for where the APM data will be stored:', 'postgres');
				$apmConfig['dest_db_pass'] = $interactor->prompt('Enter the TimescaleDB password for where the APM data will be stored:', '');
				break;
		}
		
		// Save the configuration
		$this->storageConfig = $apmConfig;
		
		if(!isset($config['apm'])) {
			$config['apm'] = $apmConfig;
		}
		
		$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		file_put_contents($configFile, $json);
		
		$interactor->boldGreen('APM configuration saved successfully! Configuration saved at '. $configFile, true);
	}
}