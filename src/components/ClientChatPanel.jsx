import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { contactCenterChatUrl } from '../config/contactCenter.js';
import {
  contactCenterOriginBase,
  findPersonalChatForDeal,
  formatMessageTime,
  getDialogMessages,
  getFileIds,
  guessMediaKind,
  isOutgoingMessage,
  isSystemMessage,
  markDialogRead,
  mergeFilesMap,
  messagePlainText,
  resolveMediaSrc,
  sendDialogMessage,
  shouldRenderMessage,
  systemMessageLabel,
  uploadFileToChat,
  waFfmpegAssetUrl,
  waMeUrl,
} from '../bitrix/openLines.js';

function loadScriptOnce(src, key) {
  if (typeof window === 'undefined') return Promise.reject();
  if (window[key]) return window[key];
  window[key] = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.onload = resolve;
    s.onerror = () => reject(new Error(`Script load failed: ${src}`));
    document.head.appendChild(s);
  });
  return window[key];
}

let ffmpegInstance = null;
async function ensureFfmpeg() {
  if (ffmpegInstance) return ffmpegInstance;
  const base = contactCenterOriginBase();
  await loadScriptOnce(`${base}wa-ffmpeg/ffmpeg.js`, '__waFfmpegLib');
  await loadScriptOnce(
    'https://cdn.jsdelivr.net/npm/@ffmpeg/util@0.12.1/dist/umd/index.js',
    '__waFfmpegUtil'
  );
  const { FFmpeg } = window.FFmpegWASM;
  const ffmpeg = new FFmpeg();
  await ffmpeg.load({
    coreURL: waFfmpegAssetUrl('core-js'),
    wasmURL: waFfmpegAssetUrl('core-wasm'),
  });
  ffmpegInstance = ffmpeg;
  return ffmpeg;
}

async function convertToOgg(blob, mime, onProgress) {
  if (onProgress) onProgress('Конвертация OGG…');
  const ffmpeg = await ensureFfmpeg();
  const { fetchFile } = window.FFmpegUtil;
  const type = (mime || blob.type || '').toLowerCase();
  let ext = 'webm';
  if (type.includes('ogg')) ext = 'ogg';
  else if (type.includes('mp4') || type.includes('m4a')) ext = 'm4a';
  const inName = `in.${ext}`;
  const outName = 'out.ogg';
  await ffmpeg.writeFile(inName, await fetchFile(blob));
  const code = await ffmpeg.exec([
    '-i',
    inName,
    '-vn',
    '-c:a',
    'libopus',
    '-ar',
    '16000',
    '-ac',
    '1',
    '-b:a',
    '16k',
    '-application',
    'voip',
    '-y',
    outName,
  ]);
  if (code !== 0) throw new Error(`FFmpeg exit ${code}`);
  const data = await ffmpeg.readFile(outName);
  await ffmpeg.deleteFile(inName).catch(() => {});
  await ffmpeg.deleteFile(outName).catch(() => {});
  const uid = `${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
  return new File([new Uint8Array(data)], `voice_${uid}.ogg`, { type: 'audio/ogg' });
}

function pickMime() {
  const types = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
  ];
  for (const t of types) {
    if (window.MediaRecorder?.isTypeSupported?.(t)) return t;
  }
  return '';
}

function ChatMedia({ fileId, file, dialogId, onOpenImage }) {
  const [src, setSrc] = useState('');
  const [kind, setKind] = useState(() => guessMediaKind(file));
  const [err, setErr] = useState(false);
  const blobRef = useRef('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setErr(false);
      try {
        const resolved = await resolveMediaSrc(dialogId, fileId, file);
        if (cancelled) {
          if (resolved.blobUrl && resolved.src) URL.revokeObjectURL(resolved.src);
          return;
        }
        if (blobRef.current) {
          URL.revokeObjectURL(blobRef.current);
          blobRef.current = '';
        }
        if (!resolved.src) {
          setErr(true);
          return;
        }
        if (resolved.blobUrl) blobRef.current = resolved.src;
        setSrc(resolved.src);
        setKind(guessMediaKind(file, resolved.contentType || ''));
      } catch {
        if (!cancelled) setErr(true);
      }
    })();
    return () => {
      cancelled = true;
      if (blobRef.current) {
        URL.revokeObjectURL(blobRef.current);
        blobRef.current = '';
      }
    };
  }, [dialogId, fileId, file?.name, file?.extension, file?.type, file?.urlDownload, file?.urlShow]);

  if (err) {
    return <div className="wa-media-fallback">Не удалось загрузить файл</div>;
  }
  if (!src) {
    return <div className="wa-media-fallback">Загрузка…</div>;
  }

  if (kind === 'image') {
    return (
      <button
        type="button"
        className="wa-media wa-media-img-btn"
        onClick={() => onOpenImage?.(src, file?.name)}
      >
        <img
          src={src}
          alt={file?.name || ''}
          loading="lazy"
          onError={() => setErr(true)}
        />
      </button>
    );
  }
  if (kind === 'audio') {
    return (
      <div className="wa-media">
        <audio controls preload="metadata" src={src} className="wa-voice" />
      </div>
    );
  }
  if (kind === 'video') {
    return (
      <div className="wa-media">
        <video controls preload="metadata" src={src} />
      </div>
    );
  }
  return (
    <a className="wa-file-link" href={src} download={file?.name || true}>
      📎 {file?.name || `Файл #${fileId}`}
    </a>
  );
}

