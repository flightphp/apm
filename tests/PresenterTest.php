<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

class PresenterTest extends TestCase
{
    public function testDashboardBucketsUseTheRangeAndUtcLabels(): void
    {
        require_once __DIR__ . '/support.php';
        [$pdo, $presenter] = apm_test_presenter();
        $now = time();
        $inside = gmdate('Y-m-d H:i:s', $now - 600);
        $alsoInside = gmdate('Y-m-d H:i:s', $now - 120);
        $old = gmdate('Y-m-d H:i:s', $now - 90000);
        $this->insertRequest($pdo, 'req_slow', $inside, 'GET', '/slow', 1.5, 200, 0, '10.0.0.8');
        $slowId = (int) $pdo->lastInsertId();
        $this->insertRequest($pdo, 'req_err', $alsoInside, 'POST', '/err', 0.2, 500, 0, '10.0.0.9');
        $errId = (int) $pdo->lastInsertId();
        $this->insertRequest($pdo, 'req_bot', $alsoInside, 'GET', '/bot', 0.05, 200, 1, '1.2.3.4');
        $this->insertRequest($pdo, 'req_old', $old, 'GET', '/old', 9, 200, 0, '10.0.0.1');

        $routeSql = 'INSERT INTO apm_routes (request_id, route_pattern, execution_time, memory_used)'
            . ' VALUES (?, ?, ?, ?)';
        $pdo->prepare($routeSql)->execute([$slowId, '/slow', 0.4, 1000]);
        $pdo->prepare('INSERT INTO apm_db_queries (request_id, query, execution_time, row_count) VALUES (?, ?, ?, ?)')
            ->execute([$slowId, 'SELECT * FROM users WHERE id = 1', 0.3, 1]);
        $pdo->prepare('INSERT INTO apm_db_queries (request_id, query, execution_time, row_count) VALUES (?, ?, ?, ?)')
            ->execute([$slowId, 'SELECT * FROM users WHERE id = 2', 0.5, 1]);
        $pdo->prepare('INSERT INTO apm_errors (request_id, error_message, error_code, error_trace) VALUES (?, ?, ?, ?)')
            ->execute([$errId, 'Boom', 500, 'trace line']);
        $eventSql = 'INSERT INTO apm_custom_events (request_id, event_type, event_data, event_dt) VALUES (?, ?, ?, ?)';
        $event = $pdo->prepare($eventSql);
        $event->execute([$slowId, 'api_call', '{"user_id":"7"}', $inside]);
        $eventId = (int) $pdo->lastInsertId();
        $dataSql = 'INSERT INTO apm_custom_event_data (custom_event_id, request_id, json_key, json_value)'
            . ' VALUES (?, ?, ?, ?)';
        $pdo->prepare($dataSql)->execute([$eventId, $slowId, 'user_id', '7']);
        $pdo->prepare('INSERT INTO apm_views (request_id, view_file, render_time) VALUES (?, ?, ?)')
            ->execute([$slowId, 'users.php', 0.01]);

        $threshold = gmdate('Y-m-d H:i:s', $now - 3600);
        $hour = $presenter->getDashboardData($threshold, 'last_hour');

        $this->assertSame(3, $hour['allRequestsCount']);
        $this->assertEqualsWithDelta(1 / 3, $hour['errorRate'], 0.001);
        $this->assertSame(1.5, $hour['p95']);
        $this->assertSame('SELECT * FROM users WHERE id = 2', $hour['longQueries'][0]['query']);
        $this->assertSame('SELECT * FROM users WHERE id = ?', $hour['longQueries'][0]['normalized']);
        $this->assertSame(2, $hour['longQueries'][0]['hit_count']);
        $this->assertSame('/slow', $hour['slowRoutes'][0]['route_pattern']);
        $this->assertSame('Boom', $hour['errorGroups'][0]['error_message']);

        $labels = array_column($hour['chartData'], 'request_dt');
        $this->assertGreaterThan(8, count($labels));
        $this->assertSame(300, strtotime($labels[1] . ' UTC') - strtotime($labels[0] . ' UTC'));
        $bucket = gmdate('Y-m-d H:i:s', intdiv(strtotime($inside . ' UTC'), 300) * 300);
        $matched = array_values(array_filter($hour['chartData'], function ($row) use ($bucket) {
            return $row['request_dt'] === $bucket;
        }));
        $this->assertNotEmpty($matched);
        $this->assertGreaterThan(0, $matched[0]['request_count']);

        $day = $presenter->getDashboardData($threshold, 'last_day');
        $dayLabels = array_column($day['chartData'], 'request_dt');
        $this->assertSame(900, strtotime($dayLabels[1] . ' UTC') - strtotime($dayLabels[0] . ' UTC'));

        $hidden = $presenter->getDashboardData($threshold, 'last_hour', ['exclude_bots' => true]);
        $this->assertSame(2, $hidden['allRequestsCount']);

        $routed = $presenter->getDashboardData($threshold, 'last_hour', ['route_pattern' => '/slow']);
        $this->assertSame(1, $routed['allRequestsCount']);

        $this->assertSame(['user_id'], $presenter->getEventKeys($threshold));

        $page = $presenter->getRequestsData($threshold, 1, 50, '', false, []);
        $this->assertCount(3, $page['requests']);
        $this->assertArrayNotHasKey('queries', $page['requests'][0]);
        $this->assertArrayHasKey('peak_memory', $page['requests'][0]);

        $details = $presenter->getRequestDetails($slowId);
        $this->assertSame('users.php', $details['views'][0]['view_file']);
        $this->assertSame('api_call', $details['custom_events'][0]['type']);
        $this->assertSame($inside, $details['custom_events'][0]['event_dt']);
        $this->assertSame('7', $details['custom_events'][0]['data']['user_id']);
    }

    private function insertRequest(
        PDO $pdo,
        string $token,
        string $when,
        string $method,
        string $url,
        float $time,
        int $code,
        int $bot,
        string $ip
    ): void {
        $pdo->prepare(
            'INSERT INTO apm_requests (
                request_token, request_dt, request_method, request_url, total_time, peak_memory,
                response_code, response_size, response_build_time, is_bot, ip, host
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$token, $when, $method, $url, $time, 2048, $code, 100, 0.01, $bot, $ip, 'example.test']);
    }
}
