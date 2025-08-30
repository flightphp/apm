<?php
declare(strict_types=1);

namespace flight\apm\presenter;

use flight\database\PdoWrapper;
use PDO;
use Throwable;

abstract class PresenterAbstract
{
    use PresenterCommonTrait;

    /** PdoWrapper instance */
    protected PdoWrapper $pdoWrapper;

    /** Runway Config */
    protected array $config;

    /**
     * {@inheritdoc}
     */
    public function getDashboardData(string $threshold, string $range = 'last_hour'): array
    {
        // Slowest Requests
        $slowRequests = $this->pdoWrapper->fetchAll(
            'SELECT id, request_token, request_url, total_time FROM apm_requests WHERE request_dt >= ? ORDER BY total_time DESC LIMIT 5',
            [$threshold]
        );

        // Slowest Routes
        $slowRoutes = $this->pdoWrapper->fetchAll(
            'SELECT route_pattern, AVG(execution_time) as avg_time FROM apm_routes WHERE request_id IN (SELECT id FROM apm_requests WHERE request_dt >= ?) GROUP BY route_pattern ORDER BY avg_time DESC LIMIT 5',
            [$threshold]
        );

        // Error Rate
        $errorCount = $this->pdoWrapper->fetchField(
            'SELECT COUNT(DISTINCT request_id) FROM apm_errors WHERE request_id IN (SELECT id FROM apm_requests WHERE request_dt >= ?)',
            [$threshold]
        );
        $totalRequests = $this->pdoWrapper->fetchField(
            'SELECT COUNT(*) FROM apm_requests WHERE request_dt >= ?',
            [$threshold]
        );
        $errorRate = $totalRequests > 0 ? $errorCount / $totalRequests : 0;

        // Long Queries
        $longQueries = $this->pdoWrapper->fetchAll(
            'SELECT query, execution_time FROM apm_db_queries WHERE request_id IN (SELECT id FROM apm_requests WHERE request_dt >= ?) ORDER BY execution_time DESC LIMIT 5',
            [$threshold]
        );

        // Slowest Middleware
        $slowMiddleware = $this->pdoWrapper->fetchAll(
            'SELECT middleware_name, AVG(execution_time) as execution_time FROM apm_middleware WHERE request_id IN (SELECT id FROM apm_requests WHERE request_dt >= ?) GROUP BY middleware_name ORDER BY execution_time DESC LIMIT 5',
            [$threshold]
        );

        // Cache Hit/Miss Rate
        $cacheData = $this->pdoWrapper->fetchAll(
            'SELECT hit, COUNT(*) as count FROM apm_cache WHERE request_id IN (SELECT id FROM apm_requests WHERE request_dt >= ?) GROUP BY hit',
            [$threshold]
        );
        $totalCacheOps = array_sum(array_column($cacheData, 'count'));
        $hits = array_filter($cacheData, fn($row) => $row['hit'] == 1);
        $hitCount = $hits ? array_sum(array_column($hits, 'count')) : 0;
        $cacheHitRate = $totalCacheOps > 0 ? $hitCount / $totalCacheOps : 0;

        // Response Code Distribution Over Time - with interval based on time range
        $requestData = $this->pdoWrapper->fetchAll(
            'SELECT request_dt, response_code FROM apm_requests WHERE request_dt >= ? ORDER BY request_dt',
            [$threshold]
        );
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
            $request_dt = strtotime($row['request_dt']);
            $bucket = floor($request_dt / $interval) * $interval;
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
            $entry = ['request_dt' => date('Y-m-d H:i:s', $bucket)];
            
            // Initialize all response codes to zero for this bucket
            foreach ($allCodes as $code) {
                $entry[$code] = isset($responseCodeData[$bucket][$code]) ? $responseCodeData[$bucket][$code] : 0;
            }
            
            $responseCodeOverTime[] = $entry;
        }

        // Latency Percentiles
        $times = $this->pdoWrapper->fetchAll(
            'SELECT total_time FROM apm_requests WHERE request_dt >= ? ORDER BY total_time',
            [$threshold]
        );
        $times = array_map(fn($row) => $row['total_time'], $times);
        $p95 = $this->calculatePercentile($times, 95);
        $p99 = $this->calculatePercentile($times, 99);

		// Graph Data (aggregated) - Optimized for large datasets
		// Use appropriate SQL for time bucketing based on the database driver
		$intervalSeconds = $interval;
		$driver = $this->pdoWrapper->getAttribute(PDO::ATTR_DRIVER_NAME); // e.g., 'mysql', 'sqlite', 'pgsql'

