// Fast contract + smoke checks on a migrated PHP shop (no browser). Used by the repair loop in migrate.mjs.
// Run alone: node --env-file=.env.local agent/check.mjs <folder>
import { spawn, execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import os from "node:os";
import { listFiles } from "./discover.mjs";

export function findPhp() {
  if (process.env.PHP_BIN && fs.existsSync(process.env.PHP_BIN)) return process.env.PHP_BIN;
  const pkgs = path.join(os.homedir(), "AppData", "Local", "Microsoft", "WinGet", "Packages");
  if (fs.existsSync(pkgs)) for (const d of fs.readdirSync(pkgs)) if (d.startsWith("PHP.PHP")) { const e = path.join(pkgs, d, "php.exe"); if (fs.existsSync(e)) return e; }
  return "php";
}

const ADMIN = "cutover-check-admin";

export async function check(dir, port = 8090) {
  const fails = [];
  const php = findPhp();
  const files = listFiles(dir).filter((f) => f.endsWith(".php"));
  for (const f of files) {
    try { execFileSync(php, ["-l", f], { stdio: "pipe" }); }
    catch (e) { fails.push(`PHP syntax error in ${path.relative(dir, f).replace(/\\/g, "/")}: ${String(e.stdout || e.message).trim().split("\n")[0]}`); }
  }
  const text = (f) => fs.readFileSync(f, "utf8");
  const legacyLeft = files.filter((f) => !/paypal_nvp\.php$/.test(f) && /api-3t|paypal_nvp\(|_notify-validate|paypalobjects\.com\/api\/checkout/.test(text(f))).map((f) => path.relative(dir, f).replace(/\\/g, "/"));
  if (legacyLeft.length) fails.push(`Legacy PayPal code still referenced in: ${legacyLeft.join(", ")}`);
  if (!files.some((f) => !/vendor/.test(f) && /verify-webhook-signature/i.test(text(f)))) fails.push("no webhook handler verifies the webhook signature with PayPal (POST /v1/notifications/verify-webhook-signature)");

  // hallucination guard: every SDK controller the code asks for must exist in the installed package
  const clientSrc = path.join(dir, "vendor", "paypal", "paypal-server-sdk", "src", "PaypalServerSdkClient.php");
  if (fs.existsSync(clientSrc)) {
    const real = new Set([...text(clientSrc).matchAll(/function (get\w+Controller)\(/g)].map((m) => m[1]));
    for (const f of files.filter((x) => !/vendor/.test(x))) for (const m of text(f).matchAll(/->(get\w+Controller)\(/g))
      if (!real.has(m[1])) fails.push(`${path.relative(dir, f).split(path.sep).join("/")} calls ${m[1]}(), which does not exist in the installed PayPal PHP SDK (it only has: ${[...real].join(", ")}). Do not invent SDK methods: use the REST recipe in GROUNDING instead.`);
  }
  const child = spawn(php, ["-S", `localhost:${port}`, "-t", dir], { stdio: "ignore", env: { ...process.env, ADMIN_TOKEN: ADMIN, SHOP_BASE_URL: `http://localhost:${port}` } });
  const base = `http://localhost:${port}`;
  try {
    for (let i = 0; i < 25; i++) { try { await fetch(base + "/"); break; } catch { await new Promise((r) => setTimeout(r, 200)); } }
    const home = await fetch(base + "/").then((r) => r.text()).catch(() => "");
    if (home.length < 400 && !home.includes("<html")) fails.push(`GET / returned an error page instead of the shop: ${home.slice(0, 160)}`);
    for (const [needle, why] of [['data-sku', "cart quantity inputs (input[data-sku]) missing from the page"], ['id="result"', 'result element (id="result") missing'], ['/web-sdk/v6/core', "JS SDK v6 core script not loaded"], ['paypal-button', "PayPal button element missing"]])
      if (!home.includes(needle)) fails.push(`index.php: ${why}`);
    for (const txt of ["Payment cancelled", "onCancel"]) if (!home.includes(txt)) fails.push(`index.php: lost cancel handling (${txt} not found); the legacy page showed "Payment cancelled."`);
    if (/checkout\.js/.test(home)) fails.push("index.php still loads legacy checkout.js");
    const parse = async (r) => { const t = await r.text(); let json = null; try { json = JSON.parse(t); } catch {} return { status: r.status, json, text: t }; };
    const post = (p, body, headers = {}) => fetch(base + p, { method: "POST", headers: { "Content-Type": "application/json", ...headers }, body: JSON.stringify(body) }).then(parse).catch((e) => ({ status: 0, json: null, text: String(e) }));
    const ct = await fetch(base + "/api/client-token.php").then(parse).catch(() => ({ status: 0, text: "" }));
    if (!(ct.status === 200 && ct.json?.clientToken)) fails.push(`api/client-token.php did not return {clientToken} (HTTP ${ct.status}, body: ${String(ct.text).slice(0, 160)})`);
    const cr = await post("/api/create-payment.php", { tee: 1, mug: 2 });
    if (!(cr.status === 200 && typeof (cr.json?.id || cr.json?.token) === "string")) fails.push(`api/create-payment.php with cart {tee:1,mug:2} returned HTTP ${cr.status} body: ${String(cr.text).slice(0, 160)}; expected 200 and an order id`);
    const ui = await post("/api/create-payment.php", { tee: "1", mug: "0", poster: "0" });
    if (!(ui.status === 200 && typeof (ui.json?.id || ui.json?.token) === "string")) fails.push(`api/create-payment.php with the cart the page really sends (quantities are strings and unselected products have "0": {tee:"1",mug:"0",poster:"0"}) returned HTTP ${ui.status} body: ${String(ui.text).slice(0, 160)}. Items with quantity 0 must be skipped (PayPal rejects them), and PayPal/SDK errors must come back as JSON {error} with HTTP 502, never as an HTML error page.`);
    const em = await post("/api/create-payment.php", {});
    if (em.status !== 400) fails.push(`api/create-payment.php with empty cart returned HTTP ${em.status}; the legacy shop returned 400 {error}`);
    const un = await fetch(base + "/api/orders.php").then((r) => r.status).catch(() => 0);
    if (![401, 403].includes(un)) fails.push(`api/orders.php without X-Admin-Token returned HTTP ${un}; expected 401 or 403`);
    const au = await fetch(base + "/api/orders.php", { headers: { "X-Admin-Token": ADMIN } }).then(async (r) => ({ s: r.status, j: await r.json().catch(() => null) })).catch(() => ({ s: 0 }));
    if (!(au.s === 200 && Array.isArray(au.j))) fails.push(`api/orders.php with the admin token returned HTTP ${au.s}; expected 200 and a JSON array`);
    const rf = await post("/api/refund.php", { token: "x" });
    if (![401, 403].includes(rf.status)) fails.push(`api/refund.php without X-Admin-Token returned HTTP ${rf.status}; expected 401 or 403`);
    const rf2 = await post("/api/refund.php", { token: "does-not-exist" }, { "X-Admin-Token": ADMIN });
    if (rf2.status !== 400) fails.push(`api/refund.php with admin token and an unknown order returned HTTP ${rf2.status}; legacy returned 400 {error:"order is not paid"}`);
    // browser smoke: load the page, click the PayPal button, fail on any JavaScript error
    try {
      const { chromium } = await import("playwright");
      const b = await chromium.launch({ channel: "chrome", headless: true });
      const bctx = await b.newContext({ locale: "en-US" });
      const pg = await bctx.newPage();
      const errs = [];
      pg.on("pageerror", (e) => errs.push(String(e).split(String.fromCharCode(10))[0].slice(0, 200)));
      await pg.goto(base + "/", { waitUntil: "domcontentloaded" });
      await pg.waitForTimeout(3000);
      const btn = pg.locator("paypal-button").first();
      let opened = false;
      if (await btn.count()) {
        const pop = bctx.waitForEvent("page", { timeout: 15000 }).then(async (p) => { await p.waitForLoadState("domcontentloaded").catch(() => {}); await pg.waitForTimeout(1500); return !p.isClosed() && /paypal\.com/.test(p.url()); }).catch(() => false);
        await btn.click().catch(() => {});
        opened = await pop;
      }
      if (!opened) fails.push("clicking the PayPal button did not open the PayPal approval window. In JS SDK v6, session.start() must be called synchronously inside the click handler and receive the PROMISE returned by createOrder() (no await before it, not a function reference, not a resolved object).");
      await b.close();
      for (const e of [...new Set(errs)]) fails.push(`browser JavaScript error when loading the page and clicking the PayPal button: ${e}`);
    } catch (e) { fails.push(`browser smoke test could not run: ${String(e).slice(0, 120)}`); }
  } finally { child.kill(); }
  return fails;
}

if (process.argv[1] && /check\.mjs$/.test(process.argv[1])) {
  const dir = process.argv[2];
  const fails = await check(dir);
  console.log(fails.length ? fails.map((f) => "FAIL " + f).join("\n") : "PASS all contract and smoke checks");
  process.exit(fails.length ? 2 : 0);
}
