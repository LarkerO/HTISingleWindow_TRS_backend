<script setup lang="ts">
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
import BusinessForm from '@/components/BusinessForm.vue';
</script>
<template>
  <div>
    <t-card :bordered="false" class="business-card"
      ><h2>出票方列表</h2>
      <div class="row"><BusinessField id="agencySearch" /><BusinessButton id="newAgency" /></div>
      <div id="agenciesList" class="table-wrap"></div
    ></t-card>
    <t-card id="agencyEditor" :bordered="false" class="business-card" hidden
      ><h2>新增 / 编辑出票方</h2>
      <BusinessForm id="agencyForm"
        ><BusinessField id="agCode" /><BusinessField id="agName" /><BusinessField id="agIssuer" /><BusinessField
          id="agContact" /><BusinessField id="agActive" />
        <div class="row" style="margin-top: 14px">
          <BusinessButton id="agencyFormSubmit" /><BusinessButton id="resetAgency" /></div></BusinessForm
    ></t-card>
  </div>
</template>
