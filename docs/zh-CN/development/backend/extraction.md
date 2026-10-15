# 可配置正文解析

`FreshRSS\Services\Extraction\ExtractionEngine` 根据声明式规则，从调用方提供的 UTF-8 HTML 或 JSON 提取字段。它不会下载网页、执行 JavaScript 或访问外部实体。CSS 使用独立的受限语法编译器 `FreshRSS\Utils\Html\CssSelector`，完整消费输入后生成 XPath；JSON 路径复用现有点路径工具，并对字符串拼接逐段检查输出大小。

## 在抓取流程中使用

在扩展管理中启用 **Web Extraction**，打开其配置页。表单提供文章和 JSON 条目模板，可填写一个或多个订阅源 ID，也可以从订阅源列表多选。分别设置标题、正文、作者和链接的解析方式、选择器及输出格式；同一字段每行一个候选，按顺序回退。作者启用“使用全部匹配项”后，每个匹配节点保留为独立作者，姓名中的逗号不会被拆开。

“应用到草稿”可先检查或撤销批量修改；直接点击“保存规则”也会先应用当前有效编辑。预览只使用草稿，不保存。`*` 表示未单独配置时使用的默认规则，具体订阅源 ID 优先。

高级 JSON 默认折叠，可表示完整接口能力。带有后处理、索引、自定义分隔符、不同解析方式的候选等表单无法完整表示的规则，会标记为高级规则；视觉编辑器不会覆盖这些额外选项。可以修改其他订阅源而保留高级规则，也可以直接展开 JSON 编辑。例如：

```json
{
  "123": {
    "format": "html",
    "fields": {
      "title": [
        {"type": "css", "selector": "h1"},
        {"type": "xpath", "selector": "//h2[1]"}
      ],
      "content": [
        {"type": "css", "selector": "article", "mode": "html"},
        {"type": "css", "selector": "main", "mode": "html"}
      ]
    },
    "remove": ["nav", "aside", ".advertisement"],
    "required": ["content"]
  }
}
```

扩展注册 `EntryBeforeAdd` 和 `EntryBeforeUpdate`，在新文章入库及已有文章更新前处理正文。同一条更新不会先在 `EntryBeforeInsert` 处理一次、随后又处理一次。默认钩子优先级为框架默认值，早于 Rule Engine 的默认优先级 100。

输入是 FreshRSS 已经取得的文章正文，不是任意文章网址的原始页面。原有完整内容抓取、CSS 抓取订阅源、JSON 订阅源的网络流程保持原有行为。经过上游清理后已移除的 `script`、`meta` 等节点，扩展无法恢复；需要从原始网页提取 JSON-LD 或页面元数据时，由调用方把已有的原始 HTML 交给下述工具接口。扩展不会为了寻找这些节点再发起请求。

扩展只写入 `title`、`content`、`author`、`link` 四个字段。文本按 FreshRSS 数据库约定进行 HTML 转义；HTML 正文使用现有安全清理器；相对链接按文章原链接解析，仅接受 HTTP/HTTPS 链接。无效链接会阻止整条文章的派生字段写入。未匹配的可选字段保留原值。存在未恢复的解析错误或缺少 `required` 字段时，整个文章保持原值，并在日志记录不含源正文的错误信息。后续候选成功时允许使用回退结果，先前失败仍会出现在预览诊断中。

本地派生字段不会替换原 RSS 的更新比较标记，因此下一次取得相同 RSS 不会仅因正文清理或标题修正而被误判更新。

规则修改影响随后新增或更新的文章，不自动重写历史文章。

## 本地预览与工具接口

配置页可粘贴 HTML 或 JSON，并填写预览订阅源 ID。点击“预览”仅使用当前表单中的规则解析输入，不保存设置。结果按字段显示文本或 HTML 源代码，并可展开查看各候选的匹配过程；完整 JSON 在详细结果中保留。所有结果均作文本显示，不作为网页执行；即使 HTML 中含有图片、脚本或链接，预览也不会加载这些资源。格式错误会保留输入草稿。保存会检查配置版本；如果其他窗口或 API 已修改规则，当前草稿和预览输入仍保留，并显示最新已保存配置供合并，旧表单不会直接覆盖新设置。

```php
$recipe = FreshRSS\Services\Extraction\ExtractionEngine::presets()['article'];
$result = FreshRSS\Services\Extraction\ExtractionEngine::extract($html, $recipe, 'https://example.org/story');

// 原始文本，或经过安全清理的 HTML，由 formats 区分。
$values = $result['values'];
$formats = $result['formats'];
$errors = $result['errors'];
$diagnostics = $result['diagnostics'];
$success = $result['success'];
```

接口可由 FreshRSS 自动加载器加载。HTML 安全清理使用现有 FreshRSS 系统配置；独立 CLI 调用时按现有 CLI 初始化流程设置上下文。

