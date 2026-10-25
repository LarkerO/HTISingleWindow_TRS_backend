# MTR TDesign Starter Vue Next 工作台

基于 Tencent/tdesign-vue-next-starter 0.14.0，源码提交 `74e3a976477ea9792f75c99355b69ff857648855`（MIT，见 LICENSE 与 UPSTREAM.md）。裁剪官方示例页面、mock 登录及图表依赖，保留官方设计变量与字体，按 Starter 的 Vue 3 + TypeScript + Vite + Pinia 结构实现业务布局、导航与明暗主题。

```bash
cd frontend
npm ci
npm run dev   # http://127.0.0.1:3002，API 代理至 8000
npm run build # 类型检查、打包并同步至 backend/public/workbench 和 test.html
```

后端仍单独使用 `php think run --host 127.0.0.1 --port 8000`。构建后打开 `/test.html` 或 `/workbench/index.html`；Hash 路由支持刷新，无需 PHP SPA fallback。开发时须先启动后端。Node >=22.12。

`src/pages/Workbench.vue`：业务页面；`src/components/BusinessField.vue`：TDesign 输入、可搜索选择器、单选组、日期和时间选择；`BusinessButton/BusinessForm/BusinessNotice/BusinessGrants`：TDesign 按钮、表单校验、提醒和复选组；`src/business/ui.ts`：响应式字段模型、TDesign 数据表格、折叠详情、行内按钮及确认／原因对话框；`src/business/workbench.js`：现有接口与业务操作；`src/App.vue`：布局、菜单、主题。

不再使用原生 select、date/time 输入控件或浏览器 confirm/prompt。证件、机构和 TLV 表单使用 TDesign Form 校验；日期传 API 仍为 YYYY-MM-DD，时间仍为 UTC HH:mm。动态表格使用 TDesign Table，数据和身份操作保持原后端规则。业务逻辑通过响应式模型适配，切换页面时保持挂载；没有隐藏的原生控件模拟组件。

API Key 仅在页面内存中，不使用 localStorage、sessionStorage 或示例账号；刷新后重新连接。管理员代出票方及运营方范围请求头、13 位票号、原请求键重试、证件快照、TLV 和清理确认均沿用后端协议。前端菜单不是授权边界，后端继续逐请求鉴权。没有示例登录或 Mock API。

构建只覆盖前端入口与自己拥有的 assets，不删除 PHP 入口或其他静态文件。已发布资源随 backend 仓库提交，不需线上 npm。后续修改请编辑 frontend/src，执行 build 同步，不直接改 public/test.html。

验证：启动 8000 端口后运行 `node tests/frontend-starter.cjs`、`node tests/frontend.cjs`、`node tests/frontend-features.cjs`、`node tests/frontend-passengers.cjs`（从仓库根目录）。Starter 用例覆盖导航、折叠、主题、角色隐藏、密钥清理、刷新及手机布局；其余用例使用原有业务与临时数据清理。

新增 `node tests/frontend-components.cjs` 检查可搜索选择、日期时间面板、单选复选组件、表单校验和取消对话框不发送写请求。

业务 API 统一使用 `src/business/sm3-client.js` 签名，验签成功后才解析响应。需要 HTTPS 或 localhost 安全环境。管理员可在“轨道公里单价”维护各限速档的分/公里单价，保存带版本校验。新票自动提交报价版本，查询后改价需重新查询。

安全与单价浏览器回归：`node tests/frontend-security.cjs`（在后端仓库目录执行）。Windows 映射盘访问 WSL 仓库时，含 PHP 数据夹具的测试可设置 `WSL_BACKEND_PATH` 为 WSL 内后端绝对路径；可用 `PLAYWRIGHT_MODULE_PATH` 指定 Playwright 包目录。
