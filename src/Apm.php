<?php

declare(strict_types=1);

namespace flight;

use flight\apm\CustomEvent;
use flight\apm\logger\LoggerInterface;
use flight\Engine;
use flight\net\Request;
use flight\net\Response;
use flight\net\Route;
use PDO;
use Throwable;
use flight\database\PdoWrapper;

class Apm
{
	/**
	 * @var LoggerInterface $logger An instance of the ApmLogger used for logging APM (Application Performance Monitoring) data.
	 */
    protected LoggerInterface $logger;

	/**
	 * @var array<int, PdoWrapper> $pdoConnections An array to store PDO connections for logging database queries.
	 */
	protected array $pdoConnections = [];

	/**
	 * @var array $metrics An array to store metrics data.
	 */
    protected array $metrics = [];

	/**
	 * @var float $sampleRate The rate at which samples are taken for monitoring purposes.
	 */
    protected float $sampleRate;

	/**
	 * Apm constructor.
	 *
	 * @param LoggerInterface $logger The APM logger instance.
	 * @param float $sampleRate The sample rate for APM logging, default is 1.0.
	 */
    public function __construct(LoggerInterface $logger, float $sampleRate = 1.0)
    {
        $this->logger = $logger;
        $this->sampleRate = $sampleRate;
    }

	/**
	 * Generates a unique request ID.
	 * 
	 * @return string
	 */
	public function generateRequestId(): string
	{
		return uniqid('req_', true);
	}

	/**
	 * Registers the events for the application performance monitoring (APM) system.
	 *
	 * This method sets up the necessary event listeners and handlers to monitor
	 * the application's performance metrics.
	 * 
	 * @param Engine $app The Flight application instance.
	 *
	 * @return void
	 */
    public function bindEventsToFlightInstance(Engine $app): void
    {
		// Set defaults
		$this->metrics['start_time'] = 0.0;
		$this->metrics['start_memory'] = 0;
		$this->metrics['request_method'] = '';
		$this->metrics['request_url'] = '';
		$this->metrics['errors'] = [];
		$this->metrics['routes'] = [];
		$this->metrics['middleware'] = [];
		$this->metrics['views'] = [];
		$this->metrics['db'] = [
			// [ 'engine' => string, 'host' => string, 'database' => string ]
			'connection_data' => [],
			// [ 'sql' => string, 'params', => array, 'execution_time' => float, 'row_count' => int, 'memory_usage' => int ]
			'query_data' => []
		];
		$this->metrics['cache'] = [];
		$this->metrics['custom'] = [];
		$this->metrics['is_bot'] = false;
        $dispatcher = $app->eventDispatcher();

        $dispatcher->on('flight.request.received', function (Request $request) use ($app) {
			// Generate request ID early and store it
			$requestId = $this->generateRequestId();
			$this->metrics['request_id'] = $requestId;

			// Make request ID accessible on the request object
			$app->response()->setHeader('X-Flight-Request-Id', $requestId);

			// Register in Flight's shared variable space for easy access
			$app->set('apm.request_id', $requestId);

            $this->metrics['start_time'] = microtime(true);
            $this->metrics['start_memory'] = memory_get_usage();
			$this->metrics['request_method'] = $request->method;
			$this->metrics['request_url'] = $request->url;
			$this->metrics['ip'] = $request->proxy_ip ?: $request->ip;
			$this->metrics['user_agent'] = $request->user_agent ?? '';
			$this->metrics['host'] = $request->host ?? '';
			if(function_exists('session_id')) {
				$this->metrics['session_id'] = session_status() === PHP_SESSION_ACTIVE ? session_id() : null;
			} else {
				$this->metrics['session_id'] = null;
			}

			// Check if the request is from a bot
			$userAgent = $request->user_agent ?? '';
			$this->metrics['is_bot'] = $this->isBot($userAgent);
        });

        $dispatcher->on('flight.route.executed', function (Route $route, float $executionTime) {
            $this->metrics['routes'][$route->pattern] = [
                'execution_time' => round($executionTime, 8),
                'memory_used' => memory_get_usage() - $this->metrics['start_memory']
            ];
        });

        $dispatcher->on('flight.middleware.executed', function (Route $route, $middleware, string $method, float $executionTime) {
			$middlewareName = is_object($middleware) ? get_class($middleware) : (string)$middleware;
			$middlewareName .= '->' . $method;
            $this->metrics['middleware'][$route->pattern][] = [
                'middleware' => $middlewareName,
                'execution_time' => round($executionTime, 8)
            ];
        });

        $dispatcher->on('flight.view.rendered', function (string $file, float $renderTime) {
            $this->metrics['views'][$file] = ['render_time' => round($renderTime, 8)];
        });

        $dispatcher->on('flight.db.queries', function (array $connectionMetrics, array $queryMetrics) {
            $this->metrics['db'] = [
				'connection_data' => $connectionMetrics,
				'query_data' => $queryMetrics
			];
        });

        $dispatcher->on('flight.error', function (Throwable $e) {
            $this->metrics['errors'][] = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ];
        });

