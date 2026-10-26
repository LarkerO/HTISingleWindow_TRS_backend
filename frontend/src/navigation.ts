export const managementGroups = [
  {
    id: 'organizations',
    title: '机构管理',
    icon: 'setting',
    pages: [
      { id: 'operators', title: '运营方', description: '运营方列表、联系信息与启停管理' },
      { id: 'agencies', title: '出票方', description: '出票方列表、票号前缀与启停管理' },
      { id: 'agencyGrants', title: '授权与密钥', description: '维护出票方授权范围与访问密钥' },
      { id: 'whitelist', title: '出票白名单', description: '维护运营方出票白名单与密钥' },
      { id: 'assignments', title: '车厂与班次归属', description: '浏览车厂归属并配置承运运营方' },
    ],
  },
  {
    id: 'configuration',
    title: '业务配置',
    icon: 'view-list',
    pages: [
      { id: 'stationDictionary', title: '车站字典', description: '车站列表、电报码、显示名称与可见性' },
      { id: 'fareRates', title: '票价规则', description: '按轨道限速维护公布票价公里单价' },
      { id: 'rollingStock', title: '车底管理', description: '维护车底席位、区间票额与特殊费率' },
    ],
  },
  {
    id: 'maintenance',
    title: '数据维护',
    icon: 'file',
    pages: [{ id: 'dataCleanup', title: '运营日清理', description: '预览并清理指定运营日的运行图与客票数据' }],
  },
];
export const adminPages = managementGroups.flatMap((group) => group.pages);
export const adminPageIds = adminPages.map((page) => page.id);
