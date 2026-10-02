# MTR 电子客票系统外部接口对接文档

- 接口版本：`/api/v1`
- 文档版本：1.0
- 更新日期：2026-10-03（北京时间）
- 适用范围：外部出票系统、运营方客票管理系统及受授权的管理系统
- 本文按当前已实现接口编写。示例中的机构、车次、证件、票号和密钥均为占位或虚构数据，不能直接作为有效业务数据使用。

## 目录

1. [基本约定](#1-基本约定)
2. [认证与权限](#2-认证与权限)
3. [推荐对接流程](#3-推荐对接流程)
4. [车站、车厂与班次查询](#4-车站车厂与班次查询)
5. [全局旅客证件库](#5-全局旅客证件库)
6. [出票、查询与退票](#6-出票查询与退票)
7. [运营方客票与 TLV](#7-运营方客票与-tlv)
8. [错误与重试](#8-错误与重试)
9. [联调验收清单](#9-联调验收清单)
10. [附录：管理员接口](#10-附录管理员接口)

## 1. 基本约定

### 1.1 地址与请求格式

服务方提供部署地址，以下使用 `BASE_URL` 表示。例如本地联调地址为 `http://localhost:8000`，正式部署地址由服务方另行交付。

接口采用 HTTP + JSON，JSON 文本编码为 UTF-8。POST、PUT 和带请求体的 DELETE 使用：

```http
X-API-Key: <服务方分配的密钥>
Content-Type: application/json
Accept: application/json
```

查询参数应进行 URL 编码；路径中的票号、证件 ID、机构代码用实际值替换。业务接口均需要认证，`GET /health` 除外。

### 1.2 响应格式

单项成功响应：

```json
{"data": {"id": 123}}
```

分页成功响应：

```json
{"data": [], "total": 0, "page": 1, "page_size": 30}
```

错误响应：

```json
{"error": "Document changed; reload current version"}
```

创建证件、出票、追加 TLV、创建机构成功返回 HTTP 201；其他成功操作通常返回 200。出票幂等重试也返回 201，不能仅凭 201 判断是否新建了一张票。错误以 HTTP 状态码为准，`error` 为说明文本，当前没有独立的机器错误码字段。

响应可包含额外字段。客户端应忽略不认识的字段，不依赖 JSON 属性顺序。本文的部分响应示例仅展示核心字段；涉及完整性要求的地方会明确说明。

### 1.3 日期、时刻与金额

| 内容 | 约定 |
|---|---|
| 日期 | `YYYY-MM-DD` |
| 时刻过滤 | `HH:MM` 或 `HH:MM:SS`，UTC |
| 数据库时间响应 | UTC 的 `YYYY-MM-DD HH:MM:SS` 或带三位毫秒的 `YYYY-MM-DD HH:MM:SS.SSS`；字符串本身没有时区后缀 |
| 金额 | `amount_minor` 为最小货币单位整数，例如 CNY 的 100 表示 1 元 |
| 时间区间 | 起始包含、结束不包含，即 `[time_from, time_to)` |
| 时区换算 | 北京时间 = UTC + 8 小时，转换时需同时调整日期 |

`trips.date` 和运营方客票列表的 `date` 按班次运营日 `service_date` 过滤；`journeys.date` 按选定上车站的 UTC 发车自然日过滤。跨午夜班次的两种日期可能不同。

日期带时间过滤时，`time_to` 必须晚于同一天的 `time_from`。跨午夜窗口请拆成两个日期查询，例如当天 23:00 至午夜、次日 00:00 至 01:00。

当前一张客票对应一名旅客、一个乘车联。出票与退票处理凭证及库存，当前没有付款、真实扣款或资金退款接口。

### 1.4 标识符

| 字段 | 含义与使用要求 |
|---|---|
| 车站 `id` / `station_id` | 字符串，包含维度信息；必须直接使用车站接口返回值 |
| `mtr_id`、`depot_id`、`siding_id`、`platform_id` | 原始 MTR ID，以字符串处理，不转换为 JavaScript Number |
| 班次 `id` | 数据库班次主键，出票时作为 `trip_id` 使用 |
| `train_number` | D/G/C/S 开头的显示车次号，可能为 null，不唯一 |
| `trip_code` | 车厂 ID + 股道 ID + 趟次的显示组合标识，不用于出票 |
| `origin_seq` / `destination_seq` | 班次内从 0 开始的停站序号，不是车站 ID |
| `ticket_number` | 13 位数字字符串，保留前导零，不作为数值处理 |
| `pnr` | 6 位订座记录号；查询接口使用票号 |
| 证件 `id` | 全局证件库主键，出票时作为 `document_id` 使用 |
| TLV `id` | 附加信息记录主键；区别于标签 `tag` |

数据库读取的部分数值字段可能以 JSON 字符串返回；客户端应按字段语义解析。出票请求中的 `trip_id`、停站序号、`document_id`，以及证件更新 `version`、TLV `tag`/`length` 必须提交 JSON 整数，不能提交带引号的数字。使用 JavaScript 时，确认整数在安全整数范围内；超出范围不能通过浮点数舍入后提交。

车次号一天内可重复。请结合日期、区间、时刻、担当说明和班次 ID 选择具体班次。环线重复经过同一车站时，需保留查询结果的两端停站序号，不能自行按站名推导。

## 2. 认证与权限

### 2.1 密钥角色

| 接口范围 | 出票方密钥 agency | 运营方密钥 operator | 管理员密钥 admin |
|---|---|---|---|
| `GET /api/v1/me` | 可以 | 可以 | 可以 |
| 车站、车厂、班次、区间查询 | 可以 | 不可以 | 可以 |
| 全局证件查询、创建、修改、日志 | 可以 | 不可以 | 可以 |
| 出票、普通票号查询、退票 | 本出票方 | 不可以 | 指定出票方上下文 |
| `/api/v1/operator/*` | 不可以 | 本运营方 | 指定运营方上下文 |
| `/api/v1/admin/*` | 不可以 | 不可以 | 可以 |

出票方与运营方使用各自独立密钥。相同公司需要两种能力时，应分别申请相应身份，而不是用运营方密钥出票。

- 出票方密钥自动绑定 `agency_code`，通常不传 `X-Agency-Code`。如果传入其他出票方代码，返回 403。
- 运营方密钥自动绑定 `operator_code`，通常不传 `X-Operator-Code`。不能指定其他运营方，也不能传 `X-Agency-Code` 冒用出票身份。
- 管理员调用普通出票、查询、退票接口时，可用 `X-Agency-Code` 指定出票方；省略时使用服务端配置的默认出票方。
- 管理员调用 `/api/v1/operator/*` 必须传有效的 `X-Operator-Code`，省略或无效返回 422。
- 停用机构的独立密钥不能认证。密钥轮换后旧密钥立即失效，明文仅在轮换响应中返回一次。

外部业务系统使用服务方分配的角色密钥。密钥应保存在服务端配置中，请求示例只使用占位变量。

### 2.2 查询当前身份

`GET /api/v1/me`

出票方响应示例：

```json
{
  "data": {
    "role": "agency",
    "agency_code": "AGENCY01",
    "agency": {
      "code": "AGENCY01",
      "name": "示例出票方",
      "issuer_code": "123",
      "active": 1
    }
  }
}
```

运营方响应示例：

```json
{
  "data": {
    "role": "operator",
    "operator_code": "OP01",
    "operator": {
      "code": "OP01",
      "name": "示例运营方",
      "active": 1,
      "whitelist_enabled": 1
    }
  }
}
```

### 2.3 出票白名单

运营方的 `whitelist_enabled`：

- `0`：所有启用的出票方都可以为该启用运营方出新票。
- `1`：只有白名单内启用的出票方可以出新票；空白名单不允许新出票。

白名单限制新出票，不授予 TLV 权限。移除白名单成员不影响该出票方既有客票的查询、退票和原出票请求的幂等重试；独立密钥仍需有效。

## 3. 推荐对接流程

### 3.1 出票方

1. 调用 `me` 确认出票身份。
2. 查询车站并保存原始车站 ID。
3. 用起终站、上车日期、可选车次号和时段查询 `journeys`。
4. 从具体结果保存 `id`、`origin_seq`、`destination_seq` 和时刻；需要时查询班次详情。
5. 按证件类型、归属地和号码精确查询全局证件。未登记则创建；需要更正则携带当前版本完整更新。
6. 为业务订单生成并持久保存一个出票 `Idempotency-Key`，携带 `document_id` 出票。
7. 保存返回的 `ticket_number`、PNR、金额及客票状态。请求超时使用同一键和相同业务参数重试。
8. 需要退票时使用原出票方身份调用退票，再核对返回状态。

查询库存不预留席位。最终是否可以出票以出票接口结果为准。

### 3.2 运营方

1. 使用运营方密钥调用 `me`。
2. 通过运营方客票列表或已知票号读取本运营方承运客票。
3. 按双方约定的标签表追加 TLV。
4. 更正附加信息时撤销旧记录，再追加新记录；保存记录 ID。
5. 分页读取附加信息，或导出有效 TLV 二进制流。

## 4. 车站、车厂与班次查询

本章接口仅允许出票方或管理员。

### 4.1 查询车站

`GET /api/v1/stations`

| 查询参数 | 必填 | 说明 |
|---|---|---|
| `q` | 否 | 站名包含查询 |
| `dimension` | 否 | 维度精确过滤，例如 `minecraft/overworld` |

最多返回 500 条，没有分页。返回结构：

```json
{
  "data": [
    {
      "id": "minecraft/overworld:10001",
      "mtr_id": "10001",
      "dimension": "minecraft/overworld",
      "name": "示例站"
    }
  ],
  "limit": 500
}
```

历史车站记录可能保留。车站存在不代表当日有可售班次。

### 4.2 查询车厂目录

`GET /api/v1/depots`

可选参数：`dimension`、`date`、`operator`、`train_number`、`time_from`、`time_to`。

返回 `data` 数组、`total`、`catalog_scope` 和 `trip_counts_scope`。目录列出源数据中当前存在的全部车厂；`dimension` 过滤目录，其他参数过滤班次统计，不会隐藏零班次车厂。

常用车厂字段：`dimension`、`depot_id`、`name`、`operator_code`、`operator_name`、`trip_count`、`scheduled_siding_count`、`siding_count`、`sidings`、`route_ids`、`departures`、`frequencies`。其中 `route_ids`、`departures`、`frequencies` 已解码为 JSON 值。

股道的 `max_trains` 是原始零基值；有限模式的 `vehicle_limit = max_trains + 1`，无限模式 `vehicle_limit = null`。`max_trains = 0` 不表示没有车辆。车厂目录可能有规划诊断，不能将目录项视为可出票班次。

### 4.3 班次列表

`GET /api/v1/trips`

| 查询参数 | 必填 | 说明 |
|---|---|---|
| `date` | 否 | UTC 运营日 `service_date` |
| `operator` | 否 | 运营方代码 |
| `dimension` | 否 | 维度 |
| `depot_id` | 否 | 原始车厂 ID 字符串 |
| `train_number` | 否 | D/G/C/S + 1–16 位数字，例如 G0104 |
| `time_from` | 否 | 首站发车时间下界；填写时必须同时指定 date |
| `time_to` | 否 | 首站发车时间上界；填写时必须同时指定 date |
| `page` | 否 | 从 1 开始，固定每页 30 条 |

只列出启用班次及启用运营方。返回 `data,total,page,page_size,timezone`。排序为运营日、车厂、股道、趟次、ID。

班次对象常用字段：

| 字段 | 说明 |
|---|---|
| `id` | 出票使用的班次主键 |
| `dimension`、`service_date` | 维度、UTC 运营日 |
| `name` | 原线路名称 |
| `train_number` | 提取到的车次号，可能为 null |
| `duty` | 含“担当”的原说明；没有时为空字符串 |
| `operator_code`、`operator_name` | 实际运营方及列表中的名称 |
| `depot_id`、`siding_id` | 原始字符串 ID |
| `run_number`、`trip_code` | 趟次及组合显示标识 |
| `depot_operator_code` | 车厂归属运营方，可能为 null |
| `operator_assignment` | `depot` 表示按车厂归属，`trip` 表示班次配置 |
| `capacity` | 区间容量 |
| `fare_per_segment` | 每相邻停站区间的最小单位票价 |
| `origin`、`destination` | 列表中的首末停站对象 |

车次号从线路名按 `|` 分段，删除非 ASCII 字母和数字、转大写后，取第一个完整匹配 D/G/C/S + 1–16 位数字的段。前导零保留。其他前缀不提取车次号。

`route_ids` 在班次对象中是数据库保存的 JSON 文本字符串，区别于车厂目录中的已解码字段。`trip_code` 可跨日期、维度重复。运行图重新导入或按日清空后，应重新查询，不永久缓存班次选择。

### 4.4 班次详情

`GET /api/v1/trips/{id}`

返回 `{"data": 班次对象, "timezone":"UTC"}`，其中 `stops` 按 `seq` 升序排列。

停站字段：`trip_id`、`seq`、`station_id`、`platform_id`、`arrival_at`、`departure_at`。

详情查询本身不代表班次可售；班次启用状态、发车时间、库存及出票授权在出票时再次校验。

### 4.5 可售区间查询

`GET /api/v1/journeys`

| 查询参数 | 必填 | 说明 |
|---|---|---|
| `origin` | 是 | 上车站 ID |
| `destination` | 是 | 下车站 ID，必须与 origin 不同 |
| `date` | 是 | 上车站发车的 UTC 日期 |
| `train_number` | 否 | 车次号 |
| `time_from`、`time_to` | 否 | 上车站发车时间窗口 |

返回最多 200 条，按上车发车时刻、班次 ID、停站序号排序，没有分页。结果过多时缩小区间、时段或车次号条件。

核心字段响应示例：

```json
{
  "data": [
    {
      "id": 123,
      "service_date": "2026-10-04",
      "dimension": "minecraft/overworld",
      "name": "G0104||示例担当",
      "train_number": "G0104",
      "duty": "示例担当",
      "operator_code": "OP01",
      "trip_code": "100+200+1",
      "run_number": 1,
      "origin_seq": 0,
      "destination_seq": 3,
      "departure_at": "2026-10-04 08:30:00.000",
      "arrival_at": "2026-10-04 09:10:00.000",
      "available": 12,
      "amount_minor": 300,
      "currency": "CNY"
    }
  ],
  "timezone": "UTC",
  "limit": 200
}
```

`available` 是所选区间各相邻停站区间剩余容量的最小值。`amount_minor = fare_per_segment × (destination_seq - origin_seq)`。结果可能包含余票为 0 的区间，也不按出票白名单预过滤；能否销售以出票校验为准。

## 5. 全局旅客证件库

管理员和启用出票方共享一套全局证件数据；不是按出票方或运营方分库。接口不提供证件删除或日志修改。

### 5.1 证件字段

| 字段 | 创建/更新要求 | 说明 |
|---|---|---|
| `document_type` | 必填字符串，1–32 字符 | 如 PASSPORT、ID_CARD；无固定类型字典 |
| `document_number` | 必填字符串，1–80 字符 | 保留前导零，不作为数字 |
| `issuing_country` | 必填字符串，3 个字母 | 如 CHN；仅校验三字母格式，不校验外部代码字典 |
| `birth_date` | 必填 | 真实日期，1000-01-01 至当前 UTC 日期 |
| `surname` | 可省略、null 或空字符串 | 姓，最多 80 字符，空值保存为 null |
| `given_name` | 必填字符串，1–80 字符 | 名 |
| `reason` | 必填字符串，1–500 字符 | 本次登记或修改原因，写入日志 |
| `version` | 更新必填，正整数 | 提交刚读取的当前版本 |
| `id` | 服务端生成 | 请求体不能指定 |
| `created_at`、`updated_at` | 服务端生成 | UTC 毫秒时间，请求体不能指定 |
| `display_name` | 响应字段 | 姓 + 空格 + 名，姓为空时只显示名 |

类型、号码、归属地去除首尾空白并将 ASCII 字母转大写。姓名去除首尾空白，完整显示姓名最多 160 字符。文本校验拒绝控制字符。

`document_type + issuing_country + document_number` 全局唯一；同一人多个证件分别建记录，系统不自动合并为同一个人。

### 5.2 查询与详情

`GET /api/v1/passenger-documents`

可选参数：

- `document_type`、`document_number`、`issuing_country`：精确查询，执行同样的去空白和大写规范化。
- `name`：姓或名的包含查询，最多 160 字符。
- `page`：每页 30 条，按证件 ID 倒序。

`GET /api/v1/passenger-documents/{id}` 返回单份证件。

建议登记前用类型、号码和归属地完整精确查询；并发创建返回重复冲突后，重新精确查询已存在记录。

### 5.3 创建

`POST /api/v1/passenger-documents`

```json
{
  "document_type": "PASSPORT",
  "document_number": "E00001234",
  "issuing_country": "CHN",
  "birth_date": "1990-02-03",
  "surname": null,
  "given_name": "示例旅客",
  "reason": "首次登记"
}
```

HTTP 201 响应：

```json
{
  "data": {
    "id": 456,
    "document_type": "PASSPORT",
    "document_number": "E00001234",
    "issuing_country": "CHN",
    "birth_date": "1990-02-03",
    "surname": null,
    "given_name": "示例旅客",
    "version": 1,
    "created_at": "2026-10-03 02:00:00.000",
    "updated_at": "2026-10-03 02:00:00.000",
    "display_name": "示例旅客"
  }
}
```

### 5.4 更新与版本冲突

`PUT /api/v1/passenger-documents/{id}`

这是完整表单更新，不是 PATCH。提交全部必填业务字段、`reason` 和当前 `version`；省略 surname 将清空姓。

```json
{
  "document_type": "PASSPORT",
  "document_number": "E00001234",
  "issuing_country": "CHN",
  "birth_date": "1990-02-03",
  "surname": null,
  "given_name": "更正后的示例姓名",
  "version": 1,
  "reason": "依据证件更正姓名"
}
```

成功返回更新后的完整证件，版本递增。当前版本不匹配返回 409，应重新获取并核对数据后再提交，不能盲目替换为新版本号重试。所有业务字段不变时返回当前记录，不增加版本、时间或日志。

### 5.5 修改日志

`GET /api/v1/passenger-documents/{id}/history?page=1`

每页 30 条，按日志 ID 倒序。每条包含：

| 字段 | 说明 |
|---|---|
| `id`、`document_id` | 日志与证件 ID |
| `action` | CREATE / UPDATE |
| `actor_role`、`actor_code` | 操作者角色、机构代码；管理员代码为 ADMIN |
| `reason`、`occurred_at` | 原因、UTC 时间 |
| `before` | 修改前完整证件；创建时为 null |
| `after` | 修改后完整证件 |

## 6. 出票、查询与退票

### 6.1 出票

`POST /api/v1/tickets`

必须额外传 `Idempotency-Key`：

```http
Idempotency-Key: order-20261004-000001
```

| 请求字段 | 必填 | 说明 |
|---|---|---|
| `trip_id` | 是 | 查询结果的数据库班次 ID，JSON 非负整数 |
| `origin_seq` | 是 | JSON 非负整数 |
| `destination_seq` | 是 | JSON 非负整数，必须大于 origin_seq |
| `document_id` | 推荐 | 全局证件 ID，JSON 正整数 |
| `passenger` | 无 document_id 时必填 | 姓名字符串，去空白后 1–160 字符 |

证件出票请求：

```json
{"trip_id":123,"origin_seq":0,"destination_seq":3,"document_id":456}
```

兼容仅姓名出票请求：

```json
{"trip_id":123,"origin_seq":0,"destination_seq":3,"passenger":"示例旅客"}
```

有 `document_id` 时，姓名以证件库为准，请求中的 passenger 不参与出票姓名。服务器保存出票当时的完整证件快照，后续全局修改不改变旧票。仅姓名出票时，证件 ID 和快照为 null。

服务器重新校验机构状态、白名单、班次状态、停站顺序、上车发车时间和库存。上车发车时间已到或过去不能出票。

成功返回完整客票对象，核心字段示例：

```json
{
  "data": {
    "id": 789,
    "ticket_number": "1230000000001",
    "pnr": "ABC234",
    "issuer_code": "123",
    "agency_code": "AGENCY01",
    "passenger": "示例旅客",
    "passenger_document_id": 456,
    "passenger_document_snapshot": {
      "id": 456,
      "document_type": "PASSPORT",
      "document_number": "E00001234",
      "issuing_country": "CHN",
      "birth_date": "1990-02-03",
      "surname": null,
      "given_name": "示例旅客",
      "version": 1,
      "created_at": "2026-10-03 02:00:00.000",
      "updated_at": "2026-10-03 02:00:00.000",
      "display_name": "示例旅客"
    },
    "amount_minor": 300,
    "currency": "CNY",
    "status": "OPEN",
    "issued_at": "2026-10-03 02:10:00",
    "refunded_at": null,
    "coupons": [
      {
        "ticket_id": 789,
        "number": 1,
        "trip_id": 123,
        "origin_seq": 0,
        "destination_seq": 3,
        "status": "OPEN",
        "trip": {"id":123,"operator_code":"OP01","train_number":"G0104"},
        "origin": {"seq":0,"station_id":"minecraft/overworld:10001","departure_at":"2026-10-04 08:30:00.000"},
        "destination": {"seq":3,"station_id":"minecraft/overworld:10002","arrival_at":"2026-10-04 09:10:00.000"}
      }
    ]
  }
}
```

上例的 trip、origin、destination 对象省略了非核心字段，实际会返回完整班次及停站对象。

### 6.2 出票幂等规则

- 键允许字符 `A-Z a-z 0-9 _ . : -`，长度 1–100。
- 键按出票方隔离，即 `agency_code + Idempotency-Key`。
- 同一键、相同规范化出票参数返回原客票，不重复占库存。
- 同一键、不同班次/区间/旅客参数返回 409。
- 证件出票的匹配参数使用 document_id，不使用后来变化的证件姓名或版本；证件更正后原请求仍可以同键重试。
- 重试返回客票当前状态；已经退票的原请求不会重新生成 OPEN 客票。
- 当前没有幂等键自动过期机制。按日清理客票会连同相关幂等记录一起删除，清理后不能再依赖原键去重。

客户端应在首次请求前持久保存键和请求参数。网络中断或 5xx 时不能换新键“再买一次”。

### 6.3 查询客票

`GET /api/v1/tickets/{ticket_number}`

返回与出票相同结构的完整客票。出票方只能读取本方出的票；其他方票号按未找到返回 404。管理员也按当前出票方上下文查询，不是无上下文跨机构查询。

### 6.4 退票

`POST /api/v1/tickets/{ticket_number}/refund`

不需要请求体或 Idempotency-Key。只允许原出票方，且所选上车站尚未发车。成功返回完整客票，客票及乘车联 `status = RFND`，填入 `refunded_at`，库存归还。

重复退同一已退票客票直接返回当前记录，不重复退库存。当前只有全票退票，没有部分退票、改签和金额计算接口。

| 状态 | 含义 |
|---|---|
| OPEN | 已出票且未退票，乘车联占用区间库存 |
| RFND | 已退票，乘车联不再占用库存 |

### 6.5 curl 调用示例

以下为 Bash 示例，先配置真实部署地址和服务方分配的出票方密钥。

```bash
export BASE_URL='http://localhost:8000'
export API_KEY='<出票方密钥>'

curl -sS "$BASE_URL/api/v1/me" -H "X-API-Key: $API_KEY"

curl -sS --get "$BASE_URL/api/v1/journeys" \
  -H "X-API-Key: $API_KEY" \
  --data-urlencode 'origin=minecraft/overworld:10001' \
  --data-urlencode 'destination=minecraft/overworld:10002' \
  --data-urlencode 'date=2026-10-04' \
  --data-urlencode 'time_from=08:00' \
  --data-urlencode 'time_to=12:00'

curl -sS "$BASE_URL/api/v1/tickets" \
  -H "X-API-Key: $API_KEY" \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: order-20261004-000001' \
  --data '{"trip_id":123,"origin_seq":0,"destination_seq":3,"document_id":456}'

curl -sS "$BASE_URL/api/v1/tickets/1230000000001" \
  -H "X-API-Key: $API_KEY"

curl -sS -X POST "$BASE_URL/api/v1/tickets/1230000000001/refund" \
  -H "X-API-Key: $API_KEY"
```

## 7. 运营方客票与 TLV

本章需要运营方密钥，或管理员密钥加 `X-Operator-Code`。出票方密钥返回 403。

权限按客票乘车联所关联班次的实际运营方判断。不能读取其他运营方客票或 TLV。退票不会自动撤销附加信息；业务方自行决定是否继续管理已退票客票。

### 7.1 浏览与读取客票

| 方法 | 路径 | 参数 / 响应 |
|---|---|---|
| GET | `/api/v1/operator/tickets` | 可选 date（UTC 运营日）、page；每页 30 条，按客票 ID 倒序 |
| GET | `/api/v1/operator/tickets/{number}` | 完整客票、证件快照及属于本运营方的乘车联 |

列表项包含 `id,ticket_number,passenger,status,issued_at,agency_code`，列表响应带 `total,page,page_size`。列表没有车次号、时间窗口或票号搜索参数；已知票号用详情接口。

### 7.2 项目 TLV v1 线格式

`project-tlv-v1` 是本项目确定的通用 TLV 格式：

| 部分 | 长度 | 编码 |
|---|---|---|
| Tag | 2 字节 | 无符号整数，big-endian，0–65535 |
| Length | 4 字节 | 无符号整数，big-endian，表示 Value 字节数 |
| Value | Length 字节 | 原始值字节 |

有效记录按记录 ID 升序逐个拼接。无条数前缀、终止标记或字节对齐填充。解析器每次读取 6 字节头，再读取 Length 个字节，直到流结束；剩余头不足 6 字节或值不足 Length 时视为不完整流。

标签由运营方自行定义，无需提前注册。建议双方另行约定标签含义、值类型及业务版本。不同运营方相同标签互不关联；同一标签可以重复出现，不能用 tag 替代记录 ID，或将流强制转成只允许一个值的字典。

例：tag=101，UTF-8 值“中文”，Length=6，完整十六进制：

```text
0065 00000006 E4B8ADE69687
```

label、记录 ID、时间、撤销状态和审计信息不进入二进制线格式。无 TTL 或自动过期。

### 7.3 追加 TLV

`POST /api/v1/operator/tickets/{number}/tlv`

| 字段 | 必填 | 说明 |
|---|---|---|
| `tag` | 是 | JSON 整数，0–65535 |
| `value` | 是 | 字符串，允许空值 |
| `encoding` | 否 | utf8（默认）、hex、base64，区分大小写 |
| `length` | 否 | JSON 整数；如果填写，必须等于解码后值字节数 |
| `label` | 否 | 最多 160 字符的说明，默认空字符串 |

单项解码后值最多 65,536 字节。hex 只能含十六进制字符且长度为偶数，不允许空格或 0x 前缀；base64 使用标准字符集和规范填充，不是 Base64URL。utf8 字节长度不等于字符数。

请求示例：

```json
{"tag":101,"encoding":"utf8","value":"中文","length":6,"label":"服务备注"}
```

任意二进制示例：

```json
{"tag":102,"encoding":"hex","value":"00FF80","label":"二进制示例"}
```

HTTP 201 完整记录响应示例：

```json
{
  "data": {
    "id": 901,
    "ticket_id": 789,
    "operator_code": "OP01",
    "tag": 101,
    "encoding": "utf8",
    "label": "服务备注",
    "actor_role": "operator",
    "actor_code": "OP01",
    "created_at": "2026-10-03 02:20:00.000",
    "revoked_at": null,
    "length": 6,
    "value": "中文",
    "wire_base64": "AGUAAAAG5Lit5paH",
    "active": true
  }
}
```

响应 value 使用原提交 encoding；hex 输出转大写。`wire_base64` 是包含头部的单条完整 TLV，不仅是 value。

追加接口没有 Idempotency-Key 支持；同一请求重复提交会新增多条记录。网络结果不明时先查询列表核对，不要直接按出票的重试规则重复追加。

### 7.4 分页读取

`GET /api/v1/operator/tickets/{number}/tlv?page=1&include_revoked=1`

默认只返回有效记录；`include_revoked=1` 包含已撤销记录。每页 30 条，按记录 ID 升序，响应：

```json
{"data":[],"total":0,"page":1,"page_size":30,"format":"project-tlv-v1"}
```

每条结构与追加响应一致。`active` 为布尔值，撤销后为 false。

### 7.5 撤销与日志

`DELETE /api/v1/operator/tickets/{number}/tlv/{id}`

必须带 JSON 请求体：

```json
{"reason":"更正错误备注"}
```

reason 去空白后为 1–500 字符。返回该记录，填入 revoked_at、active=false，原值仍保留。重复撤销不追加重复日志。不能原地修改记录，纠错采用撤销旧记录再追加新记录。

`GET /api/v1/operator/tickets/{number}/tlv/{id}/history`

返回 `{"data":[日志项]}`，按日志 ID 升序，无分页。日志含 `id,tlv_id,action,actor_role,actor_code,reason,occurred_at`。`action` 为 APPEND 或 REVOKE；追加日志的 reason 由服务器固定为 `Append TLV`。

### 7.6 导出有效流

`GET /api/v1/operator/tickets/{number}/tlv-stream`

响应示例：

```json
{
  "data": {
    "format": "project-tlv-v1",
    "byte_order": "big-endian",
    "tag_bytes": 2,
    "length_bytes": 4,
    "record_count": 1,
    "length": 12,
    "wire_base64": "AGUAAAAG5Lit5paH"
  }
}
```

这里 length 是整个流的字节数，含每条记录 6 字节头；与单项 length 的含义不同。只有有效记录参与导出。空流返回 record_count=0、length=0、wire_base64=""。

单次流上限 1 MiB（1,048,576 字节，含头部），超限返回 422，应分页获取列表。并发追加或撤销时，分页/导出不是客户端可锁定的历史版本快照；需要固定批次时由双方协调写入窗口。

## 8. 错误与重试

| HTTP 状态 | 常见原因 | 客户端处理 |
|---|---|---|
| 401 | 密钥缺失、无效、轮换失效、机构停用 | 核对密钥与机构状态 |
| 403 | 角色不符、冒用身份、出票不在白名单 | 核对身份及授权，不能通过重试解决 |
| 404 | 资源不存在，或不属于当前出票方/运营方 | 核对票号、ID、身份上下文 |
| 409 | 库存售罄、发车已过、班次不可售、幂等键冲突、证件重复/版本冲突、业务变更受保护 | 按具体场景重新查询或处理冲突 |
| 422 | 日期、字段类型、停站区间、TLV 编码/长度等参数错误 | 修正请求参数 |
| 500 | 服务端内部异常 | 记录请求信息，按操作性质核对或重试 |

典型错误文本：

```json
{"error":"Idempotency-Key reused with another request"}
{"error":"Sold out"}
{"error":"Agency is not authorized for this operator"}
{"error":"Document changed; reload current version"}
{"error":"length must match encoded value byte length"}
```

- 出票超时/5xx：保持原幂等键和参数重试，随后核对客票。
- 退票超时/5xx：查询状态，重复退票是幂等操作。
- 证件创建结果不明：使用三字段精确查询；重复创建受唯一约束保护。
- 证件更新结果不明：重新读取版本和日志，确认结果后决定是否提交。
- TLV 追加结果不明：查询记录核对；追加没有请求键去重。
- 密钥轮换结果不明：不要连续自动重试，每次轮换都会产生新密钥并使上一次失效。
- 当前未提供 webhook、异步出票、座位锁定、改签、支付或全局业务事件订阅。

## 9. 联调验收清单

| 场景 | 预期 |
|---|---|
| 出票方和运营方分别调用 me | 返回正确绑定角色及代码 |
| 同号车次选择 | 用具体班次 ID 和停站序号出票 |
| 跨午夜与北京时间换算 | 上车日期、运营日与展示日期符合各自语义 |
| 证件创建、重复登记、更正 | 全局唯一，更新携带版本，日志有前后值 |
| 证件更正后读取旧票 | 旧票快照不变 |
| 同键重复出票 | 同一票号，库存只扣一次 |
| 同键不同参数 | 409 |
| 售罄或已发车 | 出票失败，不能以查询余票作为出票成功 |
| 重复退票 | RFND，库存只归还一次 |
| 跨出票方/运营方读取客票 | 404；错误角色调用受限接口为 403 |
| UTF-8 中文、00/FF 二进制 TLV | 字节长度及导出流正确 |
| 重复标签与撤销 | 保留多个记录，撤销项不进入有效流 |
| 旧密钥访问 | 轮换后 401 |

联调请使用服务方分配的测试机构、未来班次及虚构证件。按日清理会永久移除客票及幂等记录，不能将清理作为正常退票流程。

## 10. 附录：管理员接口

本章仅面向获授权的管理系统，不是普通出票方或运营方密钥权限。

### 10.1 接口一览

以下路径统一前缀为 `/api/v1/admin`。

| 方法 | 路径 | 说明 |
|---|---|---|
| GET / POST | `/operators` | 列出 / 创建运营方 |
| PUT | `/operators/{code}` | 完整更新运营方 |
| POST | `/operators/{code}/key` | 创建或轮换运营方密钥 |
| GET | `/operators/{code}/agencies` | 读取出票白名单及启用状态 |
| POST | `/operators/{code}/agencies` | 添加名单成员 |
| DELETE | `/operators/{code}/agencies/{agency}` | 移除名单成员 |
| GET / POST | `/agencies` | 列出 / 创建出票方 |
| PUT | `/agencies/{code}` | 完整更新出票方 |
| PUT | `/agencies/{code}/operators` | 替换该出票方对应的全部运营方名单关系 |
| POST | `/agencies/{code}/key` | 创建或轮换出票方密钥 |
| PUT | `/depots/operator` | 车厂整体归属 |
| PUT | `/trips/{id}/operator` | 修改单班次运营方 |
| POST | `/clear-date` | 预览或执行运营日清理 |

### 10.2 机构管理

创建运营方：

```json
{"code":"OP01","name":"示例运营方","contact":"对接联系人","active":1,"whitelist_enabled":1}
```

创建出票方：

```json
{"code":"AGENCY01","name":"示例出票方","contact":"对接联系人","active":1,"issuer_code":"123"}
```

- 运营方 code 为 1–16 位大写字母或数字；出票方 code 为 1–32 位大写字母、数字、下划线或连字符。
- name 必填、最多 160 字符；contact 可选、最多 255 字符。
- active 为 JSON 整数 0/1，省略时为 1。
- whitelist_enabled 为 JSON 整数 0/1，新建省略时为 0；更新省略保留当前值。
- 出票方 issuer_code 必须为三位数字字符串，保持前导零，全局唯一，创建后不可变。
- PUT 提交完整 name/contact/active；省略 contact 会清空，省略 active 会设为 1。代码由路径指定，不能通过请求体变更。
- 列表返回 `{"data":[机构]}`，包含 has_api_key，不返回密钥或散列。出票方列表还包含 operators 名单关系。

密钥轮换不需要请求体：

```text
POST /api/v1/admin/agencies/AGENCY01/key
POST /api/v1/admin/operators/OP01/key
```

响应分别为 `{"data":{"agency_code":"AGENCY01","api_key":"<仅本次返回>"}}` 或 `{"data":{"operator_code":"OP01","api_key":"<仅本次返回>"}}`。

添加白名单请求为 `{"agency_code":"AGENCY01"}`。重复添加不会重复建关系；删除不存在关系不新增数据。切换开关不清除名单。

白名单查询、添加、移除均返回同一结构，enabled 是布尔值：

```json
{"data":{"operator_code":"OP01","enabled":true,"agencies":[{"code":"AGENCY01","name":"示例出票方","active":1}]}}
```

替换某出票方全部名单关系：

```json
{"operators":["OP01","OP02"]}
```

这是替换操作，传空数组清空关系；最多 1000 个代码，代码必须对应已存在运营方。这些关系只在相应运营方开启白名单时限制新销售。

### 10.3 车厂与班次归属

`PUT /api/v1/admin/depots/operator`

```json
{"dimension":"minecraft/overworld","depot_id":"100","operator_code":"OP01"}
```

按维度与车厂 ID 定位；该车厂所有日期及历史版本的班次统一归属，后续导入继承。已有任何客票（包括已退票）的班次不能变更到其他运营方，冲突整批回滚。班次响应通过 depot_operator_code / operator_assignment 标注来源。

`PUT /api/v1/admin/trips/{id}/operator`

```json
{"operator_code":"OP01"}
```

目标运营方须启用；已出票班次不能修改；已归属车厂的班次不能单独改到其他运营方，应使用车厂接口。

### 10.4 按运营日清理

`POST /api/v1/admin/clear-date`

先预览：

```json
{"date":"2026-10-04","scope":"tickets","preview":true}
```

执行：

```json
{"date":"2026-10-04","scope":"tickets","preview":false,"confirm_date":"2026-10-04"}
```

preview 为 JSON 布尔值，默认 true。执行时 confirm_date 必须与 date 相同。响应 data 提供各类数量及 blocked 等清理结果；执行时重新检查，不依赖旧预览。

清理预览响应示例：

```json
{
  "data": {
    "date": "2026-10-04",
    "scope": "tickets",
    "preview": true,
    "blocked": false,
    "counts": {
      "trips": 0,
      "stops": 0,
      "imports": 0,
      "tickets": 1,
      "coupons": 1,
      "requests": 1,
      "ticket_events": 1,
      "ticket_tlv": 2,
      "ticket_tlv_events": 2
    },
    "date_basis": "UTC service_date; all dimensions and import revisions"
  }
}
```

counts 为所选 scope 实际涉及的表数量，不涉及的表为 0。timetable 预览遇到客票返回 blocked=true，实际执行返回 409。

| scope | 范围 |
|---|---|
| tickets | 删除当日班次客票、乘车联、幂等请求、客票日志、TLV 及其日志，保留运行图 |
| timetable | 删除当日停站、班次和导入批次；有关联客票时返回 409 |
| all | 当日运行图及其全部关联客票一起删除 |

按 UTC service_date 清理所有维度和历史版本，包含跨午夜停站；不是按出票日或上车自然日清理。车站、地图目录、机构、密钥、白名单、全局旅客证件及证件修改日志均保留。

执行清理属于永久删除，调用方应使用预览结果展示实际范围并确认日期；清理后的旧班次、票号、TLV ID 以及出票幂等记录不可继续使用。


