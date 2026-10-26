<script setup lang="ts">
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
</script>
<template>
  <div>
    <t-card :bordered="false" class="business-card">
      <h2>车站字典</h2>
      <p class="muted">
        导入时自动从 MTR
        的“中文|英文”名称提取中英文；人工改名保留。未配置车站默认不可见。电报码为全系统唯一的三个英文字母，归属企业绑定已有运营方代码。MTR
        重新导入保留字典设置。
      </p>
      <div class="row">
        <BusinessField id="dictSearch" /><BusinessButton id="dictLoad" /><BusinessButton id="dictPrev" /><BusinessButton
          id="dictNext"
        />
      </div>
      <p id="dictPageInfo" class="muted"></p>
      <div id="dictionaryList" class="table-wrap"></div>
      <div id="dictionaryEditor" class="editor-section" hidden>
        <h3>编辑车站</h3>
        <div class="row">
          <BusinessField id="dictId" /><BusinessField id="dictVisible" /><BusinessField
            id="dictOperator"
          /><BusinessField id="dictCode" />
        </div>
        <div class="row">
          <BusinessField id="dictZh" /><BusinessField id="dictEn" /><BusinessField id="dictLevel1" /><BusinessField
            id="dictLevel2"
          /><BusinessButton id="dictSave" />
        </div>
        <BusinessButton id="dictCancel" /></div
    ></t-card>
  </div>
</template>
