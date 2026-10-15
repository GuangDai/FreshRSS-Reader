# Local resource profile

This profile builds the local source with the upstream Debian Dockerfile. It creates isolated data volumes and binds the web port to loopback only.

```sh
docker compose -f Docker/development/compose.yaml up --build -d
```

Open `http://127.0.0.1:18080` and complete installation with SQLite. For PostgreSQL, start with `--profile postgres` and use host `db`, database/user `freshrss`, and `FRESHRSS_DB_PASSWORD` (default: `local-development-only`). No database port is published. Do not reuse production data volumes.

The application cap is 768 MiB; Apache allows two concurrent workers with a 128 MiB PHP budget each. CLI refresh has a separate 256 MiB PHP budget and no CPU time limit. Workers recycle after 500 connections. OPcache is shared, capped at 96 MiB. These are starting budgets, not a guarantee that native allocations cannot reach the container cap.

The optional PostgreSQL service is capped at 512 MiB, with 128 MiB shared buffers, 4 MiB work memory per operation, and 30 connections. Both services keep at most three 10 MiB Docker log files. The application health check checks the listener only; it does not prove database or feed health.

Cron wakes every five minutes; existing feed TTL and HTTP cache policies still control eligibility. `config.custom.php` is an optional initial-install example, not an automatic override. It enables the durable queue with two workers and a per-host limit of one; this requires CLI PCNTL and PDO SQLite. Each queue child has its own 128 MiB PHP budget and wall-clock deadline. Existing users keep their preferences and article retention policies. See [scheduler behavior and limits](../../docs/zh-CN/development/backend/scheduler.md).

The PHP mount paths target PHP 8.4 in the upstream Debian 13 image. Recheck these paths if changing the base Dockerfile. This profile was inspected locally but not container-built because Docker was unavailable in the development environment.

See [the analysis and implementation notes](../../docs/zh-CN/development/README.md).
