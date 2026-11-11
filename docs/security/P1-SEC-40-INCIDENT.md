# P1-SEC-40 — tracked database dump in a public repository

**Issue:** #120 · **Opened as an incident:** 2026-11-11 · **Status:** partially contained, two
remediation steps blocked on owner authorization

> This document records counts, hashes and structure. It deliberately contains **no password
> hash, no email address, no IP address and no row content** from the dump. The dump was not
> copied, printed, uploaded or sent anywhere during this work.

---

## 1. Summary

A MySQL dump of the production database, `storesyria_ecommercego.sql`, was committed to the
repository on **2026-10-24** and the repository is **public** (owner-confirmed). The dump carries
93 bcrypt password hashes and personal data for customers, merchants and drivers.

**Reviewing download exposure found something worse than the dump, and it is what this document
is really about.** The live web server serves its own `.git` directory, including
`objects/info/packs`, which names the packfiles. The deployed clone is a full clone of `main`. The
repository's **root commit contains a committed `.env`** carrying the production `APP_KEY`, three
`DB_*` variables and a mail-or-gateway password.

Those facts compose into a single conclusion:

> **The production `APP_KEY` and database credentials are retrievable over plain HTTP from
> `https://storesyria.com/.git/` by any anonymous visitor.** No GitHub access, no credentials, no
> knowledge of any filename, and no skill beyond an off-the-shelf `.git` dumper.

The exposure window for that is not the dump's 18 days — it runs from whenever the site was first
deployed from this repository. The committed `.env` is in the root commit, dated **2026-08-27**.

This reorders the incident. Live credentials in an attacker's hands make the leaked password
hashes close to irrelevant: database credentials grant the whole database directly, not 93 hashes
to crack. The remediation order in §6 reflects that — **deploying the deny rules is the emergency
step**, and a GitHub history rewrite is housekeeping that changes nothing about what the server
hands out.

This finding is not new in kind. The work queue has tracked `#120` / `P1-OWN-09` as
*"the live `APP_KEY` and database credentials reachable at `3c39c160:.env`"*, **owner-deferred**,
on the understanding that exploiting it took a clone of a repository. It does not. It takes one
anonymous HTTP request to the production host. The item's severity should be re-rated on that
basis.

| | |
|---|---|
| **Live `APP_KEY` + DB credentials over HTTP** | **confirmed retrievable**, window opens 2026-08-27 |
| Exposure window (dump, via GitHub) | 2026-10-24 → ongoing (**18 days** at time of writing) |
| Exposure window (dump, via the served `.git`) | same as the credential window — the blob is in the same packfile |
| Credentials exposed in the dump | 93 bcrypt hashes, all cost 12, all distinct |
| Usable bearer tokens in the dump | **0** — the 7 token rows are SHA-256 digests, not plaintext |
| Live gateway / cloud keys in the dump | **0** |

---

## 2. What the dump contains

Measured on 2026-11-11 by counting pattern occurrences across the file. Structure: **29,790 data
rows across 84 tables**; 198 tables exist in the schema, 84 carry data.

**Credential material — 93 bcrypt hashes, every one cost 12, every one distinct:**

| Table | Hashes | Emails |
|---|---|---|
| `customers` | 57 | 59 |
| `users` | 33 | 33 |
| `delivery_boys` | 2 | 2 |
| `lynomia_contact_verifications` | 1 | 0 |

Cost 12 is above Laravel's default of 10 and roughly four times slower to attack. Zero duplicates
means no account shares a password with another and there is no seeded default password in the
set. This materially lowers — but does not remove — the odds of mass credential recovery.

**Personal data reaches well past the account tables.** Email addresses appear in 14 tables:

`shetabit_visits` 186 · `order_billing_details` 143 · `stores` 34 · `join_us` 25 ·
`zid_events` 22 · `zid_orders` 12 · `landing_page_settings` 4 · `settings`, `helpdesk_tickets`,
`audit_logs` 1 each.

`order_billing_details` (146 rows) is the most sensitive of these: billing records tie a name and
email to an address and an order. `shetabit_visits` is the largest single table at **6,442 rows**
of raw visitor requests carrying headers, user agents and referers.

