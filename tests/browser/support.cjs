'use strict';

const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { once } = require('node:events');

// Serve synthetic fixtures and static assets only. Never execute PHP or expose config/storage.
async function startFixtureServer() {
  const root = path.resolve(__dirname, '../..');
  const server = http.createServer((request, response) => {
    const relative = decodeURIComponent(new URL(request.url, 'http://localhost').pathname).slice(1);
    const file = path.resolve(root, relative);
    const allowed = file.startsWith(path.join(root, 'assets') + path.sep)
      || file.startsWith(path.join(root, 'tests/browser/fixtures') + path.sep);
    if (!allowed || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      response.writeHead(404); response.end('Not found'); return;
    }
    const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' };
    response.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(response);
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  return {
    baseURL: `http://127.0.0.1:${server.address().port}`,
    close: () => new Promise(resolve => server.close(resolve)),
  };
}

module.exports = { startFixtureServer, fixturePath: '/tests/browser/fixtures/floating-scroll.html' };
