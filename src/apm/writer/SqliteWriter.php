<?php

declare(strict_types=1);

namespace flight\apm\writer;

use PDO;
use PDOException;

class SqliteWriter implements WriterInterface
{
    private PDO $pdo;
    private array $statementCache = [];
    private bool $useTransactions = true;
    private int $batchSize = 100; // Default batch size for bulk operations

    /**
     * @param string $filename Path to SQLite database file
     * @param array $options Additional options:
     *                       - batch_size: Number of records per batch insert
     *                       - use_transactions: Whether to use transactions
     */
    public function __construct(string $dsn, array $options = [])
    {
        // SQLite-specific options
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5, // 5-second timeout
            PDO::ATTR_EMULATE_PREPARES => false, // Use native prepared statements
        ];

        $this->pdo = new PDO($dsn, null, null, $pdoOptions);
        
        // Enable WAL mode for better concurrency and performance
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA synchronous = NORMAL;'); // Less durability but better performance
        $this->pdo->exec('PRAGMA temp_store = MEMORY;'); // Store temporary tables in memory
        $this->pdo->exec('PRAGMA mmap_size = 30000000000;'); // Use memory-mapped I/O
        $this->pdo->exec('PRAGMA foreign_keys = ON;'); // Enable foreign key constraints
        
        // Set configuration options
        $this->batchSize = $options['batch_size'] ?? 100;
        $this->useTransactions = $options['use_transactions'] ?? true;

