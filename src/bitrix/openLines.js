import { bx24Call, getAuth } from './bx24.js';
import { CONTACT_CENTER_URL } from '../config/contactCenter.js';

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

function dialogHaystack(dialog) {
  return [
    dialog?.entity_id,
    dialog?.entity_data_1,
    dialog?.entity_data_2,
    dialog?.entity_data_3,
    dialog?.dialog_id,
    dialog?.name,
    dialog?.title,
  ]
    .filter(Boolean)
    .join('|')
    .toLowerCase();
}

function dialogLooksPersonal(dialog) {
  return !isGroupEntity(dialogHaystack(dialog));
}

function phoneMatchesDialog(dialog, phoneDigits) {
  if (!phoneDigits || phoneDigits.length < 10) return false;
  const hay = normalizePhoneDigits(dialogHaystack(dialog));
  const variants = [phoneDigits];
  if (phoneDigits.length === 11 && (phoneDigits[0] === '7' || phoneDigits[0] === '8')) {
    variants.push(phoneDigits.slice(1));
  }
  return variants.some((v) => v.length >= 10 && hay.includes(v));
}

async function getChatsForCrmEntity(entityType, entityId, activeOnly) {
  const type = String(entityType || '').toLowerCase();
  const id = parseInt(entityId, 10);
  if (!type || !id) return [];

  try {
    const data = await bx24Call('imopenlines.crm.chat.get', {
      CRM_ENTITY_TYPE: type,
      CRM_ENTITY: id,
      ACTIVE_ONLY: activeOnly ? 'Y' : 'N',
    });
    const list = data?.result || data || [];
    if (Array.isArray(list)) return list;
  } catch {
    /* fall through to getLastId only if list API недоступен */
  }

  if (activeOnly) return [];

  try {
    const last = await bx24Call('imopenlines.crm.chat.getLastId', {
      CRM_ENTITY_TYPE: type,
      CRM_ENTITY: id,
    });
    const cid = parseInt(last?.result !== undefined ? last.result : last, 10);
    if (cid) return [{ CHAT_ID: cid }];
  } catch {
    /* 400 = чата нет / метод ругается — не критично */
  }
  return [];
}

async function getDialogByChatId(chatId) {
  const data = await bx24Call('imopenlines.dialog.get', { CHAT_ID: parseInt(chatId, 10) });
  return data?.result || data;
}

function scoreCandidate(dialog, { phoneDigits, fromDeal }) {
  if (!dialogLooksPersonal(dialog)) return -1e9;
  const hay = dialogHaystack(dialog);
  let score = 0;

  if (fromDeal) score += 40;
  if (phoneMatchesDialog(dialog, phoneDigits)) score += 120;

  const lineStatus = parseInt(dialog?.lines?.status ?? dialog?.status, 10);
  const isClosed = CLOSED_LINE_STATUSES.includes(lineStatus);
  if (!isClosed) score += 60;
  else score -= 30;

  if (/@c\.us|@s\.whatsapp\.net|whatsapp|green|wazzup/i.test(hay)) score += 25;
  // старые/битые линии без нормального entity — вниз
  if (!dialog?.entity_id && !dialog?.entity_data_1) score -= 40;

  const updated = Date.parse(dialog?.date_update || dialog?.date_create || 0) || 0;
  score += Math.min(30, Math.floor(updated / 1e11)); // лёгкий бонус свежести

  return score;
}

/**
 * Find personal Open Lines chat for deal/contact.
 * Prefer: active session → phone match → deal binding → not closed.
 */