		if ($driver === 'sqlite') {
			// SQLite: use strftime('%s', request_dt)
			$aggregatedData = $this->pdoWrapper->fetchAll(
			"SELECT 
				(strftime('%s', request_dt) / ?) * ? as time_bucket,
				AVG(total_time) as average_time,
				COUNT(*) as request_count
			FROM apm_requests 
			WHERE request_dt >= ? 
			GROUP BY time_bucket
			ORDER BY time_bucket",
			[$intervalSeconds, $intervalSeconds, $threshold]
			);
		} elseif ($driver === 'mysql') {
			// MySQL: use UNIX_TIMESTAMP(request_dt)
			$aggregatedData = $this->pdoWrapper->fetchAll(
			"SELECT 
				(UNIX_TIMESTAMP(request_dt) DIV ?) * ? as time_bucket,
				AVG(total_time) as average_time,
				COUNT(*) as request_count
			FROM apm_requests 
			WHERE request_dt >= ? 
			GROUP BY time_bucket
			ORDER BY time_bucket",
			[$intervalSeconds, $intervalSeconds, $threshold]
			);
		} elseif ($driver === 'pgsql' || $driver === 'postgresql') {
			// PostgreSQL: use EXTRACT(EPOCH FROM request_dt)
			$aggregatedData = $this->pdoWrapper->fetchAll(
			"SELECT 
				(FLOOR(EXTRACT(EPOCH FROM request_dt) / ?) * ?) as time_bucket,
				AVG(total_time) as average_time,
				COUNT(*) as request_count
			FROM apm_requests 
			WHERE request_dt >= ? 
			GROUP BY time_bucket
			ORDER BY time_bucket",
			[$intervalSeconds, $intervalSeconds, $threshold]
			);
		} else {
			// Fallback: just return empty array
			$aggregatedData = [];
		}
        