**Tokens and sessions.** `personal_access_tokens` carries **7 rows** — 4 `User`, 2 `DeliveryBoy`,
1 `Customer` — each with `["*"]` abilities. The stored `token` column holds 64-character SHA-256
digests; a scan for 40-character plaintext tokens returns **zero**. Sanctum hashes tokens before
storage, so the dump **does not hand over a usable bearer token**. `password_reset_tokens`,
`failed_jobs` and `sessions` carry no rows (`sessions` has no schema in the dump at all).

**Secret material — all clear.** Zero matches for `sk_live_`, `pk_live_`, `AKIA`, `AIza`, `ghp_`,
SendGrid and Slack token shapes, and zero for `base64:`, so **no `APP_KEY` is inside the dump**.
The only gateway keys present are one Stripe **test** secret and one Stripe **test** publishable
key.

> The pre-existing "no credentials in the dump" test is **not** evidence for any of the above. It
> checks for `DEFINER=`, `IDENTIFIED BY` and `GRANT`, which are genuinely absent, and it cannot
> see password hashes or gateway settings rows. It passed throughout.

### 2.1 Is this real data or demo data? — unresolved, and it decides §7.3

An open question that this work cannot settle, flagged because the answer changes the notification
obligation more than any other single fact.

The work queue records, under S51, *"the owner's statement that the data is demo data"* — made
about the secrets in the historical `.env`, not about this dump. The directive opening this
incident describes the dump as carrying *"password hashes and personal data"* and instructs that it
be treated as a security incident, which is the framing used throughout this document.

What can be said from the file itself is only that the records are **shaped** like production
data: plausible addresses, varied domains, 93 distinct cost-12 hashes with no duplicate or seeded
default, and 6,442 visitor rows with varied user agents — a pattern more consistent with real
traffic than with a seeder. That is an impression, not a determination, and it is not evidence.

**Resolving it requires comparing the dump's row identifiers against the live database**, which
needs production access and was not attempted. If the records are genuinely synthetic, §7.3
largely dissolves and §7.1 reduces to resetting the staff accounts. If they are real, §7.3 applies
in full. Do not let the question stay open by default, and do not let an earlier statement about
different material stand in for an answer about this file.

---

## 3. Exposure paths

### 3.1 GitHub — the public repository

Owner-confirmed public. The dump was reachable at its path on the default branch for 18 days, and
anyone who cloned or forked in that window holds a copy that no action here can reach.

### 3.2 The live web server — `.git` is served

This is the path that escalates the incident, and it was **not** in the original finding.

The document root is the **repository root**, not `public/`: a root `index.php` shim sits beside
the root `.htaccess`, which is the standard shared-hosting Laravel layout. The front-controller
rewrite is guarded by `RewriteCond %{REQUEST_FILENAME} !-f`, so Apache returns **any file that
really exists** instead of routing the request to `index.php`. Nothing was misconfigured; the
default simply serves whatever the deploy left on disk.

Measured 2026-11-11 with `HEAD` requests only — **no response body was retrieved**:

| Path | Status | Content-Length | Reading |
|---|---|---|---|
| `/a-nonexistent-path` | 404 | — | control: the server does return real 404s, so the 200s below are real files |
| `/.env` | **403** | — | the `<Files .env>` deny is live, which proves the root `.htaccess` is the live config |
| `/storesyria_ecommercego.sql` | **404** | — | the dump is **not** on the deployed tree, and no extension rule existed — a deny would have answered 403 |
| `/.git/HEAD` | **200** | 21 | exactly `ref: refs/heads/main\n` — the deployed checkout is on `main` |
| `/.git/config` | **200** | 270 | |
| `/.git/index` | **200** | 10,376,528 | every path in the repository |
| `/.git/objects/info/packs` | **200** | 54 | **names the packfiles** |
| `/.git/logs/HEAD` | **200** | 3,647 | the reflog |
| `/.git/refs/heads/main` | **200** | 41 | |
| `/composer.lock` | **200** | 611,406 | exact version of every dependency |
| `/storage/logs/laravel.log` | **200** | 3,421,645 | the production log, as `text/plain` |

