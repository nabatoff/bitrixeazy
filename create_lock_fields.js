/**
 * Creates technical "taken in work" employee fields on deals.
 * Requires Node.js >= 18.
 */
const WEBHOOK_BASE = 'https://crm.artflowers.kz/rest/139808/2p2ytngri3eh9jsy/';

const FIELDS = [
  {
    FIELD_NAME: 'LOCK_ACCOUNTANT',
    label: '[Тех] В работе у бухгалтера',
  },
  {
    FIELD_NAME: 'LOCK_PURCHASER',
    label: '[Тех] В работе у закупщика',
  },
];

async function callMethod(method, body = null) {
  const url = new URL(method, WEBHOOK_BASE.endsWith('/') ? WEBHOOK_BASE : `${WEBHOOK_BASE}/`);
  const opts = { method: body ? 'POST' : 'GET' };
  if (body) {
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body = JSON.stringify(body);
  }

  console.log(`→ ${opts.method} ${method}`);
  const res = await fetch(url, opts);
  if (!res.ok) {
    throw new Error(`HTTP ${res.status} ${res.statusText} for ${method}`);
  }

  const data = await res.json();
  if (data.error) {
    throw new Error(`${method}: ${data.error}${data.error_description ? ` — ${data.error_description}` : ''}`);
  }
  return data.result;
}

async function findExistingField(fieldName) {
  const list = await callMethod('crm.deal.userfield.list', {
    filter: { FIELD_NAME: fieldName },
  });
  const items = Array.isArray(list) ? list : [];
  return items.find((f) => f.FIELD_NAME === fieldName || f.FIELD_NAME === `UF_CRM_${fieldName}`) || null;
}

async function ensureLockField({ FIELD_NAME, label }) {
  const existing = await findExistingField(FIELD_NAME);
  if (existing) {
    const code = existing.FIELD_NAME.startsWith('UF_')
      ? existing.FIELD_NAME
      : `UF_CRM_${existing.FIELD_NAME}`;
    console.log(`✓ already exists: ${code} (id=${existing.ID})`);
    return code;
  }

  const id = await callMethod('crm.deal.userfield.add', {
    fields: {
      FIELD_NAME,
      USER_TYPE_ID: 'employee',
      XML_ID: FIELD_NAME,
      SORT: 10000,
      MULTIPLE: 'N',
      MANDATORY: 'N',
      SHOW_FILTER: 'N',
      SHOW_IN_LIST: 'N',
      EDIT_IN_LIST: 'N',
      IS_SEARCHABLE: 'N',
      EDIT_FORM_LABEL: { ru: label, en: label },
      LIST_COLUMN_LABEL: { ru: label, en: label },
      LIST_FILTER_LABEL: { ru: label, en: label },
    },
  });

  const created = await callMethod('crm.deal.userfield.get', { id });
  const code = created?.FIELD_NAME || `UF_CRM_${FIELD_NAME}`;
  console.log(`✓ created: ${code} (id=${id})`);
  return code;
}

async function main() {
  console.log('Creating lock employee fields…');
  const codes = {};
  for (const def of FIELDS) {
    const code = await ensureLockField(def);
    codes[def.FIELD_NAME] = code;
  }

  console.log('\n=== Lock field codes (paste into defaultConfig) ===');
  console.log(JSON.stringify({
    accountant: codes.LOCK_ACCOUNTANT,
    purchaser: codes.LOCK_PURCHASER,
  }, null, 2));
}

main().catch((err) => {
  console.error('Failed:', err.message || err);
  process.exit(1);
});
