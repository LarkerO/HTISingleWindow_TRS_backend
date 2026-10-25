<script setup lang="ts">
import { Button } from 'tdesign-vue-next';
import { definitions, emitControl, models } from '@/business/ui';
defineOptions({ inheritAttrs: false });
const props = defineProps<{ id: string }>();
function click(event: MouseEvent) { if (definitions[props.id].type !== 'submit') void emitControl(props.id, 'click', event); }
</script>
<template><Button :id="id" :theme="definitions[id].theme" :variant="definitions[id].variant" :type="definitions[id].type" :disabled="models[id].disabled" :loading="!!models[id].dataset.busy" @click="click">{{ definitions[id].label }}</Button></template>
