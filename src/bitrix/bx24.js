function deepErrorString(err) {
  if (err == null) return 'Unknown Bitrix error';
  if (typeof err === 'string') return err;
  if (err instanceof Error) return err.message || String(err);
  const status = err.status || err.statusCode;
  const code = typeof err.error === 'string' ? err.error : err.ex || err.error_code;
  const desc =
    err.error_description ||
    err.error_msg ||
    (typeof err.message === 'string' ? err.message : null);
  const parts = [status, code, desc].filter((x) => x != null && typeof x !== 'object');
  if (parts.length) return parts.join(' — ');
  try {
    return JSON.stringify(err);
  } catch {
    return String(err);
  }
}

function getBX24() {
  if (typeof window === 'undefined' || !window.BX24) {
    throw new Error('window.BX24 недоступен — откройте приложение внутри Bitrix24');
  }
  return window.BX24;
}

function getPostAuth() {
  return typeof window !== 'undefined' ? window.__BITRIX_POST_AUTH__ : null;
}

export function initBx24() {
  return new Promise((resolve) => {
    getBX24().init(() => resolve(getBX24()));
  });
}

export function refreshAuth() {
  return new Promise((resolve) => {
    try {
      getBX24().refreshAuth(() => resolve(true));
    } catch {
      resolve(false);
    }
  });
}

export function bx24Call(method, params = {}) {
  return new Promise((resolve, reject) => {
    getBX24().callMethod(method, params, (result) => {
      if (result.error()) {
        reject(new Error(deepErrorString(result.error())));
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

/** Unified auth: BX24.getAuth() or injected POST auth from /api/frame */
export function getAuth() {
  try {
    const fromBx = getBX24().getAuth();
    if (fromBx?.access_token) return fromBx;
  } catch {
    /* ignore */
  }
  const post = getPostAuth();
  if (post?.AUTH_ID) {
    return {
      access_token: post.AUTH_ID,
      refresh_token: post.REFRESH_ID || '',
      expires_in: post.AUTH_EXPIRES || '',
      domain: String(post.DOMAIN || '').replace(/^https?:\/\//, ''),
      member_id: post.member_id,
    };
  }
  return null;
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

export function isInstallMode() {
  try {
    return Boolean(getBX24().install);
  } catch {
    return false;
  }
}

export function installFinish() {
  try {
    getBX24().installFinish();
  } catch {
    /* ignore */
  }
}

function resolveDomain(auth) {
  const d = auth?.domain || getPostAuth()?.DOMAIN || '';
  return String(d)
    .replace(/^https?:\/\//, '')
    .replace(/\/$/, '') || 'crm.artflowers.kz';
}

/**
 * placement.bind via direct REST + access_token (обход 401 BX24 ajax на части порталов).
 */
export async function ensureDealTabPlacement(handlerUrl) {
  await refreshAuth();
  const auth = getAuth();
  if (!auth?.access_token) {
    throw new Error(
      'Нет access_token. Открой приложение из Битрикс (не прямой URL). Handler должен идти через /api/frame (POST).'
    );
  }

  const handler = handlerUrl || `${window.location.origin}/api/frame`;
  const domain = resolveDomain(auth);
  const url = new URL(`https://${domain}/rest/placement.bind`);

  const body = new URLSearchParams({
    auth: auth.access_token,
    PLACEMENT: 'CRM_DEAL_DETAIL_TAB',
    HANDLER: handler,
    TITLE: 'BitrixEasy',
  });

  const res = await fetch(url.toString(), {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
  const data = await res.json().catch(() => ({}));
  if (data.error) {
    throw new Error(deepErrorString(data));
  }
  return data.result;
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
