<?php

// Built-in server router: real files (css, js) are served as-is.
if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = __DIR__ . $requestPath;
    if ($requestPath !== '/' && is_file($file)) {
        return false;
    }
}

use flight\apm\presenter\PresenterFactory;

$paths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../autoload.php',
    __DIR__ . '/../../autoload.php',
    __DIR__ . '/../../../autoload.php',
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
$appRootPath = dirname($finalPath) . '/../';

$app = Flight::app();

$runwayConfig = [];
if (file_exists($appRootPath . '/app/config/config.php') === true) {
    $config = require($appRootPath . '/app/config/config.php');
    $runwayConfig = $config['runway'] ?? [];
}

// This needs to come after the config is loaded so it's forced
$app->set('flight.views.path', __DIR__ . '/views');

if (empty($runwayConfig) && file_exists($appRootPath . '/.runway-config.json') === true) {
    $runwayConfig = json_decode(file_get_contents($appRootPath . '/.runway-config.json'), true);
}

$auth = $runwayConfig['apm']['dashboard_auth'] ?? null;
if (is_array($auth) && !empty($auth['user']) && isset($auth['password']) && $auth['password'] !== '') {
    $givenUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $givenPass = $_SERVER['PHP_AUTH_PW'] ?? '';
    $userOk = hash_equals((string) $auth['user'], (string) $givenUser);
    $passOk = hash_equals((string) $auth['password'], (string) $givenPass);
    if ($userOk === false || $passOk === false) {
        header('WWW-Authenticate: Basic realm="APM Dashboard"');
        header('HTTP/1.1 401 Unauthorized');
        echo 'Authentication required';
        return;
    }
}

$presenter = PresenterFactory::create($runwayConfig);

function calculateThreshold($range)
{
    $map = [
        'last_hour' => '-1 hour',
        'last_day' => '-1 day',
        'last_week' => '-1 week',
    ];
    return gmdate('Y-m-d H:i:s', strtotime($map[$range] ?? '-1 hour'));
}

function queryData(): array
{
    $query = Flight::request()->query;
    if (is_object($query) && method_exists($query, 'getData')) {
        return $query->getData();
    }
    return (array) $query;
}

function resolveWindow(array $query): array
{
    $range = (string) ($query['range'] ?? 'last_hour');
    $from = (string) ($query['from'] ?? '');
    $until = (string) ($query['until'] ?? '');
    $stamp = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
    if ($range === 'custom' && preg_match($stamp, $from) === 1) {
        if (preg_match($stamp, $until) !== 1) {
            $until = gmdate('Y-m-d H:i:s');
        }
        return [$range, $from, $until];
    }
    return [$range === 'custom' ? 'last_hour' : $range, calculateThreshold($range), ''];
}

function workerBacklog(array $runwayConfig): ?int
{
    $apm = $runwayConfig['apm'] ?? [];
    $dsn = $apm['source_db_dsn'] ?? '';
    if ($dsn === '') {
        return null;
    }
    try {
        $pdo = new PDO($dsn, $apm['source_db_user'] ?? null, $apm['source_db_pass'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $count = $pdo->query('SELECT COUNT(*) FROM apm_metrics_log')->fetchColumn();
        return $count === false ? null : (int) $count;
    } catch (Throwable $e) {
        return null;
    }
}

$app->route('GET /apm/dashboard', function () use ($app) {
    $app->render('dashboard');
});

$app->route('GET /apm/data/dashboard', function () use ($app, $presenter, $runwayConfig) {
    $query = queryData();
    [$range, $threshold, $until] = resolveWindow($query);
    $filters = $presenter->filtersFromQuery($query);
    $filters['until'] = $until;
    $filters['range'] = $range;
    $data = $presenter->getDashboardData($threshold, $range, $filters);
    $data['workerBacklog'] = workerBacklog($runwayConfig);
    $app->json($data);
});

$app->route('GET /apm/data/requests', function () use ($app, $presenter) {
    $query = queryData();
    [$range, $threshold, $until] = resolveWindow($query);
    $page = max(1, (int) ($query['page'] ?? 1));
    $perPage = 50;
    $filters = $presenter->filtersFromQuery($query);
    $filters['until'] = $until;
    $filters['range'] = $range;
    // The bundled page sends details=0 and loads a row when it is opened.
    $includeDetails = (string) ($query['details'] ?? '1') !== '0';
    $data = $presenter->getRequestsData($threshold, $page, $perPage, '', $includeDetails, $filters);
    $app->json($data);
});

$app->route('GET /apm/data/requests/@id', function ($id) use ($app, $presenter) {
    $app->json($presenter->getRequestDetails((int) $id));
});

$app->route('GET /apm/data/event-keys', function () use ($app, $presenter) {
    $query = queryData();
    [$range, $threshold] = resolveWindow($query);
    $eventKeys = $presenter->getEventKeys($threshold);
    $app->json(['event_keys' => $eventKeys]);
});

$app->start();
