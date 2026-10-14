// Cutover migration: rewrites a legacy PayPal shop to Orders v2 + PayPal PHP Server SDK + JS SDK v6 + webhooks.
// Run: node --env-file=.env.local agent/migrate.mjs <legacyFolder> <outFolder> [discovery.json]
// AI = Gemini (free tier), grounded on agent/grounding.md + agent/mappings.mjs + the discovery report.
// Every model answer is cached in proof/ai-runs/ so the demo can replay a recorded run without a key.
// Nothing is auto-merged: the output goes to a separate folder and a review report (proof/migration-report.json).
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { listFiles, discover } from "./discover.mjs";
import * as M from "./mappings.mjs";

const [src, out, discPath] = process.argv.slice(2);
if (!src || !out) { console.log("usage: node --env-file=.env.local agent/migrate.mjs <legacyFolder> <outFolder> [discovery.json]"); process.exit(1); }
const KEY = process.env.GEMINI_API_KEY;
const MODELS = (process.env.GEMINI_MODELS || "gemini-2.5-flash,gemini-3.1-flash-lite").split(",");
const CACHE = "proof/ai-runs";
fs.mkdirSync(CACHE, { recursive: true });

const grounding = fs.readFileSync(new URL("./grounding.md", import.meta.url), "utf8");
const report = discPath ? JSON.parse(fs.readFileSync(discPath, "utf8")) : discover(src);
const rel = (p) => path.relative(src, p).replace(/\\/g, "/");

const SYSTEM = `You are Cutover, a careful PayPal migration engineer. You migrate a legacy PHP shop that uses the classic NVP API (Express Checkout), checkout.js v4 and IPN to the modern stack: Orders v2 via the PayPal PHP Server SDK, JS SDK v6, and webhooks.
Rules:
- Preserve every externally visible behaviour: same URLs of the shop's own endpoints, same JSON response shapes ({token} from create-payment becomes {id} ONLY if the front end is updated consistently), same HTTP status codes, same DOM ids and result texts in the page ("Payment complete. Transaction X for $Y", "Payment cancelled.", "Payment failed: ...").
- Preserve ALL non-PayPal code and UI verbatim (cart inputs with data-sku attributes, totals, the result box id=result, the cancel handler that shows 'Payment cancelled.'). Change only what PayPal requires.
- Use ONLY the APIs shown in the GROUNDING section. Never invent SDK classes or methods. If something cannot be mapped with confidence, keep the legacy line out, add a "// CUTOVER-REVIEW:" comment explaining why, and list it in "flags".
- Fix the security findings you are given (no credential fallbacks, authenticate the refund and order-list endpoints with a shared ADMIN_TOKEN environment variable sent in the X-Admin-Token header, replay guard on payment confirmation, verify webhooks).
- Output STRICT JSON only: {"files":[{"path":"relative/path","content":"full file content","changes":["short bullet",...]}],"flags":["things a human must decide"]}. Include every file you changed or add. Do not include files that need no change.`;

