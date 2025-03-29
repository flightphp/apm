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
    public function getDashboardData(string $threshold, string $range = 'last_hour'): array
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

        // Response Code Distribution Over Time - with interval based on time range
        $stmt = $this->db->prepare('SELECT timestamp, response_code FROM apm_requests WHERE timestamp >= ? ORDER BY timestamp');
        $stmt->execute([$threshold]);
        $requestData = $stmt->fetchAll();
        $responseCodeData = [];
        
        // Set interval based on the selected time range
        switch ($range) {
            case 'last_day':
                $interval = 900; // 15 minutes (reduced from 30 minutes for more granularity)
                break;
            case 'last_week':
                $interval = 21600; // 6 hours (60 * 60 * 6)
                break;
            case 'last_hour':
            default:
                $interval = 300; // 5 minutes (default)
                break;
        }
        
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
        
        // Ensure we have entries for all time buckets in the selected period
        // This prevents gaps in the visualization
        $timeStart = strtotime($threshold);
        $timeEnd = time();
        
        for ($bucket = $timeStart - ($timeStart % $interval); $bucket <= $timeEnd; $bucket += $interval) {
            $entry = ['timestamp' => date('Y-m-d H:i:s', $bucket)];
            
            // Initialize all response codes to zero for this bucket
            foreach ($allCodes as $code) {
                $entry[$code] = isset($responseCodeData[$bucket][$code]) ? $responseCodeData[$bucket][$code] : 0;
            }
            
            $responseCodeOverTime[] = $entry;
        }

        // Latency Percentiles
        $stmt = $this->db->prepare('SELECT total_time FROM apm_requests WHERE timestamp >= ? ORDER BY total_time');
        $stmt->execute([$threshold]);
        $times = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $p95 = $this->calculatePercentile($times, 95);
        $p99 = $this->calculatePercentile($times, 99);

        // Graph Data (aggregated) - Optimized for large datasets
        // Use SQLite's strftime to group by time intervals directly in SQL
        $intervalSeconds = $interval;
        $stmt = $this->db->prepare("
            SELECT 
                (strftime('%s', timestamp) / ?) * ? as time_bucket,
                AVG(total_time) as average_time,
                COUNT(*) as request_count
            FROM apm_requests 
            WHERE timestamp >= ? 
            GROUP BY time_bucket
            ORDER BY time_bucket
        ");
        $stmt->execute([$intervalSeconds, $intervalSeconds, $threshold]);
        $aggregatedData = $stmt->fetchAll();
        
        $chartData = array_map(function($row) {
            return [
                'timestamp' => date('Y-m-d H:i:s', (int) $row['time_bucket']),
                'average_time' => $row['average_time'],
                'request_count' => $row['request_count']
            ];
        }, $aggregatedData);

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
            'allRequestsCount' => $totalRequests,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestsData(string $threshold, int $page, int $perPage, string $search = ''): array
    {
        $offset = ($page - 1) * $perPage;
        $maxRequests = 500; // Limit to a maximum of 500 requests
        
        // Check if database supports JSON functions
        $hasJsonFunctions = $this->databaseSupportsJson();

        // Extract filter parameters from the search string or use directly provided parameters
        $url = $_GET['url'] ?? '';
        $responseCode = $_GET['response_code'] ?? '';
        $responseCodePrefix = $_GET['response_code_prefix'] ?? '';
        $isBot = $_GET['is_bot'] ?? '';
        $customEventType = $_GET['custom_event_type'] ?? '';
        $minTime = $_GET['min_time'] ?? '';
        $requestId = $_GET['request_id'] ?? '';
        
        // New metadata filters
        $ip = $_GET['ip'] ?? '';
        $host = $_GET['host'] ?? '';
        $sessionId = $_GET['session_id'] ?? '';
        $userAgent = $_GET['user_agent'] ?? '';
        
        // Build main query with conditions for URL and response code
        $conditions = ['timestamp >= ?'];
        $params = [$threshold];

        // Add request ID filter (exact match)
        if (!empty($requestId)) {
            $conditions[] = 'request_id = ?';
            $params[] = $requestId;
        }

        // Add URL filter
        if (!empty($url)) {
            $conditions[] = 'request_url LIKE ?';
            $params[] = "%$url%";
        }

        // Add response code filter
        if (!empty($responseCode)) {
            $conditions[] = 'response_code = ?';
            $params[] = $responseCode;
        } elseif (!empty($responseCodePrefix)) {
            $conditions[] = 'response_code LIKE ?';
            $params[] = "$responseCodePrefix%";
        }

        // Add bot filter
        if ($isBot !== '' && ($isBot === '0' || $isBot === '1')) {
            $conditions[] = 'is_bot = ?';
            $params[] = $isBot;
        }

        // Add min time filter
        if (!empty($minTime) && is_numeric($minTime)) {
            // Convert from milliseconds to seconds for DB comparison
            $minTimeSeconds = floatval($minTime) / 1000;
            $conditions[] = 'total_time >= ?';
            $params[] = $minTimeSeconds;
        }
        
        // Add new metadata filters
        if (!empty($ip)) {
            $conditions[] = 'ip = ?';
            $params[] = "$ip";
        }
        
        if (!empty($host)) {
            $conditions[] = 'host = ?';
            $params[] = "$host";
        }
        
        if (!empty($sessionId)) {
            $conditions[] = 'session_id = ?';
            $params[] = "$sessionId";
        }
        
        if (!empty($userAgent)) {
            $conditions[] = 'user_agent LIKE ?';
            $params[] = "%$userAgent%";
        }

        // Build the base query with all conditions
        $whereClause = implode(' AND ', $conditions);
        $urlResponseCodeQuery = "SELECT request_id FROM apm_requests WHERE $whereClause ORDER BY timestamp DESC LIMIT ?";
        $params[] = $maxRequests;

        $stmt = $this->db->prepare($urlResponseCodeQuery);
        $stmt->execute($params);
        $mainRequestIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get request IDs from custom events if custom event type filter is set
        $customEventRequestIds = [];
        if (!empty($customEventType)) {
            // Try to use JSON functions if available
            if ($hasJsonFunctions) {
                // SQLite JSON functions
                $customEventsQuery = "SELECT request_id FROM apm_custom_events 
                    WHERE event_type LIKE ? 
                    LIMIT ?";
                $stmt = $this->db->prepare($customEventsQuery);
                $stmt->execute(["%$customEventType%", $maxRequests]);
            } else {
                // Fallback: Search only in event_type
                $customEventsQuery = "SELECT request_id FROM apm_custom_events WHERE event_type LIKE ? LIMIT ?";
                $stmt = $this->db->prepare($customEventsQuery);
                $stmt->execute(["%$customEventType%", $maxRequests]);
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
                ]
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
                ]
            ];
        }
        
        // Create placeholder string for the IN clause
        $placeholders = implode(',', array_fill(0, count($paginatedRequestIds), '?'));
        
        // Get the actual request data
        $requestQuery = "SELECT request_id, timestamp, request_url, total_time, response_code, is_bot, ip, user_agent, host, session_id FROM apm_requests 
            WHERE request_id IN ($placeholders) ORDER BY id DESC";
        $stmt = $this->db->prepare($requestQuery);
        $stmt->execute($paginatedRequestIds);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch details for each request
        foreach ($requests as &$request) {
            $details = $this->getRequestDetails($request['request_id']);
            $request = array_merge($request, $details);
        }
        unset($request);

        // After fetching the requests and before returning the result
        // Calculate response code distribution for the filtered requests
        $responseCodeDist = [];
        $range = $_GET['range'] ?? 'last_hour';
        
        // Set interval based on the selected time range
        switch ($range) {
            case 'last_day':
                $interval = 900; // 15 minutes (reduced from 30 minutes for more granularity)
                break;
            case 'last_week':
                $interval = 21600; // 6 hours
                break;
            case 'last_hour':
            default:
                $interval = 300; // 5 minutes
                break;
        }
        
        // If we have unique request IDs, get their response code distribution
        if (!empty($uniqueRequestIds)) {
            // For a more comprehensive view, we need to get ALL requests from the selected time range
            // not just the filtered ones for the response distribution graph
            $stmt = $this->db->prepare("SELECT timestamp, response_code FROM apm_requests 
                WHERE timestamp >= ? ORDER BY timestamp");
            $stmt->execute([$threshold]);
            $filteredRequestData = $stmt->fetchAll();
            
            $responseCodeData = [];
            
            foreach ($filteredRequestData as $row) {
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
            $allCodes = array_unique(array_column($filteredRequestData, 'response_code'));
            
            // Ensure we have entries for all time buckets in the selected period
            // This prevents gaps in the visualization
            $timeStart = strtotime($threshold);
            $timeEnd = time();
            
            for ($bucket = $timeStart - ($timeStart % $interval); $bucket <= $timeEnd; $bucket += $interval) {
                $entry = ['timestamp' => date('Y-m-d H:i:s', $bucket)];
                
                // Initialize all response codes to zero for this bucket
                foreach ($allCodes as $code) {
                    $entry[$code] = isset($responseCodeData[$bucket][$code]) ? $responseCodeData[$bucket][$code] : 0;
                }
                
                $responseCodeOverTime[] = $entry;
            }
            
            $responseCodeDist = $responseCodeOverTime;
        }

        return [
            'requests' => $requests,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'perPage' => $perPage,
                'totalRequests' => $totalRequests,
            ],
            'responseCodeDistribution' => $responseCodeDist
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
