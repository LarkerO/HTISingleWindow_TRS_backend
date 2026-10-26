<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue';
import type { Component } from 'vue';
import {
  SearchIcon,
  TicketIcon,
  UserIcon,
  FileIcon,
  SettingIcon,
  ViewListIcon,
  SunnyIcon,
  MoonIcon,
} from 'tdesign-icons-vue-next';
const props = defineProps<{ name: string }>();
const host = ref<HTMLElement>();
const icons: Record<string, Component> = {
  search: SearchIcon,
  ticket: TicketIcon,
  user: UserIcon,
  file: FileIcon,
  vehicle: ViewListIcon,
  setting: SettingIcon,
  'view-list': ViewListIcon,
  sunny: SunnyIcon,
  moon: MoonIcon,
};
// Upstream SVG groups have unprefixed IDs such as "search", which conflict
// with existing form control IDs. Scope both definitions and references.
function scopeIds() {
  const root = host.value;
  if (!root) return;
  const replacements = new Map<string, string>();
  for (const element of root.querySelectorAll('[id]')) {
    const old = element.id;
    if (old.startsWith('trs-icon-')) continue;
    const id = 'trs-icon-' + props.name + '-' + old;
    replacements.set(old, id);
    element.id = id;
  }
  for (const element of root.querySelectorAll('*'))
    for (const attribute of [...element.attributes]) {
      if (attribute.name === 'id') continue;
      let value = attribute.value;
      for (const [old, id] of replacements)
        value = value
          .replaceAll('url(#' + old + ')', 'url(#' + id + ')')
          .replace(new RegExp('^#' + old + '$'), '#' + id);
      if (value !== attribute.value) element.setAttribute(attribute.name, value);
    }
}
onMounted(scopeIds);
watch(
  () => props.name,
  async () => {
    await nextTick();
    scopeIds();
  },
);
</script>
<template>
  <span ref="host" class="workbench-icon" aria-hidden="true"><component :is="icons[name]" /></span>
</template>
