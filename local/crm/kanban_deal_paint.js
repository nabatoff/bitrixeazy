/**
 * Подсветка карточек канбана сделок по UF на воронках 15–20.
 * Подключается только на /crm/deal/kanban/ через include_kanban_deal_paint.php
 */
(function () {
	'use strict';

	var CFG = {
		categories: { 15: 1, 16: 1, 17: 1, 18: 1, 19: 1, 20: 1 },
		ufPrepay: 'UF_CRM_1764332847245',
		ufApproveNoPrepay: 'UF_CRM_1764577192130',
		ufBought: 'UF_CRM_1783486791226',
		ufPaid: 'UF_CRM_1764577842986',
		ufIssued: 'UF_CRM_1784524115744',
		approveNoPrepayOk: { 869: 1 },
		boughtOk: { 910: 1, 911: 1 },
		issuedOk: { 912: 1, 913: 1 },
		colorGreen: '#bff5bf',
		colorBlue: '#bfedff',
		colorYellow: '#f5f5a6',
		classGreen: 'wa-kanban-paint-green',
		classBlue: 'wa-kanban-paint-blue',
		classYellow: 'wa-kanban-paint-yellow',
		ajaxUrl: '/local/crm/kanban_deal_paint_ajax.php',
		batchSize: 50
	};

	var cache = {};
	var pending = {};
	var queue = [];
	var flushTimer = null;
	var paintTimer = null;
	var observed = false;
	var started = false;

	function isKanbanPage() {
		try {
			var path = (location.pathname || '') + (location.hash || '');
			return /\/crm\/deal\/kanban/i.test(path) || /\/crm\/deal\/category\/\d+\/kanban/i.test(path);
		} catch (e) {
			return false;
		}
	}

	function stageSuffix(stageId) {
		stageId = String(stageId || '');
		var i = stageId.lastIndexOf(':');
		return i >= 0 ? stageId.slice(i + 1) : stageId;
	}

	function categoryFromStage(stageId) {
		var m = String(stageId || '').match(/^C(\d+):/i);
		return m ? parseInt(m[1], 10) : 0;
	}

	function isTruthyBool(v) {
		return v === 1 || v === '1' || v === true || v === 'Y' || v === 'y';
	}

	function enumId(v) {
		if (v == null || v === '') return 0;
		if (typeof v === 'object') {
			if (v.ID != null) return parseInt(v.ID, 10) || 0;
			if (v.id != null) return parseInt(v.id, 10) || 0;
			if (v.VALUE != null && /^\d+$/.test(String(v.VALUE))) return parseInt(v.VALUE, 10) || 0;
		}
		var n = parseInt(v, 10);
		return isNaN(n) ? 0 : n;
	}

	function resolveColor(stageId, fields) {
		var cat = categoryFromStage(stageId);
		if (!CFG.categories[cat]) return '';
		var suf = stageSuffix(stageId);
		fields = fields || {};

		if (suf === 'PREPARATION') {
			if (isTruthyBool(fields[CFG.ufPrepay])) return 'green';
			var approve = enumId(fields[CFG.ufApproveNoPrepay]);
			if (CFG.approveNoPrepayOk[approve]) return 'yellow';
			return '';
		}
		if (suf === 'PREPAYMENT_INVOIC') {
			var bought = enumId(fields[CFG.ufBought]);
			return CFG.boughtOk[bought] ? 'green' : '';
		}
		if (suf === 'EXECUTING') {
			var issued = enumId(fields[CFG.ufIssued]);
			if (CFG.issuedOk[issued]) return 'blue';
			if (isTruthyBool(fields[CFG.ufPaid])) return 'green';
			return '';
		}
		return '';
	}

	function paintTargets(shell) {
		var list = [];
		if (!shell) return list;
		/* только .crm-kanban-item — оболочку .main-kanban-item не красим,
		   иначе padding-gap между карточками тоже заливается и они сливаются */
		var inner = shell.querySelector ? shell.querySelector('.crm-kanban-item') : null;
		if (inner) list.push(inner);
		else list.push(shell);
		return list;
	}

	function stripPaintEl(el) {
		if (!el) return;
		el.classList.remove(CFG.classGreen, CFG.classBlue, CFG.classYellow);
		if (el.style) {
			try {
				el.style.removeProperty('background-color');
				el.style.removeProperty('background');
			} catch (e) {
				el.style.backgroundColor = '';
				el.style.background = '';
			}
		}
	}

	function clearPaint(shell) {
		/* снять старый tint и с оболочки (legacy), и с inner */
		stripPaintEl(shell);
		paintTargets(shell).forEach(stripPaintEl);
	}

	function applyPaint(shell, color) {
		clearPaint(shell);
		if (!color) return;
		var cls = '';
		var hex = '';
		if (color === 'blue') {
			cls = CFG.classBlue;
			hex = CFG.colorBlue;
		} else if (color === 'green') {
			cls = CFG.classGreen;
			hex = CFG.colorGreen;
		} else if (color === 'yellow') {
			cls = CFG.classYellow;
			hex = CFG.colorYellow;
		}
		if (!cls) return;
		paintTargets(shell).forEach(function (el) {
			el.classList.add(cls);
			try {
				el.style.setProperty('background-color', hex, 'important');
				el.style.setProperty('background', hex, 'important');
			} catch (e) {
				el.style.backgroundColor = hex;
			}
		});
	}

	function findCards(root) {
		root = root || document;
		if (!root.querySelectorAll) return [];
		var nodes = root.querySelectorAll('.main-kanban-item[data-id]');
		var list = [];
		var seen = {};
		for (var i = 0; i < nodes.length; i++) {
			var el = nodes[i];
			var id = parseDealId(el);
			if (!id || seen[id]) continue;
			seen[id] = 1;
			list.push(el);
		}
		return list;
	}

	function parseDealId(el) {
		if (!el) return 0;
		var raw = el.getAttribute('data-id') || (el.dataset && el.dataset.id) || '';
		if (!raw && el.id) {
			var m = String(el.id).match(/(\d+)/);
			if (m) return parseInt(m[1], 10) || 0;
		}
		raw = String(raw);
		if (/^\d+$/.test(raw)) return parseInt(raw, 10);
		var m2 = raw.match(/(?:DEAL_|deal_|CRM_DEAL_)?(\d+)/i);
		return m2 ? parseInt(m2[1], 10) || 0 : 0;
	}

	function parseStageFromCard(el) {
		if (!el) return '';
		var stage =
			el.getAttribute('data-column-id') ||
			el.getAttribute('data-stage-id') ||
			(el.dataset && (el.dataset.columnId || el.dataset.stageId)) ||
			'';
		if (stage) return String(stage);

		/* у Bitrix data-id стадии висит на .main-kanban-column-body */
		var colBody = el.closest('.main-kanban-column-body');
		if (colBody) {
			stage = colBody.getAttribute('data-id') || (colBody.dataset && colBody.dataset.id) || '';
			if (stage) return String(stage);
		}

		var col = el.closest('.main-kanban-column, .crm-kanban-column');
		if (col) {
			var body = col.querySelector('.main-kanban-column-body[data-id]');
			stage =
				(body && body.getAttribute('data-id')) ||
				col.getAttribute('data-id') ||
				col.getAttribute('data-column-id') ||
				(col.dataset && col.dataset.id) ||
				'';
			if (stage) return String(stage);
		}
		return '';
	}

	function fetchDeals(ids) {
		return new Promise(function (resolve, reject) {
			var data = {
				sessid: (window.BX && BX.bitrix_sessid) ? BX.bitrix_sessid() : '',
				ids: ids.join(',')
			};

			if (window.BX && BX.ajax) {
				BX.ajax({
					url: CFG.ajaxUrl,
					method: 'POST',
					dataType: 'json',
					data: data,
					onsuccess: function (resp) {
						if (!resp || resp.status !== 'success') {
							reject(resp || new Error('bad response'));
							return;
						}
						resolve(resp.deals || {});
					},
					onfailure: function (err) {
						reject(err || new Error('ajax fail'));
					}
				});
				return;
			}

			var body = 'sessid=' + encodeURIComponent(data.sessid) + '&ids=' + encodeURIComponent(data.ids);
			fetch(CFG.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
				body: body
			}).then(function (r) { return r.json(); }).then(function (resp) {
				if (!resp || resp.status !== 'success') {
					reject(resp || new Error('bad response'));
					return;
				}
				resolve(resp.deals || {});
			}).catch(reject);
		});
	}

	function scheduleFlush() {
		if (flushTimer) return;
		flushTimer = setTimeout(function () {
			flushTimer = null;
			flushQueue();
		}, 150);
	}

	function enqueueDeal(id) {
		id = parseInt(id, 10) || 0;
		if (id <= 0 || cache[id] || pending[id]) return;
		pending[id] = true;
		queue.push(id);
		scheduleFlush();
	}

	function flushQueue() {
		if (!queue.length) return;
		var chunk = queue.splice(0, CFG.batchSize);
		fetchDeals(chunk).then(function (dealsMap) {
			chunk.forEach(function (id) {
				delete pending[id];
				var row = dealsMap[String(id)] || dealsMap[id];
				if (!row) {
					cache[id] = { STAGE_ID: '', CATEGORY_ID: 0, fields: {}, miss: true };
					return;
				}
				cache[id] = {
					STAGE_ID: row.STAGE_ID || '',
					CATEGORY_ID: parseInt(row.CATEGORY_ID, 10) || 0,
					fields: {
						UF_CRM_1764332847245: row[CFG.ufPrepay],
						UF_CRM_1764577192130: row[CFG.ufApproveNoPrepay],
						UF_CRM_1783486791226: row[CFG.ufBought],
						UF_CRM_1764577842986: row[CFG.ufPaid],
						UF_CRM_1784524115744: row[CFG.ufIssued]
					}
				};
			});
			schedulePaint();
			if (queue.length) scheduleFlush();
		}).catch(function () {
			chunk.forEach(function (id) { delete pending[id]; });
			if (queue.length) scheduleFlush();
		});
	}

	function paintCard(el) {
		var dealId = parseDealId(el);
		if (!dealId) return;

		var stageFromDom = parseStageFromCard(el);
		var entry = cache[dealId];
		if (!entry) {
			enqueueDeal(dealId);
			return;
		}
		if (entry.miss) {
			clearPaint(el);
			return;
		}

		var stageId = entry.STAGE_ID || stageFromDom;
		if (stageFromDom && /:/.test(stageFromDom)) {
			stageId = stageFromDom;
		}

		var cat = entry.CATEGORY_ID || categoryFromStage(stageId);
		if (!CFG.categories[cat]) {
			clearPaint(el);
			return;
		}

		applyPaint(el, resolveColor(stageId, entry.fields));
	}

	function paintAll() {
		findCards(document).forEach(paintCard);
	}

	function schedulePaint() {
		if (paintTimer) return;
		paintTimer = setTimeout(function () {
			paintTimer = null;
			paintAll();
		}, 150);
	}

	function invalidateDeals(ids) {
		var any = false;
		(ids || []).forEach(function (id) {
			id = parseInt(id, 10) || 0;
			if (id <= 0) return;
			delete cache[id];
			delete pending[id];
			any = true;
		});
		if (any) schedulePaint();
	}

	function idsFromCrmPull(params) {
		var ids = [];
		if (!params) return ids;
		function push(v) {
			if (v && typeof v === 'object') {
				push(v.ID || v.id || v.ENTITY_ID);
				return;
			}
			var n = parseInt(v, 10);
			if (n > 0) ids.push(n);
		}
		push(params.ID || params.id || params.ENTITY_ID);
		if (params.FIELDS) push(params.FIELDS.ID || params.FIELDS.id);
		if (params.fields) push(params.fields.ID || params.fields.id);
		if (Array.isArray(params.IDS)) params.IDS.forEach(push);
		if (Array.isArray(params.ids)) params.ids.forEach(push);
		return ids;
	}

	function invalidateVisible() {
		findCards(document).forEach(function (el) {
			var id = parseDealId(el);
			if (id) {
				delete cache[id];
				delete pending[id];
			}
		});
		schedulePaint();
	}

	function bindEvents() {
		if (window.BX && BX.addCustomEvent) {
			[
				'Kanban.Grid:onRender',
				'Kanban.Grid:onItemDragStop',
				'Kanban.Grid:onItemMoved',
				'Kanban.Grid:onColumnLoadAsync'
			].forEach(function (ev) {
				try {
					BX.addCustomEvent(ev, function () {
						schedulePaint();
					});
				} catch (e) { /* ignore */ }
			});
			try {
				BX.addCustomEvent('onPullEvent-crm', function (command, params) {
					var ids = idsFromCrmPull(params);
					if (ids.length) invalidateDeals(ids);
					else schedulePaint();
				});
			} catch (e) { /* ignore */ }
			[
				'SidePanel.Slider:onCloseComplete',
				'SidePanel.Slider:onClose',
				'BX.Crm.EntityEditor:onSave',
				'Crm.PartialEditorDialog.Close',
				'BX.Main.Filter:apply'
			].forEach(function (ev) {
				try {
					BX.addCustomEvent(ev, function () {
						invalidateVisible();
					});
				} catch (e) { /* ignore */ }
			});
		}

		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) schedulePaint();
		});
	}

	function observe() {
		if (observed) return;

		function attach(root) {
			if (observed || !root) return;
			observed = true;
			var mo = new MutationObserver(function (mutations) {
				for (var i = 0; i < mutations.length; i++) {
					if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
						schedulePaint();
						return;
					}
				}
			});
			mo.observe(root, { childList: true, subtree: true });
		}

		var root = document.querySelector('.main-kanban, .crm-kanban');
		if (root) {
			attach(root);
			return;
		}
		if (!document.body) return;
		var wait = new MutationObserver(function () {
			var k = document.querySelector('.main-kanban, .crm-kanban');
			if (k) {
				wait.disconnect();
				attach(k);
			}
		});
		wait.observe(document.body, { childList: true, subtree: true });
		setTimeout(function () {
			try { wait.disconnect(); } catch (e) { /* ignore */ }
			if (!observed) {
				var k = document.querySelector('.main-kanban, .crm-kanban');
				if (k) attach(k);
			}
		}, 8000);
	}

	function start() {
		if (!isKanbanPage() || started) return;
		started = true;
		bindEvents();
		observe();
		schedulePaint();
		setTimeout(schedulePaint, 400);
		setTimeout(schedulePaint, 1200);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}

	if (window.BX && BX.ready) {
		BX.ready(start);
	}

	window.WaKanbanDealPaint = {
		repaint: schedulePaint,
		invalidate: invalidateVisible,
		cache: function () { return cache; }
	};
	window.__waKanbanPaintLoaded = 1;
})();