export async function findPersonalChatForDeal({ dealId, contactId, phone } = {}) {
  const phoneDigits = toWaMeDigits(phone);
  const batches = [
    { entityType: 'deal', entityId: dealId, activeOnly: true, fromDeal: true },
    { entityType: 'contact', entityId: contactId, activeOnly: true, fromDeal: false },
    { entityType: 'deal', entityId: dealId, activeOnly: false, fromDeal: true },
    { entityType: 'contact', entityId: contactId, activeOnly: false, fromDeal: false },
  ];

  const seen = new Set();
  const scored = [];

  for (const batch of batches) {
    if (!batch.entityId) continue;
    const rows = await getChatsForCrmEntity(batch.entityType, batch.entityId, batch.activeOnly);
    for (const row of rows) {
      const chatId = parseInt(row.CHAT_ID || row.chatId || row.id, 10);
      if (!chatId || seen.has(chatId)) continue;
      seen.add(chatId);

      let dialog;
      try {
        dialog = await getDialogByChatId(chatId);
      } catch {
        continue;
      }
      const score = scoreCandidate(dialog, {
        phoneDigits,
        fromDeal: batch.fromDeal,
      });
      if (score < -1e8) continue;

      const lineStatus = parseInt(dialog?.lines?.status ?? dialog?.status, 10);
      const isClosed = CLOSED_LINE_STATUSES.includes(lineStatus);
      const dialogId =
        normalizeDialogId(dialog.dialog_id || dialog.id) || `chat${chatId}`;

      scored.push({
        score,
        chat: {
          chatId,
          dialogId: dialogId.startsWith('chat') ? dialogId : `chat${chatId}`,
          title: dialog.name || dialog.title || `Чат #${chatId}`,
          isClosed,
          entityId: dialog.entity_id || '',
          dialog,
        },
      });
    }

    // если на активных уже нашли с совпадением телефона — не лезем в архив
    if (batch.activeOnly) {
      const good = scored.filter((s) => s.score >= 100);
      if (good.length) break;
    }
  }

  if (!scored.length) return null;
  scored.sort((a, b) => b.score - a.score);

  // если телефон известен — не берём чат без совпадения, если есть хоть один с совпадением
  if (phoneDigits.length >= 10) {
    const withPhone = scored.filter((s) => phoneMatchesDialog(s.chat.dialog, phoneDigits));
    if (withPhone.length) return withPhone[0].chat;
  }

  return scored[0].chat;
}

export async function getDialogMessages(dialogId, { limit = 40, firstId = 0, lastId = 0 } = {}) {
  const params = { DIALOG_ID: dialogId, LIMIT: limit };
  if (firstId) params.FIRST_ID = firstId;
  if (lastId) params.LAST_ID = lastId;
  const data = await bx24Call('im.dialog.messages.get', params);
  const messages = Array.isArray(data?.messages) ? data.messages : [];
  const sorted = messages.slice().sort((a, b) => Number(a.id) - Number(b.id));
  let files = mergeFilesMap({}, data?.files || {});
  files = hydrateFilesFromMessages(files, sorted);
  return {
    messages: sorted,
    chatId: data?.chat_id || null,
    files,
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
  const uid = parseInt(currentUserId, 10);
  const acceptedByMe = managers.includes(uid) || owner === uid;
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
    try {
      await bx24Call('imopenlines.operator.answer', { CHAT_ID: id });
    } catch {
      /* пользователь может не быть оператором OL — отправим всё равно */
    }
  }
}

export async function sendDialogMessage(dialogId, text, { chatId, currentUserId } = {}) {
  const message = String(text || '').trim();
  if (!message) throw new Error('Пустое сообщение');
  if (chatId) await ensureOpenLineSession(chatId, currentUserId);
  return bx24Call('im.message.add', { DIALOG_ID: dialogId, MESSAGE: message });
}

function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const res = String(reader.result || '');
      resolve(res.includes(',') ? res.split(',')[1] : res);
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

