# 阅读主页：前后端边界与配置

`i/` 是登录后的默认阅读主页，无需选择单独的 Reader 入口。页面使用独立的 JavaScript 模块与样式，通过 JSON 获取数据，沿用同源登录会话。没有引入新的前端运行时框架，也没有把 API 密码交给浏览器。`reader` 继续作为内部控制器、API 和资源目录名称，不代表另一套供用户切换的阅读界面。

## 代码边界

| 模块 | 职责 | 不承担的工作 |
| --- | --- | --- |
| `app/Controllers/readerController.php` | 会话权限、参数校验、文章/订阅接口、偏好保存、管理员上限 | 不拼文章列表 HTML，不决定卡片布局 |
| `app/Services/Reader/Preferences.php` | 阅读偏好的 schema、默认值、允许范围与输入验证 | 不操作 DOM |
| `app/Services/Reader/EntryPresenter.php` | 文章 ID 校验与摘要输出 | 不保存偏好，不读取正文 |
| `app/Services/Reader/ApiContract.php` / `ApiResponse.php` | API 路由、请求契约与 JSON 响应 | 不操作 DOM |
| `EntryDAO::listSummaries()` | 在数据库层省略正文和正文属性的结果投影，使用现有查询语义 | 不在普通列表请求中取回/解压文章正文；全文搜索条件仍可能访问正文 |
| `p/scripts/reader/core/api.js` | 同源请求、CSRF、可配置超时、取消与协议错误 | 不保存账号口令，不自动重试写操作 |
| `p/scripts/reader/core/store.js` | 分页窗口、异步竞态、正文 LRU 缓存、乐观更新与失败回退 | 不读取主题、字号或 DOM |
| `p/scripts/reader/app.js` / `p/styles/reader/reader.css` | 导航、布局、外观、设置表单、键盘与移动端交互 | 不直接访问数据库 |
| `p/scripts/reader/subscriptions/feed-tree.js` | 按版本增量更新订阅树、保留折叠和筛选状态 | 不在打开正文时重建全部订阅节点 |
| `p/scripts/reader/subscriptions/batch-update.js` / `feed-editor.js` | 多选、字段草稿、带版本的分批保存与逐项结果 | 不自动覆盖其他窗口的新配置 |
| `p/scripts/reader/core/navigation.js` | URL、浏览器返回/前进、面板和列表位置恢复 | 不把正文或认证数据写入历史状态 |
| `p/scripts/reader/articles/document.js` | 惰性处理文章 HTML，在不加载资源的 template 中清理，再生成沙箱文档 | 不执行文章脚本，不把正文插入应用 DOM |

原来的 PHP shell 只传启动配置并加载静态模块。未来可以让反向代理分别托管静态资源和 PHP 接口，同时保持一个域名；本轮没有增加跨域认证或开放 CORS。

## 布局和风格

- 双栏阅读、文章列表、卡片网格三种布局。
- 柔和、纸张、高对比三种风格；浅色、深色和跟随系统。
- 字号、行距、正文最大宽度、侧栏宽度、列表宽度及信息密度。
- 主题、布局、密度和阅读风格使用原生单选按钮组成的图示选择块，附带名称和说明。选中后仅更新局部预览，支持放大查看；点击保存并成功提交后才应用到整站，账号与阅读偏好统一保存。
- 布局与颜色切换复用当前列表，不重新抓取文章。已读/收藏更新不重建 iframe，保留正文滚动位置。
- 窄屏自动以单栏呈现，保存的布局选择不被改写。列表宽度只影响双栏布局，这是显示方式差异，不会限制数据。
- 图片默认关闭，可为当前文章加载，或在账号偏好中允许。嵌入视频、音频及 iframe 以主动打开的新标签页链接呈现，避免默认加载第三方播放器。
- `J/K` 在应用获得焦点时支持上一篇/下一篇，并可跨页；`M`、`S` 切换已读和收藏。列表内可用方向键、Home、End 移动焦点，Enter 打开文章，Escape 关闭后回到原条目。窄屏隐藏侧栏和文章覆盖层后面的控件不参与 Tab 导航。正文 iframe 禁止脚本，焦点进入正文后不会向父页面转发键盘事件。
- “本页标为已读”仅操作当前页的未读条目，按接口公布的数量与字节上限拆分请求并显示进度。批量失败可能已部分写入，因此成功和失败后都会重新读取服务器状态；重试仍针对最初那组文章。离线时暂停状态写入，已加载正文仍可阅读。

手机正文顶部提供关闭和“更多”菜单，底部保留上一篇、已读、收藏、下一篇；正文加载期间也能关闭或切换。图片与原文链接放在“更多”中。设置页和订阅编辑对话框的内容区独立滚动，标题和保存操作保持可见。

订阅侧栏支持多选、Shift 连选、选择当前筛选结果、清空选择和逐字段批量修改。打开编辑框时固定目标 ID 和配置版本，只发送勾选修改的字段；阅读文章或更改侧栏选择不会改变正在提交的目标。TTL 使用秒，0 继承默认间隔，表单同时显示可读时长。

