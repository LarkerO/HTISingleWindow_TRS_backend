# 部署与启动

[文档导航](README.md) · [项目首页](../README.md)

## 环境

- 已验证的后端环境为 PHP 8.4、Composer、MariaDB。
- PHP 需要数据库驱动、mbstring、ZIP 和 OpenSSL；OpenSSL 必须提供 SM3。
- 仓库已包含 `public/workbench/` 静态工作台，运行后端不需要 Node。
- 修改前端或使用格式化工具时，需要 Node >= 22.12 和 npm。

仓库对应本地工作区的 `backend/` 子目录。`world/` 存档和 MTR 模组源码属于外部输入，不随 GitHub 仓库发布。

## 配置

先创建独立的数据库及其访问账户，然后复制配置模板：

```bash
cp .env.example .env
```

编辑 `.env`：

| 配置                                    | 用途                             |
| --------------------------------------- | -------------------------------- |
| `DB_HOST` / `DB_PORT`                   | MariaDB 地址和端口               |
| `DB_NAME` / `DB_USER` / `DB_PASS`       | 数据库名称及凭据                 |
| `TICKETING_API_KEY`                     | 自行设置的管理员密钥             |
| `TICKETING_AGENCY` / `TICKETING_ISSUER` | 初始化默认出票方及其三位票号前缀 |
| `TICKETING_CURRENCY`                    | 计价币种，默认 CNY               |
| `APP_DEBUG`                             | 对外部署保持 false               |

密钥、数据库密码及运行日志不应提交到 Git。正式部署使用 HTTPS；浏览器签名需要 HTTPS 或 localhost 安全环境。

## 安装与启动

在本仓库目录执行：

```bash
composer install
php think ticketing:schema
php think run --host 127.0.0.1 --port 8000
```

打开 [工作台](http://localhost:8000/test.html)，输入管理员密钥连接。根路径也会跳转到工作台。

`ticketing:schema` 可重复执行，会创建表、补齐字段和索引，并初始化默认机构及费率。升级已有环境前先备份数据库；不要将本项目表结构部署到存在不相关同名业务表的数据库。

## 首次使用

1. 在工作台确认或创建启用的运营方与出票方。
2. 按 [导入指南](importing.md) 同步 MTR 目录，试运行并导入一个运营日。
3. 设置实际容量，在“轨道公里单价”中调整演示费率。
4. 查询班次或乘车区间，确认时刻、里程、单价及余票。
5. 对外出票系统按 [接口文档](external-api.md) 和 [签名协议](sm3-auth.md) 接入。

容量、承运方、单价和商业车次号不能仅凭基础 MTR 存档可靠推导。新环境不会自动附带演示存档或运营日。

## 升级已有数据

更新代码后执行：

```bash
composer install
php think ticketing:schema
```

新运动模型需重新导入未出票的运营日。已有客票（包括已退票）的日期拒绝替换时刻表，可用 `mtr:mileage` 仅回填里程与限速分档，详见 [导入指南](importing.md) 和 [计费升级](mileage-fares.md)。原票金额及历史时刻保持原值。

API key 继续可用，调用方须使用当前 HMAC-SM3 协议；旧的仅传 `X-API-Key` 方式已关闭。

## 日期与日志

业务日期和时刻使用 UTC。北京时间为 UTC+8，换算时需同时调整日期。班次列表按运营日过滤，乘车区间按上车站 UTC 发车日期过滤，详见 [接口约定](external-api.md)。

导入诊断位于 `runtime/import-report.json`，目录诊断位于 `runtime/catalog-report.json`，请求指标日志位于 `runtime/requests/`。这些本地运行文件不随仓库发布。
