/**
 * Resolve role for current user on a deal funnel.
 * Priority: accountant > purchaser > manager
 */
export function resolveRole({ categoryId, userDepartments, config, userId, adminFlag }) {
  const funnel = config?.funnels?.[String(categoryId)];
  const isAppAdmin =
    Boolean(adminFlag) ||
    (Array.isArray(config?.adminUserIds) &&
      config.adminUserIds.map(String).includes(String(userId)));

  if (!funnel) {
    return {
      role: null,
      funnel: null,
      isAppAdmin,
      reason: `Воронка ${categoryId} не настроена в конфиге`,
    };
  }

  const deps = new Set((userDepartments || []).map(Number));
  const hit = (list) => (list || []).map(Number).some((id) => deps.has(id));

  let role = null;
  if (hit(funnel.departments?.accountant)) role = 'accountant';
  else if (hit(funnel.departments?.purchaser)) role = 'purchaser';
  else if (hit(funnel.departments?.manager)) role = 'manager';

  // Dev / first-run: no departments configured yet — admin sees manager screen
  if (!role && isAppAdmin) {
    const emptyDeps =
      !(funnel.departments?.accountant || []).length &&
      !(funnel.departments?.purchaser || []).length &&
      !(funnel.departments?.manager || []).length;
    if (emptyDeps) role = 'manager';
  }

  return { role, funnel, isAppAdmin, reason: role ? null : 'Нет роли для вашего отдела' };
}
