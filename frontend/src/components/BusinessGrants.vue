<script setup lang="ts">
import { Checkbox, CheckboxGroup } from 'tdesign-vue-next';
import { grantChoices } from '@/business/ui';
defineProps<{ id: string }>();
</script>
<template>
  <CheckboxGroup :id="id" v-model="grantChoices.value" class="business-grants"
    ><Checkbox v-for="option in grantChoices.options" :key="option.value" :value="option.value"
      ><span :data-option-value="option.value">{{ option.label }}</span></Checkbox
    ></CheckboxGroup
  >
</template>
