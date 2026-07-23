/** Shared deal field helpers */

export function isTruthyBool(v) {
  return v === true || v === 'Y' || v === '1' || v === 1;
}

export function isFilled(v) {
  if (v == null) return false;
  if (typeof v === 'string') return v.trim() !== '';
  if (Array.isArray(v)) return v.length > 0;
  return true;
}

export function enumId(v) {
  if (v == null || v === '') return '';
  return String(Array.isArray(v) ? v[0] : v);
}

export function hasPurchaseWithoutPrepayRequest(deal) {
  return isFilled(deal?.UF_CRM_1764577192130);
}

export function invoiceRequested(deal) {
  return isTruthyBool(deal?.UF_CRM_1764578603013);
}

export function invoiceIssued(deal) {
  return isFilled(deal?.UF_CRM_1784636341021) || enumId(deal?.UF_CRM_1784636341021) === '914';
}

export function prepayReceived(deal) {
  return isTruthyBool(deal?.UF_CRM_1764332847245);
}

export function approvedWithoutPrepay(deal) {
  return isTruthyBool(deal?.UF_CRM_1764332899326);
}

export function fullPayReceived(deal) {
  return isTruthyBool(deal?.UF_CRM_1764577842986);
}

export function issueWithoutPayAllowed(deal) {
  return isTruthyBool(deal?.UF_CRM_1764577872449);
}

export function purchaseStatus(deal) {
  return enumId(deal?.UF_CRM_1783486791226);
}

export function orderIssued(deal) {
  return enumId(deal?.UF_CRM_1784524115744) === '912';
}

/** Запрос выдачи без полной оплаты: явного UF в схеме нет — эвристика по состоянию сделки */
export function needsIssueWithoutPayDecision(deal) {
  if (issueWithoutPayAllowed(deal) || fullPayReceived(deal)) return false;
  return Boolean(purchaseStatus(deal));
}
