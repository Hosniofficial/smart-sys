import assert from 'node:assert/strict';
import test from 'node:test';
import { onRequest } from '../functions/api/[[path]].js';

test('API proxy forwards the /api/v1 prefix and query string to the backend', async () => {
  const previousFetch = globalThis.fetch;
  let forwardedUrl;
  globalThis.fetch = async (request) => {
    forwardedUrl = request.url;
    return new Response('ok');
  };

  try {
    const response = await onRequest({
      request: new Request('https://app.example.test/api/v1/auth/refresh?source=reload'),
      env: { BACKEND_URL: 'https://api.example.test/' }
    });

    assert.equal(response.status, 200);
    assert.equal(
      forwardedUrl,
      'https://api.example.test/api/v1/auth/refresh?source=reload'
    );
  } finally {
    globalThis.fetch = previousFetch;
  }
});
