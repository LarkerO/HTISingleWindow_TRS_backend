# 车站字典

升级先执行 `php think ticketing:schema`。管理工作台「机构管理」中的「车站字典」显示全部导入车站，支持搜索、分页、编辑。

导入车站默认不可见。配置唯一的三个英文字母电报码（自动转大写）、一个已有运营企业代码、中文名和英文名后，可以设为可见。一级、二级行政区为自由文本，可留空。车站归属与班次承运企业分别管理。重新导入 MTR 只更新源名称，不覆盖字典。

## 管理接口（管理员）

- `GET /api/v1/admin/stations?q=ABC&page=1&dimension=...`：包含未配置、隐藏车站；每页 500 条，返回 `total`。
- `PUT /api/v1/admin/stations`：保存完整配置，示例：

```json
{
  "station_id": "minecraft:overworld:123",
  "visible": true,
  "operator_code": "ABCD",
  "telegraph_code": "ABC",
  "name_zh": "示例站",
  "name_en": "Example",
  "admin_level_1": "示例省",
  "admin_level_2": "示例市"
}
```

`station_id` 必须来自导入列表；`operator_code` 必须为已有运营方的代码。电报码跨维度唯一，重复返回 409，隐藏站仍保留电报码占用。

## 查询与出票

`GET /api/v1/stations` 只返回可见站，`q` 支持源名称、中文名、英文名、电报码搜索，支持 `page`（每页 500 条）。`name` 返回配置中文名，`source_name` 保留 MTR 原名。

`GET /api/v1/journeys?origin=ABC&destination=XYZ&date=2099-06-01` 中两站参数可分别使用车站 ID 或电报码，电报码不区分大小写。

`POST /api/v1/tickets` 保持原来的 `trip_id`、站序出票，也支持：

```json
{ "trip_id": 123, "origin": "ABC", "destination": "XYZ", "passenger": "旅客" }
```

同一班次重复经过一个车站时，需要提供对应 `origin_seq` / `destination_seq` 消除歧义；同时提供电报码和站序时必须匹配。隐藏站不能新查区间或出票（包括直接使用站序），已出票的幂等重试、查票、退票继续可用。查票结果的上下车站新增 `station` 字典信息。

测试：`php tests/station-dictionary.php`，夹具在事务结束后回滚。
