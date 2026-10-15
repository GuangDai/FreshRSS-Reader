#!/usr/bin/env python3
"""Exercise the classic actualizeAction lock lifetime through real SQLite transactions.

Usage: python3 tests/integration/refresh/legacy_test.py --php /path/to/php
Only temporary data, a local source, and isolated PHP processes are used.
"""

import argparse
import json
from pathlib import Path
import tempfile
import threading
from http.server import ThreadingHTTPServer

import concurrency_test as concurrency_fixture


ROOT = Path(__file__).resolve().parents[3]
PROBE = r"""<?php
require $argv[1] . '/cli/_cli.php';
cliInitUser('integration');
$lock = FreshRSS\Infrastructure\Concurrency\UserWorkLock::tryAcquire();
echo $lock === null ? 'held' : 'free';
$lock?->release();
"""
DRIVER = r"""<?php
require $argv[1] . '/cli/_cli.php';
cliInitUser('integration');
final class LegacyLockProbePdo extends Minz_PdoSqlite {
    public array $observations = [];
    public function probe(string $phase): string {
        $process = proc_open([PHP_BINARY, DATA_PATH . '/probe.php', FRESHRSS_PATH],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start lock probe');
        $state = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Lock probe failed: ' . $error);
        $this->observations[] = [$phase, $state];
        return $state;
    }
    public function beginTransaction(): bool {
        $this->probe('begin');
        return parent::beginTransaction();
    }
    public function commit(): bool {
        $this->probe('commit');
        return parent::commit();
    }
    public function rollBack(): bool {
        $this->probe('rollback');
        return parent::rollBack();
    }
}
$mode = $argv[2];
$pdo = new LegacyLockProbePdo('sqlite:' . DATA_PATH . '/users/integration/db.sqlite',
    options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
(new ReflectionProperty(Minz_ModelPdo::class, 'sharedPdo'))->setValue(null, $pdo);
(new ReflectionProperty(Minz_ModelPdo::class, 'sharedCurrentUser'))->setValue(null, 'integration');
Minz_ModelPdo::$usesSharedPdo = true;
Minz_Request::_params(['id' => 1, 'ajax' => true, 'maxFeeds' => 1]);
$_POST['noCommit'] = $mode === 'noCommit' ? 1 : 0;
if ($mode === 'failure') {
    $pdo->exec("CREATE TRIGGER fail_commit BEFORE INSERT ON entry BEGIN SELECT RAISE(FAIL, 'injected commit failure'); END");
}
$failure = null;
try {
    (new FreshRSS_feed_Controller())->actualizeAction();
} catch (Throwable $e) {
    $failure = get_class($e) . ': ' . $e->getMessage();
}
$after = $pdo->probe('after');
echo json_encode(['mode' => $mode, 'failure' => $failure, 'observations' => $pdo->observations,
    'entries' => (int)$pdo->query('SELECT COUNT(*) FROM entry')->fetchColumn(),
    'staging' => (int)$pdo->query('SELECT COUNT(*) FROM entrytmp')->fetchColumn(),
    'transaction' => $pdo->inTransaction()], JSON_THROW_ON_ERROR), "\n";
"""


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    server = ThreadingHTTPServer(('127.0.0.1', 0), concurrency_fixture.Source)
    server.daemon_threads = True
    threading.Thread(target=server.serve_forever, daemon=True).start()
    base = f'http://127.0.0.1:{server.server_address[1]}'
    try:
        for mode in ('normal', 'noCommit', 'failure'):
            with tempfile.TemporaryDirectory(prefix=f'freshrss-legacy-{mode}-') as directory:
                path = Path(directory)
                env = concurrency_fixture.prepare(args.php, path, base, 1, 1)
                (path / 'probe.php').write_text(PROBE)
                driver = path / 'legacy.php'
                driver.write_text(DRIVER)
                output = concurrency_fixture.scheduler_fixture.run_command([args.php, str(driver), str(ROOT), mode], env)
                result = json.loads(output)
                assert result['observations'][-1] == ['after', 'free'], result
                assert all(state == 'held' for phase, state in result['observations'] if phase != 'after'), result
                assert result['transaction'] is False, result
                if mode == 'normal':
                    assert result['failure'] is None and (result['entries'], result['staging']) == (1, 0), result
                    assert ['begin', 'held'] in result['observations'] and ['commit', 'held'] in result['observations'], result
                elif mode == 'noCommit':
                    assert result['failure'] is None and (result['entries'], result['staging']) == (0, 1), result
                else:
                    assert result['failure'] == 'RuntimeException: Failed to commit staged entries', result
                    assert (result['entries'], result['staging']) == (0, 1), result
                    assert ['rollback', 'held'] in result['observations'], result
                print(json.dumps(result))
        print('PASS: legacy normal/noCommit/failure paths retain the user lock through transaction boundaries and release it afterwards')
    finally:
        server.shutdown()
        server.server_close()


if __name__ == '__main__':
    main()
