<?php

declare(strict_types=1);

namespace flight\commands;

use flight\commands\AbstractBaseCommand;
use Ahc\Cli\IO\Interactor;

class InitCommand extends AbstractBaseCommand
{
    /**
     * Construct
     *
     * @param array<string,mixed> $config JSON config from .runway-config.json
     */
    public function __construct(array $config)
    {
        parent::__construct('apm:init', 'Initialize APM configuration', $config);
        
        // Add option for config file path
        $this->option('-c --config-file path', 'Path to the runway config file', null, getcwd() . '/.runway-config.json');

        // Set command help text
        // $this->usage(
        //     '<bold>  apm:init</end> <comment>--config-file /path/to/config.json</end> ## Initialize APM configuration'
        // );
    }

    public function interact(Interactor $io): void
    {
        // No interaction needed before execute
    }

    public function execute()
    {
        $configFile = $this->configFile;
        $this->runStorageConfigWalkthrough($configFile);
    }

    protected function runStorageConfigWalkthrough(string $configFile): void
    {
        $config = [];
        $io = $this->app()->io();
        
        // Load existing config if available
        if (file_exists($configFile) === true) {
            $config = json_decode(file_get_contents($configFile), true) ?? [];

			// if $config['apm'] has something in there ask if they want to proceed
			if (!empty($config['apm'])) {
				$io->warn('APM configuration already exists.', true);
				$overwrite = $io->prompt('Overwrite existing APM configuration? (y/n)', 'n');
				if (strtolower($overwrite) !== 'y') {
					$io->info('Exiting without changes.', true);
					return;
				}
			}
        }
        
        $apmConfig = $config['apm'] ?? [];
        
        $io->boldCyan('APM Configuration Wizard', true);
        $io->cyan('This wizard will help you configure source and storage settings for APM.', true);
        
        // SOURCE CONFIGURATION
        $io->boldCyan('Source Configuration (where to read metrics from)', true);
        
        // Select source type
        $sourceTypes = [
            '1' => 'sqlite',
            // '2' => 'file',
            // '3' => 'mysql',
            // '4' => 'timescaledb'
        ];
        
        $choice = $io->choice('What type of source would you like to read from?', $sourceTypes, '1');
        $sourceType = $sourceTypes[$choice];
        $apmConfig['source_type'] = $sourceType;
        
        // Configure source based on type
        switch ($sourceType) {
            case 'file':
                $apmConfig['source_path'] = $io->prompt('Enter the path to read the metrics log file:', '/tmp/apm_metrics.json');
                if(!file_exists($apmConfig['source_path'])) {
                    $io->error('The specified file does not exist. Please check the path and try again.', true);
                    return;
                }
                break;
                
            case 'sqlite':
                $apmConfig['source_db_dsn'] = $io->prompt('Enter the SQLite DSN for the apm_metrics_log table:', 'sqlite:/tmp/apm_metrics.sqlite');
                if(strpos($apmConfig['source_db_dsn'], 'sqlite:') === false) {
                    $apmConfig['source_db_dsn'] = 'sqlite:' . $apmConfig['source_db_dsn'];
                }
                $apmConfig['source_table'] = $io->prompt('Enter the SQLite table name containing the metrics:', 'apm_metrics_log');
                break;
                
            case 'mysql':
                $apmConfig['source_db_dsn'] = $io->prompt('Enter the MySQL DSN for the database that has the apm_metrics_log table:', 'mysql:host=localhost;dbname=apm');
                $apmConfig['source_db_user'] = $io->prompt('Enter the MySQL username for the database that has the apm_metrics_log table:', 'root');
                $apmConfig['source_db_pass'] = $io->prompt('Enter the MySQL password for the database that has the apm_metrics_log table:', '');
                $apmConfig['source_table'] = $io->prompt('Enter the MySQL table name containing the metrics:', 'apm_metrics_log');
                break;
                
            case 'timescaledb':
                $apmConfig['source_db_dsn'] = $io->prompt('Enter the source TimescaleDB DSN for the database that has the apm_metrics_log table:', 'pgsql:host=localhost;dbname=apm');
                $apmConfig['source_db_user'] = $io->prompt('Enter the source TimescaleDB username for the database that has the apm_metrics_log table:', 'postgres');
                $apmConfig['source_db_pass'] = $io->prompt('Enter the source TimescaleDB password for the database that has the apm_metrics_log table:', '');
                $apmConfig['source_table'] = $io->prompt('Enter the TimescaleDB table name containing the metrics:', 'apm_metrics_log');
                break;
        }
        
        // DESTINATION CONFIGURATION
        $io->boldCyan('Destination Configuration (where to store processed metrics)', true);
        
        // Select storage type
        $storageTypes = [
            '1' => 'sqlite',
            // '2' => 'file',
            // '3' => 'mysql',
            // '4' => 'timescaledb'
        ];
        
        $choice = $io->choice('What type of storage would you like to use for destination?', $storageTypes, '1');
        $storageType = $storageTypes[$choice];
        $apmConfig['storage_type'] = $storageType;
        
        // Configure storage based on type
        switch ($storageType) {
            case 'file':
                $apmConfig['storage_path'] = $io->prompt('Enter the path to store the metrics file:', '/tmp/apm_processed_metrics.json');
                if(!is_writable(dirname($apmConfig['storage_path']))) {
                    $io->error('The specified directory is not writable. Please check the path and try again.', true);
                    return;
                }
                break;
                
            case 'sqlite':
                $apmConfig['dest_db_dsn'] = $io->prompt('Enter the SQLite DSN for where the APM data will be stored:', 'sqlite:/tmp/apm_metrics_processed.sqlite');
                if(strpos($apmConfig['dest_db_dsn'], 'sqlite:') === false) {
                    $apmConfig['dest_db_dsn'] = 'sqlite:' . $apmConfig['dest_db_dsn'];
                }
                break;
                
            case 'mysql':
                $apmConfig['dest_db_dsn'] = $io->prompt('Enter the MySQL DSN for where the APM data will be stored:', 'mysql:host=localhost;dbname=apm_processed');
                $apmConfig['dest_db_user'] = $io->prompt('Enter the MySQL username for where the APM data will be stored:', 'root');
                $apmConfig['dest_db_pass'] = $io->prompt('Enter the MySQL password for where the APM data will be stored:', '');
                break;
                
            case 'timescaledb':
                $apmConfig['dest_db_dsn'] = $io->prompt('Enter the TimescaleDB DSN for where the APM data will be stored:', 'pgsql:host=localhost;dbname=apm_processed');
                $apmConfig['dest_db_user'] = $io->prompt('Enter the TimescaleDB username for where the APM data will be stored:', 'postgres');
                $apmConfig['dest_db_pass'] = $io->prompt('Enter the TimescaleDB password for where the APM data will be stored:', '');
                break;
        }
        
        // Save the configuration
        if(!isset($config['apm'])) {
            $config['apm'] = [];
        }

		$config['apm'] = $apmConfig;
        
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($configFile, $json);
        
        $io->boldGreen('APM configuration saved successfully! Configuration saved at '. $configFile, true);
    }
}
