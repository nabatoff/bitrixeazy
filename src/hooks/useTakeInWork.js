import { useCallback, useEffect, useState } from 'react';
import { getDeal, getUser, formatUserName, updateDeal } from '../bitrix/dealApi.js';

function normalizeLockId(value) {
  if (value == null || value === '' || value === '0' || value === false) return null;
  if (Array.isArray(value)) return value[0] ? String(value[0]) : null;
  return String(value);
}

export function useTakeInWork({ dealId, lockField, currentUserId, enabled }) {
  const [lockUserId, setLockUserId] = useState(null);
  const [lockUserName, setLockUserName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const refresh = useCallback(async () => {
    if (!enabled || !dealId || !lockField) {
      setLockUserId(null);
      setLockUserName('');
      return null;
    }
    try {
      const deal = await getDeal(dealId);
      const id = normalizeLockId(deal[lockField]);
      setLockUserId(id);
      if (id) {
        const user = await getUser(id);
        setLockUserName(formatUserName(user));
      } else {
        setLockUserName('');
      }
      return id;
    } catch (err) {
      setError(err.message || String(err));
      return null;
    }
  }, [dealId, lockField, enabled]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  const isMine = lockUserId && String(lockUserId) === String(currentUserId);
  const isFree = !lockUserId;
  const isBlocked = Boolean(lockUserId && !isMine);

  const take = useCallback(async () => {
    if (!lockField) return false;
    setBusy(true);
    setError(null);
    try {
      const fresh = await getDeal(dealId);
      const current = normalizeLockId(fresh[lockField]);
      if (current && String(current) !== String(currentUserId)) {
        const user = await getUser(current);
        setLockUserId(current);
        setLockUserName(formatUserName(user));
        setError(`Сделка в работе у ${formatUserName(user)}`);
        return false;
      }
      await updateDeal(dealId, { [lockField]: currentUserId });
      await refresh();
      return true;
    } catch (err) {
      setError(err.message || String(err));
      return false;
    } finally {
      setBusy(false);
    }
  }, [dealId, lockField, currentUserId, refresh]);

  const release = useCallback(async () => {
    if (!lockField) return false;
    setBusy(true);
    setError(null);
    try {
      await updateDeal(dealId, { [lockField]: '' });
      await refresh();
      return true;
    } catch (err) {
      setError(err.message || String(err));
      return false;
    } finally {
      setBusy(false);
    }
  }, [dealId, lockField, refresh]);

  return {
    lockUserId,
    lockUserName,
    isMine,
    isFree,
    isBlocked,
    canEdit: isFree || isMine,
    busy,
    error,
    take,
    release,
    refresh,
  };
}
