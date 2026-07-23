import {
  approvedWithoutPrepay,
  fullPayReceived,
  hasPurchaseWithoutPrepayRequest,
  invoiceIssued,
  invoiceRequested,
  issueWithoutPayAllowed,
  needsIssueWithoutPayDecision,
  orderIssued,
  prepayReceived,
  purchaseStatus,
} from './fieldHelpers.js';

/**
 * @returns {{ id: string, tone: 'info'|'warn'|'ok'|'danger', title: string, text: string }[]}
 */
export function deriveDealAlerts(deal) {
  if (!deal) return [];
  const alerts = [];

  if (invoiceRequested(deal) && !invoiceIssued(deal)) {
    alerts.push({
      id: 'wait-invoice',
      tone: 'warn',
      title: 'Ждём выставления счёта',
      text: 'Менеджер запросил счёт — бухгалтерия ещё не отметила «Счёт выставлен».',
    });
  } else if (invoiceIssued(deal)) {
    alerts.push({
      id: 'invoice-ok',
      tone: 'ok',
      title: 'Счёт выставлен',
      text: 'Бухгалтерия отметила выставление счёта.',
    });
  }

  if (hasPurchaseWithoutPrepayRequest(deal) && !approvedWithoutPrepay(deal)) {
    alerts.push({
      id: 'wait-director-prepay',
      tone: 'warn',
      title: 'Ждём одобрение руководителя',
      text: 'Запрос на закуп без предоплаты — нужно решение директора.',
    });
  }

  if (!prepayReceived(deal) && !approvedWithoutPrepay(deal)) {
    alerts.push({
      id: 'block-purchase',
      tone: 'danger',
      title: 'В закуп пока нельзя',
      text: 'Нужна предоплата или одобрение закупа без предоплаты.',
    });
  } else if (prepayReceived(deal)) {
    alerts.push({
      id: 'prepay-ok',
      tone: 'ok',
      title: 'Предоплата получена',
      text: 'Можно двигать сделку в закуп (если остальные условия ок).',
    });
  } else if (approvedWithoutPrepay(deal)) {
    alerts.push({
      id: 'approved-no-prepay',
      tone: 'ok',
      title: 'Одобрено без предоплаты',
      text: 'Руководитель разрешил закуп без предоплаты.',
    });
  }

  if (!purchaseStatus(deal)) {
    alerts.push({
      id: 'need-purchase',
      tone: 'info',
      title: 'Закуп ещё не закрыт',
      text: 'Закупщик должен указать статус «закуплено полностью / с изменениями».',
    });
  }

  if (needsIssueWithoutPayDecision(deal)) {
    alerts.push({
      id: 'wait-issue-allow',
      tone: 'warn',
      title: 'Нужно решение по выдаче без оплаты',
      text: 'Полной оплаты нет — руководитель может разрешить выдачу без полной оплаты.',
    });
  } else if (issueWithoutPayAllowed(deal)) {
    alerts.push({
      id: 'issue-allowed',
      tone: 'ok',
      title: 'Выдача без полной оплаты разрешена',
      text: 'Можно выдавать заказ без полной оплаты.',
    });
  }

  if (fullPayReceived(deal)) {
    alerts.push({
      id: 'full-pay',
      tone: 'ok',
      title: 'Полная оплата получена',
      text: 'Оплата за поставку закрыта.',
    });
  }

  if (orderIssued(deal)) {
    alerts.push({
      id: 'order-out',
      tone: 'ok',
      title: 'Заказ выдан',
      text: 'Кладовщик отметил выдачу.',
    });
  }

  // не больше 3 самых актуальных: danger/warn сначала, потом ok/info
  const rank = { danger: 0, warn: 1, info: 2, ok: 3 };
  return alerts.sort((a, b) => rank[a.tone] - rank[b.tone]).slice(0, 3);
}
