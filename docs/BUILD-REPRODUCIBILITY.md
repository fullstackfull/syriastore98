# Store Syria — Build Reproducibility: `composer install` from `composer.lock`

Owner: agent3-composer, branch `p1f/agent3-composer`, branched from `p1b/integration` @ `167c73ff`.
Continued on branch `x/a3-payhere`, branched from `p1b/integration` @ `e2c187ad`, which adds
§9 (the single blocker statement, and the two routes re-probed independently) and the storefront
`\Error` guard recorded there. `composer.json` and `composer.lock` are **still unchanged**.
Scope of this document: `composer.json`, `composer.lock`, and the installability of the locked
dependency set. Nothing else.

**Verdict: NOT FIXED. The lock cannot be made installable from inside this repository.**
The blocker is a deleted upstream repository, and every route to repair it requires either
removing a package that five live call sites still reference, or wiring in a supply source whose
provenance cannot be verified. Both are forbidden. **This is now an owner decision, stated in
full in "What the owner must decide" below.**

`composer.json` and `composer.lock` are **unchanged on this branch** — byte-identical to
`167c73ff`. That is a deliberate result, not an omission; the reasoning is in
"Why nothing was committed".

---

## 1. The failure

On a clean `ubuntu-latest` GitHub runner, `composer install` from this lock exits **100**:

```
Failed to download lahirulhr/laravel-payhere from dist:
"https://api.github.com/repos/Visanduma/laravel-payhere/zipball/72ce0aa87b495517e3b2d66633e588c86d417e61"
could not be downloaded (HTTP/2 404): {"message":"Not Found","status":"404"}
Source fallback is disabled. Not trying alternative sources.
```

The locked entry (`composer.lock`, package `lahirulhr/laravel-payhere`):

| Field | Value |
|---|---|
| version | `v1.0.4` |
| source | `https://github.com/Visanduma/laravel-payhere.git` |
| dist | `https://api.github.com/repos/Visanduma/laravel-payhere/zipball/72ce0aa87b4955…` |
| reference | `72ce0aa87b495517e3b2d66633e588c86d417e61` |
| shasum | *(empty string — there is no integrity digest to validate a recovered copy against)* |
| homepage | `https://github.com/lahirulhr/laravel-payhere` *(a different owner to the source)* |
| time | `2024-08-01T06:51:29+00:00` |

### This container cannot reproduce that 404, and its own failure proves nothing

In this container every GitHub dist URL is refused by the egress proxy, **including ones that
certainly exist**:

```
$ curl -sS -o /dev/null -D - "https://api.github.com/repos/laravel/framework/zipball/86693ffa…"
HTTP/1.1 403 Forbidden
$ curl -sS -o /dev/null -D - "https://codeload.github.com/laravel/framework/legacy.zip/86693ffa…"
HTTP/1.1 403 Forbidden
```

and the 403 body is the proxy's own text, not GitHub's:

```
{"message":"GitHub access to this repository is not enabled for this session. Use add_repo to
request access. …"}
```

**A local 403 is therefore uninformative about any package.** It does not refute the runner's
404 and it must never be cited as evidence that a package is fine. The diagnosis below rests on
evidence that is *differential* — it uses a known-good and a known-bad control on the same code
path, so a blanket policy denial cannot be mistaken for a finding.

---

## 2. The diagnosis

**The upstream Git repository for `lahirulhr/laravel-payhere` no longer exists under either
owner name the package has ever been published from.** It is not one force-pushed commit, and it
is not this container.

Four independent probes, each run here:

### (a) Differential `git ls-remote`, with both controls

`github.com` Git transport *is* reachable from this container unauthenticated (unlike
`api.github.com`), so this test discriminates where `curl` cannot. `~/.config/composer/auth.json`
holds **no** credentials for any host — every section is empty — so these requests are
unauthenticated, exactly like the clean runner's.

```
=== laravel/framework                      (positive control)
exit=0
84744cdc2f91310259f659346a20fd88de84854d	HEAD
74e222cee687f957d95aaadddae69270e3205cf7	refs/heads/10.x
2224c6721536295d5be931efd1d101b0f13d1706	refs/heads/11.x

=== Visanduma/laravel-payhere              (the locked source)
exit=128
fatal: could not read Username for 'https://github.com': terminal prompts disabled

=== lahirulhr/laravel-payhere              (the package homepage owner)
exit=128
fatal: could not read Username for 'https://github.com': terminal prompts disabled

=== laravel/definitely-no-such-repo-zzz99  (negative control)
exit=128
fatal: could not read Username for 'https://github.com': terminal prompts disabled
```

Both PayHere repositories are byte-for-byte indistinguishable from a repository that does not
exist, while a public repository on the same transport returns refs. GitHub answers a
nonexistent *and* a private repository with a credential prompt rather than a 404, so the
precise reading is **"deleted, or made private"** — either way it is unfetchable by this lock.

### (b) GitHub's own repository index contains neither owner

Searched with the session's real GitHub credentials, across all of GitHub:

```
query: laravel-payhere in:name     ->  total_count: 4
  laravel-payhere/laravel-payhere   18 stars  created 2024-01-14  updated 2026-10-06
  upathissa/laravel-payhere          0 stars  created 2020-11-06
  ashanda/laravel-payhere            0 stars  created 2026-08-11
  gosdrkht/payhere-laravel           0 stars  created 2026-05-13
```

Neither `Visanduma/laravel-payhere` nor `lahirulhr/laravel-payhere` is in the index.

### (c) No fork, mirror or re-publication of the source survives

```
query: "namespace Lahirulhr\PayHere" language:PHP   ->  total_count: 45
```

