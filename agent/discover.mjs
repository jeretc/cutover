// Cutover discovery: finds every PayPal touchpoint and the security problems in a legacy codebase. Deterministic (no AI).
// Run: node agent/discover.mjs <folder> [outfile]      e.g. node agent/discover.mjs samples/legacy-php-shop proof/discovery.json
import fs from "node:fs";
import path from "node:path";
import { NVP_METHODS, NVP_PARAMS, NVP_LINE_ITEM, NVP_RESPONSE, IPN_TO_WEBHOOK } from "./mappings.mjs";

const CODE_EXT = new Set([".php", ".js", ".mjs", ".html", ".htm", ".inc", ".py", ".rb"]);
const SKIP_DIR = new Set(["node_modules", "vendor", ".git", "data"]);

export function listFiles(root) {
  const out = [];
  (function walk(d) {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) { if (!SKIP_DIR.has(e.name)) walk(p); }
      else if (CODE_EXT.has(path.extname(e.name).toLowerCase())) out.push(p);
    }
  })(root);
  return out.sort();
}

const rel = (root, p) => path.relative(root, p).replace(/\\/g, "/");
const snip = (s) => s.trim().replace(/\s+/g, " ").slice(0, 140);

export function discover(root) {
  const files = listFiles(root).map((p) => ({ p, rel: rel(root, p), lines: fs.readFileSync(p, "utf8").split(/\r?\n/) }));
  const touchpoints = [];
  const security = [];
  const unmapped = [];
  let tp = 0, sec = 0;
  const addTP = (o) => touchpoints.push({ id: `T${String(++tp).padStart(2, "0")}`, ...o });
  const addSec = (o) => security.push({ id: `S${String(++sec).padStart(2, "0")}`, ...o });

  const methodNames = Object.keys(NVP_METHODS);
  const methodRe = new RegExp(`['"](${methodNames.join("|")})['"]`);

  for (const f of files) {
    f.lines.forEach((rawLine, i) => {
      const n = i + 1;
      const line = rawLine.replace(/(^|\s)\/\/.*$/, "");
      // 1. NVP endpoint (the classic API host)
      if (/api-3t(\.sandbox)?\.paypal\.com\/nvp|\/nvp['"]/i.test(line))
        addTP({ kind: "nvp-endpoint", file: f.rel, line: n, snippet: snip(line), legacy: "Classic NVP endpoint", modern: "REST base https://api-m.sandbox.paypal.com (+ OAuth2 token)", confidence: "documented", risk: "high" });
      // 2. NVP method calls
      const m = line.match(methodRe);
      if (m) {
        const map = NVP_METHODS[m[1]];
        addTP({ kind: "nvp-call", file: f.rel, line: n, snippet: snip(line), legacy: m[1], modern: map.modern, confidence: map.confidence, risk: map.confidence === "unmapped" ? "high" : "medium", note: map.note });
        if (map.confidence === "unmapped") unmapped.push({ file: f.rel, line: n, what: m[1], why: map.note });
      }
      // 3. NVP request parameter keys
      for (const k of line.matchAll(/['"]((?:L_)?PAYMENTREQUEST_\d_[A-Z]+\d*|[A-Z][A-Z_]{2,})['"]\s*=>/g)) {
        const key = k[1];
        const li = key.match(/^L_PAYMENTREQUEST_\d_(NAME|AMT|QTY)\d*$/);
        const base = key.replace(/\$?\d+$/, "").replace(/^L_PAYMENTREQUEST_(\d)_/, "L_PAYMENTREQUEST_0_");
        const map = NVP_PARAMS[key] || (li ? NVP_LINE_ITEM[li[1]] : null);
        if (map) addTP({ kind: "nvp-param", file: f.rel, line: n, snippet: key, legacy: key, modern: map.modern, confidence: map.confidence, risk: "low" });
        else if (/^[A-Z][A-Z0-9_]+$/.test(key) && !["SHOP_CURRENCY"].includes(key) && /paypal|nvp|checkout/i.test(f.lines.join(" ")))
          if (!/^(PRODUCTS|SHOP_)/.test(key)) unmapped.push({ file: f.rel, line: n, what: key, why: "NVP-style parameter with no known modern mapping: needs a human decision" });
        void base;
      }
      // 4. NVP response fields read by the code
      for (const r of line.matchAll(/\[\s*['"](ACK|TOKEN|EMAIL|PAYMENTINFO_0_TRANSACTIONID|REFUNDTRANSACTIONID|L_LONGMESSAGE0)['"]\s*\]/g)) {
        const map = NVP_RESPONSE[r[1]];
        addTP({ kind: "nvp-response", file: f.rel, line: n, snippet: `$response['${r[1]}']`, legacy: r[1], modern: map.modern, confidence: map.confidence, risk: "low" });
      }
      // 5. checkout.js (legacy front end)
      if (/paypalobjects\.com\/api\/checkout(\.min)?\.js/.test(line))
        addTP({ kind: "checkoutjs-script", file: f.rel, line: n, snippet: snip(line), legacy: "checkout.js v4 script tag", modern: "JS SDK v6 core script (https://www.paypal.com/web-sdk/v6/core) + server-minted client token", confidence: "documented", risk: "high" });
      if (/paypal\.Button\.render/.test(line))
        addTP({ kind: "checkoutjs-button", file: f.rel, line: n, snippet: snip(line), legacy: "paypal.Button.render(...)", modern: "paypal.createInstance + createPayPalOneTimePaymentSession(...).start()", confidence: "documented", risk: "high" });
      if (/\bonAuthorize\s*:|\bpayment\s*:\s*function/.test(line))
        addTP({ kind: "checkoutjs-callback", file: f.rel, line: n, snippet: snip(line), legacy: /onAuthorize/.test(line) ? "onAuthorize callback" : "payment() callback", modern: /onAuthorize/.test(line) ? "session onApprove -> capture on the server" : "session createOrder() must resolve { orderId }", confidence: "documented", risk: "medium" });
      // 6. IPN (legacy notifications)
      if (/_notify-validate/.test(line))
        addTP({ kind: "ipn-verify", file: f.rel, line: n, snippet: snip(line), legacy: "IPN postback (cmd=_notify-validate)", modern: "Webhooks + POST /v1/notifications/verify-webhook-signature", confidence: "documented", risk: "high" });
      const st = line.match(/payment_status['"]\s*\]\s*\)?\s*(?:\?\?\s*['"][^'"]*['"]\s*\)?\s*)?===?\s*['"](\w+)['"]/);
      if (st && IPN_TO_WEBHOOK[st[1]])
        addTP({ kind: "ipn-event", file: f.rel, line: n, snippet: snip(line), legacy: `IPN payment_status = ${st[1]}`, modern: `webhook event ${IPN_TO_WEBHOOK[st[1]].modern}`, confidence: IPN_TO_WEBHOOK[st[1]].confidence, risk: "medium" });
    });
  }

  // ---- security findings ----
  for (const f of files) {
    const text = f.lines.join("\n");
    f.lines.forEach((line, i) => {
      const n = i + 1;
      if (/(PASSWORD|SIGNATURE|SECRET|USERNAME|API_KEY)/i.test(line) && /getenv\([^)]*\)\s*\?:\s*['"][^'"]+['"]/.test(line))
        addSec({ severity: "high", file: f.rel, line: n, title: "Credential with a hardcoded fallback value", why: "Secrets live in source code (a fallback is still a committed secret and masks a missing environment variable).", fix: "Read credentials only from the environment or a secret store; fail loudly when missing. The REST migration replaces user/password/signature with OAuth2 client id and secret.", evidence: snip(line) });
      if (/define\(\s*['"][A-Z_]*(PASSWORD|SIGNATURE|SECRET)['"]\s*,\s*['"][^'"]{6,}['"]\s*\)/.test(line))
        addSec({ severity: "critical", file: f.rel, line: n, title: "Secret hardcoded as a constant", why: "Anyone with the code has the credential.", fix: "Move to environment variables.", evidence: snip(line) });
      if (/CURLOPT_SSL_VERIFYPEER\s*,\s*(false|0)/i.test(line))
        addSec({ severity: "high", file: f.rel, line: n, title: "TLS certificate verification disabled", why: "Allows man-in-the-middle attacks on payment calls.", fix: "Never disable verification.", evidence: snip(line) });
      if (/['"]http:\/\/(?!localhost|127\.0\.0\.1)/.test(line))
        addSec({ severity: "medium", file: f.rel, line: n, title: "Plain HTTP URL", why: "Return/cancel/callback URLs should use HTTPS in production.", fix: "Use https URLs.", evidence: snip(line) });
      if (/file_put_contents\([^)]*['"][^'"]*(?:\/|\\)data(?:\/|\\)/.test(line) || /__DIR__\s*\.\s*['"]\/\.\.\/data\//.test(line))
        addSec({ severity: "medium", file: f.rel, line: n, title: "Order data stored under the web root", why: "Files in the document root can be downloaded directly (for example /data/orders.json).", fix: "Store data outside the web root or in a database.", evidence: snip(line) });
    });
    // IPN handler checks
    if (/_notify-validate/.test(text) && /payment_status/.test(text)) {
      const missing = [];
      if (!/receiver_email/.test(text)) missing.push("receiver_email");
      if (!/mc_gross|mc_currency/.test(text.replace(/\.\s*\(\$ipn\['mc_gross'\][^)]*\)/g, "")) || !/(===|==|!==|!=)[^\n]*mc_gross|mc_gross[^\n]*(===|==|!==|!=)/.test(text)) missing.push("amount/currency comparison");
      if (!/txn_id[^\n]*(in_array|isset|seen|duplicate|unique)|(?:in_array|isset|seen|duplicate|unique)[^\n]*txn_id/i.test(text)) missing.push("duplicate txn_id check");
      if (missing.length)
        addSec({ severity: "high", file: f.rel, line: 1, title: "IPN handler accepts a verified message without checking it matches an order", why: `After PayPal says VERIFIED it still does not check: ${missing.join(", ")}. A genuine notification for a different payment could mark an order paid.`, fix: "Check receiver, amount, currency and transaction id against the stored order and ignore duplicates. In the migration: verify the webhook signature and match the event to an order.", evidence: "payment_status handled without order matching" });
    }
    // unauthenticated sensitive endpoints
    const hasAuth = /session_start|\$_SESSION|PHP_AUTH|Authorization|Bearer|ADMIN_TOKEN|X-Admin-Token|password_verify|is_admin|require_login/i.test(text);
    if (/RefundTransaction/.test(text) && /php:\/\/input/.test(text) && !hasAuth)
      addSec({ severity: "critical", file: f.rel, line: 1, title: "Refund endpoint has no authentication", why: "Anyone who can reach this URL and knows (or guesses) an order token can trigger a refund of real money.", fix: "Require an authenticated admin session and CSRF protection; log every refund.", evidence: "RefundTransaction behind an open HTTP endpoint" });
    if (/orders_all\(\)/.test(text) && /json_encode/.test(text) && /^api\//.test(f.rel) && !hasAuth)
      addSec({ severity: "high", file: f.rel, line: 1, title: "Order listing is public", why: "Returns every order (tokens, amounts, buyer emails, transaction ids) without authentication; the tokens also unlock the refund endpoint.", fix: "Remove it or require admin authentication.", evidence: "orders_all() returned as JSON" });
    // no replay guard in the payment confirmation
    if (/DoExpressCheckoutPayment/.test(text) && /php:\/\/input/.test(text) && !/['"](PAID|COMPLETED)['"]/.test(text.split("DoExpressCheckoutPayment")[0]))
      addSec({ severity: "medium", file: f.rel, line: 1, title: "Payment confirmation has no replay guard", why: "The handler does not check whether the order is already paid before calling PayPal. The classic API hides this because it returns the same transaction, but the modern API will refuse a repeat capture, so behaviour changes.", fix: "Check the stored order status first; make the confirmation idempotent on the server.", evidence: "no status check before DoExpressCheckoutPayment" });
  }

  const count = (arr, key) => arr.reduce((a, x) => ((a[x[key]] = (a[x[key]] || 0) + 1), a), {});
  return {
    scannedAt: new Date().toISOString(),
    root: root.replace(/\\/g, "/"),
    filesScanned: files.map((f) => f.rel),
    summary: { touchpoints: touchpoints.length, byKind: count(touchpoints, "kind"), security: security.length, bySeverity: count(security, "severity"), unmapped: unmapped.length },
    touchpoints, security, unmapped,
  };
}

// CLI
if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"))) {
  const root = process.argv[2];
  if (!root) { console.log("usage: node agent/discover.mjs <folder> [out.json]"); process.exit(1); }
  const report = discover(root);
  const out = process.argv[3] || "proof/discovery.json";
  fs.mkdirSync(path.dirname(out), { recursive: true });
  fs.writeFileSync(out, JSON.stringify(report, null, 2));
  const s = report.summary;
  console.log(`Scanned ${report.filesScanned.length} files in ${root}`);
  console.log(`PayPal touchpoints: ${s.touchpoints}  ${JSON.stringify(s.byKind)}`);
  console.log(`Security findings:  ${s.security}  ${JSON.stringify(s.bySeverity)}`);
  console.log(`Unmapped (need a human): ${s.unmapped}`);
  console.log("\nSecurity findings:");
  for (const x of report.security) console.log(`  [${x.severity.toUpperCase().padEnd(8)}] ${x.file}:${x.line}  ${x.title}`);
  if (report.unmapped.length) { console.log("\nUnmapped:"); for (const u of report.unmapped) console.log(`  ${u.file}:${u.line}  ${u.what}: ${u.why}`); }
  console.log(`\nwrote ${out}`);
}
