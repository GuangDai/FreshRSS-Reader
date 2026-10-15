#!/usr/bin/env python3
"""Exercise real scheduler children against local RSS, HTTP error and slow fixtures.

Usage: python3 tests/integration/refresh/scheduler_test.py --php /path/to/php
Requires Python 3, Linux process groups and /proc, and PHP with PDO SQLite, PCNTL and the RSS modules.
The test creates and removes its own DATA_PATH; existing users and previews are untouched.
"""

import argparse
import ctypes
import fcntl
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


ROOT = Path(__file__).resolve().parents[3]
SEED = r"""<?php
$root = $argv[1];
$path = $argv[2];
$base = $argv[3];
require $root . '/constants.php';
require $root . '/lib/lib_rss.php';
$config = require $root . '/config.default.php';
$config['default_user'] = 'integration';
$config['environment'] = 'development';
$config['base_url'] = $base;
$config['scheduler'] = ['enabled' => true, 'workers' => 2, 'per_host' => 2,
    'job_timeout' => 2, 'kill_grace' => 1, 'lease_seconds' => 10,
    'run_seconds' => 8, 'retry_base' => 60, 'retry_max' => 60, 'retry_jitter_percent' => 0,
    'poll_ms' => 25];
file_put_contents($path . '/config.php', '<?php return ' . var_export($config, true) . ';');
$user = require $root . '/config-user.default.php';
$user['enabled'] = true;
file_put_contents($path . '/users/integration/config.php', '<?php return ' . var_export($user, true) . ';');
file_put_contents($path . '/users/_/config.php', '<?php return [];');
require $root . '/app/SQL/install.sql.sqlite.php';
$db = new PDO('sqlite:' . $path . '/users/integration/db.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec($GLOBALS['SQL_CREATE_TABLES']);
$insert = $db->prepare('INSERT INTO feed(id,url,category,name,website,ttl) VALUES(?,?,1,?,?,3600)');
foreach ([1 => 'good', 2 => 'slow', 3 => 'error'] as $id => $name) {
    $insert->execute([$id, $base . '/' . $name, $name, $base]);
}
"""