All 45 hits are inside **other projects' committed `vendor/` directories** —
`Mahabub-1997/Go-ERP_Lizu` at `main-file/vendor/lahirulhr/laravel-payhere/src/…` and
`Ahm-Nanzil/Aws-Portfolio` at `erpnbs/vendor/lahirulhr/laravel-payhere/src/…`. Not one hit is
the package's own repository. The code is *findable*; the *package* is not published anywhere.
Those vendored copies are **not** an acceptable supply source — see "What the owner must decide".

### (d) Every version on Packagist points at the same dead repository

Packagist still serves metadata for the package (metadata and dist are different hosts, and
**metadata being HTTP 200 does not mean a package can be downloaded** — that inference is the
one that previously led this project to expect PayHere to install on a runner). Re-queried
against live Packagist with an empty, isolated `COMPOSER_HOME`:

| version | released | source owner |
|---|---|---|
| `v1.0.4` (locked) | 2024-08-01 | `Visanduma` |
| `1.0.3` | 2024-07-03 | `Visanduma` |
| `1.0.2` | 2024-06-27 | `Visanduma` |
| `1.0.1` | 2024-06-25 | `Visanduma` |
| `v1.0.0` | 2024-06-24 | `Visanduma` |
| `0.2.1` | 2023-12-26 | `Visanduma` |
| `0.2.0` | 2023-12-26 | `Visanduma` |
| `v0.1.0` | 2022-01-06 | `lahirulhr` |
| `dev-main` | 2024-08-01 | `Visanduma` |
| `dev-dumithsalinda-patch-1`, `dev-form-detach` | — | *no source recorded* |

**There is no version to move to, and it fails on two independent grounds.** Everything from
`0.2.0` onward is hosted on the dead `Visanduma` repository. The single version on the
`lahirulhr` repository is `v0.1.0` from January 2022 — and that repository is **equally
unreachable** per probe (a). Even if it were reachable, its own requirements rule it out;
read from the same live Packagist metadata:

| version | `illuminate/contracts` | `php` | supports Laravel 11? |
|---|---|---|---|
| `v1.0.4` (locked) | `^9.0｜^10.0｜^11.0` | `^8.1` | **yes** |
| `0.2.0` | `^9.0` | `^8.0` | no — Laravel 9 only |
| `v0.1.0` | `^8.37` | `^7.4｜^8.0` | no — **Laravel 8 only** |

`v0.1.0` is also outside the root constraint `^1.0`. So the only version hosted anywhere other
than the dead repository cannot run on this platform at all: a downgrade is not a fallback here,
it is a second dead end.

### (e) Measured consequence: a version bump cannot fix this, and makes things worse

Run in an isolated scratch copy with `--no-install`, so no `vendor/` was created and no other
agent's tree was touched:

```
$ composer update lahirulhr/laravel-payhere --with-dependencies --no-install
Lock file operations: 0 installs, 9 updates, 0 removals
  - Upgrading guzzlehttp/guzzle (7.10.0 => 7.15.5)
  - Upgrading guzzlehttp/promises (2.3.0 => 2.5.3)
  - Upgrading guzzlehttp/psr7 (2.8.0 => 2.13.1)
  - Upgrading spatie/laravel-package-tools (1.16.6 => 1.93.3)
  - Upgrading symfony/deprecation-contracts (v3.6.0 => v3.7.1)
  - Upgrading symfony/polyfill-intl-idn (v1.33.0 => v1.43.0)
  - Upgrading symfony/polyfill-intl-normalizer (v1.33.0 => v1.43.0)
  - Upgrading symfony/polyfill-mbstring (v1.33.0 => v1.43.0)
  - Upgrading symfony/polyfill-php80 (v1.33.0 => v1.43.0)
```

`lahirulhr/laravel-payhere` **is not in that list.** It stays at `v1.0.4` on the identical dead
dist URL, while nine unrelated packages move — including a 77-minor jump in
`spatie/laravel-package-tools`. The single-package update is all cost and no benefit.

A **full** `composer update --no-install` behaves the same way: 152 packages move, and
`lahirulhr/laravel-payhere` is still `v1.0.4` with
`dist: https://api.github.com/repos/Visanduma/laravel-payhere/zipball/72ce0aa87b4955…`.
**No composer operation available in this repository changes that URL.**

### (f) PayHere is the only genuinely dead package — the "three unfetchable packages" account is wrong

All 239 locked `source.url` values were probed with `git ls-remote`:

```
236 REACHABLE
  3 UNREACHABLE
lahirulhr/laravel-payhere        v1.0.4  fatal: could not read Username for 'https://github.com'
yoomoney/yookassa-sdk-php        3.7.1   fatal: unable to access 'https://git.yoomoney.ru/scm/sdk/
                                         yookassa-sdk-php.git/': CONNECT tunnel failed, response 403
yoomoney/yookassa-sdk-validator  1.0.2   fatal: unable to access 'https://git.yoomoney.ru/scm/sdk/
                                         yookassa-sdk-validator-php.git/': CONNECT tunnel failed, 403
```

The two failure modes are **different and distinguishable**. The yoomoney pair fails with a
proxy `CONNECT` denial against `git.yoomoney.ru`, a host this container is not permitted to
reach at all; PayHere fails with GitHub's own credential prompt on a host that is reachable and
serves other repositories fine. Both yoomoney packages host their **dist** on `git.yoomoney.ru`
too —

```
yoomoney/yookassa-sdk-php  dist: https://git.yoomoney.ru/rest/api/latest/projects/SDK/repos/
                                 yookassa-sdk-php/archive?at=refs%2Ftags%2F3.7.1&format=zip
```

