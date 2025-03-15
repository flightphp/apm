<?php

namespace flight\apm\presenter;

use PDO;

class SqlitePresenter implements PresenterInterface
{
    /**
     * PDO instance
     */
    protected PDO $db;

    /**
     * Constructor
     *
     * @param string $dsn PDO connection dsn
     */
    public function __construct(string $dsn)
    {
        $this->db = new PDO($dsn, null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false
		]);
    }

    /**
     * {@inheritdoc}
     */
    public function getDashboardData(string $threshold): array
    {
        // Slowest Requests
        $stmt = $this->db->prepare('SELECT request_id, request_url, total_time FROM apm_requests WHERE timestamp >= ? ORDER BY total_time DESC LIMIT 5');
        $stmt->execute([$threshold]);
        $slowRequests = $stmt->fetchAll();

        // Slowest Routes
        $stmt = $this->db->prepare('SELECT route_pattern, AVG(execution_time) as avg_time FROM apm_routes WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?) GROUP BY route_pattern ORDER BY avg_time DESC LIMIT 5');
        $stmt->execute([$threshold]);
        $slowRoutes = $stmt->fetchAll();

        // Error Rate
        $stmt = $this->db->prepare('SELECT COUNT(DISTINCT request_id) FROM apm_errors WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?)');
        $stmt->execute([$threshold]);
        $errorCount = $stmt->fetchColumn();
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM apm_requests WHERE timestamp >= ?');
        $stmt->execute([$threshold]);
        $totalRequests = $stmt->fetchColumn();
        $errorRate = $totalRequests > 0 ? $errorCount / $totalRequests : 0;

        // Long Queries
        $stmt = $this->db->prepare('SELECT query, execution_time FROM apm_db_queries WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?) ORDER BY execution_time DESC LIMIT 5');
        $stmt->execute([$threshold]);
        $longQueries = $stmt->fetchAll();

        // Slowest Middleware
        $stmt = $this->db->prepare('SELECT middleware_name, AVG(execution_time) as execution_time FROM apm_middleware WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?) GROUP BY middleware_name ORDER BY execution_time DESC LIMIT 5');
        $stmt->execute([$threshold]);
        $slowMiddleware = $stmt->fetchAll();

        // Cache Hit/Miss Rate
        $stmt = $this->db->prepare('SELECT hit, COUNT(*) as count FROM apm_cache WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?) GROUP BY hit');
        $stmt->execute([$threshold]);
        $cacheData = $stmt->fetchAll();
        $totalCacheOps = array_sum(array_column($cacheData, 'count'));
        $hits = array_filter($cacheData, fn($row) => $row['hit'] == 1);
        $hitCount = $hits ? array_sum(array_column($hits, 'count')) : 0;
        $cacheHitRate = $totalCacheOps > 0 ? $hitCount / $totalCacheOps : 0;

        // Response Code Distribution Over Time
        $stmt = $this->db->prepare('SELECT timestamp, response_code FROM apm_requests WHERE timestamp >= ? ORDER BY timestamp');
        $stmt->execute([$threshold]);
        $requestData = $stmt->fetchAll();
        $responseCodeData = [];
        $interval = 300; // 5 minutes
        foreach ($requestData as $row) {
            $timestamp = strtotime($row['timestamp']);
            $bucket = floor($timestamp / $interval) * $interval;
            $code = $row['response_code'];
            if (!isset($responseCodeData[$bucket])) {
                $responseCodeData[$bucket] = [];
            }
            if (!isset($responseCodeData[$bucket][$code])) {
                $responseCodeData[$bucket][$code] = 0;
            }
            $responseCodeData[$bucket][$code]++;
        }
        $responseCodeOverTime = [];
        $allCodes = array_unique(array_column($requestData, 'response_code'));
        foreach ($responseCodeData as $bucket => $codes) {
            $entry = ['timestamp' => date('Y-m-d H:i:s', $bucket)];
            foreach ($allCodes as $code) {
                $entry[$code] = $codes[$code] ?? 0;
            }
            $responseCodeOverTime[] = $entry;
        }

        // Latency Percentiles
        $stmt = $this->db->prepare('SELECT total_time FROM apm_requests WHERE timestamp >= ? ORDER BY total_time');
        $stmt->execute([$threshold]);
        $times = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $p95 = $this->calculatePercentile($times, 95);
        $p99 = $this->calculatePercentile($times, 99);

        // Graph Data (aggregated)
        $stmt = $this->db->prepare('SELECT timestamp, total_time FROM apm_requests WHERE timestamp >= ? ORDER BY timestamp');
        $stmt->execute([$threshold]);
        $requestData = $stmt->fetchAll();
        $aggregatedData = [];
        $interval = 300; // 5 minutes
        foreach ($requestData as $row) {
            $timestamp = strtotime($row['timestamp']);
            $bucket = floor($timestamp / $interval) * $interval;
            if (!isset($aggregatedData[$bucket])) {
                $aggregatedData[$bucket] = ['sum' => 0, 'count' => 0];
            }
            $aggregatedData[$bucket]['sum'] += $row['total_time'];
            $aggregatedData[$bucket]['count']++;
        }
        $chartData = array_map(function($bucket, $data) {
            return [
                'timestamp' => date('Y-m-d H:i:s', $bucket),
                'average_time' => $data['sum'] / $data['count'],
            ];
        }, array_keys($aggregatedData), $aggregatedData);

        return [
            'slowRequests' => $slowRequests,
            'slowRoutes' => $slowRoutes,
            'errorRate' => $errorRate,
            'longQueries' => $longQueries,
            'slowMiddleware' => $slowMiddleware,
            'cacheHitRate' => $cacheHitRate,
            'responseCodeOverTime' => $responseCodeOverTime,
            'p95' => $p95,
            'p99' => $p99,
            'chartData' => $chartData,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestsData(string $threshold, int $page, int $perPage, string $search = ''): array
    {
        $offset = ($page - 1) * $perPage;
        
        // Check if database supports JSON functions
        $hasJsonFunctions = $this->databaseSupportsJson();

        // Custom search handling - determine if we need to search in custom events
		$search = trim($search);
        $searchInCustomEvents = !empty($search);
        
        // Get all matching request IDs based on URL and response code
        $urlResponseCodeQuery = 'SELECT request_id FROM apm_requests WHERE timestamp >= ?';
        $urlResponseParams = [$threshold];
        if ($search) {
            $urlResponseCodeQuery .= ' AND (request_url LIKE ? OR response_code LIKE ?)';
            $urlResponseParams[] = "%$search%";
            $urlResponseParams[] = "%$search%";
        }

        $stmt = $this->db->prepare($urlResponseCodeQuery);
        $stmt->execute($urlResponseParams);
        $mainRequestIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get request IDs from custom events if we're searching
        $customEventRequestIds = [];
        if ($searchInCustomEvents) {
            // Try to use JSON functions if available
            if ($hasJsonFunctions) {
                // SQLite JSON functions
                $customEventsQuery = "SELECT request_id FROM apm_custom_events 
                    WHERE event_type LIKE ? 
                    OR json_extract(event_data, '$') LIKE ?";
                $stmt = $this->db->prepare($customEventsQuery);
                $stmt->execute(["%$search%", "%$search%"]);
            } else {
                // Fallback: Search only in event_type
                $customEventsQuery = "SELECT request_id FROM apm_custom_events WHERE event_type LIKE ?";
                $stmt = $this->db->prepare($customEventsQuery);
                $stmt->execute(["%$search%"]);
            }
            $customEventRequestIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Merge request IDs from different sources
        $allRequestIds = array_merge($mainRequestIds, $customEventRequestIds);
        $uniqueRequestIds = array_unique($allRequestIds);
        
        // If we have no matching requests, return empty result
        if (empty($uniqueRequestIds)) {
            return [
                'requests' => [],
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => 0,
                    'perPage' => $perPage,
                    'totalRequests' => 0,
                ],
            ];
        }
        
        // Count total matching requests for pagination
        $totalRequests = count($uniqueRequestIds);
        $totalPages = max(1, ceil($totalRequests / $perPage));
        
        // Apply pagination to the request IDs
        $paginatedRequestIds = array_slice($uniqueRequestIds, $offset, $perPage);
        
        if (empty($paginatedRequestIds)) {
            return [
                'requests' => [],
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => $totalPages,
                    'perPage' => $perPage,
                    'totalRequests' => $totalRequests,
                ],
            ];
        }
        
        // Create placeholder string for the IN clause
        $placeholders = implode(',', array_fill(0, count($paginatedRequestIds), '?'));
        
        // Get the actual request data
        $requestQuery = "SELECT request_id, timestamp, request_url, total_time, response_code FROM apm_requests 
            WHERE request_id IN ($placeholders) ORDER BY timestamp DESC";
        $stmt = $this->db->prepare($requestQuery);
        $stmt->execute($paginatedRequestIds);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch details for each request
        foreach ($requests as &$request) {
            $details = $this->getRequestDetails($request['request_id']);
            $request = array_merge($request, $details);
        }
        unset($request);

        return [
            'requests' => $requests,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'perPage' => $perPage,
                'totalRequests' => $totalRequests,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestDetails(string $requestId): array
    {
        $request = [];
        
        // Middleware
        $stmt = $this->db->prepare('SELECT middleware_name, execution_time FROM apm_middleware WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $request['middleware'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Database Queries
        $stmt = $this->db->prepare('SELECT query, execution_time, row_count FROM apm_db_queries WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $request['queries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Errors
        $stmt = $this->db->prepare('SELECT error_message, error_code FROM apm_errors WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $request['errors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache Operations
        $stmt = $this->db->prepare('SELECT cache_key, hit, execution_time FROM apm_cache WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $request['cache'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Custom Events
        $stmt = $this->db->prepare('SELECT timestamp, event_type, event_data FROM apm_custom_events WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $customEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process custom events data
        $events = [];
        foreach ($customEvents as $event) {
            $events[] = [
                'timestamp' => $event['timestamp'],
                'type' => $event['event_type'],
                'data' => json_decode($event['event_data'], true)
            ];
        }
        
        $request['custom_events'] = $events;
        
        return $request;
    }

    /**
     * Calculate percentile from an array of values
     *
     * @param array $data Array of numeric values
     * @param int $percentile Percentile to calculate (e.g. 95, 99)
     * @return float The calculated percentile value
     */
    protected function calculatePercentile(array $data, int $percentile): float
    {
        if (empty($data)) return 0;
        sort($data);
        $index = ($percentile / 100) * (count($data) - 1);
        $lower = floor($index);
        $upper = ceil($index);
        if ($lower == $upper) return $data[$lower];
        $fraction = $index - $lower;
        return $data[$lower] + $fraction * ($data[$upper] - $data[$lower]);
    }

    /**
     * Check if the database has JSON functions
     *
     * @return bool True if JSON functions are supported
     */
    protected function databaseSupportsJson(): bool
    {
        try {
            $this->db->query("SELECT json_extract('', '$')");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
