<?php

declare(strict_types=1);

namespace flight\apm\worker;

use PDO;
use PDOException;

class MysqlStorage implements StorageInterface
{
    private PDO $pdo;
    private array $statementCache = [];
    private bool $useTransactions = true;
    private int $batchSize = 100; // Default batch size for bulk operations

    /**
     * @param string $dsn MySQL DSN string
     * @param string $user MySQL username
     * @param string $password MySQL password
     * @param array $options Additional options:
     *                       - batch_size: Number of records per batch insert
     *                       - use_transactions: Whether to use transactions
     */
    public function __construct(string $dsn, string $user, string $password, array $options = [])
    {
        // Ensure persistent connections
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_EMULATE_PREPARES => false, // Use native prepared statements
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
            PDO::ATTR_TIMEOUT => 5, // 5-second timeout
        ];

        $this->pdo = new PDO($dsn, $user, $password, $pdoOptions);
        
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
        $requestId = $this->generateRequestId();
        
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
                response_build_time
            ) VALUES (?, NOW(3), ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $requestId,
            $metrics['request_method'] ?? null,
            $metrics['request_url'] ?? null,
            $metrics['total_time'] ?? null,
            $metrics['peak_memory'] ?? null,
            $metrics['response_code'] ?? null,
            $metrics['response_size'] ?? null,
            $metrics['response_build_time'] ?? null
        ]);
        
        return $requestId;
    }
    
    /**
     * Store route metrics using batch inserts
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
        
        $chunks = array_chunk($routes, $this->batchSize, true);
        
        foreach ($chunks as $chunk) {
            $placeholders = [];
            $values = [];
            
            foreach ($chunk as $pattern => $data) {
                $placeholders[] = "(?, ?, ?, ?)";
                array_push(
                    $values, 
                    $requestId,
                    $pattern,
                    $data['execution_time'] ?? null,
                    $data['memory_used'] ?? null
                );
            }
            
            $sql = sprintf(
                'INSERT INTO apm_routes (request_id, route_pattern, execution_time, memory_used) VALUES %s',
                implode(', ', $placeholders)
            );
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
        }
    }
    
    /**
     * Store middleware metrics using batch inserts
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
        
        $values = [];
        
        foreach ($middleware as $routePattern => $middlewareList) {
            foreach ($middlewareList as $middlewareData) {
                $values[] = [
                    $requestId,
                    $routePattern,
                    $middlewareData['middleware'] ?? null,
                    $middlewareData['execution_time'] ?? null
                ];
            }
        }
        
        $this->batchInsert(
            'apm_middleware',
            ['request_id', 'route_pattern', 'middleware_name', 'execution_time'],
            $values
        );
    }
    
    /**
     * Store view metrics using batch inserts
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
        
        $values = [];
        
        foreach ($views as $file => $data) {
            $values[] = [
                $requestId,
                $file,
                $data['render_time'] ?? null
            ];
        }
        
        $this->batchInsert(
            'apm_views',
            ['request_id', 'view_file', 'render_time'],
            $values
        );
    }
    
    /**
     * Store database metrics using batch inserts
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
            $values = [];
            
            foreach ($dbData['connection_data'] as $connData) {
                $values[] = [
                    $requestId,
                    $connData['engine'] ?? null,
                    $connData['host'] ?? null,
                    $connData['database'] ?? null
                ];
            }
            
            $this->batchInsert(
                'apm_db_connections',
                ['request_id', 'engine', 'host', 'database_name'],
                $values
            );
        }
        
        // Store query data
        if (!empty($dbData['query_data'])) {
            $values = [];
            
            foreach ($dbData['query_data'] as $queryData) {
                $values[] = [
                    $requestId,
                    $queryData['sql'] ?? null,
                    is_array($queryData['params'] ?? null) ? json_encode($queryData['params']) : null,
                    $queryData['execution_time'] ?? null,
                    $queryData['row_count'] ?? null,
                    $queryData['memory_usage'] ?? null
                ];
            }
            
            $this->batchInsert(
                'apm_db_queries',
                ['request_id', 'query', 'params', 'execution_time', 'row_count', 'memory_usage'],
                $values
            );
        }
    }
    
    /**
     * Store error metrics using batch inserts
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
        
        $values = [];
        
        foreach ($errors as $error) {
            $values[] = [
                $requestId,
                $error['message'] ?? null,
                $error['code'] ?? null,
                $error['trace'] ?? null
            ];
        }
        
        $this->batchInsert(
            'apm_errors',
            ['request_id', 'error_message', 'error_code', 'error_trace'],
            $values
        );
    }
    
    /**
     * Store cache metrics using batch inserts
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
        
        $values = [];
        
        foreach ($cacheData as $key => $data) {
            $values[] = [
                $requestId,
                $key,
                $data['operation'] ?? null,
                isset($data['hit']) ? (int)$data['hit'] : null, // Convert boolean to int for MySQL
                $data['execution_time'] ?? null
            ];
        }
        
        $this->batchInsert(
            'apm_cache',
            ['request_id', 'cache_key', 'cache_operation', 'hit', 'execution_time'],
            $values
        );
    }
    
    /**
     * Store custom events using batch inserts
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
        
        $values = [];
        
        foreach ($customEvents as $event) {
            $values[] = [
                $requestId,
                $event['type'],
                json_encode($event['data'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                date('Y-m-d H:i:s.u', $event['timestamp']) // Format timestamp with microseconds
            ];
        }
        
        $this->batchInsert(
            'apm_custom_events',
            ['request_id', 'event_type', 'event_data', 'timestamp'],
            $values
        );
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
     * Batch insert multiple rows into a table
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
        
        // Split into chunks to avoid too many parameters in one query
        $chunks = array_chunk($valuesList, $this->batchSize);
        
        foreach ($chunks as $chunk) {
            $placeholders = [];
            $flatValues = [];
            
            // Build placeholders and flatten values
            foreach ($chunk as $values) {
                $placeholders[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                foreach ($values as $value) {
                    $flatValues[] = $value;
                }
            }
            
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders)
            );
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($flatValues);
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
     * Ensure all required tables exist in the database optimized for MySQL
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
        }

        // Create tables with optimizations for MySQL
        
        // Main requests table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_requests (
            request_id VARCHAR(36) PRIMARY KEY,
            timestamp DATETIME(3) NOT NULL,
            request_method VARCHAR(10),
            request_url VARCHAR(1024),
            total_time DOUBLE,
            peak_memory BIGINT,
            response_code INT,
            response_size BIGINT,
            response_build_time DOUBLE,
            INDEX idx_timestamp (timestamp),
            INDEX idx_url (request_url(255)),
            INDEX idx_response_code (response_code)
        ) ENGINE=InnoDB");
        
        // Routes table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_routes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            route_pattern VARCHAR(255),
            execution_time DOUBLE,
            memory_used BIGINT,
            INDEX idx_request_id (request_id),
            INDEX idx_route_pattern (route_pattern(100)),
            CONSTRAINT fk_routes_request_id FOREIGN KEY (request_id)
                REFERENCES apm_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // Middleware table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_middleware (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            route_pattern VARCHAR(255),
            middleware_name VARCHAR(255),
            execution_time DOUBLE,
            INDEX idx_request_id (request_id),
            INDEX idx_middleware_name (middleware_name(100)),
            CONSTRAINT fk_middleware_request_id FOREIGN KEY (request_id)
                REFERENCES apm_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // Views table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_views (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            view_file VARCHAR(512),
            render_time DOUBLE,
            INDEX idx_request_id (request_id),
            INDEX idx_view_file (view_file(255)),
            CONSTRAINT fk_views_request_id FOREIGN KEY (request_id)
                REFERENCES apm_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // DB Connections table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_db_connections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            engine VARCHAR(50),
            host VARCHAR(255),
            database_name VARCHAR(255),
            INDEX idx_request_id (request_id),
            INDEX idx_engine (engine),
            CONSTRAINT fk_db_connections_request_id FOREIGN KEY (request_id)
                REFERENCES apm_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // DB Queries table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_db_queries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            query TEXT,
            params TEXT,
            execution_time DOUBLE,
            row_count INT,
            memory_usage BIGINT,
            INDEX idx_request_id (request_id),
            INDEX idx_execution_time (execution_time),
            CONSTRAINT fk_db_queries_request_id FOREIGN KEY (request_id)
                REFERENCES apm_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // Errors table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_errors (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            error_message TEXT,
            error_code INT,
            error_trace TEXT,
            INDEX idx_request_id (request_id),
            INDEX idx_error_code (error_code),
            CONSTRAINT fk_errors_request_id FOREIGN KEY (request_id)
                REFERENCES apm_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // Cache operations table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_cache (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            cache_key VARCHAR(255),
            cache_operation VARCHAR(50),
            hit TINYINT(1),
            execution_time DOUBLE,
            INDEX idx_request_id (request_id),
            INDEX idx_cache_key (cache_key(100)),
            INDEX idx_hit (hit),
            CONSTRAINT fk_cache_request_id FOREIGN KEY (request_id)
                REFERENCES apm_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // Custom events table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_custom_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            event_data LONGTEXT,
            timestamp DATETIME(3) NOT NULL,
            INDEX idx_request_id (request_id),
            INDEX idx_event_type (event_type),
            INDEX idx_timestamp (timestamp),
            CONSTRAINT fk_custom_events_request_id FOREIGN KEY (request_id)
                REFERENCES apm_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // Raw metrics table for data not covered by the schema
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_raw_metrics (
            request_id VARCHAR(36) PRIMARY KEY,
            metrics_json LONGTEXT NOT NULL,
            CONSTRAINT fk_raw_metrics_request_id FOREIGN KEY (request_id)
                REFERENCES apm_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // Create an index to optimize data retrieval by time periods
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apm_requests_composite ON apm_requests(timestamp, response_code, request_method(10))");
    }
}