		$dispatcher->on('flight.cache.checked', function (string $type, bool $hit, float $executionTime) {
            $this->metrics['cache'][$type] = [
				'hit' => $hit,
				'execution_time' => round($executionTime, 8)
			];
        });
		
		$dispatcher->on('apm.custom', function (CustomEvent $CustomEvent) {
			$this->metrics['custom'][] = [
				'timestamp' => microtime(true), // Time when the event was received
				'type' => $CustomEvent->type,        // User-defined event type (e.g., 'curl_request')
				'data' => $CustomEvent->data         // User-defined event data
			];
		});

		// This is where metrics are collected and send out 
		// because this is the final call in the request lifecycle
        $dispatcher->on('flight.response.sent', function (Response $response, float $executionTime) {
            $endTime = microtime(true);
            $this->metrics['total_time'] = round($endTime - $this->metrics['start_time'], 8);
            $this->metrics['peak_memory'] = memory_get_peak_usage();
			$this->metrics['response_code'] = $response->status();
			$this->metrics['response_size'] = strlen($response->getBody());
			$this->metrics['response_build_time'] = round($executionTime, 8);

			// sampleRate allows you to scale back the number of metrics sent to the logger
			// to avoid overwhelming the logging system. A value of 1.0 means 100% of requests
			// are logged, while a value of 0.1 means only 10% of requests are logged.
			if (rand(0, 9999) / 10000 <= $this->sampleRate) {
				foreach($this->pdoConnections as $pdo) {
					/** @var PdoWrapper $pdo */
					if(method_exists($pdo, 'logQueries')) {
						$pdo->logQueries();
					}
				}
				$this->logger->log($this->metrics);
			}
        });


    }

	/**
	 * Adds a PDO connection to the APM (Application Performance Monitoring) system.
	 *
	 * @param PDO $pdo The PDO connection instance to be added.
	 *
	 * @return void
	 */
	public function addPdoConnection(PDO $pdo): void
	{
		$this->pdoConnections[] = $pdo;
	}

	/**
	 * Retrieves the metrics.
	 *
	 * @return array An array containing the metrics data.
	 */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

	/**
	 * Checks if the user agent string belongs to a known bot.
	 * 
	 * This method checks the user agent string against a list of known bot user agents
	 * 
	 * @param string $userAgent The user agent string to check.
	 * 
	 * @return bool Returns true if the user agent is a bot, false otherwise.
	 */
	public function isBot(string $userAgent): bool
	{
		// List of known bot user agents
		$botUserAgents = [
			'Googlebot',
			'Bingbot',
			'Slurp',
			'DuckDuckBot',
			'Baiduspider',
			'YandexBot',
			'Sogou',
			'Exabot',
			'facebot',
			'ia_archiver'
		];

		foreach ($botUserAgents as $bot) {
			if (stripos($userAgent, $bot) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets the current request ID.
	 * 
	 * @return string|null The request ID or null if not set.
	 */
	public function getRequestId(): ?string
	{
		return $this->metrics['request_id'] ?? null;
	}
}