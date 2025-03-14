<?php

declare(strict_types=1);

namespace flight\commands;

use flight\apm\AbstractBaseWorkerCommand;
use flight\apm\reader\ReaderInterface;
use flight\apm\reader\SqliteReader;
use flight\apm\reader\MysqlReader;
use flight\apm\reader\TimescaledbReader;
use flight\apm\reader\FileReader;
use flight\apm\writer\StorageInterface;

/**
 * WorkerCommand
 * 
 * @property-read ?string $sourceType
 * @property-read ?string $destType
 * @property-read ?string $sourceDbDsn
 * @property-read ?string $sourceDbUser
 * @property-read ?string $sourceDbPass
 * @property-read ?string $sourceTable
 * @property-read ?string $sourceFilePath
 * @property-read ?string $destDbDsn
 * @property-read ?string $destDbUser
 * @property-read ?string $destDbPass
 * @property-read ?string $destFilePath
 * @property-read ?int $timeout
 * @property-read ?int $maxMessages
 * @property-read ?bool $daemon
 * @property-read ?int $batchSize
 */
class WorkerCommand extends AbstractBaseWorkerCommand
{
    /**
     * Default configuration values
     *
     * @var array<string,mixed>
     */
    protected array $defaults = [
        'sourceType' => 'sqlite',
        'destType' => 'sqlite',
        'sourceDbDsn' => 'sqlite:/tmp/apm_metrics_log.sqlite',
        'sourceDbUser' => '',
        'sourceDbPass' => '',
        'sourceTable' => 'apm_metrics_log',
        'sourceFilePath' => '/tmp/apm_metrics_log.json',
        'destDbDsn' => 'sqlite:/tmp/apm_metrics.sqlite',
        'destDbUser' => '',
        'destDbPass' => '',
        'destFilePath' => '/tmp/apm_metrics.json',
        'timeout' => 0,
        'maxMessages' => 0,
        'batchSize' => 100
    ];

    /**
     * Construct
     *
     * @param array<string,mixed> $config JSON config from .runway-config.json
     */
    public function __construct(array $config)
    {
        parent::__construct('apm-worker', 'Starts a worker to migrate APM metrics from source to destination storage.', $config);

        // Processing options
        $this->option('--timeout timeout', 'Timeout in seconds for processing (0 = wait forever)');
        $this->option('--max_messages max_messages', 'Maximum number of messages to process (0 = unlimited)');
        $this->option('--daemon', 'Run in daemon mode (continuous processing)');
        $this->option('--batch_size batch_size', 'Number of messages to process per batch');

        // Initialize configuration
        $this->registerStorageWorkerOptions();
    }

    /**
     * Executes the worker command
     *
     * @return void
     */
    public function execute()
    {
        $io = $this->app()->io();

        // Merge defaults with config and command line options
        $options = $this->getWorkerOptions();
        
        // Display configuration
        $io->bold('Starting APM metrics worker with configuration:', true);
        $io->table([
            [
                'Setting' => 'Source Type',
                'Value' => $options['sourceType']
            ],
            [
                'Setting' => 'Destination Type',
                'Value' => $options['destType']
            ],
            [
                'Setting' => 'Batch Size',
                'Value' => $options['batchSize']
            ],
            [
                'Setting' => 'Timeout',
                'Value' => $options['timeout'] > 0 ? "{$options['timeout']} seconds" : 'Wait forever'
            ],
            [
                'Setting' => 'Max Messages',
                'Value' => $options['maxMessages'] > 0 ? $options['maxMessages'] : 'Unlimited'
            ],
            [
                'Setting' => 'Mode',
                'Value' => $options['daemon'] ? 'Daemon (continuous)' : 'One-time'
            ]
        ], [
            'head' => 'boldGreen'
        ]);

        try {
            // Setup source reader
            $io->write('Setting up source reader... ');
            $reader = $this->getReader($options);
            $io->green('Done!', true);
            
            // Setup destination storage
            $io->write('Setting up destination storage... ');
            $storage = $this->getStorageWorker($options);
            $io->green('Done!', true);

            $io->bold('Processing metrics...', true);
            
            // Message processing loop
            $messageCount = 0;
            $startTime = time();
            
            while (true) {
                $timeoutReached = $options['timeout'] > 0 && (time() - $startTime) >= $options['timeout'];
                $maxMessagesReached = $options['maxMessages'] > 0 && $messageCount >= $options['maxMessages'];
                
                // Break if we've reached timeout or max messages (if not in daemon mode)
                if ((!$options['daemon'] && ($timeoutReached || $maxMessagesReached))) {
                    break;
                }
                
                try {
                    // Read metrics from source
                    $metrics = $reader->read($options['batchSize']);
                    if (empty($metrics)) {
                        // If no messages and not in daemon mode, break
                        if (!$options['daemon']) {
                            break;
                        }
                        // Sleep to avoid hammering the source
                        sleep(1);
                        continue;
                    }
                    
                    $processedIds = [];
                    foreach ($metrics as $metric) {
                        //$io->write("Processing metric ID {$metric['id']}: ", true);
                        
                        try {
                            // Convert JSON string to array if needed
                            $metricData = $metric;
                            if (isset($metric['metrics_json']) && is_string($metric['metrics_json'])) {
                                $metricData = json_decode($metric['metrics_json'], true);
                            }
                            
                            // Store the metric in the destination
                            $storage->store($metricData);
                            $processedIds[] = $metric['id'];
                            $messageCount++;
							if ($messageCount % 100 === 0) {
	                            $io->green("Success! ({$messageCount} processed)", true);
							}
                            // Check limits within the loop
                            if (($options['maxMessages'] > 0 && $messageCount >= $options['maxMessages']) ||
                                ($options['timeout'] > 0 && (time() - $startTime) >= $options['timeout'])) {
                                break;
                            }
                        } catch (\Exception $e) {
                            $io->red("Failed! {$e->getMessage()}", true);
                            // Continue with other messages
                        }
                    }
                    
                    // Mark processed metrics
                    if (!empty($processedIds)) {
                        $reader->markProcessed($processedIds);
                    }
                    
                    // If we don't have more data and not in daemon mode, break
                    if (!$reader->hasMore() && !$options['daemon']) {
                        break;
                    }
                    
                } catch (\Exception $e) {
                    $io->red("Error processing batch: {$e->getMessage()}", true);
                    sleep(5); // Back off on error
                }
            }
            
            $io->bold("Worker finished processing {$messageCount} messages.", true);
            
        } catch (\Exception $e) {
            $io->error("Error: " . $e->getMessage(), true);
            return;
        }
    }

