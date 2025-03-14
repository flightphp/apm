<?php

declare(strict_types=1);

namespace flight\commands;

use Ahc\Cli\Helper\Shell;
use Ahc\Cli\IO\Interactor;
use Ahc\Cli\Input\Command;

/**
 * ApmDashboardCommand
 * 
 * @property-read ?string $host
 * @property-read ?string $port
 * @property-read ?string $phpPath
 * @property-read ?string $dashboardDir
 */
class ApmDashboardCommand extends Command
{
    /**
     * Default configuration values
     *
     * @var array<string,mixed>
     */
    protected array $defaults = [
        'host' => 'localhost',
        'port' => '8001',
        'dashboardDir' => null,
    ];

    /**
     * Construct
     *
     * @param array<string,mixed> $config JSON config from .runway-config.json
     */
    public function __construct(array $config = [])
    {
        parent::__construct('apm:dashboard', 'Starts a development server for the APM dashboard');

        $this->option('--host host', 'The host to serve the dashboard on (default: localhost)')
             ->option('--port port', 'The port to serve the dashboard on (default: 8001)')
             ->option('--php-path php_path', 'Path to the PHP executable (default: php)')
             ->option('--dashboard-dir dashboard_dir', 'Path to the dashboard public directory (default: [project_root]/dashboard)');

        // Set default values
        $this->host = $config['host'] ?? $this->defaults['host'];
        $this->port = $config['port'] ?? $this->defaults['port'];
        $this->phpPath = $config['php_path'] ?? 'php';
        $this->dashboardDir = $config['dashboard_dir'] ?? null;
    }

    /**
     * Executes the dashboard command
     *
     * @return void
     */
    public function execute()
    {
        $io = $this->app()->io();

        // Get options with defaults
        $host = $this->host ?? $this->defaults['host'];
        $port = $this->port ?? $this->defaults['port'];
        $phpPath = escapeshellcmd($this->phpPath ?? 'php');
        $hostPort = escapeshellarg("{$host}:{$port}");
        
        // Use the custom dashboard directory if provided, otherwise use the default
        $dashboardPath = $this->dashboardDir ?? dirname(__DIR__, 2) . '/dashboard';
        $dashboardPath = escapeshellarg($dashboardPath);
        
        if (!is_dir(trim($dashboardPath, "'"))) {
            $io->error("Dashboard directory not found at: " . trim($dashboardPath, "'"));
            return;
        }

        $io->bold("Starting APM dashboard server at http://{$host}:{$port}/apm/dashboard", true);
        $io->bold("Press Ctrl+C to stop the server", true);
        
        $command = "{$phpPath} -S {$hostPort} -t {$dashboardPath}";
        
        $io->green("Running: $command", true);
        
        // Execute the PHP server command
		// $shell = new Shell($command);
		// $shell->setOptions(getcwd());
		// $shell->execute();
		// echo $shell->getOutput();
		// echo $shell->getErrorOutput();
        passthru($command);
    }
}
