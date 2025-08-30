<?php
/*

don't think we need it


declare(strict_types=1);

namespace flight\apm\writer;

trait WriterCommonTrait
{
    private array $statementCache = [];
    private bool $useTransactions = true;
    private int $batchSize = 100;

    private function getStatement(string $sql): \PDOStatement
    {
        if (!isset($this->statementCache[$sql])) {
            $this->statementCache[$sql] = $this->pdo->prepare($sql);
        }
        return $this->statementCache[$sql];
    }

    public function store(array $metrics): void
    {
        if ($this->useTransactions) {
            $this->pdo->beginTransaction();
        }
        try {
            $requestId = $this->storeMainMetrics($metrics);
            $this->storeRouteMetrics($requestId, $metrics['routes'] ?? []);
            $this->storeMiddlewareMetrics($requestId, $metrics['middleware'] ?? []);
            $this->storeViewMetrics($requestId, $metrics['views'] ?? []);
            $this->storeDbMetrics($requestId, $metrics['db'] ?? []);
            $this->storeErrorMetrics($requestId, $metrics['errors'] ?? []);
            $this->storeCacheMetrics($requestId, $metrics['cache'] ?? []);
            $this->storeCustomEvents($requestId, $metrics['custom'] ?? []);
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

    protected function storeMainMetrics(array $metrics): string
    {
        $requestId = $metrics['request_id'] ?? $this->generateRequestId();
        $stmt = $this->getStatement('
            INSERT INTO apm_requests (
                request_id, timestamp, request_method, request_url, total_time, peak_memory, response_code, response_size, response_build_time, is_bot, ip, user_agent, host, session_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $isBot = (int) ($metrics['is_bot'] ?? 0);
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

    protected function storeRouteMetrics(string $requestId, array $routes): void
    {
        if (empty($routes)) return;
        $stmt = $this->getStatement('
            INSERT INTO apm_routes (request_id, route_pattern, execution_time, memory_used) VALUES (?, ?, ?, ?)
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

    protected function storeMiddlewareMetrics(string $requestId, array $middleware): void
    {
        if (empty($middleware)) return;
        $stmt = $this->getStatement('
            INSERT INTO apm_middleware (request_id, route_pattern, middleware_name, execution_time) VALUES (?, ?, ?, ?)
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

    protected function storeViewMetrics(string $requestId, array $views): void
    {
        if (empty($views)) return;
        $stmt = $this->getStatement('
            INSERT INTO apm_views (request_id, view_file, render_time) VALUES (?, ?, ?)
        ');
        foreach ($views as $file => $data) {
            $stmt->execute([
                $requestId,
                $file,
                $data['render_time'] ?? null
            ]);
        }
    }

    protected function storeDbMetrics(string $requestId, array $dbData): void
    {
        if (empty($dbData)) return;
        if (!empty($dbData['connection_data'])) {
            $connStmt = $this->getStatement('
                INSERT INTO apm_db_connections (request_id, engine, host, database_name) VALUES (?, ?, ?, ?)
            ');
            $connStmt->execute([
                $requestId,
                $dbData['connection_data']['engine'] ?? null,
                $dbData['connection_data']['host'] ?? null,
                $dbData['connection_data']['database'] ?? null
            ]);
        }
        if (!empty($dbData['query_data'])) {
            $queryStmt = $this->getStatement('
                INSERT INTO apm_db_queries (request_id, query, params, execution_time, row_count, memory_usage) VALUES (?, ?, ?, ?, ?, ?)
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

    protected function storeErrorMetrics(string $requestId, array $errors): void
    {
        if (empty($errors)) return;
        $stmt = $this->getStatement('
            INSERT INTO apm_errors (request_id, error_message, error_code, error_trace) VALUES (?, ?, ?, ?)
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

    protected function storeCacheMetrics(string $requestId, array $cacheData): void
    {
        if (empty($cacheData)) return;
        $stmt = $this->getStatement('
            INSERT INTO apm_cache (request_id, cache_key, hit, execution_time) VALUES (?, ?, ?, ?)
        ');
        foreach ($cacheData as $key => $data) {
            $stmt->execute([
                $requestId,
                $key,
                isset($data['hit']) ? (int) $data['hit'] : null,
                $data['execution_time'] ?? null
            ]);
        }
    }

    protected function storeCustomEvents(string $requestId, array $customEvents): void
    {
        if (empty($customEvents)) return;
        $eventStmt = $this->getStatement($this->getCustomEventInsertSql());
        $dataStmt = $this->getStatement('
            INSERT INTO apm_custom_event_data (custom_event_id, request_id, json_key, json_value) VALUES (?, ?, ?, ?)
        ');
        foreach ($customEvents as $event) {
            $eventStmt->execute([
                $requestId,
                $event['type'],
                json_encode($event['data'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $event['timestamp']
            ]);
            $eventId = $this->pdo->lastInsertId();
            foreach ($event['data'] as $key => $value) {
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

    protected function storeRawMetrics(string $requestId, array $metrics): void
    {
        $stmt = $this->getStatement('
            INSERT INTO apm_raw_metrics (request_id, metrics_json) VALUES (?, ?)
        ');
        $stmt->execute([
            $requestId,
            json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        ]);
    }

    protected function batchInsert(string $table, array $columns, array $valuesList): void
    {
        if (empty($valuesList)) return;
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), $placeholders);
        $stmt = $this->pdo->prepare($sql);
        foreach ($valuesList as $values) {
            $stmt->execute($values);
        }
    }

    protected function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    // This method allows DB-specific event timestamp handling
    protected function getCustomEventInsertSql(): string
    {
        // Default for SQLite
        return '
            INSERT INTO apm_custom_events (
                request_id, event_type, event_data, timestamp
            ) VALUES (?, ?, ?, datetime(?, \'unixepoch\'))
        ';
    }
}
*/