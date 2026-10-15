# API 调用与资源限制

## 两种认证方式

独立客户端继续使用 `api/greader.php` 或 `api/fever.php`，管理员需启用 `api_enabled`，用户需设置 API 密码。
GReader 登录使用 `POST /api/greader.php/accounts/ClientLogin`，表单字段为 `Email` 和 `Passwd`。
后续请求通过 `Authorization: GoogleLogin auth=<登录响应中的 Auth>` 认证，写请求沿用 GReader 的 token 校验。
不要把密码放入 URL。Fever 使用 POST 表单 `api_key`。这两个接口保留现有协议，未引入新的 token 类型。

Reader JSON API 地址为 `i/?c=reader&a=<action>`，用于同源页面或持有登录会话的客户端。
它要求现有用户会话，所有 POST 都必须包含 `_csrf`；值来自 Reader 页面 bootstrap。
它不接受 GReader token，也不把 API 密码传给前端。所有 DAO 都使用当前登录用户，不接受指定其他用户的请求参数。

## Reader 接口

JSON 响应包含 `version: 1`。错误响应包含字符串 `error`，控制器产生的错误还包含
`errorDetails: {code, status}`。请求解析、认证和 CSRF 虽在控制器之前进行，也使用同一 JSON 错误结构。
未登录或会话失效返回 403 `authentication_required`，已登录但页面校验失效返回 403 `csrf_invalid`；客户端应保留未提交草稿，提示重新登录或刷新页面，不能自动重放写请求。
所有 Reader JSON 响应及错误都带 `Cache-Control: private, no-store`。应用启动失败、反向代理或第三方插件仍可能产生其他格式，调用方应先判断 HTTP 状态和 Content-Type。

| action | 方法 | 输入与结果 |
| --- | --- | --- |
| `capabilities` | GET | 版本、认证要求、请求限制、分页规则、可调用能力及批量操作语义 |
| `schema` | GET | 阅读设置 schema、文章 ID 格式、规则预设、网页解析预设 |
| `feeds` | GET | 当前用户的分类及订阅源，包含未读数和错误标志 |
| `items` | GET | `get=a`、`filter=all`、`limit`、`search`、`cursor`；返回摘要 `items` 和下一页 `next` |
| `article` | GET | `id` 为字符串；返回正文及摘要元数据 |
| `state` | POST | `id` 及 `read` 或 `starred` 布尔值，修改单篇状态 |
| `batch` | POST | `ids` 字符串数组及 `read` 或 `starred` 布尔值；返回去重后的 `ids`、`changed`、`atomic` |
| `categories` | GET / POST | GET 列出分类和订阅；POST `{name}` 新增，`{id,name}` 重命名，`id` 为整数 |
| `feedUpdate` | POST | `{id,name?,categoryId?}`，整数 ID；重命名或移动订阅源，不改 URL、凭据和抓取规则 |
| `feedBatch` | POST | `{ids,changes,expectedVersions}`；对多个订阅源应用同一配置修改，事务整体提交 |
| `subscriptionBatch` | POST | `{items:[{type,id,changes,expectedVersion}]}`；单次修改多个订阅源和分类，每项可有不同修改 |
| `extensionConfig` | GET / POST | 发现、读取或凭 `expectedVersion` 保存声明了 schema 的用户插件设置 |
| `extensionBatch` | POST | 多个插件的配置与预期版本；全部验证通过后一次发布当前用户设置 |
| `export` | GET | 下载当前用户的 OPML，排除敏感 cURL 配置；订阅 URL 本身仍会包含在文件中 |
| `rulePreview` | POST | `{rules: "规则 JSON 字符串", entry: {...}}`；验证规则并预览，不写文章 |
| `extractionPreview` | POST | `{source,recipe,baseUrl?}`；本地解析提供的 HTML/JSON，不访问 URL；返回 `preview.values/formats/errors` |
| `preferences` | GET / POST | GET 设置与 schema；POST `{preferences:{...}}` 合并已验证的阅读设置 |
| `limits` | POST | 管理员修改 Reader 设置上限，输入 `{limits:{...}}` |
| `scheduler` | GET | 仅管理员：队列配置、schema、统计和历史；可用 `history_before` 翻页，不可通过此接口启动 worker |

