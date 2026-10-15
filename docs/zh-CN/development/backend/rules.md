# 可配置文章规则

在「设置 → 扩展」中启用 **Rule Engine**，再打开扩展配置。选择预设后点击「使用预设」，调整匹配内容和动作，再点击「应用到草稿」。也可以新建规则，或在列表中多选启停、排序和删除。打开「用示例文章试一试」，填写标题、正文等字段并点击「校验并预览」。复杂组合和资源限制仍可在折叠的「高级设置 · JSON」中编辑。预览只显示命中的规则 ID 和动作，不保存配置，也不修改文章。点击保存后，规则才会用于后续抓取和更新。

配置保存在当前用户的 `extensions['Rule Engine']['rules_json']` 中，各用户相互独立。默认空规则，不会改变文章。已有文章不会被批量重新处理。

## 配置示例

```json
{
  "version": 1,
  "limits": {"rules": 100, "depth": 16, "nodes": 1000, "text_bytes": 4194304, "sample_values": 1024, "explanation_nodes": 100, "tag_bytes": 2048, "scope_items": 1024, "match_checks": 100000},
  "rules": [
    {
      "id": "release-watch",
      "enabled": true,
      "when": {
        "all": [
          {"field": "title", "op": "contains", "value": "release"},
          {"not": {"field": "title", "op": "contains", "value": "prerelease"}}
        ]
      },
      "actions": {"star": true, "add_tags": ["版本发布"]},
      "stop": false
    }
  ]
}
```

`version` 必须为整数 `1`。规则 ID 必须为非空且不重复的字符串。`enabled` 和 `stop` 是可选布尔值，默认分别为 `true` 和 `false`。未知属性、错误类型、空组合、重复 ID 和超出限制均返回带属性路径的错误；不会静默忽略拼写错误。禁用的规则也会经过校验。

规则依次处理，所有规则都匹配文章原始状态，前一条规则添加的标签不会影响后一条。命中且 `stop` 为 `true` 时停止；未命中的规则不会阻止后续规则。动作取并集，标签去重。

## 条件和动作

| 条目 | 支持内容 |
| --- | --- |
| 字段 | `title` 标题、`author` 作者、`content` 纯文本正文、`url` 文章地址、`tags` 文章分类、`feed_url` 去除凭证的订阅地址、`feed_id` 十进制文本订阅 ID |
| 文本操作 | `contains` 包含、`equals` 等于、`starts_with` 前缀、`ends_with` 后缀，均要求非空字符串 `value` |
| 数值字段 | `content_length` 为正文 Unicode 字符数，省略时按 `content` 各字符串的字符数求和，缺失正文视为 0；`published_at` 为文章发布时间的 Unix 秒数 |
| 数值操作 | `gt` 大于、`gte` 大于等于、`lt` 小于、`lte` 小于等于，仅用于数值字段，`value` 必须为 JSON 整数 |
| 出现次数 | `contains` 可设正整数 `min_matches`，默认 1；统计字面文本在全部字段值中的非重叠出现次数 |
| 空值操作 | `empty` 匹配缺失字段、空字符串和空列表，不接受 `value` |
| 大小写 | 默认按 UTF-8 小写化后比较；`case_sensitive: true` 启用区分大小写 |
| 组合 | `all` 全部成立、`any` 任一成立，值为非空条件列表；`not` 的值为单个条件 |
| 动作 | `read: true` 标记已读；`star: true` 收藏；`add_tags` 添加非空文本标签列表 |

每个条件是一个文本条件或一个组合，不可混用。作者和标签可以是字符串列表，任一元素匹配即可。`empty` 要求整个列表为空或每个元素均为空字符串。字符串按字面匹配，不执行正则表达式、PHP、外部命令或网络请求。数值字段输入为规范十进制整数字符串，拒绝指数、浮点、溢出和有前导零的数值；发布时间可以为负值，正文长度不得为负值。缺失发布时间不会命中数值比较。所有整数必须在当前 PHP 整数范围内。

