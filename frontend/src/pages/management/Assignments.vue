<script setup lang="ts">
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
import BusinessNotice from '@/components/BusinessNotice.vue';
</script>
<template>
  <div>
    <t-card :bordered="false" class="business-card"
      ><h2>车厂归属列表</h2>
      <div class="row"><BusinessField id="depotOwnerSearch" /><BusinessButton id="reloadDepotOwners" /></div>
      <div id="depotOwnershipList" class="table-wrap"></div></t-card
    ><t-card :bordered="false" class="business-card"
      ><h2>车厂归属运营方</h2>
      <div class="row">
        <BusinessField id="assignDepot" /><BusinessField id="depotOperator" />
        <div class="actions"><BusinessButton id="saveDepotOperator" /></div>
      </div>
      <p class="muted">
        该车厂全部线路、全部运营日班次以及后续导入归属于所选运营方。已经出票的班次不能改为另一运营方。
      </p>
      <BusinessNotice id="depotOwnerStatus"
    /></t-card>
    <t-card :bordered="false" class="business-card"
      ><h2>设置班次运营方</h2>
      <div class="row">
        <BusinessField id="assignTripId" /><BusinessField id="assignOperator" />
        <div class="actions"><BusinessButton id="assign" /></div>
      </div>
      <p class="muted">只允许修改尚未出票的班次，已有客票（含已退票）保留原运营方。</p></t-card
    >
  </div>
</template>
