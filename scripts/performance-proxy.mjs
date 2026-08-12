import http from 'node:http';

const listenPort = Number(process.env.PERFORMANCE_PROXY_PORT || 8020);
const upstreams = (process.env.PERFORMANCE_UPSTREAMS || 'http://127.0.0.1:8021,http://127.0.0.1:8022,http://127.0.0.1:8023,http://127.0.0.1:8024')
  .split(',')
  .map((value) => new URL(value.trim()));
let cursor = 0;

const server = http.createServer((request, response) => {
  const upstream = upstreams[cursor++ % upstreams.length];
  const proxy = http.request({
    hostname: upstream.hostname,
    port: upstream.port,
    path: request.url,
    method: request.method,
    headers: { ...request.headers, host: request.headers.host },
  }, (proxied) => {
    response.writeHead(proxied.statusCode || 502, proxied.headers);
    proxied.pipe(response);
  });
  proxy.on('error', (error) => {
    if (!response.headersSent) response.writeHead(502, { 'content-type': 'application/json' });
    response.end(JSON.stringify({ message: 'Performance upstream unavailable', detail: error.message }));
  });
  request.pipe(proxy);
});

server.listen(listenPort, '127.0.0.1', () => {
  process.stdout.write(`Performance proxy listening on http://127.0.0.1:${listenPort}\n`);
});
