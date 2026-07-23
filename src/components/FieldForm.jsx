import { FIELD_META } from '../config/defaultConfig.js';

function parseMoney(value) {
  if (value == null || value === '') return { amount: '', currency: 'KZT' };
  if (typeof value === 'object' && value !== null) {
    return {
      amount: value.amount ?? value.VALUE ?? '',
      currency: value.currency ?? value.CURRENCY ?? 'KZT',
    };
  }
  const str = String(value);
  if (str.includes('|')) {
    const [amount, currency] = str.split('|');
    return { amount: amount || '', currency: currency || 'KZT' };
  }
  return { amount: str, currency: 'KZT' };
}

function serializeMoney(amount, currency = 'KZT') {
  if (amount === '' || amount == null) return '';
  return `${amount}|${currency || 'KZT'}`;
}

function formatMoneyDisplay(value) {
  const { amount } = parseMoney(value);
  if (amount === '' || amount == null) return '—';
  const n = Number(amount);
  if (!Number.isFinite(n)) return `${amount} ₸`;
  return `${n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ₸`;
}

function formatDateDisplay(value) {
  if (!value) return '—';
  const s = String(value).slice(0, 10);
  const [y, m, d] = s.split('-');
  if (y && m && d) return `${d}.${m}.${y}`;
  return s;
}

function enumLabel(meta, value) {
  if (value == null || value === '') return '—';
  const id = String(Array.isArray(value) ? value[0] : value);
  const item = (meta.items || []).find((x) => String(x.ID) === id);
  return item?.VALUE || id;
}

function isTruthyBool(v) {
  return v === true || v === 'Y' || v === '1' || v === 1;
}

function BoolField({ label, value, disabled, onChange }) {
  const checked = isTruthyBool(value);
  return (
    <label className={`bool-field${disabled ? ' is-disabled' : ''}`}>
      <input
        type="checkbox"
        className="bool-checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked ? 'Y' : 'N')}
      />
      <span className="bool-text">{label}</span>
    </label>
  );
}

function EnumField({ meta, value, disabled, onChange }) {
  const items = meta.items || [];
  const current = value == null ? '' : String(Array.isArray(value) ? value[0] : value);
  return (
    <select value={current} disabled={disabled} onChange={(e) => onChange(e.target.value || '')}>
      <option value="">—</option>
      {items.map((item) => (
        <option key={item.ID} value={String(item.ID)}>
          {item.VALUE}
        </option>
      ))}
    </select>
  );
}

function MoneyField({ value, disabled, onChange }) {
  const { amount } = parseMoney(value);
  return (
    <input
      type="number"
      step="0.01"
      value={amount}
      disabled={disabled}
      onChange={(e) => onChange(serializeMoney(e.target.value, 'KZT'))}
    />
  );
}

function FileField({ value, disabled, onUpload }) {
  const files = !value ? [] : Array.isArray(value) ? value : [value];
  return (
    <div className="file-field">
      {files.length === 0 ? (
        <span className="muted">Нет файла</span>
      ) : (
        <ul className="file-list">
          {files.map((f, i) => {
            const id = typeof f === 'object' ? f.id || f.ID : f;
            const name = typeof f === 'object' ? f.name || f.showUrl || id : id;
            const url = typeof f === 'object' ? f.downloadUrl || f.showUrl : null;
            return (
              <li key={i}>
                {url ? (
                  <a href={url} target="_blank" rel="noreferrer">
                    {name}
                  </a>
                ) : (
                  <span>{String(name)}</span>
                )}
              </li>
            );
          })}
        </ul>
      )}
      {!disabled && typeof onUpload === 'function' && (
        <label className="file-upload">
          <span className="btn btn-secondary">Загрузить файл</span>
          <input
            type="file"
            hidden
            onChange={(e) => {
              const file = e.target.files?.[0];
              e.target.value = '';
              if (file) onUpload(file);
            }}
          />
        </label>
      )}
    </div>
  );
}

function SidebarValue({ code, values, client, assignedName }) {
  const meta = FIELD_META[code] || { type: 'string', title: code };
  if (code === 'CLIENT') {
    return (
      <div className="detail-item">
        <span className="detail-label">Клиент</span>
        <span className="detail-value">{client?.fullName || '—'}</span>
        {client?.phone ? <span className="detail-sub">{client.phone}</span> : null}
        {client?.companyTitle ? <span className="detail-sub">{client.companyTitle}</span> : null}
      </div>
    );
  }
  if (code === 'CURRENCY_ID') return null;

  const value = values[code];
  let display = '—';
  switch (meta.type) {
    case 'boolean':
      display = isTruthyBool(value) ? 'Да' : 'Нет';
      break;
    case 'enumeration':
      display = enumLabel(meta, value);
      break;
    case 'money':
      display = formatMoneyDisplay(value);
      break;
    case 'date':
      display = formatDateDisplay(value);
      break;
    case 'double':
      display = value === '' || value == null ? '—' : formatMoneyDisplay(value);
      break;
    case 'user':
      display = assignedName || (value ? `ID ${value}` : '—');
      break;
    case 'file':
      display = value ? 'Файл прикреплён' : 'Нет файла';
      break;
    default:
      display = value === '' || value == null ? '—' : String(value);
  }

  return (
    <div className="detail-item">
      <span className="detail-label">{meta.title || code}</span>
      <span className="detail-value">{display}</span>
    </div>
  );
}

