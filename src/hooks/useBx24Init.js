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

export function useBx24Init() {
  const [state, setState] = useState({
    ready: false,
    error: null,
    dealId: null,
    user: null,
    departments: [],
    adminFlag: false,
    auth: null,
  });

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        await initBx24();

        // Только мастер установки — не на каждый заход админа
        if (isInstallMode()) {
          try {
            await ensureDealTabPlacement(`${window.location.origin}/`);
          } catch (bindErr) {
            console.warn('placement.bind (install):', bindErr.message || bindErr);
          }
          installFinish();
        }

        const placement = getPlacementInfo();
        const dealId = resolveDealId(placement);
        const user = await getCurrentUser();
        const departments = collectUserDepartments(user);
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
        });
        fitWindow();
      } catch (err) {
        if (cancelled) return;
        setState((s) => ({
          ...s,
          ready: false,
          error: err.message || String(err),
        }));
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  return state;
}
