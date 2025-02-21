import { serve } from 'bun';

serve({
  port: parseInt(process.env.PORT || '3006', 10),
  async fetch(request: Request, server): Promise<Response> {
    const startTime = Date.now();

    try {
      const url = new URL(request.url);

      // Handle special routes
      if (url.pathname === '/health') {
        return new Response('OK', { status: 200 });
      }

      // Proxy to ESI
      const targetUrl = `https://esi.evetech.net${url.pathname}${url.search}`;

      // Clone and adjust request headers
      const reqHeaders = new Headers(request.headers);
      reqHeaders.delete('host');

      // Ensure Authorization header is explicitly set
      const authHeader = request.headers.get('Authorization');
      if (authHeader) { reqHeaders.set('Authorization', authHeader); }

      // Prepare request init, injecting method/headers/body
      const reqInit: RequestInit = {
        method: request.method,
        headers: reqHeaders,
        redirect: 'manual'
      };
      if (!['GET', 'HEAD'].includes(request.method)) {
        reqInit.body = request.body;
      }

      // Fetch from ESI
      const upstreamResp = await fetch(targetUrl, reqInit);

      // Compute duration
      const duration = Date.now() - startTime;

      // In Bun, server?.remoteAddress contains the IP address
      const ip = server?.remoteAddress || '-';
      const method = request.method;
      const urlPath = request.url;
      const status = upstreamResp.status;
      const contentLength = upstreamResp.headers.get('content-length') || 0;
      const userAgent = request.headers.get('user-agent') || '-';

      // Log in NGINX-like format
      console.log(`${ip} - - [${new Date().toISOString()}] "${method} ${urlPath}" ${status} ${contentLength} "${userAgent}" ${duration}ms`);

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
