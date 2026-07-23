import { FIELD_META } from '../config/defaultConfig.js';
import {
  approvedWithoutPrepay,
  fullPayReceived,
  isFilled,
  issueWithoutPayAllowed,
  orderIssued,
  prepayReceived,
  purchaseStatus,
} from '../deal/fieldHelpers.js';

function fieldErr(code, extra) {
  const title = FIELD_META[code]?.title || code;
  return { code, title, message: extra || `Не заполнено: ${title}` };
}

/**
 * Zones by STAGE_ID semantics (C15 + generic).
 * - purchase zone: PREPAYMENT_INVOIC / NEW / etc. moving toward purchase
 * - issue zone: EXECUTING / UC_ / final work
 * - success: WON
 */
function stageKind(stageId) {
  const s = String(stageId || '');
  if (/:WON$/i.test(s) || s === 'WON') return 'success';
  if (/EXECUTING|UC_|FINAL|DELIVER/i.test(s)) return 'issue';
  if (/PREPAYMENT|PREPAY|INVOIC|PURCHAS|ЗАКУП/i.test(s)) return 'purchase';
  return 'other';
}

/**
 * Can we move deal to targetStage?
 * @returns {{ ok: boolean, errors: { code: string, title: string, message: string }[] }}
 */
export function canMoveToStage(deal, fromStageId, toStageId) {
  const errors = [];
  const toKind = stageKind(toStageId);
  const fromKind = stageKind(fromStageId);

  // назад — без гардов
  const stagesOrderHint = [fromStageId, toStageId];
  void stagesOrderHint;

  if (toKind === 'purchase' || (toKind === 'other' && /PREPAYMENT|INVOIC/i.test(String(toStageId)))) {
    if (!prepayReceived(deal) && !approvedWithoutPrepay(deal)) {
      errors.push(
        fieldErr(
          'UF_CRM_1764332847245',
          'Для перехода в закуп нужна предоплата или одобрение без предоплаты'
        )
      );
      errors.push(fieldErr('UF_CRM_1764332899326', '…или «Одобрено без предоплаты»'));
    }
  }

  if (toKind === 'issue') {
    if (!purchaseStatus(deal)) {
      errors.push(
        fieldErr(
          'UF_CRM_1783486791226',
          'Для выдачи закупщик должен указать статус закупа'
        )
      );
    }
  }

  if (toKind === 'success') {
    const payOk = fullPayReceived(deal) || issueWithoutPayAllowed(deal);
    if (!payOk) {
      errors.push(
        fieldErr(
          'UF_CRM_1764577842986',
          'Для успеха нужна полная оплата или разрешение выдачи без полной оплаты'
        )
      );
    }
    if (!orderIssued(deal)) {
      errors.push(fieldErr('UF_CRM_1784524115744', 'Для успеха заказ должен быть выдан'));
    }
    if (!purchaseStatus(deal)) {
      errors.push(fieldErr('UF_CRM_1783486791226', 'Статус закупа не заполнен'));
    }
  }

  // если уходим вперёд из «до закупа» в EXECUTING — тоже требуем закуп
  if (fromKind !== 'issue' && toKind === 'issue' && !isFilled(deal?.UF_CRM_1783486791226)) {
    if (!errors.some((e) => e.code === 'UF_CRM_1783486791226')) {
      errors.push(fieldErr('UF_CRM_1783486791226', 'Сначала закройте закуп'));
    }
  }

  // дедуп по code+message
  const seen = new Set();
  const uniq = [];
  for (const e of errors) {
    const k = `${e.code}:${e.message}`;
    if (seen.has(k)) continue;
    seen.add(k);
    uniq.push(e);
  }

  return { ok: uniq.length === 0, errors: uniq };
}
