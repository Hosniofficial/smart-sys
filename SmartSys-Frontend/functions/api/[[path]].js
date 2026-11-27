// Cloudflare Pages Function - API proxy.
// Forwards /api/* to the backend so the browser only talks to the Pages origin
// (same-origin requests: no CORS, and the refresh cookie stays first-party).
//
// Environment (Cloudflare Pages -> Settings -> Variables and secrets):
//   BACKEND_URL         required. Backend origin, e.g. https://api.example.com
//   PROXY_SHARED_SECRET optional but recommended. When set, the visitor IP is forwarded to the
//                       backend in X-Forwarded-Client-IP together with X-Proxy-Auth. The backend
//                       trusts that header only when the secret matches its own PROXY_SHARED_SECRET.

const SPOOFABLE_HEADERS = ['x-proxy-auth', 'x-forwarded-client-ip'];

export async function onRequest(context) {
  const { request, env } = context;

  const backendUrl = String(env.BACKEND_URL || '').replace(/\/+$/, '');
  if (!backendUrl) {
    return json({ status: 'error', message: 'Backend is not configured' }, 503);
  }

  // Preserve the backend's /api/v1 route prefix after the Pages function prefix.
  const url = new URL(request.url);
  const apiPath = url.pathname.replace(/^\/api/, '');
  const targetUrl = `${backendUrl}/api${apiPath}${url.search}`;

  // Never let a caller supply the headers the backend uses to trust a visitor IP.
  const headers = new Headers(request.headers);
  for (const name of SPOOFABLE_HEADERS) {
    headers.delete(name);
  }

  const sharedSecret = String(env.PROXY_SHARED_SECRET || '');
  const visitorIp = request.headers.get('CF-Connecting-IP');
  if (sharedSecret && visitorIp) {
    headers.set('X-Proxy-Auth', sharedSecret);
    headers.set('X-Forwarded-Client-IP', visitorIp);
  }

  const modifiedRequest = new Request(targetUrl, {
    method: request.method,
    headers,
    body: request.body,
    redirect: 'manual',
  });

  try {
    const response = await fetch(modifiedRequest);
    return new Response(response.body, response);
  } catch {
    // Do not expose network error details to the browser.
    return json({ status: 'error', message: 'Backend unreachable' }, 503);
  }
}

function json(payload, status) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}