| 方法 | 返回值 | 用途 |
| --- | --- | --- |
| `validate(array $recipe, bool $checkSelectors = false)` | 字符串错误列表 | 检查结构与类型；设为 `true` 时还检查 CSS/XPath 语法，不访问网络 |
| `extract(string $source, array $recipe, string $baseUrl = '', bool $includeItems = false)` | `values`、`formats`、`errors`、`diagnostics`、`success` | 本地解析；各字段按候选顺序选取首个非空结果 |
| `resolveUrl(string $value, string $baseUrl = '')` | HTTP/HTTPS 绝对 URL | 本地解析相对链接；无效输入抛出 `InvalidArgumentException` |
| `FreshRSS\Utils\Html\CssSelector::toXPath(string $selector)` | XPath 字符串 | 精确编译支持的 CSS 子集；不支持的语法抛出异常 |
| `presets()` | 预定义规则映射 | `article` 提取 HTML 文章及元数据；`json_item` 提取 JSON Feed 风格的单个条目对象 |
| `WebExtractionExtension::validateRecipes(array $recipes)` | 字符串错误列表 | 检查按订阅源 ID 配置的规则映射 |
| `WebExtractionExtension::applyRecipe(FreshRSS_Entry $entry, array $recipe)` | 字符串错误列表 | 在已有条目上应用规则，失败保留原条目 |

`extract()` 遇到某一候选运行错误后，仍会尝试后续候选，并同时返回诊断和取得的字段。调用方应通过 `success` 判断本次规则是否完成：某个字段的错误已被后续非空候选恢复时，结果可以成功，`errors` 仍保留先前错误。没有恢复的候选错误、缺少必需字段或输入错误会令 `success` 为 `false`。Web Extraction 按这一标志决定是否应用，并在真正写入前另行验证链接。

`diagnostics` 按尝试顺序返回 `field`、从 0 开始的 `candidate` 索引、`type`、`status`、`reason`、`matches` 和 `output_bytes`。状态为 `matched`、`empty` 或 `error`；空结果区分无匹配和处理后为空。DOM 的 `matches` 是符合节点选择条件的数量，JSON/JSON-LD 是本次扫描中取得非空标量的数量。诊断不含源文本片段或提取内容。内部条目处理可指定 `includeItems: true`，额外取得按节点保留的 `items`，用于多作者等不能按分隔符拆分的字段；默认预览不重复返回这些项。

## 规则字段

`format` 默认为 `html`。`fields` 是字段名到候选数组的映射；字段名由小写英文字母开头，可包含小写字母、数字和下划线，最多 64 个字符。工具接口支持自定义字段名；插件配置拒绝四个已支持字段以外的名称，避免拼写错误被悄悄忽略。未知选项和显式 `null` 不会被悄悄当作默认值。

| 候选选项 | 说明 |
| --- | --- |
| `type` | HTML 中使用 `css`、`xpath` 或 `jsonld`；JSON 中使用 `json` |
| `selector` | CSS 选择器、返回节点集合的 XPath，或 JSON 点路径 |
| `mode` | 默认 `text`；`html` 输出经过安全清理的 HTML；`attribute` 从匹配元素读取指定属性 |
| `attribute` | `mode: attribute` 时必填，适用于 CSS 和 XPath |
| `all` | 默认 `false`；设为 `true` 时合并同一候选的所有非空结果 |
| `separator` | 多个结果之间的分隔符，默认换行；拼接前计入输出预算，HTML 模式中同样经过安全清理 |
| `index` | 可选的 DOM 匹配索引，从 0 开始；只适用于 CSS/XPath，不能与 `all: true` 同用；越界时尝试下一候选 |
| `unique` | 设为 `true` 时要求 `all: true`，按提取后的原始字符串去重并保留首次出现顺序 |
| `postprocess` | 最多 8 个按顺序执行的文本后处理操作，见下文 |

`remove` 是 CSS 选择器数组，在匹配到的 DOM 节点副本上删除不需要的内容，不改变其他字段看到的源文档。`format: json` 不接受非空 `remove`，JSON 字符串中的 HTML 仍由安全清理器处理。清理表达式每次解析只编译一次；没有清理规则的普通文本提取无需克隆 DOM。`required` 指定必须取得非空结果的字段。

XPath 支持元素、属性及文本节点，例如 `//article`、`//a/@href`、`//h1/text()`。标量表达式如 `string(//h1)` 会返回明确错误，请改用节点表达式。未注册 PHP 扩展函数。

CSS 支持 ASCII 标签名、类、ID、属性存在判断和 `=`、`~=`、`|=`、`^=`、`$=`、`*=` 属性比较，以及后代、直接子元素、相邻和后续兄弟组合符。带引号的属性值保留首尾空格，属性词比较按 HTML 空白分隔；属性的空子串匹配遵循 CSS 语义，不会误选不存在属性的元素。

支持 `:first-child`、`:last-child`、`:first-of-type`、`:last-of-type`，以及正整数形式的 `:nth-child(2)`、`:nth-of-type(2)`。子元素位置独立于类、ID 等筛选条件计算；`of-type` 必须带明确标签名，例如 `p.hot:first-of-type`。`:not()` 可包含复合选择器列表，例如 `:not(.ad.hidden, #banner)`，但不能包含后代或兄弟关系。`:has()` 支持相对选择器，例如 `section:has(> p)`，不支持嵌套 `:has()`。非标准辅助选择器 `:contains("文字")` 匹配节点及其后代文本。

