import { createRouter, createWebHashHistory } from 'vue-router';
import { useSessionStore } from '@/store/session';
import { adminPageIds } from '@/navigation';
const EmptyPage = { render: () => null };
const pages = ['sales', 'tickets', 'documents', 'operatorTickets', ...adminPageIds];
const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', redirect: '/sales' },
    { path: '/management', redirect: '/operators' },
    ...pages.map((page) => ({ path: '/' + page, component: EmptyPage })),
    { path: '/:pathMatch(.*)*', redirect: '/sales' },
  ],
});
router.beforeEach((to) => {
  const session = useSessionStore();
  if (adminPageIds.includes(to.path.slice(1)) && session.role !== 'admin') return '/sales';
  if (to.path === '/operatorTickets' && !['admin', 'operator'].includes(session.role)) return '/sales';
  if (session.role === 'operator' && to.path !== '/operatorTickets') return '/operatorTickets';
  session.page = to.path.slice(1);
});
export default router;
