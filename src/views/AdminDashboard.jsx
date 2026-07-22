import { useEffect, useState } from 'react';
import { defaultConfig } from '../config/defaultConfig.js';
import { validateConfigShape } from '../config/configSchema.js';

function parseIdList(text) {
  return String(text || '')
    .split(/[,;\s]+/)
    .map((s) => s.trim())
    .filter(Boolean)
    .map((s) => Number(s))
    .filter((n) => Number.isFinite(n) && n > 0);
}

function idsToText(arr) {
  return (arr || []).join(', ');
}

export function AdminDashboard({ config, onSave, saving, onClose }) {
  const [draft, setDraft] = useState(() => structuredClone(config || defaultConfig));
  const [selectedFunnel, setSelectedFunnel] = useState(() => {
    const keys = Object.keys(config?.funnels || { 15: true });
    return keys.includes('15') ? '15' : keys[0];
  });
  const [message, setMessage] = useState('');
  const [errors, setErrors] = useState([]);

  useEffect(() => {
    setDraft(structuredClone(config || defaultConfig));
  }, [config]);

  const funnel = draft.funnels[selectedFunnel] || {
    name: '',
    departments: { manager: [], accountant: [], purchaser: [] },
    lockFields: { accountant: '', purchaser: '' },
    fields: { accountant: [], purchaser: [], manager: [] },
  };

  const updateFunnel = (patch) => {
    setDraft((prev) => ({
      ...prev,
      funnels: {
        ...prev.funnels,
        [selectedFunnel]: {
          ...funnel,
          ...patch,
          departments: {
            ...funnel.departments,
            ...(patch.departments || {}),
          },
          lockFields: {
            ...funnel.lockFields,
            ...(patch.lockFields || {}),
          },
        },
      },
    }));
  };

  const addFunnel = () => {
    const id = window.prompt('CATEGORY_ID новой воронки (число)?');
    if (!id) return;
    const key = String(id).trim();
    if (!key) return;
    setDraft((prev) => ({
      ...prev,
      funnels: {
        ...prev.funnels,
        [key]: {
          name: `Воронка ${key}`,
          departments: { manager: [], accountant: [], purchaser: [] },
          lockFields: {
            accountant: 'UF_CRM_LOCK_ACCOUNTANT',
            purchaser: 'UF_CRM_LOCK_PURCHASER',
          },
          fields: structuredClone(defaultConfig.funnels['15'].fields),
        },
      },
    }));
    setSelectedFunnel(key);
  };

  const handleSave = async () => {
    const errs = validateConfigShape(draft);
    setErrors(errs);
    if (errs.length) return;
    const ok = await onSave(draft);
    setMessage(ok ? 'Конфиг сохранён в appOption' : 'Ошибка сохранения');
  };

  return (
    <div className="card">
      <div className="app-header">
        <h2 style={{ margin: 0 }}>Панель администратора</h2>
        <button type="button" className="btn btn-secondary" onClick={onClose}>
          Закрыть
        </button>
      </div>

      <div className="admin-grid">
        <label>
          Admin user IDs (дополнительно к BX24.isAdmin)
          <input
            type="text"
            value={idsToText(draft.adminUserIds)}
            onChange={(e) =>
              setDraft((prev) => ({ ...prev, adminUserIds: parseIdList(e.target.value) }))
            }
            placeholder="например: 1, 42"
          />
        </label>

        <label>
          Воронка
          <select value={selectedFunnel} onChange={(e) => setSelectedFunnel(e.target.value)}>
            {Object.keys(draft.funnels).map((id) => (
              <option key={id} value={id}>
                {id} — {draft.funnels[id]?.name || 'без имени'}
              </option>
            ))}
          </select>
        </label>

        <div className="actions">
          <button type="button" className="btn btn-secondary" onClick={addFunnel}>
            + Воронка
          </button>
        </div>

        <label>
          Название воронки
          <input
            type="text"
            value={funnel.name || ''}
            onChange={(e) => updateFunnel({ name: e.target.value })}
          />
        </label>

        <label>
          Отделы менеджеров (ID)
          <input
            type="text"
            value={idsToText(funnel.departments?.manager)}
            onChange={(e) =>
              updateFunnel({ departments: { manager: parseIdList(e.target.value) } })
            }
          />
        </label>

        <label>
          Отделы бухгалтеров (ID)
          <input
            type="text"
            value={idsToText(funnel.departments?.accountant)}
            onChange={(e) =>
              updateFunnel({ departments: { accountant: parseIdList(e.target.value) } })
            }
          />
        </label>

        <label>
          Отделы закупщиков (ID)
          <input
            type="text"
            value={idsToText(funnel.departments?.purchaser)}
            onChange={(e) =>
              updateFunnel({ departments: { purchaser: parseIdList(e.target.value) } })
            }
          />
        </label>

        <label>
          Lock-поле бухгалтера
          <input
            type="text"
            value={funnel.lockFields?.accountant || ''}
            onChange={(e) =>
              updateFunnel({ lockFields: { accountant: e.target.value.trim() } })
            }
          />
        </label>

        <label>
          Lock-поле закупщика
          <input
            type="text"
            value={funnel.lockFields?.purchaser || ''}
            onChange={(e) =>
              updateFunnel({ lockFields: { purchaser: e.target.value.trim() } })
            }
          />
        </label>
      </div>

      {errors.length > 0 && (
        <div className="errors" style={{ marginTop: 12 }}>
          <ul>
            {errors.map((e) => (
              <li key={e}>{e}</li>
            ))}
          </ul>
        </div>
      )}

      {message && <p className="muted">{message}</p>}

      <div className="actions">
        <button type="button" className="btn btn-primary" disabled={saving} onClick={handleSave}>
          {saving ? 'Сохранение…' : 'Сохранить конфиг'}
        </button>
      </div>

      <p className="muted" style={{ marginTop: 12 }}>
        Поля ролей для воронки 15 уже зашиты в defaultConfig. Масштаб на 6 воронок — через «+ Воронка»
        и привязку отделов. Конфиг хранится в <code>BX24.appOption</code> (ключ deal_widget_config).
      </p>
    </div>
  );
}
