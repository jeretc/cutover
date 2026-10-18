// Behaviour scenarios for a PayPal shop. Records "golden" outcomes for the legacy shop; the same scenarios later
// run against the migrated shop and are compared.
// Run: node --env-file=.env.local scripts/scenarios.mjs [label]   (SHOP_URL defaults to http://localhost:8081)
// Output: proof/<label>.json (default label "legacy-golden"). Prints PASS/FAIL/INFO only; no credentials.
import { chromium } from "playwright";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

const SHOP = process.env.SHOP_URL || "http://localhost:8081";
const LABEL = process.argv[2] || "legacy-golden";
const { PAYPAL_BUYER_EMAIL: EMAIL, PAYPAL_BUYER_PASSWORD: PASS, PAYPAL_NVP_USER: NU, PAYPAL_NVP_PWD: NP, PAYPAL_NVP_SIGNATURE: NS } = process.env;
if (!EMAIL || !PASS) { console.log("Missing buyer credentials in .env.local"); process.exit(1); }
const shots = process.env.PROOF_SHOTS || path.join(os.tmpdir(), "cutover-proof-shots");
fs.mkdirSync(shots, { recursive: true });
fs.mkdirSync("proof", { recursive: true });
const say = (s, m) => console.log(`${s.padEnd(5)} ${m}`);

// independent check with PayPal itself (classic NVP, merchant credentials)
async function paypalTxn(id) {
  if (!(NU && NP && NS && id)) return null;
  const r = await fetch("https://api-3t.sandbox.paypal.com/nvp", {
    method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ USER: NU, PWD: NP, SIGNATURE: NS, VERSION: "204", METHOD: "GetTransactionDetails", TRANSACTIONID: id }),
  });
  const kv = Object.fromEntries(new URLSearchParams(await r.text()));
  return { ack: kv.ACK, status: kv.PAYMENTSTATUS, amount: kv.AMT, currency: kv.CURRENCYCODE, refunded: kv.REFUNDEDAMOUNT || null };
}

const browser = await chromium.launch({ channel: "chrome", headless: !process.env.PROOF_HEADED });

async function openShop(cart) {
  const ctx = await browser.newContext({ viewport: { width: 1100, height: 900 }, locale: "en-US" });
  const page = await ctx.newPage();
  await page.goto(SHOP, { waitUntil: "domcontentloaded", timeout: 30000 });
  await page.waitForTimeout(4000);
  for (const [sku, qty] of Object.entries(cart)) await page.fill(`input[data-sku="${sku}"]`, String(qty));
  await page.waitForTimeout(300);
  return { ctx, page };
}

// click the PayPal button; resolves with the popup page
async function clickPayPal(ctx, page) {
  const v6 = page.locator("paypal-button, #paypal-button button").first();
  const legacy = page.frameLocator('iframe[name^="__zoid__paypal_button"], iframe[title*="PayPal"]').first().locator(".paypal-button, [data-funding-source='paypal'], button").first();
  await Promise.race([v6.waitFor({ state: "visible", timeout: 30000 }), legacy.waitFor({ state: "visible", timeout: 30000 })]).catch(() => {});
  const button = (await v6.isVisible().catch(() => false)) ? v6 : legacy;
  const popupPromise = ctx.waitForEvent("page", { timeout: 30000 });
  await button.click();
  const pop = await popupPromise;
  await pop.waitForLoadState("domcontentloaded");
  await pop.waitForTimeout(2000);
  return pop;
}

async function login(pop) {
  const email = pop.locator("#email");
  await email.waitFor({ state: "visible", timeout: 25000 });
  await email.fill(EMAIL);
  if (await pop.locator("#btnNext").count()) await pop.locator("#btnNext").click();
  const pw = pop.locator("#password");
  await pw.waitFor({ state: "visible", timeout: 25000 });
  await pw.fill(PASS);
  await pop.locator("#btnLogin").click();
  await pop.waitForTimeout(3500);
}

async function confirm(pop) {
  const sels = ["#payment-submit-btn", "[data-testid='submit-button-initial']", "button:has-text('Complete Purchase')", "button:has-text('Pay Now')", "button:has-text('Continue')"];
  for (const sel of sels) {
    const el = pop.locator(sel).first();
    if (await el.count()) { try { await el.waitFor({ state: "visible", timeout: 12000 }); await el.click(); return true; } catch {} }
  }
  return false;
}

const resultText = async (page) => {
  await page.waitForFunction(() => /Payment (complete|failed|cancelled)/i.test(document.getElementById("result")?.textContent || ""), null, { timeout: 60000 });
  return (await page.locator("#result").innerText()).trim();
};

async function buy(cart, name) {
  const { ctx, page } = await openShop(cart);
  try {
    const pop = await clickPayPal(ctx, page);
    await login(pop);
    if (!(await confirm(pop))) throw new Error("no confirm button");
    const text = await resultText(page);
    const m = text.match(/Transaction\s+([A-Z0-9]+)\s+for\s+\$?([\d.]+)/i);
    await page.screenshot({ path: `${shots}/${name}.png` }).catch(() => {});
    return { text, txn: m?.[1] || null, amount: m?.[2] || null };
  } finally { await ctx.close(); }
}

async function cancelAtPayPal(cart, name) {
  const { ctx, page } = await openShop(cart);
  try {
    const pop = await clickPayPal(ctx, page);
    await login(pop);
    const link = pop.locator("a:has-text('Cancel and return'), button:has-text('Cancel and return')").first();
    await link.waitFor({ state: "visible", timeout: 20000 });
    await link.click();
    const text = await resultText(page);
    await page.screenshot({ path: `${shots}/${name}.png` }).catch(() => {});
    return { text };
  } finally { await ctx.close(); }
}

