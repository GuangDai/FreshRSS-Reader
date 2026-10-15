# 代码结构与命名

## 整理方案

本次整理以已有 Reader、订阅管理、规则、解析和刷新功能为范围，先确定职责和依赖，再移动文件、更新引用，最后验证运行入口。FreshRSS 原有目录已有框架约定，继续使用原约定；新增代码按下面的职责分组。

1. 后端采用 `FreshRSS\` 命名空间，与 `app/` 下的相对路径对应。服务放入 `Services/{领域}/`，数据库对象放入 `Models/{领域}/`，队列存储和进程锁放入 `Infrastructure/`，异常放入 `Exceptions/{领域}/`。仅处理格式、协议或选择器转换的工具保留在 `Utils/`。
2. 将规则样本独立为一个类文件；将阅读偏好与文章输出、API 响应与 API 契约分开，消除隐藏的加载先后依赖和混合职责。
3. Reader 前端按 `core/`、`articles/`、`subscriptions/`、`appearance/`、`settings/`、`embed/` 分组，入口保留 `app.js`。Reader 样式统一放入 `p/styles/reader/`。
4. PHP 测试对应后端目录；前端与浏览器测试区分 Reader、经典界面和扩展。浏览器配置、公共 fixture 和辅助工具独立放置；集成测试按 `refresh/`、`database/`、`api/` 分组。
5. 本地部署配置使用 `Docker/development/`，中文开发文档集中在 `docs/zh-CN/development/`。同步开发命令、CI 和所有受版本管理的路径引用。

目录迁移保持现有路由、配置键、数据库字段、扩展标识、Compose 项目名和数据卷名。现有工作区改动继续保留，不覆盖已有功能。

## 目录导航

```text
app/
├── Controllers/                 # HTTP 入口、认证、请求与响应
├── Services/                    # 按领域组织的业务操作与配置
│   ├── Reader/                  # 阅读偏好、设置、摘要、API 契约与响应
│   ├── Subscriptions/           # 订阅与分类的名称校验、配置批量修改
│   ├── Extensions/              # 扩展配置、版本与批量接口
│   ├── Rules/                   # 规则编译、评估与文档编辑
│   ├── Extraction/              # 从给定 HTML/JSON 提取字段
│   ├── Refresh/                 # 刷新任务调度与配置
│   └── Network/                 # 浏览器请求和轮询配置
├── Models/
│   ├── Subscriptions/           # 配置持久化、归属与版本检查
│   └── Rules/                   # 经校验的规则输入样本
├── Infrastructure/
│   ├── Concurrency/             # 用户工作锁与刷新锁
│   └── Refresh/                 # SQLite 队列、租约与运行历史
├── Exceptions/{Reader,Rules}/   # 对应领域的异常
├── Utils/{Http,Html}/           # 协议输入、嵌入策略与选择器转换
├── views/                       # 沿用控制器对应的模板目录
├── layout/                      # 经典界面公共布局
└── i18n/                        # 翻译

p/
├── scripts/reader/
│   ├── app.js                   # 组装界面与模块的唯一主入口
│   ├── core/                    # API、状态、偏好、导航与翻译
│   ├── articles/                # 安全正文文档与主题
│   ├── subscriptions/           # 订阅树、选择、批次与编辑器
│   ├── appearance/              # 主题与颜色对比
│   ├── settings/                # 设置分类、账户、备份与配置
│   └── embed/                   # 经典设置表单的嵌入脚本
├── styles/reader/               # reader.css、themes.css 与设置样式
└── themes/                      # 沿用主题注册和资源目录

tests/
├── app/                         # 对应 app/ 的 PHP 测试
├── lib/                         # 框架与核心扩展测试
├── cli/                         # CLI 解析与配置测试
├── frontend/{reader,classic}/   # Node 模块和经典脚本测试
├── fixtures/themes/             # 共用的固定主题数据
├── browser/                     # 按页面领域组织的 Playwright 用例
└── integration/{refresh,database,api}/

Docker/development/              # 隔离的本地部署配置
docs/zh-CN/development/
├── architecture.md              # 本文：目录、职责与命名
├── reader/                      # 阅读器界面与交互
└── backend/                     # 接口、持久化、任务、规则与解析
```

图中省略了未变动的 FreshRSS 文件。原有 `Models/*.php`、`Services/ImportService.php`、`Services/ExportService.php` 和 `Utils/*Util.php` 继续按框架约定加载；`lib/` 中的 Minz 与第三方库保持各自目录。`data/` 属于实例运行数据，`bin/`、`vendor/`、`node_modules/` 属于本地工具、产物或依赖，源码不依赖其临时预览文件。

## 职责与依赖

请求从控制器进入领域服务，由服务调用模型和基础设施。控制器处理认证、CSRF、参数入口和 HTTP 结果；服务处理跨对象操作及配置规则；DAO 处理数据库持久化与事务；基础设施处理队列和进程锁。纯格式转换工具不读取账户或启动任务。

Reader 的 `Preferences` 只定义和校验阅读偏好；`EntryPresenter` 处理文章 ID 与摘要；`ApiContract` 定义路由和请求约束；`ApiResponse` 生成响应。`Rules/RuleEngine` 评估规则，`Rules/RuleDocument` 修改规则文档，`Models/Rules/RuleSample` 保存编译谓词共享的样本。

新增 PHP 类通过 [`lib/lib_rss.php`](../../../lib/lib_rss.php) 的自动加载器解析，`FreshRSS\Services\Reader\SettingsService` 对应 `app/Services/Reader/SettingsService.php`。调用方使用 `use` 导入类，无需直接加载服务文件。独立 CLI 和测试子进程先加载常量及自动加载入口。

Reader 继续使用浏览器原生 ES module。`app.js` 组装功能模块，模块之间使用明确的相对导入。嵌入脚本保留原有 `defer` 执行方式；样式相对于自身位置引用主题图标。公共 URL、文件修改时间和测试中的静态资源引用须随文件移动一起修改。

## 命名规则

- PHP 新增命名空间目录与类使用 `PascalCase`，文件名与类名一致，每个文件定义一个主要类。用 `SettingsService`、`ConfigurationDAO`、`ApiContract` 等名称直接表达职责。
- 既有 `FreshRSS_*`、`Minz_*` 类沿用框架加载规则。控制器仍为 `app/Controllers/{name}Controller.php`，视图仍按控制器组织。
- JavaScript、CSS 和测试目录使用小写单词，多个单词用连字符连接；Python 文件使用 `snake_case`。测试保留 `.test.mjs`、`.spec.mjs` 和 `*Test.php` 的发现约定。
- 同一领域内避免重复领域前缀，例如 `settings/account.js`、`Services/Reader/SettingsService.php`。
- 不用 `design`、`refinement` 或 `enhanced` 作为新增测试和代码的职责名称；使用 `appearance`、`reading-interactions`、`development` 等可查找的名称。

## 验证范围

目录整理重点验证自动加载、直接文件引用、PHP 子进程、ES module 导入、CSS 图标地址、页面资产 URL 和测试发现范围。执行 PHP 单元测试及静态检查、Node 前端测试、浏览器用例发现与自包含界面用例、刷新集成测试和可用数据库合同。检查文档链接与开发命令是否指向真实文件。

测试结果须区分实际通过、因环境缺少依赖而未执行和已有失败。Docker 在当前环境不可用时，不声称已完成容器构建验证。

开发与复现命令见[测试指南](../../../tests/README.md)、[本地部署说明](../../../Docker/development/README.md)和[功能入口](README.md)。
