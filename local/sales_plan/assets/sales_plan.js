(function () {
	'use strict';

	var cfg = window.__AF_SALES_PLAN || {};
	var state = {
		data: null,
		dirty: false,
		loading: false,
	};

	function $(id) { return document.getElementById(id); }

	function fmt(n) {
		n = Number(n) || 0;
		return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 });
	}

	function parsePeriod() {
		var val = ($('af-sp-period') || {}).value || '';
		var m = /^(\d{4})-(\d{2})$/.exec(val);
		if (!m) {
			return { year: cfg.year, month: cfg.month };
		}
		return { year: parseInt(m[1], 10), month: parseInt(m[2], 10) };
	}

	function getBranchId() {
		var el = $('af-sp-branch');
		return el ? el.value : cfg.defaultBranch;
	}

	function getCategory() {
		var el = $('af-sp-category');
		return el ? el.value : 'all';
	}

	function showAlert(text, isError) {
		var box = $('af-sp-alert');
		if (!box) return;
		box.style.display = text ? 'block' : 'none';
		box.textContent = text || '';
		box.style.background = isError ? '#fdecea' : '#fff3cd';
		box.style.color = isError ? '#611a15' : '#856404';
	}

	function setLoading(on) {
		state.loading = on;
		var btn = $('af-sp-refresh');
		if (btn) btn.disabled = on;
	}

	function post(action, extra) {
		var p = parsePeriod();
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('sessid', cfg.sessid);
		body.set('branch_id', getBranchId());
		body.set('year', String(p.year));
		body.set('month', String(p.month));
		body.set('category', getCategory());
		if (extra) {
			Object.keys(extra).forEach(function (k) {
				body.set(k, extra[k]);
			});
		}
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: body.toString(),
		}).then(function (r) { return r.json(); });
	}

	function dealsUrl(userId) {
		var p = parsePeriod();
		var q = new URLSearchParams({
			branch_id: getBranchId(),
			year: String(p.year),
			month: String(p.month),
			category: getCategory(),
		});
		if (userId) q.set('user_id', String(userId));
		return cfg.dealsUrl + '?' + q.toString();
	}

	function renderCategoryOptions(options) {
		var sel = $('af-sp-category');
		if (!sel || !options) return;
		sel.innerHTML = '';
		options.forEach(function (opt) {
			var o = document.createElement('option');
			o.value = opt.id;
			o.textContent = opt.name;
			sel.appendChild(o);
		});
	}

	function renderSummary(d) {
		var box = $('af-sp-summary');
		if (!box || !d || !d.branch) return;
		var b = d.branch;
		var cards = [
			{ label: 'Общий план', value: fmt(b.plan) + ' ₸' },
			{ label: 'Факт', value: fmt(b.actual) + ' ₸', link: true },
			{ label: 'Выполнение', value: b.percent + '%', risk: b.at_risk },
			{ label: 'Остаток', value: fmt(b.remaining) + ' ₸' },
			{ label: 'Прогноз', value: fmt(b.forecast) + ' ₸', risk: b.at_risk },
			{ label: 'Сделок', value: String(b.deals_count), link: true },
			{ label: 'Ср. чек', value: fmt(b.avg_check) + ' ₸' },
			{ label: 'Сумма персональных планов', value: fmt(b.personal_plans_sum) + ' ₸' },
		];
		box.innerHTML = cards.map(function (c) {
			var val = c.link
				? '<a class="af-sp-link" href="' + dealsUrl() + '" target="_blank">' + c.value + '</a>'
				: c.value;
			var cls = c.risk ? ' af-sp-card__value--risk' : '';
			return '<div class="af-sp-card"><div class="af-sp-card__label">' + c.label + '</div><div class="af-sp-card__value' + cls + '">' + val + '</div></div>';
		}).join('');

		if (b.allocation_warning) {
			showAlert('Нераспределено/перераспределено: ' + fmt(b.allocation_diff) + ' ₸ относительно общего плана');
		} else {
			showAlert('');
		}
	}

	function renderTable(d) {
		var tbody = document.querySelector('#af-sp-table tbody');
		if (!tbody || !d) return;
		var canEdit = d.permissions && d.permissions.can_edit;
		var saveBtn = $('af-sp-save');
		if (saveBtn) saveBtn.style.display = canEdit ? '' : 'none';

		tbody.innerHTML = (d.managers || []).map(function (m) {
			var planCell = canEdit && m.can_edit
				? '<input class="af-sp-input af-sp-user-plan" data-user-id="' + m.user_id + '" type="number" min="0" step="1000" value="' + m.plan + '">'
				: fmt(m.plan);
			var pct = Math.min(100, Number(m.percent) || 0);
			var barCls = m.at_risk ? 'af-sp-progress__bar--risk' : '';
			var risk = m.at_risk ? '<span class="af-sp-badge">в риске</span>' : '';
			return '<tr>' +
				'<td>' + escapeHtml(m.name) + risk + '</td>' +
				'<td>' + planCell + '</td>' +
				'<td><a class="af-sp-link" href="' + dealsUrl(m.user_id) + '" target="_blank">' + fmt(m.actual) + '</a></td>' +
				'<td>' + m.deals_count + '</td>' +
				'<td>' + fmt(m.avg_check) + '</td>' +
				'<td>' + m.percent + '%<div class="af-sp-progress"><div class="af-sp-progress__bar ' + barCls + '" style="width:' + pct + '%"></div></div></td>' +
				'<td>' + fmt(m.remaining) + '</td>' +
				'<td>' + fmt(m.forecast) + '</td>' +
				'</tr>';
		}).join('');

		if (canEdit) {
			document.querySelectorAll('.af-sp-user-plan').forEach(function (inp) {
				inp.addEventListener('input', function () {
					state.dirty = true;
				});
			});
		}

		var branchPlanInput = $('af-sp-branch-plan');
		if (canEdit && !branchPlanInput) {
			var firstCard = $('af-sp-summary');
			if (firstCard) {
				var wrap = document.createElement('div');
				wrap.className = 'af-sp-card';
				wrap.innerHTML = '<div class="af-sp-card__label">Редактировать общий план</div><input id="af-sp-branch-plan" class="af-sp-input" type="number" min="0" step="1000" value="' + d.branch.plan + '">';
				firstCard.prepend(wrap);
				$('af-sp-branch-plan').addEventListener('input', function () { state.dirty = true; });
			}
		} else if (branchPlanInput && canEdit) {
			branchPlanInput.value = d.branch.plan;
		}
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
		});
	}

	function load() {
		setLoading(true);
		post('getDashboard').then(function (res) {
			setLoading(false);
			if (!res.ok) throw new Error(res.error || 'load failed');
			state.data = res.data;
			state.dirty = false;
			renderCategoryOptions(res.data.category_options);
			renderSummary(res.data);
			renderTable(res.data);
			if (cfg.isAdmin) loadAudit();
		}).catch(function (e) {
			setLoading(false);
			showAlert(e.message || 'Ошибка загрузки', true);
		});
	}

	function loadAudit() {
		post('getAudit').then(function (res) {
			if (!res.ok || !res.data || !res.data.items) return;
			var box = $('af-sp-audit');
			if (!box) return;
			box.style.display = 'block';
			box.innerHTML = '<h3>Аудит изменений</h3><ul>' + res.data.items.map(function (it) {
				return '<li>' + escapeHtml(it.CHANGED_AT) + ' — ' + escapeHtml(it.FIELD_NAME) + ': ' + escapeHtml(it.OLD_VALUE) + ' → ' + escapeHtml(it.NEW_VALUE) + '</li>';
			}).join('') + '</ul>';
		}).catch(function () {});
	}

	function save() {
		var userPlans = {};
		document.querySelectorAll('.af-sp-user-plan').forEach(function (inp) {
			userPlans[inp.getAttribute('data-user-id')] = inp.value;
		});
		var extra = { user_plans: JSON.stringify(userPlans) };
		var bp = $('af-sp-branch-plan');
		if (bp) extra.branch_plan = bp.value;
		setLoading(true);
		post('savePlans', extra).then(function (res) {
			setLoading(false);
			if (!res.ok) throw new Error(res.error || 'save failed');
			state.data = res.data;
			state.dirty = false;
			renderSummary(res.data);
			renderTable(res.data);
			showAlert('Сохранено');
		}).catch(function (e) {
			setLoading(false);
			showAlert(e.message || 'Ошибка сохранения', true);
		});
	}

	function importSaleTarget() {
		if (!confirm('Импортировать персональные планы из штатного SaleTarget?')) return;
		post('importSaleTarget').then(function (res) {
			if (!res.ok) throw new Error(res.error || 'import failed');
			showAlert('Импортировано: ' + (res.data.imported || 0));
			load();
		}).catch(function (e) {
			showAlert(e.message || 'Ошибка импорта', true);
		});
	}

	function bind() {
		var refresh = $('af-sp-refresh');
		if (refresh) refresh.addEventListener('click', load);
		var saveBtn = $('af-sp-save');
		if (saveBtn) saveBtn.addEventListener('click', save);
		var imp = $('af-sp-import');
		if (imp) imp.addEventListener('click', importSaleTarget);
		var period = $('af-sp-period');
		if (period) period.addEventListener('change', load);
		var branch = $('af-sp-branch');
		if (branch && branch.tagName === 'SELECT') branch.addEventListener('change', load);
		var cat = $('af-sp-category');
		if (cat) cat.addEventListener('change', load);
	}

	document.addEventListener('DOMContentLoaded', function () {
		bind();
		load();
	});
})();
