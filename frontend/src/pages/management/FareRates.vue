<script setup lang="ts">
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
</script>
<template>
  <div>
    <t-card :bordered="false" class="business-card"
      ><h2>轨道公里单价</h2>
      <p class="muted">
        按轨道标称限速分档计费，单价单位为分/公里。速度越高单价必须越高；各档里程合计后四舍五入到分。修改仅影响新报价及新出票，已出票金额保留快照。
      </p>
      <table class="business-table rate-list">
        <thead>
          <tr>
            <th>轨道限速</th>
            <th>公布单价（分/公里）</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="speed in [2, 20, 30, 40, 60, 80, 120, 160, 200, 300, 900]" :key="speed">
            <td>{{ speed }} km/h</td>
            <td><BusinessField :id="'fareRate' + speed" /></td>
          </tr>
        </tbody>
      </table>
      <div class="row"><BusinessButton id="saveFareRates" /><BusinessButton id="reloadFareRates" /></div>
      <p id="farePolicyInfo" class="muted"></p
    ></t-card>
  </div>
</template>
