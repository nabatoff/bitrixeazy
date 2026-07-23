import { bx24Call } from './bx24.js';

export async function getDeal(dealId) {
  return bx24Call('crm.deal.get', { id: dealId });
}

export async function updateDeal(dealId, fields) {
  return bx24Call('crm.deal.update', { id: dealId, fields });
}

/** Read File → base64 payload for Bitrix UF file field */
export function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = String(reader.result || '');
      const base64 = result.includes(',') ? result.split(',')[1] : result;
      resolve(base64);
    };
    reader.onerror = () => reject(new Error('Не удалось прочитать файл'));
    reader.readAsDataURL(file);
  });
}

/**
 * Upload file into CRM deal UF file field (fileData format).
 * @returns updated field value from reloaded deal (caller should reload)
 */
export async function uploadDealFile(dealId, fieldCode, file) {
  const base64 = await fileToBase64(file);
  await updateDeal(dealId, {
    [fieldCode]: {
      fileData: [file.name || 'file.bin', base64],
    },
  });
  return true;
}

export async function getUser(userId) {
  if (!userId) return null;
  const list = await bx24Call('user.get', { ID: userId });
  return Array.isArray(list) ? list[0] : list;
}

export async function getCurrentUser() {
  return bx24Call('user.current');
}

export function formatUserName(user) {
  if (!user) return 'Неизвестный';
  const parts = [user.LAST_NAME, user.NAME].filter(Boolean);
  if (parts.length) return parts.join(' ');
  return user.EMAIL || `ID ${user.ID}`;
}

function pickPhone(contact) {
  const phone = contact?.PHONE;
  if (!phone) return '';
  if (typeof phone === 'string') return phone;
  if (Array.isArray(phone) && phone.length) {
    return phone[0].VALUE || phone[0].value || '';
  }
  return '';
}

export async function getDealClient(deal) {
  const contactId =
    deal?.CONTACT_ID && String(deal.CONTACT_ID) !== '0'
      ? deal.CONTACT_ID
      : Array.isArray(deal?.CONTACT_IDS) && deal.CONTACT_IDS.length
        ? deal.CONTACT_IDS[0]
        : null;

  const companyId =
    deal?.COMPANY_ID && String(deal.COMPANY_ID) !== '0' ? deal.COMPANY_ID : null;

  const [contact, company] = await Promise.all([
    contactId
      ? bx24Call('crm.contact.get', { id: contactId }).catch(() => null)
      : Promise.resolve(null),
    companyId
      ? bx24Call('crm.company.get', { id: companyId }).catch(() => null)
      : Promise.resolve(null),
  ]);

  return {
    contactId: contactId ? String(contactId) : '',
    companyId: companyId ? String(companyId) : '',
    fullName: contact
      ? [contact.LAST_NAME, contact.NAME, contact.SECOND_NAME].filter(Boolean).join(' ')
      : '',
    phone: pickPhone(contact),
    companyTitle: company?.TITLE || '',
    contact,
    company,
  };
}

export async function searchContacts(query) {
  const q = String(query || '').trim();
  if (q.length < 2) return [];
  const result = await bx24Call('crm.contact.list', {
    filter: { '%FULL_NAME': q },
    select: ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PHONE'],
    order: { LAST_NAME: 'ASC' },
    start: 0,
  });
  return Array.isArray(result) ? result : [];
}

export async function searchCompanies(query) {
  const q = String(query || '').trim();
  if (q.length < 2) return [];
  const result = await bx24Call('crm.company.list', {
    filter: { '%TITLE': q },
    select: ['ID', 'TITLE'],
    order: { TITLE: 'ASC' },
    start: 0,
  });
  return Array.isArray(result) ? result : [];
}

export function collectUserDepartments(user) {
  const raw = user?.UF_DEPARTMENT;
  if (!raw) return [];
  const arr = Array.isArray(raw) ? raw : [raw];
  return arr.map((id) => Number(id)).filter((n) => Number.isFinite(n) && n > 0);
}
