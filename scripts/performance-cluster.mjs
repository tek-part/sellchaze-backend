import { execFileSync, spawn } from 'node:child_process';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
function resolvePhpRuntime() {
  if (process.env.PHP_BINARY) {
    return {
      binary: process.env.PHP_BINARY,
      prefix: process.env.PHP_INI ? ['-c', process.env.PHP_INI] : [],
    };
  }
  if (process.platform !== 'win32') return { binary: 'php', prefix: [] };

  const [binary, ini] = execFileSync(
    'powershell.exe',
    ['-NoProfile', '-NonInteractive', '-Command', 'php -r "echo PHP_BINARY, PHP_EOL, php_ini_loaded_file();"'],
    { cwd: root, encoding: 'utf8', windowsHide: true },
  ).trim().split(/\r?\n/);
  if (!binary) throw new Error('Could not resolve the PHP executable from php.cmd');
  return { binary, prefix: ini ? ['-c', ini] : [] };
}

const phpRuntime = resolvePhpRuntime();
const php = phpRuntime.binary;
const workerCount = Math.max(2, Number.parseInt(process.env.PERFORMANCE_WORKERS || '4', 10));
const firstWorkerPort = Number.parseInt(process.env.PERFORMANCE_FIRST_WORKER_PORT || '8021', 10);
const proxyPort = Number.parseInt(process.env.PERFORMANCE_PROXY_PORT || '8020', 10);
const host = '127.0.0.1';
const router = path.join(root, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php');
const workers = [];
let proxy;

function waitForPort(port, timeoutMs = 15_000) {
  const startedAt = Date.now();
  return new Promise((resolve, reject) => {
    const attempt = () => {
      const socket = net.createConnection({ host, port });
      socket.once('connect', () => {
        socket.destroy();
        resolve();
      });
      socket.once('error', () => {
        socket.destroy();
        if (Date.now() - startedAt >= timeoutMs) {
          reject(new Error(`PHP worker on ${host}:${port} did not become ready`));
          return;
        }
        setTimeout(attempt, 100);
      });
    };
    attempt();
  });
}

function run(command, args, env = process.env) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd: root,
      env,
      stdio: 'inherit',
      windowsHide: true,
    });
    child.once('error', reject);
    child.once('exit', (code, signal) => {
      if (signal) reject(new Error(`${command} terminated by ${signal}`));
      else resolve(code ?? 1);
    });
  });
}

function cleanup() {
  proxy?.close();
  for (const worker of workers) {
    if (!worker.killed) worker.kill();
  }
}

process.once('SIGINT', () => {
  cleanup();
  process.exit(130);
});
process.once('SIGTERM', () => {
  cleanup();
  process.exit(143);
});

try {
  await run(php, [...phpRuntime.prefix, 'artisan', 'performance:seed']);

  const ports = Array.from({ length: workerCount }, (_, index) => firstWorkerPort + index);
  for (const port of ports) {
    const worker = spawn(php, [...phpRuntime.prefix, '-S', `${host}:${port}`, router], {
      cwd: path.join(root, 'public'),
      env: process.env,
      stdio: 'ignore',
      windowsHide: true,
    });
    workers.push(worker);
  }
  await Promise.all(ports.map((port) => waitForPort(port)));

  let nextWorker = 0;
  proxy = http.createServer((request, response) => {
    const port = ports[nextWorker++ % ports.length];
    const upstream = http.request({
      hostname: host,
      port,
      path: request.url,
      method: request.method,
      headers: request.headers,
    }, (upstreamResponse) => {
      response.writeHead(upstreamResponse.statusCode || 502, upstreamResponse.headers);
      upstreamResponse.pipe(response);
    });
    upstream.once('error', (error) => {
      if (!response.headersSent) response.writeHead(502, { 'content-type': 'text/plain' });
      response.end(`Upstream worker failed: ${error.message}`);
    });
    request.pipe(upstream);
  });
  await new Promise((resolve, reject) => {
    proxy.once('error', reject);
    proxy.listen(proxyPort, host, resolve);
  });

  console.error(`[performance] ${workerCount} PHP workers behind http://${host}:${proxyPort}`);
  const exitCode = await run(php, [...phpRuntime.prefix, 'scripts/performance-load.php'], {
    ...process.env,
    PERFORMANCE_BASE_URL: `http://${host}:${proxyPort}`,
  });
  process.exitCode = exitCode;
} catch (error) {
  console.error(`[performance] ${error instanceof Error ? error.message : String(error)}`);
  process.exitCode = 1;
} finally {
  cleanup();
}
