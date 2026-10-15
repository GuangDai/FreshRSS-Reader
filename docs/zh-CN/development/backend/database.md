# 数据库合同、并发与索引

数据库专项完成五轮独立工作。验证使用本地普通用户运行的 PostgreSQL 17.11 和 SQLite，采用项目真实安装 schema；没有连接现有用户数据库或外部数据库。

## 五轮改动

| 轮次 | 发现的问题 | 实现与验证 |
| --- | --- | --- |
| 1：真实后端环境 | 原批量状态回归只使用 SQLite，无法发现 PostgreSQL 语法和锁行为差异 | 新增隔离 fixture 和临时 PostgreSQL 启停脚本；同一合同在两库执行 |
| 2：事务与 staging | 批量跨分片失败、嵌套事务和 staging 重排需要真实后端证据 | 通过触发器注入写入失败；验证保存点保留调用者工作、暂存文章保留并可重试、日期排序、重叠大 ID 不丢文章 |
| 3：分页与 schema | 收藏或标签查询的 OR 优先级错误；PG 标签排序缺少 DISTINCT 投影；三库 NOT NULL 元数据映射错误；PG 同名表可能混入字段 | 与 Entry 专项共同修复查询；本专项修复元数据映射和 PG search_path 解析，覆盖超过 `2^53` 的字符串 ID、分页、同名 schema 和用户表前缀 |
| 4：索引与迁移 | SQLite/PG 二列 feed-read 索引不能同时满足 feed、未读状态和 ID 排序 | 新安装使用三列索引；已有数据库提供显式幂等升级，验证调用者回滚与重复运行；用实际 DAO 查询生成 EXPLAIN |
| 5：并发与交叉回归 | 同一 feed 的不同文章并发标记，计数可能被旧语句快照覆盖；标签和 feed 状态操作需要跨库复测 | 双 PHP 进程强制制造 PostgreSQL 行锁等待；验证最终实际未读数和缓存都为零，并复测 Entry 专项的全部相关改动 |

## 运行合同

需要 Python 3、PHP 与 `pdo_sqlite`、`pdo_pgsql`，以及本地 PostgreSQL 的 `initdb`、`pg_ctl`、`postgres`。项目开发依赖需已安装。

```sh
python3 tests/integration/database/matrix.py \
  --php /path/to/php \
  --postgres-bin /path/to/postgresql/bin
```

脚本创建临时数据目录及权限为 `0700` 的 Unix socket 目录，关闭 TCP 监听，禁止 host 认证；完成后停止服务并删除临时目录。不能以 root 运行 PostgreSQL。测试不会使用 `data/config.php` 中的数据库连接。

