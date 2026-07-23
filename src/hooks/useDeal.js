import { useCallback, useEffect, useState } from 'react';
import { getDeal, getDealClient, updateDeal } from '../bitrix/dealApi.js';

export function useDeal(dealId) {
  const [deal, setDeal] = useState(null);
  const [client, setClient] = useState(null);
  const [loading, setLoading] = useState(Boolean(dealId));
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);

  const reload = useCallback(async (opts = {}) => {
    const quiet = Boolean(opts.quiet);
    if (!dealId) {
      setDeal(null);
      setClient(null);
      setLoading(false);
      setError(null);
      return null;
    }
    if (!quiet) setLoading(true);
    setError(null);
    try {
      const data = await getDeal(dealId);
      let clientData = null;
      try {
        clientData = await getDealClient(data);
      } catch (clientErr) {
        console.warn('getDealClient:', clientErr.message || clientErr);
        clientData = { fullName: '', phone: '', companyTitle: '' };
      }
      setDeal(data);
      setClient(clientData);
      return data;
    } catch (err) {
      setError(err.message || String(err));
      if (!quiet) setDeal(null);
      return null;
    } finally {
      if (!quiet) setLoading(false);
    }
  }, [dealId]);

  useEffect(() => {
    reload();
  }, [reload]);

  const softReload = useCallback(() => reload({ quiet: true }), [reload]);

  const saveFields = useCallback(
    async (fields) => {
      setSaving(true);
      setError(null);
      try {
        await updateDeal(dealId, fields);
        await reload({ quiet: true });
        return true;
      } catch (err) {
        setError(err.message || String(err));
        return false;
      } finally {
        setSaving(false);
      }
    },
    [dealId, reload]
  );

  return { deal, client, setClient, loading, error, saving, reload, softReload, saveFields };
}
