// Starts the legacy PHP shop with PHP's built-in server.
// Run: node --env-file=.env.local scripts/run-legacy-shop.mjs
// Credentials come from the environment (loaded from .env.local by node); nothing is written into the shop code.
import { spawn } from "node:child_process";
import { existsSync, readdirSync } from "node:fs";
import path from "node:path";
import os from "node:os";

const port = process.env.SHOP_PORT || "8081";
const root = path.resolve(process.argv[2] || "samples/legacy-php-shop");

function findPhp() {
  if (process.env.PHP_BIN && existsSync(process.env.PHP_BIN)) return process.env.PHP_BIN;
  const pkgs = path.join(os.homedir(), "AppData", "Local", "Microsoft", "WinGet", "Packages");
  if (existsSync(pkgs)) {
    for (const d of readdirSync(pkgs)) {
      if (d.startsWith("PHP.PHP")) {
        const exe = path.join(pkgs, d, "php.exe");
        if (existsSync(exe)) return exe;
      }
    }
  }
  return "php"; // fall back to PATH (new shells, Linux, macOS, Docker)
}

const php = findPhp();
console.log(`legacy shop: http://localhost:${port}  (php: ${php === "php" ? "from PATH" : "winget install"})`);
const child = spawn(php, ["-S", `localhost:${port}`, "-t", root], {
  stdio: "inherit",
  env: { ADMIN_TOKEN: "cutover-demo-admin", ...process.env, SHOP_BASE_URL: `http://localhost:${port}` },
});
child.on("exit", (code) => process.exit(code ?? 0));
process.on("SIGINT", () => child.kill());
