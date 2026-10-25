<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Form } from 'tdesign-vue-next';
import type { FormInstanceFunctions, SubmitContext } from 'tdesign-vue-next';
import { definitions, emitControl, formRules, models, registerFormReset } from '@/business/ui';
defineOptions({ inheritAttrs: false });
const props = defineProps<{ id: string }>();
const form = ref<FormInstanceFunctions>();
const data = computed(() =>
  Object.fromEntries((definitions[props.id].fields || []).map((field) => [field, models[field].value])),
);
const rules = computed(() => formRules(props.id));
onMounted(() => registerFormReset(props.id, () => form.value?.clearValidate()));
function submit(context: SubmitContext) {
  if (context.validateResult === true) void emitControl(props.id, 'submit', context.e as Event);
}
</script>
<template>
  <Form
    :id="id"
    ref="form"
    :data="data"
    :rules="rules"
    label-align="top"
    scroll-to-first-error="smooth"
    @submit="submit"
    ><slot
  /></Form>
</template>
