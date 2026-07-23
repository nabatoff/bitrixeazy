import { bx24Call } from './bx24.js';

/**
 * Load funnel stages for category.
 * @returns {Promise<{ STATUS_ID: string, NAME: string, SORT: number }[]>}
 */
export async function loadFunnelStages(categoryId) {
  const id = Number(categoryId);
  const list = await bx24Call('crm.dealcategory.stage.list', { id: Number.isFinite(id) ? id : 0 });
  const arr = Array.isArray(list) ? list : [];
  return arr
    .map((s) => ({
      STATUS_ID: s.STATUS_ID || s.statusId || s.STATUS_ID,
      NAME: s.NAME || s.name || s.STATUS_ID,
      SORT: Number(s.SORT ?? s.sort ?? 0),
    }))
    .sort((a, b) => a.SORT - b.SORT);
}
