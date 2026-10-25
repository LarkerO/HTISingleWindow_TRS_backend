import { computed, defineComponent, h, markRaw, nextTick, reactive, ref, render } from 'vue';
import type { Component, VNodeChild } from 'vue';
import {
  Alert,
  Button,
  Collapse,
  CollapsePanel,
  DialogPlugin,
  MessagePlugin,
  Table,
  Tag,
  Textarea,
} from 'tdesign-vue-next';
import definitionsJson from './controls.json';
import BusinessField from '@/components/BusinessField.vue';
export interface Choice {
  value: string;
  label: string;
  disabled?: boolean;
}
export interface Definition {
  kind: string;
  label?: string;
  value?: string;
  options?: Choice[];
  fields?: string[];
  submit?: string;
  [key: string]: any;
}
export const definitions = definitionsJson as Record<string, Definition>;
export const models = reactive<Record<string, any>>({});
const listeners = new Map<string, Map<string, ((event: any) => any)[]>>();
const handlers = new Map<string, Record<string, any>>();
const proxies = new Map<string, any>();
const formResetters = new Map<string, () => void>();
export const grantChoices = reactive<{ options: Choice[]; value: string[] }>({ options: [], value: [] });
export function addControl(id: string, definition: Definition) {
  definitions[id] = definition;
  models[id] = {
    value: String(definition.value ?? ''),
    options: definition.options || [],
    disabled: !!definition.disabled,
    dataset: { busy: '' },
    text: definition.text || '',
    visible: !!definition.visible,
    theme: definition.theme || 'info',
  };
}
for (const [id, definition] of Object.entries(definitions)) addControl(id, definition);
export function registerFormReset(id: string, reset: () => void) {
  formResetters.set(id, reset);
}
export function resetForm(id: string) {
  for (const field of definitions[id].fields || []) models[field].value = String(definitions[field].value ?? '');
  nextTick(() => formResetters.get(id)?.());
}
export async function emitControl(id: string, type: string, original?: Event) {
  const control = getControl(id);
  const event = { type, currentTarget: control, target: control, preventDefault: () => original?.preventDefault() };
  try {
    for (const fn of listeners.get(id)?.get(type) || []) await fn(event);
    await handlers.get(id)?.['on' + type]?.(event);
  } catch (error) {
    notify((error as Error).message, true);
  }
}
export function changeControl(id: string, value: any, original?: Event) {
  models[id].value = String(value ?? '');
  void emitControl(id, 'input', original);
  void emitControl(id, 'change', original);
}
export function setChoices(id: string, options: Choice[]) {
  const model = models[id],
    old = model.value;
  model.options = options;
  model.value = options.some((o) => o.value === old) ? old : options[0]?.value || '';
}
export function notify(text: string, error = false) {
  Object.assign(models.status, { text, visible: true, theme: error ? 'error' : 'success' });
  if (error) void MessagePlugin.error({ content: text, duration: 5000 });
}
export function formRules(id: string) {
  return Object.fromEntries(
    (definitions[id].fields || []).map((field) => {
      const meta = definitions[field],
        rules: any[] = [];
      if (meta.required)
        rules.push({ required: true, whitespace: true, message: '请填写' + meta.label, trigger: 'blur' });
      if (meta.pattern)
        rules.push({
          pattern: new RegExp('^(?:' + meta.pattern + ')$'),
          message: meta.label + '格式不正确',
          trigger: 'blur',
        });
      if (meta.kind === 'number')
        rules.push({
          validator: (value: any) =>
            (!meta.required && value === '') ||
            (/^\d+$/.test(String(value)) &&
              Number(value) >= Number(meta.min ?? 0) &&
              Number(value) <= Number(meta.max ?? Number.MAX_SAFE_INTEGER)),
          message: '请输入范围内的整数',
          trigger: 'blur',
        });
      if (meta.kind === 'date')
        rules.push({
          validator: (value: any) =>
            (!meta.required && !value) ||
            (/^\d{4}-\d{2}-\d{2}$/.test(value) &&
              !Number.isNaN(Date.parse(value)) &&
              (field !== 'documentBirthDate' || value <= new Date().toISOString().slice(0, 10))),
          message: '请选择有效日期，出生日期不能晚于今天',
          trigger: 'blur',
        });
      return [field, rules];
    }),
  );
}
// A small compatibility boundary connects established business operations to
// reactive component models. No hidden native input or select is rendered.
export function getControl(id: string): any {
  if (!models[id]) return getContainer(id);
  if (proxies.has(id)) return proxies.get(id);
  const proxy = new Proxy(
    {},
    {
      get(_target, property: string) {
        const model = models[id],
          definition = definitions[id];
        if (property === 'value') return String(model.value ?? '');
        if (property === 'hidden') return !model.visible;
        if (property === 'textContent') return model.text;
        if (property === 'reset') return () => resetForm(id);
        if (property === 'querySelector') return () => getControl(definition.submit!);
        if (property === 'addEventListener')
          return (type: string, fn: (event: any) => any) => {
            if (!listeners.has(id)) listeners.set(id, new Map());
            const list = listeners.get(id)!;
            list.set(type, [...(list.get(type) || []), fn]);
          };
        if (property.startsWith('on')) return handlers.get(id)?.[property];
        if (property in model) return model[property];
        const element = document.getElementById(id);
        const value = element && Reflect.get(element, property, element);
        return typeof value === 'function' ? value.bind(element) : value;
      },
      set(_target, property: string, value: any) {
        const model = models[id];
        if (property === 'value') model.value = String(value ?? '');
        else if (property === 'hidden') model.visible = !value;
        else if (property === 'textContent') model.text = String(value ?? '');
        else if (property.startsWith('on')) {
          if (!handlers.has(id)) handlers.set(id, {});
          handlers.get(id)![property] = value;
        } else model[property] = value;
        return true;
      },
    },
  );
  proxies.set(id, proxy);
  return proxy;
}
const containerProxies = new WeakMap<HTMLElement, any>();
export function getContainer(id: string): any {
  const element = document.getElementById(id);
  if (!element) throw Error('Missing UI target: ' + id);
  if (containerProxies.has(element)) return containerProxies.get(element);
  const proxy = new Proxy(element, {
    get(target, property) {
      if (property === 'rawElement') return target;
      if (property === 'replaceChildren')
        return (...nodes: Node[]) => {
          render(null, target);
          target.replaceChildren(...nodes);
        };
      const value = Reflect.get(target, property, target);
      return typeof value === 'function' ? value.bind(target) : value;
    },
    set(target, property, value) {
      return Reflect.set(target, property, value, target);
    },
  });
  containerProxies.set(element, proxy);
  return proxy;
}
export function createAction(label: string, fn: () => any, secondary = true): HTMLElement {
  const node = document.createElement('span');
  const action = reactive({ label, disabled: false, busy: false, secondary });
  (node as any).__businessAction = { model: action, fn: markRaw(fn) };
  Object.defineProperty(node, 'disabled', {
    get: () => action.disabled,
    set: (value) => {
      action.disabled = value;
    },
  });
  return node;
}
const ActionView = defineComponent({
  props: { action: { type: Object, required: true } },
  setup(props) {
    return () =>
      h(
        Button as Component,
        {
          variant: props.action.model.secondary ? 'outline' : 'base',
          theme: 'primary',
          disabled: props.action.model.disabled,
          loading: props.action.model.busy,
          onClick: async () => {
            if (props.action.model.busy) return;
            props.action.model.busy = true;
            try {
              await props.action.fn();
            } catch (error) {
              notify((error as Error).message, true);
            } finally {
              props.action.model.busy = false;
            }
          },
        },
        () => props.action.model.label,
      );
  },
});
export function createSelect(id: string, options: Choice[], value: string) {
  addControl(id, { kind: 'select', label: '', value, options });
  const node = document.createElement('span');
  (node as any).__businessField = id;
  Object.defineProperty(node, 'value', {
    get: () => models[id].value,
    set: (v) => {
      models[id].value = String(v);
    },
  });
  return node;
}
function contentVNode(node: any): VNodeChild {
  if (!(node instanceof Node)) return String(node ?? '');
  if (node.nodeType === Node.TEXT_NODE) return node.textContent || '';
  if ((node as any).__businessAction) return h(ActionView, { action: (node as any).__businessAction });
  if ((node as any).__businessField) return h(BusinessField, { id: (node as any).__businessField });
  const element = node as HTMLElement,
    tag = element.tagName.toLowerCase();
  const children = [...element.childNodes].map(contentVNode);
  if (tag === 'details') {
    const summary = element.querySelector('summary');
    return h(Collapse as Component, { borderless: true }, () =>
      h(CollapsePanel as Component, { value: 'detail', header: summary?.textContent || '查看详情' }, () =>
        [...element.childNodes].filter((child) => child !== summary).map(contentVNode),
      ),
    );
  }
  if (element.classList.contains('badge'))
    return h(
      Tag as Component,
      { theme: element.classList.contains('off') ? 'danger' : 'success', variant: 'light' },
      () => element.textContent,
    );
  const attributes = Object.fromEntries([...element.attributes].map((a) => [a.name, a.value]));
  return h(tag, attributes, children);
}
export function renderContent(target: any) {
  const element: HTMLElement = target.rawElement || target;
  const nodes = [...element.childNodes];
  render(null, element);
  element.replaceChildren();
  render(h('div', { class: 'business-content' }, nodes.map(contentVNode)), element);
}
export function renderTable(target: any, head: string[], rows: any[][]) {
  const element: HTMLElement = target.rawElement || target;
  const data = rows.map((cells, index) => ({ rowId: index, cells: markRaw(cells) }));
  const columns = head.map((title, index) => ({
    title,
    colKey: 'cell' + index,
    cell: (_h: any, context: any) => contentVNode(context.row.cells[index]),
  }));
  if (!(element as any)._vnode) element.replaceChildren();
  render(
    h(Table as Component, { rowKey: 'rowId', data, columns, hover: true, size: 'small', empty: '暂无数据' }),
    element,
  );
}
export function confirmAction(message: string): Promise<boolean> {
  return new Promise((resolve) => {
    let settled = false;
    const finish = (value: boolean) => {
      if (settled) return;
      settled = true;
      dialog.destroy();
      resolve(value);
    };
    const dialog = DialogPlugin.confirm({
      header: '确认操作',
      body: message,
      theme: 'warning',
      confirmBtn: '确认',
      cancelBtn: '取消',
      closeOnOverlayClick: false,
      onConfirm: () => finish(true),
      onClose: () => finish(false),
    });
  });
}
export function requestReason(message: string): Promise<string | null> {
  return new Promise((resolve) => {
    const reason = ref(''),
      error = ref(false);
    let settled = false;
    const finish = (value: string | null) => {
      if (settled) return;
      settled = true;
      dialog.destroy();
      resolve(value);
    };
    const dialog = DialogPlugin.confirm({
      header: message,
      confirmBtn: '确认撤销',
      cancelBtn: '取消',
      closeOnOverlayClick: false,
      body: () =>
        h('div', [
          h(Textarea as Component, {
            value: reason.value,
            maxlength: 500,
            placeholder: '填写撤销原因，保留记录与日志',
            onChange: (v: string) => {
              reason.value = v;
              error.value = false;
            },
          }),
          error.value ? h(Alert as Component, { theme: 'error', message: '请填写撤销原因' }) : null,
        ]),
      onConfirm: () => {
        if (!reason.value.trim()) {
          error.value = true;
          return;
        }
        finish(reason.value.trim());
      },
      onClose: () => finish(null),
    });
  });
}
