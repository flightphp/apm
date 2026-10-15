<?php

declare(strict_types=1);

namespace Tests;

use flight\apm\presenter\MysqlPresenter;
use flight\database\PdoWrapper;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Every test runs on SQLite. Set APM_TEST_MYSQL_DSN (plus APM_TEST_MYSQL_USER and
 * APM_TEST_MYSQL_PASSWORD) to also run them on MySQL/MariaDB. That database is wiped
 * before each test, so point it at a throwaway schema.
 */
class CustomEventsPanelTest extends TestCase
{
    protected PDO $pdo;

    /** @var \flight\apm\presenter\PresenterAbstract */
    protected $presenter;

    protected int $now;

    /**
     * @return array<string,array{0:string}>
     */
    public function drivers(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql']];
    }

    private function boot(string $driver): void
    {
        $this->now = time();
        if ($driver === 'sqlite') {
            require_once __DIR__ . '/support.php';
            [$this->pdo, $this->presenter] = apm_test_presenter();
            return;
        }
        $dsn = (string) getenv('APM_TEST_MYSQL_DSN');
        if ($dsn === '') {
            $this->markTestSkipped('APM_TEST_MYSQL_DSN is not set.');
        }
        $user = (string) getenv('APM_TEST_MYSQL_USER');
        $password = (string) getenv('APM_TEST_MYSQL_PASSWORD');
        $pdo = new PdoWrapper($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $files = glob(__DIR__ . '/../src/apm/migration/mysql/dest/*.sql');
        sort($files);
        foreach ($files as $file) {
            $pdo->exec(file_get_contents($file));
        }
        $this->pdo = $pdo;
        $this->presenter = new MysqlPresenter($pdo, ['apm' => []]);
    }

    /**
     * @dataProvider drivers
     */
    public function testEmptyWindowReturnsNoCustomEvents(string $driver): void
    {
        $this->boot($driver);
        $this->request('req_plain', 300, 0);

        $data = $this->presenter->getDashboardData($this->ago(3600), 'last_hour');

        $this->assertSame([], $data['customEvents']);
    }

    /**
     * @dataProvider drivers
     */
    public function testCountsOrderingLastSeenAndTrend(string $driver): void
    {
        $this->boot($driver);
        $first = $this->request('req_a', 1200, 0);
        $second = $this->request('req_b', 600, 0);
        $third = $this->request('req_c', 120, 1);
        $this->event($first, 'docs.markdown_served', 1200);
        $this->event($second, 'docs.markdown_served', 600);
        $this->event($third, 'docs.markdown_served', 120);
        $this->event($second, 'api_call', 600);

        $events = $this->presenter->getDashboardData($this->ago(3600), 'last_hour')['customEvents'];

        $this->assertSame(['docs.markdown_served', 'api_call'], array_column($events, 'event_type'));
        $this->assertSame([3, 1], array_column($events, 'event_count'));
        $this->assertSame($this->ago(120), $events[0]['last_seen']);
        $this->assertSame($this->ago(600), $events[1]['last_seen']);
        $this->assertSame(3, array_sum($events[0]['trend']));
        $this->assertSame(1, array_sum($events[1]['trend']));
        $hour = $this->presenter->getDashboardData($this->ago(3600), 'last_hour');
        $this->assertCount(count($hour['chartData']), $events[0]['trend']);
    }

    /**
     * @dataProvider drivers
     */
    public function testTiesSortByEventType(string $driver): void
    {
        $this->boot($driver);
        $id = $this->request('req_tie', 300, 0);
        $this->event($id, 'zeta', 300);
        $this->event($id, 'alpha', 300);

        $events = $this->presenter->getDashboardData($this->ago(3600), 'last_hour')['customEvents'];

        $this->assertSame(['alpha', 'zeta'], array_column($events, 'event_type'));
    }

    /**
     * @dataProvider drivers
     */
    public function testTimeRangeBoundsUseTheRequestWindow(string $driver): void
    {
        $this->boot($driver);
        $inside = $this->request('req_in', 600, 0);
        $old = $this->request('req_old', 7200, 0);
        $late = $this->request('req_late', 60, 0);
        $this->event($inside, 'docs.markdown_served', 600);
        $this->event($old, 'docs.markdown_served', 7200);
        $this->event($late, 'docs.markdown_served', 60);

        $hour = $this->presenter->getDashboardData($this->ago(3600), 'last_hour')['customEvents'];
        $this->assertSame(2, $hour[0]['event_count']);

        $bounded = $this->presenter->getDashboardData($this->ago(3600), 'custom', ['until' => $this->ago(300)]);
        $this->assertSame(1, $bounded['customEvents'][0]['event_count']);
        $this->assertSame($this->ago(600), $bounded['customEvents'][0]['last_seen']);
    }

    /**
     * @dataProvider drivers
     */
    public function testBotAndRequestFiltersApply(string $driver): void
    {
        $this->boot($driver);
        $human = $this->request('req_human', 300, 0, '/en/v3/learn');
        $bot = $this->request('req_bot', 200, 1, '/en/v3/install');
        $this->event($human, 'docs.markdown_served', 300);
        $this->event($bot, 'docs.markdown_served', 200);
        $this->event($bot, 'bot_only', 200);
        $threshold = $this->ago(3600);

        $hidden = $this->presenter->getDashboardData($threshold, 'last_hour', ['exclude_bots' => true]);
        $this->assertSame(['docs.markdown_served'], array_column($hidden['customEvents'], 'event_type'));
        $this->assertSame(1, $hidden['customEvents'][0]['event_count']);

        $bots = $this->presenter->getDashboardData($threshold, 'last_hour', ['is_bot' => '1'])['customEvents'];
        $this->assertSame([1, 1], array_column($bots, 'event_count'));

        $humans = $this->presenter->getDashboardData($threshold, 'last_hour', ['is_bot' => '0'])['customEvents'];
        $this->assertSame(['docs.markdown_served'], array_column($humans, 'event_type'));

        $byUrl = $this->presenter->getDashboardData($threshold, 'last_hour', ['url' => 'install'])['customEvents'];
        $this->assertSame(['bot_only', 'docs.markdown_served'], array_column($byUrl, 'event_type'));

        $drill = $this->presenter->getDashboardData($threshold, 'last_hour', ['custom_event_type' => 'bot_only']);
        $this->assertSame(1, $drill['allRequestsCount']);
    }

    /**
     * @dataProvider drivers
     */
    public function testLikeFiltersTreatWildcardsLiterally(string $driver): void
    {
        $this->boot($driver);
        $this->event($this->request('req_under', 300, 0, '/a_b'), 'bot_only', 300);
        $this->event($this->request('req_plain', 200, 0, '/axb'), 'botxonly', 200);
        $threshold = $this->ago(3600);

        $drill = $this->presenter->getDashboardData($threshold, 'last_hour', ['custom_event_type' => 'bot_only']);
        $this->assertSame(1, $drill['allRequestsCount']);
        $this->assertSame(['bot_only'], array_column($drill['customEvents'], 'event_type'));

        $byUrl = $this->presenter->getDashboardData($threshold, 'last_hour', ['url' => '/a_b']);
        $this->assertSame(1, $byUrl['allRequestsCount']);
    }

    private function ago(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', $this->now - $seconds);
    }

    private function request(string $token, int $ago, int $bot, string $url = '/'): int
    {
        $this->pdo->prepare(
            'INSERT INTO apm_requests (request_token, request_dt, request_method, request_url, total_time,
                peak_memory, response_code, response_size, response_build_time, is_bot)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$token, $this->ago($ago), 'GET', $url, 0.01, 2048, 200, 100, 0.01, $bot]);
        return (int) $this->pdo->lastInsertId();
    }

    private function event(int $requestId, string $type, int $ago): void
    {
        $this->pdo->prepare(
            'INSERT INTO apm_custom_events (request_id, event_type, event_data, event_dt) VALUES (?, ?, ?, ?)'
        )->execute([$requestId, $type, '{}', $this->ago($ago)]);
    }
}