— which is exactly why they downloaded without error on the GitHub runner and fail here. **It is
one dead package, not three.** The confirmed count is: **1 dead upstream (PayHere), 2 reachable
only from outside this container (yoomoney), 236 reachable from here.**

---

## 3. Current runtime state: PayHere is already non-functional

This is not a future risk. The package is **already absent from the working `vendor/` tree**, so
the integration is already broken, and the smallest-change argument has to be made against that
reality rather than against a working integration.

```
$ ls vendor/lahirulhr/
ls: cannot access 'vendor/lahirulhr/': No such file or directory
```

239 locked / **235** installed. Six locked packages are not installed at all:
`lahirulhr/laravel-payhere`, `spatie/laravel-package-tools` (PayHere's own dependency),
`yoomoney/yookassa-sdk-php`, `yoomoney/yookassa-sdk-validator`, `paragonie/random_compat`,
`php-ds/php-ds`. Two installed packages are absent from the lock
(`staabm/side-effects-detector`, `symfony/polyfill-php82`).

The only copy of the package directory anywhere on this machine is **empty** — the install
created the directory and never extracted into it:

```
$ ls -la /home/user/syriastore98/vendor/lahirulhr/laravel-payhere/
total 8
drwxr-xr-x 2 root root 4096 Oct 11 20:52 .
drwxr-xr-x 3 root root 4096 Oct 11 20:52 ..
$ find … -type f | wc -l
0
```

So there is **no local source to vendor**, and with an empty `shasum` in the lock there would be
nothing to verify a recovered copy against either.

### The application boots; the failure is deferred to the call site

Probed against the project's real autoloader (read-only, no file written into the repository):

```
Lahirulhr\PayHere\PayHere                      class_exists=no
Lahirulhr\PayHere\PayHereFacade                class_exists=no
Lahirulhr\PayHere\PayHereServiceProvider       class_exists=no
Illuminate\Foundation\Application              class_exists=YES
App\Http\Controllers\PayHerePaymnetController  class_exists=YES
App\Services\PaymentService                    class_exists=YES

bootstrap/cache/packages.php: 44 discovered packages
PayHere in discovered providers: no
vendor/composer/installed.json has lahirulhr: no
```

Because the package was never installed, Laravel's package discovery never registered
`Lahirulhr\PayHere\PayHereServiceProvider`, so **class loading and application boot are
unaffected** — which is also the confirmation required of this work item: nothing here breaks
the bootstrap, and the whole Feature suite boots the framework successfully (§6).

### The deferred failure is an uncaught `Error`, not a graceful redirect

All five call sites wrap the call in `catch (\Exception $e)`:

| file | call | catch |
|---|---|---|
| `app/Services/PaymentService.php` | `:3486` | `:3491` `catch (\Exception $e)` |
| `app/Http/Controllers/PayHerePaymnetController.php` | `:89` | `:106` `catch (\Exception $e)` |
| `packages/workdo/Campaigns/src/app/Services/CampaignsPaymentService.php` | `:1470` | `catch (\Exception $e)` |
| `packages/workdo/Donation/src/app/Services/DonationPaymentService.php` | `:1478` | `catch (\Exception $e)` |
| `packages/workdo/LotteryPrizes/src/app/Services/LotteryPaymentService.php` | `:1480` | `catch (\Exception $e)` |

A missing class is raised as `Error`, which implements `Throwable` but does **not** extend
`Exception`, so none of those handlers catch it. Proven against this project's own autoloader
rather than asserted:

```
NOT caught by catch(\Exception); fell through to Throwable: Error
message: Class "Lahirulhr\PayHere\PayHere" not found
is instanceof Exception: NO
is instanceof Error    : yes
```

**Operational consequence:** with the current `vendor/`, every PayHere checkout path — storefront
order, plan purchase, Campaigns, Donation, LotteryPrizes — raises an uncaught `Error` and returns
a 500, *not* the "payment unavailable" redirect the surrounding code was written to produce. The
plan-purchase path is being fail-closed by agent1 this cycle; the other four are recorded as a
cross-agent dependency and are **not** touched here.

---

## 4. Why nothing was committed to `composer.json` or `composer.lock`

Every available route is closed, and each is closed for a reason that is recorded rather than
assumed:

| route | status |
|---|---|
| Bump to a newer version of the same package | **Impossible.** `v1.0.4` is the newest, and every version `>= 0.2.0` is on the dead repository (§2d, §2e). |
| Downgrade to `v0.1.0`, the only version on the other owner | **Impossible.** That repository is unreachable too (§2a), it is outside `^1.0`, and it predates Laravel 11 support. |
| Remove the package | **Forbidden and wrong.** Five files still `use Lahirulhr\PayHere\PayHere` (§3). Removing it from the lock would convert a recoverable outage into a permanent one and would silently bless the current 500s as intended behaviour. |
| Vendor the source into the repository | **Impossible here.** No copy exists on this machine (the one directory is empty), and the lock carries an empty `shasum`, so a recovered copy could not be integrity-checked. |
| Add a VCS/artifact repository pointing at a surviving third-party copy | **Forbidden.** The only copies found are inside unrelated applications' committed `vendor/` trees (§2c). That is an unverifiable supply source for payment-gateway code. |
| Substitute a different PayHere package | **Out of scope.** It changes the payment architecture and requires editing five files owned by other agents. Candidates are listed below for the owner, not acted on. |
| Apply the measured advisory-clearing update (§5) | **No reproducibility payoff.** It leaves the dead URL untouched, so `composer install` still fails; it would move 152 packages that cannot be installed or tested from here. |

The brief's own rule settles the last row and the audit work alike: apply a dependency change
only where a **real install in a scratch directory** can verify it. In this container that
verification is unavailable for *two* independent reasons, and neither is a matter of effort:

1. **Every GitHub dist URL returns 403** from the egress proxy (§1), so no dist can be fetched —
   not PayHere's, not any of the other 238.
2. **Disk is at 98%, with 831 MB free** (`df -h /` → `252G total, 39G used, 831M avail`). A
   239-package `vendor/` tree does not fit, and a `--prefer-source` install (the one transport
   that *is* reachable) would clone 239 Git histories into that space and fill the volume shared
   by all five agent worktrees.

So **no clean-checkout install was performed, and none is claimed.** The installability verdict
for the other 238 packages rests on §2f's repository-level probe, which is weaker than an install
and is labelled as such: it proves the repositories exist, not that every pinned commit within
them still resolves.

---

## 5. Security advisory triage (no change applied)

Measured on the unmodified lock, which needs no install:

```
$ composer audit --locked --format=summary
Found 70 security vulnerability advisories affecting 22 packages.
```

| package | advisories | | package | advisories |
|---|---|---|---|---|
| `league/commonmark` | 14 | | `aws/aws-sdk-php` | 1 |
| `guzzlehttp/guzzle` | 9 | | `firebase/php-jwt` | 1 |
| `phpoffice/phpspreadsheet` | 9 | | `league/flysystem` | 1 |
| `dompdf/dompdf` | 6 | | `livewire/livewire` | 1 |
| `phpseclib/phpseclib` | 5 | | `mtdowling/jmespath.php` | 1 |
| `guzzlehttp/psr7` | 4 | | `phpunit/phpunit` | 1 |
| `laravel/framework` | 4 | | `psy/psysh` | 1 |
| `symfony/yaml` | 3 | | `symfony/http-foundation` | 1 |
| `symfony/mime` | 2 | | `symfony/http-kernel` | 1 |
| `symfony/routing` | 2 | | `symfony/mailer` | 1 |
| | | | `symfony/polyfill-intl-idn` | 1 |
| | | | `symfony/process` | 1 |

Also abandoned: `mercadopago/sdk`, `rachidlaasri/laravel-installer`, `xendit/xendit-php-clients`
(the last suggests `xendit/xendit-php`).

### Measured: 66 of 70 clear with no `composer.json` change — and the residual is 4, not 5

A full `composer update --no-install` on the **unmodified** `composer.json`:

```
Found 4 security vulnerability advisories affecting 1 package.
```

All four are `laravel/framework`, and all four are fixed only above the 11.x line:

| advisory | severity | affected versions |
|---|---|---|
| `PKSA-d5tc-s1qs-h781` / CVE-2026-102279 — XSS in Debug Page Information | low | `>=13.0.0,<13.30.0｜<12.69.0` |
| `PKSA-m5cs-t1y6-qpcs` — Temporary Signed URL Path Confusion | medium | `<12.61.1｜>=13.0.0,<13.12.0` |
| `PKSA-3r5d-mb8f-1qw9` — CRLF injection in default email rule | high | `<12.60.0｜>=13.0.0,<=13.9.0` |
| `CVE-2026-48019` — CRLF injection in default email rule | unscored | `>=11.0.0,<12.0.0｜…｜>=12.0.0,<12.60.0` |

The last two are the same upstream advisory (`GHSA-5vg9-5847-vvmq`) listed twice. The fourth
row's range names the whole 11.x line with no carve-out: **there is no Laravel 11 release that
clears these**, and clearing them means a Laravel 12 major upgrade, which the owner's
preserve-the-platform restriction forbids. Classification: **ACCEPTED RISK — OWNER DECISION**.

Two corrections to previously recorded conclusions, each re-measured here:

- **`firebase/php-jwt` is not blocked.** It was recorded as unclearable because
  `laravel/socialite` requires `^6.4`. That is true of the *locked* `socialite v5.16.0`, but the
  **root** constraint is `^5.16`, which already admits `socialite v5.31.0`, whose requirement is
  `^6.4|^7.0`. The update therefore resolves `firebase/php-jwt v6.10.2 → v7.2.1` and the advisory
  clears **with no `composer.json` change**. Hence 66 of 70, not 65.
- **The update does not rely on dev branches.** It was recorded that 4 residual is reached "only
  by resolving `laravel/framework` to `11.x-dev` and `firebase/php-jwt` to `6.x-dev`".
  Re-measured today, it resolves to the **stable tags** `laravel/framework v11.57.0` and
  `firebase/php-jwt v7.2.1`, and introduces **zero** new dev pins:

  ```
  dev-pinned in BASE lock (1):  maatwebsite/excel 3.1.x-dev
  dev-pinned in UPD  lock (1):  maatwebsite/excel 3.1.x-dev
  NEW dev pins introduced by the full update: NONE
  ```

  `prefer-stable: true` with `minimum-stability: dev` prefers stable tags, and the stable
  releases that satisfy these constraints exist now. The earlier reading may have held when it
  was taken; it does not reproduce today.

**It is still not applied**, for the reason in §4: 152 packages move, the suite cannot be run
against the result from here, and — decisively — it does **not** make `composer install` succeed,
so it buys the blocking CI gate nothing.

### `NEW-AUDIT-05` confirmed, with the drift measured

`maatwebsite/excel` is constrained in `composer.json` as `"^3.1.x-dev"` — a **moving branch**, by
root constraint, with no tag. Measured drift between the committed lock and a fresh resolution:

```
base lock ref: 62616317c5ec07e885c5d7f6b537f57a7239c2ff   time 2024-11-25T18:41:59+00:00
upd  lock ref: 9b96de39355aa1faaaaf46ebbfe7e8e11efc8405   time 2026-08-13T10:52:35+00:00
REFERENCE MOVED: True
```

21 months of unreviewed upstream change are reachable through that constraint. The lock pins a
reference, so `composer install` is deterministic; `composer update` is not. A tagged constraint
is the fix, and it needs an install plus the Excel-export suite to verify — unavailable here.
Classification: **OPEN**. The phpspreadsheet refutation (`maatwebsite/excel` calls
`createReader($type)` then `$reader->load($path)` on a server-generated path, never
`IOFactory::load()`, so it is not attacker-influenced) is respected and not re-litigated; the 9
advisories remain counted, not waved away.

### One unrelated reproducibility defect, not fixed

```
$ composer validate --no-check-publish
./composer.json is valid, but with a few warnings
# General warnings
- require.myfatoorah/laravel-package : unbound version constraints (*) should be avoided
```

`"myfatoorah/laravel-package": "*"` admits any future major version on the next `composer
update`. Bounding it is a one-line change, but it alters `composer.json`, which invalidates the
lock's `content-hash` and requires a re-resolution this container cannot install or test — so by
the same rule as everything else above it is **recorded, not applied**.

---

## 6. What was verified, and what was not

| claim | how | result |
|---|---|---|
| The locked PayHere dist/source is unfetchable | differential `git ls-remote` with positive and negative controls; GitHub repo index; GitHub code search | **verified** (§2a–2c) |
| No other version of the package avoids the dead repository | live Packagist query, isolated empty `COMPOSER_HOME` | **verified** (§2d) |
| No composer operation changes the dead URL | `composer update <pkg> -W --no-install`, and a full `composer update --no-install` | **verified** (§2e) |
| PayHere is the only dead upstream of the 239 | `git ls-remote` on all 239 locked `source.url` | **verified** (§2f) |
| PayHere is already absent from `vendor/` and boot is unaffected | `installed.json` diff; `class_exists` probe; `bootstrap/cache/packages.php` | **verified** (§3) |
| The deferred failure escapes `catch (\Exception)` | executed against this project's autoloader | **verified** (§3) |
| 70 advisories → 4 with no `composer.json` change | `composer audit --locked` before and after `composer update --no-install` | **verified** (§5) |
| `composer install --dry-run` would catch this | run on the pristine lock | **verified it does NOT** — exits 0 and reports PayHere installable (§6) |
| `composer install` succeeds from a clean checkout | — | **NOT VERIFIED, AND NOT CLAIMED.** Impossible here: all GitHub dists 403, and 831 MB free disk. It will keep failing on PayHere until the owner decides. |
| Every *other* locked commit still resolves | — | **NOT VERIFIED.** §2f probes repositories, not commits. Only a real install on a runner can settle this. |

### A `--dry-run` is not a reproducibility check — it gives a false green

Worth knowing before anyone proposes it as a cheaper gate. On the **pristine, broken** lock:

```
$ composer install --dry-run --no-scripts --no-interaction
  - Installing lahirulhr/laravel-payhere (v1.0.4)
  …
rc=0
```

`--dry-run` exits **0** and reports the dead package as installable, because it resolves from the
lock and never downloads anything. The only check that catches this defect is a **real**
`composer install`, which is what Agent 5's gate does. (Confirmed harmless to run anywhere: it
created no `vendor/` — `ls: cannot access 'vendor': No such file or directory`.)

