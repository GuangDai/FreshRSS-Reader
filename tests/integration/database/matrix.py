#!/usr/bin/env python3
"""Run SQLite/PostgreSQL contracts against a disposable, socket-only local server."""

import argparse
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parents[3]


def run(command, env=None, timeout=60):
    result = subprocess.run(command, cwd=ROOT, env=env, text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                            timeout=timeout, check=False)
    print(result.stdout, end='', flush=True)
    result.check_returncode()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=shutil.which('php') or str(ROOT / 'bin/php'))
    parser.add_argument('--postgres-bin', type=Path,
                        default=Path(shutil.which('initdb')).parent if shutil.which('initdb')
                        else ROOT / 'bin/postgres/bin')
    parser.add_argument('--query-plans', action='store_true',
                        help='also compare candidate indexes on 100,000 generated entries')
    parser.add_argument('--suite', choices=('all', 'entries', 'subscriptions'), default='all',
                        help='select contracts without repeating unrelated database checks')
    args = parser.parse_args()
    args.postgres_bin = args.postgres_bin.resolve()
    for name in ('initdb', 'pg_ctl', 'postgres'):
        if not (args.postgres_bin / name).is_file():
            parser.error(f'{name} is missing in {args.postgres_bin}; supply --postgres-bin')
    if os.geteuid() == 0:
        parser.error('PostgreSQL initdb requires an ordinary user')

    with tempfile.TemporaryDirectory(prefix='freshrss-db-contract-') as directory:
        temporary = Path(directory)
        data = temporary / 'data'
        socket = temporary / 'socket'
        socket.mkdir(mode=0o700)
        log = temporary / 'postgres.log'
        control = [str(args.postgres_bin / 'pg_ctl'), '-D', str(data)]
        started = False
        try:
            run([str(args.postgres_bin / 'postgres'), '--version'])
            run([str(args.postgres_bin / 'initdb'), '-D', str(data), '--auth-local=trust',
                 '--auth-host=reject', '--encoding=UTF8', '--no-locale'])
            run(control + ['-l', str(log), '-o',
                           f"-c listen_addresses='' -c unix_socket_directories='{socket}' "
                           '-c max_connections=20 -c shared_buffers=16MB', '-w', 'start'])
            started = True
            env = os.environ | {
                'FRESHRSS_ENV': 'silent',
                'FRESHRSS_TEST_PGSQL_DSN': f'pgsql:host={socket};dbname=postgres',
                'FRESHRSS_TEST_PGSQL_USER': pwd.getpwuid(os.getuid()).pw_name,
                'FRESHRSS_TEST_PGSQL_PASSWORD': '',
            }
            if args.suite in ('all', 'entries'):
                run([args.php, 'vendor/bin/phpunit',
                     'tests/integration/database/contracts/DatabaseContractTest.php'], env)
                run([args.php, 'tests/integration/database/entry-concurrency.php'], env)
            if args.suite in ('all', 'subscriptions'):
                run([args.php, 'vendor/bin/phpunit',
                     'tests/integration/database/contracts/SubscriptionConfigDatabaseTest.php'], env)
                run([args.php, 'tests/integration/database/subscription-concurrency.php'], env)
            if args.query_plans:
                for backend in ('sqlite', 'pgsql'):
                    run([args.php, 'tests/integration/database/query-plan.php',
                         f'--backend={backend}', '--entries=100000'], env, timeout=180)
        except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
            if log.exists():
                print(log.read_text(), flush=True)
            raise
        finally:
            if started or (data / 'postmaster.pid').exists():
                run(control + ['-m', 'immediate', '-w', 'stop'])


if __name__ == '__main__':
    main()
