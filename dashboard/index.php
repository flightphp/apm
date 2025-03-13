<?php

// since this is a composer package, we need to find the correct autoload.php file
// from some various locations
$paths = [
	__DIR__.'/../autoload.php',
	__DIR__.'/../../autoload.php',
	__DIR__.'/../../../autoload.php',
];
$finalPath = '';
foreach ($paths as $path) {
	if (file_exists($path) === true) {
		$finalPath = $path;
		require $path;
		break;
	}
}
if (empty($finalPath)) {
	throw new Exception('Could not find autoload.php');
}

$appRootPath = dirname($finalPath).'/../';


$app = Flight::app();
$app->set('flight.views.path', __DIR__.'/views');
$runway_config = json_decode(file_get_contents($appRootPath.'/.runway-config.json'), true);

$app->register('db', 'PDO', [$runway_config['apm']['dest_db_dsn'], null, null, [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]]);

function calculateThreshold($range) {
    $map = [
        'last_hour' => '-1 hour',
        'last_day' => '-1 day',
        'last_week' => '-1 week',
    ];
    return gmdate('Y-m-d H:i:s', strtotime($map[$range] ?? '-1 hour'));
}

function calculatePercentile($data, $percentile) {
    if (empty($data)) return 0;
    sort($data);
    $index = ($percentile / 100) * (count($data) - 1);
    $lower = floor($index);
    $upper = ceil($index);
    if ($lower == $upper) return $data[$lower];
    $fraction = $index - $lower;
    return $data[$lower] + $fraction * ($data[$upper] - $data[$lower]);
}

$app->route('GET /', function() use ($app) {
    $app->render('dashboard');
});

