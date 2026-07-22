import { useEffect, useState } from 'react';
import {
  ensureDealTabPlacement,
  fitWindow,
  getAuth,
  getPlacementInfo,
  initBx24,
  installFinish,
  isAdmin,
  isInstallMode,
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

        // First open / install URL: bind deal tab + finish installer
        if (isInstallMode() || isAdmin()) {
          try {
            await ensureDealTabPlacement(`${window.location.origin}/`);
          } catch (bindErr) {
            console.warn('placement.bind:', bindErr.message || bindErr);
          }
          if (isInstallMode()) {
            installFinish();
          }
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