export async function uploadFileToChat({ chatId, dialogId, file, caption = '', currentUserId }) {
  const cid = parseInt(chatId, 10);
  if (!cid) throw new Error('CHAT_ID не определён');
  if (!file) throw new Error('Файл не выбран');

  try {
    await ensureOpenLineSession(cid, currentUserId);
  } catch {
    /* operator.answer часто 400 если ты не оператор OL — текст/файл всё равно пробуем */
  }

  const content = await fileToBase64(file);
  const dialog = dialogId || `chat${cid}`;
  const uploadOpts = { timeoutMs: 120000 };

  // 1) im.v2 — достаточно скоупа im (как в PHP КЦ для голосовых)
  try {
    return await bx24Call(
      'im.v2.File.upload',
      {
        dialogId: dialog,
        fields: {
          name: file.name || 'file.bin',
          content,
          message: caption || '',
        },
      },
      uploadOpts
    );
  } catch (e1) {
    console.warn('im.v2.File.upload failed, try disk', e1);
  }

  // 2) disk + commit — нужен скоуп disk
  let folderId = null;
  try {
    const folder = await bx24Call('im.disk.folder.get', { CHAT_ID: cid });
    folderId = folder.ID || folder.id;
  } catch {
    try {
      const folder = await bx24Call('im.disk.folder.get', { DIALOG_ID: dialog });
      folderId = folder.ID || folder.id;
    } catch (e) {
      throw new Error(
        'Не удалось загрузить файл. Добавь скоупы im + disk в локальном приложении и переоткрой виджет. ' +
          (e.message || '')
      );
    }
  }
  if (!folderId) throw new Error('Не удалось получить папку чата');

  let uploaded;
  try {
    uploaded = await bx24Call(
      'disk.folder.uploadfile',
      {
        id: folderId,
        data: { NAME: file.name || 'file.bin' },
        fileContent: [file.name || 'file.bin', content],
      },
      uploadOpts
    );
  } catch (e) {
    const msg = e.message || String(e);
    if (/401|unauthorized|insufficient_scope|ACCESS_DENIED|permission/i.test(msg)) {
      throw new Error(
        'Нет прав на disk.folder.uploadfile (401). В карточке локального приложения добавь скоуп disk, сохрани права и переоткрой сделку.'
      );
    }
    throw e;
  }

  const fileId =
    uploaded.ID || uploaded.id || (uploaded.result && (uploaded.result.ID || uploaded.result.id));
  if (!fileId) throw new Error('Файл не загружен на диск');

  return bx24Call('im.disk.file.commit', {
    CHAT_ID: cid,
    FILE_ID: [fileId],
    MESSAGE: caption || '',
  });
}

