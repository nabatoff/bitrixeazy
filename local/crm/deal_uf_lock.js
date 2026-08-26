/**
 * Read-only UF на карточке/канбане сделки для не-админов.
 * Список полей: window.__WA_DEAL_UF_LOCK
 */
(function () {
	'use strict';

	var FIELDS = [];
	var locked = {};

	function currentFields() {
		var a = Array.isArray(window.__WA_DEAL_UF_LOCK) ? window.__WA_DEAL_UF_LOCK : [];
		var b = Array.isArray(window.__DSG_FIELD_LOCK) ? window.__DSG_FIELD_LOCK : [];
		return a.concat(b);
	}

	function rebuildLocked() {
		FIELDS = currentFields();
		locked = {};
		FIELDS.forEach(function (n) { locked[String(n)] = 1; });
		return FIELDS.length;
	}

	if (window.WaDealUfLock && typeof window.WaDealUfLock.apply === 'function') {
		window.WaDealUfLock.apply();
		return;
	}

	if (!rebuildLocked()) {
		/* поля могут появиться позже (DSG epilog) — всё равно подпишемся */
	}

	function markNode(node) {
		if (!node || !node.classList) return;
		node.classList.add('wa-deal-uf-locked');
		try {
			node.style.setProperty('pointer-events', 'none', 'important');
			node.style.setProperty('cursor', 'default', 'important');
			node.style.setProperty('opacity', '0.92');
		} catch (e) {
			node.style.pointerEvents = 'none';
		}
	}

	function lockControl(control) {
		if (!control) return;
		try {
			if (typeof control.setEditable === 'function') {
				control.setEditable(false);
			}
		} catch (e1) { /* ignore */ }
		try {
			control._isEditable = false;
			control._isEditInViewEnabled = false;
		} catch (e2) { /* ignore */ }
		try {
			var scheme = control.getSchemeElement ? control.getSchemeElement() : control._schemeElement;
			if (scheme) {
				if (typeof scheme.setEditable === 'function') scheme.setEditable(false);
				scheme._isEditable = false;
				if (typeof scheme.setData === 'function') {
					var data = scheme.getData ? (scheme.getData() || {}) : {};
					data.enableEditInView = false;
					scheme.setData(data);
				}
			}
		} catch (e3) { /* ignore */ }
		try {
			var wrap = control.getWrapper ? control.getWrapper() : (control._wrapper || null);
			markNode(wrap);
		} catch (e4) { /* ignore */ }
	}

	function walkControls(editor) {
		if (!editor) return;
		var list = [];
		try {
			if (typeof editor.getControls === 'function') {
				list = editor.getControls() || [];
			}
		} catch (e) { list = []; }

		function visit(ctrl) {
			if (!ctrl) return;
			var id = '';
			try {
				id = String(
					(ctrl.getId && ctrl.getId()) ||
					(ctrl.getName && ctrl.getName()) ||
					ctrl.getId() ||
					''
				);
			} catch (e) {
				id = '';
			}
			if (id && locked[id]) {
				lockControl(ctrl);
			}
			var children = [];
			try {
				if (typeof ctrl.getChildren === 'function') {
					children = ctrl.getChildren() || [];
				} else if (typeof ctrl.getChildControls === 'function') {
					children = ctrl.getChildControls() || [];
				} else if (typeof ctrl.getControls === 'function') {
					children = ctrl.getControls() || [];
				} else if (ctrl._controls) {
					children = ctrl._controls;
				}
			} catch (e2) { children = []; }
			if (children && children.length) {
				for (var i = 0; i < children.length; i++) visit(children[i]);
			}
		}

		for (var i = 0; i < list.length; i++) visit(list[i]);

		FIELDS.forEach(function (name) {
			try {
				if (typeof editor.getControlById === 'function') {
					lockControl(editor.getControlById(name));
				}
				if (typeof editor.getControlByIdRecursive === 'function') {
					lockControl(editor.getControlByIdRecursive(name));
				}
			} catch (e) { /* ignore */ }
		});
	}

	function findEditors() {
		var out = [];
		try {
			if (window.BX && BX.Crm && BX.Crm.EntityEditor) {
				if (typeof BX.Crm.EntityEditor.getDefault === 'function') {
					var d = BX.Crm.EntityEditor.getDefault();
					if (d) out.push(d);
				}
				if (BX.Crm.EntityEditor.items) {
					Object.keys(BX.Crm.EntityEditor.items).forEach(function (k) {
						out.push(BX.Crm.EntityEditor.items[k]);
					});
				}
			}
		} catch (e) { /* ignore */ }
		try {
			if (window.BX && BX.UI && BX.UI.EntityEditor) {
				if (typeof BX.UI.EntityEditor.getDefault === 'function') {
					var u = BX.UI.EntityEditor.getDefault();
					if (u) out.push(u);
				}
				if (BX.UI.EntityEditor.items) {
					Object.keys(BX.UI.EntityEditor.items).forEach(function (k) {
						out.push(BX.UI.EntityEditor.items[k]);
					});
				}
			}
		} catch (e2) { /* ignore */ }
		return out;
	}

	function lockDomFallbacks() {
		FIELDS.forEach(function (name) {
			var nodes = document.querySelectorAll(
				'[data-cid="' + name + '"],[data-name="' + name + '"],[data-field-tag="' + name + '"]'
			);
			for (var i = 0; i < nodes.length; i++) {
				markNode(nodes[i]);
				var field = nodes[i].closest('.ui-entity-editor-content-block, .crm-entity-widget-content-block');
				if (field) markNode(field);
			}
		});
	}

	function apply() {
		rebuildLocked();
		if (!FIELDS.length) return;
		findEditors().forEach(walkControls);
		lockDomFallbacks();
	}

	var timer = null;
	function schedule() {
		if (timer) return;
		timer = setTimeout(function () {
			timer = null;
			apply();
		}, 50);
	}

	function boot() {
		apply();
		setTimeout(apply, 400);
		setTimeout(apply, 1200);
		setTimeout(apply, 3000);
		if (window.BX && BX.addCustomEvent) {
			[
				'BX.Crm.EntityEditor:onInit',
				'BX.Crm.EntityEditor:onControlModeChange',
				'BX.Crm.EntityEditor:onRefreshLayout',
				'BX.UI.EntityEditor:onInit',
				'BX.UI.EntityEditor:onControlModeChange',
				'BX.UI.EntityEditor:onRefreshLayout',
				'Crm.PartialEditorDialog.Close',
				'SidePanel.Slider:onLoad'
			].forEach(function (ev) {
				try { BX.addCustomEvent(ev, schedule); } catch (e) { /* ignore */ }
			});
		}
		if (document.body) {
			new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
		}
	}

	if (!document.getElementById('wa-deal-uf-lock-css')) {
		var style = document.createElement('style');
		style.id = 'wa-deal-uf-lock-css';
		style.textContent = '.wa-deal-uf-locked{pointer-events:none!important;cursor:default!important;}';
		(document.head || document.documentElement).appendChild(style);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
	if (window.BX && BX.ready) BX.ready(boot);

	window.WaDealUfLock = { apply: apply, fields: FIELDS };
})();
