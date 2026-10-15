# 插件依赖、诊断与文件接口

插件在 FreshRSS 的 PHP 进程内执行，必须来自可信来源。能力声明用于说明用途，不限制插件的 PHP 权限，也不是沙箱。

## 元数据

原有 `metadata.json` 保持兼容，以下字段均可省略：

```json
{
  "name": "ArticleTools",
  "entrypoint": "ArticleTools",
  "type": "user",
  "version": "1.0.0",
  "min_php": "8.1",
  "min_freshrss": "1.25.0",
  "requires": {"ParserLibrary": "1.2.0"},
  "conflicts": ["LegacyArticleTools"],
  "capabilities": ["network", "entry-filter"],
  "configure_assets": true
}
```

`requires` 的键是依赖插件的 `name`，值是最低版本或 `*`。版本比较使用 PHP 的 `version_compare`。不接受 Composer 的 `^`、`~` 或复合约束。依赖必须已启用，或同时出现在明确启用的列表中；管理器不会替用户启用未选择的插件。同时选择时，先初始化依赖。缺失、版本过低、初始化失败和循环依赖都会阻止依赖它的插件启用。

系统插件不能依赖用户插件。用户插件可以依赖已经启用的系统插件。`conflicts` 按双向关系检查：任意一方声明冲突即可阻止共同启用。同一列表同时选择两个冲突插件时，两者都不会启用。

`capabilities` 是非空字符串列表，可通过 `getCapabilities()` 读取。`getRequirements()` 和 `getConflicts()` 分别返回依赖与冲突。`configure_assets` 显式启用配置页面的 `static/configure.css` 和 `static/configure.js` 加载。

## 错误和 hook 观测

`Minz_ExtensionManager::getActivationError($name)` 返回当前不能启用的原因，空字符串表示依赖和冲突预检查通过。它不执行插件的安装或初始化。插件列表显示已发现插件的依赖、冲突和初始化错误。`getLoadErrors()` 返回本次请求的发现和初始化错误；这些错误不删除用户保存的启用偏好。

仅返回 HTML 的附加菜单、导航和登录按钮 hook 会隔离异常，继续执行后续 hook。数据库写入、条目过滤、解析、认证、维护等 hook 仍向调用者抛出异常，防止错误被误当成过滤成功或写入成功。回调已经产生的副作用无法自动回滚。

以下接口可用于扩展测试或诊断代码：

```php
Minz_ExtensionManager::observeHooks(true);
// 执行需要观察的实际抓取、过滤或页面逻辑。
$metrics = Minz_ExtensionManager::getHookMetrics();
$presentationErrors = Minz_ExtensionManager::getHookErrors();
Minz_ExtensionManager::observeHooks(false);
```

观测默认关闭。开启后按 hook 名称记录 `calls`、`failures`、`duration_ns`，仅保留聚合值，不保存条目正文、回调参数或逐次调用记录。数组规模受核心 hook 枚举数量限制；再次调用 `observeHooks()` 会清空指标。可隔离错误每个 hook 每次请求最多记录和写日志一次，消息最多保留 1024 字节。全部状态在管理器重新初始化时清空，不跨用户或请求保留。

## 管理操作与文件边界

管理页面在调用 `install()` 前检查依赖和冲突。当前已启用的依赖方存在时，页面拒绝停用其依赖。移除操作只允许已停用、没有当前启用依赖方、位于第三方扩展目录直接下级的真实目录；核心扩展和目录符号链接不会通过此入口删除。安装、卸载和配置抛出的 PHP 异常会转为失败通知和日志，不会保存成功的启用状态。

`hasFile()`、`getFile()` 和 `mtimeFile()` 拒绝绝对路径、父目录跳转、控制字符和指向扩展用户目录以外的文件符号链接。`saveFile()` 同样检查文件名与真实父目录，拒绝符号链接目标；已有子目录中的相对文件仍可使用。写入失败会抛出 `Minz_ExtensionException`。文件接口用于减少路径错误，不限制可信插件直接调用 PHP 文件函数。

## 声明式用户配置

插件可以覆盖 `getConfigurationSchema()`，为每个字段声明类型、默认值和边界；不声明的旧插件仍使用原配置页。可选的 `validateConfiguration(array $configuration)` 在通用类型检查通过后执行领域校验，返回 `字段名 => 错误说明`，不要把敏感值放入错误消息。

