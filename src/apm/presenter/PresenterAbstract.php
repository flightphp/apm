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
     *
     * @param array<string,mixed> $filters Optional dashboard filters. Keys match the query string.
     */
    public function getDashboardData(string $threshold, string $range = 'last_hour', array $filters = []): array
    {
        [$where, $params] = $this->requestConditions('r', $threshold, $filters);

        $slowRequests = $this->pdoWrapper->fetchAll(
            "SELECT r.id, r.request_token, r.request_method, r.request_url, r.total_time, r.response_code
            FROM apm_requests r
            WHERE {$where}
            ORDER BY r.total_time DESC
            LIMIT 5",
            $params
        );

        $slowRoutes = $this->pdoWrapper->fetchAll(
            "SELECT route_pattern, AVG(apm_routes.execution_time) AS avg_time, COUNT(*) AS hit_count
            FROM apm_routes
            INNER JOIN apm_requests r ON r.id = apm_routes.request_id
            WHERE {$where}
            GROUP BY route_pattern
            ORDER BY avg_time DESC
            LIMIT 5",
            $params
        );

        $errorCount = (int) $this->pdoWrapper->fetchField(
            "SELECT COUNT(DISTINCT apm_errors.request_id)
            FROM apm_errors
            INNER JOIN apm_requests r ON r.id = apm_errors.request_id
            WHERE {$where}",
            $params
        );
        $totals = $this->pdoWrapper->fetchRow(
            "SELECT COUNT(*) AS request_count, AVG(r.total_time) AS average_time
            FROM apm_requests r
            WHERE {$where}",
            $params
        );
        $totalRequests = (int) ($totals['request_count'] ?? 0);
        $averageLatency = (float) ($totals['average_time'] ?? 0);
        $errorRate = $totalRequests > 0 ? $errorCount / $totalRequests : 0;

        $queryGroups = $this->pdoWrapper->fetchAll(
            "SELECT apm_db_queries.query AS query,
                AVG(apm_db_queries.execution_time) AS avg_time,
                MAX(apm_db_queries.execution_time) AS max_time,
                COUNT(*) AS hit_count
            FROM apm_db_queries
            INNER JOIN apm_requests r ON r.id = apm_db_queries.request_id
            WHERE {$where}
            GROUP BY apm_db_queries.query
            ORDER BY avg_time DESC
            LIMIT 200",
            $params
        );
        $longQueries = $this->groupQueries($queryGroups);

        $slowMiddleware = $this->pdoWrapper->fetchAll(
            "SELECT middleware_name, AVG(apm_middleware.execution_time) AS execution_time, COUNT(*) AS hit_count
            FROM apm_middleware
            INNER JOIN apm_requests r ON r.id = apm_middleware.request_id
            WHERE {$where}
            GROUP BY middleware_name
            ORDER BY execution_time DESC
            LIMIT 5",
            $params
        );

        $cacheData = $this->pdoWrapper->fetchAll(
            "SELECT apm_cache.hit AS hit, COUNT(*) AS count
            FROM apm_cache
            INNER JOIN apm_requests r ON r.id = apm_cache.request_id
            WHERE {$where}
            GROUP BY apm_cache.hit",
            $params
        );
        $totalCacheOps = array_sum(array_column($this->rowsToArrays($cacheData), 'count'));
        $hits = array_filter($this->rowsToArrays($cacheData), function ($row) {
            return (int) $row['hit'] === 1;
        });
        $hitCount = $hits ? array_sum(array_column($hits, 'count')) : 0;
        $cacheHitRate = $totalCacheOps > 0 ? $hitCount / $totalCacheOps : 0;

        $errorGroups = $this->pdoWrapper->fetchAll(
            "SELECT apm_errors.error_message AS error_message,
                apm_errors.error_code AS error_code,
                COUNT(*) AS hit_count
            FROM apm_errors
            INNER JOIN apm_requests r ON r.id = apm_errors.request_id
            WHERE {$where}
            GROUP BY apm_errors.error_message, apm_errors.error_code
            ORDER BY hit_count DESC
            LIMIT 8",
            $params
        );

        $interval = $this->intervalSeconds($range, $threshold, $filters);
        $chart = $this->buildChart($where, $params, $threshold, $filters, $interval);
        $p95 = $this->percentileValue($where, $params, $totalRequests, 95);
        $p99 = $this->percentileValue($where, $params, $totalRequests, 99);
        $throughput = $this->throughputPerMinute($threshold, $filters, $totalRequests);

        return [
            'slowRequests' => $slowRequests,
            'slowRoutes' => $slowRoutes,
            'errorRate' => $errorRate,
            'longQueries' => $longQueries,
            'slowMiddleware' => $slowMiddleware,
            'cacheHitRate' => $cacheHitRate,
            'responseCodeOverTime' => $chart['exact'],
            'statusClassesOverTime' => $chart['classes'],
            'p95' => $p95,
            'p99' => $p99,
            'chartData' => $chart['latency'],
            'allRequestsCount' => $totalRequests,
            'averageLatency' => $averageLatency,
            'throughputPerMinute' => $throughput,
            'errorGroups' => $errorGroups,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @param bool $includeDetails When false, skip the per-request detail queries.
     * @param array<string,mixed>|null $filters Null reads the current query string, which preserves older callers.
     */
    public function getRequestsData(
        string $threshold,
        int $page,
        int $perPage,
        string $search = '',
        bool $includeDetails = true,
        ?array $filters = null
    ): array {
        $filters = $filters ?? $this->filtersFromGlobals();
        if ($search !== '' && empty($filters['url'])) {
            $filters['url'] = $search;
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;
        [$where, $params] = $this->requestConditions('r', $threshold, $filters);

        $totalRequests = (int) $this->pdoWrapper->fetchField(
            "SELECT COUNT(*) FROM apm_requests r WHERE {$where}",
            $params
        );
        $totalPages = $totalRequests > 0 ? (int) ceil($totalRequests / $perPage) : 0;

        $empty = [
            'requests' => [],
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'perPage' => $perPage,
                'totalRequests' => $totalRequests,
            ],
            'responseCodeDistribution' => [],
        ];
        if ($totalRequests === 0 || $offset >= $totalRequests) {
            $empty['responseCodeDistribution'] = $this->buildChart(
                $where,
                $params,
                $threshold,
                $filters,
                $this->intervalSeconds((string) ($filters['range'] ?? 'last_hour'), $threshold, $filters)
            )['exact'];
            return $empty;
        }

        $limit = (int) $perPage;
        $offset = (int) $offset;
        $requests = $this->pdoWrapper->fetchAll(
            "SELECT r.id, r.request_token, r.request_dt, r.request_method, r.request_url, r.total_time,
                r.response_code, r.is_bot, r.ip, r.user_agent, r.host, r.session_id, r.peak_memory, r.response_size
            FROM apm_requests r
            WHERE {$where}
            ORDER BY r.request_dt DESC
            LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        $shouldMaskIp = $this->shouldMaskIpAddresses();
        $rows = [];
        foreach ($requests as $request) {
            $row = $request->getData();
            if ($includeDetails) {
                $row = array_merge($row, $this->getRequestDetails((int) $row['id']));
            }
            if ($shouldMaskIp === true && !empty($row['ip'])) {
                $row['ip'] = $this->maskIpAddress((string) $row['ip']);
            }
            $rows[] = $row;
        }

        $range = (string) ($filters['range'] ?? 'last_hour');
        $distribution = $this->buildChart(
            $where,
            $params,
            $threshold,
            $filters,
            $this->intervalSeconds($range, $threshold, $filters)
        )['exact'];

        return [
            'requests' => $rows,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => max(1, $totalPages),
                'perPage' => $perPage,
                'totalRequests' => $totalRequests,
            ],
            'responseCodeDistribution' => $distribution,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestDetails(int $requestId): array
    {
        $request = $this->pdoWrapper->fetchRow('SELECT * FROM apm_requests WHERE id = ?', [$requestId]);
        $data = $request->getData();

        $data['routes'] = $this->pdoWrapper->fetchAll(
            'SELECT route_pattern, execution_time, memory_used FROM apm_routes WHERE request_id = ?',
            [$requestId]
        );
        $data['middleware'] = $this->pdoWrapper->fetchAll(
            'SELECT middleware_name, execution_time FROM apm_middleware WHERE request_id = ?',
            [$requestId]
        );
        $data['views'] = $this->pdoWrapper->fetchAll(
            'SELECT view_file, render_time FROM apm_views WHERE request_id = ?',
            [$requestId]
        );
        $data['db_connections'] = $this->pdoWrapper->fetchAll(
            'SELECT engine, host, database_name FROM apm_db_connections WHERE request_id = ?',
            [$requestId]
        );
        $data['queries'] = $this->pdoWrapper->fetchAll(
            'SELECT query, params, execution_time, row_count, memory_usage FROM apm_db_queries WHERE request_id = ?',
            [$requestId]
        );
        $data['errors'] = $this->pdoWrapper->fetchAll(
            'SELECT error_message, error_code, error_trace FROM apm_errors WHERE request_id = ?',
            [$requestId]
        );
        $data['cache'] = $this->pdoWrapper->fetchAll(
            'SELECT cache_key, hit, execution_time FROM apm_cache WHERE request_id = ?',
            [$requestId]
        );

        $customEvents = $this->pdoWrapper->fetchAll(
            'SELECT id, event_dt, event_type, event_data FROM apm_custom_events WHERE request_id = ?',
            [$requestId]
        );

        $events = [];
        foreach ($customEvents as $event) {
            $eventData = json_decode((string) $event['event_data'], true);
            $keyValuePairs = [];
            $pairs = $this->pdoWrapper->fetchAll(
                'SELECT json_key, json_value FROM apm_custom_event_data WHERE custom_event_id = ? AND request_id = ?',
                [$event['id'], $requestId]
            );
            foreach ($pairs as $pair) {
                $keyValuePairs[$pair['json_key']] = $pair['json_value'];
            }
            foreach ($keyValuePairs as $key => $value) {
                if (is_string($value) && $this->looksLikeJson($value)) {
                    $decodedValue = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $keyValuePairs[$key] = $decodedValue;
                    }
                }
            }
            $eventData = !empty($keyValuePairs) ? $keyValuePairs : $eventData;
            $events[] = [
                'event_dt' => $event['event_dt'],
                'type' => $event['event_type'],
                'data' => $eventData,
            ];
        }
        $data['custom_events'] = $events;

        if ($this->shouldMaskIpAddresses() && !empty($data['ip'])) {
            $data['ip'] = $this->maskIpAddress((string) $data['ip']);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventKeys(string $threshold): array
    {
        try {
            $rows = $this->pdoWrapper->fetchAll(
                'SELECT DISTINCT apm_custom_event_data.json_key AS json_key
                FROM apm_custom_event_data
                INNER JOIN apm_requests ON apm_requests.id = apm_custom_event_data.request_id
                WHERE apm_requests.request_dt >= ?
                ORDER BY json_key',
                [$threshold]
            );
            return array_map(function ($row) {
                return $row['json_key'];
            }, $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function filtersFromQuery(array $query): array
    {
        $eventKeys = [];
        if (!empty($query['event_keys'])) {
            $decoded = json_decode((string) $query['event_keys'], true);
            if (is_array($decoded)) {
                $eventKeys = $decoded;
            }
        }

        return [
            'until' => (string) ($query['until'] ?? ''),
            'exclude_bots' => (string) ($query['exclude_bots'] ?? '') === '1',
            'route_pattern' => (string) ($query['route'] ?? ''),
            'url' => (string) ($query['url'] ?? ''),
            'response_code' => (string) ($query['response_code'] ?? ''),
            'response_code_prefix' => (string) ($query['response_code_prefix'] ?? ''),
            'is_bot' => (string) ($query['is_bot'] ?? ''),
            'custom_event_type' => (string) ($query['custom_event_type'] ?? ''),
            'min_time' => (string) ($query['min_time'] ?? ''),
            'request_id' => (string) ($query['request_id'] ?? ''),
            'ip' => (string) ($query['ip'] ?? ''),
            'host' => (string) ($query['host'] ?? ''),
            'session_id' => (string) ($query['session_id'] ?? ''),
            'user_agent' => (string) ($query['user_agent'] ?? ''),
            'error_message' => (string) ($query['error_message'] ?? ''),
            'range' => (string) ($query['range'] ?? 'last_hour'),
            'event_keys' => $eventKeys,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function filtersFromGlobals(): array
    {
        return $this->filtersFromQuery($_GET);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    protected function requestConditions(string $alias, string $threshold, array $filters): array
    {
        $where = ["{$alias}.request_dt >= ?"];
        $params = [$threshold];

        if (!empty($filters['until'])) {
            $where[] = "{$alias}.request_dt < ?";
            $params[] = $filters['until'];
        }
        if (!empty($filters['exclude_bots'])) {
            $where[] = "{$alias}.is_bot = 0";
        }
        if (!empty($filters['request_id'])) {
            $where[] = "{$alias}.request_token = ?";
            $params[] = $filters['request_id'];
        }
        if (!empty($filters['url'])) {
            $where[] = "{$alias}.request_url LIKE ? ESCAPE '\\'";
            $params[] = '%' . $this->escapeLike((string) $filters['url']) . '%';
        }
        if (!empty($filters['response_code'])) {
            $where[] = "{$alias}.response_code = ?";
            $params[] = $filters['response_code'];
        } elseif (!empty($filters['response_code_prefix'])) {
            $where[] = "{$alias}.response_code LIKE ?";
            $params[] = $filters['response_code_prefix'] . '%';
        }
        if (isset($filters['is_bot']) && ($filters['is_bot'] === '0' || $filters['is_bot'] === '1')) {
            $where[] = "{$alias}.is_bot = ?";
            $params[] = $filters['is_bot'];
        }
        if (!empty($filters['min_time']) && is_numeric($filters['min_time'])) {
            $where[] = "{$alias}.total_time >= ?";
            $params[] = ((float) $filters['min_time']) / 1000;
        }
        if (!empty($filters['ip'])) {
            $where[] = "{$alias}.ip = ?";
            $params[] = $filters['ip'];
        }
        if (!empty($filters['host'])) {
            $where[] = "{$alias}.host = ?";
            $params[] = $filters['host'];
        }
        if (!empty($filters['session_id'])) {
            $where[] = "{$alias}.session_id = ?";
            $params[] = $filters['session_id'];
        }
        if (!empty($filters['user_agent'])) {
            $where[] = "{$alias}.user_agent LIKE ? ESCAPE '\\'";
            $params[] = '%' . $this->escapeLike((string) $filters['user_agent']) . '%';
        }
        if (!empty($filters['route_pattern'])) {
            $where[] = "EXISTS (SELECT 1 FROM apm_routes route_filter
                WHERE route_filter.request_id = {$alias}.id AND route_filter.route_pattern = ?)";
            $params[] = $filters['route_pattern'];
        }
        if (!empty($filters['custom_event_type'])) {
            $where[] = "EXISTS (SELECT 1 FROM apm_custom_events event_filter
                WHERE event_filter.request_id = {$alias}.id AND event_filter.event_type LIKE ? ESCAPE '\\')";
            $params[] = '%' . $this->escapeLike((string) $filters['custom_event_type']) . '%';
        }
        if (!empty($filters['error_message'])) {
            $where[] = "EXISTS (SELECT 1 FROM apm_errors error_filter
                WHERE error_filter.request_id = {$alias}.id AND error_filter.error_message = ?)";
            $params[] = $filters['error_message'];
        }

        foreach ($filters['event_keys'] ?? [] as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $key = (string) ($filter['key'] ?? '');
            $operator = (string) ($filter['operator'] ?? 'exact');
            $value = (string) ($filter['value'] ?? '');
            if ($key === '' && $value === '') {
                continue;
            }
            $clauses = [];
            if ($key !== '') {
                $clauses[] = 'data_filter.json_key = ?';
                $params[] = $key;
            }
            if ($value !== '') {
                switch ($operator) {
                    case 'contains':
                        $clauses[] = "data_filter.json_value LIKE ? ESCAPE '\\'";
                        $params[] = '%' . $this->escapeLike($value) . '%';
                        break;
                    case 'starts_with':
                        $clauses[] = "data_filter.json_value LIKE ? ESCAPE '\\'";
                        $params[] = $this->escapeLike($value) . '%';
                        break;
                    case 'ends_with':
                        $clauses[] = "data_filter.json_value LIKE ? ESCAPE '\\'";
                        $params[] = '%' . $this->escapeLike($value);
                        break;
                    case 'greater_than':
                        $clauses[] = 'data_filter.json_value > ?';
                        $params[] = $value;
                        break;
                    case 'less_than':
                        $clauses[] = 'data_filter.json_value < ?';
                        $params[] = $value;
                        break;
                    case 'greater_than_equal':
                        $clauses[] = 'data_filter.json_value >= ?';
                        $params[] = $value;
                        break;
                    case 'less_than_equal':
                        $clauses[] = 'data_filter.json_value <= ?';
                        $params[] = $value;
                        break;
                    case 'exact':
                    default:
                        $clauses[] = 'data_filter.json_value = ?';
                        $params[] = $value;
                        break;
                }
            }
            if ($clauses !== []) {
                $where[] = 'EXISTS (SELECT 1 FROM apm_custom_event_data data_filter
                    WHERE data_filter.request_id = ' . $alias . '.id AND ' . implode(' AND ', $clauses) . ')';
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string,mixed> $filters
     */
    protected function intervalSeconds(string $range, string $threshold, array $filters): int
    {
        if ($range === 'last_day') {
            return 900;
        }
        if ($range === 'last_week') {
            return 21600;
        }
        if ($range !== 'custom') {
            return 300;
        }
        $start = strtotime($threshold . ' UTC') ?: time();
        $end = !empty($filters['until']) ? (strtotime((string) $filters['until'] . ' UTC') ?: time()) : time();
        $raw = (int) ceil(max(60, $end - $start) / 40);
        foreach ([60, 300, 900, 3600, 21600, 86400] as $step) {
            if ($raw <= $step) {
                return $step;
            }
        }
        return 86400;
    }

    /**
     * @param array<int,mixed> $params
     * @param array<string,mixed> $filters
     * @return array{latency:array<int,array<string,mixed>>,classes:array<int,array<string,mixed>>,exact:array<int,array<string,mixed>>}
     */
    protected function buildChart(string $where, array $params, string $threshold, array $filters, int $interval): array
    {
        $bucket = $this->bucketSql();
        $bucketParams = array_merge([$interval, $interval], $params);
        $classRows = $this->pdoWrapper->fetchAll(
            "SELECT {$bucket} AS time_bucket,
                AVG(r.total_time) AS average_time,
                COUNT(*) AS request_count,
                SUM(CASE WHEN r.response_code >= 200 AND r.response_code < 300 THEN 1 ELSE 0 END) AS code_2xx,
                SUM(CASE WHEN r.response_code >= 300 AND r.response_code < 400 THEN 1 ELSE 0 END) AS code_3xx,
                SUM(CASE WHEN r.response_code >= 400 AND r.response_code < 500 THEN 1 ELSE 0 END) AS code_4xx,
                SUM(CASE WHEN r.response_code >= 500 AND r.response_code < 600 THEN 1 ELSE 0 END) AS code_5xx
            FROM apm_requests r
            WHERE {$where}
            GROUP BY time_bucket
            ORDER BY time_bucket",
            $bucketParams
        );
        $exactRows = $this->pdoWrapper->fetchAll(
            "SELECT {$bucket} AS time_bucket, r.response_code AS response_code, COUNT(*) AS request_count
            FROM apm_requests r
            WHERE {$where}
            GROUP BY time_bucket, r.response_code
            ORDER BY time_bucket",
            $bucketParams
        );

        $byBucket = [];
        foreach ($classRows as $row) {
            $byBucket[(int) $row['time_bucket']] = $row;
        }
        $exactByBucket = [];
        $codes = [];
        foreach ($exactRows as $row) {
            $key = (int) $row['time_bucket'];
            $code = (string) $row['response_code'];
            $exactByBucket[$key][$code] = (int) $row['request_count'];
            $codes[$code] = true;
        }

        $start = strtotime($threshold . ' UTC') ?: time();
        $end = !empty($filters['until']) ? (strtotime((string) $filters['until'] . ' UTC') ?: time()) : time();
        $start = $start - ($start % $interval);
        $latency = [];
        $classes = [];
        $exact = [];
        for ($cursor = $start; $cursor <= $end; $cursor += $interval) {
            $label = gmdate('Y-m-d H:i:s', $cursor);
            $row = $byBucket[$cursor] ?? null;
            $latency[] = [
                'request_dt' => $label,
                'average_time' => $row ? (float) $row['average_time'] : 0,
                'request_count' => $row ? (int) $row['request_count'] : 0,
            ];
            $classes[] = [
                'request_dt' => $label,
                'code_2xx' => $row ? (int) $row['code_2xx'] : 0,
                'code_3xx' => $row ? (int) $row['code_3xx'] : 0,
                'code_4xx' => $row ? (int) $row['code_4xx'] : 0,
                'code_5xx' => $row ? (int) $row['code_5xx'] : 0,
            ];
            $entry = ['request_dt' => $label];
            foreach (array_keys($codes) as $code) {
                $entry[$code] = $exactByBucket[$cursor][$code] ?? 0;
            }
            $exact[] = $entry;
        }

        return ['latency' => $latency, 'classes' => $classes, 'exact' => $exact];
    }

    protected function bucketSql(): string
    {
        $driver = (string) $this->pdoWrapper->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            return '((UNIX_TIMESTAMP(r.request_dt) - TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())) DIV ?) * ?';
        }
        if ($driver === 'pgsql' || $driver === 'postgresql') {
            return '(CAST(FLOOR(EXTRACT(EPOCH FROM r.request_dt) / ?) AS INTEGER) * ?)';
        }
        return "(CAST(strftime('%s', r.request_dt) / ? AS INTEGER) * ?)";
    }

    /**
     * Nearest-rank percentile. OFFSET is inlined because SQLite will not bind LIMIT.
     *
     * @param array<int,mixed> $params
     */
    protected function percentileValue(string $where, array $params, int $count, int $percentile): float
    {
        if ($count < 1) {
            return 0.0;
        }
        $offset = (int) max(0, min($count - 1, (int) ceil($percentile / 100 * $count) - 1));
        $value = $this->pdoWrapper->fetchField(
            "SELECT r.total_time FROM apm_requests r WHERE {$where} ORDER BY r.total_time ASC LIMIT 1 OFFSET {$offset}",
            $params
        );
        return $value === false || $value === null ? 0.0 : (float) $value;
    }

    /**
     * @param array<string,mixed> $filters
     */
    protected function throughputPerMinute(string $threshold, array $filters, int $count): float
    {
        $start = strtotime($threshold . ' UTC') ?: time();
        $end = !empty($filters['until']) ? (strtotime((string) $filters['until'] . ' UTC') ?: time()) : time();
        $seconds = max(1, $end - $start);
        return ($count / $seconds) * 60;
    }

    /**
     * Collapse literal values so five copies of one statement become one row.
     *
     * @param array<int,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    protected function groupQueries(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $raw = (string) $row['query'];
            $normalized = $this->normalizeSql($raw);
            if (!isset($groups[$normalized])) {
                $groups[$normalized] = [
                    'query' => $raw,
                    'normalized' => $normalized,
                    'total_time' => 0.0,
                    'max_time' => 0.0,
                    'hit_count' => 0,
                ];
            }
            $hits = (int) $row['hit_count'];
            $groups[$normalized]['total_time'] += (float) $row['avg_time'] * $hits;
            $groups[$normalized]['hit_count'] += $hits;
            $groups[$normalized]['max_time'] = max($groups[$normalized]['max_time'], (float) $row['max_time']);
            if ((float) $row['max_time'] >= $groups[$normalized]['max_time']) {
                $groups[$normalized]['query'] = $raw;
            }
        }
        $result = [];
        foreach ($groups as $group) {
            $avg = $group['hit_count'] > 0 ? $group['total_time'] / $group['hit_count'] : 0.0;
            $result[] = [
                'query' => $group['query'],
                'normalized' => $group['normalized'],
                'execution_time' => $avg,
                'avg_time' => $avg,
                'max_time' => $group['max_time'],
                'hit_count' => $group['hit_count'],
            ];
        }
        usort($result, function ($a, $b) {
            return $b['avg_time'] <=> $a['avg_time'];
        });
        return array_slice($result, 0, 5);
    }

    protected function normalizeSql(string $sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"]|"")*"/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        return trim($sql);
    }

    protected function looksLikeJson(string $value): bool
    {
        $length = strlen($value);
        if ($length < 2) {
            return false;
        }
        $first = $value[0];
        $last = $value[$length - 1];
        return ($first === '{' && $last === '}') || ($first === '[' && $last === ']');
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    protected function rowsToArrays(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = is_object($row) && method_exists($row, 'getData') ? $row->getData() : (array) $row;
        }
        return $out;
    }
}
