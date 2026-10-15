#!/bin/sh
set -eu

image="${1:-freshrss-reader:ci}"
container="freshrss-smoke-$$"

cleanup() {
	status=$?
	trap - EXIT
	if [ "$status" -ne 0 ]; then
		docker logs "$container" >&2 || true
	fi
	docker rm --force --volumes "$container" >/dev/null 2>&1 || true
	exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Anonymous volumes belong only to this test and are deleted with the container.
docker run --detach --name "$container" \
	--env CRON_MIN= --env TZ=UTC \
	--volume /var/www/FreshRSS/data --volume /var/www/FreshRSS/extensions \
	"$image" >/dev/null

attempt=0
until docker exec "$container" php Docker/healthcheck.php; do
	attempt=$((attempt + 1))
	if [ "$attempt" -ge 30 ]; then
		echo 'FreshRSS did not serve the installation page in time' >&2
		exit 1
	fi
	sleep 2
done

docker exec -i "$container" php <<'PHP'
<?php
foreach (['curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pcntl', 'pdo_sqlite', 'pdo_mysql', 'pdo_pgsql', 'simplexml', 'xml', 'zip'] as $extension) {
	if (!extension_loaded($extension)) {
		throw new RuntimeException('Missing extension: ' . $extension);
	}
}
foreach (['proc_open', 'pcntl_alarm', 'pcntl_signal'] as $function) {
	if (!function_exists($function)) {
		throw new RuntimeException('Missing function: ' . $function);
	}
}
foreach (['bin', 'node_modules', 'vendor', '.git', '.env', 'constants.local.php'] as $path) {
	if (file_exists($path)) {
		throw new RuntimeException('Unexpected image build input: ' . $path);
	}
}
PHP

docker exec --user www-data "$container" php cli/do-install.php \
	--default-user smoketest --db-type sqlite --base-url http://localhost --language en
docker exec --user www-data "$container" php cli/create-user.php \
	--user smoketest --password smoke-test-only --no-default-feeds

docker exec -i "$container" sh -c 'cat > p/smoke-fixture.xml' <<'RSS'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Smoke fixture</title><link>https://example.test/</link><description>Local fixture</description>
<item><guid isPermaLink="false">container-smoke-article</guid><title>Container smoke article</title><description>Local feed ingestion</description></item>
</channel></rss>
RSS

docker exec -i --user www-data "$container" php <<'PHP'
<?php
require 'cli/_cli.php';
$config = FreshRSS_Context::systemConf();
if (($config->scheduler['enabled'] ?? false) !== true) {
	throw new RuntimeException('The new installation did not enable the durable scheduler');
}
$config->internal_host_allowlist = ['127.0.0.1:80'];
if (!$config->save()) {
	throw new RuntimeException('Cannot configure local fixture access');
}
cliInitUser('smoketest');
$id = FreshRSS_Factory::createFeedDao()->addFeed([
	'url' => 'http://127.0.0.1/smoke-fixture.xml', 'category' => 1,
	'name' => 'Smoke fixture', 'website' => 'https://example.test/', 'description' => '', 'lastUpdate' => 0,
]);
if ($id === false) {
	throw new RuntimeException('Cannot create fixture subscription');
}
PHP

docker exec --user www-data "$container" php cli/refresh-queue.php run
docker exec --user www-data "$container" php cli/refresh-queue.php status
docker exec -i --user www-data "$container" php <<'PHP'
<?php
require 'cli/_cli.php';
$status = FreshRSS\Infrastructure\Refresh\Queue::open()->status(time());
$success = array_filter($status['history'], static fn(array $job): bool => $job['kind'] === 'feed' && $job['outcome'] === 'ok');
if (count($success) !== 1 || $status['running'] !== 0) {
	throw new RuntimeException('Refresh worker did not complete the feed successfully');
}
$database = new PDO('sqlite:' . DATA_PATH . '/users/smoketest/db.sqlite');
if ($database->query("SELECT COUNT(*) FROM entry WHERE guid='container-smoke-article'")->fetchColumn() !== 1) {
	throw new RuntimeException('Refresh worker did not commit the fixture article');
}
PHP
docker exec "$container" php Docker/healthcheck.php
echo 'Container installation, HTTP, PHP dependencies and feed ingestion passed.'
