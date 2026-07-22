export function AccessDenied({ reason }) {
  return (
    <div className="centered card">
      <h2>Нет доступа</h2>
      <p className="muted">{reason || 'Ваш отдел не привязан к ролям этой воронки.'}</p>
      <p className="muted">Администратор настраивает отделы в панели управления приложения.</p>
    </div>
  );
}
