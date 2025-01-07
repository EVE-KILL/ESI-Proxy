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

      // Handle redirects to keep them on our proxy
      if (upstreamResp.status >= 300 && upstreamResp.status < 400) {
        const location = upstreamResp.headers.get('location');
        if (location) {
          // If location is absolute URL to ESI, rewrite it to use our host
          const locationUrl = new URL(location, 'https://esi.evetech.net');
          if (locationUrl.hostname === 'esi.evetech.net') {
            const proxyUrl = new URL(request.url);
            locationUrl.protocol = proxyUrl.protocol;
            locationUrl.host = proxyUrl.host;
          }
          respHeaders.set('location', locationUrl.toString());
        }
      }

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

      // Special handling for UI content
      if (url.pathname.startsWith('/ui/')) {
        const contentType = upstreamResp.headers.get('content-type') || '';

        if (contentType.includes('text/html') || contentType.includes('application/javascript') || contentType.includes('text/css')) {
          // Get the content and replace any absolute ESI URLs with our proxy URL
          const text = await upstreamResp.text();
          const proxyHost = new URL(request.url).host;
          const modified = text.replace(/https:\/\/esi\.evetech\.net/g, `https://${proxyHost}`);

          return new Response(modified, {
            status: upstreamResp.status,
            headers: respHeaders
          });
        }
      }

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