const api = async (p, body) => {
  const r = await fetch(`${SHOP}${p}`, { method: body ? "POST" : "GET", headers: { "Content-Type": "application/json", "X-Admin-Token": process.env.ADMIN_TOKEN || "cutover-demo-admin" }, body: body ? JSON.stringify(body) : undefined });
  return { status: r.status, json: await r.json().catch(() => ({})) };
};

const record = { label: LABEL, shop: SHOP, at: new Date().toISOString(), scenarios: {} };
async function scenario(id, title, fn) {
  try { const out = await fn(); record.scenarios[id] = { title, ...out }; say(out.pass ? "PASS" : "FAIL", `${id}: ${out.summary}`); }
  catch (e) { record.scenarios[id] = { title, pass: false, summary: String(e.message).split("\n")[0] }; say("FAIL", `${id}: ${String(e.message).split("\n")[0]}`); }
}

// 1. normal purchase
let first = null;
await scenario("S1-single-item", "Buy one tee", async () => {
  const r = await buy({ tee: 1 }, "S1");
  first = r;
  const t = await paypalTxn(r.txn);
  const pass = /Payment complete/i.test(r.text) && t?.status === "Completed" && Number(t.amount) === 25;
  return { pass, summary: `shop: paid ${r.amount}; PayPal: ${t?.status} ${t?.amount} ${t?.currency}`, outcome: { shop: "paid", amount: r.amount, paypalStatus: t?.status, paypalAmount: t?.amount, currency: t?.currency } };
});

// 2. multi-item cart
let second = null;
await scenario("S2-multi-item", "Buy 1 tee + 2 mugs (25.00 + 2 x 12.50)", async () => {
  const r = await buy({ tee: 1, mug: 2 }, "S2");
  second = r;
  const t = await paypalTxn(r.txn);
  const pass = /Payment complete/i.test(r.text) && t?.status === "Completed" && Number(t.amount) === 50;
  return { pass, summary: `shop: paid ${r.amount}; PayPal: ${t?.status} ${t?.amount} ${t?.currency}`, outcome: { shop: "paid", amount: r.amount, paypalStatus: t?.status, paypalAmount: t?.amount, currency: t?.currency } };
});

// 3. buyer cancels at PayPal
await scenario("S3-buyer-cancels", "Buyer cancels at PayPal", async () => {
  const r = await cancelAtPayPal({ tee: 1 }, "S3");
  const pass = /cancelled/i.test(r.text);
  return { pass, summary: `shop shows: ${r.text}`, outcome: { shop: "cancelled" } };
});

// 4. refund an order
await scenario("S4-refund", "Refund the second order in full", async () => {
  const orders = (await api("/api/orders.php")).json;
  const order = Array.isArray(orders) ? orders.find((o) => o.transaction_id === second?.txn) : null;
  if (!order) throw new Error("order not found in the shop data");
  const rf = await api("/api/refund.php", { token: order.token });
  const t = await paypalTxn(second.txn);
  const pass = rf.status === 200 && rf.json.ok === true && /Refunded|Partially/i.test(t?.status || "");
  return { pass, summary: `refund HTTP ${rf.status}; PayPal now says ${t?.status}`, outcome: { refundHttp: rf.status, paypalStatus: t?.status } };
});

// 5. a second refund of the same order must be refused
await scenario("S5-refund-twice", "Refunding an already refunded order is refused", async () => {
  const orders = (await api("/api/orders.php")).json;
  const order = orders.find((o) => o.transaction_id === second?.txn);
  const rf = await api("/api/refund.php", { token: order.token });
  const pass = rf.status >= 400;
  return { pass, summary: `second refund HTTP ${rf.status} (${rf.json.error || "no error"})`, outcome: { refundHttp: rf.status } };
});

// 6. double-submit: replaying the confirmation for a paid order must never create a second charge
await scenario("S6-double-execute", "Replaying the payment confirmation for a paid order does not charge twice", async () => {
  const orders = (await api("/api/orders.php")).json;
  const order = orders.find((o) => o.transaction_id === first?.txn);
  const rp = await api("/api/execute-payment.php", { token: order.token, orderId: order.token, payerID: order.payer_id || "UNKNOWN" });
  const sameTxn = rp.status === 200 && rp.json.transactionId === first?.txn;
  const refused = rp.status >= 400 && rp.status !== 404;
  const pass = sameTxn || refused;
  return { pass, summary: `replay HTTP ${rp.status}: ${sameTxn ? "returned the SAME transaction (no second charge)" : refused ? "refused (" + (rp.json.error || "error") + ")" : "NEW transaction created (double charge!)"}`, outcome: { noSecondCharge: pass, behaviour: sameTxn ? "returns-same-transaction" : refused ? "refuses" : "double-charge" } };
});

// 7. empty cart
await scenario("S7-empty-cart", "Creating a payment with an empty cart is rejected", async () => {
  const rc = await api("/api/create-payment.php", {});
  const pass = rc.status === 400;
  return { pass, summary: `HTTP ${rc.status} (${rc.json.error || ""})`, outcome: { http: rc.status } };
});

await browser.close();
fs.writeFileSync(`proof/${LABEL}.json`, JSON.stringify(record, null, 2));
const failed = Object.values(record.scenarios).filter((s) => !s.pass).length;
console.log(`\nwrote proof/${LABEL}.json (${Object.keys(record.scenarios).length} scenarios, ${failed} failed)`);
process.exit(failed ? 2 : 0);