每个批次按服务端公布的数量和 UTF-8 请求字节上限拆分。进度和逐项结果可重新打开，停止只取消尚未发送的批次。遇到版本冲突先保留草稿并展示当前服务器值，用户检查后再保存未完成项目；已经成功或当前值已满足修改的订阅不会重复提交。一次 HTTP 请求内部原子提交，多次请求之间可能部分成功。

URL 保存筛选、搜索和当前文章，返回/前进恢复列表位置与面板。替换文章时创建新的正文 frame，避免 iframe 的 `srcdoc` 导航额外占用浏览器返回历史；已读和收藏操作仍复用当前 frame。

## 单一配置定义

schema 在 `FreshRSS\Services\Reader\Preferences` 中定义，经启动数据下发。前端直接用它生成字段、选项、单位、最小/最大值和默认值；浏览器与 PHP 不各自维护一套参数表。设置 JSON 支持导入导出，只包含外观与性能偏好，不含正文、订阅或凭据。

用户配置保存在 `data/users/<user>/config.php` 的 `reader_preferences` 中。管理员可以在“高级与系统”修改服务器上限，对应 `data/config.php` 的 `reader`；默认值在 `config.default.php`。

写入无效类型、未知字段或超范围值会返回 HTTP 400 和具体字段。Reader 列表请求超出每页上限也会返回允许范围，不静默裁剪。老偏好因管理员调低上限而超范围时，页面显示调整提示和实际生效值。

| 参数 | 默认值 | 范围来源 / 关闭方式 | 实际影响 |
| --- | --- | --- | --- |
| `pageSize` | 50 篇 | 1 至 `reader.max_page_size`，默认上限 200 | 每次列表请求及当前 DOM 数量；不是总阅读数量 |
| `cacheArticles` | 12 篇 | 0 至 `reader.max_cached_articles`，默认上限 200 | 0 关闭正文缓存 |
| `cacheMiB` | 4 MiB | 0 至 `reader.max_cache_mib`，默认上限 64 | 0 关闭正文缓存；按序列化文本长度 × 2 估算，不等于完整浏览器 heap |
| `requestTimeout` | 15 秒 | 0 至 `reader.max_request_timeout`，默认上限 120 | 0 关闭浏览器超时；不会改变 PHP、反代或数据库的限时 |
| `refreshInterval` | 120 秒 | 0 至 `reader.max_refresh_interval`，默认上限 86,400 | 0 仅手动更新；只取订阅计数，不直接抓 RSS |
| 搜索长度 | 上限 1,000 字符 | `reader.max_search_chars` | 输入框显示限制，接口过长返回错误，不截断搜索语句 |
| 设置文件大小 | 上限 16,384 bytes | `reader.max_settings_bytes` | 限制浏览器导入文件，不限制文章长度 |

篇数与容量同时约束缓存，先达到哪个就淘汰最久未使用的条目。超大文章仍然可以打开，只是不放入缓存。打开的正文、iframe、列表元数据和浏览器自身资源不计入这份缓存估算，因此这里不是浏览器进程内存硬上限。侧栏仍随订阅数量增长。

自动更新在上一个计数请求结束后开始计算间隔，隐藏页面和离线时暂停，不会堆积请求。没有额外的隐式退避上限，也不会用阅读字号、分页大小或请求超时来推导刷新频率。服务端 RSS 抓取仍由原有调度器、TTL、HTTP 缓存和 Retry-After 控制。

原有 Reader Comfort 和旧页面计数定时器的配置继续保留兼容，不决定阅读主页的排版和计数同步。Google Reader API 的页大小使用原有 `limits.api_max_entries` / `limits.api_max_ids` 两项，与浏览器阅读界面的 `reader.max_page_size` 分开。

## JSON 接口约定

所有路由位于 `./?c=reader&a=<action>`，控制器返回的 JSON 含 `version: 1`。未登录或会话失效返回 HTTP 403 `authentication_required`，安全令牌失效返回 HTTP 403 `csrf_invalid`，均使用 JSON 错误格式。后者保留阅读数据与草稿、阻止后续写操作并提示刷新，不自动重放请求。返回账号数据的响应带 `Cache-Control: private, no-store`，所有写操作沿用 FreshRSS 的 CSRF 检查。

| action | 方法 | 说明 |
| --- | --- | --- |
| `items` | GET | `get`、`filter`、`search`、`cursor`、`limit`；返回不含正文的 `items` 与 `next` |
| `article` | GET | 按字符串 `id` 读取当前账号的正文，运行现有 `EntryBeforeDisplay` PHP hook |
| `feeds` | GET | 当前账号的分类、订阅与缓存未读计数 |
| `state` | POST | `id` 与一个布尔字段 `read` 或 `starred`；按目标状态设置，重复相同请求可重试 |
| `batch` | POST | `ids` 与一个布尔字段 `read` 或 `starred`；返回已接受的 ID、变更数量和非原子标志；写入前检查所有 ID |
| `feedBatch` | POST | 目标 `ids`、选中字段 `changes` 与每项 `expectedVersions`；409 返回冲突项当前配置，整个请求不写入 |
| `capabilities` | GET | 支持的动作及 API 批量数量、请求字节等上限；相同上限也经启动数据传给前端 |
| `preferences` | GET / POST | 读取或局部更新用户偏好；返回 schema 与生效值 |
| `limits` | POST | 仅管理员更新上限，返回最新 schema 和当前用户的有效偏好 |

