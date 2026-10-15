# 本地开发与功能说明

本文档介绍基于 FreshRSS 1.30.1 的 Reader、规则、网页解析和刷新队列。目录职责和命名见[代码结构](architecture.md)，各类测试的执行条件见[测试指南](../../../tests/README.md)。

## 从界面开始使用

打开主页 `i/`，登录后直接阅读，无需选择另一套阅读界面。阅读主页支持双栏、列表、卡片布局、32 个 MetaCubeXD 主题和排版设置。工具栏的齿轮打开完整设置页，阅读侧栏保留文章集合和订阅导航；管理、多选、账号与维护工具统一放入设置。主题、布局、密度和阅读风格通过图示选择块修改，选中后在局部预览中比较效果，也可放大查看；与账号设置统一保存，保存成功后才应用到整站。窄屏调整显示方式，不改写已保存的布局偏好。

在“设置 → 订阅与分类”按名称、分类或抓取状态筛选后，可选择订阅批量修改名称、分类、刷新间隔、启停状态和显示范围。切换筛选保留已选目标，Shift 支持连续选择。只修改选入保存范围的字段，页面显示进度、每项结果和未保存的项目。服务端按读取时的配置版本检查冲突；其他页面已经改过的订阅不会被旧表单覆盖。多次 HTTP 请求各自提交，整个跨请求操作不构成一个事务。

| 入口 | 当前能力 | 详细说明 |
| --- | --- | --- |
| 阅读主页 | 阅读、搜索、已读/收藏、完整设置页、订阅筛选与批量配置 | [设置与管理工具](reader/settings.md)、[界面架构](reader/architecture.md)、[屏幕与输入设备适配](reader/responsive.md) |
| MetaCubeXD 主题 | 32 个主题的阅读界面缩略图、局部与放大预览、日夜配色；保存后统一应用 | [主题与外观](reader/themes.md) |
| 信息层级与操作 | 合并工具栏、标题优先、即时搜索、直接批量编辑、减少重复请求 | [阅读界面优化](reader/interactions.md) |
| 设置与管理工具 | 11 类设置；阅读、订阅、过滤、解析、数据、回收、账号、扩展、任务与完整管理表单 | [分类、保存与权限](reader/settings.md) |
| 扩展 → Rule Engine | 预设、组合及数值条件、作用域、动作预览与匹配说明、规则批量草稿 | [过滤规则](backend/rules.md) |
| 扩展 → Web Extraction | 按订阅配置 CSS、XPath、JSON、JSON-LD，候选回退、后处理和解析诊断 | [网页解析](backend/extraction.md) |
| 扩展配置 | 类型与范围校验、脱敏导出、导入、配置版本检查；无自定义模板时显示通用表单 | [插件配置与诊断](backend/extensions.md) |
| Reader API | 文章、订阅及分类、批量配置、多插件原子保存、规则与解析预览 | [接口、认证与限制](backend/api.md) |
| 后台刷新队列 | 并发下载、失败重试、超时恢复、持久暂停/恢复、有界运行历史 | [调度与运行命令](backend/scheduler.md) |
| 网络、存储与数据库 | HTTP 缓存并发、响应大小限制、事务与保存点、原子配置保存、索引升级 | [HTTP](backend/http.md)、[存储](backend/storage.md)、[数据库合同与索引](backend/database.md) |

规则与解析均提供可视化编辑器，支持预设、批量操作、草稿撤销和本地预览；高级 JSON 保留复杂配置，表单不会覆盖不支持的选项。新核心扩展默认关闭，设置按用户保存，不自动重写历史文章。预览仅处理传入文本，不抓取远程页面。

手机正文将上一篇、已读、收藏和下一篇放在底部；列表的翻页与本页已读也共用底部一行。设置页在窄屏通过下拉选择分类，长表单有各自的保存反馈。完整管理工具在设置页内继续原有表单和重新认证流程；工具草稿可以收起后继续编辑。浏览器返回会先关闭当前面板，并恢复筛选、分页和滚动位置。慢请求只显示局部等待；关闭或切换后，旧响应不会重新打开面板。

“存储与回收”读取真实数据库与归档统计，保存保留策略不执行立即清理；“更新与任务”的管理员队列设置和暂停／恢复不启动工作进程。自动 SQLite 备份策略仍由服务器定时任务或 CLI 执行，界面不安装 cron。指定源的文章导出目前每源最多 50 篇，不能作为全部文章备份。

## 配置、并发和隔离

配置沿用 `data/config.php` 与用户配置文件。字段定义由后端提供给界面和 API，错误类型、未知字段及超限值会返回具体错误。插件的敏感字段不出现在配置描述或导出中；未提交的字段保持原值。

- `reader` 控制阅读主页的管理员上限，用户偏好存于 `reader_preferences`。
- `network` 是原有页面的请求等待和未读轮询配置，默认值来自 `FreshRSS\Services\Network\BrowserNetworkConfiguration::schema()`。
- `scheduler` 控制队列、全局/主机/用户并发、超时、退避、任务规划与历史分页。
- `limits.api_*` 控制 API 页数、批次和请求体；`limits.http_*`、`limits.retry_after_*` 控制抓取与源站等待。
- 规则、解析方法和 hook 优先级等使用各插件的配置声明。

订阅与分类批量修改先检查全部目标、归属、字段和版本，再在一个数据库事务中写入。任一版本过期或写入失败，整批不提交；同用户正在应用抓取结果时，配置接口可以返回可重试的繁忙状态。插件批量设置则在同一个文件锁内重读最新配置，全部校验后一次发布，避免覆盖其他插件的修改。

