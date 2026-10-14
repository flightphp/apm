# FlightPHP APM

This repository is the `flightphp/apm` Composer library. It is not a Flight skeleton. There is no `app/config/routes.php`.

## Public API

Do not change these:

- Method signatures on `flight\Apm`, `flight\apm\CustomEvent`, `LoggerInterface`, `PresenterInterface`, and the reader and writer interfaces
- Flight event names the collector listens for
- The metrics array passed to `LoggerInterface::log()`
- CLI command names: `apm:init`, `apm:worker`, `apm:dashboard`, `apm:migrate`, `apm:purge`, `apm:config-migrate`
- Existing `apm` config keys

Adding a return key, a dashboard route, or an optional config key is fine. `WriterCommonTrait` is unused. Leave it in place.

`addPdoConnection()` accepts a `PDO`. `PdoWrapper` is deprecated upstream. Do not require a newer Flight type.

## Storage

SQLite and MySQL/MariaDB only. Schema changes are new files under `src/apm/migration/{sqlite,mysql}/dest/`. Do not edit a migration that has already shipped.

`apm:migrate` records filenames in one flat list shared by the source and destination databases. A new file needs a unique name, such as `0006-dest-indexes.sql`.

`request_dt` and `event_dt` are UTC strings from `gmdate`. Format chart labels with `gmdate`. Purge cutoffs use UTC.

Dashboard queries live in `src/apm/presenter/PresenterAbstract.php`. Filter the request window with a join or `EXISTS` on `apm_requests.request_dt`, not by loading every row into PHP.

## Dashboard

`dashboard/` is Bootstrap 5, Chart.js, and vanilla JavaScript. There is no build step. `php vendor/bin/runway apm:dashboard` serves `dashboard/index.php` as the router script so `/apm/dashboard` hits Flight and `/css` and `/js` still serve as files.

Optional basic auth reads `apm.dashboard_auth.user` and `apm.dashboard_auth.password`. If either is empty, the dashboard stays open.

## Bots

`Apm::isBot()` is a case-insensitive substring list. It includes search crawlers and LLM crawlers (GPTBot, ClaudeBot, PerplexityBot, SearxNG, and the rest in that method). `Google-Extended` is a robots.txt token, not a User-Agent, so it is not in the list. Add new agents there when a vendor publishes a new token.

## Tests and docs

Tests live in `tests/`. Do not commit `coverage/`.

The English docs page is `content/v3/en/awesome-plugins/apm.md` in the `flightphp-docs` repository. Update it when dashboard behavior or the upgrade steps change. Production upgrade steps also live in this repo's README under "Upgrading a production server".

Read the installed Flight core and the Flight docs before using a Flight API.