### Baseline regression check

`composer.json` and `composer.lock` are byte-identical to `167c73ff` and no code was touched, so
the suites must be unchanged — re-measured, not quoted:

```
$ vendor/bin/phpunit --testsuite Unit
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.
...................................................               51 / 51 (100%)
OK (51 tests, 279 assertions)

$ vendor/bin/phpunit --testsuite Feature
FAILURES!
Tests: 1106, Assertions: 6100, Failures: 8, Skipped: 17.
```

Identical to the inherited baseline on every count. PHPUnit omits the `Errors:` field when the
count is zero, so that summary line is the evidence for **0 errors**. The 8 failures are the
known stock Breeze scaffold set (the tail of this run is a `ProfileTest` failure on
`Attempt to read property "slug" on null` in `resources/views/partision/header.blade.php`); the
17 skips are the GATE F / undeclared-schema blockers. **Nothing was skipped, disabled, deleted
or weakened by this branch** — it adds one new documentation file and nothing else.

The Feature suite boots the full framework 1106 times with `lahirulhr/laravel-payhere` absent
from `vendor/`, which is the positive evidence required of this work item: nothing here breaks
class loading or application bootstrap.

---

## 7. Reproduce it in one command

Run from anywhere, including inside this container. It carries its own positive control, so a
blanket network denial cannot be mistaken for the finding:

