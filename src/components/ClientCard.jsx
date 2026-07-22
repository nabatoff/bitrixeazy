export function ClientCard({ client }) {
  return (
    <div className="field-row client-card">
      <div className="field-label">Клиент</div>
      <div className="field-control">
        <p className="client-line">
          <strong>{client?.fullName || '—'}</strong>
        </p>
        <p className="client-line muted">Телефон: {client?.phone || '—'}</p>
        <p className="client-line muted">Компания: {client?.companyTitle || '—'}</p>
      </div>
    </div>
  );
}
