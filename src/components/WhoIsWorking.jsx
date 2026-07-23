import { useEffect, useState } from 'react';
import { formatUserName, getUser } from '../bitrix/dealApi.js';

function normalizeLockId(value) {
  if (value == null || value === '' || value === '0' || value === false) return null;
  if (Array.isArray(value)) return value[0] ? String(value[0]) : null;
  return String(value);
}

function useLockLabel(deal, fieldCode) {
  const [name, setName] = useState('');
  const id = normalizeLockId(deal?.[fieldCode]);

  useEffect(() => {
    let cancelled = false;
    if (!id) {
      setName('');
      return undefined;
    }
    (async () => {
      try {
        const user = await getUser(id);
        if (!cancelled) setName(formatUserName(user));
      } catch {
        if (!cancelled) setName(`ID ${id}`);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [id]);

  return { id, name };
}

export function WhoIsWorking({ deal, lockFields }) {
  const accountantField = lockFields?.accountant || 'UF_CRM_LOCK_ACCOUNTANT';
  const purchaserField = lockFields?.purchaser || 'UF_CRM_LOCK_PURCHASER';
  const accountant = useLockLabel(deal, accountantField);
  const purchaser = useLockLabel(deal, purchaserField);

  return (
    <div className="who-working">
      <div className="who-working-title">Кто в работе над сделкой</div>
      <div className="who-working-grid">
        <div className={`who-chip ${accountant.id ? 'who-busy' : 'who-free'}`}>
          <span className="who-role">Бухгалтер</span>
          <span className="who-name">{accountant.id ? accountant.name : 'свободно'}</span>
        </div>
        <div className={`who-chip ${purchaser.id ? 'who-busy' : 'who-free'}`}>
          <span className="who-role">Закупщик</span>
          <span className="who-name">{purchaser.id ? purchaser.name : 'свободно'}</span>
        </div>
      </div>
    </div>
  );
}
