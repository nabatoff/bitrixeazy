import {
  approvedWithoutPrepay,
  fullPayReceived,
  hasPurchaseWithoutPrepayRequest,
  invoiceIssued,
  invoiceRequested,
  isTruthyBool,
  needsIssueWithoutPayDecision,
  prepayReceived,
  purchaseStatus,
} from './fieldHelpers.js';

const INFO_CODES = new Set([
  'ID',
  'ASSIGNED_BY_ID',
  'CLIENT',
  'OPPORTUNITY',
  'UF_CRM_1782797821314',
  'UF_CRM_1782798174634',
  'UF_CRM_1731673902240',
  'UF_CRM_1784532842739',
  'UF_CRM_1750940585581',
]);

/**
 * Filter role fieldDefs to what is relevant for the current deal state.
 * @returns {{ fields: object[], emptyMessage: string|null, hideFormUntilLock: boolean }}
 */
export function filterVisibleFields(role, fieldDefs, deal, { lockIsMine = false } = {}) {
  const defs = fieldDefs || [];
  const byCode = (code, mode) => {
    const found = defs.find((f) => f.code === code);
    if (found) return { ...found, mode: mode || found.mode };
    return { code, mode: mode || 'edit', section: 'Задача' };
  };

  if (role === 'director') {
    const tasks = [];
    if (hasPurchaseWithoutPrepayRequest(deal) && !approvedWithoutPrepay(deal)) {
      tasks.push(byCode('UF_CRM_1764332899326', 'edit'));
    }
    if (needsIssueWithoutPayDecision(deal)) {
      tasks.push(byCode('UF_CRM_1764577872449', 'edit'));
    }
    const info = [
      byCode('CLIENT', 'view'),
      byCode('OPPORTUNITY', 'view'),
      byCode('ASSIGNED_BY_ID', 'view'),
      byCode('UF_CRM_1782797821314', 'view'),
    ].map((f) => ({ ...f, section: 'Сделка' }));
    const taskFields = tasks.map((f) => ({ ...f, section: 'Решение руководителя' }));
    return {
      fields: [...info, ...taskFields],
      emptyMessage: taskFields.length
        ? null
        : 'Нет ожидающих решений. Ждите запрос на одобрение.',
      hideFormUntilLock: false,
    };
  }

  if (role === 'storekeeper') {
    return {
      fields: [
        { ...byCode('CLIENT', 'view'), section: 'Сделка' },
        { ...byCode('OPPORTUNITY', 'view'), section: 'Сделка' },
        { ...byCode('UF_CRM_1782797821314', 'view'), section: 'Сделка' },
        { ...byCode('UF_CRM_1731673902240', 'view'), section: 'Сделка' },
        { ...byCode('UF_CRM_1750940585581', 'view'), section: 'Сделка' },
        { ...byCode('UF_CRM_1784524115744', 'edit'), section: 'Выдача' },
      ],
      emptyMessage: null,
      hideFormUntilLock: false,
    };
  }

  if (role === 'accountant') {
    const info = [
      byCode('CLIENT', 'view'),
      byCode('OPPORTUNITY', 'view'),
      byCode('ASSIGNED_BY_ID', 'view'),
      byCode('UF_CRM_1782797821314', 'view'),
      byCode('UF_CRM_1782798174634', 'view'),
    ].map((f) => ({ ...f, section: 'Сделка' }));

    const tasks = [];
    if (invoiceRequested(deal) && !invoiceIssued(deal)) {
      tasks.push(
        { ...byCode('UF_CRM_1784636341021', 'edit'), section: 'Текущая задача' },
        { ...byCode('UF_CRM_1764676465', 'edit'), section: 'Текущая задача' }
      );
    } else if (
      invoiceIssued(deal) &&
      !prepayReceived(deal) &&
      !approvedWithoutPrepay(deal)
    ) {
      tasks.push(
        { ...byCode('UF_CRM_1764332847245', 'edit'), section: 'Текущая задача' },
        { ...byCode('UF_CRM_1764676465', 'edit'), section: 'Текущая задача' }
      );
    } else if (
      (prepayReceived(deal) || approvedWithoutPrepay(deal) || invoiceIssued(deal)) &&
      !fullPayReceived(deal) &&
      !isTruthyBool(deal?.UF_CRM_1764577872449)
    ) {
      // фаза полной оплаты (когда уже прошли предоплату/счёт, и выдача без оплаты ещё не закрыла тему)
      const purchased = Boolean(purchaseStatus(deal));
      if (purchased || invoiceIssued(deal)) {
        tasks.push(
          { ...byCode('UF_CRM_1764577842986', 'edit'), section: 'Текущая задача' },
          { ...byCode('UF_CRM_1784532842739', 'edit'), section: 'Текущая задача' }
        );
      }
    }

    return {
      fields: [...info, ...tasks],
      emptyMessage: tasks.length
        ? null
        : 'Сейчас задач нет — ждите уведомление (счёт / предоплата / полная оплата).',
      hideFormUntilLock: false,
    };
  }

  if (role === 'purchaser') {
    if (!lockIsMine) {
      return {
        fields: [
          { ...byCode('CLIENT', 'view'), section: 'Сделка' },
          { ...byCode('OPPORTUNITY', 'view'), section: 'Сделка' },
        ],
        emptyMessage: null,
        hideFormUntilLock: true,
      };
    }
    const status = purchaseStatus(deal);
    const fields = [
      { ...byCode('CLIENT', 'view'), section: 'Сделка' },
      { ...byCode('OPPORTUNITY', 'view'), section: 'Сделка' },
      { ...byCode('UF_CRM_1784532842739', 'view'), section: 'Сделка' },
      { ...byCode('UF_CRM_1750940585581', 'edit'), section: 'Закуп' },
      { ...byCode('UF_CRM_1783485774093', 'edit'), section: 'Закуп' },
      { ...byCode('UF_CRM_1783486791226', 'edit'), section: 'Закуп' },
    ];
    if (status === '911') {
      fields.push({ ...byCode('UF_CRM_1783487251339', 'edit'), section: 'Закуп' });
    }
    return { fields, emptyMessage: null, hideFormUntilLock: false };
  }

  // manager — все поля роли, task-filter не режет (степпер отдельно)
  return { fields: defs, emptyMessage: null, hideFormUntilLock: false };
}

export function isInfoField(code) {
  return INFO_CODES.has(code);
}
