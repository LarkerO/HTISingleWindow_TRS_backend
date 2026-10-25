import { defineStore } from 'pinia';
export const useSessionStore = defineStore('ticketing-session', {
  state: () => ({ role: '', connected: false, page: 'sales' }),
});
