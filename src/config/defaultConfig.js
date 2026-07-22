/** Field meta for labels/enums used by FieldForm (subset of schema). */
export const FIELD_META = {
  ID: { type: 'integer', title: 'ID' },
  ASSIGNED_BY_ID: { type: 'user', title: 'Ответственный' },
  OPPORTUNITY: { type: 'double', title: 'Сумма' },
  CURRENCY_ID: { type: 'crm_currency', title: 'Валюта' },
  CONTACT_ID: { type: 'crm_contact', title: 'Контакт' },
  COMPANY_ID: { type: 'crm_company', title: 'Компания' },
  CLIENT: { type: 'client', title: 'Клиент' },
  UF_CRM_1784532842739: { type: 'string', title: 'Номер накладной' },
  UF_CRM_1764676465: { type: 'money', title: 'Сумма предоплаты' },
  UF_CRM_1782797821314: { type: 'date', title: 'Дата доставки заказа' },
  UF_CRM_1782798174634: {
    type: 'enumeration',
    title: 'Страна заказа',
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
  UF_CRM_1750940585581: { type: 'string', title: 'Маркировка' },
  UF_CRM_1764577842986: { type: 'boolean', title: 'Полная оплата за поставку получена' },
  UF_CRM_1764577872449: { type: 'boolean', title: 'Выдача товара без полной оплаты разрешена' },
  UF_CRM_1784636341021: {
    type: 'enumeration',
    title: 'Счет выставлен',
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
    items: [
      { ID: '912', VALUE: 'Да' },
      { ID: '913', VALUE: 'Нет' },
    ],
  },
  UF_CRM_LOCK_ACCOUNTANT: { type: 'employee', title: '[Тех] В работе у бухгалтера' },
  UF_CRM_LOCK_PURCHASER: { type: 'employee', title: '[Тех] В работе у закупщика' },
};

const accountantFields = [
  { code: 'ASSIGNED_BY_ID', mode: 'view' },
  { code: 'UF_CRM_1784532842739', mode: 'edit' },
  { code: 'OPPORTUNITY', mode: 'view' },
  { code: 'CURRENCY_ID', mode: 'view' },
  { code: 'UF_CRM_1764577842986', mode: 'edit' },
  { code: 'UF_CRM_1764676465', mode: 'edit' },
  { code: 'CLIENT', mode: 'view' },
  { code: 'UF_CRM_1782797821314', mode: 'view' },
  { code: 'UF_CRM_1782798174634', mode: 'view' },
  { code: 'UF_CRM_1784636341021', mode: 'edit' },
  { code: 'UF_CRM_1764332847245', mode: 'edit' },
  { code: 'UF_CRM_1764577872449', mode: 'edit' },
];

const purchaserFields = [
  { code: 'ASSIGNED_BY_ID', mode: 'view' },
  { code: 'UF_CRM_1784532842739', mode: 'view' },
  { code: 'UF_CRM_1783485774093', mode: 'edit' },
  { code: 'UF_CRM_1750940585581', mode: 'edit' },
  { code: 'OPPORTUNITY', mode: 'view' },
  { code: 'CURRENCY_ID', mode: 'view' },
  { code: 'CLIENT', mode: 'view' },
  { code: 'UF_CRM_1782797821314', mode: 'view' },
  { code: 'UF_CRM_1731673902240', mode: 'view' },
  { code: 'UF_CRM_1782798174634', mode: 'view' },
  { code: 'UF_CRM_1783486791226', mode: 'edit' },
  { code: 'UF_CRM_1783487251339', mode: 'edit' },
];

const managerFields = [
  { code: 'ASSIGNED_BY_ID', mode: 'edit' },
  { code: 'UF_CRM_1784532842739', mode: 'view' },
  { code: 'ID', mode: 'view' },
  { code: 'OPPORTUNITY', mode: 'edit' },
  { code: 'CURRENCY_ID', mode: 'edit' },
  { code: 'UF_CRM_1764676465', mode: 'edit' },
  { code: 'UF_CRM_1764577872449', mode: 'view' },
  { code: 'UF_CRM_1764577842986', mode: 'view' },
  { code: 'CLIENT', mode: 'edit' },
  { code: 'UF_CRM_1750940585581', mode: 'view' },
  { code: 'UF_CRM_1782797821314', mode: 'edit' },
  { code: 'UF_CRM_1782798174634', mode: 'edit' },
  { code: 'UF_CRM_1725623607217', mode: 'edit' },
  { code: 'UF_CRM_1731673902240', mode: 'edit' },
  { code: 'UF_CRM_1764578603013', mode: 'edit' },
  { code: 'UF_CRM_1764577192130', mode: 'edit' },
  { code: 'UF_CRM_1784636341021', mode: 'view' },
  { code: 'UF_CRM_1764332847245', mode: 'view' },
  { code: 'UF_CRM_1764332899326', mode: 'view' },
  { code: 'UF_CRM_1783485774093', mode: 'view' },
  { code: 'UF_CRM_1783486791226', mode: 'view' },
  { code: 'UF_CRM_1783487251339', mode: 'view' },
  { code: 'UF_CRM_1784524115744', mode: 'view' },
];

export const CONFIG_KEY = 'deal_widget_config';

export const defaultConfig = {
  version: 1,
  adminUserIds: [],
  funnels: {
    '15': {
      name: 'New Опт Алматы',
      departments: {
        // Отдел маркетинга (ID 154) — тест; приоритет ролей: accountant > purchaser > manager
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
  if (!stored || typeof stored !== 'object') {
    return structuredClone(defaultConfig);
  }
  return {
    ...defaultConfig,
    ...stored,
    version: stored.version ?? defaultConfig.version,
    adminUserIds: Array.isArray(stored.adminUserIds) ? stored.adminUserIds : [],
    funnels: {
      ...defaultConfig.funnels,
      ...(stored.funnels || {}),
    },
  };
}
