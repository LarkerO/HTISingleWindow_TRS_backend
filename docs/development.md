# 开发指南

[文档导航](README.md)

## 项目目录

| 目录                               | 内容                                     |
| ---------------------------------- | ---------------------------------------- |
| `app/mtr/`                         | MessagePack、存档解析、路线与运行时间    |
| `app/service/`                     | 导入、客票、计费、机构和证件等业务       |
| `app/middleware/`                  | 鉴权、角色范围、签名和请求指标           |
| `app/command/`                     | 建表、导入、目录同步、里程回填及按日清理 |
| `route/` / `config/` / `database/` | 路由、配置和表结构                       |
| `frontend/`                        | Vue 工作台源码与开发工具                 |
| `public/workbench/`                | 已构建的工作台静态资源                   |
| `tests/` / `examples/`             | 回归测试与 OTA 示例                      |
| `runtime/`                         | 本地报告、日志和测试产物，不提交         |

更多业务规则见 [实现说明](architecture.md)。

## 前端开发

后端先启动在 8000 端口，再在仓库目录执行：

```bash
npm --prefix frontend ci
npm --prefix frontend run dev
```

开发页面为 [http://127.0.0.1:3002](http://127.0.0.1:3002)，API 代理到后端。Node 要求 >= 22.12。前端采用 Hash 路由，刷新无需配置 PHP SPA fallback。

完成源码修改后执行：

```bash
npm --prefix frontend run build
```

构建先检查 TypeScript，再同步 `public/workbench/` 和 `public/test.html`。发布资源随仓库提交，应修改 `frontend/src/` 后构建，而非直接编辑生成文件。前端技术结构和上游许可见 [前端说明](../frontend/README.md)。

## 代码格式化

Prettier + PHP 插件统一维护 PHP、Vue、JS/TS、CSS/Less、JSON 和 Markdown：

```bash
npm --prefix frontend run format
npm --prefix frontend run format:check
```

也可用 `composer format`、`composer format:check`，或在 `frontend/` 内运行同名 npm 脚本。check 只读检查，不符合规范时退出码非 0。依赖版本由 `frontend/package-lock.json` 锁定。

每行建议 120 字符；PHP 缩进 4 空格，前端 2 空格，统一 LF。PHP 插件以 PHP 8.0 为输出语法目标。根目录 `prettier.config.mjs` 与前端 `.prettierrc.js` 共用规则，编辑器可自动读取；`.editorconfig` 提供通用设置。

`.prettierignore` 排除依赖、运行数据、环境文件、生成资源、混合模板和上游文件；不格式化 `public/`、`vendor/`、`runtime/`、`dist/` 或框架 CLI 入口 `think`。

PHP 与 Node 分装在 Windows/WSL 时，在能访问 npm 的环境运行格式化命令。避免用 Windows Node 通过映射盘构建 WSL 项目，可在 WSL 内使用原生 Node。

## 测试前提

后端测试需要已配置的独立测试数据库及迁移后的表结构；部分测试基于已导入班次。存档相关测试还要求仓库旁有 `../world/mtr/minecraft/overworld` 及对应 ZIP，输入不随 GitHub 发布。

测试会写临时机构、班次、客票或证件，并在完成后回滚或清理；应使用测试环境执行，不以生产数据验证。

## 后端回归

```bash
php tests/sm3.php
node tests/sm3-client.mjs
php tests/fares.php
php tests/integration.php
php tests/http.php
php tests/concurrency.php
php tests/management.php
php tests/passenger-http.php
```

- SM3：请求及响应验签、篡改、防重放、错误和指标日志。
- 计费：分档里程、整数舍入、费率版本、原票快照和幂等。
- 客票：区间库存复用、八进程争抢一个库存、退票和机构隔离。
- 机构、证件和 TLV：角色权限、轮换、版本修改、快照和二进制流。

存档与数据管理回归：

```bash
php tests/motion.php
php tests/routes.php
php tests/zero-trains.php
php tests/multi-sidings.php
php tests/depots.php
php tests/merge.php
php tests/clear-date.php
php tests/features.php
php tests/passengers.php
```

测试日期、端口和夹具以各脚本为准，不代表仓库自带对应运营日或存档。

## 浏览器回归

先启动后端，再在仓库目录执行；需要 Playwright 与可用浏览器：

```bash
node tests/frontend-security.cjs
node tests/frontend-starter.cjs
node tests/frontend.cjs
node tests/frontend-components.cjs
node tests/frontend-features.cjs
node tests/frontend-passengers.cjs
```

可用 `WORKBENCH_URL` 指定页面地址、`BROWSER_CHANNEL` 指定浏览器通道。Windows 映射盘访问 WSL 仓库时，数据夹具测试可用 `WSL_BACKEND_PATH` 指定 WSL 内后端路径；支持该选项的测试可用 `PLAYWRIGHT_MODULE_PATH` 指定 Playwright 包目录。

截图与运行日志保存在 `runtime/`，不提交到仓库。