星标和添加标签仅用于新文章，避免更新文章时重新覆盖用户选择。已读动作也适用于更新文章，并触发现有 `EntryAutoRead` hook。`add_tags` 修改 RSS 文章分类，不创建 FreshRSS 用户标签，也不将现有标签删除。RSS 分类存储以 `#` 和 `,` 分隔，因此添加的分类名称不得包含这两个字符，校验时会明确报错。无取消收藏、删除文章和标记未读动作。

内置预设覆盖赞助文章、正式发布、空正文、短正文、重复推广、指定作者、订阅地址前缀。短正文预设的字符数阈值和重复推广预设的关键词、出现次数可直接修改。预设中的作者和地址需要按实际订阅修改。使用预设会打开参数表单，应用后加入草稿列表，不替换已有规则。组合预设保留原有的全部、任一和排除结构，只调整所选参数。

## 限制和调用接口

`limits` 的各值必须为正整数；省略的值使用示例中的默认值。`rules` 限制规则总数，`depth` 限制条件嵌套层数（根节点为 1），`nodes` 限制整份配置的条件节点总数，`text_bytes` 限制示例字段文本的总 UTF-8 字节数以及单个规则文本值和标签的长度。`sample_values` 限制所有输入字段的字符串总个数，默认 1024；字符串字段计 1 个，列表内每个字符串各计 1 个，包括空字符串。此限制避免空文本列表绕过字节限制后造成大量重复匹配。`tag_bytes` 限制合并后 RSS 分类的实际 HTML 编码存储字节数，默认 2048，可调低但不得超过模型的实际存储容量；单条动作已超限时不能保存配置，多条规则与已有分类合并后超限时，整次规则应用会在修改任何已读、星标或分类状态前拒绝。`explanation_nodes` 限制单次预览最多返回的条件说明数量，默认 100；达到上限只截断说明，不影响规则判定。超限直接报错，不截断文本或列表。管理员应根据订阅规模和服务器内存调整，而不是无限提高限制。

JSON 默认最多 1 MiB，解析结构深度最多 512 层；PHP 调用者可通过 `fromJson` 或 `validate` 的第二个参数调整字节上限。配置界面和示例 JSON 使用默认字节上限。JSON 结构层数与条件树深度不同，过深的 JSON 会先被解析器拒绝。

```php
$errors = FreshRSS\Services\Rules\RuleEngine::validate($json);
$engine = FreshRSS\Services\Rules\RuleEngine::fromJson($json);
$result = $engine->preview([
    'title' => 'Release 2.0',
    'author' => ['Example Author'],
    'tags' => [],
]);
// $result = ['matched' => ['release-watch'],
//            'actions' => ['star' => true, 'add_tags' => ['版本发布']]];
$presets = FreshRSS\Services\Rules\RuleEngine::presets();
```

预览接收已解码的 UTF-8 纯文本，文本字段为字符串或字符串列表，数值字段为规范整数字符串，未知字段与错误类型会被拒绝。实际抓取应用规则时会从 FreshRSS 文章中去除 HTML 标签、解码 HTML 实体，再匹配；输出标签会重新编码后交给文章模型。校验返回首个错误或空列表；`fromJson`、`preview` 和 `apply` 会抛出 `InvalidArgumentException`。可缓存编译后的引擎，在同一批文章中复用。

扩展在 `EntryBeforeAdd` 和 `EntryBeforeUpdate` 阶段应用规则，默认 hook 优先级为 `100`，便于默认优先级的正文解析扩展先完成工作。可通过用户扩展配置的整数 `hook_priority` 调整执行顺序。配置无效或文章文本超限时记录具体错误，该文章保持进入规则引擎前的状态，其他抓取任务继续执行。

## 数值筛选与匹配说明