```bash
for r in laravel/framework Visanduma/laravel-payhere lahirulhr/laravel-payhere; do \
  printf '%-34s ' "$r"; GIT_TERMINAL_PROMPT=0 git ls-remote "https://github.com/$r.git" \
  >/dev/null 2>&1 && echo REACHABLE || echo UNREACHABLE; done
```

Expected:

```
laravel/framework                  REACHABLE
Visanduma/laravel-payhere          UNREACHABLE
lahirulhr/laravel-payhere          UNREACHABLE
```

If the first line ever reads `UNREACHABLE`, the environment is blocking GitHub and the other two
lines mean nothing — re-run it somewhere with network access.

To confirm the end-to-end failure, on a machine that can reach GitHub dists:

```bash
composer install --no-interaction --no-scripts
```

Do **not** run that against a worktree in this container: the five `vendor/` trees are hardlinked
and an install would corrupt every other agent's test run.

---

## 8. What the owner must decide

`composer install` from `composer.lock` **cannot succeed on any machine** until one of these is
chosen. Agent 5's blocking `composer install` + PHPUnit CI gate stays **red** until then; that is
a correct signal, not a gate defect.

**Option 1 — recover the authentic package from a machine that already has it (preferred).**
The production host very likely has a populated `vendor/lahirulhr/laravel-payhere/` from before
the repository vanished. This is the same shape as the GATE F export: a read-only extraction a
sysadmin performs, with the result checksummed on arrival.
- Ask the sysadmin to confirm the directory is non-empty on production, then archive it and
  record `sha256sum` of the archive **and** of each file.
