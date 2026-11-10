# P2.5-CLOSURE — the printable order receipt

`tools/render-matrix.cjs --stub-print`, 4 runs, `GET order-receipt/{encrypted id}`.

**Read the capture count honestly: four files, two distinct documents.** The receipt carries no
`data-ss-theme` and takes its direction from the **order's own store**, not from the viewer — that
is the P1-SEC-11 fix, not an oversight. So the light/dark axis does not apply to this surface at
all, and the two theme runs per direction are the same document photographed twice. The matrix
claims the row on all four files existing, and this paragraph is why that is not a claim of four
distinct readings.

Measured on the served page: `lang="en"` (store 1's `default_language`), `dir` from store 1's own
`SITE_RTL` row, `data-ss-theme` **absent**, body background transparent. `lang` and `dir` are
independent settings and the harness writes only the second, which is why they can legitimately
disagree in a fixture run.

---

## It was never a permission problem, which is what every previous run reported

Every attempt reported `HTTP 200 ... landed on /dashboard`, and the harness's premise note read it
as *"(redirect — likely a permission refusal)"*. It took proving four separate things from inside
the running request to establish that nothing was refused:

| checked | result |
|---|---|
| does the token decrypt? | yes — `Crypt::decrypt` → `'1'` |
| does the order resolve? | yes — `Order::find` found it, `DB::table('orders')->where('id',1)->count()` = 1 |
| does `order_detail` report not-found? | no — `message` is `(none)` |
| is the handler entered, and does it throw? | entered all four times with the full 200-character token, and the catch-all logged nothing |

The handler returns the view correctly. **The navigation is the document's own, on the client,
after the response.** `order/receipt.blade.php:293-300`:

```js
window.print();
window.onafterprint = back;
function back() { window.close(); window.history.back(); }
```

In a headless browser `window.print()` returns immediately, `onafterprint` fires, and the page
navigates **back** before anything can be measured or photographed. An earlier hand probe that read
the URL right after `domcontentloaded` saw the 200 and the correct path — because it looked before
the handler fired. The harness waits 450ms, which is long enough for it.

`render-matrix.cjs --stub-print` replaces `window.print` with a no-op through
`page.addInitScript`, so it runs **before** the document's own scripts and `onafterprint` never
fires. Nothing about the document is altered — only the automation's inability to put a print
dialog in front of it. The flag is opt-in, because a page that does not print itself should not
have its print path quietly stubbed.

## Two things this left behind, deliberately

**The catch-all now logs.** `order_receipt` ends `catch (\Exception $e) { return
redirect()->back()->with('error', __('Something was wrong.')); }`, which is indistinguishable from
a forged id, a missing order and a broken view. Every check in the table above was needed only
because the handler threw the reason away. It still redirects with the same message — a merchant
sees exactly what they saw before, and never a stack trace — but the log now carries the class,
message and location. A caught exception that leaves no trace is a defect in its own right, and
this one is on a document a merchant prints for a customer.

**The encrypted id is generated, never hand-built.** `order-receipt/{id}` opens
`Crypt::decrypt($id)` and the list screens link it with `Crypt::encrypt($order->id)`. A plain
integer is not a shorter spelling of the same thing: it raises a caught `DecryptException` and
redirects, which is exactly the false "permission refusal" reading recorded in
`probe-package-c-reachability.cjs`. `CLAUDE.md` records that `APP_KEY` is in git history and
unrotated, so this is an **encoding and not a control** — a recorded security finding, and not this
harness's business. What matters here is only that it is the URL format the screen requires.

## Reproducing

```bash
docs/development/phase-2.5/tools/serve-dev.sh
```

```bash
ENC=$(DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file SESSION_DRIVER=file php -r 'require "vendor/autoload.php"; $a = require_once "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $p = new PDO("sqlite:" . __DIR__ . "/database/p2dev.sqlite"); echo Illuminate\Support\Facades\Crypt::encrypt((string) (int) $p->query("SELECT MIN(id) FROM orders WHERE store_id = 1")->fetchColumn());')
```

```bash
node docs/development/phase-2.5/tools/render-matrix.cjs --out <dir> --paths "/order-receipt/$ENC" --stub-print
```
