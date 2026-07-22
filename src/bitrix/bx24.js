function getBX24() {
  if (typeof window === 'undefined' || !window.BX24) {
    throw new Error('window.BX24 недоступен — откройте приложение внутри Bitrix24');
  }
  return window.BX24;
}

export function initBx24() {
  return new Promise((resolve) => {
    getBX24().init(() => resolve(getBX24()));
  });
}

export function bx24Call(method, params = {}) {
  return new Promise((resolve, reject) => {
    getBX24().callMethod(method, params, (result) => {
      if (result.error()) {
        const err = result.error();
        reject(new Error(typeof err === 'string' ? err : err.ex || err.error_description || JSON.stringify(err)));
        return;
      }
      resolve(result.data());
    });
  });
}

export function getPlacementInfo() {
  try {
    return getBX24().placement.info();
  } catch {
    return null;
  }
}

export function getAuth() {
  try {
    return getBX24().getAuth();
  } catch {
    return null;
  }
}

export function isAdmin() {
  try {
    return Boolean(getBX24().isAdmin());
  } catch {
    return false;
  }
}

export function appOptionGet(name) {
  return new Promise((resolve) => {
    getBX24().appOption.get(name, (value) => resolve(value));
  });
}

export function appOptionSet(name, value) {
  return new Promise((resolve) => {
    getBX24().appOption.set(name, value, () => resolve(true));
  });
}

export function fitWindow() {
  try {
    getBX24().fitWindow();
  } catch {
    /* ignore outside iframe */
  }
}

/** Extract deal ID from CRM_DEAL_DETAIL_TAB placement. */
export function resolveDealId(placement) {
  if (!placement) return null;
  const opts = placement.options || {};
  const id =
    opts.ID ||
    opts.DEAL_ID ||
    opts.ENTITY_ID ||
    placement.placementOptions?.ID ||
    null;
  return id != null ? String(id) : null;
}
