import express, { Request, Response } from 'express';
import { PassThrough } from 'stream';

const app = express();
const port = parseInt(process.env.PORT || '3006', 10);
const host = process.env.HOST || '0.0.0.0';

// Middleware for logging
app.use((req: Request, res: Response, next) => {
  const start = Date.now();
  res.on('finish', () => {
    const duration = Date.now() - start;
    const logMessage = `${req.ip} - - [${new Date().toISOString()}] "${req.method} ${
      req.originalUrl
    } HTTP/${req.httpVersion}" ${res.statusCode} ${res.get('Content-Length') || 0} "${
      req.headers['referer'] || '-'
    }" "${req.headers['user-agent'] || '-'}" ${duration}ms`;
    console.log(logMessage);
  });
  next();
});

// Use a raw body parser for all content types
app.use(express.raw({ type: () => true }));

app.get('/health', (req: Request, res: Response) => {
  res.send('OK');
});

app.all('*', async (req: Request, res: Response) => {
  try {
    // Build target URL
    const targetUrl = `https://esi.evetech.net${req.url}`;

    // Build fetch headers (copy everything except 'host')
    const requestHeaders: Record<string, string> = {};
    for (const [key, value] of Object.entries(req.headers)) {
      if (key.toLowerCase() === 'host') continue;
      // If a header value is an array, pick the first or join them
      requestHeaders[key] = Array.isArray(value) ? value.join(', ') : (value ?? '');
    }

    const fetchOptions: RequestInit = {
      method: req.method,
      headers: requestHeaders,
      verbose: true,
    };

    // Forward request body if it's not GET/HEAD
    if (!['GET', 'HEAD'].includes(req.method)) {
      fetchOptions.body = req.body;
    }

    // Perform the fetch
    const fetchResponse = await fetch(targetUrl, fetchOptions);

    // Set the status code
    res.status(fetchResponse.status);

    // Copy the response headers
    fetchResponse.headers.forEach((value, key) => {
      res.setHeader(key, value);
    });

    // Stream the response body directly to the client
    if (fetchResponse.body) {
      // In Node >=18 or Bun, fetchResponse.body is a WHATWG ReadableStream
      // so we need to convert it to a Node stream
      const passThrough = new PassThrough();
      const reader = fetchResponse.body.getReader();

      (async () => {
        while (true) {
          const { done, value } = await reader.read();
          if (done) {
            passThrough.end();
            break;
          }
          passThrough.write(Buffer.from(value));
        }
      })();

      passThrough.pipe(res);
    } else {
      // No body (e.g., 204 No Content, or HEAD request)
      res.end();
    }
  } catch (err) {
    console.error(err);
    res.status(500).send('Proxy error occurred');
  }
});

app.listen(port, host, () => {
  console.log(`Server started at http://${host}:${port}`);
});