        $chartData = array_map(function($row) {
            return [
                'request_dt' => date('Y-m-d H:i:s', (int) $row['time_bucket']),
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
        
        // Enhanced custom event data filters - multiple keys and values with operators
        $eventKeys = isset($_GET['event_keys']) ? json_decode($_GET['event_keys'], true) : [];
        
        // Build main query with conditions for URL and response code
        $conditions = ['request_dt >= ?'];
        $params = [$threshold];

        // Add request ID filter (exact match)
        if (!empty($requestId)) {
            $conditions[] = 'request_token = ?';
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
            $params[] = $ip;
        }
        
        if (!empty($host)) {
            $conditions[] = 'host = ?';
            $params[] = $host;
        }
        
        if (!empty($sessionId)) {
            $conditions[] = 'session_id = ?';
            $params[] = $sessionId;
        }
        
        if (!empty($userAgent)) {
            $conditions[] = 'user_agent LIKE ?';
            $params[] = "%$userAgent%";
        }

        // Build the base query with all conditions
        $whereClause = implode(' AND ', $conditions);
        $urlResponseCodeQuery = "SELECT id FROM apm_requests WHERE $whereClause ORDER BY request_dt DESC LIMIT ?";
        $params[] = $maxRequests;

		$mainRequestIds = $this->pdoWrapper->fetchAll($urlResponseCodeQuery, $params);
		$mainRequestIds = array_map(fn($row) => $row['id'], $mainRequestIds);

        // Get request IDs from custom events if custom event type filter is set
        $customEventRequestIds = [];
        if (!empty($customEventType)) {
            // Search only in event_type
            $customEventsQuery = "SELECT request_id FROM apm_custom_events WHERE event_type LIKE ? LIMIT ?";
            $customEventRequestIds = $this->pdoWrapper->fetchAll($customEventsQuery, ["%$customEventType%", $maxRequests]);
            $customEventRequestIds = array_map(fn($row) => $row['request_id'], $customEventRequestIds);
            
            // If no matching custom events, we should return empty results
            // This ensures that when a custom event type filter is specified but no matches are found,
            // the final result set will be empty
            if (empty($customEventRequestIds) && !empty($customEventType)) {
                return [
                    'requests' => [],
                    'pagination' => [
                        'currentPage' => $page,
                        'totalPages' => 0,
                        'perPage' => $perPage,
                        'totalRequests' => 0,
                    ],
                    'responseCodeDistribution' => []
                ];
            }
        }
        
        // Get request IDs from custom event data if key/value filters are set
        $customEventDataRequestIds = [];
        if (!empty($eventKeys)) {
            // Use a simpler approach that's more compatible with SQLite
            $validFilters = array_filter($eventKeys, fn($f) => !empty($f['key']) || !empty($f['value']));
            
            if (!empty($validFilters)) {
                // Process each filter separately to build individual queries
                $matchingRequestIdSets = [];
                
                foreach ($validFilters as $filter) {
                    $key = $filter['key'] ?? '';
                    $operator = $filter['operator'] ?? 'exact';
                    $value = $filter['value'] ?? '';
                    
                    if (empty($key) && empty($value)) {
                        continue;
                    }
                    
                    // Build a simpler query for this specific filter
                    $filterParams = [];
                    $filterConditions = [];
                    
                    if (!empty($key)) {
                        $filterConditions[] = "json_key = ?";
                        $filterParams[] = $key;
                    }
                    
                    if (!empty($value)) {
                        switch ($operator) {
                            case 'exact':
                                $filterConditions[] = "json_value = ?";
                                $filterParams[] = $value;
                                break;
                            case 'contains':
                                $filterConditions[] = "json_value LIKE ?";
                                $filterParams[] = "%$value%";
                                break;
                            case 'starts_with':
                                $filterConditions[] = "json_value LIKE ?";
                                $filterParams[] = "$value%";
                                break;
                            case 'ends_with':
                                $filterConditions[] = "json_value LIKE ?";
                                $filterParams[] = "%$value";
                                break;
                            // For numeric comparisons, use simple string comparison
                            // This is more reliable in SQLite without CAST
                            case 'greater_than':
                                $filterConditions[] = "json_value > ?";
                                $filterParams[] = (string)$value;
                                break;
                            case 'less_than':
                                $filterConditions[] = "json_value < ?";
                                $filterParams[] = (string)$value;
                                break;
                            case 'greater_than_equal':
                                $filterConditions[] = "json_value >= ?";
                                $filterParams[] = (string)$value;
                                break;
                            case 'less_than_equal':
                                $filterConditions[] = "json_value <= ?";
                                $filterParams[] = (string)$value;
                                break;
                        }
                    }
                    
                    // If we have conditions for this filter
                    if (!empty($filterConditions)) {
                        $filterQuery = "SELECT DISTINCT request_id FROM apm_custom_event_data WHERE " . 
                            implode(" AND ", $filterConditions);
                            
                        try {
                                
                            $matchingIds = $this->pdoWrapper->fetchAll($filterQuery, $filterParams);
                            $matchingIds = array_map(fn($row) => $row['request_id'], $matchingIds);
                            
                            if (!empty($matchingIds)) {
                                $matchingRequestIdSets[] = $matchingIds;
                            }
                        } catch (Throwable $e) {
                            error_log("Error in filter query: " . $e->getMessage());
                        }
                    }
                }
                
                // If we have multiple filters, find intersections
                if (count($matchingRequestIdSets) > 0) {
                    // Start with the first set
                    $customEventDataRequestIds = array_shift($matchingRequestIdSets);
                    
                    // Intersect with each additional set
                    foreach ($matchingRequestIdSets as $idSet) {
                        $customEventDataRequestIds = array_intersect($customEventDataRequestIds, $idSet);
                    }
                }
            }
        }
        
        // Merge request IDs from different sources
        // This logic needs to be updated to use intersections when appropriate
        $uniqueRequestIds = [];
        
        // Determine which filters are active
        $hasCustomEventFilters = !empty($customEventType) || !empty($eventKeys);
        $hasMainFilters = !empty($url) || !empty($responseCode) || !empty($responseCodePrefix) || 
                          $isBot !== '' || !empty($minTime) || !empty($ip) || !empty($host) || 
                          !empty($sessionId) || !empty($userAgent) || !empty($requestId);

        // Logic for applying filters
        if ($hasCustomEventFilters) {
            // Start with empty set if we're filtering by custom events
            $matchingIds = [];
            
            // Apply custom event type filter if present
            if (!empty($customEventType) && !empty($customEventRequestIds)) {
                $matchingIds = $customEventRequestIds;
            }
            // Apply custom event data filter if present
            if (!empty($eventKeys) && !empty($customEventDataRequestIds)) {
                if (!empty($matchingIds)) {
                    // Intersect with existing matches
                    $matchingIds = array_intersect($matchingIds, $customEventDataRequestIds);
                } else {
                    // Use these as the starting set
                    $matchingIds = $customEventDataRequestIds;
                }
            }
            
            // Apply main filters if present
            if ($hasMainFilters) {
                if (!empty($matchingIds)) {
                    // Intersect with main request IDs
                    $uniqueRequestIds = array_intersect($matchingIds, $mainRequestIds);
                } else {
                    // This case shouldn't happen in practice
                    $uniqueRequestIds = [];
                }
            } else {
                // No main filters, use custom event matches directly
                $uniqueRequestIds = $matchingIds;
            }
        } else {
            // No custom event filters, use main request IDs directly
            $uniqueRequestIds = $mainRequestIds;
        }
        
        // Make sure we have unique IDs
        $uniqueRequestIds = array_unique($uniqueRequestIds);
		// var_dump($uniqueRequestIds);
        
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
        $requestQuery = "SELECT id, request_token, request_dt, request_url, total_time, response_code, is_bot, ip, user_agent, host, session_id FROM apm_requests 
            WHERE id IN ($placeholders) ORDER BY request_dt DESC";
    	$requests = $this->pdoWrapper->fetchAll($requestQuery, $paginatedRequestIds);

		$shouldMaskIp = $this->shouldMaskIpAddresses();
        
        // Fetch details for each request
        foreach ($requests as &$request) {
            $details = $this->getRequestDetails($request['id']);
            $request = array_merge($request->getData(), $details);

            // Mask IP address if the option is enabled
            if ($shouldMaskIp === true) {
                $request['ip'] = $this->maskIpAddress($request['ip']);
            }
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
            $filteredRequestData = $this->pdoWrapper->fetchAll(
                "SELECT request_dt, response_code FROM apm_requests WHERE request_dt >= ? ORDER BY request_dt",
                [$threshold]
            );
            
            $responseCodeData = [];
            
            foreach ($filteredRequestData as $row) {
                $request_dt = strtotime($row['request_dt']);
                $bucket = floor($request_dt / $interval) * $interval;
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
                $entry = ['request_dt' => date('Y-m-d H:i:s', $bucket)];
                
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
    public function getRequestDetails(int $requestId): array
    {
        $request = $this->pdoWrapper->fetchRow('SELECT * FROM apm_requests WHERE id = ?', [$requestId]);
        
		// Middleware
		$request['middleware'] = $this->pdoWrapper->fetchAll('SELECT middleware_name, execution_time FROM apm_middleware WHERE request_id = ?', [$requestId]);

		// Database Queries
		$request['queries'] = $this->pdoWrapper->fetchAll('SELECT query, execution_time, row_count FROM apm_db_queries WHERE request_id = ?', [$requestId]);

		// Errors
		$request['errors'] = $this->pdoWrapper->fetchAll('SELECT error_message, error_code FROM apm_errors WHERE request_id = ?', [$requestId]);

		// Cache Operations
		$request['cache'] = $this->pdoWrapper->fetchAll('SELECT cache_key, hit, execution_time FROM apm_cache WHERE request_id = ?', [$requestId]);

		// Custom Events - Use both tables for comprehensive data
		$customEvents = $this->pdoWrapper->fetchAll('SELECT id, event_dt, event_type, event_data FROM apm_custom_events WHERE request_id = ?', [$requestId]);
        
        // Process custom events data
        $events = [];
        foreach ($customEvents as $event) {
            $eventData = json_decode($event['event_data'], true);
            
            // If the custom event data table exists, retrieve the key-value pairs
            $keyValuePairs = [];
            $pairs = $this->pdoWrapper->fetchAll(
                'SELECT json_key, json_value FROM apm_custom_event_data WHERE custom_event_id = ? AND request_id = ?',
                [$event['id'], $requestId]
            );
            foreach ($pairs as $pair) {
                $keyValuePairs[$pair['json_key']] = $pair['json_value'];
            }
			
			// For each key-value pair, try to decode JSON values
			foreach ($keyValuePairs as $key => $value) {
				// Try to decode the value if it looks like JSON
				if (is_string($value) && 
					((str_starts_with($value, '{') && str_ends_with($value, '}')) || 
						(str_starts_with($value, '[') && str_ends_with($value, ']')))) {
					try {
						$decodedValue = json_decode($value, true);
						if (json_last_error() === JSON_ERROR_NONE) {
							$keyValuePairs[$key] = $decodedValue;
						}
					} catch (\Exception $e) {
						// Keep the original value if decoding fails
					}
				}
			}
			
			// Use key-value pairs if available, otherwise fall back to the JSON data
			$eventData = !empty($keyValuePairs) ? $keyValuePairs : $eventData;
            
            $events[] = [
                'event_dt' => $event['event_dt'],
                'type' => $event['event_type'],
                'data' => $eventData
            ];
        }
        
        $request['custom_events'] = $events;
        
        // Mask IP address if the option is enabled
        if ($this->shouldMaskIpAddresses() && isset($request['ip'])) {
            $request['ip'] = $this->maskIpAddress($request['ip']);
        }

        return $request->getData();
    }

	/**
     * Get available event keys for search filters
     * 
     * @param string $threshold Timestamp threshold
     * @return array List of unique event keys
     */
    public function getEventKeys(string $threshold): array
    {
        try {
            $rows = $this->pdoWrapper->fetchAll(
                "SELECT DISTINCT json_key FROM apm_custom_event_data WHERE request_id IN (SELECT id FROM apm_requests WHERE event_dt >= ?) ORDER BY json_key",
                [$threshold]
            );
            return array_map(fn($row) => $row['json_key'], $rows);
        } catch (\Exception $e) {
            return [];
        }
    }
}
