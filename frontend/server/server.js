const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = parseInt(process.env.FRONTEND_PORT || '3000', 10);
const PUBLIC_DIR = path.join(__dirname, '..', 'public');

const MIME_TYPES = {
    '.html': 'text/html',
    '.css': 'text/css',
    '.js': 'application/javascript',
    '.json': 'application/json',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.svg': 'image/svg+xml',
    '.ico': 'image/x-icon',
};

const server = http.createServer((req, res) => {
    let urlPath = req.url.split('?')[0];

    if (urlPath === '/' || urlPath === '') {
        urlPath = '/index.html';
    }

    const filePath = path.join(PUBLIC_DIR, urlPath);

    fs.readFile(filePath, (err, content) => {
        if (err) {
            if (err.code === 'ENOENT') {
                fs.readFile(path.join(PUBLIC_DIR, 'index.html'), (err2, content2) => {
                    if (err2) {
                        res.writeHead(500);
                        res.end('500 Internal Server Error');
                        return;
                    }
                    res.writeHead(200, { 'Content-Type': 'text/html' });
                    res.end(content2, 'utf-8');
                });
            } else {
                res.writeHead(500);
                res.end('500 Internal Server Error');
            }
            return;
        }

        const ext = path.extname(filePath);
        const contentType = MIME_TYPES[ext] || 'application/octet-stream';

        res.writeHead(200, {
            'Content-Type': contentType,
            'Cache-Control': 'no-store, no-cache, must-revalidate, proxy-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0',
        });
        res.end(content, 'utf-8');
    });
});

server.listen(PORT, () => {
    console.log('============================================');
    console.log('  Emergency Messaging System - Frontend');
    console.log('============================================');
    console.log('  Server:     http://localhost:' + PORT);
    console.log('  Public dir: ' + PUBLIC_DIR);
    console.log('============================================');
});
