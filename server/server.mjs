// Cutover live demo server: one process that serves the report site (web/) and runs BOTH shops side by side:
//   /legacy/  the old shop (checkout.js v4 + classic NVP API)
//   /shop/    the migrated shop (Orders v2 + PHP Server SDK + JS SDK v6)
// Each shop is a PHP built-in server on an internal port; this server proxies to it.
// All PayPal credentials come from environment variables (sandbox keys). Nothing is stored in code.
import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import { spawn } from "node:child_process";
import { findPhp } from "../agent/check.mjs";

const root = path.resolve(process.env.CUTOVER_ROOT || ".");
const PORT = Number(process.env.PORT || 8080);
const PUBLIC = (process.env.PUBLIC_URL || process.env.RENDER_EXTERNAL_URL || `http://localhost:${PORT}`).replace(/\/$/, "");
const SHOPS = {
  "/legacy": { dir: path.join(root, "samples/legacy-php-shop"), port: 8101 },
  "/shop": { dir: path.join(root, "samples/migrated-php-shop-out"), port: 8102 },
};
const web = path.join(root, "web");
const php = findPhp();

for (const [prefix, s] of Object.entries(SHOPS)) {
  fs.mkdirSync(path.join(s.dir, "data"), { recursive: true });
  const child = spawn(php, ["-S", `127.0.0.1:${s.port}`, "-t", s.dir], {
    stdio: "ignore",
    env: { ADMIN_TOKEN: process.env.ADMIN_TOKEN || "cutover-demo-admin", ...process.env, SHOP_BASE_URL: PUBLIC + prefix },
  });
  child.on("exit", (c) => console.log(`php for ${prefix} exited (${c})`));
  process.on("exit", () => child.kill());
}

const types = { ".html": "text/html; charset=utf-8", ".js": "text/javascript", ".css": "text/css", ".json": "application/json", ".png": "image/png", ".svg": "image/svg+xml" };
const send = (res, code, body, type = "application/json") => { res.writeHead(code, { "Content-Type": type, "Cache-Control": "no-store" }); res.end(typeof body === "string" ? body : JSON.stringify(body)); };

function recentOrders() {
  const out = [];
  for (const [prefix, s] of Object.entries(SHOPS)) {
    try {
      const orders = JSON.parse(fs.readFileSync(path.join(s.dir, "data/orders.json"), "utf8"));
      for (const o of Object.values(orders)) out.push({ shop: prefix === "/legacy" ? "Old shop" : "Migrated shop", amount: o.amount, status: o.status, transaction: o.transaction_id || null, at: o.created || null });
    } catch {}
  }
  return out.sort((a, b) => String(b.at).localeCompare(String(a.at))).slice(0, 12);
}

// the shops use absolute /api/ URLs; behind a prefix they must point at the prefixed path
const rewriteHtml = (html, prefix) => html.replace(/(['"`])\/api\//g, `$1${prefix}/api/`);

function proxy(req, res, prefix, s) {
  const upstreamPath = req.url.slice(prefix.length) || "/";
  const up = http.request({ host: "127.0.0.1", port: s.port, path: upstreamPath, method: req.method, headers: { ...req.headers, host: `127.0.0.1:${s.port}` } }, (r) => {
    const headers = { ...r.headers };
    if (headers.location && headers.location.startsWith("/")) headers.location = prefix + headers.location;
    if (/text\/html/.test(headers["content-type"] || "")) {
      const chunks = []; r.on("data", (c) => chunks.push(c));
      r.on("end", () => { const body = rewriteHtml(Buffer.concat(chunks).toString("utf8"), prefix); delete headers["content-length"]; res.writeHead(r.statusCode, { ...headers, "Cache-Control": "no-store" }); res.end(body); });
    } else { res.writeHead(r.statusCode, headers); r.pipe(res); }
  });
  up.on("error", () => send(res, 502, { error: "shop is starting, try again in a few seconds" }));
  req.pipe(up);
}

http.createServer((req, res) => {
  const url = new URL(req.url, "http://x");
  if (url.pathname === "/healthz") return send(res, 200, { ok: true });
  if (url.pathname === "/api/recent-orders") return send(res, 200, recentOrders());
  if (url.pathname === "/api/demo-buyer") {
    const e = process.env.PAYPAL_BUYER_EMAIL, p = process.env.PAYPAL_BUYER_PASSWORD;
    return send(res, 200, e && p ? { email: e, password: p } : { email: null });
  }
  for (const [prefix, s] of Object.entries(SHOPS)) {
    if (url.pathname === prefix) { res.writeHead(301, { Location: prefix + "/" }); return res.end(); }
    if (url.pathname.startsWith(prefix + "/")) return proxy(req, res, prefix, s);
  }
  const p = path.normalize(decodeURIComponent(url.pathname)).replace(/^[\\/]+/, "");
  const f = path.join(web, p === "" ? "index.html" : p);
  if (!f.startsWith(web) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) return send(res, 404, "Not found", "text/plain");
  res.writeHead(200, { "Content-Type": types[path.extname(f)] || "application/octet-stream", "Cache-Control": "no-cache" });
  fs.createReadStream(f).pipe(res);
}).listen(PORT, () => console.log(`Cutover live demo on ${PUBLIC}  (old shop /legacy/, migrated shop /shop/)`));