### 3.2.1 The credential retrieval chain

Each link was measured, not assumed. The conclusion follows from the conjunction.

| # | Link | How it was established |
|---|---|---|
| 1 | A `.env` carrying `APP_KEY`, three `DB_*` variables and one mail/gateway password is committed at `3c39c160:.env` (blob `aa5c75b8…`, 1,047 bytes) | `git cat-file`; **counts of set keys only — no value was read or recorded** |
| 2 | `3c39c160` is the repository's **root commit**, "Initial commit 98", 2026-08-27 | `git log -1` |
| 3 | So that blob is an ancestor of every branch, `main` included | `git merge-base --is-ancestor 3c39c160 main` → true |
| 4 | The deployed checkout is on `main` | `/.git/HEAD` = 200, **21 bytes** = exactly `ref: refs/heads/main\n` |
| 5 | The deployed clone is **full**, not shallow, sparse or alternates-backed | `/.git/shallow`, `/.git/info/sparse-checkout`, `/.git/objects/info/alternates` all **404** |
| 6 | So the server's packfile contains the root commit and its blobs | follows from 3–5 |
| 7 | The packfiles are **named** by a world-readable file | `/.git/objects/info/packs` = 200, 54 bytes |
| 8 | Which is exactly the input an off-the-shelf `.git` dumper needs | standard tooling |

**Therefore the production `APP_KEY`, database name, database username, database password and a
mail-or-gateway password are retrievable by an anonymous HTTP client.** The packfile was
deliberately **not** fetched to prove this; the chain above is sufficient and fetching it would
have meant retrieving the very credentials and dump the directive forbids distributing.

### 3.2.2 Other consequences

1. **The dump is downloadable from production too**, despite its own path returning 404. The blob
   is in the same packfile. Removing the file from the working tree — which this change does —
   **does not close this path.**
2. **`Crypt::encrypt()` is forgeable by anyone, not just by anyone with a clone.** `CLAUDE.md`
   already records that `APP_KEY` is in history and unrotated, and that encrypted ids in URLs are
   therefore forgeable. Link 1 above gives that a live, anonymous retrieval path.
3. **Database credentials are the sharpest edge.** Whether they are *usable* depends on whether
   MySQL accepts remote connections for that user, which cannot be determined without probing the
   database — out of scope for non-invasive review and not attempted. If it does, the dump
   exposure is moot by comparison: an attacker reads the live database rather than a 2026-10-24
   snapshot of it.
4. **`composer.lock` is readable**, giving the exact version of every dependency. That is what
   turns the 66 published advisories tracked in §2 of the acceptance report from a list into a
   targeting aid.

### 3.3 What was *not* found

`/database/p2dev.sqlite` returns 404 — the dev fixture is not deployed. No directory listing is
available (`Options -Indexes` is set and honoured).

---

## 4. Containment performed in this change

Non-destructive only. No history was rewritten, no branch was force-pushed, nothing was deleted
from the remote.

1. **The dump is untracked from the working tree** (`git rm --cached storesyria_ecommercego.sql`).
   Merging this removes the file from every checkout that pulls it, including the server's. It
   does **not** remove it from history, and by design cannot — see §6.
2. **Future dumps are excluded.** `.gitignore` carries `*.sql`, `*.sql.gz`, `*.dump`, `*.bak`,
   verified against eight candidate paths with `git check-ignore`. Note the removal in step 1 is
   what makes the pattern effective against this file: `git check-ignore` reports a *tracked* path
   as not ignored, so the block was inert against it until now.
3. **Deny rules written, and deliberately NOT armed.** They cover `.git` and other VCS metadata,
   dump and backup extensions including compressed variants, `storage/{logs,framework,app}`,
   `composer.json`/`composer.lock`, and editor swapfiles, and they are held in
   `docs/security/p1-sec-40-apache-deny.conf`, which no server reads.

   They were originally committed into `/.htaccess` and `public/.htaccess`. That was a latent
   breach of the owner's standing instruction not to change public access to the test site: the
   live document root **is** the repository root, and the deployed tree is a git checkout whose
   `.git/HEAD` reads `ref: refs/heads/main`. Directives in those files therefore go live the
   moment anyone runs `git pull` on that host — no deploy pipeline, nobody intending it. They
   were lifted out on 2026-11-11, so both served files are now **directive-identical to
   `origin/main`** and merging cannot change public access. The conf states what to append and
   where; activation is an owner decision.

   The placement instruction survives with them: **outside** `<IfModule mod_rewrite.c>` — the
   pre-existing `<Files .env>` deny is inside it and would fail open if mod_rewrite were ever
   unloaded.
