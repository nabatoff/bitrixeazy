export function DealStatusAlerts({ alerts }) {
  if (!alerts?.length) return null;
  return (
    <div className="status-alerts">
      {alerts.map((a) => (
        <div key={a.id} className={`status-alert status-alert-${a.tone}`}>
          <div className="status-alert-title">{a.title}</div>
          <div className="status-alert-text">{a.text}</div>
        </div>
      ))}
    </div>
  );
}
