# MTR 数据导入

[文档导航](README.md) · [部署与启动](getting-started.md)

命令从本仓库目录执行。示例中的 `/path/to/...` 替换为自己的存档路径，日期替换为要生成的 UTC 运营日。存档可以位于仓库外部。

## 同步地图目录

扫描整个 MTR 目录的各维度：

```bash
php think mtr:catalog /path/to/world/mtr
```

只处理一个维度：

```bash
php think mtr:catalog /path/to/world/mtr/minecraft/overworld --dimension=minecraft/overworld
```

目录同步保存所有车厂、股道和规划诊断，不会导入可售班次、删除客票或改写既有时刻表。日期与运营方筛选只影响工作台中的班次计数，不会隐藏没有班次的车厂。

目录和同名 ZIP 并存时优先使用目录。已从地图删除的车厂保留历史记录并标记为非当前；历史车站仍可供既有时刻表引用。

## 试运行与导入运营日

先只读解析单个维度目录或 ZIP：

```bash
php think mtr:import /path/to/overworld.zip   --dimension=minecraft/overworld --date=2026-10-26 --dry-run
```

检查 `runtime/import-report.json` 中的 warnings，再导入：

```bash
php think mtr:import /path/to/overworld.zip   --dimension=minecraft/overworld --date=2026-10-26   --operator=LOCAL --capacity=100
```

每个运营日需显式导入。运营方必须存在且启用；容量应使用实际运营配置，省略时为 0，不可出票。单价在后台费率表管理，旧 `--fare` 参数仅保留兼容，不再参与新票定价。

导入允许部分成功。无法生成班次的车厂会给出原因，应查看报告；空计划不会覆盖已有时刻表。

## 参数

| 参数           | 含义                                                      |
| -------------- | --------------------------------------------------------- |
| `--dimension`  | 维度名称，避免不同维度相同 MTR ID 混淆                    |
| `--date`       | UTC 运营日，YYYY-MM-DD                                    |
| `--operator`   | 默认承运方；已设置车厂归属时继承车厂运营方                |
| `--capacity`   | 每相邻停站区间可用容量                                    |
| `--dry-run`    | 解析和输出报告，不写入业务数据库                          |
| `--merge`      | 为匹配的未出票班次保留 ID 和原有运营配置                  |
| `--world-tick` | 运营日 UTC 0 点的 Minecraft dayTime，用于游戏时间频率计划 |
| `--mapping`    | JSON 文件，显式选择车厂使用的计划股道                     |

mapping 文件格式：

```json
{ "车厂ID": "股道ID" }
```

未指定 mapping 时，可用股道按名称、颜色排序轮换分配。真实游戏还取决于车辆是否就绪，因此离线计划不能保证逐趟使用的物理车辆。

## 重新导入

```bash
php think mtr:import /path/to/world/mtr/minecraft/overworld   --dimension=minecraft/overworld --date=2026-10-26   --operator=LOCAL --capacity=100 --merge
```

同一维度、同一运营日允许替换未出票计划。已有任何客票（包括已退票）时，普通导入和 merge 均拒绝替换。导入通过命名锁、列车行锁和事务协调，旧版本留作历史记录。

## 仅回填里程

```bash
php think mtr:mileage /path/to/original/dimension   --dimension=minecraft/overworld --date=2026-10-26
```

回填要求原存档哈希、班次键及逐站站台一致。只更新累计总里程与限速分档，不改时刻、库存或已出票金额。不匹配时跳过并报告，不能用新地图替代历史路径。

## 离线计划的限制

- 实时时刻表使用车厂 `departures` 毫秒表，0 点按 UTC 解释。
- 游戏时间频率需要 `--world-tick`；该参数生成固定相位计划，不还原历史实际发车。
- 无限循环、手动驾驶、非 TRAIN 模式、路径缺失、站台无车站或车站归属重叠时，当前跳过并报告。
- 信号等待、服务器卡顿、实时晚点、车辆可用性及附加模组改写行为不在自由运行估算内。

存档解析、方向班次和运行时间算法见 [实现说明](architecture.md)，计费分类见 [里程计费](mileage-fares.md)。