4. **An nginx equivalent** is committed at `docs/security/p1-sec-40-nginx-deny.conf`, unused today
   (the host is Apache) but ready, because a migration to nginx would silently discard every
   `.htaccess` protection.
5. **A regression test**, `tests/Unit/Security/DumpAndVcsPathsAreDeniedTest.php`, extracts the
   patterns from the committed conf and applies them the way Apache does — `<FilesMatch>`
   against the basename, `RedirectMatch` against the path. 32 tests: 18 paths that must be refused,
   10 that must keep working, and four structural pins. Deleting the conf fails 30 of them.

   Two of those pins guard the deferral rather than the rules. One fails if **either** served
   `.htaccess` gains an active deny directive — measured by re-arming each file in turn — so the
   arming described in step 3 cannot recur silently. The other fails if the conf buries its rules
   in `<IfModule mod_rewrite.c>`, which anyone activating them by copying the file verbatim would
   otherwise inherit. A third fails if a served file stops naming P1-SEC-40 and the conf, because
   once the rules are absent that comment is the only thing distinguishing a deferral from an
   oversight.

   The negative controls are the point. Denying `/storage/` wholesale would have broken every
   product image on the storefront, because uploads are served as `/storage/uploads/<file>` through
   the `public/storage` symlink. Only the three internal subtrees are refused.

6. **A side effect of the removal was caught and repaired before it could bite.**
   `UserDeletionSafetyCoverageTest` read the dump to keep `UserDeletionSafety::CASCADE_DEPENDENCIES`
   honest against production's real foreign keys, and was written to *skip* when the dump was
   absent — correct, and better than passing vacuously. Untracking the dump made that skip the
   normal case. Measured: with the dump moved aside, **all four cases skipped and the suite still
   exited 0**, because CI runs `vendor/bin/phpunit --testsuite Unit` with no `--fail-on-skipped`
   and the known-outcome manifest covers the Feature log only. Four cases of security coverage
   would have gone quiet with a green suite and no signal.

   `docs/security/P0-USER-DELETION-CASCADE.md` had predicted this precise failure and recorded it
   as an open gap against #120. It is now closed: the 15 `ADD CONSTRAINT` lines live in a committed
   schema-only fixture, `tests/Support/Security/fixtures/production-users-cascades.txt`, verified at
   extraction to carry zero hashes, emails, IP addresses, `INSERT` statements or value tuples. The
   four cases read the fixture and run everywhere; a fifth cross-checks the fixture against the dump
   where the dump is still present, and only that one skips. Dump present → 5 passed; dump absent →
   4 passed, 1 skipped.

   The general point is worth keeping: **a security remediation that deletes an input can silently
   delete the coverage built on it.** Removing the dump was right; letting it take four tests with
   it would not have been.

> **None of this takes effect on the live host, and now cannot take effect by accident.** The
> server keeps serving `.git`. The deny rules are not in any file Apache reads, so deploying or
> pulling this branch changes nothing about public access — which is what the owner asked for.
> Arming them means copying the conf's rules into `.htaccess`, an owner decision (§8).

---

## 5. Affected branches, copies and reach

The dump blob is `d3d42e68e2847e4970597b03679cd0b48df21bd2` (sha256 of the file:
`6e54b88f769b1216e7d5196bd6f95222b4598be74ebb8398d5aeb0ed9c7df78e`, 20,470,785 bytes, 37,533
lines). It entered in a single commit, `3935dee8ddb3fefd6b4f1475a13e30d3e52ca16d`, dated
2026-10-24, authored `fullstackfull`, message "Add files via upload" — a GitHub web-UI upload.
Exactly one commit touches the path, which makes the history surgery in §6 unusually simple.

