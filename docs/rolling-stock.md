# 车底、席位与区间票额

车底代码来自 MTR `Siding.train_custom_id`，为空时回退到 `train_type`，按 MTR 的规则转为小写。同一列车 ID 跨车厂、跨维度共用；席位启用和票额按「运营方＋车底 ID＋席位种别」设置。

## 默认规则

| 代码            | 席位       | 默认启用 | 每班票额 | 公布票价倍率 |
| --------------- | ---------- | -------- | -------- | ------------ |
| STANDING        | 无座       | 是       | 100      | 1            |
| SECOND          | 二等座     | 是       | 400      | 1            |
| FIRST           | 一等座     | 否       | 0        | 1.5          |
| PREFERRED_FIRST | 优选一等座 | 否       | 0        | 1.8          |
| PREMIUM         | 特等座     | 否       | 0        | 3            |

管理员在独立「车底管理」模块维护席位种别、全局默认票额/倍率，并按多个运营方与多个车底批量启用、修改票额或恢复默认。新种别默认关闭；启用但票额为 0 时可查询、不可售。修改全局默认票额影响未设置专属票额的车底。`trips.capacity` 保留为历史兼容字段，不再作为新查询/出票的限额；导入的 `--capacity` 同样仅保留兼容，不覆盖车底管理配置。

无座和二等座固定采用公布票价，不能覆盖倍率。其他种别优先级为：运营方＋车底专属 > 运营方通用 > 车底通用 > 席位全局默认。倍率用整数基点保存，10000 表示 ×1，支持四位小数。倍率作用于精确的分档里程计价结果，最终只四舍五入一次到分。

所有席位当前均为 `seating_mode=unassigned`，独立限额，不分配车厢与座位。预留的 `carriage_number`、`seat_number` 为 NULL，当前提交指定座位会被拒绝。无座与二等座也分别占用自己的票额。

票额按班次的每个相邻停站区间计算：A→B 与 B→C 可以复用；A→C 必须两个区间都有余量。同车底连续运行多个班次，各班次独立发售，每张票仅允许一个班次，不支持跨班次连续乘车。

## 接口

管理接口仅管理员可用：

- `GET /api/v1/admin/rolling-stock`：返回 version、seat_types、stocks、seat_settings、fare_rules。
- `PUT /api/v1/admin/rolling-stock`：提交 `{version,kind,rows}`；kind 为 `seat_types`、`seat_settings` 或 `fare_rules`，每批 1–500 行，事务内全部成功或全部回滚。

席位种别行：`{code,name,default_capacity,multiplier_bp,sort_order}`。代码为大写字母开头的稳定标识，可含数字与下划线，最多 32 字符。

专属席位行：`{operator_code,stock_code,seat_code,enabled,capacity}`。`enabled` 为 JSON 布尔值，capacity 为 0–1000000 的 JSON 整数。恢复默认提交同样的三个标识字段及 `reset:true`。

费率行：`{operator_code,stock_code,seat_code,multiplier_bp}`。运营方为空表示所有运营方，车底为空表示该运营方所有车底，两者不能同时为空（全局默认在席位种别维护）。移除覆盖使用 `reset:true`。倍率范围为 ×1–×100。不能关闭仍有已售客票/有效占额的席位，也不能把限额降到已占用数量以下。

查询沿用 `GET /api/v1/journeys`，新增 `stock_code`、`stock_version`、`seat_options`。每个启用席位提供 seat_code、seat_name、capacity、available、amount_minor、multiplier_bp、rate_source、seating_mode。未启用席位完全省略。顶层 amount_minor/available 保留为公布票价/二等座兼容字段；新调用方应使用 seat_options。二等座被关闭时，顶层 available 为 0。

出票 `POST /api/v1/tickets` 增加 `seat_code`、`stock_version`、可选 `hold_id`。省略 seat_code 按二等座处理；建议始终携带查询返回的 stock_version 与 fare_version，配置变更返回 409。服务端重新核价并保存席位、倍率、车底、配置版本快照；后续修改不影响历史客票和同请求键的幂等重试。退款自动释放对应班次、席位、区间的票额。

## 可选占额流程

当前没有真实支付处理，可直接出票，也可先占额：

1. `POST /api/v1/inventory-holds`，携带独立 `Idempotency-Key`，正文 `{trip_id,origin_seq,destination_seq,seat_code,stock_version,fare_version}`。
2. 返回占额 id、expires_at（UTC）与版本；有效期 10 分钟，最晚到上车站发车时间。相同请求键重试返回同一记录，已过期的请求键不会续期。
3. 出票时附 `hold_id`，事务内转换为客票，不重复占用库存。占额必须属于当前出票方，且班次、区间、席位完全一致；费率版本变化后需重新占额。
4. 放弃购买可 `DELETE /api/v1/inventory-holds/:id`。过期记录从所有库存计算中立即排除，无需定时任务。过期记录保留用于幂等与审计。

车底策略锁与班次行锁保护占额和出票，防止并发超售；退款释放对应票额。已有有效占额时，重导入时刻表和改派运营方会被拒绝。按日期清理会一并删除该日期班次的占额记录。

## 升级与验证

```bash
php think ticketing:schema
php think mtr:stock /path/to/original/dimension --dimension=minecraft/overworld
php tests/rolling-stock.php
php tests/fares.php
php tests/integration.php
php tests/concurrency.php
npm --prefix frontend run build
```

历史停车轨道目录此前只保存基础车型，升级后需使用原始存档补回具体列车 ID。`mtr:stock` 更新目录的车底元数据，历史班次只从哈希匹配的原导入存档补齐；存在有效占额或已有客票且车底代码不为空的班次不会强行改车型。输出 skipped 时应核对原始存档，不能套用最新地图覆盖旧班次。新导入班次自动读取具体列车 ID，无须回填。
