/**
 * Bitrix24 schema dump: deal categories + deal fields → bitrix_schema.json
 * Requires Node.js >= 18 (native fetch).
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WEBHOOK_BASE = 'https://crm.artflowers.kz/rest/139808/2p2ytngri3eh9jsy/';
const OUT_FILE = path.join(__dirname, 'bitrix_schema.json');

async function callMethod(method, query = {}) {
  const url = new URL(method, WEBHOOK_BASE.endsWith('/') ? WEBHOOK_BASE : `${WEBHOOK_BASE}/`);
  for (const [key, value] of Object.entries(query)) {
    url.searchParams.set(key, String(value));
  }

  console.log(`→ GET ${method}`);
  const res = await fetch(url);
  if (!res.ok) {
    throw new Error(`HTTP ${res.status} ${res.statusText} for ${method}`);
  }

  const data = await res.json();
  if (data.error) {
    const err = new Error(`${method}: ${data.error}${data.error_description ? ` — ${data.error_description}` : ''}`);
    err.bitrixError = data.error;
    throw err;
  }

  return data.result;
}

async function fetchCategories() {
  try {
    const result = await callMethod('crm.dealcategory.list');
    return Array.isArray(result) ? result : result?.categories ?? result;
  } catch (err) {
    if (err.bitrixError === 'ERROR_METHOD_NOT_FOUND' || /not found|METHOD_NOT_FOUND/i.test(String(err.message))) {
      console.warn('crm.dealcategory.list unavailable, fallback → crm.category.list (entityTypeId=2)');
      const result = await callMethod('crm.category.list', { entityTypeId: 2 });
      return result?.categories ?? result;
    }
    throw err;
  }
}

async function main() {
  console.log('Fetching Bitrix24 schema…');

  const categories = await fetchCategories();
  const fields = await callMethod('crm.deal.fields');

  const categoryList = Array.isArray(categories) ? categories : [];
  const fieldKeys = fields && typeof fields === 'object' ? Object.keys(fields) : [];

  const payload = {
    fetchedAt: new Date().toISOString(),
    webhookHost: 'crm.artflowers.kz',
    categories: categoryList,
    fields,
  };

  fs.writeFileSync(OUT_FILE, JSON.stringify(payload, null, 2), 'utf8');

  console.log(`✓ categories: ${categoryList.length}`);
  console.log(`✓ fields: ${fieldKeys.length}`);
  console.log(`✓ saved: ${OUT_FILE}`);
}

main().catch((err) => {
  console.error('Failed:', err.message || err);
  process.exit(1);
});
