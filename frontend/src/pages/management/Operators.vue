<script setup lang="ts">
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
import BusinessForm from '@/components/BusinessForm.vue';
</script>
<template>
  <div>
    <t-card :bordered="false" class="business-card"
      ><h2>运营方列表</h2>
      <div class="row"><BusinessField id="operatorSearch" /><BusinessButton id="newOperator" /></div>
      <div id="operatorsList" class="table-wrap"></div
    ></t-card>
    <t-card id="operatorEditor" :bordered="false" class="business-card" hidden
      ><h2>新增 / 编辑运营方</h2>
      <BusinessForm id="operatorForm"
        ><BusinessField id="opCode" /><BusinessField id="opName" /><BusinessField id="opContact" /><BusinessField
          id="opWhitelist" /><BusinessField id="opActive" />
        <div class="row" style="margin-top: 14px">
          <BusinessButton id="operatorFormSubmit" /><BusinessButton id="resetOperator" /></div></BusinessForm
    ></t-card>
  </div>
</template>