```php
public function getConfigurationSchema(): array {
	return [
		'enabled' => ['type' => 'boolean', 'default' => false],
		'concurrency' => ['type' => 'integer', 'default' => 4, 'min' => 1, 'max' => 16],
		'endpoint' => ['type' => 'string', 'default' => '', 'maxLength' => 4096],
		'token' => ['type' => 'string', 'secret' => true],
	];
}
```

支持 `boolean`、`integer`、`number`、`string`、`array`（列表）和 `object`（关联数组）。PHP 使用空数组表示空对象配置。整数和布尔值不接受数字或布尔字符串；数值不接受无穷大和非数。`required` 要求字段存在，`nullable` 明确允许 `null`，`enum` 使用严格类型比较。数字用 `min/max`，字符串用 `minLength/maxLength`（UTF-8 字节数），集合用 `maxItems`，任意值可用 `maxBytes` 限制 JSON 编码后的体积。数据深度上限由 `MAX_VALUE_DEPTH` 定义。未知字段、无效默认值、不适用的约束以及超过边界的值都会报错，不会静默截断。

`FreshRSS\Services\Extensions\ConfigurationService` 提供统一入口：

- `describe($extension)` 返回 `schema`、带默认值的 `values`、`errors`、`redacted` 和不透明的 `version`。
- `validate($schema, $configuration)` 只校验，不保存；`schemaErrors($schema)` 检查声明本身。
- `save($extension, $configuration, $expectedVersion)` 合并部分配置，检查版本后保存。
- `saveBatch($changes, $maximum)` 一次验证多个插件配置，全部通过后只发布一次用户配置文件。每项为 `extension` 对象、`configuration` 和可选 `expectedVersion`。默认最多 100 个不同插件，可通过参数明确调整。
- `export($extension)` 返回带 `version: 1`、插件 `entrypoint`、`configuration`、`redacted` 的可序列化导出文档。
- `import($extension, $json, $maxBytes, $expectedVersion)` 检查导出格式、插件身份、体积和配置内容，然后走同一保存路径。默认导入文档上限为 1 MiB，可显式调整。

这些配置服务只处理当前用户的用户插件，不接受用户名参数，也不能修改系统插件配置。`secret` 字段的实际值、默认值和枚举值不会出现在描述或导出中；导出的 `redacted` 只列出字段名。部分更新和导入都保留未提交的敏感值，也保留旧插件尚未纳入声明的存储字段。提交这些未声明字段仍会报错。

配置版本使用服务器盐值进行 HMAC，并绑定当前用户、插件身份、插件版本、声明和实际配置。它用于并发编辑检测，不用于证明文件已保存。保存时在文件锁内重新读取最新配置；其他插件和其他用户配置项的修改不会被旧快照覆盖。版本冲突时返回 `conflict`，其中包含最新的脱敏配置与版本；批量冲突放在 `conflicts`。任一配置无效或版本冲突时，整批不写入。实际文件写入失败会抛出 `RuntimeException`，内存仍保留保存前的状态。

没有 `configure.phtml` 的用户插件会自动获得通用表单，包含类型对应的输入控件、错误说明、导入和脱敏导出。敏感输入框始终为空，留空保留旧值；需要显式清空时，通过允许 `null` 的 API 字段或导入文档提交。表单使用现有 CSRF 校验，并携带配置版本，防止旧页面覆盖新配置。Rule Engine 和 Web Extraction 保留各自的领域编辑器，同时使用统一配置声明和版本检查。

## 配置读取与启用结果

类型化配置 getter 每次读取当前 Context，不再保留上一个用户或同一请求中较早的插件配置缓存。切换用户、另一个插件实例保存配置后，已有实例会读取新上下文中的值。

管理页面通过 `installAndEnable()` 先执行依赖预检查、安装和初始化，初始化成功后才保存启用偏好。安装或初始化失败时，管理器恢复它维护的 hook 列表；插件自行执行的数据库迁移、文件写入和其他外部副作用不能由管理器自动回滚。保存启用或停用偏好失败也会报告失败，不再显示成功通知。
