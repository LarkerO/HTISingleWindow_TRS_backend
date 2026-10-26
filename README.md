![Transit Information Single Window](https://larkerw-1256088996.cos.ap-shanghai.myqcloud.com/TISW_Title.png)

# MTR 电子客票后端

面向 Minecraft Transit Railway（MTR 3.x）的时刻表与电子客票系统，附带 Vue 3 管理工作台。

- 读取 MTR 存档，生成方向班次、逐站时刻与运行里程。
- 查询区间余票，按轨道限速档里程计费，支持出票、退票与审计。
- 管理运营方、出票方、车站字典、旅客证件和客票附加信息。
- 配置可见车站、唯一三字母电报码、中英文站名及行政区，支持按电报码查询与出票。
- 使用 HMAC-SM3 双向认证，返回请求耗时与内存指标。

技术栈：ThinkPHP 8 / PHP 8.4 / MariaDB；前端为 Vue 3 + TDesign。

## 快速启动

准备 PHP、Composer 和 MariaDB，创建数据库。在本仓库目录执行：

```bash
cp .env.example .env
# 编辑 .env：填写数据库连接与 TICKETING_API_KEY
composer install
php think ticketing:schema
php think run --host 127.0.0.1 --port 8000
```

打开 [管理工作台](http://localhost:8000/test.html)。仓库已包含构建后的前端；导入自己的 MTR 存档后即可查询班次。

导入后请在「机构管理 → 车站字典」配置并启用对外车站；未配置站默认隐藏。

详细环境要求、配置与首次导入步骤见 [部署与启动](docs/getting-started.md)。

## 文档

| 需要做什么             | 文档                                                                  |
| ---------------------- | --------------------------------------------------------------------- |
| 浏览全部文档           | [文档导航](docs/README.md)                                            |
| 部署、配置与升级       | [部署与启动](docs/getting-started.md)                                 |
| 导入存档、同步车厂目录 | [MTR 数据导入](docs/importing.md)                                     |
| 使用工作台与日常管理   | [工作台使用](docs/workbench.md)                                       |
| 对接 API / OTA         | [接口文档](docs/external-api.md) · [SM3 认证与指标](docs/sm3-auth.md) |
| 配置车站字典与电报码   | [车站字典](docs/station-dictionary.md)                                |
| 配置公里单价           | [里程计费](docs/mileage-fares.md)                                     |
| 了解算法与业务规则     | [实现说明](docs/architecture.md)                                      |
| 开发、测试与格式化     | [开发指南](docs/development.md)                                       |

存档及 MTR 模组源码不随本仓库发布。运行时间为离线估算；本项目目前处理客票凭证与库存，不包含支付结算。
