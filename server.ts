import express, { Request, Response } from 'express';
import fetch from 'node-fetch';

const app = express();
const port = process.env.PORT || 3006;
const host = process.env.HOST || '0.0.0.0';

app.use((req: Request, res: Response, next) => {
    const start = Date.now();
    res.on('finish', () => {
        const duration = Date.now() - start;
        const logMessage = `${req.ip} - - [${new Date().toISOString()}] "${req.method} ${req.originalUrl} HTTP/${req.httpVersion}" ${res.statusCode} ${res.get('Content-Length') || 0} "${req.headers['referer'] || '-'}" "${req.headers['user-agent'] || '-'}" ${duration}ms`;
        console.log(logMessage);
    });
    next();
});

app.use(express.raw({ type: '*/*' }));

app.get('/health', (req: Request, res: Response) => {
    res.send('OK');
});

app.all('*', async (req: Request, res: Response) => {
    const targetUrl = `https://esi.evetech.net${req.url}`;
    const headers: Record<string, string> = {};
    for (const [key, value] of Object.entries(req.headers)) {
        if (key.toLowerCase() === 'host') continue;
        headers[key] = Array.isArray(value) ? value.join(';') : value || '';
    }

    const requestInit: RequestInit = {
        method: req.method,
        headers,
        body: ['GET', 'HEAD'].includes(req.method) ? undefined : req.body,
    };

    const response = await fetch(targetUrl, requestInit);
    res.status(response.status);
    response.headers.forEach((value, key) => {
        res.setHeader(key, value);
    });
    res.setHeader('X-Powered-By', 'ESI-PROXY');

    response.body.pipe(res);
});

app.listen(port, host, () => {
    console.log(`Server started at http://${host}:${port}`);
});
