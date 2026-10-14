# Cutover

**Migrate legacy PayPal code, and prove it still pays.**

Cutover is an AI migration agent. It reads an old PayPal integration (classic NVP API, `checkout.js` v4, IPN), rewrites it to the modern stack (Orders v2, the PayPal Server SDK, JS SDK v6, webhooks), and then **proves in the PayPal sandbox that the new code behaves like the old one**: a real browser pays, cancels and refunds through both versions, and PayPal itself is asked what happened.

> Built for the PayPal AI Hackathon. Sandbox only: fake buyer, fake money. This project is not affiliated with or endorsed by PayPal.

**Live demo:** _URL added after deploy_ &nbsp;|&nbsp; **Demo video:** _link added after upload_

## Why

Cutover day is the scariest day. Thousands of shops still run PayPal code written years ago, and PayPal keeps moving to newer APIs. Switching means finding every touchpoint, rewriting it, and then trusting that checkout still works, because a broken checkout means zero sales. AI can do the rewriting, but nobody should have to trust it. So Cutover does not ask for trust. It produces evidence.

## How it works

| Step | What happens | AI? |
| --- | --- | --- |
| 1. **Discover** | A deterministic scan finds every PayPal touchpoint (API calls, parameters, response fields, front-end script, IPN) and security risks (hardcoded credentials, open refund endpoint, weak IPN checks). Each item shows the legacy code, the modern equivalent and how sure the mapping is. Anything it cannot map is flagged for a human, never guessed. | No |
| 2. **Rewrite** | Gemini migrates the whole shop in one pass, grounded on PayPal's old-to-new mapping and on SDK facts verified in the sandbox. Output goes to a separate folder. Nothing is auto-merged. | Yes |
| 3. **Check and repair** | Automatic checks catch the AI's mistakes (syntax, leftover legacy code, **invented SDK methods**, contract and smoke tests, a real browser clicking the PayPal button). Failures are sent back to the model for repair, up to three rounds. | Yes |
| 4. **Prove** | The same seven scenarios run against the old and the migrated shop in a real browser as a sandbox buyer. After every payment PayPal's API confirms the status and amount. The two runs are diffed. | No |

## Results (recorded run)

- PayPal touchpoints in the shop: **43 to 0**
- Security findings: **9 to 1** (the remaining one, order data under the web root, is flagged for a human)
- Scenarios behaving identically on old and new, verified by PayPal: **7 of 7** (single item, multi-item, buyer cancels, refund, refund twice, replayed confirmation, empty cart)
- Cutover also retired the dead legacy client and flagged the open decisions in `proof/migration-report.json`.

### Mistakes the AI made and the checks caught

- Called `getNotificationsController()->verifyWebhookSignature()`, which does not exist in PayPal's PHP SDK (caught by an SDK guard that looks every controller up in the installed package).
- Awaited order creation before opening PayPal, so the browser blocked the checkout window (caught by a browser smoke test).
- Sent zero-quantity items that PayPal rejects, and returned HTML errors instead of JSON.
- Crashed the whole shop when an optional webhook setting was missing.
- Dropped the cart UI and cancel message (a cheaper fallback model).

### A trap only differential testing finds

The classic API is idempotent: replaying a payment confirmation returns the same transaction. Orders v2 refuses a second capture. A naive migration silently changes that behaviour. Cutover's replay scenario catches it, and the migrated shop keeps the original guarantee (no second charge).

## Try it

The demo site runs the report **and both shops live**: an old shop (`/legacy/`) and the migrated shop (`/shop/`), each with a working sandbox PayPal button. Pay with the sandbox buyer shown on the page and watch the payments appear in the live feed.

## Run it yourself

**You need:** Node 20+, PHP 8.2+ (with the `curl` extension), Composer, Chrome, a free [PayPal developer](https://developer.paypal.com) sandbox account, and a free [Gemini API key](https://aistudio.google.com/apikey).

```bash
git clone https://github.com/jeretc/cutover.git && cd cutover
npm install
cp .env.example .env.local          # then fill in your sandbox values (see below)
```

Sandbox setup: in the PayPal dashboard (Sandbox mode) create an app (client id and secret), use a **US Personal** sandbox account as the buyer, and copy the Business account's NVP/SOAP API credentials. Put them in `.env.local`. Never commit that file.

```bash
npm run start          # the demo site + both shops live on http://localhost:8080
```

Re-run the pipeline:

```bash
npm run discover       # scan the legacy shop            -> proof/discovery.json
npm run migrate        # AI rewrite + check/repair loop  -> samples/migrated-php-shop-out/
npm run migrated-shop  # (in another terminal) run the migrated shop on :8081
npm run prove -- migrated   # 7 scenarios in a real browser against the shop on SHOP_URL (default :8081)
                       # (run the old shop the same way with the label legacy-golden)
npm run diff           # compare proof/legacy-golden.json with proof/migrated.json
npm run build-web      # refresh the demo site data
```

Every AI answer is cached in `proof/ai-runs/`, so the recorded migration can be replayed without an API key.

## Repository layout

```
agent/      discover.mjs (scan), mappings.mjs + grounding.md (what the AI is grounded on),
            migrate.mjs (rewrite + repair loop), check.mjs (contract, smoke, hallucination guard)
samples/    legacy-php-shop (the "before"), migrated-php-shop-out (Cutover's output)
scripts/    scenarios.mjs (browser proof), diff-proof.mjs, run-legacy-shop.mjs, build-web-data.mjs
server/     server.mjs (one process: demo site + both shops + live feed)
web/        the demo site (AG Grid findings table, diff viewer, proof table)
proof/      recorded evidence: discovery, golden record, migrated run, migration report, AI runs
```

## Built with

- **PayPal:** Orders v2, [PayPal PHP Server SDK](https://github.com/paypal/PayPal-PHP-Server-SDK) (generated by APIMatic), JS SDK v6, Webhooks, the classic NVP API (for the legacy "before" shop)
- **AI:** Google Gemini (free tier) with a verify-and-repair loop
- **UI:** [AG Grid](https://www.ag-grid.com) Community for the findings table
- **Proof harness:** Playwright driving real Chrome

## Honest limits

- The recorded run covers one legacy stack (PHP, NVP Express Checkout, `checkout.js` v4, IPN). Other stacks need new mappings.
- The webhook handler is verified by contract checks and, once deployed, against a real sandbox webhook; card-payment (`DoDirectPayment`) and billing-agreement code are flagged for a human, not auto-migrated.
- Cutover produces a reviewable diff and report. A person approves; nothing is applied automatically.

## License

[MIT](LICENSE)
