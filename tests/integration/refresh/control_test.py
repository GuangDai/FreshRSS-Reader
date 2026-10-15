#!/usr/bin/env python3
"""Test persistent pause/resume, graceful and forced signals, and long-poll deadlines.

Usage: python3 tests/integration/refresh/control_test.py --php /path/to/php
All data and requests are local fixtures, isolated from the installed FreshRSS instance.
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

import concurrency_test as concurrency_fixture


ROOT = Path(__file__).resolve().parents[3]
run_command = concurrency_fixture.scheduler_fixture.run_command


class ControlSource(BaseHTTPRequestHandler):
    started = threading.Event()
    release = threading.Event()
    requests = []
    recovered = False

    def log_message(self, *_args):
        pass

    def do_GET(self):
        if not self.path.startswith(('/feed/', '/blocked')):
            self.send_response(404)
            self.end_headers()
            return
        ControlSource.requests.append(self.path)
        ControlSource.started.set()
        if self.path == '/feed/1':
            ControlSource.release.wait(timeout=10)
        if self.path == '/blocked' and not ControlSource.recovered:
            time.sleep(15)
        base = f"http://{self.headers['Host']}"
        body = f'''<?xml version="1.0"?><rss version="2.0"><channel>
<title>Control fixture</title><link>{base}/</link><description>Fixture</description>
<item><guid isPermaLink="false">control-{self.path}</guid><title>control title</title>
<link>{base}/article</link><description>local source</description></item>
</channel></rss>'''.encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/rss+xml')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass


def prepare(php, path, base, count=3, blocked=False, long_poll=False):
    env = concurrency_fixture.prepare(php, path, base, 1, count)
    update = path / 'control-config.php'
    update.write_text('''<?php
$path = $argv[1];
$config = require $path . '/config.php';
$config['scheduler']['workers'] = 1;
$config['scheduler']['per_host'] = 1;
$config['scheduler']['poll_ms'] = (int)$argv[2];
$config['scheduler']['job_timeout'] = (int)$argv[3];
file_put_contents($path . '/config.php', '<?php return ' . var_export($config, true) . ';');
''')
    run_command([php, str(update), str(path), '10000' if long_poll else '25', '1' if long_poll else '8'], env)
    if blocked:
        with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
            db.execute('UPDATE feed SET url=? WHERE id=1', (base + '/blocked',))
    run_command([php, 'cli/refresh-queue.php', 'enqueue'], env)
    # Keep the assertions about admission focused on feeds, independently of maintenance order.
    with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
        for user in ('', 'integration'):
            queue.execute('INSERT INTO schedule(username,maintenance_due) VALUES(?,?) '
                          'ON CONFLICT(username) DO UPDATE SET maintenance_due=excluded.maintenance_due',
                          (user, int(time.time()) + 3600))
    ControlSource.started.clear()
    ControlSource.release.clear()
    ControlSource.requests = []
    ControlSource.recovered = False
    return env


def start(php, env):
    return subprocess.Popen([php, 'cli/refresh-queue.php', 'run'], cwd=ROOT, env=env, text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)


def stop_cleanup(process):
    ControlSource.release.set()
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    if process.poll() is None:
        process.communicate(timeout=2)


def finish(process, timeout=10):
    stdout, stderr = process.communicate(timeout=timeout)
    assert process.returncode == 0, (stdout, stderr)
    try:
        os.killpg(process.pid, 0)
    except ProcessLookupError:
        pass
    else:
        raise AssertionError('Supervisor left a running child process group')
    return json.loads(stdout)


def entry_count(path):
    with sqlite3.connect(path / 'users/integration/db.sqlite') as db:
        return db.execute('SELECT COUNT(*) FROM entry').fetchone()[0]


def diagnostic(path):
    with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
        history = queue.execute('SELECT feed_id,kind,outcome FROM history').fetchall()
    logs = {str(log.relative_to(path)): log.read_text(errors='replace') for log in (path / 'users').glob('*/log.txt')}
    return {'entries': entry_count(path), 'history': history, 'logs': logs}


def test_pause_resume(php, base):
    with tempfile.TemporaryDirectory(prefix='freshrss-pause-') as directory:
        path = Path(directory)
        env = prepare(php, path, base)
        process = start(php, env)
        try:
            assert ControlSource.started.wait(timeout=5), 'First source did not start'
            assert json.loads(run_command([php, 'cli/refresh-queue.php', 'pause'], env)) == {'paused': True}
            ControlSource.release.set()
            assert finish(process)['completed'] == 1, 'Pause did not drain only the already running job'
            assert entry_count(path) == 1, diagnostic(path)
            before = list(ControlSource.requests)
            assert json.loads(run_command([php, 'cli/refresh-queue.php', 'run'], env))['completed'] == 0, 'Pause did not persist across CLI runs'
            assert ControlSource.requests == before
            assert json.loads(run_command([php, 'cli/refresh-queue.php', 'status'], env))['paused'] is True
            assert json.loads(run_command([php, 'cli/refresh-queue.php', 'resume'], env)) == {'paused': False}
            assert json.loads(run_command([php, 'cli/refresh-queue.php', 'run'], env))['completed'] == 2
            assert entry_count(path) == 3, 'Resume lost or duplicated queued feed work'
            print('PASS: pause drains active work, persists across runs, and resume completes preserved jobs')
        finally:
            stop_cleanup(process)


def test_graceful_signal(php, base):
    with tempfile.TemporaryDirectory(prefix='freshrss-graceful-') as directory:
        path = Path(directory)
        env = prepare(php, path, base)
        process = start(php, env)
        try:
            assert ControlSource.started.wait(timeout=5)
            process.send_signal(signal.SIGTERM)
            ControlSource.release.set()
            assert finish(process)['completed'] == 1, 'First signal did not drain the current job'
            assert entry_count(path) == 1, diagnostic(path)
            assert json.loads(run_command([php, 'cli/refresh-queue.php', 'status'], env))['paused'] is False, 'A process signal incorrectly paused the durable queue'
            assert json.loads(run_command([php, 'cli/refresh-queue.php', 'run'], env))['completed'] == 2
            assert entry_count(path) == 3
            print('PASS: one SIGTERM drains active work without pausing future supervisors')
        finally:
            stop_cleanup(process)


def test_forced_signal(php, base):
    with tempfile.TemporaryDirectory(prefix='freshrss-force-') as directory:
        path = Path(directory)
        env = prepare(php, path, base, count=1, blocked=True)
        process = start(php, env)
        try:
            assert ControlSource.started.wait(timeout=5)
            started = time.monotonic()
            process.send_signal(signal.SIGTERM)
            time.sleep(0.1)
            process.send_signal(signal.SIGINT)
            finish(process, timeout=4)
            elapsed = time.monotonic() - started
            assert elapsed < 2, f'Second signal failed to force termination: {elapsed:.2f}s'
            assert entry_count(path) == 0
            with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
                assert queue.execute('SELECT owner,attempts FROM jobs WHERE feed_id=1').fetchone() == ('', 1)
                assert queue.execute('SELECT outcome FROM history WHERE feed_id=1 ORDER BY id DESC LIMIT 1').fetchone() == ('crashed',)
                queue.execute('UPDATE jobs SET retry_at=0 WHERE feed_id=1')
            ControlSource.recovered = True
            assert json.loads(run_command([php, 'cli/refresh-queue.php', 'run'], env))['completed'] == 1
            assert entry_count(path) == 1, 'Forced termination lost the retryable job'
            print(f'PASS: second termination signal stops children in {elapsed:.2f}s and preserves a successful retry')
        finally:
            stop_cleanup(process)


def test_long_poll_deadline(php, base):
    with tempfile.TemporaryDirectory(prefix='freshrss-deadline-') as directory:
        path = Path(directory)
        env = prepare(php, path, base, count=1, blocked=True, long_poll=True)
        started = time.monotonic()
        result = json.loads(run_command([php, 'cli/refresh-queue.php', 'run'], env, timeout=6))
        elapsed = time.monotonic() - started
        assert result['completed'] == 1
        with sqlite3.connect(path / 'scheduler/queue.sqlite') as queue:
            outcome, duration = queue.execute('SELECT outcome,duration_ms FROM history WHERE feed_id=1 ORDER BY id DESC LIMIT 1').fetchone()
            assert outcome == 'timeout', outcome
            assert duration < 3000, ('Ten-second polling overran the one-second deadline and one-second grace', duration)
            assert queue.execute('SELECT owner FROM jobs WHERE feed_id=1').fetchone() == ('',)
        assert elapsed < 3.5, elapsed
        print(f'PASS: poll_ms=10000 still enforces job_timeout=1 plus grace=1; total {elapsed:.2f}s')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    server = ThreadingHTTPServer(('127.0.0.1', 0), ControlSource)
    server.daemon_threads = True
    threading.Thread(target=server.serve_forever, daemon=True).start()
    base = f'http://127.0.0.1:{server.server_address[1]}'
    try:
        test_pause_resume(args.php, base)
        test_graceful_signal(args.php, base)
        test_forced_signal(args.php, base)
        test_long_poll_deadline(args.php, base)
    finally:
        ControlSource.release.set()
        server.shutdown()
        server.server_close()


if __name__ == '__main__':
    main()
