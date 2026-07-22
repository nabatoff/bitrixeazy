import { useCallback, useEffect, useState } from 'react';
import { appOptionGet, appOptionSet } from '../bitrix/bx24.js';
import { CONFIG_KEY, mergeConfig } from '../config/defaultConfig.js';

export function useAppConfig(enabled = true) {
  const [config, setConfig] = useState(null);
  const [loading, setLoading] = useState(Boolean(enabled));
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);

  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const raw = await appOptionGet(CONFIG_KEY);
      let parsed = null;
      if (raw && typeof raw === 'string') {
        try {
          parsed = JSON.parse(raw);
        } catch {
          parsed = null;
        }
      } else if (raw && typeof raw === 'object') {
        parsed = raw;
      }
      setConfig(mergeConfig(parsed));
    } catch (err) {
      setError(err.message || String(err));
      setConfig(mergeConfig(null));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!enabled) return undefined;
    reload();
    return undefined;
  }, [enabled, reload]);

  const saveConfig = useCallback(async (next) => {
    setSaving(true);
    setError(null);
    try {
      const merged = mergeConfig(next);
      await appOptionSet(CONFIG_KEY, JSON.stringify(merged));
      setConfig(merged);
      return true;
    } catch (err) {
      setError(err.message || String(err));
      return false;
    } finally {
      setSaving(false);
    }
  }, []);

  return { config, loading, error, saving, reload, saveConfig };
}
