# FreshRSS tests

See our [documentation about running tests](https://freshrss.github.io/FreshRSS/en/developers/03_Running_tests.html).

```sh
make test-all
```

See [`tests.yml`](../.github/workflows/tests.yml) for the GitHub Actions automated tests.

See [`composer.json`](../composer.json) for the different tests and versions, to be run locally.

## Details about this *tests* folder

Unit tests are based on [PHPUnit](https://phpunit.de/).
Here is an example of manual install:

```sh
cd ./tests/
wget -O phpunit.phar https://phar.phpunit.de/phpunit-10.phar
php phpunit.phar --bootstrap bootstrap.php
```

The `shellchecks.sh` script is used to safeguard shell scripts from common
shell script bugs and to ensure a consistent style.
It requires [ShellCheck](https://www.shellcheck.net/) and [shfmt](https://github.com/mvdan/sh).

## Test layout and entry points

Run the following commands from the repository root after installing Composer and npm dependencies.

| Directory | Purpose | Entry point |
| --- | --- | --- |
| `app/`, `lib/`, `cli/` | PHP unit and regression tests, following the application structure | `composer run-script phpunit` |
| `frontend/` | Node.js unit tests, grouped by Reader and Classic features | `npm run test:frontend` |
| `browser/` | Browser behavior, appearance, accessibility and responsive layouts | `npm run test:browser` |
| `integration/refresh/` | Real refresh workers, concurrency, recovery and legacy entry points | `make test-integration-refresh` |
| `integration/database/` | Real schemas, database contracts and concurrent writes | `make test-integration-database` |
| `integration/api/` | Fever and Google Reader authentication and protocol tests | `make test-integration-api` |
| `fixtures/` | Shared test inputs; suite-specific fixtures stay beside their suite | Loaded by the corresponding tests |

`make test-all` runs Composer checks, npm checks (including frontend unit tests) and spelling checks.
It does not start browsers, refresh integration tests, a PostgreSQL server or API fixtures.
CI also runs the refresh integration tests explicitly; its current coverage is defined in [`tests.yml`](../.github/workflows/tests.yml).

The PHPUnit configuration discovers PHP tests recursively, including the SQLite database contracts.
PostgreSQL cases require `FRESHRSS_TEST_PGSQL_DSN`; the database matrix runner supplies an isolated temporary server.

## Browser suites

Browser tests are grouped under `browser/reader/`, `browser/classic/`, `browser/extensions/`,
`browser/api/` and `browser/auth/`. The PHP-rendered Reader fixture lives in `browser/fixtures/`.
All configurations share defaults in `browser/config/base.mjs`; results go to the ignored
`bin/test-results/browser/` directory.

The default configuration discovers every browser specification with Chromium.
The following focused configurations preserve their individual viewport and browser matrices:

| npm command | Coverage |
| --- | --- |
| `npm run test:browser:appearance` | Reader appearance: desktop, phone and landscape Chromium |
| `npm run test:browser:reading` | Reader interactions: Chromium desktop, small phone, phone and landscape; Firefox and WebKit |
| `npm run test:browser:classic` | Classic interactions: Chromium, Firefox and WebKit |
| `npm run test:browser:settings` | Settings page: desktop, phone and small phone Chromium; Firefox and WebKit |
| `npm run test:browser:account` | Account settings: desktop and phone Chromium; Firefox and WebKit |
| `npm run test:browser:embed` | Embedded settings: Chromium, Firefox and WebKit |

Install the required Playwright browsers before executing these suites:

```sh
npx playwright install chromium firefox webkit
npm run test:browser -- --list
npm run test:browser:appearance -- --project=desktop
```

The PHP-rendered appearance and interaction fixtures do not need an installed FreshRSS account.
They require a PHP CLI executable in `PATH`, or an explicit `FRESHRSS_TEST_PHP` path.
Tests using a live installation require an isolated server and account with sample articles:

```sh
FRESHRSS_TEST_URL=http://127.0.0.1:18080/i/ \
FRESHRSS_TEST_USER=reader FRESHRSS_TEST_PASSWORD='test-password' \
FRESHRSS_TEST_OTHER_USER=readerother FRESHRSS_TEST_OTHER_PASSWORD='other-test-password' \
npm run test:browser
```

Some tests change preferences, subscriptions and article state. Use dedicated test accounts.
Missing credentials cause applicable tests to skip; a skipped test has not verified that behavior.
`settings-page.config.mjs` also supports optional local credentials in the ignored `bin/ui-review.env` file.

`node tests/browser/tools/capture-classic-themes.mjs` generates Classic theme preview images
using an isolated account or `FRESHRSS_TEST_STORAGE_STATE`.

## Integration prerequisites

Refresh integration tests create and clean temporary data directories, HTTP fixtures and worker processes.
They require Linux process facilities, Python 3 and PHP with PDO SQLite, PCNTL and the normal RSS extensions.
An alternative PHP executable can be selected without changing the scripts:

```sh
make test-integration-refresh TEST_PHP=/path/to/php
python3 tests/integration/refresh/scheduler_test.py --php /path/to/php
```

Database contracts require PDO SQLite and PDO PostgreSQL, Composer test dependencies,
and PostgreSQL binaries (`initdb`, `pg_ctl`, `postgres`). The runner must execute as an ordinary user.
It creates and removes its own temporary PostgreSQL cluster and listens only on a Unix socket:

```sh
python3 tests/integration/database/matrix.py \
  --php /path/to/php --postgres-bin /path/to/postgresql/bin
```

Use `--suite entries` or `--suite subscriptions` to select a database contract group.
Add `--query-plans` to run the optional generated-data index comparisons.

API protocol tests require an isolated SQLite seed directory containing populated `reader` and empty
`readerother` users. The script copies the seed into a temporary directory, changes credentials only
in that copy, starts its own PHP server and removes the copy afterwards:

```sh
FRESHRSS_API_FIXTURE=/path/to/isolated/seed \
FRESHRSS_PHP_BINARY=/path/to/php \
node tests/integration/api/auth.mjs
```

The local resource profile in [`Docker/development/`](../Docker/development/README.md)
can provide an isolated live installation for browser tests; it does not seed accounts automatically.
