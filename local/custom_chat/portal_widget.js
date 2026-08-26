(function () {
	if (window.__waCcPortalBoot) return;
	window.__waCcPortalBoot = true;

	var cfg = window.__WA_CC_PORTAL || {};
	var unreadUrl = cfg.unreadUrl || '/local/custom_chat/portal_unread.php';
	var sliderBase = cfg.slider || '/local/custom_chat/slider.php';
	var menuId = String(cfg.menuId || '1897508225');
	var lastCount = -1;
	var fetchTimer = null;
	var skipGrowToast = false;
	var pullBound = false;
	var fetchInFlight = false;
	var lastFetchAt = 0;
	var POLL_MS = 45000;
	var FETCH_DEBOUNCE_MS = 2000;

	function currentUserId() {
		var n = parseInt(cfg.userId, 10) || 0;
		if (n) return n;
		try {
			if (window.BX && BX.message) {
				n = parseInt(BX.message('USER_ID') || BX.message('USERID') || 0, 10) || 0;
			}
		} catch (e) { /* ignore */ }
		return n;
	}

	function normalizeDialogId(id) {
		if (id == null || id === '') return '';
		var s = String(id);
		if (/^chat\d+$/i.test(s)) return s.toLowerCase();
		if (/^\d+$/.test(s)) return 'chat' + s;
		return s;
	}

	function findMenuItem() {
		var byId = document.getElementById('bx_left_menu_' + menuId)
			|| document.querySelector('.menu-item-block[data-id="' + menuId + '"]')
			|| document.querySelector('.menu-item-block[data-link*="/marketplace/app/64"]')
			|| document.querySelector('a.menu-item-link[href*="/marketplace/app/64"]');
		if (byId) {
			return byId.classList && byId.classList.contains('menu-item-block')
				? byId
				: (byId.closest('.menu-item-block') || byId);
		}
		var nodes = document.querySelectorAll('.menu-item-block, a.menu-item-link, [data-role="item-text"]');
		for (var i = 0; i < nodes.length; i++) {
			var el = nodes[i];
			var href = (el.getAttribute && (el.getAttribute('href') || el.getAttribute('data-link'))) || '';
			var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
			if (/\/marketplace\/app\/64\b/.test(href) || /\/marketplace\/app\/local\.6a7b/.test(href) || /\/local\/custom_chat\/app/.test(href)) {
				return el.closest('.menu-item-block') || el;
			}
			if (/^ватсап\s*чат$/i.test(text) || (el.getAttribute && el.getAttribute('data-role') === 'item-text' && /ватсап\s*чат/i.test(text))) {
				return el.closest('.menu-item-block') || el;
			}
		}
		return null;
	}

	function ensureBadge(item) {
		if (!item) return null;
		item.classList.add('wa-cc-menu-icon');
		item.classList.remove('menu-item-no-icon-state');
		var icon = item.querySelector('.menu-item-icon');
		if (icon && icon.textContent) icon.textContent = '';
		var badge = item.querySelector('.wa-cc-menu-badge');
		if (badge) return badge;
		badge = document.createElement('span');
		badge.className = 'wa-cc-menu-badge';
		badge.setAttribute('aria-label', 'Непрочитанные чаты WhatsApp');
		var textNode = item.querySelector('.menu-item-link-text, [data-role="item-text"]');
		if (textNode && textNode.parentNode) {
			textNode.parentNode.appendChild(badge);
		} else {
			item.appendChild(badge);
		}
		return badge;
	}

	function setNativeCounter(item, n) {
		if (!item) return;
		item.classList.toggle('menu-item-with-index', n > 0);
		var native = item.querySelector('#menu-counter-wa_cc_unread') || item.querySelector('.ui-counter');
		if (!native) return;
		native.style.display = n > 0 ? '' : 'none';
		native.classList.toggle('--hide', n <= 0);
		native.classList.toggle('ui-counter--hide', n <= 0);
		var val = native.querySelector('.ui-counter__value, .ui-cnt-value');
		if (val) val.textContent = n > 0 ? String(n) : '0';
		else if (n > 0) native.setAttribute('data-value', String(n));
	}

	function setMenuCount(n) {
		n = parseInt(n, 10) || 0;
		lastCount = n;
		var item = findMenuItem();
		if (!item) return;
		var badge = ensureBadge(item);
		if (badge) {
			if (n > 0) {
				badge.textContent = n > 99 ? '99+' : String(n);
				badge.classList.add('is-on');
			} else {
				badge.textContent = '';
				badge.classList.remove('is-on');
			}
		}
		setNativeCounter(item, n);
		try {
			if (window.BX && BX.Intranet && BX.Intranet.LeftMenu && typeof BX.Intranet.LeftMenu.updateCounters === 'function') {
				BX.Intranet.LeftMenu.updateCounters({ wa_cc_unread: n });
			}
		} catch (e) { /* ignore */ }
	}

	function fetchUnreadCount() {
		if (document.hidden) return;
		if (fetchInFlight) return;
		fetchInFlight = true;
		lastFetchAt = Date.now();
		fetch(unreadUrl, { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.ok) return;
				var n = parseInt(data.count, 10) || 0;
				var grew = lastCount >= 0 && n > lastCount && !skipGrowToast;
				skipGrowToast = false;
				setMenuCount(n);
				if (grew) {
					var chat = (data.chats && data.chats[0]) || {};
					showToast({
						title: chat.title || 'WhatsApp',
						text: 'Новое сообщение',
						chatId: chat.chatId || 0,
						dialogId: chat.dialogId || ''
					});
				}
			})
			.catch(function () { /* ignore */ })
			.then(function () { fetchInFlight = false; });
	}

	function scheduleFetch() {
		clearTimeout(fetchTimer);
		fetchTimer = setTimeout(fetchUnreadCount, FETCH_DEBOUNCE_MS);
	}

	function pick(obj, keys) {
		if (!obj) return undefined;
		for (var i = 0; i < keys.length; i++) {
			if (obj[keys[i]] != null && obj[keys[i]] !== '') return obj[keys[i]];
		}
		return undefined;
	}

	function isChatOpenNow(chatId, dialogId) {
		try {
			var openChat = String(window.__waCcOpenChatId || '');
			var openDlg = normalizeDialogId(window.__waCcOpenDialogId || '');
			if (dialogId && openDlg && normalizeDialogId(dialogId) === openDlg) return true;
			if (chatId && openChat && String(chatId) === String(openChat)) return true;
			var sp = window.BX && BX.SidePanel && BX.SidePanel.Instance;
			if (sp && typeof sp.getOpenSliders === 'function') {
				var sliders = sp.getOpenSliders() || [];
				for (var i = 0; i < sliders.length; i++) {
					var u = '';
					try { u = sliders[i].getUrl() || ''; } catch (e2) { u = ''; }
					if (u.indexOf('custom_chat') === -1) continue;
					if (chatId && u.indexOf('chatId=' + chatId) !== -1) return true;
					if (dialogId && u.indexOf('dialogId=') !== -1) return true;
					try {
						var frame = sliders[i].iframe || (sliders[i].getFrameWindow && sliders[i].getFrameWindow());
						var win = frame && frame.contentWindow ? frame.contentWindow : frame;
						if (win) {
							if (chatId && String(win.__waCcOpenChatId || '') === String(chatId)) return true;
							if (dialogId && normalizeDialogId(win.__waCcOpenDialogId) === normalizeDialogId(dialogId)) return true;
						}
					} catch (e3) { /* ignore */ }
				}
			}
		} catch (e) { /* ignore */ }
		return false;
	}

	function openWaChat(chatId, dialogId) {
		var q = [];
		if (chatId) q.push('chatId=' + encodeURIComponent(chatId));
		else if (dialogId) q.push('dialogId=' + encodeURIComponent(dialogId));
		var url = sliderBase + (q.length ? '?' + q.join('&') : '');
		try {
			if (window.BXMobileApp && BXMobileApp.PageManager && BXMobileApp.PageManager.loadPageBlank) {
				BXMobileApp.PageManager.loadPageBlank({ url: url, title: 'WhatsApp чат', cache: false });
				return;
			}
		} catch (e) { /* ignore */ }
		var sp = (window.BX && BX.SidePanel && BX.SidePanel.Instance)
			|| (window.top && window.top.BX && window.top.BX.SidePanel && window.top.BX.SidePanel.Instance);
		if (sp) {
			sp.open(url, { width: 1100, cacheable: false, allowChangeHistory: false, printable: false });
			return;
		}
		window.location.href = url;
	}

	function toastRoot() {
		var el = document.getElementById('wa-cc-toasts');
		if (el) return el;
		el = document.createElement('div');
		el.id = 'wa-cc-toasts';
		el.className = 'wa-cc-toasts';
		(document.body || document.documentElement).appendChild(el);
		return el;
	}

	function stripText(s) {
		return String(s || '')
			.replace(/\[[^\]]+\]/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function showToast(info) {
		var root = toastRoot();
		var id = 'wa-cc-toast-' + (info.chatId || info.dialogId || 'x');
		var old = document.getElementById(id);
		if (old) old.remove();
		while (root.children.length >= 3) {
			root.removeChild(root.lastChild);
		}
		var el = document.createElement('div');
		el.id = id;
		el.className = 'wa-cc-toast';
		el.innerHTML =
			'<div class="wa-cc-toast-title"></div>' +
			'<div class="wa-cc-toast-text"></div>' +
			'<div class="wa-cc-toast-hint">Открыть чат</div>';
		el.querySelector('.wa-cc-toast-title').textContent = info.title || 'WhatsApp';
		el.querySelector('.wa-cc-toast-text').textContent = info.text || 'Новое сообщение';
		el.addEventListener('click', function () {
			openWaChat(info.chatId, info.dialogId);
			el.remove();
		});
		root.insertBefore(el, root.firstChild);
		setTimeout(function () {
			if (el.parentNode) el.remove();
		}, 8000);
	}

	function haystack(params, chat) {
		return [
			chat && chat.type, chat && chat.TYPE,
			chat && chat.entity_type, chat && chat.ENTITY_TYPE, chat && chat.entityType,
			chat && chat.entity_id, chat && chat.ENTITY_ID, chat && chat.entityId,
			params && params.dialogId, params && params.DIALOG_ID, params && params.dialog_id
		].join('|').toLowerCase();
	}

	function isWaOlPull(params) {
		params = params || {};
		var chat = params.chat || params.CHAT || {};
		var h = haystack(params, chat);
		var type = String(pick(chat, ['type', 'TYPE']) || params.chatType || '').toLowerCase();
		var entityType = String(pick(chat, ['entity_type', 'ENTITY_TYPE', 'entityType']) || '').toUpperCase();
		if (type === 'lines' || type === 'openlines' || entityType === 'LINES') return true;
		if (h.indexOf('imol|') !== -1 || h.indexOf('fos_green') !== -1) return true;
		return /fos_green|whatsapp|green_api|@c\.us|@g\.us/.test(h);
	}

	function isIncoming(params) {
		var msg = (params && (params.message || params.MESSAGE)) || {};
		var sender = parseInt(pick(msg, ['senderId', 'sender_id', 'authorId', 'author_id', 'AUTHOR_ID', 'SENDER_ID']) || params.userId || params.USER_ID || 0, 10) || 0;
		var me = currentUserId();
		if (me && sender && sender === me) return false;
		if (msg.system || msg.SYSTEM) return false;
		return true;
	}

	function pullInfo(params) {
		var msg = params.message || params.MESSAGE || {};
		var chat = params.chat || params.CHAT || {};
		var chatId = pick(params, ['chatId', 'chat_id', 'CHAT_ID']) || pick(msg, ['chat_id', 'chatId', 'CHAT_ID']) || pick(chat, ['id', 'ID']);
		var dialogId = pick(params, ['dialogId', 'dialog_id', 'DIALOG_ID']) || pick(msg, ['dialog_id', 'dialogId']) || (chatId ? ('chat' + chatId) : '');
		var users = params.users || params.USERS || {};
		var sender = pick(msg, ['senderId', 'sender_id', 'authorId', 'AUTHOR_ID', 'SENDER_ID']);
		var u = users[sender] || users[String(sender)] || {};
		var title = pick(chat, ['name', 'NAME', 'title', 'TITLE']) || pick(u, ['name', 'NAME', 'first_name', 'FIRST_NAME']) || 'WhatsApp';
		var text = stripText(pick(msg, ['text', 'TEXT', 'message', 'MESSAGE']) || '');
		if (!text) text = 'Новое сообщение';
		return {
			chatId: chatId ? parseInt(chatId, 10) : 0,
			dialogId: dialogId,
			title: String(title).replace(/^\[.*?\]\s*/, ''),
			text: text
		};
	}

	function handleIncoming(params, moduleId) {
		params = params || {};
		if (params.params && (params.params.message || params.params.MESSAGE || params.params.chat || params.params.CHAT)) {
			params = params.params;
		}
		var ol = isWaOlPull(params) || String(moduleId || '').toLowerCase() === 'imopenlines';
		if (!ol || !isIncoming(params)) {
			if (ol) scheduleFetch();
			return;
		}
		var info = pullInfo(params);
		if (isChatOpenNow(info.chatId, info.dialogId)) {
			scheduleFetch();
			return;
		}
		skipGrowToast = true;
		showToast(info);
		if (lastCount >= 0) setMenuCount(lastCount + 1);
		scheduleFetch();
	}

	function bindPull() {
		if (pullBound) return true;
		var ok = false;

		function onAnyPull(moduleId, command, params) {
			var mod = String(moduleId || '').toLowerCase();
			var cmd = String(command || '');
			if (mod !== 'im' && mod !== 'imopenlines') return;
			if (/message/i.test(cmd)) {
				handleIncoming(params || {}, mod);
				return;
			}
			if (/readMessage|unread|readAll|messageRead|recent/i.test(cmd)) {
				if (mod === 'imopenlines' || isWaOlPull(params || {})) scheduleFetch();
			}
		}

		if (window.BX && BX.PULL && typeof BX.PULL.subscribe === 'function') {
			var pullType = (BX.PullClient && BX.PullClient.SubscriptionType)
				? BX.PullClient.SubscriptionType.Server : null;
			['im', 'imopenlines'].forEach(function (mod) {
				var sub = {
					moduleId: mod,
					callback: function (data) {
						onAnyPull(mod, (data && data.command) || '', (data && data.params) || {});
					}
				};
				if (pullType) sub.type = pullType;
				BX.PULL.subscribe(sub);
			});
			ok = true;
		}
		if (window.BX && BX.addCustomEvent) {
			BX.addCustomEvent('onPullEvent', function (moduleId, command, params) {
				onAnyPull(moduleId, command, params);
			});
			BX.addCustomEvent('onPullEvent-im', function (command, params) {
				onAnyPull('im', command, params);
			});
			BX.addCustomEvent('onPullEvent-imopenlines', function (command, params) {
				onAnyPull('imopenlines', command, params);
			});
			ok = true;
		}
		try {
			if (window.BX && BX.Event && BX.Event.EventEmitter && typeof BX.Event.EventEmitter.subscribe === 'function') {
				['onPullEvent-im', 'onPullEvent-imopenlines', 'onPullEvent-main'].forEach(function (ev) {
					BX.Event.EventEmitter.subscribe(ev, function (event) {
						var data = [];
						try { data = event.getCompatData ? event.getCompatData() : (event.data || []); } catch (e2) { data = []; }
						if (ev === 'onPullEvent-main') {
							if (String(data[0] || '') === 'user_counter') scheduleFetch();
							return;
						}
						onAnyPull(ev.replace('onPullEvent-', ''), data[0], data[1] || {});
					});
				});
				ok = true;
			}
		} catch (e) { /* ignore */ }
		if (ok) pullBound = true;
		return ok;
	}

	window.addEventListener('message', function (ev) {
		var d = ev && ev.data;
		if (!d || d.source !== 'wa-cc') return;
		if (d.type === 'unread' && typeof d.count === 'number') {
			setMenuCount(d.count);
		}
	});

	function paintMenu() {
		var item = findMenuItem();
		ensureBadge(item);
		if (lastCount >= 0) setMenuCount(lastCount);
		return !!item;
	}

	function boot() {
		paintMenu();
		fetchUnreadCount();
		if (!bindPull()) {
			var n = 0;
			var t = setInterval(function () {
				n++;
				if (bindPull() || n > 50) clearInterval(t);
			}, 200);
		}
		setInterval(function () {
			if (!document.hidden) fetchUnreadCount();
		}, POLL_MS);
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden && Date.now() - lastFetchAt > 15000) fetchUnreadCount();
		});
		var moTries = 0;
		var mo = setInterval(function () {
			moTries++;
			if (paintMenu() || moTries > 50) clearInterval(mo);
		}, 250);
		try {
			var root = document.getElementById('menu-items-block') || document.querySelector('.menu-items-block') || document.body;
			new MutationObserver(function () {
				if (!document.querySelector('.wa-cc-menu-icon')) paintMenu();
			}).observe(root, { childList: true, subtree: true });
		} catch (e) { /* ignore */ }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