**Branches carrying the blob** (same blob on each):

| Ref | Carries the dump |
|---|---|
| `main` | **yes** — the default branch, so the repository landing page |
| `claude/vibrant-bell-12tquy` | yes (this branch; removed by this change) |
| `claude/busy-edison-k0pjwa` | yes |
| `claude/gracious-volta-duiynj` | yes |
| `claude/brave-cori-byyw4s` | no |
| `claude/pensive-cerf-h6rizn` | no |

**The second exposed blob.** The committed `.env` is a separate object with its own identity:
blob `aa5c75b8bc005cc14a00453e8dc0de16fa54260d`, 1,047 bytes, at path `.env` in commit
`3c39c160001965b7e11602eed73c2c6e4f03f52f` ("Initial commit 98", 2026-08-27, author `root`).
Because that is the **root commit**, the blob is reachable from every ref in the repository —
there is no branch that does not carry it, and the table above does not apply to it. Only key
*names* were counted; no value was read, printed or recorded anywhere.

**Copies beyond reach of any remediation.** These cannot be recalled and should be assumed to
exist:

- Any clone or fork made while the repository was public.
- Any `.git` fetched from the production host over HTTP (§3.2). This channel leaves no trace in
  GitHub's audit log, so its use cannot be ruled out from the GitHub side.
- GitHub's own fork network, and any cached or mirrored view of a public repository.
- CI caches and artifacts from runs on the four affected branches.
- The second repository, `StoreSyria_app98`, was **not** examined for a dump of its own. Worth
  checking before closing the incident.

---

## 6. Coordinated history-cleanup plan — **PLAN ONLY, NOT EXECUTED**

> Not performed. The directive forbids force-push, history rewriting and destructive repository
> operations without separate explicit owner authorization, and this plan is not that
> authorization. The blob is intentionally left reachable in history, which is also what preserves
> the forensic evidence: no separate copy of the dump was made, because history retains it.

Order matters, and the ordering changed once §3.2.1 was established. The history rewrite is **not**
the first step and is worth little on its own: while the live host serves `.git`, the whole history
is downloadable from production no matter what state GitHub is in.

**Steps 1–3 are the emergency. They need no rewrite and no force-push.**

| # | Step | Needs owner authorization | Why this order |
|---|---|---|---|
| 1 | **Arm the deny rules and deploy them** — copy `docs/security/p1-sec-40-apache-deny.conf` into `/.htaccess` and `public/.htaccess` as it describes, then deploy. They are deliberately dormant today (§4.3), so a deploy alone does nothing. Or, faster and not requiring a deploy at all: move the server's `.git` directory outside the document root, or delete it if the deploy does not rely on `git pull` | deployment / server access | **The emergency step.** It is the only thing that stops anonymous retrieval of `APP_KEY` and the database credentials. Everything else in this table is secondary to it |
| 2 | Re-run the §3.2 `HEAD` table and expect 404 for every `.git` path | no | Cheap confirmation the deploy took effect. Do not assume it did |
| 3 | **Rotate the database password, and `APP_KEY`** | **yes** — breaks existing ciphertext and needs a coordinated config change | Must follow step 1, or the replacements are exposed through the same channel within minutes. Assume both are compromised: they were anonymously retrievable for an unknown period. Rotating `APP_KEY` invalidates session cookies and any ciphertext written under the old key — scope `Crypt::` call sites and encrypted columns first |
| 4 | Pull Apache access logs and search for `/.git/` and the dump path **before rotation discards the window** | server access | Time-critical, and the only way to turn "exposed" into "accessed or not". Do it early even though it is listed here |
| 5 | Decide the repository's visibility | **yes** | Making it private closes channel 3.1 immediately at no technical cost. Independent of everything else |
| 6 | Rewrite history to drop the dump — `git filter-repo --invert-paths --path storesyria_ecommercego.sql` on a fresh mirror clone | **yes** — rewrites history, requires force-push | One commit touches the path, so this is a clean single-path filter. Rewrites every commit SHA from `3935dee8` forward on all four branches |
| 7 | While rewriting, drop the historical `.env` in the same pass — add `--path .env` to the same `filter-repo` invocation | **yes** | It is the root commit, so this rewrites **every** commit in the repository. Doing it in one pass avoids a second round of broken SHAs. Pointless before step 3: removing the file does not un-leak a key that was already served |
| 8 | Force-push the rewritten refs; every collaborator re-clones | **yes** | Open PRs on the affected branches need rebasing; SHAs in issue comments and in this document become stale |
| 9 | Ask GitHub Support to purge cached views and stale blob references | no | GitHub serves unreachable blobs by SHA for a period after a rewrite; only Support clears that |
| 10 | Re-verify from a clean clone that both paths and both blob SHAs are absent | no | The check that the rewrite worked |