以下条件筛选发布时间晚于给定时间、正文不超过 200 个 Unicode 字符，且正文中至少两次出现同一关键词的文章。时间使用固定 Unix 秒数，不依赖服务器时区或执行时的当前时间。`case_sensitive` 和 `min_matches` 不适用于数值操作。

```json
{
  "all": [
    {"field": "published_at", "op": "gte", "value": 1704067200},
    {"field": "content_length", "op": "lte", "value": 200},
    {"field": "content", "op": "contains", "value": "subscribe", "min_matches": 2}
  ]
}
```

PHP 调用 `$engine->preview($sample, explain: true)` 可获取额外的 `explanation`。配置页预览默认开启说明。已有调用省略第二个参数时保持原返回结构，不生成说明。

```json
{
  "conditions": [
    {"path": "rules.rules[0].when.all[0]", "op": "gte", "matched": true, "field": "published_at", "actual": 1704153600}
  ],
  "truncated": false
}
```

说明记录实际执行的条件路径、操作和真假结果；数值比较和出现次数条件还给出 `actual`。`all`、`any`、`not` 记录组合结果，仍采用短路求值，未执行的分支不在说明中。说明不包含正文、作者等原始文本。默认预览新文章。使用 `$engine->preview($sample, explain: true, updated: true)` 或配置页的更新文章选项时，返回动作会排除 `star` 和 `add_tags`，`explanation.suppressed_actions` 列出被保护规则排除的动作。匹配记录仍保留。`previewEntry($entry, explain: true)` 从真实文章读取发布时间、解码后的地址和文本，并自动使用文章更新状态，与 `apply` 共用计算过程。

Rule Engine 同时向通用扩展设置接口提供 `rules_json` 和 `hook_priority` 字段声明。接口在保存前执行字段类型、长度检查及完整规则编译校验。优先级默认 100，接受当前 PHP 整数范围，不新增任意范围限制。

## 按订阅和文章状态限定规则

规则可增加 `scope` 对象，避免为多个订阅重复展开相同条件，也能将抓取更新时的已读规则与新文章规则分开维护。

```json
{
  "id": "selected-feed-release",
  "scope": {
    "feed_ids": [12, 18, 25],
    "exclude_feed_ids": [18],
    "states": ["new"]
  },
  "when": {"field": "title", "op": "contains", "value": "release"},
  "actions": {"star": true},
  "stop": true
}
```

`feed_ids` 限定允许的订阅，`exclude_feed_ids` 排除订阅；同时出现时排除优先。订阅 ID 必须为正整数，状态只能为 `new` 或 `updated`。各属性省略即不限制，提供时必须为非空列表。`limits.scope_items` 限制整份配置中所有作用域列表元素的总数，默认 1024，可以显式调整。

作用域在文章条件前判断，不匹配的规则不会执行其条件，也不会因为 `stop` 阻止其他规则。说明中使用 `op: "scope"` 表示作用域判断。预览涉及订阅作用域时，应提供单个规范十进制字符串 `feed_id`；缺失时该订阅作用域不匹配，歧义列表或错误类型会报错。状态使用预览的 `updated` 参数，真实抓取则使用文章模型的更新状态。

## 批量处理的资源控制

实际文章采用按需读取：只读取经过作用域和短路判断后真正需要的字段，同一字段在一次文章评估中只读取、解码一次。仅匹配标题的规则不会加载订阅对象或处理正文，其他订阅的正文规则也不会令本订阅读取无关的大正文。UTF-8 小写化结果按字段缓存；正文长度使用同一份纯文本。

`limits.match_checks` 为每篇文章的共享比较预算，默认 100000，可显式调整。每个实际执行的文本条件按该字段的值数量计费，至少为 1；数值条件和作用域各计 1。预算按可能比较的字段值数量预留，不因是否开启解释、是否提前找到匹配而改变。超限抛出明确错误，并在任何文章状态变更前结束本次应用。

直接 `preview` 会校验调用者提交的全部字段，字节数和值数量限制覆盖整个输入。`previewEntry` 和 `apply` 的相同限制覆盖实际读取的字段；未读取的正文不会因为无关的大小限制阻止标题规则。正文抓取和解析阶段的限制由相应的 HTTP/解析模块负责。