function WorkControl({
  code,
  mode,
  values,
  onChange,
  locked,
  assignedName,
  onUploadFile,
}) {
  const meta = FIELD_META[code] || { type: 'string', title: code };
  const disabled = mode === 'view' || locked;
  const title = meta.title || code;
  const value = values[code];

  if (meta.type === 'boolean') {
    return (
      <BoolField
        label={title}
        value={value}
        disabled={disabled}
        onChange={(v) => onChange(code, v)}
      />
    );
  }

  let control;
  switch (meta.type) {
    case 'enumeration':
      control = (
        <EnumField meta={meta} value={value} disabled={disabled} onChange={(v) => onChange(code, v)} />
      );
      break;
    case 'money':
      control = <MoneyField value={value} disabled={disabled} onChange={(v) => onChange(code, v)} />;
      break;
    case 'date':
      control = (
        <input
          type="date"
          value={value ? String(value).slice(0, 10) : ''}
          disabled={disabled}
          onChange={(e) => onChange(code, e.target.value)}
        />
      );
      break;
    case 'double':
    case 'integer':
      control = (
        <input
          type="number"
          value={value ?? ''}
          disabled={disabled}
          onChange={(e) => onChange(code, e.target.value)}
        />
      );
      break;
    case 'file':
      control = (
        <FileField
          value={value}
          disabled={disabled}
          onUpload={onUploadFile ? (file) => onUploadFile(code, file) : undefined}
        />
      );
      break;
    case 'user':
      control = (
        <div className="user-field">
          <div className="user-name">{assignedName || (value ? `ID ${value}` : '—')}</div>
          {mode === 'edit' && !locked && (
            <input
              type="text"
              value={value ?? ''}
              placeholder="ID"
              onChange={(e) => onChange(code, e.target.value)}
            />
          )}
        </div>
      );
      break;
    default:
      if (code === 'UF_CRM_1783487251339') {
        control = (
          <textarea
            value={value ?? ''}
            disabled={disabled}
            rows={3}
            onChange={(e) => onChange(code, e.target.value)}
          />
        );
      } else {
        control = (
          <input
            type="text"
            value={value ?? ''}
            disabled={disabled}
            placeholder="Введите значение"
            onChange={(e) => onChange(code, e.target.value)}
          />
        );
      }
  }

  return (
    <div className="work-field" data-field={code}>
      <label className="work-label">{title}</label>
      <div className="work-control">{control}</div>
    </div>
  );
}

const WORK_TITLES = {
  accountant: 'Бухгалтерия',
  purchaser: 'Закуп',
  manager: 'Работа менеджера',
  director: 'Решение руководителя',
  storekeeper: 'Выдача',
};

/**
 * Two-pane form matching the accountant mockup.
 */
export function FieldForm({
  fieldDefs,
  values,
  onChange,
  client,
  locked,
  errors,
  onSave,
  saving,
  assignedName,
  showSave = true,
  onUploadFile,
  role,
  emptyMessage,
}) {
  const detailFields = (fieldDefs || []).filter(
    (f) => f.mode === 'view' && f.code !== 'CURRENCY_ID'
  );
  const workFields = (fieldDefs || []).filter((f) => f.mode === 'edit');
  const boolFields = workFields.filter((f) => (FIELD_META[f.code]?.type || '') === 'boolean');
  const otherWork = workFields.filter((f) => (FIELD_META[f.code]?.type || '') !== 'boolean');

  return (
    <div className="screen-body">
      <aside className="screen-sidebar">
        <h2 className="sidebar-title">Детали сделки</h2>
        <div className="detail-list">
          {detailFields.length === 0 ? (
            <p className="muted">Нет данных</p>
          ) : (
            detailFields.map((f) => (
              <SidebarValue
                key={f.code}
                code={f.code}
                values={values}
                client={client}
                assignedName={assignedName}
              />
            ))
          )}
        </div>
      </aside>

      <div className="screen-work">
        <h2 className="work-title">{WORK_TITLES[role] || 'Работа'}</h2>

        {errors?.length > 0 && (
          <div className="errors">
            <strong>Проверьте данные</strong>
            <ul>
              {errors.map((e) => (
                <li key={e}>{e}</li>
              ))}
            </ul>
          </div>
        )}

        {emptyMessage && <div className="banner banner-warn">{emptyMessage}</div>}

        <div className="work-grid">
          {otherWork.map((f) => (
            <div
              key={f.code}
              className={
                f.code === 'UF_CRM_1783487251339' || f.code === 'UF_CRM_1725623607217'
                  ? 'work-span-2'
                  : ''
              }
            >
              <WorkControl
                code={f.code}
                mode={f.mode}
                values={values}
                onChange={onChange}
                locked={locked}
                assignedName={assignedName}
                onUploadFile={onUploadFile}
              />
            </div>
          ))}

          {boolFields.length > 0 && (
            <div className="work-span-2 bool-panel">
              <div className="bool-stack">
                {boolFields.map((f) => (
                  <WorkControl
                    key={f.code}
                    code={f.code}
                    mode={f.mode}
                    values={values}
                    onChange={onChange}
                    locked={locked}
                    assignedName={assignedName}
                    onUploadFile={onUploadFile}
                  />
                ))}
              </div>
            </div>
          )}
        </div>

        {showSave && workFields.length > 0 && (
          <div className="work-footer">
            <button
              type="button"
              className="btn btn-primary btn-save"
              disabled={saving || locked}
              onClick={onSave}
            >
              {saving ? 'Сохранение…' : 'Сохранить'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
