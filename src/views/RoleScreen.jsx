import { useEffect, useMemo, useState } from 'react';
import { FieldForm } from '../components/FieldForm.jsx';
import { TakeInWorkBanner } from '../components/TakeInWorkBanner.jsx';
import { useTakeInWork } from '../hooks/useTakeInWork.js';
import { validateEitherOr } from '../validation/eitherOr.js';

function pickValues(deal, fieldDefs) {
  const values = {};
  for (const f of fieldDefs) {
    if (f.code === 'CLIENT') continue;
    values[f.code] = deal?.[f.code] ?? '';
  }
  return values;
}

export function RoleScreen({
  role,
  title,
  deal,
  dealId,
  client,
  setClient,
  fieldDefs,
  lockField,
  currentUserId,
  useLock,
  isAppAdmin,
  saveFields,
  saving,
}) {
  const [values, setValues] = useState(() => pickValues(deal, fieldDefs));
  const [pendingClient, setPendingClient] = useState({});
  const [errors, setErrors] = useState([]);

  const lock = useTakeInWork({
    dealId,
    lockField,
    currentUserId,
    enabled: Boolean(useLock && lockField),
  });

  useEffect(() => {
    setValues(pickValues(deal, fieldDefs));
    setPendingClient({});
  }, [deal, fieldDefs]);

  const locked = useLock ? !lock.canEdit : false;

  const onChange = (code, value) => {
    setValues((prev) => ({ ...prev, [code]: value }));
  };

  const onClientChange = (patch) => {
    setPendingClient((prev) => ({ ...prev, ...patch }));
    setClient((prev) => ({
      ...prev,
      ...(patch.CONTACT_ID
        ? {
            contactId: String(patch.CONTACT_ID),
            fullName: patch.contactPreview?.fullName || prev?.fullName,
            phone: patch.contactPreview?.phone ?? prev?.phone,
          }
        : {}),
      ...(patch.COMPANY_ID
        ? {
            companyId: String(patch.COMPANY_ID),
            companyTitle: patch.companyPreview?.companyTitle || prev?.companyTitle,
          }
        : {}),
    }));
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
      if (code === 'CLIENT') continue;
      fields[code] = values[code];
    }
    if (editableCodes.has('CLIENT')) {
      if (pendingClient.CONTACT_ID) fields.CONTACT_ID = pendingClient.CONTACT_ID;
      if (pendingClient.COMPANY_ID) fields.COMPANY_ID = pendingClient.COMPANY_ID;
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
        onClientChange={onClientChange}
        locked={locked}
        errors={errors}
        onSave={onSave}
        saving={saving}
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