订阅源创建、删除、完整 OPML 导入等操作继续使用已有 GReader API 或经典界面。
默认分类不能重命名；分类名称冲突、超过分类数量上限返回 409。
名称按 HTML 编码后的数据库字节长度校验，避免底层静默截断。订阅源 ID 或目标分类不属于当前用户时返回 404。

## 搜索与分页

`items` 支持 `get=a`（主列表）、`A`、`s`、`i` 以及 `f_<订阅源 ID>`、`c_<分类 ID>`。
`filter` 为 `all`、`unread` 或 `starred`。`search` 复用 FreshRSS 布尔搜索语法。
`limit` 必须在 Reader schema 给出的范围内，超限返回 400，不静默缩小。
数组形式的流、筛选、游标或搜索参数会明确拒绝，不能被当成空值悄悄重置筛选；结束游标也不会跳过其他参数校验。
搜索超过布尔表达式字节或括号深度限制时，返回 400 `invalid_search`，页面可保留原查询供修改。

第一次请求不传 `cursor`。下一页原样传回 `next`，并保持搜索、筛选与订阅范围不变；`next: null` 表示结束。
按文章 ID 倒序翻页，游标边界严格排除上一页末项。文章 ID 始终使用十进制字符串，避免 JavaScript 数字精度丢失。
这是实时数据分页，不是数据库快照；翻页期间被删除或改变筛选状态的文章可能不再出现，新入库文章在刷新首页后出现。

## 批量状态语义

```js
const response = await fetch('./?c=reader&a=batch', {
  method: 'POST',
  credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ _csrf: csrf, ids: ['1791856235002504'], read: true }),
});
if (!response.ok) throw new Error(`HTTP ${response.status}`);
const result = await response.json();
```

`read` 与 `starred` 必须且只能提供一个，必须是 JSON 布尔值。空数组、整数文章 ID、非法 ID、溢出 ID 都返回 400。
数量限制在去重之前检查，超限返回 413；随后去重，并在任何写入前检查全部 ID 是否存在于当前用户数据库。
任意 ID 不存在则整次请求返回 404，不执行状态修改。

数据库状态及未读计数由 DAO 的事务保护；插件钩子可能在提交后运行，且存在并发删除，因此 API 不承诺涵盖插件外部副作用的整体事务，返回 `atomic: false`。
发生 500 或连接中断后，应重新读取状态；将同一组 ID 设置为同一布尔值可重试，但插件自身的外部副作用未必可重复执行。
`changed` 是数据库影响行数，不保证在不同数据库之间等于“状态发生变化的文章数”，不要用它计算本地未读计数。

## 可配置边界与兼容协议

在 `data/config.php` 的 `limits` 中配置：

```php
'limits' => [
    'api_max_request_bytes' => 1048576,
    'api_max_batch_items' => 1000,
    'api_max_entries' => 1000,
    'api_max_ids' => 10000,
],
```

实际值可从 Reader `capabilities.limits` 查询，bootstrap 的 `apiLimits` 也会提供它们。
`api_max_entries` / `api_max_ids` 约束 GReader 列表页，客户端通过 continuation 获取其余结果。
Reader 页面大小仍由独立的 `reader.max_page_size` 控制。

GReader、Fever 和 Reader 不接受 multipart 上传（返回 415），请使用 JSON 或 URL 编码表单。
请求体超过字节上限返回 413；读取时额外检查一个字节，避免把大请求截断为部分操作。
GReader 重复表单字段逐项读取，每个重复字段列表受 `api_max_batch_items` 限制。
Fever 的 `with_ids`、`feed_ids`、`group_ids` 在分割前检查字节和数量，非法 ID 沿用旧协议忽略规则。
Fever 对严格验证的十进制 ID 构造列表，避免多个筛选列表合用时超出数据库占位符上限。
反向代理请求体上限和 PHP 的 `post_max_size` / `max_input_vars` 仍可能更严格，部署时需与客户端批次大小保持一致。

规则和解析器另有结构、深度、字段、输出等边界，详见其模块文档和 schema 返回的预设。
预览接口只处理请求中提供的内容，不进行远程抓取；实际抓取仍走原有 HTTP/SSRF 校验。

## 独立认证与协议回归

