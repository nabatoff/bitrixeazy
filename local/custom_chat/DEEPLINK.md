# Deep-link для /local/custom_chat/

В конец `BX.ready` на странице КЦ (после `loadChatList(); setInterval(...)`) вставь:

```javascript
(async function openFromQuery() {
  const params = new URLSearchParams(window.location.search);
  const chatIdParam = params.get('chatId');
  const dialogIdParam = params.get('dialogId');
  if (!chatIdParam && !dialogIdParam) return;

  const tryOpen = async () => {
    let target = null;
    if (chatIdParam) {
      const cid = parseInt(chatIdParam, 10);
      target = (chatsCache || []).find(function (c) {
        const id = c.chat_id || (c.chat && c.chat.id);
        return parseInt(id, 10) === cid;
      });
      if (!target && cid) {
        try {
          target = await chatItemFromDialogChatId(cid);
          if (target) {
            chatsCache = mergeChatLists(chatsCache, [target]);
            await enrichChatDisplayNames([target]);
            renderChatList();
          }
        } catch (e) {
          console.warn('deeplink dialog.get', e);
        }
      }
    }
    if (!target && dialogIdParam) {
      const want = String(dialogIdParam).toLowerCase();
      target = (chatsCache || []).find(function (c) {
        const id = resolveDialogId(c);
        return id && String(id).toLowerCase() === want;
      });
    }
    if (target) {
      await openDialog(target);
      return true;
    }
    return false;
  };

  for (let i = 0; i < 8; i++) {
    if (await tryOpen()) return;
    await new Promise(function (r) { setTimeout(r, 400); });
  }
})();
```

Виджет открывает: `https://crm.artflowers.kz/local/custom_chat/?chatId=…`