- Host it as a Composer `artifact` or `path` repository inside the owner's control, or on a
  private Satis/Toran mirror, pinned by checksum.
- Caveat to state plainly: the lock's `shasum` is **empty**, so a recovered copy can be
  checked for *internal* consistency and reviewed by eye, but **cannot** be cryptographically
  matched against what Packagist once served. The review must read the code — it handles payment
  hashes.
- This is the only option that preserves the integration and the five call sites unchanged.

**Option 2 — vendor the package into this repository.** Same provenance problem as Option 1 and
the same prerequisite (a copy must be obtained first), but it removes the external dependency
permanently. Costs: the project takes over maintenance of payment-gateway code, and the files
land under a path no current agent owns.

**Option 3 — migrate to a maintained PayHere package.** The only active candidate found is
`laravel-payhere/laravel-payhere` (18 stars, created 2024-01-14, last updated 2026-10-06).
**Provenance is unverified and I did not evaluate its code** — its repository is outside this
session's allowlist, so I could not read a single file. It is a *different* package name and
almost certainly a different namespace, so it would require editing all five call sites and
re-verifying the hash construction and the `checkOut()->data()->successUrl()->failUrl()
->renderView()` flow against PayHere's live sandbox. That is a payment-architecture change and
needs its own cycle with gateway credentials. **Listed as intelligence, explicitly not
recommended on this evidence.**

**Option 4 — retire PayHere deliberately.** Remove the package *and* the five call sites, and
remove the gateway from the merchant payment-settings UI so no store can enable a method that
cannot work. This is a product decision with store-facing consequences for Sri Lankan merchants,
and it must not be done as a side effect of a dependency cleanup. **Nothing on this branch takes
a step toward it.** Note that until something is decided, the gateway is still *offerable* in
settings while no attempt to use it can ever complete. As of §9 an attempt no longer 500s --
it is refused with a redirect-and-error at the storefront -- which removes the crash but does
**not** make the gateway work, and does not reduce the need for this decision.

**Do not choose "ignore it".** The options that look like ignoring it — pruning the package from
the lock, or adding `--ignore-platform-reqs`-style hand-waving — are the two that convert a
visible, recoverable outage into an invisible permanent one.

### Sequencing, once PayHere is settled

1. Land the PayHere decision and get one real green `composer install` from the lock on CI.
2. **Only then** apply the §5 advisory update, and let that same now-trusted gate verify it:
   70 → 4 advisories, no `composer.json` change, no new dev pins.
3. In the same pass, bound `myfatoorah/laravel-package` and give `maatwebsite/excel` a tagged
   constraint, each verified by the gate rather than by a resolver run.

Until step 1, every dependency number this project reports — including the 70, the suite counts
and this document's own baseline — comes from a `vendor/` tree that does not match the lock
(`laravel/framework 11.57.0` installed against `11.47.0` locked, 148 of 239 packages differing).
That is `NEW-VENDOR-DRIFT-01`, and it is unchanged by this work.

---

## 9. The blocker, in one statement — and the two supply routes, re-probed independently

Added on `x/a3-payhere`. Nothing here reverses §1–§8; it closes the two questions that were
still open about *alternative versions* and *alternative transports*, and records the one
question that cannot honestly be answered from this container.

### 9.1 The blocker statement

> **`composer install` from this `composer.lock` cannot be made to succeed by any change inside
> this repository.** The lock requires `lahirulhr/laravel-payhere` `v1.0.4`, whose dist is a
> GitHub zipball of `Visanduma/laravel-payhere` — a repository that returns **HTTP 404 on a
> clean `ubuntu-latest` runner**, i.e. it no longer exists upstream. **All eight published
> versions** of this package resolve to a zipball of the *same two* GitHub repositories, and
> **neither repository resolves over the Git transport either**, so there is no older version to
> fall back to and `--prefer-source` is not a route. **No published version carries a dist
> `shasum`** — the field is empty for all eight — so even a recovered or forked copy could not
> be integrity-verified against the lock by Composer. Therefore the only ways forward require an
> input from outside this repository: an authentic copy obtained from a machine that still has
> one (§8 Option 1/2), a deliberate migration (Option 3), or a deliberate retirement (Option 4).
> **Owner action required; this is not an agent-fixable defect.**

### 9.2 Every published version points at the same two dead repositories

From live Packagist metadata (`https://repo.packagist.org/p2/lahirulhr/laravel-payhere.json`,
HTTP 200, 6986 bytes — metadata is served from a host that is reachable; that says nothing about
whether a dist can be downloaded):

