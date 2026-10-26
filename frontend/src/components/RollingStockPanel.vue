<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import {
  Table as TTable,
  Select as TSelect,
  Input as TInput,
  InputNumber as TInputNumber,
  Switch as TSwitch,
} from 'tdesign-vue-next';
import { useSessionStore } from '@/store/session';
import { notify } from '@/business/ui';
const props = defineProps<{ request?: (path: string, method?: string, body?: any) => Promise<any> }>();
const session = useSessionStore();
const data = ref<any>(null),
  operators = ref<any[]>([]),
  busy = ref(false);
const selectedOperators = ref<string[]>([]),
  selectedStocks = ref<string[]>([]);
const type = reactive({ code: '', name: '', default_capacity: 0, multiplier: 1, sort_order: 5 });
const setting = reactive({ seat_code: 'FIRST', enabled: true, capacity: 100 });
const rate = reactive({ seat_code: 'FIRST', scope: 'operator_stock', multiplier: 1.5 });
const stockOptions = computed(() =>
  [...new Set<string>((data.value?.stocks || []).map((s: any) => s.stock_code))].map((value) => ({
    value,
    label: value,
  })),
);
const operatorOptions = computed(() => operators.value.map((o) => ({ value: o.code, label: `${o.name} (${o.code})` })));
const seatOptions = computed(() => (data.value?.seat_types || []).map((s: any) => ({ value: s.code, label: s.name })));
const rateSeatOptions = computed(() => seatOptions.value.filter((s: any) => !['SECOND', 'STANDING'].includes(s.value)));
const rows = computed(() =>
  (data.value?.stocks || [])
    .filter(
      (s: any) =>
        (!selectedOperators.value.length || selectedOperators.value.includes(s.operator_code)) &&
        (!selectedStocks.value.length || selectedStocks.value.includes(s.stock_code)),
    )
    .map((s: any) => ({
      ...s,
      operator_label: s.operator_code || '未分配',
      key: JSON.stringify([s.operator_code, s.stock_code]),
      summary: s.seats.map((v: any) => `${v.seat_name} ${v.capacity} 张 ×${v.multiplier_bp / 10000}`).join('；'),
    })),
);
async function run(fn: () => Promise<void>) {
  busy.value = true;
  try {
    await fn();
  } catch (e) {
    notify((e as Error).message, true);
  } finally {
    busy.value = false;
  }
}
async function load() {
  if (!props.request || session.role !== 'admin' || !session.connected) return;
  await run(async () => {
    const [a, b] = await Promise.all([props.request!('/admin/rolling-stock'), props.request!('/admin/operators')]);
    data.value = a.data;
    operators.value = b.data;
  });
}
watch(
  () => [props.request, session.connected, session.role],
  () => {
    data.value = null;
    operators.value = [];
    selectedOperators.value = [];
    selectedStocks.value = [];
    void load();
  },
  { immediate: true },
);
async function save(kind: string, items: any[]) {
  if (!data.value || !props.request) throw Error('请先加载车底配置');
  if (!items.length || items.length > 500) throw Error('请选择目标，每批最多 500 项');
  data.value = (
    await props.request('/admin/rolling-stock', 'PUT', { version: data.value.version, kind, rows: items })
  ).data;
  notify('车底配置已保存，新的查询和出票使用当前配置');
}
function targets(scope = 'operator_stock') {
  const ops = scope === 'stock' ? [''] : selectedOperators.value;
  const stocks = scope === 'operator' ? [''] : selectedStocks.value;
  if (!ops.length || !stocks.length) throw Error('请先选择目标运营方和车底');
  return ops.flatMap((operator_code) => stocks.map((stock_code) => ({ operator_code, stock_code })));
}
function saveType() {
  return run(async () => {
    await save('seat_types', [
      {
        code: type.code,
        name: type.name,
        default_capacity: Number(type.default_capacity),
        multiplier_bp: Math.round(Number(type.multiplier) * 10000),
        sort_order: Number(type.sort_order),
      },
    ]);
  });
}
function saveSettings(reset = false) {
  return run(async () => {
    await save(
      'seat_settings',
      targets().map((t) => ({
        ...t,
        seat_code: setting.seat_code,
        enabled: setting.enabled,
        capacity: Number(setting.capacity),
        reset,
      })),
    );
  });
}
function saveRates(reset = false) {
  return run(async () => {
    await save(
      'fare_rules',
      targets(rate.scope).map((t) => ({
        ...t,
        seat_code: rate.seat_code,
        multiplier_bp: Math.round(Number(rate.multiplier) * 10000),
        reset,
      })),
    );
  });
}
function editType(s: any) {
  Object.assign(type, s, { multiplier: s.multiplier_bp / 10000 });
}
</script>
<template>
  <div class="stock-management">
    <t-card :bordered="false" class="business-card">
      <h2>车底与席位库存</h2>
      <p class="muted">
        车底代码来自 MTR 停车轨道配置的列车
        ID，相同代码跨车厂、跨维度共用。席位按运营方配置，票额按班次与乘车区间独立占用、区间复用。当前均为不对号入座。
      </p>
      <t-button :loading="busy" @click="load">刷新配置</t-button>
      <span v-if="data"> 配置版本 {{ data.version }}</span>
      <div class="stock-grid">
        <label
          >目标运营方（可多选）<t-select
            v-model="selectedOperators"
            multiple
            filterable
            clearable
            :options="operatorOptions"
            placeholder="选择运营方"
        /></label>
        <label
          >目标车底（可多选）<t-select
            v-model="selectedStocks"
            multiple
            filterable
            clearable
            :options="stockOptions"
            placeholder="选择列车 ID"
        /></label>
      </div>
      <t-table
        row-key="key"
        :data="rows"
        :columns="[
          { colKey: 'stock_code', title: '车底代码' },
          { colKey: 'operator_label', title: '运营方' },
          { colKey: 'summary', title: '已启用席位 · 每班票额 · 倍率' },
        ]"
        :pagination="{ defaultCurrent: 1, defaultPageSize: 10, total: rows.length }"
      />
    </t-card>
    <t-card v-if="data" :bordered="false" class="business-card">
      <h2>席位种别与全局默认值</h2>
      <p class="muted">
        初始只启用无座、二等座。新增种别默认关闭，启用后采用其默认倍率。无座、二等座固定为公布票价 ×1。票额设为 0
        表示可查询、暂停发售。
      </p>
      <t-table
        row-key="code"
        :data="data.seat_types"
        :columns="[
          { colKey: 'code', title: '代码' },
          { colKey: 'name', title: '席位' },
          { colKey: 'default_capacity', title: '默认票额' },
          { colKey: 'multiplier_bp', title: '默认倍率' },
          { colKey: 'edit', title: '操作' },
        ]"
      >
        <template #multiplier_bp="{ row }">×{{ row.multiplier_bp / 10000 }}</template>
        <template #edit="{ row }"><t-button variant="text" @click="editType(row)">编辑</t-button></template>
      </t-table>
      <div class="stock-grid">
        <label>代码<t-input v-model="type.code" placeholder="例如 BUSINESS（唯一且稳定）" /></label>
        <label>席位名称<t-input v-model="type.name" placeholder="席位种别名称" /></label>
        <label
          >默认票额<t-input-number v-model="type.default_capacity" :min="0" :max="1000000" :decimal-places="0"
        /></label>
        <label>默认倍率<t-input-number v-model="type.multiplier" :min="1" :max="100" :decimal-places="4" /></label>
        <label>排序<t-input-number v-model="type.sort_order" :min="0" :decimal-places="0" /></label>
      </div>
      <t-button :loading="busy" @click="saveType">保存席位种别与默认值</t-button>
      <t-button
        variant="text"
        @click="Object.assign(type, { code: '', name: '', default_capacity: 0, multiplier: 1, sort_order: 5 })"
        >新增种别</t-button
      >
    </t-card>
    <t-card v-if="data" :bordered="false" class="business-card">
      <h2>批量启用席位与设置票额</h2>
      <p class="muted">
        作用于上方所选运营方 ×
        所选车底的全部组合，所有运营日的班次共用此配置。已售和有效占额超过新限额时，修改会被拒绝。
      </p>
      <div class="stock-grid">
        <label>席位<t-select v-model="setting.seat_code" :options="seatOptions" /></label>
        <label>启用<t-switch v-model="setting.enabled" /></label>
        <label>每班票额<t-input-number v-model="setting.capacity" :min="0" :max="1000000" :decimal-places="0" /></label>
      </div>
      <t-button :loading="busy" @click="saveSettings()">批量保存席位与票额</t-button>
      <t-button variant="outline" :disabled="busy" @click="saveSettings(true)">所选席位恢复默认</t-button>
      <t-table
        row-key="key"
        :data="
          data.seat_settings.map((r: any) => ({
            ...r,
            key: JSON.stringify([r.operator_code, r.stock_code, r.seat_code]),
          }))
        "
        :columns="[
          { colKey: 'operator_code', title: '运营方' },
          { colKey: 'stock_code', title: '车底' },
          { colKey: 'seat_code', title: '席位代码' },
          { colKey: 'enabled', title: '启用（1/0）' },
          { colKey: 'capacity', title: '票额' },
        ]"
        :pagination="{ defaultCurrent: 1, defaultPageSize: 10, total: data.seat_settings.length }"
      />
    </t-card>
    <t-card v-if="data" :bordered="false" class="business-card">
      <h2>批量设置特殊费率</h2>
      <p class="muted">
        优先级：运营方＋车底专属 > 运营方通用 > 车底通用 >
        全局默认。倍率乘公布票价的精确计算值，最终只四舍五入一次到分。
      </p>
      <div class="stock-grid">
        <label
          >范围<t-select
            v-model="rate.scope"
            :options="[
              { value: 'operator_stock', label: '所选运营方＋车底' },
              { value: 'operator', label: '所选运营方全部车底' },
              { value: 'stock', label: '所选车底全部运营方' },
            ]"
        /></label>
        <label>席位<t-select v-model="rate.seat_code" :options="rateSeatOptions" /></label>
        <label>倍率<t-input-number v-model="rate.multiplier" :min="1" :max="100" :decimal-places="4" /></label>
      </div>
      <t-button :loading="busy" @click="saveRates()">批量保存特殊费率</t-button>
      <t-button variant="outline" :disabled="busy" @click="saveRates(true)">移除所选范围的特殊费率</t-button>
      <t-table
        row-key="key"
        :data="
          data.fare_rules.map((r: any) => ({ ...r, key: JSON.stringify([r.operator_code, r.stock_code, r.seat_code]) }))
        "
        :columns="[
          { colKey: 'operator_code', title: '运营方（空为全部）' },
          { colKey: 'stock_code', title: '车底（空为全部）' },
          { colKey: 'seat_code', title: '席位代码' },
          { colKey: 'multiplier_bp', title: '倍率' },
        ]"
        :pagination="{ defaultCurrent: 1, defaultPageSize: 10, total: data.fare_rules.length }"
      >
        <template #multiplier_bp="{ row }">×{{ row.multiplier_bp / 10000 }}</template>
      </t-table>
    </t-card>
  </div>
</template>
<style scoped>
.stock-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin: 20px 0;
}
.stock-grid label {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.stock-management :deep(.t-button) {
  margin-right: 8px;
}
</style>
