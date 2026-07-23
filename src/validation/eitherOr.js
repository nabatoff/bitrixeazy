import { isFilled, isTruthyBool } from '../deal/fieldHelpers.js';

/**
 * Client-side either/or (+ conditional) validation before save.
 * @returns {string[]} error messages
 */
export function validateEitherOr(values, role) {
  const errors = [];

  const fullPay = isTruthyBool(values.UF_CRM_1764577842986);
  const releaseWithout = isTruthyBool(values.UF_CRM_1764577872449);
  if (fullPay && releaseWithout) {
    errors.push(
      'Нельзя одновременно: «Полная оплата за поставку получена» и «Выдача товара без полной оплаты разрешена»'
    );
  }

  const prepay = isTruthyBool(values.UF_CRM_1764332847245);
  const approvedNoPrepay = isTruthyBool(values.UF_CRM_1764332899326);
  if (prepay && approvedNoPrepay) {
    errors.push('Нельзя одновременно: «Предоплата получена» и «Одобрено без предоплаты»');
  }

  if (role === 'purchaser' || role === 'manager') {
    const purchaseStatus = String(
      Array.isArray(values.UF_CRM_1783486791226)
        ? values.UF_CRM_1783486791226[0]
        : values.UF_CRM_1783486791226 || ''
    );
    if (purchaseStatus === '911' && !isFilled(values.UF_CRM_1783487251339)) {
      errors.push('При закупке с изменениями укажите комментарий');
    }
  }

  return errors;
}