队列支持通过 `per_user` 调整同一用户的下载并发，文章应用、标签和计数提交仍使用用户工作锁。抓取期间若订阅配置被修改，旧响应不会写回新配置。暂停允许在途任务完成，恢复后继续领取；信号处理、租约和子进程自身截止时间分别处理正常退出与异常恢复。

Reader 的摘要列表不读取或解压正文，打开文章后才请求内容；全文搜索仍可能检查正文。正文在无脚本和同源权限的 iframe 中显示，远程图片由用户决定是否加载。缓存按篇数与容量限制，不持久化正文或离线写入队列。PHP 插件仍是受信任的进程内代码，配置校验和浏览器隔离不构成 PHP 权限沙箱。

原有页面的网络配置示例，单位为秒：

```php
'network' => [
    'request_timeout' => 30,
    'slider_timeout' => 15,
    'unread_timeout' => 15,
    'unread_interval' => 120,
    'unread_backoff_max' => 900,
    'unread_jitter' => 10,
    'unread_resume_min_interval' => 15,
],
```

三个 `timeout` 为 0 时关闭对应客户端超时；`unread_interval=0` 关闭自动未读轮询。抖动、退避及恢复冷却各自支持 0。它们不改变服务器抓取周期，也不覆盖 Reader 偏好；浏览器计时器的数值边界由配置校验明确检查。

## 启动与复现

[Docker/development](../../../Docker/development/README.md) 提供独立的本地资源配置，默认仅绑定本地地址：

```sh
docker compose -f Docker/development/compose.yaml up --build -d
```

该配置为应用分配 768 MiB、可选数据库分配 512 MiB，并设置 PHP、工作进程和日志预算。这是起始配置；当前环境未执行 Docker 构建，不能据此推断实际峰值内存或吞吐。

队列默认关闭。设置 `scheduler.enabled=true` 后，原有定时入口转交队列；也可直接执行一次 CLI 运行：

```sh
php cli/refresh-queue.php schema
php cli/refresh-queue.php status
php cli/refresh-queue.php enqueue
php cli/refresh-queue.php run
php cli/refresh-queue.php pause
php cli/refresh-queue.php resume
```

`enqueue` 只同步任务，`run` 执行到期工作；命令结束后不保留调度服务。队列需要 PDO SQLite、PCNTL、`proc_open` 和可写本地数据目录。

常规开发检查：

```sh
composer install
npm ci
composer run-script phpunit
composer run-script phpstan
composer run-script phpcs
npm test
```

浏览器测试需要已启动的独立测试实例和测试账号。缺少账号时部分用例会跳过，跳过不代表通过：

```sh
FRESHRSS_TEST_URL=http://127.0.0.1:18080/i/ \
FRESHRSS_TEST_USER=reader FRESHRSS_TEST_PASSWORD='测试密码' \
FRESHRSS_TEST_OTHER_USER=readerother FRESHRSS_TEST_OTHER_PASSWORD='另一测试密码' \
npm run test:browser
```

后台集成测试自行创建并清理临时数据、本地 HTTP 服务及工作进程：

```sh
python3 tests/integration/refresh/scheduler_test.py --php php
python3 tests/integration/refresh/concurrency_test.py --php php
python3 tests/integration/refresh/control_test.py --php php
python3 tests/integration/refresh/legacy_test.py --php php
```

协议测试使用生成的预览目录作为只读种子，在临时副本中配置凭据，详见 [API 文档](backend/api.md)：

```sh
FRESHRSS_API_FIXTURE=bin/preview-data node tests/integration/api/auth.mjs
```

数据库专项已经使用真实安装 schema 在 SQLite 和本地 PostgreSQL 17.11 上运行事务、分页、索引及并发冲突合同。复现需要相应 PDO 驱动和本地 PostgreSQL 工具；脚本创建临时数据库，不读取现有用户连接：

```sh
python3 tests/integration/database/matrix.py \
  --php bin/php --postgres-bin bin/postgres/bin
```

已有 SQLite/PostgreSQL 用户库的三列索引升级使用 `php cli/db-upgrade.php --user=alice` 显式执行；大库可能等待或阻塞写入，具体行为见 [数据库文档](backend/database.md)。

各专项的复现条件见对应文档；设置页的分类、草稿、账户保存、工具嵌入和权限用例见[设置说明](reader/settings.md#相关实现与检查)。局部查询计划和人工数据耗时不代表整个网站的吞吐。响应式检查使用 Chromium、Firefox 与 WebKit 的界面用例，覆盖窄屏、横屏、放大文字、键盘焦点、慢请求和返回导航；具体范围见[适配说明](reader/responsive.md#自动化范围)。

## 当前限制

MySQL 尚未运行真实实例合同及并发负载测试。SQLite/PostgreSQL 的本地专项不能替代真实订阅分布、长期运行、索引写入成本或生产容量评估。浏览器使用生成数据和视口模拟，未进行真实手机或触控笔硬件测试；WebKit 引擎检查不等同于在 iOS Safari 真机上运行。

网页手动刷新、WebSub 和旧 CLI 不经过队列全局配额。`per_host` 按原始订阅主机分组，不覆盖所有重定向目标或插件自行访问的站点；全文提取仍属于订阅刷新任务。队列未验证 NFS、Windows 或分布式多节点，不能作为多主机共享队列使用。

规则不执行任意正则、PHP 或外部命令；CSS 选择器有明确的支持子集。尚未验证所有第三方插件的兼容性；PHP 插件权限沙箱、自动更新商店及持久离线写入队列尚未实现。