function MessageBubble({ msg, currentUserId, filesMap, dialogId, onOpenImage }) {
  if (!shouldRenderMessage(msg)) return null;

  const system = isSystemMessage(msg);
  const out = !system && isOutgoingMessage(msg, currentUserId);
  const plain = system ? systemMessageLabel(msg) : messagePlainText(msg);
  const fileIds = getFileIds(msg);

  return (
    <div className={`wa-msg ${system ? 'system' : out ? 'out' : 'in'}`}>
      {fileIds.map((id) => (
        <ChatMedia
          key={id}
          fileId={id}
          file={filesMap[id]}
          dialogId={dialogId}
          onOpenImage={onOpenImage}
        />
      ))}
      {plain ? <span className="wa-msg-text">{plain}</span> : null}
      <span className="wa-msg-time">{formatMessageTime(msg.date || msg.DATE)}</span>
    </div>
  );
}

function ImageLightbox({ src, alt, onClose }) {
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [onClose]);

  if (!src) return null;
  return (
    <div className="wa-lightbox" role="dialog" aria-modal="true" onClick={onClose}>
      <button type="button" className="wa-lightbox-close" onClick={onClose} aria-label="Закрыть">
        ×
      </button>
      <img
        src={src}
        alt={alt || ''}
        className="wa-lightbox-img"
        onClick={(e) => e.stopPropagation()}
      />
    </div>
  );
}

function ContactCenterModal({ url, onClose }) {
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [onClose]);

  return (
    <div className="wa-cc-modal" role="dialog" aria-modal="true">
      <div className="wa-cc-modal-backdrop" onClick={onClose} />
      <div className="wa-cc-modal-panel">
        <div className="wa-cc-modal-bar">
          <span className="wa-cc-modal-title">Контакт-центр</span>
          <button type="button" className="wa-mini-btn" onClick={onClose}>
            Закрыть
          </button>
        </div>
        <iframe className="wa-cc-frame" src={url} title="Контакт-центр" />
      </div>
    </div>
  );
}

