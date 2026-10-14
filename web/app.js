const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
const chip = (v) => (v ? `<span class="chip ${esc(v)}">${esc(v)}</span>` : "");

// ---- live demo (only works when served by server/server.mjs; hides itself otherwise) ----
fetch("/api/demo-buyer").then((r) => r.json()).then((b) => {
  if (!b.email) return;
  $("#b-email").textContent = b.email; $("#b-pass").textContent = b.password; $("#buyer").hidden = false;
  document.querySelectorAll("[data-copy]").forEach((btn) => btn.onclick = () => { navigator.clipboard?.writeText($("#" + btn.dataset.copy).textContent); btn.textContent = "Copied"; setTimeout(() => (btn.textContent = "Copy"), 1200); });
}).catch(() => {});
function pollFeed() {
  fetch("/api/recent-orders").then((r) => r.json()).then((rows) => {
    $("#feed").hidden = false;
    $("#feed-body").innerHTML = rows.length
      ? `<table><thead><tr><th>Shop</th><th>Amount</th><th>Status</th><th>Transaction</th></tr></thead><tbody>${rows.map((o) => `<tr><td>${esc(o.shop)}</td><td>$${esc(o.amount)}</td><td><span class="chip ${o.status === "PAID" ? "ok" : o.status === "REFUNDED" ? "medium" : "inferred"}">${esc(o.status)}</span></td><td>${esc(o.transaction || "-")}</td></tr>`).join("")}</tbody></table>`
      : `<div class="empty">No payments yet. Open a shop, pay with the sandbox buyer, and it appears here.</div>`;
  }).catch(() => {});
}
pollFeed(); setInterval(pollFeed, 4000);

