<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useSessionStore } from '@/store/session';
import Workbench from '@/pages/Workbench.vue';
const session = useSessionStore();
const collapsed = ref(window.innerWidth < 800),
  dark = ref(false);
const resize = () => {
  if (window.innerWidth < 800) collapsed.value = true;
};
onMounted(() => window.addEventListener('resize', resize));
onUnmounted(() => window.removeEventListener('resize', resize));
const titles: Record<string, string> = {
  sales: '查询与出票',
  tickets: '查票与退票',
  documents: '旅客证件库',
  operatorTickets: '运营方客票 / TLV',
  management: '机构管理',
};
function navigate(page: string) {
  document.querySelector<HTMLElement>(`[data-tab="${page}"]`)?.click();
}
function activate(event: KeyboardEvent) {
  (event.currentTarget as HTMLElement).click();
}
const title = computed(() => titles[session.page] || titles.sales);
watch(dark, (value) => document.documentElement.setAttribute('theme-mode', value ? 'dark' : 'light'));
</script>
<template>
  <t-layout class="starter-layout">
    <t-aside :width="collapsed ? '64px' : '232px'" class="starter-aside">
      <t-menu :collapsed="collapsed" :value="session.page" class="starter-menu">
        <template #logo
          ><div class="brand">
            <span class="brand-mark">M</span><span v-if="!collapsed">MTR 客票工作台</span>
          </div></template
        >
        <div
          id="salesTab"
          data-tab="sales"
          :hidden="session.role === 'operator'"
          role="menuitem"
          tabindex="0"
          aria-label="查询与出票"
          @keydown.enter="activate"
          @keydown.space.prevent="activate"
        >
          <t-menu-item value="sales" @click="navigate('sales')"
            ><template #icon><t-icon name="search" /></template>查询与出票</t-menu-item
          >
        </div>
        <div
          id="ticketsTab"
          data-tab="tickets"
          :hidden="session.role === 'operator'"
          role="menuitem"
          tabindex="0"
          aria-label="查票与退票"
          @keydown.enter="activate"
          @keydown.space.prevent="activate"
        >
          <t-menu-item value="tickets" @click="navigate('tickets')"
            ><template #icon><t-icon name="ticket" /></template>查票与退票</t-menu-item
          >
        </div>
        <div
          id="documentsTab"
          data-tab="documents"
          :hidden="session.role === 'operator'"
          role="menuitem"
          tabindex="0"
          aria-label="旅客证件库"
          @keydown.enter="activate"
          @keydown.space.prevent="activate"
        >
          <t-menu-item value="documents" @click="navigate('documents')"
            ><template #icon><t-icon name="user" /></template>旅客证件库</t-menu-item
          >
        </div>
        <div
          id="operatorTicketsTab"
          data-tab="operatorTickets"
          :hidden="!['admin', 'operator'].includes(session.role)"
          role="menuitem"
          tabindex="0"
          aria-label="运营方客票 / TLV"
          @keydown.enter="activate"
          @keydown.space.prevent="activate"
        >
          <t-menu-item value="operatorTickets" @click="navigate('operatorTickets')"
            ><template #icon><t-icon name="file" /></template>运营方客票 / TLV</t-menu-item
          >
        </div>
        <div
          id="manageTab"
          data-tab="management"
          :hidden="session.role !== 'admin'"
          role="menuitem"
          tabindex="0"
          aria-label="机构管理"
          @keydown.enter="activate"
          @keydown.space.prevent="activate"
        >
          <t-menu-item value="management" @click="navigate('management')"
            ><template #icon><t-icon name="setting" /></template>机构管理</t-menu-item
          >
        </div>
      </t-menu>
    </t-aside>
    <t-layout class="starter-main">
      <t-header class="starter-header">
        <div class="header-start">
          <t-button variant="text" shape="square" aria-label="收起或展开导航" @click="collapsed = !collapsed"
            ><t-icon name="view-list" /></t-button
          ><span>{{ title }}</span>
        </div>
        <div class="header-end">
          <t-tag :theme="session.connected ? 'success' : 'default'" variant="light">{{
            session.connected ? '已连接' : '尚未连接'
          }}</t-tag
          ><t-button variant="text" shape="square" aria-label="切换明暗主题" @click="dark = !dark"
            ><t-icon :name="dark ? 'sunny' : 'moon'"
          /></t-button>
        </div>
      </t-header>
      <t-content class="starter-content">
        <t-breadcrumb
          ><t-breadcrumb-item>MTR 电子客票</t-breadcrumb-item
          ><t-breadcrumb-item>{{ title }}</t-breadcrumb-item></t-breadcrumb
        >
        <div class="page-heading">
          <div>
            <h1>{{ title }}</h1>
            <p>运行图查询与电子客票管理</p>
          </div>
          <t-tag variant="outline">UTC 时刻</t-tag>
        </div>
        <Workbench />
        <t-footer>测试工作台 · 本系统客票采用项目内部协议 · TDesign Starter Vue Next</t-footer>
      </t-content>
    </t-layout>
  </t-layout>
</template>