export function portalOrigin() {
  const auth = getAuth();
  const domain = String(auth?.domain || 'crm.artflowers.kz').replace(/^https?:\/\//, '');
  return `https://${domain}`;
}

/** Bitrix часто отдаёт относительные /bitrix/... — в виджете на Vercel они ломаются */
export function absolutizeBitrixUrl(url) {
  if (!url) return '';
  const s = String(url).trim();
  if (!s || s.startsWith('blob:') || s.startsWith('data:')) return s;
  if (/^https?:\/\//i.test(s)) return s;
  if (s.startsWith('//')) return `https:${s}`;
  const origin = portalOrigin();
  return s.startsWith('/') ? origin + s : `${origin}/${s}`;
}

/** Прокси медиа на портале (сессия Битрикс). REST download/disk на этом портале даёт 400/401. */
export function parseFileId(v) {
  if (v == null || v === '') return 0;
  if (typeof v === 'object') {
    return parseFileId(
      v.id || v.ID || v.fileId || v.FILE_ID || v.diskFileId || v.objectId || v.objectid
    );
  }
  const s = String(v).trim();
  const m = s.match(/^(?:n)?(\d+)$/i);
  if (m) return parseInt(m[1], 10) || 0;
  const n = parseInt(s, 10);
  return Number.isNaN(n) ? 0 : n;
}

function pickFirstUrl(...vals) {
  for (const v of vals) {
    if (typeof v === 'string' && v.trim()) return v.trim();
  }
  return '';
}

function mediaPreviewUrl(media) {
  if (!media || typeof media !== 'object') return '';
  const preview = media.preview || media.Preview || media.sd || media.SD || '';
  if (typeof preview === 'string') return preview;
  if (preview && typeof preview === 'object') {
    return (
      preview['250'] ||
      preview['500'] ||
      preview['1000'] ||
      pickFirstUrl(...Object.values(preview))
    );
  }
  return pickFirstUrl(media.hd, media.HD, media.previewUrl);
}

function normalizeMediaKind(type, ext, name) {
  let t = String(type || '')
    .toLowerCase()
    .trim();
  if (t.includes('/')) t = t.split('/')[0];
  const e = String(ext || '').toLowerCase();
  const n = String(name || '').toLowerCase();
  if (!t || t === 'file' || t === 'document') {
    if (/^(jpe?g|png|gif|webp|bmp|heic|heif)$/i.test(e)) t = 'image';
    else if (/^(mp3|ogg|oga|wav|m4a|opus|aac)$/i.test(e)) t = 'audio';
    else if (/^(mp4|mov|avi|mkv)$/i.test(e)) t = 'video';
    else if (/voice|audio_message|голос/i.test(n)) t = 'audio';
    else if (e === 'webm') t = 'audio';
  }
  return t;
}

export function portalMediaProxyUrl(fileId, chatId = 0) {
  const id = parseFileId(fileId);
  if (!id) return '';
  try {
    const url = new URL(CONTACT_CENTER_URL);
    url.searchParams.set('wa_media', String(id));
    let cid = parseFileId(chatId);
    if (!cid) {
      const d = String(chatId || '').replace(/^chat/i, '');
      cid = parseInt(d, 10) || 0;
    }
    if (cid) url.searchParams.set('chat', String(cid));
    return url.toString();
  } catch {
    return '';
  }
}

export async function downloadFileUrl(dialogId, fileId, chatId = 0) {
  return portalMediaProxyUrl(fileId, chatId || dialogId);
}

/**
 * src: URL из messages.files (часто urlpreview lowercase + signed disk URL) → иначе прокси КЦ.
 * Без im.v2.File.download / disk.file.get (400/401 spam).
 */
export async function resolveMediaSrc(dialogId, fileId, file = null, chatId = 0) {
  let url =
    absolutizeBitrixUrl(file?.urlShow) ||
    absolutizeBitrixUrl(file?.urlDownload) ||
    absolutizeBitrixUrl(file?.urlPreview) ||
    absolutizeBitrixUrl(file?.mediaHd) ||
    absolutizeBitrixUrl(file?.mediaSd);

  if (!url) {
    url = portalMediaProxyUrl(fileId, chatId || dialogId);
  }
  if (!url) return { src: '', blobUrl: false, contentType: '' };

  const useCreds = /wa_media=/.test(url);
  try {
    const res = await fetch(url, {
      mode: 'cors',
      credentials: useCreds ? 'include' : 'omit',
    });
    if (res.ok) {
      const blob = await res.blob();
      if (blob && blob.size > 0 && !/text\/html/i.test(blob.type)) {
        return { src: URL.createObjectURL(blob), blobUrl: true, contentType: blob.type };
      }
    }
  } catch {
    /* CORS — прямой URL (signed disk URL обычно ок без cookies) */
  }
  return { src: url, blobUrl: false, contentType: '' };
}

export function guessMediaKind(file, contentType = '') {
  if (isImageFile(file) || /^image\//i.test(contentType)) return 'image';
  if (isAudioFile(file) || /^audio\//i.test(contentType)) return 'audio';
  if (isVideoFile(file) || /^video\//i.test(contentType)) return 'video';
  const name = `${file?.name || ''}.${file?.extension || ''}`.toLowerCase();
  if (/\.(jpe?g|png|gif|webp|bmp|heic)(\?|$)/i.test(name)) return 'image';
  if (/\.(mp3|ogg|oga|wav|m4a|opus|aac|webm)(\?|$)/i.test(name)) return 'audio';
  if (/\.(mp4|mov|avi|mkv)(\?|$)/i.test(name)) return 'video';
  // WA часто шлёт file. без типа — в чате это почти всегда фото
  if (!file?.type || file.type === 'file') return 'image';
  return 'file';
}

export function normalizeFileRecord(raw, key) {
  if (!raw) return null;
  const viewer = raw.viewerAttrs || raw.viewerattrs || {};
  const id = parseFileId(
    raw.id || raw.ID || raw.fileId || raw.diskFileId || viewer.objectId || viewer.objectid || key
  );
  if (!id) return null;
  const name =
    raw.name ||
    raw.NAME ||
    raw.originalName ||
    raw.title ||
    viewer.title ||
    `file.${raw.extension || raw.EXTENSION || 'bin'}`;
  const ext = String(
    raw.extension || raw.EXTENSION || String(name).split('.').pop() || ''
  ).toLowerCase();
  const type = normalizeMediaKind(
    raw.type || raw.TYPE || raw.mediaType || viewer.viewertype || viewer.viewerType,
    ext,
    name
  );
  const media = raw.mediaUrl || raw.mediaurl || {};
  // Bitrix REST часто отдаёт urlpreview/urlshow/urldownload в lowercase
  const urlPreview = absolutizeBitrixUrl(
    pickFirstUrl(
      raw.urlPreview,
      raw.urlpreview,
      raw.previewUrl,
      raw.previewImage,
      mediaPreviewUrl(media),
      viewer.viewerResized,
      viewer.viewerresized
    )
  );
  const urlShow = absolutizeBitrixUrl(
    pickFirstUrl(
      raw.urlShow,
      raw.urlshow,
      raw.showUrl,
      raw.viewUrl,
      raw.url,
      viewer.src,
      media.hd,
      media.HD,
      mediaPreviewUrl(media)
    )
  );
  const urlDownload = absolutizeBitrixUrl(
    pickFirstUrl(
      raw.urlDownload,
      raw.urldownload,
      raw.downloadUrl,
      raw.src,
      viewer.src,
      media.hd,
      media.HD,
      urlShow,
      urlPreview
    )
  );
  return {
    id,
    name,
    extension: ext,
    type,
    urlPreview,
    urlShow,
    urlDownload,
    mediaHd: absolutizeBitrixUrl(pickFirstUrl(media.hd, media.HD)),
    mediaSd: absolutizeBitrixUrl(pickFirstUrl(media.sd, media.SD, mediaPreviewUrl(media))),
    isVoiceNote: !!(raw.isVoiceNote || raw.isvoicenote),
  };
}

export function mergeFilesMap(filesMap, files) {
  const next = { ...filesMap };
  if (!files) return next;
  if (Array.isArray(files)) {
    files.forEach((raw) => {
      const f = normalizeFileRecord(raw);
      if (f) next[f.id] = { ...(next[f.id] || {}), ...f };
    });
    return next;
  }
  Object.keys(files).forEach((key) => {
    const f = normalizeFileRecord(files[key], key);
    if (f) next[f.id] = { ...(next[f.id] || {}), ...f };
  });
  return next;
}

function msgParams(msg) {
  return msg?.params || msg?.PARAMS || {};
}

/** Достаём URL/ID из самого сообщения (новый формат IM без files[]) */
export function hydrateFilesFromMessages(filesMap, messages) {
  let next = { ...filesMap };
  (messages || []).forEach((msg) => {
    const p = msgParams(msg);
    const viewer = p.viewerAttrs || p.viewerattrs || {};
    const id = parseFileId(
      p.objectId || p.objectid || p.FILE_ID || p.fileId || viewer.objectId || viewer.objectid
    );
    const src = pickFirstUrl(p.src, p.SRC, viewer.src);
    const media = msg.mediaUrl || msg.mediaurl || {};
    const preview = mediaPreviewUrl(media);
    if (!id) return;
    const name = p.title || viewer.title || String(msg.text || '').trim() || `file.${id}`;
    const ext = String(name.split('.').pop() || '').toLowerCase();
    let type = normalizeMediaKind(viewer.viewertype || viewer.viewerType || '', ext, name);
    if (msg.isVoiceNote || msg.isvoicenote) type = 'audio';
    if (msg.isVideoNote || msg.isvideonote) type = 'video';
    if (!type && (src || preview)) type = 'image';
    const prev = next[id] || { id };
    next[id] = {
      ...prev,
      id,
      name: prev.name || name,
      extension: prev.extension || ext,
      type: prev.type || type,
      urlPreview: prev.urlPreview || absolutizeBitrixUrl(preview || src),
      urlShow: prev.urlShow || absolutizeBitrixUrl(src || preview),
      urlDownload: prev.urlDownload || absolutizeBitrixUrl(src || preview),
      isVoiceNote: prev.isVoiceNote || !!(msg.isVoiceNote || msg.isvoicenote),
    };
  });
  return next;
}

export function getFileIds(msg) {
  const ids = new Set();
  const p = msgParams(msg);
  ['FILE_ID', 'FILE', 'fileId', 'FILE_IDS', 'objectId', 'objectid'].forEach((key) => {
    let val = p[key];
    if (val == null || val === '') return;
    if (!Array.isArray(val)) val = [val];
    val.forEach((v) => {
      const id = parseFileId(v);
      if (id) ids.add(id);
    });
  });
  const viewer = p.viewerAttrs || p.viewerattrs || {};
  const vid = parseFileId(viewer.objectId || viewer.objectid);
  if (vid) ids.add(vid);
  if (Array.isArray(msg?.files)) {
    msg.files.forEach((f) => {
      const id = parseFileId(f);
      if (id) ids.add(id);
    });
  }
  const text = msg?.text || '';
  const re = /\[?(?:DISK\s+)?FILE\s+ID=(?:n)?(\d+)\]?/gi;
  let match;
  while ((match = re.exec(text)) !== null) ids.add(parseInt(match[1], 10));
  return Array.from(ids);
}

export function isImageFile(f) {
  if (!f) return false;
  const type = normalizeMediaKind(f.type, f.extension, f.name);
  const ext = (f.extension || '').toLowerCase();
  return type === 'image' || /^(jpe?g|png|gif|webp|bmp|heic)$/i.test(ext);
}

export function isAudioFile(f) {
  if (!f) return false;
  if (f.isVoiceNote) return true;
  const type = normalizeMediaKind(f.type, f.extension, f.name);
  const ext = (f.extension || '').toLowerCase();
  const name = (f.name || '').toLowerCase();
  if (type === 'audio') return true;
  if (/^(mp3|ogg|oga|wav|m4a|opus|aac)$/i.test(ext)) return true;
  if (/voice|audio_message|голос/i.test(name)) return true;
  if (ext === 'webm' && type !== 'video') return true;
  return false;
}

export function isVideoFile(f) {
  if (!f || isAudioFile(f)) return false;
  const type = normalizeMediaKind(f.type, f.extension, f.name);
  const ext = (f.extension || '').toLowerCase();
  return type === 'video' || /^(mp4|mov|avi|mkv)$/i.test(ext);
}

export function formatMessageTime(raw) {
  if (raw == null || raw === '') return '';
  let d;
  if (typeof raw === 'number') d = new Date(raw < 1e12 ? raw * 1000 : raw);
  else d = new Date(raw);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function matchConnectorOperatorPrefix(text) {
  return String(text || '').match(/^\s*\[(?:b|B)\]([^\]]+)\[\/(?:b|B)\]\s*:?\s*/);
}

export function isConnectorOperatorMessage(msg) {
  if (matchConnectorOperatorPrefix(msg?.text || '')) return true;
  const p = msg?.params || {};
  if (p.CONNECTOR || p.FROM_CONNECTOR || p.IMOL_FORM || p.IMOL_COMMENT) return true;
  return false;
}

export function stripBbLite(text) {
  return String(text || '')
    .replace(/^\s*\[(?:b|B)\][^\]]+\[\/(?:b|B)\]\s*:?\s*/i, '')
    .replace(/\[br\]/gi, '\n')
    .replace(/\[USER=\d+[^\]]*\]([\s\S]*?)\[\/USER\]/gi, '$1')
    .replace(/\[[^\]]+\]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Чистый текст для пузыря; пустая строка если нечего показывать */
export function messagePlainText(msg) {
  return stripBbLite(msg?.text || '');
}

export function isOutgoingMessage(msg, currentUserId) {
  const authorId = parseInt(msg.author_id || msg.senderId || 0, 10);
  if (authorId && String(authorId) === String(currentUserId)) return true;
  if (isConnectorOperatorMessage(msg)) return true;
  return false;
}

const SYSTEM_TEXT_RE =
  /начал работу с диалогом|завершил работу|диалог закрыт|перевед[её]н|поставил оценку|пригласил|покинул|приглашение направлено|участник(?:ам|ов)? очереди|очередь оператор|оператор присоедини|сессия (?:открыт|закрыт)|ожидает ответа|назначен ответственный|автоматическ\w*\s+завершен|завершен\w*\s+диалог|закрытие диалог|диалог\s+завершен|начат\w*\s+новый\s+диалог|новый диалог\s*№?\s*\d*|диалог\s*№\s*\d+|номер\s+диалог|открыт\w*\s+диалог|сессия\s+№|session\s+(?:start|finish|close)|ol[_-]?(?:start|finish|close)|таймаут|по\s+истечении|клиент\s+не\s+отвеча|ожидание\s+ответа\s+клиента/i;

export function isSystemMessage(msg) {
  if (isConnectorOperatorMessage(msg)) return false;
  if (getFileIds(msg).length) return false;

  const authorId = parseInt(msg.author_id || msg.senderId || 0, 10);
  const text = msg?.text || '';
  const plain = stripBbLite(text);
  const p = msg?.params || {};
  const code = p.CODE;

  if (msg?.system === true || msg?.isSystem === true) return true;
  if (p.system === 'Y' || p.SYSTEM === 'Y' || p.NOTIFY === 'system') return true;
  if (code && (Array.isArray(code) ? code.length : true)) return true;

  // служебные фразы OL — даже если author_id бота/системы ≠ 0
  if (SYSTEM_TEXT_RE.test(text) || SYSTEM_TEXT_RE.test(plain)) return true;

  if (/\[USER=\d+/i.test(text) && authorId === 0) return true;

  if (authorId === 0) {
    if (!plain) return true;
    if (/^[-—–\s.]+$/.test(plain)) return true;
    if (/диалог/i.test(plain) && /(№|номер|заверш|открыт|закрыт|начат|сесси)/i.test(plain)) {
      return true;
    }
  }
  return false;
}

/** Не рендерить пустые «—» */
export function shouldRenderMessage(msg) {
  if (getFileIds(msg).length) return true;
  const plain = messagePlainText(msg);
  if (plain && !/^[-—–\s.]+$/.test(plain)) return true;
  if (isSystemMessage(msg)) {
    // системные с осмысленным текстом — да; пустые — скрыть
    return Boolean(plain) && !/^[-—–\s.]+$/.test(plain);
  }
  return false;
}

export function systemMessageLabel(msg) {
  const plain = messagePlainText(msg);
  if (plain) return plain;
  return 'Системное сообщение';
}

/** FFmpeg assets через PHP-прокси КЦ на портале */
export function waFfmpegAssetUrl(name) {
  const url = new URL(CONTACT_CENTER_URL);
  url.searchParams.set('wa_ffmpeg', name);
  return url.toString();
}

export function contactCenterOriginBase() {
  try {
    const u = new URL(CONTACT_CENTER_URL);
    // /local/custom_chat/ → same folder for wa-ffmpeg/
    return `${u.origin}${u.pathname.replace(/\/?$/, '/')}`;
  } catch {
    return CONTACT_CENTER_URL;
  }
}
