![](https://larkerw-1256088996.cos.ap-shanghai.myqcloud.com/TISW_Title.png)

# MTR 电子客票后端

ThinkPHP 8.1 / PHP 8.4 / MariaDB，附带无构建依赖的 HTML 测试工作台。

## 已完成

- 从单个维度目录或 ZIP 读取 MTR 3.x MessagePack：车站、站台、线路、车库、存车线及运行路径。64 位 ID 用字符串输出，避免 JavaScript 精度丢失。
- 根据 `Siding.generateTimeSegments` 的轨道长度、限速、加减速、停站和折返重建离线图定时间；保存按运营日展开的列车与逐站时刻。
- 按车站区间查询车次；电子客票（13 位票号）、PNR、乘车联、出票机构、承运方、票价及审计记录。
- 出票幂等、区间库存复用、事务锁防超售、退票幂等和库存归还。
- 私有接口鉴权，禁止直接控制器访问。密码及 API key 在 `.env`，不在文档中展示。

这是借鉴电子机票模型的项目内部协议，未宣称通过 IATA 认证或接入航空清算。当前支持一张票一名旅客一个乘车联、多个独立出票机构；金额以最小货币单位整数保存。未接入付款，出票与退票是客票凭证和库存操作，未发生真实扣款或退款。

## 启动

```bash
composer install
php think ticketing:schema
php think run --host 127.0.0.1 --port 8000
```

MariaDB 连接已写入本地 `.env`。数据库应预先存在，建表命令只使用 `CREATE TABLE IF NOT EXISTS`。不要向已有同名业务表的数据库执行本项目建表命令。

在 `.env` 查看 `TICKETING_API_KEY`，请求头传 `X-API-Key`。所有日期、时刻使用 UTC（MTR 源码用系统 epoch 毫秒模 86400000），北京时间为 UTC+8。`date` 查询参数指上车站发车的 UTC 日期，跨午夜不丢失。

## 导入

```bash
# 只解析并生成报告，不写数据库
php think mtr:import ../world/mtr/minecraft/overworld.zip \
  --dimension=minecraft/overworld --date=2026-10-04 --dry-run

# 明确给出本运营日的容量及每相邻站区间票价（分）
php think mtr:import ../world/mtr/minecraft/overworld.zip \
  --dimension=minecraft/overworld --date=2026-10-04 \
  --operator=LOCAL --capacity=100 --fare=100
```

容量 100、每区间 100 分仅为演示配置，不来自 MTR 存档。默认不传容量时容量为 0（不可出票），默认票价 0。每个运营日显式导入；不自动将线路名称当作商业车次号。

同一维度同一运营日可重新导入未出票的时刻表；一旦有客票记录（包括已退票），命令拒绝替换，避免旧票和新版本同时占用同一物理列车。旧版本保留供审计。导入使用 MariaDB 命名锁及列车行锁，整批写入事务；空结果不覆盖已有时刻表。

诊断在 `runtime/import-report.json`，每个入库批次也保存在 `imports.report`。导入允许部分成功，必须查看 warnings；当前并非所有车库均可直接售票。

### 离线数据的边界

ZIP 原样读取，不解压或修改原存档。MTR 文件写入没有 truncate，可能遗留旧尾字节；读取与 Java 一样只消费首个完整 map。

- 实时时刻表：使用车库 `departures` 毫秒表，0 点是 UTC。
- 游戏时间频率：需要 `--world-tick`，含义是该运营日 UTC 0 点的 Minecraft dayTime。以此生成固定相位的计划，不是还原历史实际发车；仅有剥离的 mtr 目录无法确定 Minecraft 时钟和上次实际发车相位。
- 多条可用存车线：真实 MTR 会根据 deployIndex 和车辆是否可用分配，因此离线快照不能保证逐趟物理车辆。使用 `--mapping=路径.json` 显式选定计划存车线，格式 `{"车库ID":"存车线ID"}`；这是计划模板选择。
- 无限循环、手动驾驶、非 TRAIN 模式、缺失已生成路径、站台无车站或重叠车站归属：目前跳过并给出诊断，不凭空生成可售班次。
- 轨道几何及运动按根目录提供的 3.x 源码计算。存档含部分附加模组字段；附加速度规则、信号等待、服务器卡顿、运行中的车辆可用状态及实时晚点不属于当前离线图定算法。
- 座席/容量、运价、承运方和商业车次号不能从基础存档可靠推导。当前导入参数控制容量、票价、承运方；运营方需先在管理页面创建并启用；后续可扩展每车次运价、座席和正式车次号。

## 外部接口对接文档

面向外部出票方、运营方及管理系统的完整接口说明见 [docs/external-api.md](docs/external-api.md)，包含权限、字段、示例、幂等与 TLV 编码。

## API

| 方法 | 路径 | 功能 |
|---|---|---|
| GET | `/health` | 健康检查，无鉴权 |
| GET | `/api/v1/stations?q=广阳&dimension=minecraft/overworld` | 查车站，最多 500 条 |
| GET | `/api/v1/journeys?origin=维度:车站nongID&destination=维度:车站ID&date=2026-10-04` | 区间车次及余票，最多 200 条 |
| GET | `/api/v1/trips/{id}` | 班次逐站时刻 |
| POST | `/api/v1/tickets` | 出票，需要 Idempotency-Key |
| GET | `/api/v1/tickets/{票号}` | 本出票机构的电子客票及乘车联 |
| POST | `/api/v1/tickets/{票号}/refund` | 整票退票，出发前可退 |

出票 JSON：

```json
{"trip_id":123,"origin_seq":0,"destination_seq":3,"passenger":"旅客名称"}
```

`trip_id` 和站序均为 JSON 整数。站序从 0 开始，用车次查询返回的 origin_seq/destination_seq，支持同一站重复经过的明确区间。票价由后端计算，不接受客户端改价。一次请求出票一人。相同机构及 Idempotency-Key 重试返回同一客票；换内容复用同一键返回 409。接口查询余票为即时快照，出票时以事务内库存检查为准。

空 API key 返回 503、错误 key 返回 401、非法参数 422、库存/状态冲突 409、票不存在 404。出票机构由独立 API key 绑定，不能通过请求冒用其他机构。管理员可用 X-Agency-Code 指定代操作出票方。当前退票拒绝已发车客票。

## 验证

```bash
php tests/integration.php
php tests/http.php
php tests/concurrency.php
php tests/management.php
```

集成测试基于真实已导入时刻表，检查解码、逐站时间、出票、幂等冲突、区间复用、防超售、机构隔离、退票和已售日期保护，测试写入整体回滚。HTTP 测试临时启动本地 18764 端口并自动停止，检查鉴权、路由和错误格式。

并发测试创建专用测试班次，启动八个独立 PHP 进程争抢一个库存，验证仅一张票成功并在结束后清理测试记录。

## HTML 测试工作台与机构管理

启动后打开 http://localhost:8000/test.html（根路径也会跳转到工作台）。无需 npm 构建。页面与 API 必须同源，不要直接双击 HTML 用 file:// 打开。

1. 输入 `.env` 的 `TICKETING_API_KEY` 并连接。此密钥现在是管理员密钥，原有调用仍兼容，默认代操作配置的 LOCAL 出票方。
2. 查询与出票：加载车站列表（含保留的历史车站），浏览班次 / 逐站时刻，在已存在的班次上选择站区间再查询和出票。日期默认演示运营日 2026-10-04；当前显示 UTC。
3. 机构管理：新增或编辑运营方、出票方，启用或停用。机构代码及出票方三位票号前缀创建后不可变，前缀不重复，既有客票保留历史身份。
4. 为出票方勾选可销售的运营方，保存授权。新建出票方默认不在任何运营方白名单内；仅当运营方开启白名单时需要显式授权。
5. 生成出票方独立密钥，仅生成响应展示明文，请复制保存。数据库仅存 SHA-256；再次生成旧密钥立即失效。使用机构密钥登录只显示售票功能，不能访问管理接口或冒用其他出票方。
6. 管理员可在连接栏切换代操作出票方。选择会清除当前待出票区间，请重新选择车次。机构管理不删除历史机构，可用停用代替删除。
7. 可为尚未出票的单个班次更改运营方；已有客票（包括已退票）不允许变更。导入新运营方的班次前，先创建启用的运营方，再用 `--operator=代码`。

默认 LOCAL 运营方和出票方已从原配置迁移，原有时刻表保持关联。管理员密钥仅存 `.env`，出票方密钥仅存哈希；HTML 不包含密钥，也不写入 localStorage / sessionStorage。

停用运营方会阻止其新票销售并从普通查询隐藏班次；停用出票方会阻止其密钥访问。撤销销售授权仅阻止新出票，原有票的查票和退票仍可用。管理员可代操作停用机构的既有客票，但新出票仍检查机构启用状态。幂等重试原出票请求可以返回已有客票。

管理 API 使用原管理员 `X-API-Key`：

| 方法 | 路径 | 功能 |
|---|---|---|
| GET | `/api/v1/me` | 当前角色、出票方身份 |
| GET | `/api/v1/trips?date=2026-10-04&operator=LOCAL&page=1` | 分页浏览班次（每页 30 条） |
| GET / POST | `/api/v1/admin/operators` | 列出 / 创建运营方 |
| PUT | `/api/v1/admin/operators/{code}` | 编辑名称、联系方式、启用状态 |
| GET / POST | `/api/v1/admin/agencies` | 列出 / 创建出票方 |
| PUT | `/api/v1/admin/agencies/{code}` | 编辑名称、联系方式、启用状态 |
| PUT | `/api/v1/admin/agencies/{code}/operators` | 设置销售授权，JSON `{"operators":["LOCAL"]}` |
| POST | `/api/v1/admin/agencies/{code}/key` | 生成或轮换出票方密钥 |
| PUT | `/api/v1/admin/trips/{id}/operator` | 改班次运营方，JSON `{"operator_code":"LOCAL"}` |

创建运营方使用 `{"code":"GYRAIL","name":"广阳铁路","contact":"","active":1}`；创建出票方另加 `"issuer_code":"901"`，代码支持 A–Z、0–9（出票方另支持下划线与横线）。编辑用完整表单传 name/contact/active；active 为整数 0/1。

`php think ticketing:schema` 现在也会执行 `database/organizations.sql` 并初始化默认机构，可重复执行，不重置现有机构或授权。

`tests/management.php` 覆盖管理权限、独立密钥、销售授权撤销、票号前缀、跨机构隔离、密钥轮换、停用和 HTTP 出票退票；结束后清理临时数据。前端 `tests/frontend.cjs` 使用 Playwright 和 Edge 验证连接、翻页、区间选择、机构表单与清除密钥；浏览器截图保存在 runtime。运行前端测试前请先启动 8000 端口服务；本机可使用 bundled Node，其他环境需安装 Playwright，可用 BROWSER_CHANNEL 指定已安装的浏览器。

## MTR 车厂筛选与组合班次 ID

班次浏览增加「MTR 车厂」选择，显示当前地图的全部车厂，包括未生成班次和未配置的车厂，可按名称、原始 ID 识别。修改日期、运营方或车厂会自动回到第一页；筛选使用维度 + 车厂 ID，避免不同维度同 ID 混在一起。

「班次 ID 显示」支持两种形式：

- 组合标识：`车厂ID+股道ID+趟次`，API 字段 `trip_code`。
- 原数据库 ID：API 字段 `id`，继续用于出票、查详情和设置运营方。

`run_number` 从 1 开始，在每个运营日、每个车厂及股道内按图定车厂发车顺序编号；分页不重新编号。相同组合标识可以在不同运营日或维度重复，完整识别需结合 `dimension` 与 `service_date`。原始 MTR 车厂、股道 ID 均为字符串，不转换成 JavaScript Number。

现有班次已回填趟次，没有重新导入或修改原主键。新导入自动保存序号。旧版本数据库升级可重复执行 `php think ticketing:schema`：新增 run_number 并仅回填尚未编号的记录，按原始导入批次、维度、运营日、车厂、股道分组；同一存车线路径的首站时间偏移相同，因此以首站发车时间排序等价于原车厂发车顺序。

新增接口：

- `GET /api/v1/depots?date=2026-10-04&operator=LOCAL`：列出全部地图车厂；date/operator 仅控制 trip_count，不隐藏零班次车厂。返回完整车厂设置、股道和规划诊断。
- `GET /api/v1/trips?date=2026-10-04&depot_id=车厂ID&dimension=minecraft/overworld&page=1`：按车厂筛选，可同时传 operator。

班次列表、详情、区间查询及客票中的 trip 对象都返回 `trip_code`、`run_number`。组合标识是额外的显示形式，现有数字主键接口保持兼容。

验证命令 `php tests/depots.php` 检查车厂筛选计数、长 ID 精度、跨页连续编号、查询详情一致性及新计划编号；前端浏览器测试也覆盖车厂选择和两种 ID 显示切换。

## 完整地图车厂目录（已修正）

目录车厂与可售班次现在分别保存。之前只导入了较旧的 overworld.zip，而且车厂下拉由已生成班次反推，会漏掉未生成班次的车厂。当前优先读取解压目录，扫描整个 world/mtr 的六个维度：主世界 148 个车厂、178 条股道、242 个当前车站；其余五个维度没有车厂。旧 ZIP 中的 139 个车厂、177 条股道和 235 个车站是旧快照。

```bash
# 完整扫描所有维度，只同步目录及诊断，不改客票与已入库班次
php think mtr:catalog ../world/mtr

# 单个维度也可以指定
php think mtr:catalog ../world/mtr/minecraft/overworld --dimension=minecraft/overworld

# 从当前目录导入可重建的时刻表，保留匹配且未出票的班次 ID 和单班次运营方/容量/运价
php think mtr:import ../world/mtr/minecraft/overworld \
  --dimension=minecraft/overworld --date=2026-10-04 \
  --capacity=100 --fare=100 --merge
```

已用当前目录更新演示运营日为 2,380 个班次，原 1,900 个班次中 1,420 个仍在当前计划的班次保留了原 ID；不在当前计划的 480 个旧班次停用，新增 960 个班次。没有用户客票受到影响。若运营日已有任何客票（含已退票），merge 与普通替换一样拒绝更新。

`mtr_depots` 保存所有车厂及路线、发车表、频率、交通模式、循环设置和规划原因，`mtr_depot_sidings` 保存所有股道，包括手动或关闭的股道。`catalog-report.json` 记录逐维度完整扫描结果。未配置线路、路径缺失、没有保存的关联股道、多股道待选择、游戏时钟锚点缺失、无限循环等情况仍完整入库和显示，不能把这些车厂误称为已生成可售时刻表。

车厂下拉显示全部 148 个车厂。选择无班次车厂时，页面展示具体诊断，并可展开全部股道和发车配置；日期、运营方筛选只影响班次数量，不使车厂消失。地图中被删除的车厂保留历史记录并标记 present=0，不再显示为当前车厂。

保留旧车站记录供历史时刻表引用，因此数据库站点总数可高于当前地图的 242 个车站。目录扫描不以车站存在为前提，空维度也能完整扫描。目录数据在目录与同名 ZIP 并存时优先使用目录。

测试 `php tests/depots.php` 验证完整车厂名单与原始目录逐 ID 对齐、零班次诊断和筛选；`php tests/merge.php` 验证班次匹配保留原 ID 和配置、补入新班次及停用已删除班次，测试写入回滚。

## max_trains 是零基车辆上限（已修正）

根目录源码 `SidingScreen.java:107` 显示 `getMaxTrains() + 1`；`Siding.java:390` 的生成条件是 `trains.isEmpty() || ...`，空股道仍会生成第一列车。`Depot.java` 的股道选择/发车逻辑没有用 maxTrains 大于零作为条件。因此存档 max_trains=0、unlimited_trains=false 表示最多一列车，不表示关闭或不能按时刻表自动发车。

解析器已删除该错误过滤。API 保留原始 max_trains，并增加 vehicle_limit（有限模式为 max_trains+1，无限模式为 null）；页面按游戏含义显示“车辆上限 1（原始 max_trains=0）”或“无限”。仍使用 max_trains 的原始值存库，避免破坏存档语义。

此次修正新增 591 个来自原始 max_trains=0 股道的图定班次，2026-10-04 演示日现为 2,971 个班次，原先 2,380 个班次保留 ID。容量/票价仍为原演示配置。未生成路径、循环展开、世界时钟或多股道计划问题按各自原因报告，不再归因于零基车辆上限。

`php tests/zero-trains.php` 使用真实有效股道构造 max_trains=0、非无限车辆的回归样本，确认仍生成全部指定发车时刻，并检查改变车辆上限不会充当自动发车开关。API 和浏览器测试也验证原始 0 展示为 1 列车。

## 路线顺序与方向班次（修正）

之前把股道的整个车厂运行周期当作一趟乘客班次，车厂配置「上行 + 回转 + 下行」时会出现头—尾—头，班次名称和 route_ids 也错误地沿用了车厂。现在逐条按配置线路生成乘客班次：每趟只关联一个 route_id，名称来自 Route.name；共享终点允许同时作为上一方向终点和下一方向起点，但两方向的区间库存不重叠。

源码依据为 Depot.generateMainRoute、Siding.generateRoute 和 PathFinder.findPath/appendPath。车厂 route_ids 和线路 platform_ids 均按原数组顺序处理，只合并相邻相同站台 ID。乘客首站的 stop_index 从 1 开始，回库股道在末站之后。用 saved_rail_base_id + stop_index 对齐当前配置；过期、缺站、站序错误的已生成路径拒绝入库并报告，需在 MTR 重新生成路径。零停站时间的站台仍按游戏配置视为通过，不可购票。折返同一站序的重复遍历合并；单条线路显式配置的环线或重复经过站点保留，不按站名全局去重。

所有方向班次的时刻仍相对原车厂发车计算，返回方向不会重置为出库发车时刻。组合班次编号改为每个运营日、车厂、股道内按车厂发车顺序及线路顺序连续编号，每个方向班次有独立标识。旧整周期班次与新方向班次的 key 不同；merge 停用旧班次并新增方向班次，继承匹配旧周期的运营方、容量和运价。已经采用方向班次的再次 merge 保留匹配 ID。任何有关联客票（含已退票）的日期仍拒绝重新导入。

真实存档只读试运行得到 7,030 个方向班次，这与旧周期班次数口径不同；没有自动修改已有运营日。重新导入指定日期才会应用新的路线解析。

## 按指定运营日清空

管理员工作台的「机构管理」内新增「按运营日清空数据」。先选择日期和范围预览数量，再输入同一日期并确认执行。日期指 trips.service_date 的 UTC 运营日，包含所有维度、所有历史导入版本及其跨午夜停站；不按出票日期或停站自然日删除。

- all：当日运行图及其关联客票全部删除。
- tickets：删除当日班次的客票（包括已退票）、乘车联、幂等请求和客票审计，释放库存，保留运行图。
- timetable：删除当日停站、班次、导入批次；如有关联客票则返回 409，须先清客票或使用 all。
- 车站、MTR 地图目录、运营方、出票方、授权和密钥不属于按日数据，均保留。

POST /api/v1/admin/clear-date 仅允许管理员。预览 JSON 为 {"date":"2026-10-04","scope":"all","preview":true}；preview 默认 true。执行 JSON 为 {"date":"2026-10-04","scope":"all","preview":false,"confirm_date":"2026-10-04"}。返回各表受影响数量及 blocked；执行阶段重新校验和计数。删除使用事务、与导入共用的运营日命名锁及与出票/退票共用的行锁，不使用 TRUNCATE 或禁用外键。

命令行默认也仅预览：

```bash
php think ticketing:clear-date --date=2026-10-04 --scope=all
# 永久执行；scope 也可选 tickets 或 timetable
php think ticketing:clear-date --date=2026-10-04 --scope=all --execute
```

新增验证：php tests/routes.php（真实站序及往返、环线、路径过期）和 php tests/clear-date.php（三种范围、跨午夜、历史版本、库存释放、其他日期隔离及回滚）。tests/management.php 也检查管理员预览、删除确认参数及机构权限。


## D/G/C/S 车次号、车厂归属及可选白名单

线路名称先按每个 | 分段，再删除段内非 ASCII 字母、数字字符，统一为大写；采用第一个完全符合 D/G/C/S + 1–16 位数字的段。例如 G2113||服铁沁京局担当G2113 → G2113，G306 滨海北-广阳 → G306，保留 G0104 的前导零。K123、G1A、GCT5 和 G123/G124 等未匹配或含多个号的段不作车次号。按这些规则匹配的线路名（包括 S 前缀）均可参与筛选；不匹配的路线保留原显示和班次 ID。担当说明原文另由 duty 返回，名称中的 | 不再直接拼入主要车次显示。

train_number 存于 trips，只有普通索引，没有唯一约束。同一个运营日可有多个相同车次号，始终用原数据库 id 出票和查详情。工作台结果显示车次号、担当说明、发到时刻和组合标识／数据库 ID；选中后也显示明确的时间与 ID。重新查询清除旧的待出票选择。

- GET /api/v1/trips?date=2026-10-04&train_number=G2113&time_from=08:00&time_to=12:00：日期按运营日，时间窗口按首站发车时刻。
- GET /api/v1/journeys?origin=站ID&destination=站ID&date=2026-10-04&train_number=G2113&time_from=08:00&time_to=12:00：日期及时间窗口按选定上车站的 UTC 发车时刻，支持跨午夜班次。
- train_number 和两个时间参数均可不填；时间使用 HH:MM 或 HH:MM:SS，范围为起始包含、结束不包含。同日结束必须晚于开始。一次查询最多返回 200 个区间，仍可用区间和时间进一步筛查。

「机构管理」新增「车厂归属运营方」。PUT /api/v1/admin/depots/operator 的 JSON 为 {"dimension":"minecraft/overworld","depot_id":"原始字符串ID","operator_code":"LOCAL"}。保存后，该车厂所有路线对应的班次、所有运营日和历史版本统一归属所选运营方，后续导入自动继承，目录同步不清除归属。接口与导入通过车厂归属命名锁协调；已有任何客票（含已退票）的班次不能变更到另一运营方，整批回滚。已归属车厂的班次也不能通过单班次接口改到其他运营方。API 返回 depot_operator_code 和 operator_assignment，工作台车厂信息及班次运营方显示「车厂归属」。同一条 MTR 路线由多个车厂运行时，各班次按发车车厂分别归属。

运营方新增 whitelist_enabled（JSON 整数 0/1）：

- 0：不启用白名单，所有启用的出票方均可为该启用运营方出新票。
- 1：仅允许列表中的启用出票方，空列表拒绝所有新出票。
- 切换模式保留列表；移除名单成员不影响其既有客票的查询、退票或原请求的幂等重试。停用机构仍阻止新销售或机构密钥访问。

新建运营方默认不启用白名单；迁移现有运营方时保留原先必须授权的行为，默认开启。可在运营方表单或「运营方出票白名单」切换。原 agency_operators 表复用为白名单，原按出票方配置接口也继续操作同一列表，仅对白名单开启的运营方限制新出票。

管理员接口：
- GET /api/v1/admin/operators/{code}/agencies：返回 enabled 及带名称、状态的白名单。
- POST 同路径，JSON {"agency_code":"LOCAL"}：添加，重复添加不增加记录。
- DELETE /api/v1/admin/operators/{code}/agencies/{agency}：移除。
- PUT /api/v1/admin/operators/{code}：在原表单 JSON 上增加 whitelist_enabled。

已有环境执行 php think ticketing:schema 即可升级，不必清库。2026-10-04 已按当前目录重新导入 7,030 个方向班次，容量 100、每相邻站区间 100 分；车厂目录仍含完整 148 个车厂。无法计划的无限循环、缺路径、缺世界时钟等车厂继续显示具体诊断。

新增 php tests/features.php 验证真实同号班次、时间段搜索、白名单销售限制、车厂归属与后续导入继承，数据库测试写入回滚。php tests/management.php 验证新增管理员接口。node tests/frontend-features.cjs 使用专用临时机构和车厂验证完整页面操作并自动清理；需先运行 8000 端口服务。



## 全局旅客证件库及运营方客票 TLV

执行 php think ticketing:schema 升级现有数据库。工作台新增「旅客证件库」和「运营方客票 / TLV」两个页面。

全局证件字段：
- document_type：类型，1–32 字符，可使用 PASSPORT、ID_CARD 或自定义类型。
- document_number：号码，1–80 字符，保留前导零；类型和号码去除首尾空格并将 ASCII 字母转为大写。
- issuing_country：证件归属地三字母码，转大写；校验三字母格式，可用 CHN。未接入外部归属地码字典。
- birth_date：出生日期 YYYY-MM-DD，必须是真实有效且不晚于当前日期。
- surname：姓，可省略或传 null／空字符串；given_name：名，必填。各最多 80 字符，完整显示姓名最多 160 字符。
- created_at、updated_at：服务器记录的 UTC 毫秒时间。
- version：修改版本，从 1 开始。更新必须提交当前版本，旧版本返回 409。
- 相同类型、归属地及号码只能有一条全局记录。

管理员和启用出票方可以查询、登记、修改全局证件；每次登记、有效修改在事务中保存修改前后数据、操作角色／机构、原因和时间。reason 为必填的 1–500 字符添加／修改原因。不变的更新不增加版本或日志。接口不提供删除证件或修改审计记录的操作。

GET /api/v1/passenger-documents 支持 document_type、document_number、issuing_country 精确查询，name 姓／名包含查询及 page 分页。GET /api/v1/passenger-documents/{id} 查详情，GET /api/v1/passenger-documents/{id}/history 查分页修改日志。POST 创建及 PUT /api/v1/passenger-documents/{id} 更新使用完整表单：

```json
{"document_type":"PASSPORT","document_number":"E00001234","issuing_country":"CHN","birth_date":"1990-02-03","surname":"","given_name":"旅客名称","reason":"新增证件"}
```

修改时另传 "version":1。时间和 ID 不接受客户端指定；归属地不参与从姓名推断。一个人拥有多种证件时分别登记各证件。

出票可传 document_id，姓名从证件库读取，并在客票保存出票当时的完整证件快照。全局证件后续更正不改历史客票；同一 Idempotency-Key 重试也不会因证件库变化而重复出票或冲突。原只传 passenger 的出票调用继续兼容，原有客票没有自动猜测证件信息。

```json
{"trip_id":123,"origin_seq":0,"destination_seq":1,"document_id":456}
```

工作台可在证件列表选择「用于出票」，或在出票区填写证件库 ID；客票查询会展示证件快照。

### 运营方身份与客票附加信息

管理员 POST /api/v1/admin/operators/{code}/key 创建／轮换运营方独立密钥，响应仅本次返回明文，数据库保存 SHA-256。工作台运营方白名单区增加密钥按钮。运营方密钥只可管理该运营方实际承运客票，不能冒用出票方出／退票、维护全局证件库或修改其他运营方信息；停用运营方后其密钥不能登录。GET /api/v1/me 返回 role=operator 及 operator_code。

管理员使用以下运营方接口时必须传 X-Operator-Code；运营方密钥自动绑定自身运营方，不能指定另一运营方。出票方白名单只影响新票销售，不授予运营方附加信息管理权限。

- GET /api/v1/operator/tickets：分页浏览本运营方承运的客票，可传 date（UTC 班次运营日）。
- GET /api/v1/operator/tickets/{number}：读取本运营方客票、乘车联及证件快照。
- GET /api/v1/operator/tickets/{number}/tlv：分页读本运营方附加信息，include_revoked=1 包含已撤销记录。
- POST 同路径：追加 TLV。
- DELETE /api/v1/operator/tickets/{number}/tlv/{id}：撤销，JSON {"reason":"纠正错误信息"}，保留原值及审计；重复撤销不追加重复日志。
- GET /api/v1/operator/tickets/{number}/tlv/{id}/history：追加／撤销日志。
- GET /api/v1/operator/tickets/{number}/tlv-stream：导出有效记录的 Base64 二进制流，按追加顺序拼接。

### 项目 TLV v1 编码

通用 TLV 的本项目格式采用：标签为 2 字节无符号整数，长度为 4 字节无符号整数，均为 big-endian，随后是 length 个值字节。tag 可为 0–65535，由运营方自行定义其含义。不同运营方的标签和值隔离；同一标签可多次追加，不覆盖历史项。

JSON 值编码支持 utf8、hex、base64。length 可省略由服务器计算；传入时必须是实际值字节数。label 为可选人类可读说明，不参与二进制编码。单项值上限 65536 字节；UTF-8 中文按字节计长，二进制保留 00、FF 等任意字节。Base64 输入使用带标准填充的规范表示。输出返回 length、value、wire_base64 及 active。

```json
{"tag":101,"encoding":"utf8","value":"中文","length":6,"label":"备注"}
```

以上二进制 hex 为 0065 00000006 E4B8ADE69687。无需预先创建标签。TLV 记录追加后保持原值，纠错使用撤销旧项再追加新项；不设置有效期。流接口单次最多 1 MiB，超限可通过分页列表读取。

按运营日清客票时同时删除其 TLV 和 TLV 审计，预览返回 ticket_tlv、ticket_tlv_events 数量；全局证件及证件修改日志保留。已有客票不增加收费或更改库存规则。

验证：
```bash
php tests/passengers.php
php tests/passenger-http.php
node tests/frontend-passengers.cjs
```

测试覆盖证件唯一性、可忽略姓、服务端时间、并发版本、前后值日志、出票快照和幂等、UTF-8／二进制 TLV、重复标签、运营方隔离、密钥轮换、撤销日志及日清理。数据库服务测试写入回滚；HTTP 与浏览器使用专用临时证件／机构／客票并自动清理。


