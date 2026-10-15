# 阅读界面的信息层级与操作

这次优化围绕阅读、查找和管理订阅展开，沿用 32 个 MetaCubeXD 主题的原始配色配置。界面使用现有 PHP、JavaScript、CSS 和 SVG 图标，没有新增 UI 框架。

## 分组与空间

阅读主页将集合标题、分页数量、搜索、布局切换和刷新放在顶部工具栏。手机按实际显示提供列表、卡片两种快速选择；保存的双栏偏好仍保留，恢复桌面宽度后继续使用。

文章列表先显示标题，再用一行显示来源、作者、日期和收藏状态。作者与来源相同时只显示一次；完整信息保留在正文中。未读通过字重和可访问名称区分，收藏使用与操作栏一致的 SVG。舒适与紧凑密度分别调整行内间距，长标题继续完整换行。

阅读侧栏只显示文章集合、分类和订阅，移除订阅标题旁的管理、多选入口，以及左下角的外观和经典界面操作区。工具栏保留一个齿轮进入设置；订阅筛选、选择数量、批量编辑和完整管理工具集中在“订阅与分类”。正常订阅不展示没有额外信息的彩色圆点，抓取错误仍保留明确警示和读屏文本。

设置页分为 11 类，桌面使用侧栏导航，窄屏使用分类下拉选择；搜索可以按分类、工具说明和已加载字段定位。主题、布局、密度和阅读风格使用带图示与说明的单选块，主题缩略图显示侧栏、文章列表和正文。选中后只更新局部预览，也可放大查看；外观与账户字段统一保存，成功后应用到整站。缓存放入“存储与回收”，计数同步放入“更新与任务”，配置导入导出放入“数据与备份”，服务器上限放入“高级与系统”。原文与图片可以直接操作，图片入口仅在文章有外部图片且尚未允许显示时出现。

经典界面共用 Calm 的新间距：收紧工具栏与日期分组，扩大标题和日期字号，并把手机设置导航融入顶部。大字号下仍保留品牌、44px 触控目标和可见键盘焦点；页面滚动预留固定提交栏的空间。

## 减少重复操作

- 文章搜索在停止输入约 350ms 后查询，Enter 仍可立即提交；中文输入法组词时不发出查询，清空立即恢复列表。切换集合或离开页面会取消待执行的搜索。
- 再次选择当前订阅或阅读分类保留列表位置与正文，不重新请求；重复点击当前文章保留原正文和阅读位置。刷新仍可主动重新获取内容。
- 搜索没有结果时提供“清空搜索”。只有单页时隐藏两个不可用的分页按钮；本页已全部读完且无需分页时收起底部操作区。
- 批量订阅字段支持直接编辑，差异自动选入修改范围，恢复原值自动排除。仍可手动取消或重新包含某项；全成功后清除已完成的修改，避免再次提交相同值。混合值、`0`、`false` 和版本冲突继续按原规则处理。
- Reader 继承账号已有的 `mark_when['article']` 与 `mark_when['site']` 设置，不新增重复开关。正文成功打开后自动标已读；失败或过期的正文不触发。用户手动标未读后，主题变化或收藏操作不会再次自动覆盖。
- 外观与账户字段没有变化时不重复提交。保存失败保留草稿；无效配置仍可定位到错误字段；返回阅读保持已保存的外观和正文位置，再次打开设置可以继续当前页面内的草稿。
- 账户、规则、解析和后台任务直接在对应分类配置；完整 Feed、账号、导入导出和管理员工具继续设置页内的原有表单。工具收起后保留未保存内容，并提供继续编辑入口。
- 保存回收规则、执行文章清理和优化数据库分别操作；调整队列和暂停／恢复只更改配置与领取状态，服务器任务仍负责执行。具体分类、保存范围和权限见[设置说明](settings.md)。

## 设计依据与验证范围

布局借鉴 [Apple HIG Layout](https://developer.apple.com/design/human-interface-guidelines/layout) 的相关信息分组、[Toolbars](https://developer.apple.com/design/human-interface-guidelines/toolbars) 的动作优先级，以及 [Buttons](https://developer.apple.com/design/human-interface-guidelines/buttons) 对清楚文字标签的建议。搜索参考 [Search fields](https://developer.apple.com/design/human-interface-guidelines/search-fields) 的即时响应原则；高级配置的收纳参考 [NNG Progressive Disclosure](https://www.nngroup.com/articles/progressive-disclosure/)。这些是对 Web 工具的借鉴，没有将原生平台单位或组件视为 Web 的实现。

以下是此前阅读布局优化的合成文章场景测量，仅记录当时的布局变化，不表示本轮界面的尺寸或改进结果：

| 场景 | 此前改动前 | 此前改动后 |
| --- | --- | --- |
| 1440px 桌面，文章列表起点 | 164px | 约 71px |
| 320px 手机，文章列表起点 | 204px | 115px |
| 普通单行条目高度 | 123px | 84px |
| 320 × 568，完整可见文章 | 2 篇 | 4 篇 |
| 844 × 390，分页超出视口 | 19px | 0px |
| 再选当前订阅的额外列表请求 | 1 次 | 0 次 |

这些历史数值来自同组本地测试内容，不代表当前所有标题长度、字体和账号内容。已有回归涉及 32 个主题、中文输入法、搜索竞态、自动已读、批量编辑、失败重试、RTL、200% 字号、强制颜色、触控和键盘；本轮改动的检查结果以实际测试输出为准。

```sh
npm test
npx playwright test --config tests/browser/config/reading-interactions.config.mjs \
  --project=desktop --project=firefox --project=small --project=phone --project=landscape
npx playwright test --config tests/browser/config/appearance.config.mjs
npx playwright test --config tests/browser/config/classic-reading.config.mjs \
  --project=chromium --project=firefox
```

Reader 的独立设计与交互测试不需要登录；经典界面测试使用 `FRESHRSS_TEST_URL`、`FRESHRSS_TEST_USER`、`FRESHRSS_TEST_PASSWORD` 指向独立测试服务与账号，拦截写入请求。

WebKit 项目可在安装对应 Playwright 浏览器和系统依赖后单独用 `--project=webkit` 运行。本工作区使用已有兼容版本复核：

```sh
PLAYWRIGHT_BROWSERS_PATH="$PWD/bin/playwright-webkit-compat" \
  npx playwright test --config tests/browser/config/reading-interactions.config.mjs --project=webkit
```
