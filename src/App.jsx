import { useCallback, useMemo, useState } from 'react';
import { AccessDenied } from './components/AccessDenied.jsx';
import { useAppConfig } from './hooks/useAppConfig.js';
import { useBx24Init } from './hooks/useBx24Init.js';
import { useDeal } from './hooks/useDeal.js';
import { resolveRole } from './roles/resolveRole.js';
import { AdminDashboard } from './views/AdminDashboard.jsx';
import {
  AccountantView,
  DirectorView,
  ManagerView,
  PurchaserView,
  StorekeeperView,
} from './views/RoleScreen.jsx';

export default function App() {
  const bx = useBx24Init();
  const { config, loading: configLoading, error: configError, saving: configSaving, saveConfig } =
    useAppConfig(bx.ready);
  const {
    deal,
    client,
    setClient,
    loading: dealLoading,
    error: dealError,
    saving,
    saveFields,
    reload,
    softReload,
  } = useDeal(bx.dealId);

  const [showAdmin, setShowAdmin] = useState(false);
  const [movingStage, setMovingStage] = useState(false);

  const resolved = useMemo(() => {
    if (!config || !deal || !bx.user) return null;
    return resolveRole({
      categoryId: deal.CATEGORY_ID,
      userDepartments: bx.departments,
      config,
      userId: bx.user.ID,
      adminFlag: bx.adminFlag,
    });
  }, [config, deal, bx.user, bx.departments, bx.adminFlag]);

  const onMoveStage = useCallback(
    async (stageId) => {
      setMovingStage(true);
      try {
        const ok = await saveFields({ STAGE_ID: stageId });
        return ok;
      } finally {
        setMovingStage(false);
      }
    },
    [saveFields]
  );

  if (bx.error) {
    return (
      <div className="app">
        <div className="errors">Ошибка BX24: {bx.error}</div>
        <p className="muted">Приложение должно открываться внутри iframe Битрикс24.</p>
      </div>
    );
  }

  if (!bx.ready || configLoading || (bx.dealId && dealLoading) || !config) {
    const parts = [];
    if (!bx.ready) parts.push(bx.step || 'BX24');
    else if (configLoading) parts.push('config');
    else if (bx.dealId && dealLoading) parts.push(`deal #${bx.dealId}`);
    else if (!config) parts.push('config merge');
    return (
      <div className="loading">
        Загрузка… <span className="muted">({parts.join(' → ') || '…'})</span>
      </div>
    );
  }

  if (!bx.dealId) {
    return (
      <div className="app">
        <div className="card">
          <h2>Приложение открыто вне вкладки сделки</h2>
          <p className="muted">
            Это нормально из меню / при установке. Чтобы вкладка появилась в сделке — админ → «Привязать
            вкладку в сделку».
          </p>
          <p className="muted">
            Auth: {bx.auth?.access_token ? 'есть token' : 'нет token'} · user: {bx.user?.ID || '—'} ·
            placement: {bx.placementCode || '—'}
          </p>
          <p className="muted">
            Нет вкладки в сделке? В правах приложения нужен скоуп <code>placement</code> (не только
            CRM/user), потом «Привязать вкладку».
          </p>
          {bx.adminFlag && (
            <button type="button" className="btn btn-secondary" onClick={() => setShowAdmin(true)}>
              Открыть админ-панель
            </button>
          )}
        </div>
        {showAdmin && (
          <AdminDashboard
            config={config}
            onSave={saveConfig}
            saving={configSaving}
            onClose={() => setShowAdmin(false)}
          />
        )}
      </div>
    );
  }

  if (!deal) {
    return (
      <div className="app">
        <div className="errors">Не удалось загрузить сделку #{bx.dealId}: {dealError || 'нет данных'}</div>
        {bx.adminFlag && (
          <button type="button" className="btn btn-secondary" onClick={() => setShowAdmin(true)}>
            ⚙ Админ
          </button>
        )}
        {showAdmin && (
          <AdminDashboard
            config={config}
            onSave={saveConfig}
            saving={configSaving}
            onClose={() => setShowAdmin(false)}
          />
        )}
      </div>
    );
  }

  const viewProps = {
    deal,
    dealId: bx.dealId,
    client,
    setClient,
    funnel: resolved?.funnel,
    currentUserId: bx.user?.ID,
    isAppAdmin: resolved?.isAppAdmin,
    saveFields,
    saving,
    reload,
    softReload,
    onMoveStage,
    movingStage,
  };

  let body = null;
  if (!resolved?.role) {
    body = <AccessDenied reason={resolved?.reason} />;
  } else if (resolved.role === 'director') {
    body = <DirectorView {...viewProps} />;
  } else if (resolved.role === 'accountant') {
    body = <AccountantView {...viewProps} />;
  } else if (resolved.role === 'purchaser') {
    body = <PurchaserView {...viewProps} />;
  } else if (resolved.role === 'storekeeper') {
    body = <StorekeeperView {...viewProps} />;
  } else {
    body = <ManagerView {...viewProps} />;
  }

  return (
    <div className="app">
      <div className="app-topbar">
        <span className="muted" style={{ fontSize: 13 }}>
          Сделка #{deal.ID}
          {resolved?.funnel?.name ? ` · ${resolved.funnel.name}` : ''}
        </span>
        {resolved?.isAppAdmin && (
          <button type="button" className="btn btn-secondary" onClick={() => setShowAdmin((v) => !v)}>
            {showAdmin ? 'Скрыть админ' : 'Админ'}
          </button>
        )}
      </div>

      {(dealError || configError) && (
        <div className="errors">{dealError || configError}</div>
      )}

      {showAdmin && resolved?.isAppAdmin && (
        <AdminDashboard
          config={config}
          onSave={saveConfig}
          saving={configSaving}
          onClose={() => setShowAdmin(false)}
        />
      )}

      {body}
    </div>
  );
}
