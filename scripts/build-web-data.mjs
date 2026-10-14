// Builds web/data/*.json from the real recorded run (proof/ + the legacy and migrated shop folders).
// Run: node scripts/build-web-data.mjs
import fs from "node:fs";
import path from "node:path";
import { diffLines } from "diff";
import { listFiles } from "../agent/discover.mjs";

const LEG = "samples/legacy-php-shop", MIG = "samples/migrated-php-shop-out", OUT = "web/data";
fs.mkdirSync(OUT, { recursive: true });
const J = (f) => JSON.parse(fs.readFileSync(f, "utf8"));
const rel = (root, p) => path.relative(root, p).split(path.sep).join("/");
const rd = (root, f) => (fs.existsSync(path.join(root, f)) ? fs.readFileSync(path.join(root, f), "utf8").split(String.fromCharCode(13)).join("") : null);

const before = J("proof/discovery.json"), after = J("proof/discovery-after.json"), report = J("proof/migration-report.json");
const legacy = J("proof/legacy-golden.json"), migrated = J("proof/migrated.json");

// file diffs
const lf = listFiles(LEG).map((p) => rel(LEG, p)), mf = listFiles(MIG).filter((p) => !p.includes("vendor")).map((p) => rel(MIG, p));
const files = [];
for (const f of [...new Set([...lf, ...mf])].sort()) {
  const a = rd(LEG, f), b = rd(MIG, f);
  if (a === b) continue;
  const status = a === null ? "added" : b === null ? "removed" : "changed";
  const ops = diffLines(a || "", b || "").map((d) => ({ t: d.added ? "+" : d.removed ? "-" : " ", v: d.value }));
  const added = ops.filter((o) => o.t === "+").reduce((n, o) => n + o.v.split("\n").length - 1, 0);
  const removed = ops.filter((o) => o.t === "-").reduce((n, o) => n + o.v.split("\n").length - 1, 0);
  const note = [...report.filesChanged, ...report.filesAdded].find((x) => x.path === f)?.changes || [];
  files.push({ path: f, status, added, removed, ops, notes: note });
}

const mistakes = [
  { what: "Invented a PayPal SDK method", detail: "The AI called getNotificationsController()->verifyWebhookSignature(). That method does not exist in PayPal's PHP SDK.", caught: "SDK guard: every controller the code calls is looked up in the installed package.", fixed: "Rewritten with PayPal's REST verify-webhook-signature endpoint." },
  { what: "Broke the checkout button", detail: "It awaited the order creation before opening PayPal, so the browser blocked the PayPal window.", caught: "Browser smoke test: a real Chrome clicks the button and the PayPal window must open.", fixed: "start() is now called synchronously with the order promise." },
  { what: "Sent items PayPal rejects", detail: "Every product went to PayPal, including quantity 0. PayPal refuses those, and the error came back as an HTML page.", caught: "Contract check with the cart the page really sends.", fixed: "Zero-quantity items skipped; errors returned as JSON." },
  { what: "Crashed the whole shop", detail: "config.php stopped on load when an optional webhook setting was missing, so every page showed an error.", caught: "Smoke check of every endpoint.", fixed: "Optional settings are only required where they are used." },
  { what: "Dropped the cart UI", detail: "A cheaper fallback model deleted the quantity inputs and the cancel message.", caught: "Contract check: the page must keep its cart inputs and cancel handling.", fixed: "Restored by the repair loop." },
];

const data = {
  generatedAt: new Date().toISOString(),
  summary: {
    before: { touchpoints: before.summary.touchpoints, security: before.summary.security, bySeverity: before.summary.bySeverity },
    after: { touchpoints: after.summary.touchpoints, security: after.summary.security, bySeverity: after.summary.bySeverity },
    files: { changed: files.filter((f) => f.status === "changed").length, added: files.filter((f) => f.status === "added").length, removed: files.filter((f) => f.status === "removed").length },
    scenarios: Object.keys(legacy.scenarios).length,
    identical: Object.keys(legacy.scenarios).filter((id) => JSON.stringify(legacy.scenarios[id].outcome) === JSON.stringify(migrated.scenarios[id]?.outcome)).length,
    models: [...new Set(report.calls.map((c) => c.model))],
    repairRounds: report.calls.filter((c) => c.repair).length,
  },
  touchpoints: before.touchpoints, security: before.security, securityAfter: after.security,
  files, flags: report.flags.map((f) => f.flag), removed: report.filesRemoved || [],
  rounds: report.rounds, mistakes,
  scenarios: Object.entries(legacy.scenarios).map(([id, s]) => ({ id, title: s.title, legacy: { pass: s.pass, summary: s.summary, outcome: s.outcome }, migrated: { pass: migrated.scenarios[id]?.pass, summary: migrated.scenarios[id]?.summary, outcome: migrated.scenarios[id]?.outcome } })),
};
fs.writeFileSync(`${OUT}/cutover.json`, JSON.stringify(data));
console.log(`wrote ${OUT}/cutover.json  (${(fs.statSync(`${OUT}/cutover.json`).size / 1024).toFixed(0)} KB)`, JSON.stringify(data.summary));
