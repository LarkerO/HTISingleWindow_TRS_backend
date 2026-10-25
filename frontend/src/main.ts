import { createApp } from 'vue';
import { createPinia } from 'pinia';
import {
  Layout,
  Aside,
  Header,
  Content,
  Footer,
  Menu,
  MenuItem,
  Button,
  Tag,
  Card,
  Breadcrumb,
  BreadcrumbItem,
} from 'tdesign-vue-next';
import WorkbenchIcon from './components/WorkbenchIcon.vue';
import App from './App.vue';
import router from './router';
import 'tdesign-vue-next/es/style/index.css';
import './style/workbench.less';
const app = createApp(App).use(createPinia()).use(router);
for (const [name, component] of Object.entries({
  TLayout: Layout,
  TAside: Aside,
  THeader: Header,
  TContent: Content,
  TFooter: Footer,
  TMenu: Menu,
  TMenuItem: MenuItem,
  TButton: Button,
  TTag: Tag,
  TCard: Card,
  TBreadcrumb: Breadcrumb,
  TBreadcrumbItem: BreadcrumbItem,
}))
  app.component(name, component);
app.component('TIcon', WorkbenchIcon).mount('#app');
