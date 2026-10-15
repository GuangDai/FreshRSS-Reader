#!/usr/bin/env python3
"""Compare serial/parallel source fetches and discard configuration changes made in flight.

Usage: python3 tests/integration/refresh/concurrency_test.py --php /path/to/php
Uses temporary data and local HTTP fixtures only. Requires the scheduler integration dependencies.
"""

import argparse
import json
import os
from pathlib import Path
import signal
import sqlite3
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import scheduler_test as scheduler_fixture


ROOT = Path(__file__).resolve().parents[3]
SEED = scheduler_fixture.SEED + r"""
$config['salt'] = 'local-concurrency-test-only';
$config['scheduler']['per_user'] = (int)$argv[4];
$config['scheduler']['job_timeout'] = 8;
$config['scheduler']['lease_seconds'] = 12;
$config['scheduler']['run_seconds'] = 30;
file_put_contents($path . '/config.php', '<?php return ' . var_export($config, true) . ';');
$db->exec('DELETE FROM feed');
$count = (int)$argv[5];
for ($id = 1; $id <= $count; $id++) {
    $insert->execute([$id, $base . '/feed/' . $id, 'fixture-' . $id, $base]);
}
$db->prepare('UPDATE category SET attributes=? WHERE id=1')
    ->execute([json_encode(['read_when_same_title_in_category' => 100])]);
$db->prepare('INSERT INTO tag(name,attributes) VALUES(?,?)')
    ->execute(['fixture-label', json_encode(['filters' => [['search' => 'intitle:shared', 'actions' => ['label']]]])]);
"""


class Source(BaseHTTPRequestHandler):
    active = 0
    requests = []
    peak = 0
    lock = threading.Lock()
    race_started = threading.Event()
    race_release = threading.Event()

    def log_message(self, *_args):
        pass

    def do_GET(self):
        Source.requests.append(self.path)
        if not self.path.startswith(('/feed/', '/race', '/replacement')):
            self.send_response(404)
            self.end_headers()
            return
        with Source.lock:
            Source.active += 1
            Source.peak = max(Source.peak, Source.active)
        try:
            if self.path.startswith('/feed/'):
                time.sleep(1)
            if self.path.startswith('/race'):
                Source.race_started.set()
                Source.race_release.wait(timeout=10)
            if self.path == '/race-gone':
                self.send_response(410)
                self.end_headers()
                return
            base = f"http://{self.headers['Host']}"
            body = f'''<?xml version="1.0"?><rss version="2.0"><channel>
<title>Local concurrency fixture</title><link>{base}/</link><description>Fixture</description>
<item><guid isPermaLink="false">guid-{self.path}</guid><title>shared title</title>
<link>{base}/article</link><description>Source {self.path}</description></item>
</channel></rss>'''.encode()
            self.send_response(200)
            self.send_header('Content-Type', 'application/rss+xml; charset=UTF-8')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            try:
                self.wfile.write(body)
            except (BrokenPipeError, ConnectionResetError):
                pass
        finally:
            with Source.lock:
                Source.active -= 1


def prepare(php, path, base, concurrency, count):
    for name in ('users/_', 'users/integration', 'cache', 'favicons', 'PubSubHubbub', 'extensions-data', 'tmp'):
        (path / name).mkdir(parents=True, exist_ok=True)
    env = dict(os.environ, DATA_PATH=str(path), TMPDIR=str(path / 'tmp'),
               INTERNAL_HOST_ALLOWLIST=base.removeprefix('http://'), FRESHRSS_ENV='development')
    seed = path / 'seed.php'
    seed.write_text(SEED)
    scheduler_fixture.run_command([php, str(seed), str(ROOT), str(path), base, str(concurrency), str(count)], env)
    return env


def counts(path):
    with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
        return {
            'entries_unread': db.execute('SELECT COUNT(*),SUM(is_read=0) FROM entry').fetchone(),
            'cached_entries_unread': db.execute('SELECT SUM(cache_nbEntries),SUM(cache_nbUnreads) FROM feed').fetchone(),
            'labels': db.execute('SELECT COUNT(*),COUNT(DISTINCT id_entry) FROM entrytag').fetchone(),
            'staging': db.execute('SELECT COUNT(*) FROM entrytmp').fetchone()[0],
        }


def timed_run(php, base, concurrency):
    with tempfile.TemporaryDirectory(prefix=f'freshrss-concurrency-{concurrency}-') as directory:
        path = Path(directory)
        env = prepare(php, path, base, concurrency, 4)
        Source.peak = 0
        started = time.monotonic()
        result = json.loads(scheduler_fixture.run_command([php, 'cli/refresh-queue.php', 'run'], env))
        duration = time.monotonic() - started
        actual = counts(path)
        expected = {'entries_unread': (4, 1), 'cached_entries_unread': (4, 1), 'labels': (4, 4), 'staging': 0}
        assert actual == expected, {'per_user': concurrency, 'actual': actual, 'expected': expected}
        assert result['completed'] == 6, result
        assert Source.peak == concurrency, {'requested_concurrency': concurrency, 'observed': Source.peak}
        return {'per_user': concurrency, 'seconds': round(duration, 3), 'peak_source_requests': Source.peak, **actual}


