import { serve } from 'bun';

serve({
  port: parseInt(process.env.PORT || '3006', 10),
  async fetch(request: Request): Promise<Response> {
    try {
      const originalUrl = new URL(request.url);
      const targetUrl = 'https://esi.evetech.net' + originalUrl.pathname + originalUrl.search;

      // Clone and adjust request headers
      const reqHeaders = new Headers(request.headers);
      reqHeaders.delete('host');

      // Prepare request init, injecting method/headers/body
      const reqInit: RequestInit = {
        method: request.method,
        headers: reqHeaders,
      };
      if (!['GET', 'HEAD'].includes(request.method)) {
        reqInit.body = request.body;
      }

      // Fetch from ESI
      const upstreamResp = await fetch(targetUrl, reqInit);

      // Clone upstream response headers, removing potentially problematic ones
      const respHeaders = new Headers(upstreamResp.headers);
      respHeaders.delete('content-encoding');
      respHeaders.delete('content-length');
      respHeaders.delete('transfer-encoding');

      // Force keep-alive
      respHeaders.set('Connection', 'keep-alive');

      // Return new Response with the upstream body and cleaned-up headers
      return new Response(upstreamResp.body, {
        status: upstreamResp.status,
        headers: respHeaders
      });
    } catch (err) {
      console.error('Proxy error:', err);
      return new Response('Proxy error occurred', { status: 500 });
    }
  }
});