class Fixture(BaseHTTPRequestHandler):
    visits = []
    recover_error = False
    recover_slow = False
    lock = threading.Lock()

    def log_message(self, *_args):
        pass

    def do_GET(self):
        with self.lock:
            self.visits.append(self.path)
        if self.path == '/slow' and not self.recover_slow:
            time.sleep(15)
        if self.path == '/error' and not self.recover_error:
            self.send_response(500)
            self.end_headers()
            return
        if self.path not in ('/good', '/slow', '/error'):
            self.send_response(404)
            self.end_headers()
            return
        body = b'''<?xml version="1.0"?><rss version="2.0"><channel>
<title>Local scheduler fixture</title><link>http://example.test/</link><description>Fixture</description>
<item><guid isPermaLink="false">scheduler-local-good</guid><title>Committed fixture article</title>
<link>http://example.test/article</link><description>Local test content</description></item>
</channel></rss>'''
        body = body.replace(b'http://example.test', f"http://{self.headers['Host']}".encode())
        if self.path == '/slow':
            body = body.replace(b'scheduler-local-good', b'scheduler-local-after-crash')
        if self.path == '/error':
            body = body.replace(b'scheduler-local-good', b'scheduler-local-recovered')
        self.send_response(200)
        self.send_header('Content-Type', 'application/rss+xml; charset=UTF-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass


def run_command(command, env, timeout=25):
    process = subprocess.Popen(command, cwd=ROOT, env=env, text=True,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
    try:
        stdout, stderr = process.communicate(timeout=timeout)
        if process.returncode:
            raise AssertionError(f'Command failed ({process.returncode}): {stdout}\n{stderr}')
        # Workers inherit the supervisor process group: a remaining group means a leaked child.
        try:
            os.killpg(process.pid, 0)
        except ProcessLookupError:
            pass
        else:
            raise AssertionError('Scheduler left a live child process group')
        return stdout
    finally:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        if process.poll() is None:
            process.wait()


def test_supervisor_crash(php, path, env):
    # Adopt orphaned PHP workers so the test can verify SIGALRM and reap them itself.
    libc = ctypes.CDLL(None, use_errno=True)
    previous_subreaper = ctypes.c_int()
    if libc.prctl(37, ctypes.byref(previous_subreaper), 0, 0, 0) != 0:
        raise OSError(ctypes.get_errno(), 'PR_GET_CHILD_SUBREAPER failed')
    if libc.prctl(36, 1, 0, 0, 0) != 0:
        raise OSError(ctypes.get_errno(), 'PR_SET_CHILD_SUBREAPER failed')
    with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
        queue.execute('UPDATE jobs SET retry_at=0 WHERE feed_id=2 AND kind="feed"')
    before = Fixture.visits.count('/slow')
    supervisor = subprocess.Popen([php, 'cli/refresh-queue.php', 'run'], cwd=ROOT, env=env, text=True,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
    children = set()
    try:
        deadline = time.monotonic() + 5
        while Fixture.visits.count('/slow') == before and time.monotonic() < deadline:
            if supervisor.poll() is not None:
                raise AssertionError('Supervisor exited before the slow worker reached HTTP')
            time.sleep(0.025)
        assert Fixture.visits.count('/slow') > before, 'Slow worker never reached the HTTP fixture'
        children_path = Path(f'/proc/{supervisor.pid}/task/{supervisor.pid}/children')
        children = {int(pid) for pid in children_path.read_text().split()}
        assert len(children) == 1, f'Expected one real slow worker, got {children}'
        with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
            owner, lease_until = queue.execute('SELECT owner,lease_until FROM jobs WHERE feed_id=2').fetchone()
        assert owner, 'Slow worker had no lease before supervisor termination'
        killed_at = time.monotonic()
        supervisor.kill()  # Deliberately leave the worker alive: do not kill its process group here.
        supervisor.communicate(timeout=2)
        statuses = []
        while children and time.monotonic() - killed_at < 4:
            for child in list(children):
                reaped, status = os.waitpid(child, os.WNOHANG)
                if reaped:
                    children.remove(child)
                    statuses.append(status)
            if children:
                time.sleep(0.025)
        duration = time.monotonic() - killed_at
        assert not children, 'Worker survived its independent watchdog after supervisor SIGKILL'
        assert duration < 3.5, f'Worker exceeded its 3-second watchdog: {duration:.2f}s'
        assert all(os.WIFSIGNALED(status) and os.WTERMSIG(status) == signal.SIGALRM for status in statuses), statuses
        for lock_path in list((path / 'scheduler').glob('*.lock')) + list((path / 'tmp').glob('*.freshrss.lock')):
            with lock_path.open('a') as handle:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                fcntl.flock(handle, fcntl.LOCK_UN)
        with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
            assert queue.execute('SELECT owner FROM jobs WHERE feed_id=2').fetchone()[0] == owner, 'Crash unexpectedly acknowledged the lease'
        # Let the real persisted lease expire, then prove another supervisor can reclaim it.
        Fixture.recover_slow = True
        while time.time() <= lease_until:
            time.sleep(min(0.1, max(0.01, lease_until - time.time())))
        result = json.loads(run_command([php, 'cli/refresh-queue.php', 'run'], env))
        assert result['completed'] == 1, 'Expired orphan lease was not reclaimed exactly once'
        with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
            assert db.execute('SELECT guid FROM entry WHERE id_feed=2').fetchone() == ('scheduler-local-after-crash',), 'Reclaimed worker did not commit its feed'
        with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
            assert queue.execute('SELECT COUNT(*) FROM jobs WHERE feed_id=2').fetchone()[0] == 0, 'Reclaimed lease was not acknowledged'
            assert queue.execute('SELECT outcome FROM history WHERE feed_id=2 ORDER BY id DESC LIMIT 1').fetchone() == ('ok',)
        print(f'PASS: supervisor SIGKILL, worker SIGALRM after {duration:.2f}s, released kernel locks, and recovery after lease expiry')
    finally:
        try:
            os.killpg(supervisor.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        if supervisor.poll() is None:
            supervisor.communicate(timeout=2)
        for child in children:
            try:
                os.waitpid(child, 0)
            except ChildProcessError:
                pass
        libc.prctl(36, previous_subreaper.value, 0, 0, 0)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    server = ThreadingHTTPServer(('127.0.0.1', 0), Fixture)
    server.daemon_threads = True
    threading.Thread(target=server.serve_forever, daemon=True).start()
    port = server.server_address[1]
    base = f'http://127.0.0.1:{port}'
    try:
        with tempfile.TemporaryDirectory(prefix='freshrss-scheduler-integration-') as directory:
            path = Path(directory)
            for name in ('users/_', 'users/integration', 'cache', 'favicons', 'PubSubHubbub', 'extensions-data', 'tmp'):
                (path / name).mkdir(parents=True, exist_ok=True)
            env = dict(os.environ, DATA_PATH=directory, TMPDIR=str(path / 'tmp'),
                       INTERNAL_HOST_ALLOWLIST=f'127.0.0.1:{port}', FRESHRSS_ENV='development')
            seed = path / 'seed.php'
            seed.write_text(SEED)
            run_command([args.php, str(seed), str(ROOT), directory, base], env)
            started = time.monotonic()
            output = run_command([args.php, 'cli/refresh-queue.php', 'run'], env)
            elapsed = time.monotonic() - started
            with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
                history = queue.execute('SELECT feed_id,kind,outcome FROM history ORDER BY id').fetchall()
                pending = queue.execute('SELECT feed_id,owner,attempts,retry_at FROM jobs WHERE kind="feed"').fetchall()
            with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
                entries = db.execute('SELECT guid,title FROM entry').fetchall()
            diagnostic = {'stdout': output.strip(), 'elapsed_seconds': round(elapsed, 2),
                          'history': history, 'pending': pending, 'entries': entries, 'requests': Fixture.visits}
            for log in (path / 'users').glob('*/log.txt'):
                diagnostic[f'log_{log.parent.name}'] = log.read_text(errors='replace')[-4000:]
            print(json.dumps(diagnostic, indent=2))
            assert (1, 'feed', 'ok') in history, 'Normal RSS worker did not finish successfully'
            assert (2, 'feed', 'timeout') in history, 'Slow worker was not terminated as a timeout'
            assert (3, 'feed', 'fetch_error') in history, 'HTTP 500 worker did not preserve a retryable failure'
            assert entries == [('scheduler-local-good', 'Committed fixture article')], 'Successful RSS was not committed exactly once'
            assert {row[0] for row in pending} == {2, 3}, 'Failed feed jobs were not retained'
            assert all(owner == '' and attempts == 1 and retry_at > time.time()
                       for _, owner, attempts, retry_at in pending), 'Retry leases or backoff are incorrect'
            assert all(route in Fixture.visits for route in ('/good', '/slow', '/error')), 'A fixture endpoint was never fetched'
            assert elapsed < 12, 'Slow worker exceeded the configured timeout and grace'
            previous_visits = list(Fixture.visits)
            output = run_command([args.php, 'cli/refresh-queue.php', 'run'], env)
            assert json.loads(output)['completed'] == 0, 'A repeat run bypassed TTL or retry backoff'
            assert Fixture.visits == previous_visits, 'A repeat run fetched work that was not due'
            # Make just the HTTP error eligible and recover its fixture, without waiting for the real backoff.
            Fixture.recover_error = True
            with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
                queue.execute('UPDATE jobs SET retry_at=0 WHERE feed_id=3 AND kind="feed"')
            output = run_command([args.php, 'cli/refresh-queue.php', 'run'], env)
            assert json.loads(output)['completed'] == 1, 'The recovered HTTP job did not run once'
            with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
                assert db.execute('SELECT COUNT(*) FROM entry WHERE id_feed=1').fetchone()[0] == 1, 'Successful feed was imported twice'
                assert db.execute('SELECT guid FROM entry WHERE id_feed=3').fetchone() == ('scheduler-local-recovered',), 'Recovered feed was not committed'
            with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
                assert queue.execute('SELECT COUNT(*) FROM jobs WHERE feed_id=3 AND kind="feed"').fetchone()[0] == 0, 'Successful retry was not acknowledged'
            print('PASS: RSS commit, timeout termination, HTTP retry and recovery, TTL/backoff, released leases, and no leaked children')
            test_supervisor_crash(args.php, path, env)
    finally:
        server.shutdown()
        server.server_close()


if __name__ == '__main__':
    main()
