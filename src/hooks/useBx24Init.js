import { useEffect, useState } from 'react';
import {
  fitWindow,
  getAuth,
  getPlacementInfo,
  initBx24,
  installFinish,
  isAdmin,
  isInstallMode,
  ensureDealTabPlacement,
  resolveDealId,
} from '../bitrix/bx24.js';
import { collectUserDepartments, getCurrentUser } from '../bitrix/dealApi.js';

function placementCode(info) {
  if (!info) return '';
  return String(info.placement || info.Placement || '');
}

export function useBx24Init() {
  const [state, setState] = useState({
    ready: false,
    error: null,
    dealId: null,
    user: null,
    departments: [],
    adminFlag: false,
    auth: null,
    step: 'init',
  });

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        setState((s) => ({ ...s, step: 'BX24.init' }));
        await initBx24();

        const placement = getPlacementInfo();
        const code = placementCode(placement);
        const inDealTab = code === 'CRM_DEAL_DETAIL_TAB';
        const dealId = resolveDealId(placement);

        if (isInstallMode() && !inDealTab) {
          setState((s) => ({ ...s, step: 'placement.bind' }));
          try {
            await ensureDealTabPlacement(`${window.location.origin}/api/frame`);
          } catch (bindErr) {
            console.warn('placement.bind (install):', bindErr.message || bindErr);
          }
          installFinish();
        }

        setState((s) => ({ ...s, step: 'user.current' }));
        let user = null;
        let departments = [];
        try {
          user = await getCurrentUser();
          departments = collectUserDepartments(user);
        } catch (userErr) {
          console.warn('user.current:', userErr.message || userErr);
        }

        const adminFlag = isAdmin();
        const auth = getAuth();

        if (cancelled) return;
        setState({
          ready: true,
          error: null,
          dealId,
          user,
          departments,
          adminFlag,
          auth,
          step: 'ready',
          placementCode: code,
        });
        fitWindow();
      } catch (err) {
        if (cancelled) return;
        setState((s) => ({
          ...s,
          ready: false,
          error: err.message || String(err),
          step: 'error',
        }));
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  return state;
}
