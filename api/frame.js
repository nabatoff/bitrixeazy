import fs from 'fs';
import path from 'path';

/**
 * Bitrix iframe loads HANDLER via POST with AUTH_* in body.
 * Static hosting → 405; this function accepts GET/POST and injects auth into HTML.
 */
function readIndexHtml() {
  const candidates = [
    path.join(process.cwd(), 'dist', 'index.html'),
    path.join(process.cwd(), 'index.html'),
    path.join(process.cwd(), 'public', 'index.html'),
  ];
  for (const p of candidates) {
    if (fs.existsSync(p)) return fs.readFileSync(p, 'utf8');
  }
  return `<!DOCTYPE html><html><body><p>index.html not found in serverless bundle</p></body></html>`;
}

function pickAuth(body) {
  if (!body || typeof body !== 'object') return null;
  const AUTH_ID = body.AUTH_ID || body.access_token;
  if (!AUTH_ID) return null;
  return {
    AUTH_ID,
    REFRESH_ID: body.REFRESH_ID || body.refresh_token || '',
    AUTH_EXPIRES: body.AUTH_EXPIRES || body.expires_in || '',
    DOMAIN: body.DOMAIN || body.domain || '',
    member_id: body.member_id || '',
    APP_SID: body.APP_SID || '',
    status: body.status || '',
  };
}

export default function handler(req, res) {
  let html = readIndexHtml();
  const auth = req.method === 'POST' ? pickAuth(req.body) : null;

  if (auth) {
    const inject = `<script>window.__BITRIX_POST_AUTH__=${JSON.stringify(auth)};</script>`;
    if (html.includes('<head>')) {
      html = html.replace('<head>', `<head>${inject}`);
    } else {
      html = inject + html;
    }
  }

  res.statusCode = 200;
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader(
    'Content-Security-Policy',
    "frame-ancestors https://crm.artflowers.kz https://*.bitrix24.ru https://*.bitrix24.com https://*.bitrix24.kz 'self'"
  );
  res.end(html);
}
