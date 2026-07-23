import { useEffect, useMemo, useState } from 'react';
import { formatUserName, getUser, uploadDealFile } from '../bitrix/dealApi.js';
import { fitWindow } from '../bitrix/bx24.js';
import { FieldForm } from '../components/FieldForm.jsx';
import { StageStepper } from '../components/StageStepper.jsx';
import { WhoIsWorking } from '../components/WhoIsWorking.jsx';
import { filterVisibleFields } from '../deal/visibleFields.js';
import { useTakeInWork } from '../hooks/useTakeInWork.js';
import { validateEitherOr } from '../validation/eitherOr.js';

function pickValues(deal, fieldDefs) {
  const values = {};
  for (const f of fieldDefs) {
    if (f.code === 'CLIENT' || f.code === 'CURRENCY_ID') continue;
    values[f.code] = deal?.[f.code] ?? '';
  }
  return values;
}

function useFitFrame(deps) {
  useEffect(() => {
    const root = document.querySelector('.app');
    const run = () => {
      try {
        fitWindow();
      } catch {
        /* ignore */
      }
    };
    run();
    if (!root || typeof ResizeObserver === 'undefined') {
      const t = setTimeout(run, 300);
      return () => clearTimeout(t);
    }
    const ro = new ResizeObserver(() => run());
    ro.observe(root);
    const t = setTimeout(run, 100);
    return () => {
      ro.disconnect();
      clearTimeout(t);
    };
  }, deps);
}

