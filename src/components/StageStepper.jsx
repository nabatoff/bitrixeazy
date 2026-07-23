import { useEffect, useMemo, useState } from 'react';
import { loadFunnelStages } from '../bitrix/stages.js';
import { canMoveToStage } from '../validation/stageGuards.js';

export function StageStepper({
  deal,
  categoryId,
  canEdit,
  onMoveStage,
  moving,
}) {
  const [stages, setStages] = useState([]);
  const [loadError, setLoadError] = useState(null);
  const [shake, setShake] = useState(false);
  const [flashOk, setFlashOk] = useState(false);
  const [moveErrors, setMoveErrors] = useState([]);

  const currentId = deal?.STAGE_ID || '';

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const list = await loadFunnelStages(categoryId);
        if (!cancelled) setStages(list);
      } catch (err) {
        if (!cancelled) setLoadError(err.message || String(err));
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [categoryId]);

  const currentIndex = useMemo(
    () => stages.findIndex((s) => s.STATUS_ID === currentId),
    [stages, currentId]
  );

  const nextStage = currentIndex >= 0 && currentIndex < stages.length - 1
    ? stages[currentIndex + 1]
    : null;

  const tryMove = async (target) => {
    if (!canEdit || !target || moving) return;
    const check = canMoveToStage(deal, currentId, target.STATUS_ID);
    if (!check.ok) {
      setMoveErrors(check.errors);
      setShake(true);
      setTimeout(() => setShake(false), 500);
      return;
    }
    setMoveErrors([]);
    const ok = await onMoveStage(target.STATUS_ID);
    if (ok) {
      setFlashOk(true);
      setTimeout(() => setFlashOk(false), 450);
    }
  };

  if (loadError) {
    return <p className="muted">Не удалось загрузить этапы: {loadError}</p>;
  }
  if (!stages.length) {
    return <p className="muted">Загрузка этапов воронки…</p>;
  }

  return (
    <div className={`stage-stepper ${shake ? 'stage-shake' : ''} ${flashOk ? 'stage-flash' : ''}`}>
      <div className="stage-stepper-head">
        <div>
          <div className="stage-label">Этап воронки</div>
          <div className="stage-current">
            {stages[currentIndex]?.NAME || currentId || '—'}
          </div>
        </div>
        {canEdit && nextStage && (
          <button
            type="button"
            className="btn btn-primary"
            disabled={moving}
            onClick={() => tryMove(nextStage)}
          >
            {moving ? 'Переход…' : `Далее: ${nextStage.NAME}`}
          </button>
        )}
      </div>

      <div className="stage-track">
        {stages.map((s, i) => {
          const done = currentIndex >= 0 && i < currentIndex;
          const active = s.STATUS_ID === currentId;
          const clickable = canEdit && i === currentIndex + 1;
          return (
            <button
              key={s.STATUS_ID}
              type="button"
              className={`stage-dot ${done ? 'is-done' : ''} ${active ? 'is-active' : ''}`}
              disabled={!clickable || moving}
              title={s.NAME}
              onClick={() => clickable && tryMove(s)}
            >
              <span className="stage-dot-index">{i + 1}</span>
              <span className="stage-dot-name">{s.NAME}</span>
            </button>
          );
        })}
      </div>

      {moveErrors.length > 0 && (
        <div className="stage-move-error">
          <strong>Перейти нельзя</strong>
          <ul>
            {moveErrors.map((e) => (
              <li key={`${e.code}-${e.message}`}>{e.message}</li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
