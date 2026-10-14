# FlightPHP APM
[![Latest Stable Version](http://poser.pugx.org/flightphp/apm/v)](https://packagist.org/packages/flightphp/apm)
[![License](https://poser.pugx.org/flightphp/apm/license)](https://packagist.org/packages/flightphp/apm)
[![PHP Version Require](http://poser.pugx.org/flightphp/apm/require/php)](https://packagist.org/packages/flightphp/apm)
[![Dependencies](http://poser.pugx.org/flightphp/apm/dependents)](https://packagist.org/packages/flightphp/apm)

A lightweight Application Performance Monitoring (APM) library for the [FlightPHP](https://github.com/flightphp/core) framework. Keep your app fast, spot bottlenecks, and sleep better at night!

## What is FlightPHP APM?

FlightPHP APM helps you track how your app performs in real-time—think of it as a fitness tracker for your code! It logs metrics like request times, memory usage, database queries, and custom events, then gives you a slick dashboard to see it all. Why care? Because slow apps lose users, and finding performance hiccups *before* they bite saves you headaches (and maybe a few angry emails).

Built to be fast and simple, it slots right into your FlightPHP project with minimal fuss.

## Installation

Grab it with Composer:

```bash
composer require flightphp/apm
```

## Quick Start

1. **Log APM Metrics**  
   Add this to your `index.php` or services file to start tracking:
   ```php
   use flight\apm\logger\LoggerFactory;
   use flight\Apm;

   $ApmLogger = LoggerFactory::create(__DIR__ . '/../../.runway-config.json');
   $Apm = new Apm($ApmLogger);
   $Apm->bindEventsToFlightInstance($app);
   ```

2. **Set Up Config**  
   Run this to create your `.runway-config.json`:
   ```bash
   php vendor/bin/runway apm:init
   ```

3. **Process Metrics**  
   Fire up the worker to crunch those metrics (runs once by default):
   ```bash
   php vendor/bin/runway apm:worker
   ```
   Want it continuous? Try `--daemon`:
   ```bash
   php vendor/bin/runway apm:worker --daemon
   ```

4. **View Your Dashboard**  
   Launch the dashboard to see your app’s pulse:
   ```bash
   php vendor/bin/runway apm:dashboard --host localhost --port 8001
   ```

## Keeping the Worker Running

The worker processes your metrics—here’s how to keep it humming:
- **Daemon Mode**: `php vendor/bin/runway apm:worker --daemon` (runs forever!)
- **Crontab**: `* * * * * php /path/to/project/vendor/bin/runway apm:worker` (runs every minute)
- **Tmux/Screen**: Start it in a detachable session with `tmux` or `screen` for easy monitoring.

## Requirements

- PHP 7.4 or higher
- [FlightPHP Core](https://github.com/flightphp/core) v3.15+

## Supported Databases

FlightPHP APM currently supports the following databases for storing metrics:

- **SQLite3**
- **MySQL/MariaDB**

## Upgrading a production server

Index changes ship as new SQL files. They do not rewrite collected rows. The worker can stay up while they run. On a large MySQL or MariaDB database, the new indexes still take a lock-light but heavy read of `apm_requests`, so run the migrate during a quiet period.

1. Back up the destination database. For SQLite, copy the destination file. For MySQL or MariaDB, `mysqldump` the APM schema.
2. Deploy the new package (`composer update flightphp/apm`).
3. From the application root, run:

```bash
php vendor/bin/runway apm:migrate
```

SQLite runs `src/apm/migration/sqlite/dest/0006-dest-indexes.sql`. MySQL and MariaDB run `src/apm/migration/mysql/dest/0002-dest-indexes.sql`. `apm:migrate` records filenames in the runway config and skips a file it has already applied. The same command is safe to run again.

`request_dt` is stored in UTC. `apm:purge` now compares against a UTC cutoff. Check the kept window the first time you purge after upgrading if the server timezone is not UTC.

The dashboard in this release adds latency and status charts, route and error filters, a custom time range, a hide-bots switch, and optional basic auth. Set `apm.dashboard_auth` when the dashboard is reachable beyond localhost:

```json
{
  "apm": {
    "dashboard_auth": {
      "user": "apm",
      "password": "change-me"
    }
  }
}
```

Leave `user` or `password` empty to keep the dashboard open. The built-in PHP server is still a development server. Put a reverse proxy in front of it on a production host.

Request IDs in `X-Flight-Request-Id` now match `request_token` on the stored row. Bot detection also marks LLM crawlers and metasearch agents such as GPTBot, ClaudeBot, PerplexityBot, and SearxNG.

## Documentation

Want the full scoop? Check out the [FlightPHP APM Documentation](https://docs.flightphp.com/awesome-plugins/apm) for setup details, worker options, dashboard tricks, and more!

## Community

Join us on [Matrix IRC #flight-php-framework:matrix.org](https://matrix.to/#/#flight-php-framework:matrix.org) to chat, ask questions, or share your APM wins!

[![](https://dcbadge.limes.pink/api/server/https://discord.gg/Ysr4zqHfbX)](https://discord.gg/Ysr4zqHfbX)

## License

MIT—free and open for all!