文章 ID 始终作为十进制字符串传输，避免 JavaScript Number 精度损失。分页使用接收顺序 ID，并以下一页 `id < cursor` 继续；上一页游标对应文章被标记已读后，不会因无条件跳过第一项而丢掉后一篇。

每个状态写请求只更改一个字段，避免跨两次数据库操作产生部分成功。订阅管理、标签编辑、查询管理及完整管理表单从设置页进入，沿用已有后台能力。

## 隔离与资源生命周期

- 阅读主页不加载旧主题、User JS、User CSS 或旧页面主脚本。依赖旧 DOM 的前端插件需要适配，不能直接假定兼容。
- 后端 PHP 插件仍在原进程内运行；它们是受信任代码，本轮没有提供 PHP 插件沙箱。
- 正文经过清理后放入不带 `allow-scripts`、不带 `allow-same-origin` 的 iframe。opaque origin 阻止访问父页面；独立 CSP 限制资源，链接使用 `noopener noreferrer`。
- 不在 localStorage、IndexedDB 或 Service Worker 中存正文与认证信息。缓存属于当前页面和账号；收到认证错误会清空数据，页面退出时取消读写请求、释放正文和缓存、停止轮询；从保留的页面恢复时重新读取当前列表、订阅和选中文章。已经到达服务器的写操作仍可能完成，中断批次会显示未确认结果，用户检查当前值后才能重新提交。
- 请求代次保证较晚到达的旧响应不能覆盖新筛选、新文章或失效后的会话；会话失效前发出的写请求也不能恢复旧数据。与读请求重叠的状态写入会暂存覆盖值，防止较旧列表覆盖已读/收藏；相关请求结束就释放，不累计保存阅读历史。单条写失败回退本地状态，重试按钮重发同一个目标操作。
- 翻页失败保留当前页与游标，重试成功后再改变上一页历史；更换筛选或搜索后清除旧分页历史。数字设置留空会标出字段并阻止保存，不自动解释成 0。
- 翻页替换列表，DOM 不随累计阅读篇数持续堆积；正文只在打开时请求，当前只保留一个正文 frame。

## 验证

此前已用本地 Chromium、Firefox、SQLite 和生成文章检查三种布局、设置保存、管理员调整范围、跨账号文章读写拒绝、CSRF 拒绝、取消请求、状态更新保持正文 frame，以及窄屏布局。这是已有回归范围，不作为本轮界面改动的验证结果。

恶意正文测试包含脚本、表单、事件属性、第三方图片与 iframe：脚本不执行、无法访问父页面、初始外部请求为 0，明确点击图片按钮后才发起图片请求。

可重复执行的浏览器测试位于 `tests/browser/`，使用独立本地账号，至少准备 25 篇样本文章。测试会改变并恢复偏好与收藏状态，不应指向日常账号或生产实例。

```sh
npm ci
npx playwright install chromium
FRESHRSS_TEST_URL=http://127.0.0.1:18080/i/ \
FRESHRSS_TEST_USER=reader \
FRESHRSS_TEST_PASSWORD='<test-account-password>' \
npm run test:browser
```

运行时没有 Playwright 依赖；它只用于开发测试。PHP/JS 单元测试仍通过 `composer run-script phpunit` 和 `npm run test:frontend` 执行。浏览器测试缺少账号环境变量时会跳过，不能把跳过记为验证通过。

此前在同一批 50 篇生成样本中，元数据接口返回 10,647 bytes，带正文的 Google Reader JSON 返回 54,141 bytes；两种协议结构不同，这个样本仅说明按需取正文减少了首次传输内容，不能外推为整站加速比例。

此前在同一批 5,000 个订阅、100 个分类的本地样本中，连续打开和关闭正文 20 次，侧栏 MutationObserver 记录从 450,000 条降为 0；列表维持 50 张卡片和一个正文 frame。这个测试检查节点复用，不代表本轮界面改动后的浏览器内存或所有设备上的耗时比例。

生命周期回归通过人工派发 `pagehide/pageshow` 检查中断回调和正文恢复；没有据此声称验证了浏览器原生 BFCache 命中。缓存测试连续写入 1,000 篇，检查条目与估算字节双重上限。

尚未验证 PostgreSQL/MySQL 的实际负载、所有第三方插件、真实移动客户端及长时间浏览器 heap 曲线。SQLite 上的交互测试和局部微基准不能替代这些数据。
