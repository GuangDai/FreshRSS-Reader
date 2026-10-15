# MetaCubeXD 主题与阅读外观

Reader 的主题选择与 MetaCubeXD 的 32 项选择器一致，以 [MetaCubeXD 提交 `8bbc8f58`](https://github.com/MetaCubeX/metacubexd/blob/8bbc8f58fef71148a94fb5c0ff808f79b057337d/packages/ui/constants/index.ts) 为依据。颜色和控件配置来自其锁定的 [daisyUI 5.7.17](https://github.com/MetaCubeX/metacubexd/blob/8bbc8f58fef71148a94fb5c0ff808f79b057337d/pnpm-lock.yaml)，包含 20 个语义色、圆角、尺寸、边框、深度、噪声和明暗模式，共 29 项原始值。daisyUI 本身另外提供的 Abyss、Caramellatte 和 Silk 不在该选择器中，未加入。

经典界面也新增这 32 个主题，出现在“显示设置”的主题列表中，名称以 `MetaCubeXD ·` 开头。它们共用 Calm 的响应式布局，每个主题包含 LTR、RTL 样式和真实阅读页面的预览图。已选择的经典主题不会因本次新增而改变。

```text
Acid       Aqua       Autumn     Black       Bumblebee  Business   CMYK       Coffee
Corporate  Cupcake    Cyberpunk  Dark        Dim        Dracula    Emerald    Fantasy
Forest     Garden     Halloween  Lemonade    Light      Lo-Fi      Luxury     Night
Nord       Pastel     Retro      Sunset      Synthwave  Valentine  Winter     Wireframe
```

## 选择、预览和保存

点击 Reader 工具栏的齿轮，在“设置 → 外观与排版”选择主题，或展开“浏览全部 32 个主题”查看配色。主题名称、明暗类型和色样随选择更新；导航、列表、表单和隔离正文同步变化。预览不会提交请求，只有点击“保存设置”才写入账号配置。返回阅读会恢复保存的外观，当前页面内再次打开设置可以继续草稿；“撤销修改”还原阅读器偏好。“数据与备份”的阅读器配置导出包含当前草稿，导入后仍需保存；关闭后的延迟导入结果不会覆盖新打开的设置。

取消预览会恢复原正文及其阅读位置。保存失败会保留当前草稿和对应预览，便于重试；无效数字会阻止导出并聚焦需要纠正的字段。更新服务器限制时，以响应到达时的当前草稿适配新范围，避免覆盖等待期间的编辑。

新配置默认使用 Sunset。选择“跟随系统”时，`lightTheme` 默认 Nord，`darkTheme` 默认 Sunset，也可以分别选择其它主题。以上默认值与 [MetaCubeXD 配置](https://github.com/MetaCubeX/metacubexd/blob/8bbc8f58fef71148a94fb5c0ff808f79b057337d/packages/ui/stores/config.ts) 一致。已有 Reader 的 `auto`、`light`、`dark` 及其它有效设置继续保留；旧导出缺少日夜主题字段时补齐默认值。

经典界面的每个 MetaCubeXD 主题使用固定明暗类型，系统切换明暗时仍保留所选配色。经典界面和 Reader 分别保存外观，日夜主题自动切换在 Reader 中设置。

“书页排版”采用本地衬线字体，“高对比”提高辅助文字和分隔线的可见度。这些阅读风格保留所选主题的颜色。正文的字号、行距、宽度和每页数量位于“阅读与操作”，缓存位于“存储与回收”，请求等待和自动计数同步位于“更新与任务”，服务器上限位于“高级与系统”；完整分类和保存范围见[设置说明](settings.md)。

原始主题值保持不变。Reader 对链接、小字、错误提示和主按钮文字另外计算可读颜色，以覆盖阅读区、侧栏和选中项的不同背景。按钮底色与其文字分别取主色和主色文字，避免浅色主按钮上的白字失去对比。系统强制颜色模式使用系统色。正文继续在无脚本、无同源权限的 iframe 内显示，不启用第三方样式。

## 代码位置与检查

- `app/Services/Reader/Preferences.php`：服务端主题选择、默认值和偏好校验。
- `p/scripts/reader/appearance/themes.js`、`p/styles/reader/themes.css`：32 个主题的完整原始配置。
- `p/scripts/reader/appearance/colours.js`：从主题生成可读的界面文字颜色。
- `p/scripts/reader/articles/document.js`：隔离正文的主题、排版和内容清理。
- `p/styles/vendor/daisyui-LICENSE`：所复制主题配置的 MIT 许可证。
- `p/themes/MetaCube-{name}/`：经典界面各主题的注册信息、原始配置、RTL 和预览图。
- `p/themes/MetaCubeXD/common.css`：经典页面共享布局与语义颜色映射。

不需要安装 daisyUI、Tailwind 或新的运行时框架。主题配置与离线上游快照逐字段比较；浏览器检查实际页面、正文、保存与取消、导入与导出、系统明暗切换和触屏横屏。

```sh
npm run test:frontend
npx playwright test --config tests/browser/config/appearance.config.mjs
npx playwright test --config tests/browser/config/default.config.mjs classic/themes.spec.mjs
```

独立设计测试用 PHP 渲染实际 schema 和页面模板，由本地模拟接口提供文章与偏好，不需要登录或修改真实账号。PHP 可通过 `FRESHRSS_TEST_PHP` 指定。

经典主题测试使用 `FRESHRSS_TEST_URL`、`FRESHRSS_TEST_USER`、`FRESHRSS_TEST_PASSWORD` 指向独立测试服务和账号，或通过 `FRESHRSS_TEST_STORAGE_STATE` 提供登录状态。测试加载真实页面和样式，拦截写请求。重新生成经典预览图使用同样的隔离环境运行 `node tests/browser/tools/capture-classic-themes.mjs`。
