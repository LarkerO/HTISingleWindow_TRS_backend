<script setup lang="ts">
import { Alert } from 'tdesign-vue-next';
import { models } from '@/business/ui';
defineOptions({ inheritAttrs: false });
defineProps<{ id: string }>();
</script>
<template>
  <Alert
    :id="id"
    v-show="models[id].visible"
    class="business-notice"
    :theme="models[id].theme"
    :message="models[id].text"
  />
</template>
