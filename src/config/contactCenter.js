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
