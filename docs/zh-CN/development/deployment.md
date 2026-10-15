# Docker 部署

本仓库的部署镜像为 `ghcr.io/guangdai/freshrss-reader`，包含独立 Reader、规则、订阅管理和持久化刷新队列。
使用 Debian 13、Apache 和 PHP 8.4，支持 `linux/amd64`、`linux/arm64`。
镜像公开发布，可直接拉取，无需登录 GitHub Container Registry。
上游 `freshrss/freshrss` 镜像不包含本仓库新增的功能。

## 首次启动

安装 Docker Engine 和 Docker Compose v2，在仓库根目录执行：

```sh
cp .env.example .env
docker compose pull
docker compose up -d
docker compose ps
```

访问 `http://服务器地址:8080/i/`，在安装向导中选择 SQLite，并设置管理员账号和密码。
默认无需配置数据库密码；SQLite 数据库、用户配置、订阅、文章和刷新队列均存储在持久化卷中。
启用公网访问前，应通过 HTTPS 反向代理完成安装和登录。

根目录的 `docker-compose.yml` 用于部署，`Docker/development/compose.yaml` 用于本地开发，两者使用不同的项目名和数据卷。
启动根目录配置不会自动接入已有开发数据。

## 配置

修改 `.env` 后，执行 `docker compose up -d` 应用容器配置。
`.env` 已加入 Git 和镜像构建的忽略列表，`.env.example` 仅提供无真实凭据的模板。

| 变量 | 默认值 | 作用 |
| --- | --- | --- |
| `FRESHRSS_IMAGE` | `ghcr.io/guangdai/freshrss-reader:latest` | 使用的镜像及版本 |
| `FRESHRSS_BIND` | `0.0.0.0` | 端口绑定地址；仅供本机代理访问时改为 `127.0.0.1` |
| `FRESHRSS_PORT` | `8080` | 宿主机 HTTP 端口 |
| `TZ` | `Asia/Shanghai` | PHP 和计划任务时区 |
| `CRON_MIN` | `*/5` | 每五分钟启动刷新调度；留空可关闭内置定时任务 |
| `FRESHRSS_MEMORY_LIMIT` | `1024m` | 应用容器内存上限 |
| `FRESHRSS_CPUS` | `1.0` | 应用容器 CPU 配额 |
| `TRUSTED_PROXY` | `0` | 默认忽略转发的客户端 IP；使用反向代理时填可信代理地址或网段 |

首次安装会启用持久化队列，设置两个刷新 worker、同一主机最多一个任务，每个 worker 的 PHP 内存上限为 128 MiB。
Cron 每五分钟启动一次调度，订阅的刷新周期、重试退避和暂停状态决定具体执行哪些任务。
镜像只为尚未安装、且没有 `config.custom.php` 的新数据目录写入初始设置。
接入已有数据时保留原配置，可在管理员的刷新任务设置中启用队列和调整并发。

Apache 同时处理最多两个请求，单个 Web 请求的 PHP 内存上限为 128 MiB，CLI 调度进程为 256 MiB。
这些是小规模部署的初始资源预算，不能代表实际峰值内存；可依据文章大小、扩展和并发量调整。
PHP 配置可挂载到 `/etc/php/8.4/apache2/conf.d/zz-local.ini` 和 `/etc/php/8.4/cli/conf.d/zzz-local.ini`，Apache 配置位于 `/etc/apache2/mods-available/mpm_prefork.conf`。
Docker 日志保留最多三个 10 MiB 文件。

健康检查会请求容器内 `/i/` 并确认返回 FreshRSS HTML 页面，安装前和安装后均可运行。
它用于发现 Web 服务故障，不代表所有订阅源或数据库操作均正常，也不会自动重启仅被标记为 `unhealthy` 的容器。

## 持久化、更新与备份

Compose 默认使用以下命名卷，实际名称带有项目名前缀 `freshrss-reader_`：