| version | dist (all `api.github.com/repos/…/zipball/`) | dist `shasum` |
|---|---|---|
| `v1.0.4` **(locked)** | `Visanduma/laravel-payhere` @ `72ce0aa8…` | *empty* |
| `1.0.3` | `Visanduma/laravel-payhere` @ `043d2467…` | *empty* |
| `1.0.2` | `Visanduma/laravel-payhere` @ `eead14ad…` | *empty* |
| `1.0.1` | `Visanduma/laravel-payhere` @ `0d32a3bc…` | *empty* |
| `v1.0.0` | `Visanduma/laravel-payhere` @ `e8f7c12e…` | *empty* |
| `0.2.1` | `Visanduma/laravel-payhere` @ `e5886224…` | *empty* |
| `0.2.0` | `Visanduma/laravel-payhere` @ `5626185e…` | *empty* |
| `v0.1.0` | `lahirulhr/laravel-payhere` @ `0c6b3e0f…` | *empty* |

Two consequences beyond §2(d):

1. **The empty `shasum` is the decisive argument against a fork or a mirror.** There is no
   digest anywhere in the lock for this package, so Composer cannot tell an authentic archive
   from an altered one. For a package that computes payment hashes, "download it from somewhere
   that looks right" is not an option, and the §8 Option 1 caveat applies to *every* route that
   does not start from a copy whose provenance a human has established.
2. The ownership split (`v0.1.0` under `lahirulhr`, `0.2.0`+ under `Visanduma`) means the
   package changed hands. If the `Visanduma` repository is gone, **seven versions die together**
   — this is not one force-pushed commit.

### 9.3 The Git transport does not resolve either, so `--prefer-source` is not a route

`--prefer-source` would be a legitimate install strategy if the repository existed. Re-run here
with `GIT_TERMINAL_PROMPT=0`, unauthenticated, with a positive **and** a negative control on the
same transport:

```
=== laravel/framework                      (positive control)
d63eca049e55b2d9b68a468802ff6ed7f70cc7ad	HEAD
74e222cee687f957d95aaadddae69270e3205cf7	refs/heads/10.x

=== Visanduma/laravel-payhere              (the locked source)
fatal: Authentication failed for 'https://github.com/Visanduma/laravel-payhere.git/'

=== lahirulhr/laravel-payhere              (the other owner, for v0.1.0)
fatal: Authentication failed for 'https://github.com/lahirulhr/laravel-payhere.git/'

=== laravel/definitely-no-such-repo-zzz99  (negative control)
fatal: Authentication failed for 'https://github.com/laravel/definitely-no-such-repo-zzz99.git/'
```

Both PayHere repositories are indistinguishable from the negative control, while a public
repository on the same transport returns refs. GitHub answers *nonexistent* and *private* alike,
so the precise reading stays **"deleted, or made private"** — and either way an unauthenticated
clean runner, which is what CI is, cannot clone it. **`--prefer-source` changes the transport,
not the existence of the repository.**

### 9.4 What this container cannot answer, and the honest route to the answer

`api.github.com` is refused by this container's egress proxy for **every** URL, including
repositories that certainly exist (§1 measured it; it was re-measured on this branch and the 403
body is the proxy's own text, not GitHub's). **A 403 here is not evidence in either direction and
must not be reported as any.** Only the clean runner's 404 is authoritative about existence.

One question therefore remains open and is worth exactly one bounded probe:

> Is the nearest older version, `1.0.3` (`zipball/043d2467…`), fetchable on a clean runner?

It is **unlikely** — it is a zipball of the same repository whose newer commit 404s, and a
repository 404 is not commit-specific — but it is cheap to settle and it is the only remaining
cell in the matrix. The honest route is the repo's existing `lockfile-reproducibility` job,
which already performs a clean-runner fetch; it must be run by the coordinator, because
`.github/**` is another agent's file ownership and **a probe workflow must not be added here**.
Two preconditions on any version change, before it is even attempted:

- **§2(e) measured that Composer will not move this package anyway**: `composer update
  lahirulhr/laravel-payhere --with-dependencies` moved nine *unrelated* packages and left
  PayHere at `v1.0.4` on the identical dead URL. A bump has to be forced, and forcing it is a
  lock edit, not a resolver result.
- **The call surface must survive it.** Five sites call
  `PayHere::checkOut()->data()->successUrl()->failUrl()->renderView()` (listed in §9.5). A
  version that silently drops that fluent API is worse than the current block, because it
  converts a build failure into a runtime failure on a payment path.

A further trap for anyone measuring this locally: this container's Composer cache
(`/root/.cache/composer`, ~6.8G) holds metadata for this package but **no dist** for it, while
satisfying most *other* packages. A scratch `composer install` can therefore go green from cache
and still fail on a clean runner. Any local reproducibility measurement must pass `--no-cache`
or point `COMPOSER_CACHE_DIR` at an empty directory, or it is not evidence.

### 9.5 Why the package is not removed from the lock

Removing it would be a fatal at class load, not a cleanup. The hard references are:

| file:line | reference |
|---|---|
| `app/Services/PaymentService.php:46` | `use Lahirulhr\PayHere\PayHere;` |
| `app/Services/PaymentService.php:3499` | `return PayHere::checkOut()` |
| `app/Http/Controllers/PayHerePaymnetController.php:12` | `use Lahirulhr\PayHere\PayHere;` |
| `app/Http/Controllers/PayHerePaymnetController.php:92` | `return PayHere::checkOut()` |
| `packages/workdo/Campaigns/src/app/Services/CampaignsPaymentService.php:45,1470` | `use` + `PayHere::checkOut()` |
| `packages/workdo/Donation/src/app/Services/DonationPaymentService.php:21,1478` | `use` + `PayHere::checkOut()` |
| `packages/workdo/LotteryPrizes/src/app/Services/LotteryPaymentService.php:21,1480` | `use` + `PayHere::checkOut()` |

`vendor/lahirulhr/` does not exist in the working tree (`ls` -> *No such file or directory*,
against 120 installed vendor directories), so these five call paths are **already** dead at
runtime. That is the state the §9.6 guard addresses.

