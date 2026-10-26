<script setup lang="ts">
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
</script>
<template>
  <div>
    <t-card :bordered="false" class="business-card"
      ><h2>按运营日清空数据</h2>
      <div class="row">
        <BusinessField id="clearDate" /><BusinessField id="clearScope" />
        <div class="actions"><BusinessButton id="clearPreview" /></div>
      </div>
      <p class="muted">
        按班次运营日清理全部维度和历史导入版本，包含跨午夜停站。客票范围包含已退票记录、乘车联、幂等请求和审计记录；全部数据同时清除运行图。车站、地图目录、机构、密钥及全局证件库和证件日志保留；客票
        TLV 同客票一起清理。仅清运行图时若有关联客票则拒绝。
      </p>
      <pre id="clearSummary"></pre>
      <div class="row">
        <BusinessField id="clearConfirm" />
        <div class="actions"><BusinessButton id="clearExecute" /></div></div
    ></t-card>
  </div>
</template>
