/** Field meta for labels/enums used by FieldForm (subset of schema). */
export const FIELD_META = {
  ID: { type: 'integer', title: 'ID сделки', narrow: true },
  ASSIGNED_BY_ID: { type: 'user', title: 'Ответственный', narrow: true },
  OPPORTUNITY: { type: 'double', title: 'Сумма', narrow: true },
  CURRENCY_ID: { type: 'crm_currency', title: 'Валюта', narrow: true },
  CONTACT_ID: { type: 'crm_contact', title: 'Контакт' },
  COMPANY_ID: { type: 'crm_company', title: 'Компания' },
  CLIENT: { type: 'client', title: 'Клиент' },
  UF_CRM_1784532842739: { type: 'string', title: 'Номер накладной', narrow: true },
  UF_CRM_1764676465: { type: 'money', title: 'Сумма предоплаты', narrow: true },
  UF_CRM_1782797821314: { type: 'date', title: 'Дата доставки заказа', narrow: true },
  UF_CRM_1782798174634: {
    type: 'enumeration',
    title: 'Страна заказа',
    narrow: true,
    items: [
      { ID: '903', VALUE: 'Китай' },
      { ID: '904', VALUE: 'Малайзия' },
      { ID: '905', VALUE: 'Эквадор' },
      { ID: '906', VALUE: 'Нидерланды' },
      { ID: '907', VALUE: 'Кения' },
    ],
  },
  UF_CRM_1731673902240: {
    type: 'enumeration',
    title: 'Город опт Алматы',
    narrow: true,
    items: [
      { ID: '552', VALUE: 'Алматы' },
      { ID: '553', VALUE: 'Шымкент' },
      { ID: '562', VALUE: 'Тараз' },
      { ID: '563', VALUE: 'Кызылорда' },
      { ID: '566', VALUE: 'Туркестан' },
      { ID: '807', VALUE: 'Конаев' },
      { ID: '808', VALUE: 'Талгар' },
      { ID: '809', VALUE: 'Каскелен' },
      { ID: '810', VALUE: 'Иссык' },
      { ID: '811', VALUE: 'Узанагаш' },
      { ID: '812', VALUE: 'Жаркент' },
      { ID: '833', VALUE: 'Талдыкорган' },
      { ID: '841', VALUE: 'Мерке' },
      { ID: '842', VALUE: 'Аягоз' },
      { ID: '843', VALUE: 'Сарыагаш' },
      { ID: '844', VALUE: 'Балхаш' },
      { ID: '845', VALUE: 'Бишкек' },
    ],
  },
  UF_CRM_1750940585581: { type: 'string', title: 'Маркировка', narrow: true },
  UF_CRM_1764577842986: { type: 'boolean', title: 'Полная оплата за поставку получена' },
  UF_CRM_1764577872449: { type: 'boolean', title: 'Выдача товара без полной оплаты разрешена' },
  UF_CRM_1784636341021: {
    type: 'enumeration',
    title: 'Счет выставлен',
    narrow: true,
    items: [{ ID: '914', VALUE: 'Да' }],
  },
  UF_CRM_1764332847245: { type: 'boolean', title: 'Предоплата получена' },
  UF_CRM_1764332899326: { type: 'boolean', title: 'Одобрено без предоплаты' },
  UF_CRM_1764578603013: { type: 'boolean', title: 'Запросить выставление счета' },
  UF_CRM_1764577192130: {
    type: 'enumeration',
    title: 'Запрос одобрения на закуп без предоплаты',
    items: [{ ID: '869', VALUE: 'Прошу одобрение на закуп без предоплаты' }],
  },
  UF_CRM_1725623607217: { type: 'file', title: 'Договор с клиентом' },
  UF_CRM_1783485774093: {
    type: 'enumeration',
    title: 'Взят в работу закупщиком',
    narrow: true,
    items: [{ ID: '908', VALUE: 'Да' }],
  },
  UF_CRM_1783486791226: {
    type: 'enumeration',
    title: 'Закуплено полностью или с изменениями?',
    items: [
      { ID: '910', VALUE: 'Закуплено полностью' },
      { ID: '911', VALUE: 'Закуплено с изменениями' },
    ],
  },
  UF_CRM_1783487251339: {
    type: 'string',
    title: 'Укажите комментарий какие изменения произошли в закупе',
  },
  UF_CRM_1784524115744: {
    type: 'enumeration',
    title: 'Заказ выдан',
    narrow: true,
    items: [
      { ID: '912', VALUE: 'Да' },
      { ID: '913', VALUE: 'Нет' },
    ],
  },
  UF_CRM_LOCK_ACCOUNTANT: { type: 'employee', title: '[Тех] В работе у бухгалтера' },
  UF_CRM_LOCK_PURCHASER: { type: 'employee', title: '[Тех] В работе у закупщика' },
};

const S = {
  deal: 'Сделка',
  managerEdit: 'Менеджер — можно редактировать',
  accountantView: 'Бухгалтерия — только просмотр',
  purchaserView: 'Закуп — только просмотр',
  accountantEdit: 'Бухгалтерия — редактирование',
  purchaserEdit: 'Закуп — редактирование',
};

