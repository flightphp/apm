<?php

declare(strict_types=1);

namespace flight\commands;

use flight\apm\reader\ReaderFactory;
use flight\apm\writer\WriterFactory;

/**
 * WorkerCommand
 * 
 * @property-read ?int $timeout
 * @property-read ?int $maxMessages
 * @property-read ?bool $daemon
 * @property-read ?int $batchSize
 */
class WorkerCommand extends AbstractBaseCommand
{
    /**
     * Default configuration values
     *
     * @var array<string,mixed>
     */
    protected array $defaults = [
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
        parent::__construct('apm:worker', 'Starts a worker to migrate APM metrics from source to destination storage.', $config);

		// Initialize configuration
		$this->registerStorageWorkerOptions();
    }

	protected function registerStorageWorkerOptions(): void
	{

		// Processing options
        $this->option('--timeout timeout', 'Timeout in seconds for processing (0 = wait forever)');
        $this->option('--max_messages max_messages', 'Maximum number of messages to process (0 = unlimited)');
        $this->option('-d --daemon', 'Run in daemon mode (continuous processing)');
        $this->option('--batch_size batch_size', 'Number of messages to process per batch');

		 // Add option for config file path
		$this->option('--config-file', 'Path to the runway config file', null, getcwd() . '/.runway-config.json');

	}

	protected function autoLocateRunwayConfigPath(): string
	{
		$paths = [
			getcwd().'/.runway-config.json',
			__DIR__.'/../.runway-config.json',
			__DIR__.'/../../.runway-config.json'
		];

		foreach ($paths as $path) {
			if (file_exists($path) === true) {
				return $path;
			}
		}

		$interactor = $this->app()->io();
		$interactor->red('Runway APM configuration not found. Please run "php vendor/bin/runway apm:init" first to configure the APM.', true);
		$interactor->orange('Could not find .runway-config.json file. Please define the path to the config file for the Factory object. It should be in /path/to/project-root/.runway-config.json', true);
		exit(1);
	}
	
    /**
     * Executes the worker command
     *
     * @return void
     */
    public function execute()
    {
        $io = $this->app()->io();

		if(empty($this->configFile)) {
			$runwayConfigPath = $this->autoLocateRunwayConfigPath();
		} else {
			$runwayConfigPath = $this->configFile;
			if(!file_exists($runwayConfigPath)) {
				$io->red("Runway config file not found at: " . $runwayConfigPath, true);
				return;
			}
		}

		$runwayConfig = json_decode(file_get_contents($runwayConfigPath), true);

		// Merge defaults with config and command line options
        $options = $this->getWorkerOptions($runwayConfig);
        
        // Display configuration
        $io->bold('Starting APM metrics worker with configuration:', true);
        $io->table([
            [
                'Setting' => 'Source Type',
                'Value' => $options['source_type']
            ],
            [
                'Setting' => 'Destination Type',
                'Value' => $options['storage_type']
            ],
            [
                'Setting' => 'Batch Size',
                'Value' => $options['batch_size'] > 0 ? $options['batch_size'] : ($options['batchSize'] ?? 'All available')
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
            $reader = ReaderFactory::create($runwayConfigPath);
            $io->green('Done!', true);
            
            // Setup destination storage
            $io->write('Setting up destination storage... ');
            $storage = WriterFactory::create($runwayConfigPath);
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
                            $io->red("Failed to process metric ID {$metric['id']}: {$e->getMessage()}", true);
                            // Add to processed IDs to delete the erroneous metric
                            $processedIds[] = $metric['id'];
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
     * Get worker options from config, defaults and command line
     *
     * @return array<string,mixed>
     */
    protected function getWorkerOptions(array $runwayConfig): array
    {
        $options = $runwayConfig['apm'];
        
        // Map command-line option names to property names
        $optionToPropertyMap = [
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
            else if(empty($options[$key])) {
                $options[$key] = $value;
            }
        }
        
        // Handle boolean flag
        $options['daemon'] = $this->daemon;
        
        // Convert numeric values
        foreach (['timeout', 'maxMessages', 'batchSize'] as $numKey) {
            if (isset($options[$numKey])) {
                $options[$numKey] = (int)$options[$numKey];
            }
        }
        
        return $options;
    }
}