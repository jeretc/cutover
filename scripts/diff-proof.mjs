// Compares the golden record of the legacy shop with the migrated shop run, scenario by scenario.
// Run: node scripts/diff-proof.mjs [legacy.json] [migrated.json]
import fs from "node:fs";
const [a, b] = [process.argv[2] || "proof/legacy-golden.json", process.argv[3] || "proof/migrated.json"].map((f) => JSON.parse(fs.readFileSync(f, "utf8")));
let diffs = 0;
for (const id of Object.keys(a.scenarios)) {
  const x = a.scenarios[id], y = b.scenarios[id];
  const same = y && JSON.stringify(x.outcome) === JSON.stringify(y.outcome) && x.pass === y.pass;
  if (!same) diffs++;
  console.log(`${same ? "SAME " : "DIFF "} ${id}  ${x.title}`);
  if (!same) { console.log("   legacy  :", JSON.stringify(x.outcome), x.pass ? "" : "(failed)"); console.log("   migrated:", JSON.stringify(y?.outcome), y?.pass ? "" : "(failed)"); }
}
console.log(`\n${Object.keys(a.scenarios).length - diffs}/${Object.keys(a.scenarios).length} scenarios behave identically`);
process.exit(diffs ? 2 : 0);