export function RoleScreen({
  role,
  title,
  deal,
  dealId,
  client,
  fieldDefs,
  lockField,
  currentUserId,
  useLock,
  isAppAdmin,
  saveFields,
  saving,
  reload,
  softReload,
  funnel,
  showStepper = false,
  onMoveStage,
  movingStage = false,
}) {
  const lock = useTakeInWork({
    dealId,
    lockField,
    currentUserId,
    enabled: Boolean(useLock && lockField),
    extraOnTake:
      role === 'purchaser'
        ? { UF_CRM_1783485774093: '908' } // Взят в работу закупщиком = Да
        : null,
    onChanged: typeof softReload === 'function' ? softReload : typeof reload === 'function' ? reload : null,
  });

  const [values, setValues] = useState(() => pickValues(deal, fieldDefs || []));
  const [errors, setErrors] = useState([]);
  const [assignedName, setAssignedName] = useState('');
  const [uploadingFile, setUploadingFile] = useState(false);

  const dealState = useMemo(() => ({ ...deal, ...values }), [deal, values]);

  const visibility = useMemo(
    () =>
      filterVisibleFields(role, fieldDefs, dealState, {
        lockIsMine: Boolean(lock.isMine),
      }),
    [role, fieldDefs, dealState, lock.isMine]
  );

  const visibleDefs = visibility.fields;

  useEffect(() => {
    setValues(pickValues(deal, fieldDefs || []));
  }, [deal, fieldDefs]);

  useEffect(() => {
    let cancelled = false;
    const id = values.ASSIGNED_BY_ID || deal?.ASSIGNED_BY_ID;
    if (!id) {
      setAssignedName('');
      return undefined;
    }
    (async () => {
      try {
        const user = await getUser(id);
        if (!cancelled) setAssignedName(formatUserName(user));
      } catch {
        if (!cancelled) setAssignedName(`ID ${id}`);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [values.ASSIGNED_BY_ID, deal?.ASSIGNED_BY_ID]);

  useFitFrame([
    deal,
    values,
    errors,
    lock.isBlocked,
    lock.isMine,
    lock.isFree,
    role,
    visibility.emptyMessage,
  ]);

  const locked = useLock ? !lock.canEdit : false;
  const hideEdits =
    locked || (visibility.hideFormUntilLock && !lock.isMine);

  const onChange = (code, value) => {
    setValues((prev) => ({ ...prev, [code]: value }));
  };

  const editableCodes = useMemo(
    () => new Set(visibleDefs.filter((f) => f.mode === 'edit').map((f) => f.code)),
    [visibleDefs]
  );

  const onUploadFile = async (code, file) => {
    if (!file || !dealId) return;
    setUploadingFile(true);
    setErrors([]);
    try {
      await uploadDealFile(dealId, code, file);
      if (typeof reload === 'function') await reload();
    } catch (err) {
      setErrors([err.message || String(err)]);
    } finally {
      setUploadingFile(false);
    }
  };

  const onSave = async () => {
    const validationValues = { ...deal, ...values };
    const errs = validateEitherOr(validationValues, role);
    setErrors(errs);
    if (errs.length) return;

    const fields = {};
    for (const code of editableCodes) {
      if (code === 'CLIENT' || code === 'CURRENCY_ID') continue;
      fields[code] = values[code];
    }

    await saveFields(fields);
  };

  const showSave =
    visibleDefs.some((f) => f.mode === 'edit') &&
    !(visibility.hideFormUntilLock && !lock.isMine);

  const formDefs =
    visibility.hideFormUntilLock && !lock.isMine
      ? visibleDefs.filter((f) => f.mode === 'view')
      : visibleDefs;

  return (
    <div className="screen-wrap">
      <WhoIsWorking deal={deal} lockFields={funnel?.lockFields} />

      {showStepper && (
        <StageStepper
          deal={deal}
          categoryId={deal?.CATEGORY_ID}
          canEdit={role === 'manager'}
          onMoveStage={onMoveStage}
          moving={movingStage}
        />
      )}

      <div className="screen-card">
        <div className="screen-header">
          <h1 className="screen-title">{title}</h1>
          <div className="screen-header-actions">
            <span className="badge">{role}</span>
            {useLock && lock.isFree && (
              <button
                type="button"
                className="btn btn-primary"
                disabled={lock.busy}
                onClick={lock.take}
              >
                {lock.busy ? '…' : 'Взять в работу'}
              </button>
            )}
            {useLock && lock.isMine && (lock.isMine || isAppAdmin) && (
              <button
                type="button"
                className="btn btn-danger"
                disabled={lock.busy}
                onClick={lock.release}
              >
                Освободить
              </button>
            )}
          </div>
        </div>

        {useLock && lock.isBlocked && (
          <div className="screen-lock-banner banner-block">
            Сделка в работе у <strong>{lock.lockUserName || `ID ${lock.lockUserId}`}</strong>.
            Редактирование заблокировано.
          </div>
        )}

        {useLock && lock.isFree && (
          <div className="screen-lock-banner banner-warn">
            Сделка свободна. Возьмите в работу, чтобы редактировать.
            {lock.error ? <div className="muted">{lock.error}</div> : null}
          </div>
        )}

        {visibility.hideFormUntilLock && !lock.isMine && !lock.isBlocked && (
          <div className="screen-lock-banner banner-warn">
            После «Взять в работу» откроются поля закупа.
          </div>
        )}

        <FieldForm
          role={role}
          fieldDefs={formDefs}
          values={values}
          onChange={onChange}
          client={client}
          locked={hideEdits}
          errors={errors}
          onSave={onSave}
          saving={saving || uploadingFile}
          assignedName={assignedName}
          showSave={showSave}
          onUploadFile={onUploadFile}
          emptyMessage={visibility.emptyMessage}
        />
      </div>
    </div>
  );
}

export function AccountantView(props) {
  return (
    <RoleScreen
      {...props}
      role="accountant"
      title="Экран бухгалтера"
      useLock
      lockField={props.funnel?.lockFields?.accountant}
      fieldDefs={props.funnel?.fields?.accountant || []}
    />
  );
}

export function PurchaserView(props) {
  return (
    <RoleScreen
      {...props}
      role="purchaser"
      title="Экран закупщика"
      useLock
      lockField={props.funnel?.lockFields?.purchaser}
      fieldDefs={props.funnel?.fields?.purchaser || []}
    />
  );
}

export function ManagerView(props) {
  return (
    <RoleScreen
      {...props}
      role="manager"
      title="Экран менеджера"
      useLock={false}
      showStepper
      fieldDefs={props.funnel?.fields?.manager || []}
    />
  );
}

export function DirectorView(props) {
  return (
    <RoleScreen
      {...props}
      role="director"
      title="Экран руководителя"
      useLock={false}
      fieldDefs={props.funnel?.fields?.director || []}
    />
  );
}

export function StorekeeperView(props) {
  return (
    <RoleScreen
      {...props}
      role="storekeeper"
      title="Экран кладовщика"
      useLock={false}
      fieldDefs={props.funnel?.fields?.storekeeper || []}
    />
  );
}
