<?php

declare(strict_types=1);

namespace flight\commands;

use flight\apm\AbstractBaseWorkerCommand;
use PDO;

/**
 * PdoWorkerCommand
 * 
 * @property-read ?string $storage_type
 * @property-read ?string $source_db_dsn
 * @property-read ?string $source_db_user
 * @property-read ?string $source_db_pass
 * @property-read ?string $source_table
 * @property-read ?string $db_dsn
 * @property-read ?string $db_user
 * @property-read ?string $db_pass
 * @property-read ?int $timeout
 * @property-read ?int $max_messages
 * @property-read ?bool $daemon
 * @property-read ?string $storage_path
 */
class PdoWorkerCommand extends AbstractBaseWorkerCommand
{
    /**
     * Default configuration values
     *
     * @var array<string,mixed>
     */
    protected array $defaults = [
        'storage_type' => 'sqlite',
        'source_db_dsn' => 'mysql:host=localhost;dbname=application',
        'source_db_user' => 'root',
        'source_db_pass' => '',
        'source_table' => 'apm_metrics_log',
        'timeout' => 0,
        'max_messages' => 0
    ];

    /**
     * Construct
     *
     * @param array<string,mixed> $config JSON config from .runway-config.json
     */
    public function __construct(array $config)
    {
        parent::__construct('apm-worker:pdo', 'Starts a job queue worker for APM metrics pulling from a database table and storing in your backend storage.', $config);

        // Custom options just for PDO source
        $this->option('--source_db_dsn=VALUE', 'Source database connection string (default: mysql:host=localhost;dbname=application)');
        $this->option('--source_db_user=VALUE', 'Source database user (default: root)');
        $this->option('--source_db_pass=VALUE', 'Source database password (default: empty)');
        $this->option('--source_table=VALUE', 'Source table name to consume from (default: apm_metrics_log)');
        $this->option('--timeout=VALUE', 'Timeout in seconds for processing (0 = wait forever)');
        $this->option('--max_messages=VALUE', 'Maximum number of messages to process (0 = unlimited)');
        $this->option('--daemon', 'Run in daemon mode (continuous processing)');

        // Standard options for any worker type
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
                'Setting' => 'Storage Type',
                'Value' => $options['storage_type']
            ],
            [
                'Setting' => 'Source DB DSN',
                'Value' => $options['source_db_dsn']
            ],
            [
                'Setting' => 'Source Table',
                'Value' => $options['source_table']
            ],
            [
                'Setting' => 'Timeout',
                'Value' => $options['timeout'] > 0 ? "{$options['timeout']} seconds" : 'Wait forever'
            ],
            [
                'Setting' => 'Max Messages',
                'Value' => $options['max_messages'] > 0 ? $options['max_messages'] : 'Unlimited'
            ],
            [
                'Setting' => 'Mode',
                'Value' => $options['daemon'] ? 'Daemon (continuous)' : 'One-time'
            ]
        ], [
            'head' => 'boldGreen'
        ]);

        try {
            // Setup storage connection
            $io->write('Connecting to storage worker... ');
            $storageWorker = $this->getStorageWorker($options);
            $io->writeln('<green>Connected!</green>');
            
            // Setup PDO source connection
            $io->write('Connecting to source database... ');
            $sourcePdo = new PDO(
                $options['source_db_dsn'],
                $options['source_db_user'],
                $options['source_db_pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            $io->writeln('<green>Connected!</green>');

            $io->bold('Processing messages from database...', true);
            
            // Message processing loop
            $messageCount = 0;
            $startTime = time();
            
            // Prepare statement to get unprocessed metrics
            $fetchStmt = $sourcePdo->prepare("SELECT * FROM {$options['source_table']} LIMIT 100");
            
            // Prepare statement to mark metrics as processed
            $deleteStmt = $sourcePdo->prepare("DELETE FROM {$options['source_table']} WHERE id = :id");
            
            while (true) {
                $timeoutReached = $options['timeout'] > 0 && (time() - $startTime) >= $options['timeout'];
                $maxMessagesReached = $options['max_messages'] > 0 && $messageCount >= $options['max_messages'];
                
                // Break if we've reached timeout or max messages (if not in daemon mode)
                if ((!$options['daemon'] && ($timeoutReached || $maxMessagesReached))) {
                    break;
                }
                
                // Begin transaction
                $sourcePdo->beginTransaction();
                
                try {
                    // Fetch unprocessed metrics
                    $fetchStmt->execute();
                    $metrics = $fetchStmt->fetchAll();
                    
                    if (empty($metrics)) {
                        $sourcePdo->commit();
                        // If no messages and not in daemon mode, break
                        if (!$options['daemon']) {
                            break;
                        }
                        // Sleep to avoid hammering the database
                        sleep(1);
                        continue;
                    }
                    
                    foreach ($metrics as $metric) {
                        $io->write("Processing metric ID {$metric['id']}: ");
                        
                        try {
                            $storageWorker->store($metric);
                            $deleteStmt->bindValue(':id', $metric['id'], PDO::PARAM_INT);
                            $deleteStmt->execute();
                            $messageCount++;
                            $io->writeln("<green>Success!</green> ({$messageCount} processed)");
                            
                            // Check limits within the loop
                            if (($options['max_messages'] > 0 && $messageCount >= $options['max_messages']) ||
                                ($options['timeout'] > 0 && (time() - $startTime) >= $options['timeout'])) {
                                break;
                            }
                        } catch (\Exception $e) {
                            $io->writeln("<red>Failed!</red> {$e->getMessage()}");
                            // Continue with other messages
                        }
                    }
                    
                    $sourcePdo->commit();
                    
                } catch (\Exception $e) {
                    $sourcePdo->rollBack();
                    $io->writeln("<red>Database error:</red> {$e->getMessage()}");
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
     * Get worker options from config, defaults and command line
     *
     * @return array<string,mixed>
     */
    protected function getWorkerOptions(): array
    {
        $options = [];
        
        // Start with defaults
        foreach ($this->defaults as $key => $value) {
            // Command line options take precedence
            if (property_exists($this, $key) && $this->$key !== null) {
                $options[$key] = $this->$key;
            } 
            // Then check config
            elseif (isset($this->config['worker'][$key])) {
                $options[$key] = $this->config['worker'][$key];
            } 
            // Fall back to defaults
            else {
                $options[$key] = $value;
            }
        }
        
        // Handle boolean flag
        $options['daemon'] = property_exists($this, 'daemon') && $this->daemon === true;
        
        // Convert numeric values
        if (isset($options['timeout'])) {
            $options['timeout'] = (int)$options['timeout'];
        }
        
        if (isset($options['max_messages'])) {
            $options['max_messages'] = (int)$options['max_messages'];
        }
        
        return $options;
    }
}
