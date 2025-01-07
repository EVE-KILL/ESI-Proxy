import express, { Request, Response } from 'express';

const app = express();
const port = parseInt(process.env.PORT || '3006', 10);
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
    const requestInit: RequestInit = {
        method: req.method,
        headers: req.headers as Record<string, string>,
    };

    if (!['GET', 'HEAD'].includes(req.method)) {
        requestInit.body = req.body;
    }

    try {
        const response = await fetch(targetUrl, requestInit);
        res.status(response.status);
        response.headers.forEach((value, key) => {
            res.setHeader(key, value);
        });
        if (response.body) {
            response.body.pipe(res);
        } else {
            res.end();
        }
    } catch (error) {
        res.status(500).send('Proxy error occurred');
    }
});

app.listen(port, host, () => {
    console.log(`Server started at http://${host}:${port}`);
});