本次本地环境没有系统 PostgreSQL，使用 [PostgreSQL 官方仓库的 REL_17_11 源码](https://github.com/postgres/postgres/tree/REL_17_11) 编译普通用户工具，放在忽略的 `bin/postgres/` 下。复现命令为：

```sh
python3 tests/integration/database/matrix.py \
  --php bin/php --postgres-bin bin/postgres/bin
```

也可对已有的专用测试 PostgreSQL 实例运行；fixture 会创建随机 schema，并在结束时删除：

```sh
FRESHRSS_TEST_PGSQL_DSN='pgsql:host=/path/to/test/socket;dbname=test' \
FRESHRSS_TEST_PGSQL_USER=test \
php vendor/bin/phpunit tests/integration/database/contracts/DatabaseContractTest.php
```

没有设置 `FRESHRSS_TEST_PGSQL_DSN` 时，普通 PHPUnit 运行只执行 SQLite 合同和不连接 MySQL 的元数据映射测试，不会将 PostgreSQL 记为已通过。

只检查订阅配置批量保存，可选择对应合同，无需重复文章与索引测试：

```sh
python3 tests/integration/database/matrix.py \
  --php bin/php --postgres-bin bin/postgres/bin --suite subscriptions
```

订阅配置合同使用真实外键和大小写敏感列，验证动态未读计数不使配置版本过期、全部版本先检查再写入、后项 SQL 失败时整批回滚、调用者事务保留，以及分类被删除后 `category=NULL` 的订阅仍能单独改名。错误模式同时覆盖 PDO 静默返回和异常。

并发用例启动两个 PHP 进程，强制两者使用同一批旧版本并等待 PostgreSQL 行锁；释放锁后必须恰有一批成功，另一批返回版本冲突，分类与两个 feed 保持同批提交。这个用例直接调用 DAO，以验证数据库层的竞争处理；公开服务还会使用用户工作锁，因此实际 API 也可能先返回可重试的 `503 processing_busy`。

该整合检查发现并推动修复了 PostgreSQL 的 `cache_nbUnreads` 标识符引用，以及无关配置更新把 `category=NULL` 写成 `0` 的外键失败问题。版本预检通过 PostgreSQL 不随事务回滚的 sequence 记录确认：后项版本过期时，没有执行前项更新。

合同覆盖：

- 1,200 条 ID 跨参数分片，重复 ID、重复已读请求和只重计相关 feed。
- 静默错误、异常、调用者事务中的保存点，以及失败后的连接继续使用。
- staging 日期排序、重叠 ID、失败保留暂存数据、重试、外部事务回滚。
- 大 ID 全局升降序；分页游标文章变为已读后，下一页仍完整。
- 收藏或标签筛选、标签多级排序、标签及 feed 已读/未读转换。
- schema 字段的 NOT NULL、PG search_path 和用户表前缀。
- 旧索引升级、事务回滚及重复运行不重建索引。

## 已有数据库升级

新安装的 SQLite、PostgreSQL 已使用 `entry_feed_read_index(id_feed,is_read,id)`。已有数据库按用户执行：

```sh
php cli/db-upgrade.php --user=alice
```

升级前检查现有索引列；已经是目标结构时直接返回。SQLite 和 PostgreSQL 在事务中替换旧索引，调用者已有事务时使用保存点。失败回滚后保留原结构，重复执行不会反复重建。MySQL 使用 InnoDB 二级索引已有的主键后缀，此命令不改变其索引。

这是显式维护操作，没有放入页面请求或每次抓取。普通事务式建索引可能等待或阻塞数据库写入，大库应在维护时段执行。这里没有使用 `CREATE INDEX CONCURRENTLY`，也不声称无阻塞升级。

## 查询计划复现

```sh
php tests/integration/database/query-plan.php \
  --backend sqlite --entries 100000 --iterations 10 > sqlite-plans.json

FRESHRSS_TEST_PGSQL_DSN='pgsql:host=/path/to/test/socket;dbname=test' \
FRESHRSS_TEST_PGSQL_USER=test \
php tests/integration/database/query-plan.php \
  --backend pgsql --entries 100000 --iterations 10 > pgsql-plans.json
```

也可在临时实例脚本后加 `--query-plans`，一起运行两库计划实验。输出 JSON 包含 SQL、参数、索引定义、返回行数、执行计划和客户端查询均值。它使用 Reader 的 ID 选择子查询，不代表完整 API 响应时间或并发吞吐。

数据包含 100 个 feed，分别构造均匀未读和最旧 1% 未读两种分布。每种分布比较三阶段：

| 阶段 | 索引 | 用途 |
| --- | --- | --- |
| `legacy` | 原二列 `(id_feed,is_read)` | 在隔离 fixture 内恢复原结构 |
| `upgraded` | 调用实际升级方法得到三列 `(id_feed,is_read,id)` | 验证已落地改动 |
| `candidate_global` | 额外加入 `(is_read,id)` | 评估未落地的全局未读候选索引 |

三个阶段返回的文章 ID 必须完全一致。本次 PostgreSQL 人工数据中，均匀分布的 feed 游标查询访问的共享缓冲块从 26 降至 6；SQLite 从二列索引加临时排序变为三列覆盖索引。全局候选索引只在部分数据分布下被 PostgreSQL 采用，因此没有直接加入生产 schema。

这些结果针对生成数据和单个 SQL。尚未测量真实订阅分布、索引增加的写入成本、MySQL 实机行为及三库长期高负载吞吐。