fetch("data/cutover.json").then((r) => r.json()).then((d) => {
  const s = d.summary;
  $("#board").innerHTML = `<h3>Go / no-go board</h3>` + [
    ["Discover", "every touchpoint found", `${s.before.touchpoints}<s>→</s>${s.after.touchpoints}`],
    ["Security", "findings after migration", `${s.before.security}<s>→</s>${s.after.security}`],
    ["Check and repair", "AI mistakes sent back and fixed", `${d.mistakes.length}`],
    ["Prove", "scenarios identical, PayPal-verified", `${s.identical}/${s.scenarios}`],
  ].map(([t, sub, n]) => `<div class="row"><i class="lamp"></i><div><strong>${t}</strong><span class="d">${sub}</span></div><div class="n">${n}</div></div>`).join("");

  // ---- AG Grid, themed to the board ----
  const dark = matchMedia("(prefers-color-scheme: dark)").matches;
  document.body.dataset.agThemeMode = dark ? "dark" : "light";
  const theme = agGrid.themeQuartz.withParams({ fontFamily: "IBM Plex Mono, monospace", fontSize: 13, accentColor: "#ff5a1f", borderRadius: 0, wrapperBorderRadius: 0, headerFontWeight: 600, backgroundColor: "#f4f5f7", foregroundColor: "#0b0f14", headerBackgroundColor: "#e6e8eb", borderColor: "#c3c8d0" }, "light")
    .withParams({ fontFamily: "IBM Plex Mono, monospace", fontSize: 13, accentColor: "#ff5a1f", borderRadius: 0, wrapperBorderRadius: 0, headerFontWeight: 600, backgroundColor: "#12161b", foregroundColor: "#eceff3", headerBackgroundColor: "#1d242d", borderColor: "#2a313a" }, "dark");
  const sevOrder = ["critical", "high", "medium", "low"];
  const tpCols = [
    { field: "id", headerName: "ID", width: 80 },
    { field: "file", headerName: "File", flex: 1.1, minWidth: 150 },
    { field: "line", headerName: "Line", width: 80 },
    { field: "legacy", headerName: "Today (legacy)", flex: 1.3, minWidth: 190 },
    { field: "modern", headerName: "Becomes (modern)", flex: 2, minWidth: 240, tooltipField: "modern" },
    { field: "confidence", headerName: "Mapping", width: 130, cellRenderer: (p) => chip(p.value) },
    { field: "risk", headerName: "Risk", width: 100, cellRenderer: (p) => chip(p.value) },
  ];
  const secCols = [
    { field: "id", headerName: "ID", width: 80 },
    { field: "severity", headerName: "Severity", width: 120, cellRenderer: (p) => chip(p.value), comparator: (a, b) => sevOrder.indexOf(a) - sevOrder.indexOf(b), sort: "asc" },
    { field: "file", headerName: "File", flex: 1, minWidth: 150 },
    { field: "title", headerName: "Finding", flex: 1.5, minWidth: 220, tooltipField: "why" },
    { field: "why", headerName: "Why it matters", flex: 2.2, minWidth: 280, wrapText: true, autoHeight: true },
    { field: "fix", headerName: "Fix", flex: 2, minWidth: 260, wrapText: true, autoHeight: true },
  ];
  const api = agGrid.createGrid($("#grid"), { theme, columnDefs: tpCols, rowData: d.touchpoints, tooltipShowDelay: 300, defaultColDef: { sortable: true, resizable: true, filter: true } });
  $("#c-tp").textContent = `(${d.touchpoints.length})`; $("#c-sec").textContent = `(${d.security.length})`;
  const pick = (which) => {
    $("#tab-tp").setAttribute("aria-selected", which === "tp"); $("#tab-sec").setAttribute("aria-selected", which === "sec");
    api.setGridOption("columnDefs", which === "tp" ? tpCols : secCols);
    api.setGridOption("rowData", which === "tp" ? d.touchpoints : d.security);
  };
  $("#tab-tp").onclick = () => pick("tp"); $("#tab-sec").onclick = () => pick("sec");
  $("#q").oninput = (e) => api.setGridOption("quickFilterText", e.target.value);

  // ---- diff viewer ----
  const list = $("#files");
  list.innerHTML = d.files.map((f, i) => `<button class="file" data-i="${i}"><span class="tag ${f.status}">${f.status}</span><code>${esc(f.path)}</code><small>+${f.added} −${f.removed}</small></button>`).join("");
  const show = (i) => {
    const f = d.files[i];
    list.querySelectorAll(".file").forEach((b, k) => b.setAttribute("aria-current", k === i));
    $("#notes").innerHTML = f.notes.length ? `<b>What changed:</b> ${f.notes.map(esc).join(" · ")}` : f.status === "removed" ? "Retired: the legacy NVP client is no longer referenced by any file." : "Rewritten by the agent.";
    let html = "";
    for (const op of f.ops) for (const ln of op.v.replace(/\n$/, "").split("\n"))
      html += `<div class="${op.t === "+" ? "a" : op.t === "-" ? "d" : ""}"><span class="n">${op.t === " " ? " " : op.t === "-" ? "−" : "+"}</span>${esc(ln) || " "}</div>`;
    $("#code").innerHTML = html;
  };
  list.onclick = (e) => { const b = e.target.closest(".file"); if (b) show(+b.dataset.i); };
  show(Math.max(0, d.files.findIndex((f) => f.path === "api/execute-payment.php")));
  $("#flags").innerHTML = `<h3>Left for a human to decide</h3><ul>${d.flags.map((f) => `<li>${esc(f)}</li>`).join("")}${d.securityAfter.map((x) => `<li>${esc(x.title)} (${esc(x.file)}): ${esc(x.fix)}</li>`).join("")}</ul>`;

  // ---- mistakes ----
  $("#mist").innerHTML = d.mistakes.map((m) => `<article class="m"><h3>${esc(m.what)}</h3><p>${esc(m.detail)}</p><p><b>Caught by</b>${esc(m.caught)}</p><p class="v"><b>Fixed</b>${esc(m.fixed)}</p></article>`).join("");

  // ---- proof ----
  const out = (o) => (o ? Object.entries(o).map(([k, v]) => `${esc(k)}: ${esc(v)}`).join(" · ") : "");
  $("#proof").innerHTML = `<thead><tr><th>Scenario</th><th>Old shop</th><th>Migrated shop</th><th>Verdict</th></tr></thead><tbody>` + d.scenarios.map((s) => {
    const same = JSON.stringify(s.legacy.outcome) === JSON.stringify(s.migrated.outcome) && s.legacy.pass && s.migrated.pass;
    return `<tr><td data-l="Scenario"><div class="id">${esc(s.id)}</div><div class="t">${esc(s.title)}</div></td><td data-l="Old shop" class="o">${out(s.legacy.outcome)}</td><td data-l="Migrated shop" class="o">${out(s.migrated.outcome)}</td><td data-l="Verdict"><span class="go ${same ? "" : "no"}">${same ? "GO · IDENTICAL" : "NO-GO"}</span></td></tr>`;
  }).join("") + `</tbody>`;
});
