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

function serializeMoney(amount, currency = 'KZT') {
  if (amount === '' || amount == null) return '';
  return `${amount}|${currency || 'KZT'}`;
}

function BoolField({ value, disabled, onChange }) {
  const checked = value === true || value === 'Y' || value === '1' || value === 1;
  return (
    <label className="bool-field">
      <input
        type="checkbox"
        className="bool-checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked ? 'Y' : 'N')}
      />
      <span className="bool-text">Да</span>
    </label>
  );
}

function EnumField({ meta, value, disabled, onChange, narrow }) {
  const items = meta.items || [];
  const current = value == null ? '' : String(Array.isArray(value) ? value[0] : value);
  return (
    <select
      className={narrow ? 'input-narrow' : undefined}
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
  const { amount } = parseMoney(value);
  return (
    <input
      className="input-narrow"
      type="number"
      step="0.01"
      value={amount}
      disabled={disabled}
      onChange={(e) => onChange(serializeMoney(e.target.value, 'KZT'))}
    />
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

function SingleField({
  code,
  mode,
  values,
  onChange,
  client,
  locked,
  assignedName,
}) {
  const meta = FIELD_META[code] || { type: 'string', title: code };
  const disabled = mode === 'view' || locked;
  const narrow = Boolean(meta.narrow);

  if (code === 'CLIENT') {
    return <ClientCard client={client} />;
  }

  if (code === 'CURRENCY_ID') return null;

  const title = meta.title || code;
  const value = values[code];

  let control;
  switch (meta.type) {
    case 'boolean':
      control = <BoolField value={value} disabled={disabled} onChange={(v) => onChange(code, v)} />;
      break;
    case 'enumeration':
      control = (
        <EnumField
          meta={meta}
          value={value}
          disabled={disabled}
          narrow={narrow}
          onChange={(v) => onChange(code, v)}
        />
      );
      break;
    case 'money':
      control = <MoneyField value={value} disabled={disabled} onChange={(v) => onChange(code, v)} />;
      break;
    case 'date':
      control = (
        <input
          className={narrow ? 'input-narrow' : undefined}
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
          className={narrow ? 'input-narrow' : undefined}
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
        <div className="user-field">
          <div className="user-name">{assignedName || (value ? `ID ${value}` : '—')}</div>
          {mode === 'edit' && !locked && (
            <input
              className="input-narrow"
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
      if (narrow || code === 'UF_CRM_1750940585581' || code === 'UF_CRM_1784532842739') {
        control = (
          <input
            className="input-narrow"
            type="text"
            value={value ?? ''}
            disabled={disabled}
            onChange={(e) => onChange(code, e.target.value)}
          />
        );
      } else {
        control = (
          <textarea
            value={value ?? ''}
            disabled={disabled}
            rows={code === 'UF_CRM_1783487251339' ? 3 : 2}
            onChange={(e) => onChange(code, e.target.value)}
          />
        );
      }
  }

  return (
    <div className="field-row">
      <div className="field-label">{title}</div>
      <div className="field-control">{control}</div>
    </div>
  );
}

function groupBySection(fieldDefs) {
  const groups = [];
  let current = null;
  for (const f of fieldDefs) {
    const section = f.section || 'Поля';
    if (!current || current.title !== section) {
      current = { title: section, fields: [] };
      groups.push(current);
    }
    current.fields.push(f);
  }
  return groups;
}

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
}) {
  const groups = groupBySection(fieldDefs);

  return (
    <div className="form-stack">
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

      {groups.map((g, index) => (
        <section
          className={`card${index === 0 ? ' card-span-full' : ''}`}
          key={g.title}
        >
          <h2>{g.title}</h2>
          {g.fields.map((f) => (
            <SingleField
              key={f.code}
              code={f.code}
              mode={f.mode}
              values={values}
              onChange={onChange}
              client={client}
              locked={locked && f.mode === 'edit'}
              assignedName={assignedName}
            />
          ))}
        </section>
      ))}

      <div className="actions sticky-actions">
        <button type="button" className="btn btn-primary" disabled={saving || locked} onClick={onSave}>
          {saving ? 'Сохранение…' : 'Сохранить'}
        </button>
      </div>
    </div>
  );
}