未实现的语法会明确拒绝，包括转义标识符、命名空间、伪元素、动态表单状态伪类、`nth` 公式、属性大小写修饰符和带转义或换行的 CSS 字符串。嵌套伪类最多 8 层。需要超出这一子集的表达式时可使用 XPath；此接口不承诺浏览器的完整 CSS 语法。

JSON 支持 `items[0].title`、`author.name` 等现有点路径和带引号的文字拼接，例如 `"Hello " & author.name`；不实现 JSONPath 的递归下降、过滤表达式或通配符。路径指向数组或对象时，不会自动将其序列化成正文；数字 `0` 会保留为字符串 `"0"`。

JSON-LD 支持页面内 `application/ld+json` 脚本中的对象、顶层数组及一层 `@graph` 数组；按文档顺序查找，不自动按 `@type` 选择某一种实体。一个无效脚本不会遮住后面的有效脚本；全部脚本损坏时返回诊断。不会加载远程 `@context`、展开关联数据或执行脚本。

## 声明式后处理

后处理仅适用于 `text` 和 `attribute` 模式，避免更改 HTML 中的空白语义。每一步都再次检查输出预算。

| 操作 | 作用 |
| --- | --- |
| `{"type":"trim"}` | 去除首尾 ASCII 空白；现有候选默认也会先执行此处理 |
| `{"type":"normalize_whitespace"}` | 把 Unicode 分隔空白、换行和制表符合并为一个普通空格，再去除首尾空格 |
| `{"type":"absolute_url"}` | 使用调用方的 `baseUrl` 解析相对 URL；要求 HTTP/HTTPS 和非空主机名，拒绝控制符、内部空白及反斜杠 |
| `{"type":"date","input_format":"Y-m-d","timezone":"Asia/Shanghai"}` | 按显式 PHP 日期格式解析；未提供的时间字段清零，默认输出 RFC 3339；拒绝无效日历日期和尾随多余文本 |

日期操作可通过 `output_format` 设置 PHP 输出格式，`timezone` 默认为 `UTC`。它不采用自然语言日期猜测。`postprocess` 可以组合，例如：

```json
{
  "type": "css",
  "selector": "a.story",
  "mode": "attribute",
  "attribute": "href",
  "postprocess": [{"type":"trim"}, {"type":"absolute_url"}]
}
```

扩展配置页的预览没有文章链接上下文；预览相对 URL 时，可通过工具或阅读器 `extractionPreview` API 显式传入 `baseUrl`。实际条目钩子使用原文章链接。

## 批量配置与并发保存

`WebExtractionExtension` 通过通用扩展配置接口公开 `recipes` 对象，一次提交可设置多个订阅源。任一规则的结构、字段名、CSS/XPath 语法或订阅源 ID 非法时，整次保存被拒绝，不会先保存前面的有效项。订阅源 ID 必须是当前 PHP 平台可表示的正整数，也可以使用 `*` 默认项。

PHP 调用示例：

```php
$description = FreshRSS\Services\Extensions\ConfigurationService::describe($extension);
$result = FreshRSS\Services\Extensions\ConfigurationService::save($extension, [
    'recipes' => [123 => $articleRecipe, 456 => $otherRecipe],
], $description['version']);
```

保存使用通用服务的文件锁和配置版本检查。发生版本冲突时，返回 `saved: false` 和 `conflict`，其中包括最新 `values` 和 `version`，供调用方合并后重试。经典配置表单使用同一条保存路径，隐藏字段为 `configuration_version`；预览仍不保存任何配置。

## 资源边界与错误

以下是单次调用的默认值，同时也是允许上限；规则可设为更小的正整数。超限字段不会被静默截断。边界均以命名常量集中定义，避免散落在抓取流程中。

| 规则选项 | 默认上限 |
| --- | --- |
| `max_source_bytes` | 8 MiB UTF-8 源文本 |
| `max_output_bytes` | 每个字段 2 MiB，包括分隔符、拼接、安全清理和后处理后的输出 |
| `max_matches` | 每个 DOM 候选最多 1000 个匹配节点；JSON-LD 累计最多 1000 个对象 |

每份规则最多 32 个字段，每字段最多 16 个候选，单个选择器或分隔符最多 2048 字节；`remove`、`required` 各最多 32 项。JSON 最大解码深度为 64。插件配置最多 128 个规则，配置文本最多 1 MiB。诊断最多为 32 × 16 = 512 条候选记录；错误列表最多 64 条、每条最多 256 字节，超过时附带省略提示。

NUL、非法 UTF-8、无效 JSON、错误 XPath、超出 HTML 解析器深度等致命错误均会产生诊断。HTML 使用 `LIBXML_NONET`，不开启外部实体替换，也不开启放宽深度限制的 `LIBXML_PARSEHUGE`。一般的缺失闭合标签由 DOM 解析器恢复；达到解析器资源限制时返回错误，避免把已经丢失内容的文档当成成功结果。
