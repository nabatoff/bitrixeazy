/**
 * Bitrix opens the app iframe with POST (auth form fields).
 * Static index.html on Vercel answers POST with 405 — rewrite to HTML 200.
 */
export const config = {
  matcher: ['/', '/index.html'],
};

export default async function middleware(request) {
  if (request.method !== 'POST') {
    return; // continue to static / rewrite
  }

  const indexUrl = new URL('/index.html', request.url);
  const upstream = await fetch(indexUrl, {
    method: 'GET',
    headers: { Accept: 'text/html' },
  });
  const html = await upstream.text();

  return new Response(html, {
    status: 200,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      'Cache-Control': 'no-store',
      'Content-Security-Policy':
        "frame-ancestors https://crm.artflowers.kz https://*.bitrix24.ru https://*.bitrix24.com https://*.bitrix24.kz 'self'",
    },
  });
}
