export function TakeInWorkBanner({ lock, onTake, onRelease, canRelease }) {
  if (!lock) return null;

  if (lock.isBlocked) {
    return (
      <div className="banner banner-block">
        Сделка в работе у <strong>{lock.lockUserName || `ID ${lock.lockUserId}`}</strong>.
        Редактирование заблокировано.
      </div>
    );
  }

  if (lock.isMine) {
    return (
      <div className="banner banner-ok">
        Сделка в работе у вас.
        {canRelease && (
          <div className="actions">
            <button type="button" className="btn btn-danger" disabled={lock.busy} onClick={onRelease}>
              Освободить
            </button>
          </div>
        )}
      </div>
    );
  }

  return (
    <div className="banner banner-warn">
      Сделка свободна. Возьмите в работу, чтобы редактировать.
      <div className="actions">
        <button type="button" className="btn btn-primary" disabled={lock.busy} onClick={onTake}>
          Взять в работу
        </button>
      </div>
      {lock.error && <p className="muted">{lock.error}</p>}
    </div>
  );
}
