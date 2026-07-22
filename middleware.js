import { rewrite } from '@vercel/edge';

/** POST to `/` must never hit static index.html (405). */
export const config = {
  matcher: ['/', '/index.html'],
};

export default function middleware(request) {
  if (request.method === 'POST' || request.method === 'OPTIONS') {
    return rewrite(new URL('/api/frame', request.url));
  }
}