## 批量编辑和并发保存

高级设置中的「批量编辑规则」接收操作列表，点击「应用到草稿」只更新编辑器。整批操作结束后会重新校验完整配置；任一操作或最终规则无效时，编辑器保留原来的草稿，不会保存部分修改。

```json
[
  {"op": "enable", "id": "release-watch", "enabled": false},
  {
    "op": "upsert",
    "rule": {
      "id": "news-watch",
      "when": {"field": "title", "op": "contains", "value": "news"},
      "actions": {"star": true}
    }
  },
  {"op": "move", "id": "news-watch", "before": "release-watch"}
]
```

| 操作 | 行为 |
| --- | --- |
| `upsert` | `rule` 为完整规则；同 ID 替换并保留原位置，新 ID 追加到末尾 |
| `remove` | 删除 `id` 指定的现有规则 |
| `enable` | 将现有规则的 `enabled` 设置为严格布尔值 |
| `move` | 将现有规则移到 `before` 指定规则之前；省略 `before` 或设为 `null` 表示末尾 |

不存在的 ID、未知操作字段、错误类型均报错。移动到自身之前是无操作。规则顺序会影响 `stop`，因此移动操作使用稳定 ID，不依赖调用者记住数组下标。

`FreshRSS\Services\Rules\RuleEngine::patch($baseJson, $operations)` 返回新的 JSON 字符串，不写文件或数据库。第三个参数控制最大操作数，默认 `DEFAULT_PATCH_OPERATIONS` 的 1000；第四个参数控制原文档、编码后操作列表和最终文档的字节上限，默认 1 MiB。结果保留 `limits` 等非规则配置。实现独立放在 `app/Services/Rules/RuleDocument.php`，运行时评估不加载批量编辑逻辑。

```php
$settings = FreshRSS\Services\Extensions\ConfigurationService::describe($extension);
$next = FreshRSS\Services\Rules\RuleEngine::patch($settings['values']['rules_json'], $operations);
$result = FreshRSS\Services\Extensions\ConfigurationService::save(
    $extension,
    ['rules_json' => $next],
    $settings['version']
);
```

保存时必须使用读取待编辑规则时取得的版本。不要在提交前重新获取版本后直接覆盖，否则会绕过并发冲突保护。通用配置服务会在同一文件锁内检查版本并发布；多个扩展一起更新可使用 `saveBatch`，任一配置无效或版本冲突时整批不保存。

经典 Rule Engine 配置页也携带版本。其他窗口或 API 修改后，旧页面保存会保留草稿并展示当前已保存的规则，不覆盖服务器新版本；用户对照后可继续修改草稿或明确再次保存。保存发生文件错误时同样保留草稿并显示具体错误。

## 可视编辑器与高级配置

列表支持多选启用、停用、删除，以及逐条上移、下移。修改只影响草稿，「撤销上一步」可恢复上一次列表操作，点击「保存规则」才写入配置。编辑单条规则时可设置匹配字段、比较方式、内容、动作，以及折叠选项中的订阅范围、文章状态和出现次数。按 Enter 可将表单加入草稿，Escape 关闭表单；在编辑期间直接保存，也会先检查并应用表单内容。

可视编辑器支持单条件，以及最多 8 个可识别条件的组合参数编辑；更复杂的结构或未知选项会明确提示转到高级设置，原始 JSON 不会被删改。规则列表与 JSON 双向同步，列表启停和排序会保留未编辑的条件和顶层限制。配置含有超出 JavaScript 安全整数范围的数值时，只提供原文编辑，避免浏览器数值转换丢失精度。

高级 JSON 在表单编辑期间发生变化时，旧表单不能覆盖新 JSON，必须重新打开规则。预览结果先显示命中的规则数和动作，具体条件路径与错误信息可在详情中查看。
