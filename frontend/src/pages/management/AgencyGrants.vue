<script setup lang="ts">
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
import BusinessNotice from '@/components/BusinessNotice.vue';
import BusinessGrants from '@/components/BusinessGrants.vue';
</script>
<template>
  <div>
    <t-card :bordered="false" class="business-card" style="margin-top: 18px"
      ><h2>出票方白名单关联与密钥</h2>
      <BusinessField id="grantAgency" /><BusinessGrants id="grantOperators" />
      <div class="row"><BusinessButton id="saveGrants" /><BusinessButton id="rotateKey" /></div>
      <p class="muted">
        停用机构立即阻止其新请求；移出已启用白名单只阻止新出票，不影响原有客票的查票和退票。运营方关闭白名单时所有启用出票方均可出票。重置密钥后旧密钥立即失效。
      </p>
      <BusinessNotice id="keyResult"
    /></t-card>
  </div>
</template>
