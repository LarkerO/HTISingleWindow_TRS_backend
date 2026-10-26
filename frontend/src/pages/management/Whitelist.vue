<script setup lang="ts">
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
import BusinessNotice from '@/components/BusinessNotice.vue';
</script>
<template>
  <div>
    <t-card :bordered="false" class="business-card"
      ><h2>运营方出票白名单</h2>
      <div class="row">
        <BusinessField id="whitelistOperator" /><BusinessField id="whitelistMode" />
        <div class="actions"><BusinessButton id="saveWhitelistMode" /><BusinessButton id="rotateOperatorKey" /></div>
      </div>
      <p class="muted">开启后空列表表示不允许任何出票方出新票；关闭后保留列表以便再次开启。既有客票仍可查询和退票。</p>
      <div class="row">
        <BusinessField id="whitelistAgency" />
        <div class="actions"><BusinessButton id="addWhitelistAgency" /></div>
      </div>
      <div id="whitelistList" class="table-wrap"></div>
      <BusinessNotice id="operatorKeyResult"
    /></t-card>
  </div>
</template>
