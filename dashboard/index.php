<?php

require __DIR__.'/../vendor/autoload.php';

$app = Flight::app();
$app->set('flight.views.path', __DIR__.'/views');
$runway_config = json_decode(file_get_contents(__DIR__.'/../.runway-config.json'), true);

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

$app->route('GET /apm/data/dashboard', function() use  ($app) {
    $range = $app->request()->query['range'] ?? 'last_hour';
	var_dump($range);
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