**What a rewrite does not achieve.** It does not reach forks or clones already taken, it does not
reach anything fetched from the production `.git`, and it does not undo a retrieval that has
already happened. Steps 6–8 stop *future* disclosure through GitHub. Step 1 stops ongoing
disclosure through production, step 3 makes what leaked worthless, and §7 is what protects the
people whose data is in the file.

---

## 7. Incident-response recommendation

Offered as a recommendation. Notification obligations in particular are a legal determination, not
an engineering one, and nothing here substitutes for counsel.

### 7.1 Accounts and password resets

All 93 hashes should be treated as exposed. Cost 12 and no duplicates means this is not a
minutes-matter emergency — a strong password is impractical to recover offline at that cost — but
weak and reused passwords in the set are recoverable given time, and 18 days have passed.

| Priority | Who | Count | Action |
|---|---|---|---|
| 1 | `users` of type `super admin` / `admin` | subset of 33 | Force reset immediately; these hold platform and tenant-root privilege. Verify each account's `type` and role assignments are what they should be, since a changed `type` is the account-takeover vector `UserPolicy::sameTenant()` was hardened against |
| 2 | remaining `users` (merchant staff) | rest of 33 | Force reset |
| 3 | `customers` | 57 | Force reset on next login rather than locking out — storefront shoppers, lower privilege |
| 4 | `delivery_boys` | 2 | Force reset |
| 5 | `lynomia_contact_verifications` | 1 | Inspect what this hash secures; it is the one entry whose purpose is not self-evident |

Advise against reuse of the exposed password on any other service, which is the real risk for the
57 customers.

### 7.2 Session and token revocation

The dump exposes **no usable bearer token** — the 7 rows are SHA-256 digests of 40-character
random tokens, which are not reversible. Revocation is therefore prudent, not urgent on the dump's
account alone.

It is urgent for a different reason. Per `CLAUDE.md`, Sanctum tokens in this codebase **never
expire**, every one carries `['*']`, and there is **no refresh path for any client**. So the 7
tokens are almost certainly still valid, and the `.git` exposure means an attacker may hold the
source needed to understand exactly what they unlock.

1. Delete all rows from `personal_access_tokens`, which forces every API client to re-authenticate.
   Note the shipped Flutter app **forces a logout on any 401**, so this logs those users out — that
   is the intended effect, but it is user-visible and should be timed.
2. Invalidate web sessions. The `sessions` table is absent from the dump, so confirm the live
   session driver first; if it is `database`, truncate it, and if it is `file`, clear
   `storage/framework/sessions`.
3. Rotating `APP_KEY` (§6 step 3) invalidates session cookies as a side effect. Sequence these
   together to avoid logging everyone out twice.
4. Treat the absence of a token-expiry mechanism as a follow-up in its own right —
   `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` already scopes it.

### 7.3 Notification obligations

A determination for the owner and their counsel. The engineering facts they will need:

- **Personal data was exposed**, not merely credentials: names, emails, mobile numbers, dates of
  birth, billing addresses tied to orders, and 6,442 rows of visitor requests with IP addresses and
  user agents. Under GDPR-style regimes IP addresses and this billing data are personal data, and
  the 72-hour notification clock runs from **awareness**, not from the breach.
- **Data subjects number at least 107** by distinct email across the account tables, and more once
  `order_billing_details` and `shetabit_visits` are counted. An exact figure needs a per-table
  deduplicated count against the live database, not the dump.
