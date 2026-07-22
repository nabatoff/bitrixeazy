import { FIELD_META } from '../config/defaultConfig.js';
import { ClientCard } from './ClientCard.jsx';

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

function serializeMoney(amount, currency) {
  if (amount === '' || amount == null) return '';
  return `${amount}|${currency || 'KZT'}`;
}

function BoolField({ value, disabled, onChange }) {
  const checked = value === true || value === 'Y' || value === '1' || value === 1;
  return (
    <label>
      <input
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked ? 'Y' : 'N')}
      />{' '}
      Да
    </label>
  );
}

function EnumField({ meta, value, disabled, onChange }) {
  const items = meta.items || [];
  const current = value == null ? '' : String(Array.isArray(value) ? value[0] : value);
  return (
    <select
      value={current}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value || '')}
    >
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
  const { amount, currency } = parseMoney(value);
  return (
    <div className="money-row">
      <input
        type="number"
        step="0.01"
        value={amount}
        disabled={disabled}
        onChange={(e) => onChange(serializeMoney(e.target.value, currency))}
      />
      <input
        type="text"
        value={currency}
        disabled={disabled}
        onChange={(e) => onChange(serializeMoney(amount, e.target.value))}
      />
    </div>
  );
}

function FileField({ value }) {
  if (!value) return <span className="muted">Нет файла</span>;
  const files = Array.isArray(value) ? value : [value];
  return (
    <ul style={{ margin: 0, paddingLeft: 18 }}>
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
  );
}

function SingleField({ code, mode, values, onChange, client, onClientChange, locked }) {
  const meta = FIELD_META[code] || { type: 'string', title: code };
  const disabled = mode === 'view' || locked;

  if (code === 'CLIENT') {
    return <ClientCard client={client} mode={locked ? 'view' : mode} onChange={onClientChange} />;
  }

  // Pair opportunity+currency visually — still separate codes in config
  if (code === 'CURRENCY_ID' && values.__skipCurrency) return null;

  const title = meta.title || code;
  const value = values[code];

  let control;
  switch (meta.type) {
    case 'boolean':
      control = <BoolField value={value} disabled={disabled} onChange={(v) => onChange(code, v)} />;
      break;
    case 'enumeration':
      control = <EnumField meta={meta} value={value} disabled={disabled} onChange={(v) => onChange(code, v)} />;
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
      control = <FileField value={value} />;
      break;
    case 'user':
      control = (
        <input
          type="text"
          value={value ?? ''}
          disabled={disabled}
          placeholder="ID пользователя"
          onChange={(e) => onChange(code, e.target.value)}
        />
      );
      break;
    case 'crm_currency':
      control = (
        <select value={value || 'KZT'} disabled={disabled} onChange={(e) => onChange(code, e.target.value)}>
          {['KZT', 'USD', 'EUR', 'RUB'].map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
      );
      break;
    default:
      control = (
        <textarea
          value={value ?? ''}
          disabled={disabled}
          rows={code === 'UF_CRM_1783487251339' ? 3 : 1}
          onChange={(e) => onChange(code, e.target.value)}
        />
      );
  }

  return (
    <div className="field-row">
      <div className="field-label">{title}</div>
      <div className="field-control">{control}</div>
    </div>
  );
}

export function FieldForm({
  fieldDefs,
  values,
  onChange,
  client,
  onClientChange,
  locked,
  errors,
  onSave,
  saving,
}) {
  return (
    <div className="card">
      <h2>Поля сделки</h2>
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
      {fieldDefs.map((f) => (
        <SingleField
          key={f.code}
          code={f.code}
          mode={f.mode}
          values={values}
          onChange={onChange}
          client={client}
          onClientChange={onClientChange}
          locked={locked && f.mode === 'edit'}
        />
      ))}
      <div className="actions">
        <button type="button" className="btn btn-primary" disabled={saving || locked} onClick={onSave}>
          {saving ? 'Сохранение…' : 'Сохранить'}
        </button>
      </div>
    </div>
  );
}