        // Ensure the metrics tables exist
        $this->ensureTablesExist();
    }

    /**
     * Get prepared statement from cache or create new one
     *
     * @param string $sql SQL statement
     * @return \PDOStatement The prepared statement
     */
    private function getStatement(string $sql): \PDOStatement
    {
        if (!isset($this->statementCache[$sql])) {
            $this->statementCache[$sql] = $this->pdo->prepare($sql);
        }
        
        return $this->statementCache[$sql];
    }

    /**
     * Store metrics data
     * 
     * @param array $metrics The metrics to store
     */
    public function store(array $metrics): void
    {
        // Begin transaction for atomicity if enabled
        if ($this->useTransactions) {
            $this->pdo->beginTransaction();
        }
        
        try {
            // Store main metrics record
            $requestId = $this->storeMainMetrics($metrics);
            
            // Store related data
            $this->storeRouteMetrics($requestId, $metrics['routes'] ?? []);
            $this->storeMiddlewareMetrics($requestId, $metrics['middleware'] ?? []);
            $this->storeViewMetrics($requestId, $metrics['views'] ?? []);
            $this->storeDbMetrics($requestId, $metrics['db'] ?? []);
            $this->storeErrorMetrics($requestId, $metrics['errors'] ?? []);
            $this->storeCacheMetrics($requestId, $metrics['cache'] ?? []);
            $this->storeCustomEvents($requestId, $metrics['custom'] ?? []);
            
            // Store raw JSON for any data not covered by the schema
            $this->storeRawMetrics($requestId, $metrics);
            
            if ($this->useTransactions) {
                $this->pdo->commit();
            }
        } catch (\Exception $e) {
            if ($this->useTransactions) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Store main request metrics and return the generated request_id
     * 
     * @param array $metrics
     * @return string The generated request ID
     */
    protected function storeMainMetrics(array $metrics): string
    {
        // Use provided request ID or generate a new one if not available
        $requestId = $metrics['request_id'] ?? $this->generateRequestId();

        $stmt = $this->getStatement('
            INSERT INTO apm_requests (
                request_id, 
                timestamp, 
                request_method,
                request_url, 
                total_time, 
                peak_memory,
                response_code,
                response_size,
                response_build_time,
                is_bot,
                ip,
                user_agent,
                host,
                session_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $isBot = (int) $metrics['is_bot'];
        
        $stmt->execute([
            $requestId,
            gmdate('Y-m-d H:i:s', (int) $metrics['start_time']),
            $metrics['request_method'] ?? null,
            $metrics['request_url'] ?? null,
            $metrics['total_time'] ?? null,
            $metrics['peak_memory'] ?? null,
            $metrics['response_code'] ?? null,
            $metrics['response_size'] ?? null,
            $metrics['response_build_time'] ?? null,
            $isBot,
            $metrics['ip'] ?? null,
            $metrics['user_agent'] ?? null,
            $metrics['host'] ?? null,
            $metrics['session_id'] ?? null
        ]);
        
        return $requestId;
    }
    
    /**
     * Store route metrics using batch inserts optimized for SQLite
     * 
     * @param string $requestId
     * @param array $routes
     * @return void
     */
    protected function storeRouteMetrics(string $requestId, array $routes): void
    {
        if (empty($routes)) {
            return;
        }
        
        $stmt = $this->getStatement('
            INSERT INTO apm_routes (
                request_id, route_pattern, execution_time, memory_used
            ) VALUES (?, ?, ?, ?)
        ');
        
        foreach ($routes as $pattern => $data) {
            $stmt->execute([
                $requestId,
                $pattern,
                $data['execution_time'] ?? null,
                $data['memory_used'] ?? null
            ]);
        }
    }
    
    /**
     * Store middleware metrics
     * 
     * @param string $requestId
     * @param array $middleware
     * @return void
     */
    protected function storeMiddlewareMetrics(string $requestId, array $middleware): void
    {
        if (empty($middleware)) {
            return;
        }
        
        $stmt = $this->getStatement('
            INSERT INTO apm_middleware (
                request_id, route_pattern, middleware_name, execution_time
            ) VALUES (?, ?, ?, ?)
        ');
        
        foreach ($middleware as $routePattern => $middlewareList) {
            foreach ($middlewareList as $middlewareData) {
                $stmt->execute([
                    $requestId,
                    $routePattern,
                    $middlewareData['middleware'] ?? null,
                    $middlewareData['execution_time'] ?? null
                ]);
            }
        }
    }
    
    /**
     * Store view metrics
     * 
     * @param string $requestId
     * @param array $views
     * @return void
     */
    protected function storeViewMetrics(string $requestId, array $views): void
    {
        if (empty($views)) {
            return;
        }
        
        $stmt = $this->getStatement('
            INSERT INTO apm_views (
                request_id, view_file, render_time
            ) VALUES (?, ?, ?)
        ');
        
        foreach ($views as $file => $data) {
            $stmt->execute([
                $requestId,
                $file,
                $data['render_time'] ?? null
            ]);
        }
    }
    
    /**
     * Store database metrics
     * 
     * @param string $requestId
     * @param array $dbData
     * @return void
     */
    protected function storeDbMetrics(string $requestId, array $dbData): void
    {
        if (empty($dbData)) {
            return;
        }
        
        // Store connection data
        if (!empty($dbData['connection_data'])) {
            $connStmt = $this->getStatement('
                INSERT INTO apm_db_connections (
                    request_id, engine, host, database_name
                ) VALUES (?, ?, ?, ?)
            ');
            
			$connStmt->execute([
				$requestId,
				$dbData['connection_data']['engine'] ?? null,
				$dbData['connection_data']['host'] ?? null,
				$dbData['connection_data']['database'] ?? null
			]);
        }
        
        // Store query data
        if (!empty($dbData['query_data'])) {
            $queryStmt = $this->getStatement('
                INSERT INTO apm_db_queries (
                    request_id, query, params, execution_time, row_count, memory_usage
                ) VALUES (?, ?, ?, ?, ?, ?)
            ');
            
            foreach ($dbData['query_data'] as $queryData) {
                $queryStmt->execute([
                    $requestId,
                    $queryData['sql'] ?? null,
                    is_array($queryData['params'] ?? null) ? json_encode($queryData['params']) : null,
                    $queryData['execution_time'] ?? null,
                    $queryData['row_count'] ?? null,
                    $queryData['memory_usage'] ?? null
                ]);
            }
        }
    }
    
    /**
     * Store error metrics
     * 
     * @param string $requestId
     * @param array $errors
     * @return void
     */
    protected function storeErrorMetrics(string $requestId, array $errors): void
    {
        if (empty($errors)) {
            return;
        }
        
        $stmt = $this->getStatement('
            INSERT INTO apm_errors (
                request_id, error_message, error_code, error_trace
            ) VALUES (?, ?, ?, ?)
        ');
        
        foreach ($errors as $error) {
            $stmt->execute([
                $requestId,
                $error['message'] ?? null,
                $error['code'] ?? null,
                $error['trace'] ?? null
            ]);
        }
    }
    
    /**
     * Store cache metrics
     * 
     * @param string $requestId
     * @param array $cacheData
     * @return void
     */
    protected function storeCacheMetrics(string $requestId, array $cacheData): void
    {
        if (empty($cacheData)) {
            return;
        }
        
        $stmt = $this->getStatement('
            INSERT INTO apm_cache (
                request_id, cache_key, hit, execution_time
            ) VALUES (?, ?, ?, ?)
        ');
        
        foreach ($cacheData as $key => $data) {
            $stmt->execute([
                $requestId,
                $key,
                isset($data['hit']) ? (int) $data['hit'] : null, // Convert boolean to int
                $data['execution_time'] ?? null
            ]);
        }
    }
    
    /**
     * Store custom events
     *
     * @param string $requestId
     * @param array $customEvents
     * @return void
     */
    protected function storeCustomEvents(string $requestId, array $customEvents): void
    {
        if (empty($customEvents)) {
            return;
        }
        
        // First statement for original table
        $eventStmt = $this->getStatement('
            INSERT INTO apm_custom_events (
                request_id, event_type, event_data, timestamp
            ) VALUES (?, ?, ?, datetime(?, \'unixepoch\'))
        ');
        
        // Second statement for new key-value table
        $dataStmt = $this->getStatement('
            INSERT INTO apm_custom_event_data (
                custom_event_id, request_id, json_key, json_value
            ) VALUES (?, ?, ?, ?)
        ');
        
        foreach ($customEvents as $event) {
            // Insert into main events table first
            $eventStmt->execute([
                $requestId,
                $event['type'],
                json_encode($event['data'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $event['timestamp']
            ]);
            
            // Get the last inserted ID for the foreign key relationship
            $eventId = $this->pdo->lastInsertId();
            
            // Now insert each key-value pair into the event_data table
            foreach ($event['data'] as $key => $value) {
                // If value is an array, convert it to JSON string
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                
                $dataStmt->execute([
                    $eventId,
                    $requestId,
                    $key,
                    $value
                ]);
            }
        }
    }
    
    /**
     * Store raw metrics JSON
     * 
     * @param string $requestId
     * @param array $metrics
     * @return void
     */
    protected function storeRawMetrics(string $requestId, array $metrics): void
    {
        $stmt = $this->getStatement('
            INSERT INTO apm_raw_metrics (
                request_id,
                metrics_json
            ) VALUES (?, ?)
        ');
        
        $stmt->execute([
            $requestId,
            json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        ]);
    }

    /**
     * Batch insert multiple rows into a table - simplified for SQLite
     * SQLite doesn't benefit from multi-value inserts in the same way MySQL does
     *
     * @param string $table Table name
     * @param array $columns Column names
     * @param array $valuesList List of value arrays
     * @return void
     */
    protected function batchInsert(string $table, array $columns, array $valuesList): void
    {
        if (empty($valuesList)) {
            return;
        }
        
        // For SQLite, prepare a single statement and execute it in a loop
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $columns),
            $placeholders
        );
        
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($valuesList as $values) {
            $stmt->execute($values);
        }
    }
    
    /**
     * Generate a unique request ID
     * 
     * @return string
     */
    protected function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * Ensure all required tables exist in the database, optimized for SQLite
     *
     * @return void
     */
    protected function ensureTablesExist(): void
    {
        // Check if tables already exist
        try {
            $this->pdo->query("SELECT 1 FROM apm_requests LIMIT 1");
            // Tables exist, no need to recreate
            return;
        } catch (PDOException $e) {
            // Tables don't exist, continue to create them
			throw new \RuntimeException("Database tables do not exist. Make sure you have run 'php vendor/bin/runway apm:migrate'.");
        }
    }
}
