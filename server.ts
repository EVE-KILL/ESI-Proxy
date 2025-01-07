import express, { Request, Response } from 'express';
import fetch from 'node-fetch';

const app = express();
const port = process.env.PORT || 3005;
const host = process.env.HOST || '127.0.0.1';

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

    const responseBuffer = Buffer.from(await response.arrayBuffer());
    res.send(responseBuffer);
});

app.listen(port, host, () => {
    console.log(`Server started at http://${host}:${port}`);
});