$app->route('GET /apm/data/dashboard', function() use ($app) {
    $range = Flight::request()->query['range'] ?? 'last_hour';
    $page = max(1, (int) (Flight::request()->query['page'] ?? 1)); // Default to page 1
    $perPage = 50; // Number of requests per page
    $search = Flight::request()->query['search'] ?? ''; // Search term
    $offset = ($page - 1) * $perPage;
    $threshold = calculateThreshold($range);
    $db = $app->db();

    // Slowest Requests
    $stmt = $db->prepare('SELECT request_id, request_url, total_time FROM apm_requests WHERE timestamp >= ? ORDER BY total_time DESC LIMIT 5');
    $stmt->execute([$threshold]);
    $slowRequests = $stmt->fetchAll();

    // Slowest Routes
    $stmt = $db->prepare('SELECT route_pattern, AVG(execution_time) as avg_time FROM apm_routes WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?) GROUP BY route_pattern ORDER BY avg_time DESC LIMIT 5');
    $stmt->execute([$threshold]);
    $slowRoutes = $stmt->fetchAll();

    // Error Rate
    $stmt = $db->prepare('SELECT COUNT(DISTINCT request_id) FROM apm_errors WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?)');
    $stmt->execute([$threshold]);
    $errorCount = $stmt->fetchColumn();
    $stmt = $db->prepare('SELECT COUNT(*) FROM apm_requests WHERE timestamp >= ?');
    $stmt->execute([$threshold]);
    $totalRequests = $stmt->fetchColumn();
    $errorRate = $totalRequests > 0 ? $errorCount / $totalRequests : 0;

    // Long Queries
    $stmt = $db->prepare('SELECT query, execution_time FROM apm_db_queries WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?) ORDER BY execution_time DESC LIMIT 5');
    $stmt->execute([$threshold]);
    $longQueries = $stmt->fetchAll();

    // Slowest Middleware
    $stmt = $db->prepare('SELECT middleware_name, AVG(execution_time) as execution_time FROM apm_middleware WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?) GROUP BY middleware_name ORDER BY execution_time DESC LIMIT 5');
    $stmt->execute([$threshold]);
    $slowMiddleware = $stmt->fetchAll();

    // Cache Hit/Miss Rate
    $stmt = $db->prepare('SELECT hit, COUNT(*) as count FROM apm_cache WHERE request_id IN (SELECT request_id FROM apm_requests WHERE timestamp >= ?) GROUP BY hit');
    $stmt->execute([$threshold]);
    $cacheData = $stmt->fetchAll();
    $totalCacheOps = array_sum(array_column($cacheData, 'count'));
    $hits = array_filter($cacheData, fn($row) => $row['hit'] == 1);
    $hitCount = $hits ? $hits[0]['count'] : 0;
    $cacheHitRate = $totalCacheOps > 0 ? $hitCount / $totalCacheOps : 0;

    // Response Code Distribution Over Time
	$stmt = $db->prepare('SELECT timestamp, response_code FROM apm_requests WHERE timestamp >= ? ORDER BY timestamp');
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

	// Count total requests for pagination (with search)
    $countQuery = 'SELECT COUNT(*) FROM apm_requests WHERE timestamp >= ?';
    $countParams = [$threshold];
    if ($search) {
        $countQuery .= ' AND (request_url LIKE ? OR response_code LIKE ?)';
        $countParams[] = "%$search%";
        $countParams[] = "%$search%";
    }
    $stmt = $db->prepare($countQuery);
    $stmt->execute($countParams);
    $totalRequests = $stmt->fetchColumn();
    $totalPages = max(1, ceil($totalRequests / $perPage));

    // Paginated Requests with Search
    $query = 'SELECT request_id, timestamp, request_url, total_time, response_code FROM apm_requests WHERE timestamp >= ?';
    $params = [$threshold];
    if ($search) {
        $query .= ' AND (request_url LIKE ? OR response_code LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $query .= ' ORDER BY timestamp DESC LIMIT ? OFFSET ?';
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($requests as &$request) {
        $requestId = $request['request_id'];

        // Middleware
        $stmt = $db->prepare('SELECT middleware_name, execution_time FROM apm_middleware WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $request['middleware'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Database Queries
        $stmt = $db->prepare('SELECT query, execution_time, row_count FROM apm_db_queries WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $request['queries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Errors
        $stmt = $db->prepare('SELECT error_message, error_code FROM apm_errors WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $request['errors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache Operations
        $stmt = $db->prepare('SELECT cache_key, cache_operation, hit, execution_time FROM apm_cache WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $request['cache'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($request);

    // Latency Percentiles
    $stmt = $db->prepare('SELECT total_time FROM apm_requests WHERE timestamp >= ? ORDER BY total_time');
    $stmt->execute([$threshold]);
    $times = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $p95 = calculatePercentile($times, 95);
    $p99 = calculatePercentile($times, 99);

    // Graph Data (aggregated)
    $stmt = $db->prepare('SELECT timestamp, total_time FROM apm_requests WHERE timestamp >= ? ORDER BY timestamp');
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

    $app->json([
        'slowRequests' => $slowRequests,
        'slowRoutes' => $slowRoutes,
        'errorRate' => $errorRate,
        'longQueries' => $longQueries,
        'slowMiddleware' => $slowMiddleware,
        'cacheHitRate' => $cacheHitRate,
        'responseCodeOverTime' => $responseCodeOverTime,
		'requests' => $requests,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
            'totalRequests' => $totalRequests,
        ],
        'p95' => $p95,
        'p99' => $p99,
        'chartData' => $chartData,
    ]);
});

$app->route('GET /apm/slow-requests', function() use ($app) {
    $range = $app->request()->query['range'] ?? 'last_hour';
    $threshold = calculateThreshold($range);
    $db = $app->db();
    $stmt = $db->prepare('SELECT request_id, request_url, total_time FROM apm_requests WHERE timestamp >= ? ORDER BY total_time DESC LIMIT 100');
    $stmt->execute([$threshold]);
    $requests = $stmt->fetchAll();
    $app->render('slow_requests', ['requests' => $requests, 'range' => $range]);
});

$app->start();
