# 轨道里程分档计费

新报价与新出票统一采用 `Σ(某限速档行驶里程 × 该档每公里单价)`，不再使用相邻停站数量乘 `fare_per_segment`。旧字段保留兼容历史数据和旧导入参数，但不参与新票定价。

## 口径

- 1 方块按 1 米，轨道长度与随附 MTR 3.x `Rail.getLength()` 一致；使用生成路径，不用两站直线距离。
- 按 `RailType.speedLimit` 的**标称限速**分类，不按列车瞬时速度。普通限速档为 20/40/60/80/120/160/200/300 km/h；其他已支持轨道包含 2/30/900 档。
- PLATFORM、TURN_BACK 标称 80，SIDING 标称 40，NONE 标称 20；其实际运行限速仍按 MTR 的继承规则计算，计费分类与运动规则各有明确口径。
- 逐 tick 穿过多个轨道时按实际穿越长度拆分。反向起步因车长发生的位置跳转不算行驶里程。方向班次首站从零累计，区间取两端各档累计里程差。
- 里程以毫米为精度，单价为每公里的最小货币单位整数（CNY 为分/公里）。计算用整数 `distance_mm × rate_minor_per_km`，所有档累加后除 1,000,000，最终一次四舍五入到分，避免逐段舍入累积误差。

## 管理接口

`GET /api/v1/admin/fare-rates` 返回 `version`、`currency`、`rates`。`PUT` 提交当前版本和**完整**档位数组：

```json
{
  "version": 1,
  "rates": [
    { "speed_kmh": 2, "rate_minor_per_km": 5 },
    { "speed_kmh": 20, "rate_minor_per_km": 10 },
    { "speed_kmh": 30, "rate_minor_per_km": 12 },
    { "speed_kmh": 40, "rate_minor_per_km": 15 },
    { "speed_kmh": 60, "rate_minor_per_km": 20 },
    { "speed_kmh": 80, "rate_minor_per_km": 25 },
    { "speed_kmh": 120, "rate_minor_per_km": 35 },
    { "speed_kmh": 160, "rate_minor_per_km": 45 },
    { "speed_kmh": 200, "rate_minor_per_km": 55 },
    { "speed_kmh": 300, "rate_minor_per_km": 75 },
    { "speed_kmh": 900, "rate_minor_per_km": 150 }
  ]
}
```

上面是初始化的**演示单价**。管理员可在工作台“轨道公里单价”编辑，或通过已签名管理接口调整。单价必须为 0–1,000,000 的 JSON 整数，随速度严格增加；缺档、重复、未知档位、倒挂均返回 422。版本不符返回 409，避免覆盖他人的编辑。机构密钥不能管理单价。

查询区间返回 `distance_m`、`amount_minor`、`fare_version`、`fare_breakdown`、`fare_method=speed_mileage`。每档明细含 `speed_kmh`、`distance_m`、`rate_minor_per_km`。没有可靠分档里程时 `fare_available=false`、`amount_minor=null`，禁止新出票，不回退到旧的按站计价。

出票可附 `fare_version`。前端自动附带查询版本；若单价已改变返回 409，要求重新查询。服务端始终重新计算，不信任调用方提交的金额。未提供版本的 OTA 按出票时当前单价核价，建议总是提交版本。出票事务与单价更新通过策略行锁互斥。

每张新票的 `fare_snapshot` 保存出票金额、里程、单价版本和分档明细；后续改价不影响既有票和幂等重试。旧票保留原金额，快照为 null。

## 升级

```bash
php think ticketing:schema
php think mtr:mileage /path/to/original/dimension --dimension=minecraft/overworld --date=2026-10-04
```

里程回填要求源存档哈希、班次键和逐站站台匹配，仅更新累计总里程与分档里程，保留时刻、库存和已出票金额。未出票的新运营日直接正常导入即可。回填输出跳过数量时需检查存档版本，不能强行套用不同地图数据。

验证：`php tests/fares.php`、`php tests/motion.php`、`php tests/merge.php`、`php tests/concurrency.php`。
