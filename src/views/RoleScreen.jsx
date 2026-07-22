import { useEffect, useMemo, useState } from 'react';
import { formatUserName, getUser } from '../bitrix/dealApi.js';
import { fitWindow } from '../bitrix/bx24.js';
import { FieldForm } from '../components/FieldForm.jsx';
import { TakeInWorkBanner } from '../components/TakeInWorkBanner.jsx';
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
}) {
  const [values, setValues] = useState(() => pickValues(deal, fieldDefs));
  const [errors, setErrors] = useState([]);
  const [assignedName, setAssignedName] = useState('');

  const lock = useTakeInWork({
    dealId,
    lockField,
    currentUserId,
    enabled: Boolean(useLock && lockField),
  });

  useEffect(() => {
    setValues(pickValues(deal, fieldDefs));
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

  useFitFrame([deal, values, errors, lock.isBlocked, lock.isMine, lock.isFree, role]);

  const locked = useLock ? !lock.canEdit : false;

  const onChange = (code, value) => {
    setValues((prev) => ({ ...prev, [code]: value }));
  };

  const editableCodes = useMemo(
    () => new Set(fieldDefs.filter((f) => f.mode === 'edit').map((f) => f.code)),
    [fieldDefs]
  );

  const onSave = async () => {
    const validationValues = { ...values };
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

  return (
    <div>
      <div className="app-header">
        <h1>{title}</h1>
        <span className="badge">{role}</span>
      </div>

      {useLock && (
        <TakeInWorkBanner
          lock={lock}
          onTake={lock.take}
          onRelease={lock.release}
          canRelease={lock.isMine || isAppAdmin}
        />
      )}

      <FieldForm
        fieldDefs={fieldDefs}
        values={values}
        onChange={onChange}
        client={client}
        locked={locked}
        errors={errors}
        onSave={onSave}
        saving={saving}
        assignedName={assignedName}
      />
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
      fieldDefs={props.funnel?.fields?.manager || []}
    />
  );
}
