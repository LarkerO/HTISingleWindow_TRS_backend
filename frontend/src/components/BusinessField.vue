<script setup lang="ts">
import { computed, h } from 'vue';
import type { Component } from 'vue';
import {
  DatePicker,
  FormItem,
  Input,
  InputNumber,
  Radio,
  RadioGroup,
  Select,
  Textarea,
  TimePicker,
} from 'tdesign-vue-next';
import { changeControl, definitions, models } from '@/business/ui';
defineOptions({ inheritAttrs: false });
const props = defineProps<{ id: string }>();
const model = computed(() => models[props.id]);
const meta = computed(() => definitions[props.id]);
function handleEnter(event: KeyboardEvent) {
  if (['date', 'time'].includes(meta.value.kind)) event.preventDefault();
}
function change(value: any, context?: any) {
  if (context?.type === 'props' || context?.trigger === 'initial') return;
  changeControl(props.id, value, context?.e);
}
function renderControl() {
  const definition = meta.value,
    value = model.value;
  const common = {
    value: value.value,
    disabled: value.disabled,
    readonly: !!definition.readonly,
    placeholder: definition.placeholder || '请输入' + definition.label,
    onChange: change,
    'aria-label': definition.label,
  };
  if (definition.kind === 'select')
    return h(Select as Component, {
      ...common,
      options: value.options.map((option: any) => ({
        ...option,
        content: () => h('span', { 'data-option-value': option.value }, option.label),
      })),
      filterable: true,
      clearable: true,
      popupProps: { overlayClassName: 'workbench-select-popup' },
    });
  if (definition.kind === 'radio')
    return h(RadioGroup as Component, common, () =>
      value.options.map((option: any) =>
        h(Radio as Component, { value: option.value, disabled: option.disabled }, () =>
          h('span', { 'data-option-value': option.value }, option.label),
        ),
      ),
    );
  if (definition.kind === 'date')
    return h(DatePicker as Component, {
      ...common,
      placeholder: definition.placeholder || '请选择日期',
      format: 'YYYY-MM-DD',
      valueType: 'YYYY-MM-DD',
      allowInput: true,
      clearable: true,
      disableDate:
        props.id === 'documentBirthDate'
          ? (date: Date) =>
              `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` >
              new Date().toISOString().slice(0, 10)
          : undefined,
    });
  if (definition.kind === 'time')
    return h(TimePicker as Component, {
      ...common,
      placeholder: '请选择时间',
      format: 'HH:mm',
      allowInput: true,
      clearable: true,
    });
  if (definition.kind === 'number')
    return h(InputNumber as Component, {
      ...common,
      value: value.value === '' ? undefined : value.value,
      largeNumber: true,
      min: definition.min === undefined ? undefined : Number(definition.min),
      max: definition.max === undefined ? undefined : Number(definition.max),
      decimalPlaces: 0,
      theme: 'column',
    });
  if (definition.kind === 'textarea')
    return h(Textarea as Component, {
      ...common,
      maxlength: definition.maxlength ? Number(definition.maxlength) : undefined,
      autosize: { minRows: Number(definition.rows || 4), maxRows: 8 },
    });
  return h(Input as Component, {
    ...common,
    type: definition.kind === 'password' ? 'password' : 'text',
    autocomplete: definition.autocomplete || 'off',
    maxlength: definition.maxlength ? Number(definition.maxlength) : undefined,
    clearable: definition.kind !== 'password' && !definition.readonly,
  });
}
const FieldInput = () => renderControl();
</script>
<template>
  <div :id="id" class="control-field" :data-kind="meta.kind" @keydown.enter="handleEnter">
    <FormItem :name="meta.form ? id : undefined" :label="meta.label" label-align="top"><FieldInput /></FormItem>
  </div>
</template>
