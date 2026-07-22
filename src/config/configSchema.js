/**
 * Lightweight shape checks for appOption config.
 */
export function validateConfigShape(config) {
  const errors = [];
  if (!config || typeof config !== 'object') {
    return ['Конфиг пуст или повреждён'];
  }
  if (!config.funnels || typeof config.funnels !== 'object') {
    errors.push('Нет объекта funnels');
  } else {
    for (const [id, funnel] of Object.entries(config.funnels)) {
      if (!funnel?.name) errors.push(`Воронка ${id}: нет name`);
      if (!funnel?.departments) errors.push(`Воронка ${id}: нет departments`);
      if (!funnel?.lockFields) errors.push(`Воронка ${id}: нет lockFields`);
      if (!funnel?.fields) errors.push(`Воронка ${id}: нет fields`);
    }
  }
  return errors;
}