### 9.6 The storefront no longer 500s on this — the build is still blocked

`app/Services/PaymentService.php:3435`, the first statement of `processPayHere` (declared at
`:3426`), now refuses PayHere checkout with a `class_exists()` guard, returning the same redirect-with-error the method already produced for every other
failure, and the block's `catch (\Exception $e)` is widened to `catch (\Throwable $e)`
(`\Error` is not an `\Exception`, which is why the uncaught crash reached the customer; the
file already used `catch (\Throwable)` in 15 other places). Held by
`tests/Feature/Payments/PayHereAbsentCheckoutGuardTest.php`.

**This is a crash fix, not a reproducibility fix.** `composer install` from this lock still
fails, the required CI `tests` job still dies at the same download, and PayHere still cannot
take a payment. The guard only ensures that the customer-facing symptom of the blocker is a
refusal rather than an HTTP 500 while the owner decides §8.

---

# §10. CLOSED — the owner retired the integration (2026-10-13)

**Status: RESOLVED. This section supersedes §8's four options; none of them was taken.**

The owner decided PayHere is not needed. The blocker is therefore closed by removing the
dependency rather than by recovering it.

## What was done

`composer remove lahirulhr/laravel-payhere --no-install --no-scripts` — composer itself, never a
hand-edit, so the lock's `content-hash` stays composer-written and `composer validate` stays green.

```
Lock file operations: 0 installs, 0 updates, 2 removals
lock 239 -> 237 entries   (packages 200 -> 198; packages-dev 39 unchanged)
```

The second removal, `spatie/laravel-package-tools`, is a legitimate transitive cascade and was
verified, not assumed: in the pre-change lock it was required by exactly one package (the PayHere
package, `^1.9.2`), it is absent from root `composer.json`, and no first-party or vendored file
references `LaravelPackageTools`. A sorted name+version diff of the whole lock is **two deletion
lines and zero additions** — no surviving package moved version.

## Evidence that the lock now resolves

Clean room: a fresh directory holding only `composer.json` and `composer.lock`, with
`COMPOSER_CACHE_DIR` pointed at a verified-empty directory so this container's ~6.8G Composer
cache cannot flatter the result.

```
composer install --prefer-dist --no-interaction --no-progress --no-scripts --dry-run
  Verifying lock file contents can be installed on current platform   -> passes
  Package operations: 237 installs, 0 updates, 0 removals
  exit 0; zero error/could-not/failed lines
  laravel/framework at the locked v11.47.0
  both yoomoney packages present; neither removed name in the plan
```

**Scope of that claim, stated precisely.** This proves the lock **resolves** and is
platform-compatible. It does **not** prove every dist still downloads. A real install needs ~1.5G
of `vendor/` against 1.3G free in this container, and dist fetchability cannot be measured from
here at all — `api.github.com` answers **403 to every URL** through this container's proxy, which
is evidence in neither direction. **CI's strict `tests` job, installing from this lock on a clean
runner, is the authoritative proof.**

## Laravel boots

`php artisan --env=testing about` exits 0 (Laravel 11.57.0 installed locally, env `testing`).

An earlier claim in this repository held that removing the package from the lock would fatal at
class load because five files `use Lahirulhr\PayHere\PayHere`. **That premise was wrong.** A `use`
statement is an alias and loads nothing; all five references are static calls inside method
bodies, none at boot. The package was already absent from `vendor/` before this change, and the
app booted then too — so removing it from the lock cannot have broken boot.

`bootstrap/cache/` is fully gitignored (`*` with `!.gitignore`), so no stale package manifest
reaches a clean runner, and the provider and `PayHere` alias were registered **only** by Composer
package auto-discovery (`extra.laravel`), never in `config/app.php`. Removal therefore leaves no
dangling provider reference.

## What is retired, and what is merely unselectable

- **Refusing, explicitly:** the two first-party call sites — `PaymentService::processPayHere()`
  and `PayHerePaymnetController::planPayWithPayHere()` — refuse because the integration is
  retired, as their first statement, before any session, attachment or database write, with
  `class_exists()` kept as a second line.
- **Contained by unavailability, NOT fixed:** the three addon services that call
  `PayHere::checkOut()` (`packages/workdo/Campaigns`, `Donation`, `LotteryPrizes`) are inside the
  guard-protected `packages/` tree and were not edited. They are unreachable because the provider
  is unselectable at every surface that offers it — not because those call sites were repaired.
  Reaching them already fatalled before this change.
- **Routes kept deliberately.** `resources/views/plans/stripe.blade.php:1871` renders
  `route('pay.payhere.payment')`, and its guard at `:1863` is `isset(...) == 'on'` — i.e.
  `true == 'on'`, which is **TRUE** — so that block renders whenever `payhere_merchant_secret` is
  non-empty, regardless of `is_payhere_enabled`. Deleting the route name would raise
  `RouteNotFoundException` on a page that currently loads. A refusing route beats a 404.

## Now-dead apparatus (deliberately left in place)

The degraded-install machinery built while the blocker was open — the `tests-degraded` job,
`--allow-missing`, `make-degraded-manifest.php`, `screen-install-failure.php` and the
`lockfile-reproducibility` job — is dead code once the strict install succeeds. It **self-retires
without reddening CI**: the install step emits a `degraded=false` notice when the strict install
succeeds. It is left for one green strict run to confirm the fix, and can be removed in a later,
separate commit. Two comments in it are now stale and should be corrected if it is touched: the
"committed-and-stale bootstrap cache" note (those files are untracked) and the
"Do NOT remove the package from the lock: five files use its classes" note (disproven above).
