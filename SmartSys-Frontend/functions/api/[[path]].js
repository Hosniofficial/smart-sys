// Cloudflare Pages Function - API Proxy
// This proxies /api/* requests to the backend on Render
// to make them same-origin and fix cookie issues

export async function onRequest(context) {
  const { request, env } = context;
  
  // Get the original path after /api/
  const url = new URL(request.url);
  const apiPath = url.pathname.replace(/^\/api/, '');
  
  // Backend URL from environment or default
  const backendUrl = env.BACKEND_URL || 'https://smartsys-api.onrender.com';
  const targetUrl = `${backendUrl}${apiPath}${url.search}`;
  
  // Clone the request with the new URL
  const modifiedRequest = new Request(targetUrl, {
    method: request.method,
    headers: request.headers,
    body: request.body,
    redirect: 'manual',
  });
  
  // Forward the request to backend
  try {
    const response = await fetch(modifiedRequest);
    
    // Clone response and modify headers if needed
    const modifiedResponse = new Response(response.body, response);
    
    // Add CORS headers for good measure
    modifiedResponse.headers.set('Access-Control-Allow-Credentials', 'true');
    
    return modifiedResponse;
  } catch (error) {
    return new Response(
      JSON.stringify({ 
        status: 'error', 
        message: 'Backend unreachable',
        error: error.message 
      }), 
      { 
        status: 503,
        headers: { 'Content-Type': 'application/json' }
      }
    );
  }
}