    /**
     * Get a reader instance based on configuration
     *
     * @param array $options Configuration options
     * @return ReaderInterface The configured reader
     */
    protected function getReader(array $options): ReaderInterface
    {
        switch ($options['sourceType']) {
            case 'sqlite':
                return new SqliteReader(
                    $options['sourceDbDsn'],
                    $options['sourceTable']
                );
                
            case 'mysql':
                return new MysqlReader(
                    $options['sourceDbDsn'],
                    $options['sourceDbUser'],
                    $options['sourceDbPass'],
                    $options['sourceTable']
                );
                
            case 'timescaledb':
                return new TimescaledbReader(
                    $options['sourceDbDsn'],
                    $options['sourceDbUser'],
                    $options['sourceDbPass'],
                    $options['sourceTable']
                );
                
            case 'file':
                return new FileReader($options['sourceFilePath']);
                
            default:
                throw new \InvalidArgumentException("Invalid source type: {$options['sourceType']}");
        }
    }

    /**
     * Get a storage instance based on configuration
     *
     * @param array $options Configuration options
     * @return StorageInterface The configured storage
     */
    protected function getStorageWorker(array $options): StorageInterface
    {
        switch ($options['destType']) {
            case 'file':
                return new \flight\apm\writer\FileStorage($options['destFilePath']);
                
            case 'sqlite':
                return new \flight\apm\writer\SqliteStorage($options['destDbDsn']);
                
            case 'mysql':
                return new \flight\apm\writer\MysqlStorage(
                    $options['destDbDsn'], 
                    $options['destDbUser'], 
                    $options['destDbPass']
                );
                
            case 'timescaledb':
                return new \flight\apm\writer\TimescaledbStorage(
                    $options['destDbDsn'], 
                    $options['destDbUser'], 
                    $options['destDbPass']
                );
                
            default:
                throw new \InvalidArgumentException("Invalid destination type: {$options['destType']}");
        }
    }

    /**
     * Get worker options from config, defaults and command line
     *
     * @return array<string,mixed>
     */
    protected function getWorkerOptions(): array
    {
        $options = [];
        
        // Map command-line option names to property names
        $optionToPropertyMap = [
            'source_type' => 'sourceType',
            'dest_type' => 'destType',
            'source_db_dsn' => 'sourceDbDsn',
            'source_db_user' => 'sourceDbUser',
            'source_db_pass' => 'sourceDbPass',
            'source_table' => 'sourceTable',
            'source_file_path' => 'sourceFilePath',
            'dest_db_dsn' => 'destDbDsn',
            'dest_db_user' => 'destDbUser',
            'dest_db_pass' => 'destDbPass',
            'dest_file_path' => 'destFilePath',
            'timeout' => 'timeout',
            'max_messages' => 'maxMessages',
            'batch_size' => 'batchSize'
        ];
        // Start with defaults
        foreach ($this->defaults as $key => $value) {

			// camelCase to snake_case coversion of the $key
			$snake_key = strtolower(preg_replace('/[A-Z]/', '_$0', $key));

            // Get the corresponding command-line option name if exists
            $optionName = array_search($key, $optionToPropertyMap) ?: $key;
            
            // Command line options take precedence
            if (property_exists($this, $optionName) && $this->$optionName !== null) {
				$options[$key] = $this->$optionName;
            } 
            // Check the camelCase property too
            elseif (property_exists($this, $key) && $this->$key !== null) {
                $options[$key] = $this->$key;
            }
            // Then check config
            elseif (isset($this->storageConfig[$snake_key])) {
                $options[$key] = $this->storageConfig[$snake_key];
            }
            // Fall back to defaults
            else {
                $options[$key] = $value;
            }
        }
        
        // Handle boolean flag
        $options['daemon'] = property_exists($this, 'daemon') && $this->daemon === true;
        
        // Convert numeric values
        foreach (['timeout', 'maxMessages', 'batchSize'] as $numKey) {
            if (isset($options[$numKey])) {
                $options[$numKey] = (int)$options[$numKey];
            }
        }
        
        return $options;
    }
}