async function gemini(prompt) {
  const hash = crypto.createHash("sha256").update(SYSTEM + prompt).digest("hex").slice(0, 16);
  const cf = `${CACHE}/${hash}.json`;
  if (fs.existsSync(cf)) return { ...JSON.parse(fs.readFileSync(cf, "utf8")), cached: true };
  if (!KEY) throw new Error("GEMINI_API_KEY missing and no cached run for this input");
  let last;
  for (const model of MODELS) {
    for (let attempt = 0; attempt < 3; attempt++) {
      if (attempt) await new Promise((r) => setTimeout(r, 15000 * attempt));
      const r = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent`, {
        method: "POST", headers: { "Content-Type": "application/json", "x-goog-api-key": KEY },
        body: JSON.stringify({ systemInstruction: { parts: [{ text: SYSTEM }] }, contents: [{ role: "user", parts: [{ text: prompt }] }], generationConfig: { temperature: 0.1, maxOutputTokens: 60000, responseMimeType: "application/json" } }),
      });
      const body = await r.json().catch(() => ({}));
      const text = body?.candidates?.[0]?.content?.parts?.map((p) => p.text).join("") || "";
      if (r.ok && text) {
        try { const json = JSON.parse(text); const rec = { model, at: new Date().toISOString(), json }; fs.writeFileSync(cf, JSON.stringify(rec, null, 2)); return rec; }
        catch (e) { last = `invalid JSON from ${model}`; fs.writeFileSync(`${CACHE}/invalid-${model}-${Date.now()}.txt`, text); console.log(`    ${last} (${text.length} chars, ends: ${JSON.stringify(text.slice(-60))})`); }
      } else { last = `${model} HTTP ${r.status}`; console.log(`    ${last}, retrying`); }
    }
  }
  throw new Error(`all models failed (${last})`);
}

// group the work: one call per legacy file that has findings; related files are included as context
const byFile = {};
for (const t of report.touchpoints) (byFile[t.file] ||= { t: [], s: [] }).t.push(t);
for (const s of report.security) (byFile[s.file] ||= { t: [], s: [] }).s.push(s);
const read = (f) => fs.readFileSync(path.join(src, f), "utf8");
const allFiles = listFiles(src).map(rel);

fs.rmSync(out, { recursive: true, force: true });
for (const f of allFiles) { fs.mkdirSync(path.dirname(path.join(out, f)), { recursive: true }); fs.copyFileSync(path.join(src, f), path.join(out, f)); }

fs.mkdirSync(path.join(out, "data"), { recursive: true });
const migration = { at: new Date().toISOString(), source: src, out, calls: [], filesChanged: [], filesAdded: [], flags: [] };
const targets = Object.keys(byFile).sort();
console.log(`Migrating ${targets.length} files with findings: ${targets.join(", ")}`);

// One pass over the whole codebase so every file agrees on shared interfaces (ids, status values, helpers).
const all = allFiles.map((f) => "--- FILE " + f + " ---" + String.fromCharCode(10) + read(f)).join(String.fromCharCode(10, 10));
const findings = { touchpoints: report.touchpoints.map(({ file, line, legacy, modern, confidence }) => ({ file, line, legacy, modern, confidence })), security: report.security.map(({ file, severity, title, fix }) => ({ file, severity, title, fix })), unmapped: report.unmapped };
const prompt = `GROUNDING
${grounding}

MAPPING TABLES
${JSON.stringify({ methods: M.NVP_METHODS, params: M.NVP_PARAMS, response: M.NVP_RESPONSE, ipn: M.IPN_TO_WEBHOOK }, null, 1)}

DISCOVERY FINDINGS
${JSON.stringify(findings)}

WHOLE LEGACY CODEBASE
${all}

Migrate the whole shop in one consistent pass. Keep the shop's own endpoint URLs and the order JSON file layout; the order record keeps keys token (now the PayPal order id), amount, status (CREATED/PAID/REFUNDED), transaction_id (the capture id), payer_email, payer_id. Output only files that change or are new; each file at most once.`;
const rel2 = (p) => path.relative(out, p).split(path.sep).join("/");
const apply = (res) => {
  for (const nf of res.json.files || []) {
    const p = path.join(out, nf.path);
    const existed = fs.existsSync(p) || migration.filesAdded.some((x) => x.path === nf.path);
    fs.mkdirSync(path.dirname(p), { recursive: true });
    fs.writeFileSync(p, nf.content);
    const list = existed && !migration.filesAdded.some((x) => x.path === nf.path) ? migration.filesChanged : migration.filesAdded;
    const prev = list.find((x) => x.path === nf.path);
    if (prev) prev.changes.push(...(nf.changes || [])); else list.push({ path: nf.path, changes: nf.changes || [] });
  }
};
const syncVendor = () => { const v = new URL("./sdk-cache/vendor", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"); for (const f of ["composer.json", "composer.lock"]) { const sf = new URL("./sdk-cache/" + f, import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"); if (fs.existsSync(sf)) fs.copyFileSync(sf, path.join(out, f)); } if (fs.existsSync(v) && !fs.existsSync(path.join(out, "vendor"))) fs.cpSync(v, path.join(out, "vendor"), { recursive: true }); };
migration.rounds = [];
console.log(`Migrating the whole shop (${allFiles.length} files)`);
let res = await gemini(prompt);
console.log(`  pass 1: ${res.cached ? "cached" : "AI"} (${res.model})`);
migration.calls.push({ model: res.model, cached: !!res.cached });
apply(res); for (const fl of res.json.flags || []) migration.flags.push({ flag: fl });
syncVendor();
const { check } = await import("./check.mjs");
for (let round = 1; round <= 3; round++) {
  const fails = await check(out);
  migration.rounds.push({ round, failures: fails });
  console.log(`  check ${round}: ${fails.length ? fails.length + " failure(s)" : "all contract and smoke checks pass"}`);
  fails.forEach((f) => console.log("    - " + f.slice(0, 160)));
  if (!fails.length || round === 3) break;
  const cur = listFiles(out).filter((f) => !f.includes("vendor")).map(rel).map((f) => "--- FILE " + f + " ---" + String.fromCharCode(10) + fs.readFileSync(path.join(out, f), "utf8")).join(String.fromCharCode(10, 10));
  const fix = `The migrated shop below failed automated checks. Fix ONLY what the failures require, keep everything else. Return only the files you change (full content), same JSON format.

GROUNDING
${grounding}

FAILURES
${fails.map((f) => "- " + f).join(String.fromCharCode(10))}

CURRENT MIGRATED SHOP
${cur}`;
  res = await gemini(fix);
  console.log(`  repair ${round}: ${res.cached ? "cached" : "AI"} (${res.model})`);
  migration.calls.push({ model: res.model, cached: !!res.cached, repair: round });
  apply(res); for (const fl of res.json.flags || []) migration.flags.push({ flag: fl });
}
// retire legacy files nothing references any more (deterministic, recorded for the human reviewer)
migration.filesRemoved = [];
{
  const php = listFiles(out).filter((f) => !f.includes("vendor") && f.endsWith(".php"));
  for (const f of php) {
    const base = path.basename(f);
    if (!/function paypal_nvp\(/.test(fs.readFileSync(f, "utf8"))) continue;
    const used = php.some((g) => g !== f && fs.readFileSync(g, "utf8").includes(base));
    if (!used) { fs.rmSync(f); migration.filesRemoved.push({ path: rel2(f), reason: "legacy NVP client, no longer referenced by any file" }); }
  }
}
fs.writeFileSync("proof/migration-report.json", JSON.stringify(migration, null, 2));
console.log(`\nchanged ${migration.filesChanged.length}, added ${migration.filesAdded.length}, flags for a human ${migration.flags.length}. wrote proof/migration-report.json`);
