import { useEffect, useState } from 'react';
import { formatUserName, searchCompanies, searchContacts } from '../bitrix/dealApi.js';

export function ClientCard({ client, mode, onChange }) {
  const readOnly = mode !== 'edit';
  const [contactQuery, setContactQuery] = useState('');
  const [companyQuery, setCompanyQuery] = useState('');
  const [contactHits, setContactHits] = useState([]);
  const [companyHits, setCompanyHits] = useState([]);
  const [searching, setSearching] = useState(false);

  useEffect(() => {
    if (readOnly || contactQuery.trim().length < 2) {
      setContactHits([]);
      return undefined;
    }
    let cancelled = false;
    const t = setTimeout(async () => {
      setSearching(true);
      try {
        const list = await searchContacts(contactQuery);
        if (!cancelled) setContactHits(list);
      } finally {
        if (!cancelled) setSearching(false);
      }
    }, 350);
    return () => {
      cancelled = true;
      clearTimeout(t);
    };
  }, [contactQuery, readOnly]);

  useEffect(() => {
    if (readOnly || companyQuery.trim().length < 2) {
      setCompanyHits([]);
      return undefined;
    }
    let cancelled = false;
    const t = setTimeout(async () => {
      setSearching(true);
      try {
        const list = await searchCompanies(companyQuery);
        if (!cancelled) setCompanyHits(list);
      } finally {
        if (!cancelled) setSearching(false);
      }
    }, 350);
    return () => {
      cancelled = true;
      clearTimeout(t);
    };
  }, [companyQuery, readOnly]);

  return (
    <div className="field-row client-card">
      <div className="field-label">Клиент</div>
      <div className="field-control">
        <p className="client-line">
          <strong>{client?.fullName || '—'}</strong>
        </p>
        <p className="client-line muted">Телефон: {client?.phone || '—'}</p>
        <p className="client-line muted">Компания: {client?.companyTitle || '—'}</p>

        {!readOnly && (
          <div className="client-search">
            <input
              type="text"
              placeholder="Поиск контакта…"
              value={contactQuery}
              onChange={(e) => setContactQuery(e.target.value)}
            />
            {contactHits.length > 0 && (
              <ul className="client-results">
                {contactHits.map((c) => (
                  <li key={c.ID}>
                    <button
                      type="button"
                      onClick={() => {
                        onChange?.({
                          CONTACT_ID: c.ID,
                          contactPreview: {
                            fullName: [c.LAST_NAME, c.NAME].filter(Boolean).join(' '),
                            phone: Array.isArray(c.PHONE) ? c.PHONE[0]?.VALUE : '',
                          },
                        });
                        setContactQuery('');
                        setContactHits([]);
                      }}
                    >
                      {formatUserName(c)} <span className="muted">#{c.ID}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}

            <input
              type="text"
              placeholder="Поиск компании…"
              value={companyQuery}
              onChange={(e) => setCompanyQuery(e.target.value)}
            />
            {companyHits.length > 0 && (
              <ul className="client-results">
                {companyHits.map((c) => (
                  <li key={c.ID}>
                    <button
                      type="button"
                      onClick={() => {
                        onChange?.({
                          COMPANY_ID: c.ID,
                          companyPreview: { companyTitle: c.TITLE },
                        });
                        setCompanyQuery('');
                        setCompanyHits([]);
                      }}
                    >
                      {c.TITLE} <span className="muted">#{c.ID}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
            {searching && <p className="muted">Поиск…</p>}
          </div>
        )}
      </div>
    </div>
  );
}
