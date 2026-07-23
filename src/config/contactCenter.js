/**
 * Contact-center (WhatsApp UI) on the portal.
 * Local app scopes required for chat panel: im, imopenlines
 * (plus existing crm, user, placement).
 */
export const CONTACT_CENTER_URL = 'https://crm.artflowers.kz/local/custom_chat/';

export const REQUIRED_CHAT_SCOPES = ['im', 'imopenlines'];

export function contactCenterChatUrl({ chatId, dialogId } = {}) {
  const url = new URL(CONTACT_CENTER_URL);
  if (chatId) url.searchParams.set('chatId', String(chatId));
  if (dialogId) url.searchParams.set('dialogId', String(dialogId));
  return url.toString();
}

/** Portal-relative path for BX24.openPath / SidePanel */
export function contactCenterChatPath({ chatId, dialogId } = {}) {
  const url = new URL(contactCenterChatUrl({ chatId, dialogId }));
  return `${url.pathname}${url.search}`;
}

/**
 * Open КЦ in Bitrix SidePanel (slider), not a new browser tab.
 * Falls back to window.open if openPath fails.
 */
export function openContactCenter({ chatId, dialogId } = {}) {
  const path = contactCenterChatPath({ chatId, dialogId });
  const absolute = contactCenterChatUrl({ chatId, dialogId });

  try {
    const BX24 = typeof window !== 'undefined' ? window.BX24 : null;
    if (BX24 && typeof BX24.openPath === 'function') {
      BX24.openPath(path, (result) => {
        const status = result?.result || result;
        if (status === 'error' || status === false) {
          window.open(absolute, '_blank', 'noopener,noreferrer');
        }
      });
      return;
    }
  } catch {
    /* fall through */
  }

  // same-origin parent (редко) — прямой SidePanel
  try {
    const sp = window.parent?.BX?.SidePanel?.Instance;
    if (sp && typeof sp.open === 'function') {
      sp.open(path, { cacheable: false, allowChangeHistory: false, width: 1100 });
      return;
    }
  } catch {
    /* cross-origin */
  }

  window.open(absolute, '_blank', 'noopener,noreferrer');
}