| 卷 | 容器目录 | 内容 |
| --- | --- | --- |
| `app-data` | `/var/www/FreshRSS/data` | 配置、用户数据库、缓存和刷新队列 |
| `app-extensions` | `/var/www/FreshRSS/extensions` | 用户安装的扩展 |

更新镜像会保留数据卷：

```sh
docker compose pull
docker compose up -d
```

`latest` 和 `main` 跟随主分支通过 CI 的提交；需要固定版本时，将 `FRESHRSS_IMAGE` 改为对应的 `sha-…` 标签或镜像摘要。
更新前备份数据。SQLite 部署可短暂停止应用，归档两个卷：

```sh
docker compose stop app
docker compose run --rm --no-deps --entrypoint tar app \
  -czf - -C /var/www/FreshRSS data extensions > freshrss-backup.tar.gz
docker compose up -d
```

备份文件包含账号和数据库配置，应存放在受限目录。
`docker compose down` 会保留数据卷；`docker compose down --volumes` 会删除数据，请勿用于常规更新。

## 可选 PostgreSQL

需要 PostgreSQL 时，在首次安装前设置 `.env` 中的 `FRESHRSS_DB_PASSWORD` 为独立密码，再执行：

```sh
docker compose -f docker-compose.yml -f Docker/production/compose.postgres.yaml pull
docker compose -f docker-compose.yml -f Docker/production/compose.postgres.yaml up -d
```

安装向导中选择 PostgreSQL，主机填 `db`，端口填 `5432`，数据库和用户名均填 `freshrss`，密码使用 `.env` 中设置的值。
数据库不向宿主机公开端口，应用会在 PostgreSQL 健康检查通过后启动。
PostgreSQL 使用独立的 `app-postgres` 卷，内存上限为 512 MiB；应用和数据库合计的内存配额上限为 1536 MiB。
所有后续 Compose 操作均需保留上述两个 `-f` 参数。
只有显式加载 PostgreSQL 配置时才要求数据库密码；默认 SQLite 启动不受该变量影响。

该配置适用于新安装，不会把已有 SQLite 数据自动迁移到 PostgreSQL。
迁移方法见 [CLI 数据库备份与恢复](../../../cli/README.md) 和 [上游 Docker 文档](../../../Docker/README.md#migrate-database)。
PostgreSQL 部署还需备份数据库，例如：

```sh
docker compose -f docker-compose.yml -f Docker/production/compose.postgres.yaml \
  exec -T db pg_dump -U freshrss freshrss > freshrss-postgres.sql
```

## 本地构建与 CI

使用当前工作区构建镜像：

```sh
docker build --pull -f Docker/Dockerfile -t freshrss-reader:local .
sh Docker/smoke-test.sh freshrss-reader:local
FRESHRSS_IMAGE=freshrss-reader:local docker compose up -d --pull never
```

构建上下文排除运行数据、`.env`、本地常量、私钥、开发依赖和测试目录。
PHP 的运行依赖和前端资源随镜像一起交付，部署时无需挂载源码或执行 Composer、npm 安装。
Debian 的 PHP CLI 包内置 PCNTL，镜像构建时会检查 PCNTL 和 `proc_open`，确保调度器能够启动独立 worker。
参见 [Debian PHP CLI 包说明](https://packages.debian.org/trixie/php8.4-cli) 和 [Docker Compose 服务配置](https://docs.docker.com/reference/compose-file/services/)。

GitHub Actions 在拉取请求和主分支提交上运行代码检查、应用测试及容器冒烟测试。
冒烟测试使用临时容器和匿名数据卷，检查安装页面、PHP 扩展、SQLite 安装、刷新 worker 和本地 RSS 文章入库，结束时自动清理。
主分支测试通过后，发布 `linux/amd64`、`linux/arm64` 镜像到 GHCR；测试失败时不会更新发布标签。

运行状态与手动刷新：

```sh
docker compose logs --tail=100 app
docker compose exec --user www-data app php cli/refresh-queue.php status
docker compose exec --user www-data app php cli/refresh-queue.php run
```
