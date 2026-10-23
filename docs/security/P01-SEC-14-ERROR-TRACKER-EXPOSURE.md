# P1-SEC-14 — the third-party error tracker, and what only the server can tell us

*Written 2026-10-23. Implements the owner's decision of the same date. Everything below is either
executed here or marked explicitly as **not verifiable from this repository**.*

---

## 1. The owner's decision, and how each clause is implemented

> *"I do not know whether LB_KEY and LB_PROJECT_KEY are configured in production, and I do not need
> to make that technical determination. Verify their presence securely without exposing their
> values. My decision is to disable outbound LaraBug reporting in production until sensitive-data
> protection is verified. Preserve safe local error logging. Do not send passwords, cookies,
> sessions, payment credentials, card information or full authenticated user objects to external
> error trackers. Document any historical exposure requiring server investigation."*

| clause | how it is implemented | state |
|---|---|---|
| verify key presence **without exposing values** | checked by presence and length only; no value printed, logged or committed | **done — and the answer is "not determinable here", see §2** |
| **disable outbound reporting** until protection verified | `larabug.outbound_enabled`, default **false**; `ScrubbedLaraBug::mayReportOutbound()` requires a configured account **and** that switch | **done** |
| **preserve safe local error logging** | the `stack` channel is `['single','larabug']`; `single` writes to `storage/logs/laravel.log` before the tracker is reached. The refusal happens in the handler, **not** by removing the channel | **done, and asserted** |
| never send passwords / cookies / sessions / payment credentials / card data | `config/larabug.php` blacklist covers `*password*`, `*cookie*`, `*session*`, `*remember*`, `*secret*`, `*key*`, `*card*`, `*cc_*`, `*number*`, `*expir*`, `*cvv*`, `*otp*`, `pin`, `*signature*`, `*csrf*`, `*bearer*`, `*token*`, `*iban*` — plus value-level scrubbing of the message, trace and URL | **done** |
| never send **full authenticated user objects** | `User::toLarabug()` returns `['id','type','created_by']` — not the model | **done** |
| document historical exposure needing server investigation | §2 and §3 below | **done** |

---

## 2. The key question, answered as precisely as this container allows

**`.env` is not present in this container.** That is the honest finding, and it is why the owner's
instruction to "verify securely" cannot be completed here rather than merely being inconvenient.

What *can* be stated:

```
larabug.login_key     = EMPTY   (in this environment)
larabug.project_key   = EMPTY   (in this environment)
larabug.environments  = [production]
logging.default       = stack
stack channels        = [single, larabug]
```

No value was printed, logged or written anywhere, here or in the test suite.

**The mechanism that makes the production answer matter is verified, and it is counter-intuitive:**

> `vendor/larabug/larabug/src/Http/Client.php:38-49` **POSTs whether or not a key is set.** It checks
> neither `login_key` nor `project_key`; an unconfigured instance sends
> `Authorization: Bearer ` with an empty token to `https://www.larabug.com/api/log`.

So **"no key" was never an off switch** — it was an unauthenticated send. That is precisely why the
owner's decision is implemented as a *separate* switch rather than as "leave the key unset".

---

## 3. Historical exposure — **requires server investigation, not answerable here**

This section exists because the owner asked for it, and it is deliberately phrased as questions for
whoever has server access rather than as conclusions.

**What would have been transmitted, per reported exception, before the protections landed:** the
full URL with its query string, the **decrypted** cookies (including the session id and any
`remember_*`), the raw `cookie` header, `Session::all()`, `Request::all()`, old input, and
`auth()->user()->toArray()` — and `executor`, the twelve source lines either side of the throw.

**Triggering requests** would have been any 500 on a card POST, on a settings save carrying gateway
secrets, or on any authenticated request.

### What to check on the server

1. **Was `LB_KEY` / `LB_PROJECT_KEY` ever set in production?**
   `grep -c '^LB_KEY=' .env` — the **count**, never the value.
2. **Was `APP_ENV=production`?** The tracker is gated on `larabug.environments = ['production']`.
3. **Was there outbound traffic to `www.larabug.com`?** Egress logs, or the firewall's allow-list.
   This is the decisive evidence: if that host was never reachable, nothing left regardless of keys.
4. **If 1–3 are all yes**, then the window is from whenever the key was set until this change
   deploys, and the exposed categories are those listed above.

### What is accepted rather than remediated

Per the owner's standing decisions, recorded here so the distinction is not lost:

- **Keys are not rotated at this time.** Any credential that *was* transmitted historically remains
  as it was. This is **accepted risk, not remediation.**
- **No production operation is performed from here** — no `.env` read, no log fetch, no egress test.

**If check 3 shows real outbound traffic, this stops being accepted risk and becomes a confirmed
disclosure requiring escalation.** It is not closed by this document.

---

## 4. What was executed

```
php artisan test tests/Feature/Security/LaraBugPayloadTest.php    78 passed (175 assertions)
```

**Removal checks — 5 mutants, 5 bite:**

| | mutant | outcome |
|---|---|---|
| L1 | the outbound switch is removed from the gate | BITES |
| L2 | the switch defaults to ON | BITES |
| L3 | the gate falls open when the key is absent from config | **SURVIVED**, then BITES |
| L4 | the local log channel is dropped (the wrong way to disable) | BITES |
| L5 | the unkeyed gate is removed, leaving only the switch | BITES |

**L3 is the one worth recording.** It models a real deployment: `php artisan config:cache` writes one
compiled array, so an instance still running a cache built *before* this change **has no
`outbound_enabled` key at all**, and `config()`'s own default is the only thing deciding.

The first attempt to test that used `config()->offsetUnset()`, and the mutant still survived —
because Laravel's config `Repository::offsetUnset()` calls `set($key, null)`; it does **not** forget
the key. So the test was exercising `(bool) null`, which is false on its own, and the in-code default
was never consulted. The test now rewrites the whole `larabug` array without the key, which is the
shape a stale config cache really has.

**And a false reading avoided:** the mutation harness replaces the *first* occurrence of its anchor,
and the first occurrence of `config('larabug.outbound_enabled', false)` is in the **log context**,
not the gate — so the first L3 run mutated a log line and proved nothing. Re-anchored on the whole
`return` statement.

---

## 5. What is NOT claimed

- **Not claimed:** that nothing was ever transmitted. See §3 — that needs server access.
- **Not claimed:** that every secret is covered. The blacklist is a key-name glob plus value-level
  scrubbing; a secret under a key nobody listed, in a payload field that is not a request variable,
  would still go out **if outbound were enabled**. The outbound switch is what makes that residual
  moot today, and it is why re-enabling has a precondition rather than being a config edit.
- **Before `LARABUG_OUTBOUND_ENABLED=true` is ever set**, the payload must be inspected against a
  real exception on a real card POST and shown to carry none of the categories in §1.
