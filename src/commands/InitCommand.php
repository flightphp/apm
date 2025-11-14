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
    }

    public function interact(Interactor $io): void
    {
        // No interaction needed before execute
    }

    public function execute()
    {
        $this->runStorageConfigWalkthrough();
    }

    protected function runStorageConfigWalkthrough(): void
    {
        $io = $this->app()->io();
		$config = $this->config['runway'];
        
		// if $config['apm'] has something in there ask if they want to proceed
		if (!empty($config['apm'])) {
			$io->warn('APM configuration already exists.', true);
			$overwrite = $io->prompt('Overwrite existing APM configuration? (y/n)', 'n');
			if (strtolower($overwrite) !== 'y') {
				$io->info('Exiting without changes.', true);
				return;
			}
		}
        
        $apmConfig = $config['apm'] ?? [];
        
        $io->boldCyan('APM Configuration Wizard', true);
        $io->cyan('This wizard will help you configure source and storage settings for APM.', true);
        
        // SOURCE CONFIGURATION
        $io->boldCyan('Source Configuration (where to store logs and read metrics from)', true);

        // Select source type
        $sourceTypes = [
            '1' => 'sqlite',
            '2' => 'mysql',
        ];
        
        $choice = $io->choice('What type of source would you like to read from?', $sourceTypes, '1');
        $sourceType = $sourceTypes[$choice];
        $apmConfig['source_type'] = $sourceType;
        
        // Configure source based on type
        switch ($sourceType) {
                
            case 'sqlite':
                $apmConfig['source_db_dsn'] = $io->prompt('Enter the SQLite DSN for the apm_metrics_log table:', 'sqlite:/tmp/apm_metrics.sqlite');
                if(strpos($apmConfig['source_db_dsn'], 'sqlite:') === false) {
                    $apmConfig['source_db_dsn'] = 'sqlite:' . $apmConfig['source_db_dsn'];
                }
                break;
                
            case 'mysql':
                $apmConfig['source_db_dsn'] = $io->prompt('Enter the MySQL DSN for the database that has the apm_metrics_log table:', 'mysql:host=localhost;dbname=apm');
                $apmConfig['source_db_user'] = $io->prompt('Enter the MySQL username for the database that has the apm_metrics_log table:', 'root');
                $apmConfig['source_db_pass'] = $io->prompt('Enter the MySQL password for the database that has the apm_metrics_log table:', '');
                $optionsJson = $io->prompt('Enter any PDO options for MySQL as a JSON object (e.g. {"PDO::ATTR_ERRMODE":3}):', '{}');
                $apmConfig['source_db_options'] = json_decode($optionsJson, true) ?? [];
                break;
                
        }
        
        // DESTINATION CONFIGURATION
        $io->boldCyan('Destination Configuration (where to store processed metrics)', true);
        
        // Select storage type
        $storageTypes = [
            '1' => 'sqlite',
            '2' => 'mysql',
        ];
        
        $choice = $io->choice('What type of storage would you like to use for destination?', $storageTypes, '1');
        $storageType = $storageTypes[$choice];
        $apmConfig['storage_type'] = $storageType;
        
        // Configure storage based on type
        switch ($storageType) {
                
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
                $optionsJson = $io->prompt('Enter any PDO options for MySQL as a JSON object (e.g. {"PDO::ATTR_ERRMODE":3}):', '{}');
                $apmConfig['dest_db_options'] = json_decode($optionsJson, true) ?? [];
                break;
                
        }
        
        // Save the configuration
        if(!isset($config['apm'])) {
            $config['apm'] = [];
        }

        // Add option for masking IP addresses
        $maskIp = $io->prompt('Do you want to mask IP addresses in the dashboard? (y/n)', 'n');
        $apmConfig['mask_ip_addresses'] = strtolower($maskIp) === 'y';

        $config['apm'] = $apmConfig;

		$this->setRunwayConfig($config);

        $io->boldGreen('APM configuration saved successfully!', true);

		$answer = $io->prompt('Do you want to run the migration now? (y/n)', 'y',);

		if (strtolower($answer) !== 'y') {
			$io->info('Exiting without running migration.', true);
			return;
		}

		$io->info('Running migration...', true);
		$this->app()->handle([ 'vendor/bin/runway', 'apm:migrate' ]);
		$io->boldGreen('Migration completed successfully!', true);
    }
}
