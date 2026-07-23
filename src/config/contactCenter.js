/**
 * Contact-center (WhatsApp UI) on the portal.
 * Local app scopes: im, imopenlines, disk (+ crm, user, placement).
 *
 * Нельзя открывать КЦ iframe с Vercel: портал шлёт X-Frame-Options: sameorigin.
 * Поэтому только SidePanel (BX24.openPath) или отдельное окно-popup.
 */
export const CONTACT_CENTER_URL = 'https://crm.artflowers.kz/local/custom_chat/';

export const REQUIRED_CHAT_SCOPES = ['im', 'imopenlines', 'disk'];

export function contactCenterChatUrl({ chatId, dialogId } = {}) {
  const url = new URL(CONTACT_CENTER_URL);
  if (chatId) url.searchParams.set('chatId', String(chatId));
  if (dialogId) url.searchParams.set('dialogId', String(dialogId));
  return url.toString();
}

export function contactCenterChatPath({ chatId, dialogId } = {}) {
  const url = new URL(contactCenterChatUrl({ chatId, dialogId }));
  return `${url.pathname}${url.search}`;
}

function openCcPopup(absoluteUrl) {
  const w = Math.min(1200, Math.floor(window.screen.availWidth * 0.9));
  const h = Math.min(860, Math.floor(window.screen.availHeight * 0.9));
  const left = Math.max(0, Math.floor((window.screen.availWidth - w) / 2));
  const top = Math.max(0, Math.floor((window.screen.availHeight - h) / 2));
  const features = [
    'popup=yes',
    `width=${w}`,
    `height=${h}`,
    `left=${left}`,
    `top=${top}`,
    'menubar=no',
    'toolbar=no',
    'location=no',
    'status=no',
    'resizable=yes',
    'scrollbars=yes',
  ].join(',');
  const win = window.open(absoluteUrl, 'bitrixeazy_contact_center', features);
  if (win) {
    try {
      win.focus();
    } catch {
      /* ignore */
    }
    return true;
  }
  return false;
}

/**
 * Открыть КЦ: SidePanel Битрикса (предпочтительно), иначе popup-окно.
 * Никогда не iframe из виджета на Vercel — sameorigin блокирует.
 */
export function openContactCenter({ chatId, dialogId } = {}) {
  const path = contactCenterChatPath({ chatId, dialogId });
  const absolute = contactCenterChatUrl({ chatId, dialogId });

  try {
    const BX24 = window.BX24;
    if (BX24 && typeof BX24.openPath === 'function') {
      BX24.openPath(path, (result) => {
        if (result?.result === 'error') {
          openCcPopup(absolute);
        }
      });
      return;
    }
  } catch {
    /* fall through */
  }

  try {
    const sp = window.top?.BX?.SidePanel?.Instance;
    if (sp && typeof sp.open === 'function') {
      sp.open(path, { cacheable: false, allowChangeHistory: false, width: 1100 });
      return;
    }
  } catch {
    /* cross-origin parent */
  }

  openCcPopup(absolute);
}