- **Residency matters and is unknown.** Which regime applies depends on where the data subjects
  are, not where the company is. The dump holds `countries`, `states` and `cities` reference data
  but customer residency needs checking against the live `customers` and `order_billing_details`
  rows.
- **Exposure is confirmed, access is not.** There is no evidence anyone fetched either channel, and
  — importantly — **no means of obtaining that evidence** for channel 3.2 from the GitHub side. If
  the host keeps Apache access logs, a search for requests to `/.git/` and to the dump's path is
  the one way to turn "exposed" into "accessed or not", and it should be done **before log rotation
  discards the window**. That is time-critical and is the single most useful piece of evidence
  still obtainable.
- **Payment data.** The only gateway keys in the dump are Stripe **test** keys, and no card data
  was found. This is likely to narrow PCI-DSS obligations considerably, but the finding is about
  the dump's contents, not an assurance about the live Stripe configuration.
- **The credential exposure widens the question the lawyers have to answer, and this is the point
  most likely to be missed.** If the assessment is scoped to the dump, the affected set is a
  2026-10-24 snapshot: 93 accounts and the personal data in §2, fixed and enumerable. But §3.2.1
  establishes that the live database credentials were anonymously retrievable. If those credentials
  accept a remote connection, the potentially affected set is **the live database, including every
  record created since the dump** — a different and larger disclosure, and one that keeps growing
  until step 1 of §6 is done. Whether remote connections are accepted is the single fact that
  decides which of those two scopes applies, it cannot be settled without checking the live MySQL
  configuration, and no probing of it was attempted here. **Establish that before scoping any
  notification.**

---

## 8. Decisions requiring explicit owner authorization

None of these were performed.

Ordered by urgency, not by number.

| # | Decision | Blocked by |
|---|---|---|
| 1 | **Stop the live host serving `.git`** — deploy the deny rules, or move/delete the server-side `.git`. The only step that closes anonymous retrieval of `APP_KEY` and the database credentials | no production access; deployment not authorized |
| 2 | **Rotate the database password and `APP_KEY`**, after step 1 | secret rotation is an escalation item; breaks existing ciphertext and sessions |
| 3 | Pull Apache access logs for `/.git/` and the dump path **before log rotation discards the window** | no production access — and time-sensitive, so it expires rather than waits |
| 4 | Change repository visibility to private | owner decision |
| 5 | Force password resets and revoke tokens (§7.1, §7.2) | touches live user data and logs users out |
| 6 | Rewrite history and force-push, dump and historical `.env` in one pass (§6 steps 6–8) | explicitly forbidden without separate authorization |
| 7 | Issue breach notifications | legal determination |
| 8 | Re-rate `#120` / `P1-OWN-09` from **owner-deferred** in light of §3.2.1 | owner decision; the deferral rested on exploitation requiring a clone, which it does not |

`StoreSyria_app98` was checked and is clean: no dump-shaped file is tracked and none appears in its
history. Dump patterns were added to its `.gitignore` as prevention. Two `AIza`-prefixed Google API
keys are committed there in `android/app/google-services.json` and
`ios/Runner/GoogleService-Info.plist`; those are client-side credentials by Google's design, ship
inside every released APK and IPA, and are extractable from any published build, so they are **not
part of this incident** and do not need rotating on its account. They do need restriction in the
Google Cloud console — package name plus signing SHA-1, bundle id, and per-API limits — which is a
separate, lower-priority item.

---

## 9. Cross-references

| | |
|---|---|
| work queue entry | `docs/security/P01-SECURITY-WORK-QUEUE.md` (P1-SEC-40) |
| recurrence containment | `.gitignore`, the dump-and-archives block |
| Apache deny rules (dormant) | `docs/security/p1-sec-40-apache-deny.conf` |
| served Apache config (no deny rules, by design) | `/.htaccess`, `public/.htaccess` |
| nginx deny rules | `docs/security/p1-sec-40-nginx-deny.conf` |
| regression test | `tests/Unit/Security/DumpAndVcsPathsAreDeniedTest.php` |
| token lifetime follow-up | `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` |
| dependency advisories | `docs/development/phase-2.5/PHASE-2.5-FINAL-ACCEPTANCE-REPORT.md` §2 |