export function ClientChatPanel({ dealId, client, currentUserId }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [hint, setHint] = useState('');
  const [chat, setChat] = useState(null);
  const [messages, setMessages] = useState([]);
  const [filesMap, setFilesMap] = useState({});
  const [text, setText] = useState('');
  const [sending, setSending] = useState(false);
  const [recording, setRecording] = useState(false);
  const [recMs, setRecMs] = useState(0);
  const [lightbox, setLightbox] = useState({ src: '', alt: '' });
  const [ccOpen, setCcOpen] = useState(false);

  const listRef = useRef(null);
  const lastIdRef = useRef(0);
  const fileInputRef = useRef(null);
  const mediaRecorderRef = useRef(null);
  const mediaStreamRef = useRef(null);
  const chunksRef = useRef([]);
  const recTimerRef = useRef(null);
  const recStartedRef = useRef(0);

  const phone = client?.phone || '';
  const waUrl = waMeUrl(phone);
  const contactId = client?.contactId || null;
  const hasText = Boolean(text.trim());

  const scrollBottom = () => {
    const el = listRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  };

  const applyMessages = (msgs, files, { replace = false } = {}) => {
    if (files) setFilesMap((prev) => mergeFilesMap(replace ? {} : prev, files));
    setMessages((prev) => {
      if (replace) return msgs;
      const ids = new Set(prev.map((m) => String(m.id)));
      const add = msgs.filter((m) => !ids.has(String(m.id)));
      if (!add.length) return prev;
      return [...prev, ...add].sort((a, b) => Number(a.id) - Number(b.id));
    });
    if (msgs.length) {
      const last = msgs[msgs.length - 1];
      lastIdRef.current = Math.max(lastIdRef.current, Number(last.id) || 0);
    }
  };

  const loadChat = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const found = await findPersonalChatForDeal({ dealId, contactId, phone });
      setChat(found);
      if (!found) {
        setMessages([]);
        setFilesMap({});
        return;
      }
      const data = await getDialogMessages(found.dialogId, { limit: 60 });
      lastIdRef.current = 0;
      applyMessages(data.messages, data.files, { replace: true });
      if (data.messages.length) {
        lastIdRef.current = Number(data.messages[data.messages.length - 1].id) || 0;
      }
      markDialogRead(found.dialogId);
      requestAnimationFrame(scrollBottom);
    } catch (err) {
      const msg = err.message || String(err);
      if (/insufficient_scope|ACCESS_DENIED|permission/i.test(msg)) {
        setError(
          'Нет прав на чат/файлы. В локальном приложении добавь скоупы im, imopenlines и disk, переоткрой виджет.'
        );
      } else {
        setError(msg);
      }
      setChat(null);
      setMessages([]);
    } finally {
      setLoading(false);
    }
  }, [dealId, contactId, phone]);

  useEffect(() => {
    loadChat();
  }, [loadChat]);

  useEffect(() => {
    if (!chat?.dialogId) return undefined;
    const t = setInterval(async () => {
      try {
        const data = await getDialogMessages(chat.dialogId, {
          limit: 25,
          firstId: lastIdRef.current || 0,
        });
        if (!data.messages.length) return;
        applyMessages(data.messages, data.files);
        requestAnimationFrame(scrollBottom);
      } catch {
        /* ignore */
      }
    }, 4500);
    return () => clearInterval(t);
  }, [chat?.dialogId]);

  const refreshTail = async () => {
    if (!chat?.dialogId) return;
    const data = await getDialogMessages(chat.dialogId, {
      limit: 25,
      firstId: lastIdRef.current || 0,
    });
    applyMessages(data.messages, data.files);
    requestAnimationFrame(scrollBottom);
  };

  const onSend = async () => {
    if (!chat?.dialogId || !text.trim() || sending) return;
    setSending(true);
    setError(null);
    try {
      await sendDialogMessage(chat.dialogId, text, {
        chatId: chat.chatId,
        currentUserId,
      });
      setText('');
      await refreshTail();
    } catch (err) {
      setError(err.message || String(err));
    } finally {
      setSending(false);
    }
  };

  const onAttach = async (fileList) => {
    if (!chat?.chatId || !fileList?.length || sending) return;
    setSending(true);
    setError(null);
    setHint('Загрузка файла…');
    try {
      const files = Array.from(fileList);
      for (let i = 0; i < files.length; i++) {
        setHint(`Загрузка: ${files[i].name} (${i + 1}/${files.length})…`);
        await uploadFileToChat({
          chatId: chat.chatId,
          dialogId: chat.dialogId,
          file: files[i],
          caption: i === 0 ? text.trim() : '',
          currentUserId,
        });
      }
      setText('');
      const data = await getDialogMessages(chat.dialogId, { limit: 60 });
      lastIdRef.current = 0;
      applyMessages(data.messages, data.files, { replace: true });
      if (data.messages.length) {
        lastIdRef.current = Number(data.messages[data.messages.length - 1].id) || 0;
      }
      requestAnimationFrame(scrollBottom);
    } catch (err) {
      setError(err.message || String(err));
    } finally {
      setSending(false);
      setHint('');
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  const stopTracks = () => {
    mediaStreamRef.current?.getTracks?.().forEach((t) => t.stop());
    mediaStreamRef.current = null;
  };

  const clearRecTimer = () => {
    if (recTimerRef.current) {
      clearInterval(recTimerRef.current);
      recTimerRef.current = null;
    }
  };

  const cancelRecording = () => {
    clearRecTimer();
    try {
      mediaRecorderRef.current?.stop?.();
    } catch {
      /* ignore */
    }
    mediaRecorderRef.current = null;
    chunksRef.current = [];
    stopTracks();
    setRecording(false);
    setRecMs(0);
  };

  const startRecording = async () => {
    if (!chat?.dialogId || recording || sending) return;
    if (!navigator.mediaDevices?.getUserMedia) {
      setError('Браузер не поддерживает запись (нужен HTTPS)');
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      mediaStreamRef.current = stream;
      chunksRef.current = [];
      const mime = pickMime();
      const mr = mime
        ? new MediaRecorder(stream, { mimeType: mime })
        : new MediaRecorder(stream);
      mediaRecorderRef.current = mr;
      mr.ondataavailable = (e) => {
        if (e.data?.size) chunksRef.current.push(e.data);
      };
      mr.start(250);
      recStartedRef.current = Date.now();
      setRecMs(0);
      setRecording(true);
      recTimerRef.current = setInterval(() => {
        setRecMs(Date.now() - recStartedRef.current);
      }, 250);
    } catch {
      setError(
        'Нет доступа к микрофону. В iframe Битрикса запись часто блокируется — открой КЦ (кнопка) или разреши микрофон для сайта. Файлы: нужен скоуп disk.'
      );
      stopTracks();
    }
  };

  const finishRecording = async (send) => {
    const mr = mediaRecorderRef.current;
    if (!mr) {
      cancelRecording();
      return;
    }
    const mime = mr.mimeType || 'audio/webm';
    await new Promise((resolve) => {
      mr.onstop = () => resolve();
      try {
        mr.stop();
      } catch {
        resolve();
      }
    });
    clearRecTimer();
    stopTracks();
    setRecording(false);
    const blob = new Blob(chunksRef.current, { type: mime });
    chunksRef.current = [];
    mediaRecorderRef.current = null;
    setRecMs(0);

    if (!send || blob.size < 800 || !chat?.chatId) return;

    setSending(true);
    setHint('Подготовка голосового…');
    try {
      let file;
      try {
        if (/ogg|opus/i.test(mime)) {
          const uid = `${Date.now()}`;
          file = new File([blob], `voice_${uid}.ogg`, { type: 'audio/ogg' });
        } else {
          file = await convertToOgg(blob, mime, setHint);
        }
      } catch {
        setHint('Конвертер недоступен, отправка как есть…');
        const uid = `${Date.now()}`;
        file = new File([blob], `voice_${uid}.webm`, { type: mime || 'audio/webm' });
      }
      setHint('Отправка голосового…');
      await uploadFileToChat({
        chatId: chat.chatId,
        dialogId: chat.dialogId,
        file,
        currentUserId,
      });
      const data = await getDialogMessages(chat.dialogId, { limit: 60 });
      lastIdRef.current = 0;
      applyMessages(data.messages, data.files, { replace: true });
      if (data.messages.length) {
        lastIdRef.current = Number(data.messages[data.messages.length - 1].id) || 0;
      }
      requestAnimationFrame(scrollBottom);
    } catch (err) {
      setError(err.message || String(err));
    } finally {
      setSending(false);
      setHint('');
    }
  };

  const openCc = () => setCcOpen(true);

  const openImage = (src, alt) => setLightbox({ src, alt: alt || '' });
  const closeLightbox = () => setLightbox({ src: '', alt: '' });
  const ccUrl = contactCenterChatUrl({ chatId: chat?.chatId, dialogId: chat?.dialogId });

  const formatRec = (ms) => {
    const s = Math.floor(ms / 1000);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  };

  return (
    <div className="wa-panel">
      <div className="wa-panel-head">
        <div className="wa-panel-head-info">
          <div className="wa-panel-title">
            {chat?.title || client?.fullName || 'Чат с клиентом'}
          </div>
          <div className="wa-panel-sub">
            {[client?.fullName, phone].filter(Boolean).join(' · ') || 'нет телефона'}
            {chat?.isClosed ? ' · сессия закрыта' : ''}
          </div>
        </div>
        <div className="wa-panel-head-actions">
          <button type="button" className="wa-mini-btn" onClick={openCc} title="Открыть в КЦ">
            КЦ
          </button>
        </div>
      </div>

      {error ? <div className="wa-panel-error">{error}</div> : null}
      {hint ? <div className="wa-panel-hint">{hint}</div> : null}

      {loading ? (
        <div className="wa-panel-empty">Ищем WhatsApp…</div>
      ) : !chat ? (
        <div className="wa-panel-empty">
          <p>Личный чат Open Lines не найден (или линия устарела).</p>
          {waUrl ? (
            <a className="btn btn-primary" href={waUrl} target="_blank" rel="noreferrer">
              Написать в WhatsApp
            </a>
          ) : (
            <p className="muted">Добавьте телефон в CRM.</p>
          )}
        </div>
      ) : (
        <>
          <div className="wa-messages" ref={listRef}>
            {messages.filter(shouldRenderMessage).length === 0 ? (
              <div className="wa-panel-empty soft">Нет сообщений</div>
            ) : (
              messages.map((msg) => (
                <MessageBubble
                  key={msg.id}
                  msg={msg}
                  currentUserId={currentUserId}
                  filesMap={filesMap}
                  dialogId={chat.dialogId}
                  onOpenImage={openImage}
                />
              ))
            )}
          </div>

          {recording ? (
            <div className="wa-rec-bar">
              <span className="wa-rec-dot" />
              <span className="wa-rec-timer">{formatRec(recMs)}</span>
              <div className="wa-rec-wave" />
              <button type="button" className="wa-rec-cancel" onClick={cancelRecording}>
                Отмена
              </button>
              <button
                type="button"
                className="wa-send-btn"
                onClick={() => finishRecording(true)}
                title="Отправить"
              >
                <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                  <path d="M1.101 21.757 23.8 12.028 1.101 2.3l.011 7.912 13.623 1.816-13.623 1.817-.011 7.912z" />
                </svg>
              </button>
            </div>
          ) : (
            <div className="wa-compose">
              <button
                type="button"
                className="wa-icon-btn"
                title="Прикрепить"
                disabled={sending}
                onClick={() => fileInputRef.current?.click()}
              >
                <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22">
                  <path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 0 1 5 0v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5a2.5 2.5 0 0 0 5 0V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z" />
                </svg>
              </button>
              <input
                ref={fileInputRef}
                type="file"
                multiple
                hidden
                accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt"
                onChange={(e) => onAttach(e.target.files)}
              />
              <textarea
                rows={1}
                value={text}
                placeholder="Сообщение…"
                disabled={sending}
                onChange={(e) => setText(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    onSend();
                  }
                }}
              />
              <button
                type="button"
                className={`wa-send-btn ${hasText ? '' : 'mic'}`}
                disabled={sending}
                title={hasText ? 'Отправить' : 'Голосовое'}
                onClick={() => (hasText ? onSend() : startRecording())}
              >
                {hasText ? (
                  <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                    <path d="M1.101 21.757 23.8 12.028 1.101 2.3l.011 7.912 13.623 1.816-13.623 1.817-.011 7.912z" />
                  </svg>
                ) : (
                  <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.91-3c-.49 0-.9.36-.98.85C16.52 14.2 14.47 16 12 16s-4.52-1.8-4.93-4.15a.998.998 0 0 0-.98-.85c-.61 0-1.09.54-1 1.14.49 3 2.89 5.35 5.91 5.78V20c0 .55.45 1 1 1s1-.45 1-1v-2.08c3.02-.43 5.42-2.78 5.91-5.78.1-.6-.39-1.14-1-1.14z" />
                  </svg>
                )}
              </button>
            </div>
          )}
        </>
      )}

      {lightbox.src
        ? createPortal(
            <ImageLightbox src={lightbox.src} alt={lightbox.alt} onClose={closeLightbox} />,
            document.body
          )
        : null}
      {ccOpen
        ? createPortal(
            <ContactCenterModal url={ccUrl} onClose={() => setCcOpen(false)} />,
            document.body
          )
        : null}
    </div>
  );
}