/** Менеджер: шапка → свои edit → бухгалтерия view → закуп view */
const managerFields = [
  { code: 'ID', mode: 'view', section: S.deal },
  { code: 'ASSIGNED_BY_ID', mode: 'edit', section: S.deal },
  { code: 'CLIENT', mode: 'view', section: S.deal },
  { code: 'OPPORTUNITY', mode: 'edit', section: S.deal },
  { code: 'UF_CRM_1764676465', mode: 'edit', section: S.deal },
  { code: 'UF_CRM_1750940585581', mode: 'view', section: S.deal },
  { code: 'UF_CRM_1782797821314', mode: 'edit', section: S.deal },
  { code: 'UF_CRM_1782798174634', mode: 'edit', section: S.deal },
  { code: 'UF_CRM_1731673902240', mode: 'edit', section: S.deal },

  { code: 'UF_CRM_1725623607217', mode: 'edit', section: S.managerEdit },
  { code: 'UF_CRM_1764578603013', mode: 'edit', section: S.managerEdit },
  { code: 'UF_CRM_1764577192130', mode: 'edit', section: S.managerEdit },

  { code: 'UF_CRM_1784532842739', mode: 'view', section: S.accountantView },
  { code: 'UF_CRM_1764577842986', mode: 'view', section: S.accountantView },
  { code: 'UF_CRM_1764577872449', mode: 'view', section: S.accountantView },
  { code: 'UF_CRM_1784636341021', mode: 'view', section: S.accountantView },
  { code: 'UF_CRM_1764332847245', mode: 'view', section: S.accountantView },
  { code: 'UF_CRM_1764332899326', mode: 'view', section: S.accountantView },

  { code: 'UF_CRM_1783485774093', mode: 'view', section: S.purchaserView },
  { code: 'UF_CRM_1783486791226', mode: 'view', section: S.purchaserView },
  { code: 'UF_CRM_1783487251339', mode: 'view', section: S.purchaserView },
  { code: 'UF_CRM_1784524115744', mode: 'view', section: S.purchaserView },
];

const accountantFields = [
  { code: 'ID', mode: 'view', section: S.deal },
  { code: 'ASSIGNED_BY_ID', mode: 'view', section: S.deal },
  { code: 'CLIENT', mode: 'view', section: S.deal },
  { code: 'OPPORTUNITY', mode: 'view', section: S.deal },
  { code: 'UF_CRM_1782797821314', mode: 'view', section: S.deal },
  { code: 'UF_CRM_1782798174634', mode: 'view', section: S.deal },

  { code: 'UF_CRM_1784532842739', mode: 'edit', section: S.accountantEdit },
  { code: 'UF_CRM_1764676465', mode: 'edit', section: S.accountantEdit },
  { code: 'UF_CRM_1764577842986', mode: 'edit', section: S.accountantEdit },
  { code: 'UF_CRM_1764577872449', mode: 'edit', section: S.accountantEdit },
  { code: 'UF_CRM_1784636341021', mode: 'edit', section: S.accountantEdit },
  { code: 'UF_CRM_1764332847245', mode: 'edit', section: S.accountantEdit },
];

const purchaserFields = [
  { code: 'ID', mode: 'view', section: S.deal },
  { code: 'ASSIGNED_BY_ID', mode: 'view', section: S.deal },
  { code: 'CLIENT', mode: 'view', section: S.deal },
  { code: 'OPPORTUNITY', mode: 'view', section: S.deal },
  { code: 'UF_CRM_1784532842739', mode: 'view', section: S.deal },
  { code: 'UF_CRM_1782797821314', mode: 'view', section: S.deal },
  { code: 'UF_CRM_1782798174634', mode: 'view', section: S.deal },
  { code: 'UF_CRM_1731673902240', mode: 'view', section: S.deal },

  { code: 'UF_CRM_1783485774093', mode: 'edit', section: S.purchaserEdit },
  { code: 'UF_CRM_1750940585581', mode: 'edit', section: S.purchaserEdit },
  { code: 'UF_CRM_1783486791226', mode: 'edit', section: S.purchaserEdit },
  { code: 'UF_CRM_1783487251339', mode: 'edit', section: S.purchaserEdit },
];

export const CONFIG_KEY = 'deal_widget_config';

export const defaultConfig = {
  version: 2,
  adminUserIds: [],
  funnels: {
    '15': {
      name: 'New Опт Алматы',
      departments: {
        manager: [154],
        accountant: [154],
        purchaser: [154],
      },
      lockFields: {
        accountant: 'UF_CRM_LOCK_ACCOUNTANT',
        purchaser: 'UF_CRM_LOCK_PURCHASER',
      },
      fields: {
        accountant: accountantFields,
        purchaser: purchaserFields,
        manager: managerFields,
      },
    },
  },
};

export function mergeConfig(stored) {
  const base = structuredClone(defaultConfig);
  if (!stored || typeof stored !== 'object') return base;

  const funnelIds = new Set([
    ...Object.keys(base.funnels),
    ...Object.keys(stored.funnels || {}),
  ]);

  const funnels = {};
  for (const id of funnelIds) {
    const d = base.funnels[id] || {
      name: `Воронка ${id}`,
      departments: { manager: [], accountant: [], purchaser: [] },
      lockFields: {
        accountant: 'UF_CRM_LOCK_ACCOUNTANT',
        purchaser: 'UF_CRM_LOCK_PURCHASER',
      },
      fields: structuredClone(base.funnels['15'].fields),
    };
    const s = stored.funnels?.[id] || {};
    funnels[id] = {
      ...d,
      name: s.name || d.name,
      departments: s.departments || d.departments,
      lockFields: { ...d.lockFields, ...(s.lockFields || {}) },
      // раскладка полей всегда из кода
      fields: d.fields,
    };
  }

  return {
    ...base,
    adminUserIds: Array.isArray(stored.adminUserIds) ? stored.adminUserIds : [],
    funnels,
  };
}
