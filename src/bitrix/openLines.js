import { bx24Call } from './bx24.js';

const CLOSED_LINE_STATUSES = [50, 60, 70, 80];

export function normalizePhoneDigits(value) {
  return String(value || '').replace(/\D/g, '');
}

export function toWaMeDigits(phone) {
  let num = normalizePhoneDigits(phone);
  if (!num) return '';
  if (num.length === 11 && num[0] === '8') num = `7${num.slice(1)}`;
  if (num.length === 10) num = `7${num}`;
  return num;
}

export function waMeUrl(phone) {
  const digits = toWaMeDigits(phone);
  return digits ? `https://wa.me/${digits}` : '';
}

function normalizeDialogId(id) {
  if (id == null || id === '') return '';
  const s = String(id);
  if (/^chat\d+$/i.test(s)) return s.toLowerCase();
  if (/^\d+$/.test(s)) return `chat${s}`;
  return s;
}

function isGroupEntity(haystack) {
  return /@g\.us\b/i.test(String(haystack || ''));
}

function dialogLooksPersonal(dialog) {
  const parts = [
    dialog?.entity_id,
    dialog?.entity_data_1,
    dialog?.entity_data_2,
    dialog?.entity_data_3,
    dialog?.dialog_id,
  ]
    .filter(Boolean)
    .join('|');
  return !isGroupEntity(parts);
}

async function getChatsForCrmEntity(entityType, entityId) {
  const type = String(entityType || '').toLowerCase();
  const id = parseInt(entityId, 10);
  if (!type || !id) return [];

  try {
    const data = await bx24Call('imopenlines.crm.chat.get', {
      CRM_ENTITY_TYPE: type,
      CRM_ENTITY: id,
      ACTIVE_ONLY: 'N',
    });
    const list = data?.result || data || [];
    if (Array.isArray(list) && list.length) return list;
  } catch {
    /* fall through */
  }

  try {
    const last = await bx24Call('imopenlines.crm.chat.getLastId', {
      CRM_ENTITY_TYPE: type,
      CRM_ENTITY: id,
    });
    const cid = parseInt(last?.result !== undefined ? last.result : last, 10);
    if (cid) return [{ CHAT_ID: cid }];
  } catch {
    /* ignore */
  }
  return [];
}

async function getDialogByChatId(chatId) {
  const data = await bx24Call('imopenlines.dialog.get', { CHAT_ID: parseInt(chatId, 10) });
  return data?.result || data;
}

/**
 * Find personal (non-group) Open Lines chat for deal/contact.
 * @returns {Promise<null|{ chatId: number, dialogId: string, title: string, isClosed: boolean }>}
 */
export async function findPersonalChatForDeal({ dealId, contactId }) {
  const candidates = [];
  if (dealId) {
    const forDeal = await getChatsForCrmEntity('deal', dealId);
    candidates.push(...forDeal);
  }
  if (contactId) {
    const forContact = await getChatsForCrmEntity('contact', contactId);
    candidates.push(...forContact);
  }

  const seen = new Set();
  for (const row of candidates) {
    const chatId = parseInt(row.CHAT_ID || row.chatId || row.id, 10);
    if (!chatId || seen.has(chatId)) continue;
    seen.add(chatId);

    let dialog;
    try {
      dialog = await getDialogByChatId(chatId);
    } catch {
      continue;
    }
    if (!dialogLooksPersonal(dialog)) continue;

    const lineStatus = parseInt(dialog?.lines?.status ?? dialog?.status, 10);
    const isClosed = CLOSED_LINE_STATUSES.includes(lineStatus);
    const dialogId =
      normalizeDialogId(dialog.dialog_id || dialog.id) || `chat${chatId}`;

    return {
      chatId,
      dialogId: dialogId.startsWith('chat') ? dialogId : `chat${chatId}`,
      title: dialog.name || dialog.title || `Чат #${chatId}`,
      isClosed,
      dialog,
    };
  }

  return null;
}

export async function getDialogMessages(dialogId, { limit = 40, firstId = 0 } = {}) {
  const params = { DIALOG_ID: dialogId, LIMIT: limit };
  if (firstId) params.FIRST_ID = firstId;
  const data = await bx24Call('im.dialog.messages.get', params);
  const messages = Array.isArray(data?.messages) ? data.messages : [];
  return {
    messages: messages.slice().sort((a, b) => Number(a.id) - Number(b.id)),
    chatId: data?.chat_id || null,
    files: data?.files || {},
  };
}

export async function markDialogRead(dialogId) {
  try {
    await bx24Call('im.dialog.read', { DIALOG_ID: dialogId });
  } catch {
    /* ignore */
  }
}

export async function ensureOpenLineSession(chatId, currentUserId) {
  const id = parseInt(chatId, 10);
  if (!id) return;

  let dialog;
  try {
    dialog = await getDialogByChatId(id);
  } catch {
    return;
  }

  const lineStatus = parseInt(dialog?.lines?.status, 10);
  const isClosed = CLOSED_LINE_STATUSES.includes(lineStatus);
  const managers = (dialog.manager_list || []).map((x) => parseInt(x, 10)).filter(Boolean);
  const owner = parseInt(dialog.owner, 10) || 0;
  const acceptedByMe =
    managers.includes(parseInt(currentUserId, 10)) || owner === parseInt(currentUserId, 10);
  const waiting = [0, 5, 10].includes(lineStatus) || (Number.isNaN(lineStatus) && !acceptedByMe);

  if (isClosed) {
    try {
      await bx24Call('imopenlines.session.start', { CHAT_ID: id });
    } catch {
      /* may already be open */
    }
    try {
      await bx24Call('imopenlines.operator.answer', { CHAT_ID: id });
    } catch {
      /* ignore */
    }
    return;
  }

  if (!acceptedByMe && waiting) {
    await bx24Call('imopenlines.operator.answer', { CHAT_ID: id });
  }
}

export async function sendDialogMessage(dialogId, text, { chatId, currentUserId } = {}) {
  const message = String(text || '').trim();
  if (!message) throw new Error('Пустое сообщение');
  if (chatId) await ensureOpenLineSession(chatId, currentUserId);
  return bx24Call('im.message.add', { DIALOG_ID: dialogId, MESSAGE: message });
}

export function formatMessageTime(raw) {
  if (raw == null || raw === '') return '';
  let d;
  if (typeof raw === 'number') d = new Date(raw < 1e12 ? raw * 1000 : raw);
  else d = new Date(raw);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

export function stripBbLite(text) {
  return String(text || '')
    .replace(/^\s*\[(?:b|B)\][^\]]+\[\/(?:b|B)\]\s*:?\s*/i, '')
    .replace(/\[br\]/gi, '\n')
    .replace(/\[[^\]]+\]/g, '')
    .trim();
}

export function isOutgoingMessage(msg, currentUserId) {
  const authorId = parseInt(msg.author_id || msg.senderId || 0, 10);
  if (authorId && String(authorId) === String(currentUserId)) return true;
  const text = msg.text || '';
  if (/^\s*\[(?:b|B)\]/.test(text)) return true;
  return false;
}

export function isSystemMessage(msg) {
  const authorId = parseInt(msg.author_id || msg.senderId || 0, 10);
  if (authorId !== 0) return false;
  const text = msg.text || '';
  if (/^\s*\[(?:b|B)\]/.test(text)) return false;
  if (/начал работу|завершил|диалог закрыт|перевед/i.test(text)) return true;
  return false;
}