def changed_source_run(php, base, old_path):
    with tempfile.TemporaryDirectory(prefix='freshrss-changed-source-') as directory:
        path = Path(directory)
        env = prepare(php, path, base, 2, 1)
        with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
            db.execute('UPDATE feed SET url=? WHERE id=1', (base + old_path,))
        Source.race_started.clear()
        Source.race_release.clear()
        process = subprocess.Popen([php, 'cli/refresh-queue.php', 'run'], cwd=ROOT, env=env, text=True,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
        try:
            assert Source.race_started.wait(timeout=5), 'Source request did not start'
            replacement = (base + '/replacement', 'edited during download', json.dumps({'fixture_revision': 2}))
            with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
                db.execute('UPDATE feed SET url=?,name=?,attributes=? WHERE id=1', replacement)
            Source.race_release.set()
            stdout, stderr = process.communicate(timeout=15)
            assert process.returncode == 0, (stdout, stderr)
            with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
                actual = db.execute('SELECT url,name,attributes FROM feed WHERE id=1').fetchone()
                assert actual == replacement, ('Stale download overwrote the configuration', actual, replacement)
                assert db.execute('SELECT COUNT(*) FROM entry').fetchone()[0] == 0, 'Stale source content was applied'
                assert db.execute('SELECT ttl,lastUpdate,error FROM feed WHERE id=1').fetchone() == (3600, 0, 0), 'Stale success/error changed feed state'
            with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
                assert queue.execute('SELECT outcome FROM history WHERE feed_id=1 ORDER BY id DESC LIMIT 1').fetchone() == ('busy',)
                queue.execute('UPDATE jobs SET retry_at=0 WHERE feed_id=1')
            result = json.loads(scheduler_fixture.run_command([php, 'cli/refresh-queue.php', 'run'], env))
            assert result['completed'] == 1, result
            with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
                assert db.execute('SELECT guid FROM entry').fetchall() == [('guid-/replacement',)]
            print(f'PASS: in-flight {old_path} discarded, edited configuration preserved, replacement source committed')
        finally:
            Source.race_release.set()
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            if process.poll() is None:
                process.communicate(timeout=2)


def stale_host_run(php, base):
    with tempfile.TemporaryDirectory(prefix='freshrss-stale-host-') as directory:
        path = Path(directory)
        env = prepare(php, path, base, 2, 1)
        driver = path / 'stale-host.php'
        driver.write_text(r"""<?php
require $argv[1] . '/cli/_cli.php';
cliInitUser('integration');
$hookCalls = 0;
Minz_ExtensionManager::addHook(Minz_HookType::FeedsListBeforeActualize, static function (array $feeds) use (&$hookCalls): array {
    $hookCalls++;
    return $feeds;
});
[$updated] = FreshRSS_feed_Controller::actualizeFeedsAndCommit(1, cleanCache: false, expectedHost: $argv[2]);
echo json_encode(['updated' => $updated, 'hookCalls' => $hookCalls], JSON_THROW_ON_ERROR), "\n";
""")
        requests_before = list(Source.requests)
        result = json.loads(scheduler_fixture.run_command([php, str(driver), str(ROOT), 'old.example.invalid'], env))
        assert result == {'updated': 0, 'hookCalls': 0}, result
        assert Source.requests == requests_before, 'Stale host contacted the new origin or a favicon endpoint'
        with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
            assert db.execute('SELECT COUNT(*) FROM entry').fetchone()[0] == 0
        result = json.loads(scheduler_fixture.run_command([php, str(driver), str(ROOT), '127.0.0.1'], env))
        assert result == {'updated': 1, 'hookCalls': 1}, result
        with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
            assert db.execute('SELECT guid FROM entry').fetchall() == [('guid-/feed/1',)]
        print('PASS: stale queued host skips hooks and all HTTP, while the matching host can fetch and commit')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    server = ThreadingHTTPServer(('127.0.0.1', 0), Source)
    server.daemon_threads = True
    threading.Thread(target=server.serve_forever, daemon=True).start()
    base = f'http://127.0.0.1:{server.server_address[1]}'
    try:
        serial = timed_run(args.php, base, 1)
        parallel = timed_run(args.php, base, 2)
        print(json.dumps({'serial': serial, 'parallel': parallel}, indent=2))
        assert parallel['seconds'] < serial['seconds'] * 0.85, 'Two simultaneous downloads did not reduce fixture wall time'
        changed_source_run(args.php, base, '/race')
        changed_source_run(args.php, base, '/race-gone')
        stale_host_run(args.php, base)
        print('PASS: parallel source timing, serialised duplicate-title rules, labels, counters, staging and stale-download rejection')
    finally:
        Source.race_release.set()
        server.shutdown()
        server.server_close()


if __name__ == '__main__':
    main()
