import { useCallback, useEffect, useRef, useState } from 'react';
import { contactCenterChatUrl } from '../config/contactCenter.js';
import {
  findPersonalChatForDeal,
  formatMessageTime,
  getDialogMessages,
  isOutgoingMessage,
  isSystemMessage,
  markDialogRead,
  sendDialogMessage,
  stripBbLite,
  waMeUrl,
} from '../bitrix/openLines.js';

export function ClientChatPanel({ dealId, client, currentUserId }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [chat, setChat] = useState(null);
  const [messages, setMessages] = useState([]);
  const [text, setText] = useState('');
  const [sending, setSending] = useState(false);
  const listRef = useRef(null);
  const lastIdRef = useRef(0);

  const phone = client?.phone || '';
  const waUrl = waMeUrl(phone);
  const contactId = client?.contactId || null;

  const scrollBottom = () => {
    const el = listRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  };

  const loadChat = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const found = await findPersonalChatForDeal({ dealId, contactId });
      setChat(found);
      if (!found) {
        setMessages([]);
        return;
      }
      const { messages: msgs } = await getDialogMessages(found.dialogId, { limit: 50 });
      setMessages(msgs);
      lastIdRef.current = msgs.length ? Number(msgs[msgs.length - 1].id) || 0 : 0;
      markDialogRead(found.dialogId);
      requestAnimationFrame(scrollBottom);
    } catch (err) {
      const msg = err.message || String(err);
      if (/insufficient_scope|ACCESS_DENIED|permission/i.test(msg)) {
        setError(
          'Нет прав на чат. В локальном приложении добавь скоупы im и imopenlines, переоткрой виджет.'
        );
      } else {
        setError(msg);
      }
      setChat(null);
      setMessages([]);
    } finally {
      setLoading(false);
    }
  }, [dealId, contactId]);

  useEffect(() => {
    loadChat();
  }, [loadChat]);

  useEffect(() => {
    if (!chat?.dialogId) return undefined;
    const t = setInterval(async () => {
      try {
        const { messages: msgs } = await getDialogMessages(chat.dialogId, {
          limit: 20,
          firstId: lastIdRef.current || 0,
        });
        if (!msgs.length) return;
        setMessages((prev) => {
          const ids = new Set(prev.map((m) => String(m.id)));
          const add = msgs.filter((m) => !ids.has(String(m.id)));
          if (!add.length) return prev;
          const next = [...prev, ...add].sort((a, b) => Number(a.id) - Number(b.id));
          lastIdRef.current = Number(next[next.length - 1].id) || lastIdRef.current;
          return next;
        });
        requestAnimationFrame(scrollBottom);
      } catch {
        /* ignore poll errors */
      }
    }, 4500);
    return () => clearInterval(t);
  }, [chat?.dialogId]);

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
      const { messages: msgs } = await getDialogMessages(chat.dialogId, {
        limit: 20,
        firstId: lastIdRef.current || 0,
      });
      setMessages((prev) => {
        const ids = new Set(prev.map((m) => String(m.id)));
        const add = msgs.filter((m) => !ids.has(String(m.id)));
        const next = [...prev, ...add].sort((a, b) => Number(a.id) - Number(b.id));
        if (next.length) lastIdRef.current = Number(next[next.length - 1].id) || 0;
        return next;
      });
      requestAnimationFrame(scrollBottom);
    } catch (err) {
      setError(err.message || String(err));
    } finally {
      setSending(false);
    }
  };

  const openCc = () => {
    const url = contactCenterChatUrl({
      chatId: chat?.chatId,
      dialogId: chat?.dialogId,
    });
    window.open(url, '_blank', 'noopener,noreferrer');
  };

  return (
    <div className="client-chat">
      <div className="client-chat-head">
        <div>
          <div className="client-chat-title">Чат с клиентом</div>
          <div className="client-chat-sub">
            {client?.fullName || 'Клиент'}
            {phone ? ` · ${phone}` : ''}
          </div>
        </div>
        <div className="client-chat-actions">
          <button type="button" className="btn btn-secondary" onClick={loadChat} disabled={loading}>
            Обновить
          </button>
          <button type="button" className="btn btn-secondary" onClick={openCc}>
            Открыть в КЦ
          </button>
        </div>
      </div>

      {error && <div className="errors" style={{ margin: '0 0 12px' }}>{error}</div>}

      {loading ? (
        <p className="muted">Ищем диалог WhatsApp…</p>
      ) : !chat ? (
        <div className="client-chat-empty">
          <p>Личного чата Open Lines с этим клиентом не найдено.</p>
          {waUrl ? (
            <a className="btn btn-primary" href={waUrl} target="_blank" rel="noreferrer">
              Написать в WhatsApp
            </a>
          ) : (
            <p className="muted">У контакта нет телефона — добавьте номер в CRM.</p>
          )}
        </div>
      ) : (
        <>
          <div className="client-chat-meta muted">
            {chat.title}
            {chat.isClosed ? ' · сессия была закрыта (при отправке откроем снова)' : ''}
          </div>
          <div className="client-chat-list" ref={listRef}>
            {messages.length === 0 ? (
              <div className="muted" style={{ textAlign: 'center', padding: 16 }}>
                Нет сообщений
              </div>
            ) : (
              messages.map((msg) => {
                const system = isSystemMessage(msg);
                const out = !system && isOutgoingMessage(msg, currentUserId);
                const body = stripBbLite(msg.text) || (msg.params?.FILE_ID ? '📎 Файл' : '');
                return (
                  <div
                    key={msg.id}
                    className={`client-chat-msg ${system ? 'is-system' : out ? 'is-out' : 'is-in'}`}
                  >
                    <div className="client-chat-msg-text">{body || '—'}</div>
                    <div className="client-chat-msg-time">
                      {formatMessageTime(msg.date || msg.DATE)}
                    </div>
                  </div>
                );
              })
            )}
          </div>
          <div className="client-chat-compose">
            <textarea
              rows={2}
              value={text}
              placeholder="Сообщение клиенту…"
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
              className="btn btn-primary"
              disabled={sending || !text.trim()}
              onClick={onSend}
            >
              {sending ? '…' : 'Отправить'}
            </button>
          </div>
        </>
      )}
    </div>
  );
}
