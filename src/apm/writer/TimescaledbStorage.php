<?php

declare(strict_types=1);

namespace flight\apm\writer;

use PDO;

class TimescaledbStorage implements StorageInterface
{
	private \PDO $pdo;

	public function __construct(string $dsn, string $user, string $password)
	{
		$this->pdo = new \PDO($dsn, $user, $password);
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

		// Ensure the metrics tables exist
		$this->ensureTablesExist();
	}

	public function store(array $metrics): void
	{
		// Begin transaction for atomicity
		$this->pdo->beginTransaction();
		
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
			
			$this->pdo->commit();
		} catch (\Exception $e) {
			$this->pdo->rollBack();
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
		
		$stmt = $this->pdo->prepare('
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
			) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)
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
	 * Store route metrics
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
		
		$stmt = $this->pdo->prepare('
			INSERT INTO apm_routes (
				request_id,
				route_pattern,
				execution_time,
				memory_used
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
		
		$stmt = $this->pdo->prepare('
			INSERT INTO apm_middleware (
				request_id,
				route_pattern,
				middleware_name,
				execution_time
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
		
		$stmt = $this->pdo->prepare('
			INSERT INTO apm_views (
				request_id,
				view_file,
				render_time
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
			$stmt = $this->pdo->prepare('
				INSERT INTO apm_db_connections (
					request_id,
					engine,
					host,
					database_name
				) VALUES (?, ?, ?, ?)
			');
			
			foreach ($dbData['connection_data'] as $connData) {
				$stmt->execute([
					$requestId,
					$connData['engine'] ?? null,
					$connData['host'] ?? null,
					$connData['database'] ?? null
				]);
			}
		}
		
		// Store query data
		if (!empty($dbData['query_data'])) {
			$stmt = $this->pdo->prepare('
				INSERT INTO apm_db_queries (
					request_id,
					query,
					params,
					execution_time,
					row_count,
					memory_usage
				) VALUES (?, ?, ?, ?, ?, ?)
			');
			
			foreach ($dbData['query_data'] as $queryData) {
				$stmt->execute([
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
		
		$stmt = $this->pdo->prepare('
			INSERT INTO apm_errors (
				request_id,
				error_message,
				error_code,
				error_trace
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
		
		$stmt = $this->pdo->prepare('
			INSERT INTO apm_cache (
				request_id,
				cache_key,
				cache_operation,
				hit,
				execution_time
			) VALUES (?, ?, ?, ?, ?)
		');
		
		foreach ($cacheData as $key => $data) {
			$stmt->execute([
				$requestId,
				$key,
				$data['operation'] ?? null,
				$data['hit'] ?? null,
				$data['execution_time'] ?? null
			]);
		}
	}
	
	/**
	 * Store raw metrics JSON for data not covered by the schema
	 * 
	 * @param string $requestId
	 * @param array $metrics
	 * @return void
	 */
	protected function storeRawMetrics(string $requestId, array $metrics): void
	{
		$stmt = $this->pdo->prepare('
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

	protected function storeCustomEvents(string $requestId, array $customEvents): void
	{
		if (empty($customEvents)) {
			return;
		}

		$stmt = $this->pdo->prepare('
			INSERT INTO apm_custom_events (
				request_id,
				event_type,
				event_data,
				timestamp
			) VALUES (?, ?, ?, ?)
		');

		foreach ($customEvents as $event) {
			$stmt->execute([
				$requestId,
				$event['type'],
				json_encode($event['data'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
				date('Y-m-d H:i:s.u', $event['timestamp']) // Convert microtime float to timestamp string
			]);
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
     * Ensure all required tables exist in the database
     *
     * @return void
     */
    protected function ensureTablesExist(): void
    {
        // Main requests table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_requests (
            request_id TEXT PRIMARY KEY,
            timestamp TIMESTAMPTZ NOT NULL,
            request_method TEXT,
            request_url TEXT,
            total_time DOUBLE PRECISION,
            peak_memory BIGINT,
            response_code INTEGER,
            response_size BIGINT,
            response_build_time DOUBLE PRECISION
        )");
        
        // Routes table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_routes (
            id SERIAL PRIMARY KEY,
            request_id TEXT NOT NULL REFERENCES apm_requests(request_id) ON DELETE CASCADE,
            route_pattern TEXT,
            execution_time DOUBLE PRECISION,
            memory_used BIGINT
        )");
        
        // Middleware table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_middleware (
            id SERIAL PRIMARY KEY,
            request_id TEXT NOT NULL REFERENCES apm_requests(request_id) ON DELETE CASCADE,
            route_pattern TEXT,
            middleware_name TEXT,
            execution_time DOUBLE PRECISION
        )");
        
        // Views table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_views (
            id SERIAL PRIMARY KEY,
            request_id TEXT NOT NULL REFERENCES apm_requests(request_id) ON DELETE CASCADE,
            view_file TEXT,
            render_time DOUBLE PRECISION
        )");
        
        // DB Connections table with updated schema
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_db_connections (
            id SERIAL PRIMARY KEY,
            request_id TEXT NOT NULL REFERENCES apm_requests(request_id) ON DELETE CASCADE,
            engine TEXT,
            host TEXT,
            database_name TEXT
        )");
        
        // DB Queries table with updated schema
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_db_queries (
            id SERIAL PRIMARY KEY,
            request_id TEXT NOT NULL REFERENCES apm_requests(request_id) ON DELETE CASCADE,
            query TEXT,
            params TEXT,
            execution_time DOUBLE PRECISION,
            row_count INTEGER,
            memory_usage BIGINT
        )");
        
        // Errors table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_errors (
            id SERIAL PRIMARY KEY,
            request_id TEXT NOT NULL REFERENCES apm_requests(request_id) ON DELETE CASCADE,
            error_message TEXT,
            error_code INTEGER,
            error_trace TEXT
        )");
        
        // Cache operations table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_cache (
            id SERIAL PRIMARY KEY,
            request_id TEXT NOT NULL REFERENCES apm_requests(request_id) ON DELETE CASCADE,
            cache_key TEXT,
            cache_operation TEXT,
            hit BOOLEAN,
            execution_time DOUBLE PRECISION
        )");
        
        // Raw metrics table for data not covered by the schema
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_raw_metrics (
            request_id TEXT PRIMARY KEY REFERENCES apm_requests(request_id) ON DELETE CASCADE,
            metrics_json TEXT NOT NULL
        )");

		$this->pdo->exec("CREATE TABLE IF NOT EXISTS apm_custom_events (
			id SERIAL PRIMARY KEY,
			request_id TEXT NOT NULL REFERENCES apm_requests(request_id) ON DELETE CASCADE,
			event_type TEXT NOT NULL,
			event_data JSONB,
			timestamp TIMESTAMPTZ NOT NULL
		)");
        
        // Create indexes for better query performance
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apm_requests_timestamp ON apm_requests(timestamp)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apm_requests_url ON apm_requests(request_url)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apm_requests_response_code ON apm_requests(response_code)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apm_routes_pattern ON apm_routes(route_pattern)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apm_middleware_name ON apm_middleware(middleware_name)");
		$this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apm_custom_events_timestamp ON apm_custom_events(timestamp)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apm_db_queries_execution_time ON apm_db_queries(execution_time)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apm_db_connections_engine ON apm_db_connections(engine)");
        
        // If using TimescaleDB, create hypertable
        try {
            $this->pdo->exec("SELECT create_hypertable('apm_requests', 'timestamp', if_not_exists => true)");
        } catch (\Exception $e) {
            // TimescaleDB might not be available, that's ok
        }
    }
}