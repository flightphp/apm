<?php

// since this is a composer package, we need to find the correct autoload.php file
// from some various locations

use flight\apm\presenter\PresenterFactory;

$paths = [
	__DIR__.'/../vendor/autoload.php',
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

$presenter = PresenterFactory::create($appRootPath.'/.runway-config.json');

function calculateThreshold($range) {
    $map = [
        'last_hour' => '-1 hour',
        'last_day' => '-1 day',
        'last_week' => '-1 week',
    ];
    return gmdate('Y-m-d H:i:s', strtotime($map[$range] ?? '-1 hour'));
}

$app->route('GET /apm/dashboard', function() use ($app) {
    $app->render('dashboard');
});

// Modified to only return widget data (no requests or pagination)
$app->route('GET /apm/data/dashboard', function() use ($app, $presenter) {
    $range = Flight::request()->query['range'] ?? 'last_hour';
    $threshold = calculateThreshold($range);
	$data = $presenter->getDashboardData($threshold);
    $app->json($data);
});

// New endpoint specifically for request log data with enhanced custom events search
$app->route('GET /apm/data/requests', function() use ($app, $presenter) {
    $range = Flight::request()->query['range'] ?? 'last_hour';
	$threshold = calculateThreshold($range);
    $page = max(1, (int) (Flight::request()->query['page'] ?? 1));
    $perPage = 50;
    $search = Flight::request()->query['search'] ?? '';
	$data = $presenter->getRequestsData($threshold, $page, $perPage, $search);
	$app->json($data);
});

// New endpoint to retrieve available event keys for filtering
$app->route('GET /apm/data/event-keys', function() use ($app, $presenter) {
    $range = Flight::request()->query['range'] ?? 'last_hour';
    $threshold = calculateThreshold($range);
    $eventKeys = $presenter->getEventKeys($threshold);
    $app->json(['event_keys' => $eventKeys]);
});

$app->start();