`tests/integration/api/auth.mjs` 会复制指定的 SQLite 测试数据目录，在副本中为 `reader` 和 `readerother`
生成随机 API 密码及 Fever key，启动本地 PHP 服务，结束后停止服务并删除副本。
源目录需包含已有文章的 `reader` 及没有文章的 `readerother`，测试不改源目录中的配置、文章和凭据。

```sh
FRESHRSS_API_FIXTURE=bin/preview-data \
FRESHRSS_PHP_BINARY=bin/php-sqlite/php \
node tests/integration/api/auth.mjs
```

可用 `FRESHRSS_API_TEST_PORT` 修改默认本地端口 18082。PHP 需要运行 FreshRSS API 的扩展以及 PDO SQLite。
测试覆盖 Fever 正确认证、错误及过期 key 映射、两个用户之间的读取/写入隔离、分类和订阅、单条及批量状态、双向分页和多筛选列表；
GReader 覆盖登录、订阅与标签列表、未读计数、continuation、写 token、重复字段及十六进制文章标识。

Fever `links` 沿用已有行为，返回空列表；FreshRSS 尚未实现 Fever 的 Hot Links 排名。
Fever 文章查询沿用每页最多 50 条，通过 `since_id` 或 `max_id` 获取其余文章。

## 机器可读契约和插件配置

`GET ?c=reader&a=schema` 的 `contract` 字段列出所有 Reader 操作、允许的 HTTP 方法、管理员要求、
请求位置及 JSON Schema 2020-12 格式的请求/响应结构。控制器按同一份路由表检查方法，错误方法返回 405 和 `Allow`。
批量条数、页面大小和搜索长度来自当前配置；文章 ID 的格式及十进制最大值与运行时校验共用定义。
`format=freshrss-entry-id` 和 `x-maximumDecimal` 用于保留 64 位整数的字符串精度，普通 JSON Schema 验证器应另外检查该最大值。

`GET ?c=reader&a=extensionConfig` 列出声明配置 schema 的用户插件。
增加 `extension=RuleEngine` 或 `extension=WebExtraction` 可读取单个插件的 `configuration`，包括 `version`、`schema`、`values`、`errors` 和 `redacted`。
标记为 secret 的字段不会出现在 `values` 中，其默认值也不导出。

以下调用合并当前用户的规则插件设置；未提交的字段保留，不会启用或停用插件：

```js
const current = await (await fetch('./?c=reader&a=extensionConfig&extension=RuleEngine')).json();
const response = await fetch('./?c=reader&a=extensionConfig', {
  method: 'POST',
  credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    _csrf: csrf,
    extension: 'RuleEngine',
    expectedVersion: current.configuration.version,
    configuration: { rules_json: JSON.stringify({ version: 1, rules: [] }) },
  }),
});
if (!response.ok) throw new Error(`HTTP ${response.status}`);
const { configuration } = await response.json();
```

未知设置、错误类型、超限值和语义错误返回 400，并在 `fields` 中标出问题字段。
系统插件不可通过这个用户配置接口写入；没有配置 schema 的插件返回 409。
解析插件的字段及边界直接读取其 schema，接口不另行复制一套解析规则。
`rulePreview` 可传布尔值 `explain` 和 `updated`：前者请求有数量上限的解释，后者预览已存在文章更新时的规则行为。

## 批量订阅配置与并发冲突

`feeds` 现在同时返回每个订阅源的 `ttl`（秒）、`enabled`、`priority` 和不透明 `version`；分类也带 `version`。
bootstrap 和 `schema` 中的 `subscription.fields` 提供允许的字段、类型、数值边界、单位和选项。
订阅源可修改 `name`、`categoryId`、`ttl`、`enabled`、`priority`；分类只可修改 `name`。URL、凭据和抓取源码不可经这些接口写入。
TTL 为 0 表示继承用户默认刷新间隔；暂停与恢复刷新通过 `enabled` 表达，服务处理 FreshRSS 原有负 TTL 存储方式。

```js
const catalog = await (await fetch('./?c=reader&a=feeds')).json();
const selected = catalog.feeds.filter(feed => selectedIds.includes(feed.id));
const response = await fetch('./?c=reader&a=feedBatch', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    _csrf: csrf,
    ids: selected.map(feed => feed.id),
    expectedVersions: Object.fromEntries(selected.map(feed => [feed.id, feed.version])),
    changes: { ttl: 3600, enabled: true },
  }),
});
const result = await response.json();
if (!response.ok) throw new Error(result.error);
```

