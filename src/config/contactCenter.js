/**
 * Contact-center (WhatsApp UI) on the portal.
 * Local app scopes required for chat panel: im, imopenlines, disk
 * (plus existing crm, user, placement).
 */
export const CONTACT_CENTER_URL = 'https://crm.artflowers.kz/local/custom_chat/';

export const REQUIRED_CHAT_SCOPES = ['im', 'imopenlines', 'disk'];

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
 * Пытается открыть КЦ в SidePanel Битрикса.
 * @returns {boolean} true если вызов ушёл в openPath/SidePanel (без гарантии UI)
 */
export function tryOpenContactCenterSlider({ chatId, dialogId } = {}) {
  const path = contactCenterChatPath({ chatId, dialogId });

  try {
    const BX24 = typeof window !== 'undefined' ? window.BX24 : null;
    if (BX24 && typeof BX24.openPath === 'function') {
      BX24.openPath(path, () => {});
      return true;
    }
  } catch {
    /* ignore */
  }

  try {
    const sp = window.top?.BX?.SidePanel?.Instance || window.parent?.BX?.SidePanel?.Instance;
    if (sp && typeof sp.open === 'function') {
      sp.open(path, { cacheable: false, allowChangeHistory: false, width: 1100 });
      return true;
    }
  } catch {
    /* cross-origin */
  }

  return false;
}