没有传入的字段保留原值。`expectedVersions` 必须覆盖所有目标；重复 ID、未知字段或缺失版本在写入前拒绝。
一个版本过期就返回 409 `configuration_conflict`，`conflicts` 提供该项的当前配置及新版本，整批不写。
调用方应保留用户草稿并展示差异，不能拿新版本直接重放旧修改来掩盖冲突。
阅读状态、未读数和抓取错误不会改变订阅配置版本。

同一用户正在应用抓取结果或修改订阅时，配置接口通过非阻塞锁返回 503 `processing_busy`、`retryable:true` 和 `Retry-After`，不排队占用 Web worker。
旧 `feedUpdate` 和分类写入也使用该锁，但旧单项接口未强制版本前提；需要防止旧快照覆盖时使用新批量接口。

`subscriptionBatch` 的每项使用 `type: "feed"` 或 `"category"`，并分别带 `id`、`expectedVersion`、`changes`。
所有目标的归属、字段和值、分类目标及版本通过检查后，更新在一个数据库事务中执行；数据库约束或后续写入失败会回滚此前更新。
成功返回 `atomic:true`、更新后的 `feeds` 和 `categories`。默认分类不可更名，分类名不能与已有分类或标签冲突；分类名称互换需要另外选择临时名称。
配置数量上限复用 `api_max_batch_items`。客户端分成多次 HTTP 请求时，每一批独立提交，整个多批操作不构成一个事务。

## 多插件一次保存与规则批量草稿

`extensionConfig` 写入必须带最近读取的 `expectedVersion`。
`extensionBatch` 接收 `items: [{extension, configuration, expectedVersion}, ...]`，每个插件只能出现一次。
服务在同一个配置文件锁中重读最新设置，检查全部版本、字段和值，然后只发布一次文件。
未提交的设置及 secret 保留；一个插件的变更不会让另一个插件的版本失效。
错误返回 400 和按插件分组的 `fields`；版本过期返回 409 和按插件分组的 `conflicts`，其中包含当前脱敏配置及版本，整批不写。
成功返回 `{atomic:true,extensions:{RuleEngine:{...},WebExtraction:{...}}}`，可以直接更新页面上的配置快照。

```js
const { configurations } = await (await fetch('./?c=reader&a=extensionConfig')).json();
const byName = Object.fromEntries(configurations.map(item => [item.extension, item]));
const items = [
  { extension: 'RuleEngine', expectedVersion: byName.RuleEngine.version,
    configuration: { hook_priority: 100 } },
  { extension: 'WebExtraction', expectedVersion: byName.WebExtraction.version,
    configuration: { recipes: {} } },
];
const response = await fetch('./?c=reader&a=extensionBatch', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ _csrf: csrf, items }),
});
if (!response.ok) throw new Error(`HTTP ${response.status}`);
```

上例的 `recipes: {}` 会清空该插件的当前用户配方；实际表单应发送用户编辑的值，不应自动套用示例。

批量整理规则时，`rulePreview` 可增加 `operations` 数组：

```json
[
  {"op":"enable","id":"sponsored","enabled":false},
  {"op":"move","id":"releases","before":"sponsored"}
]
```

还支持 `upsert`（带完整 `rule`）和 `remove`（带 `id`）。全部操作及最终规则文档验证通过后，响应提供 `patchedRules` 和预览结果。
该接口只产生草稿，不写设置；用原先读到的插件版本，把 `patchedRules` 作为 `rules_json` 经配置接口保存。
任何一项编辑失败都不会返回部分修改的 `patchedRules`。操作数量显式使用 `api_max_batch_items`，与页面批量操作限制一致。


## 调度历史分页

管理员 `scheduler` 响应的历史记录受 `scheduler.status_history_limit` 限制，默认为 100 条；它与历史保留量 `history_limit` 分开。
把 `status.historyNext` 作为下一次请求的 `history_before`，按记录 ID 降序读取后续页。
`historyNext: null` 表示没有下一页；`status_history_limit: 0` 不返回历史，也不删除已保留记录。
`history_before` 必须为正十进制整数，空值、负数、科学计数法、数组或溢出值返回 400 `invalid_history_cursor`。
该分页入口沿用管理员检查，普通用户不能读取其他用户的队列记录。
