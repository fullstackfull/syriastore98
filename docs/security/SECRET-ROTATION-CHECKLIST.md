# Secret rotation checklist — Store Syria

**Status: NOT EXECUTED. This document is a plan, not a record of work done.**

Rotation and history rewriting both change production and shared history, so neither was
performed. Both require explicit owner authorization. No secret value appears in this
document, in any commit made alongside it, or in any log produced while writing it.

---

## 1. What is exposed, and since when

`.env` was committed in `3c39c160 Initial commit 98` and remained tracked until
`e4665728` (this remediation branch). The repository
`github.com/fullstackfull/syriastore98` is **public**, so every value in that file has been
world-readable for the whole period, and remains readable in history after the untracking.

Keys confirmed to hold real, non-placeholder values. Established by comparing digests of the
committed value against the shipped `.env.example` default — values were never printed:

| Key | Exposure |
|---|---|
| `APP_KEY` | **Highest priority.** Laravel's signing/encryption key |
| `DB_PASSWORD` | Database credential |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `VITE_REVERB_APP_KEY` | Broadcast credentials |
| `FIREBASE_CREDENTIALS` | Path/credential reference — confirm what it resolves to on the host |

Placeholders at the time of audit, so no action beyond confirming they are still placeholders:
`REDIS_PASSWORD`, `MAIL_PASSWORD` (both equal to the `.env.example` default), `PUSHER_*` (empty).

## 2. Why `APP_KEY` is first

`APP_KEY` is the key behind `encrypt()`/`decrypt()`, the `encrypted` cast, signed URLs and
session cookies. While it is public:

- any `encrypted` column is readable by anyone with a copy of the database or a dump;
- session cookies can be forged;
- signed URLs can be minted.

This is the reason the forthcoming Lynomia integration must not store any credential in this
database until rotation is complete: Laravel's `encrypted` cast provides **no** confidentiality
against an attacker who holds the key.

⚠ **Rotating `APP_KEY` invalidates existing encrypted data.** Before rotating, inventory every
`encrypted` cast and every `Crypt::`/`encrypt()` call site and plan a re-encryption pass, or
accept the loss deliberately. At the time of writing the only `encrypted` cast in the
application was `chatwoot_integrations.api_access_token`, which this branch retires — so the
re-encryption burden is currently near zero. **Confirm against the live schema before acting.**
All sessions will be invalidated; users will be logged out.

## 3. Order of operations

1. **Decide repository visibility.** Rotation is pointless if the next leak re-exposes
   everything. Either make the repository private, or accept that history stays public and
   treat every historical value as permanently burned.
2. **Inventory encrypted data** (§2) and decide re-encrypt vs. discard.
3. **Rotate, in a maintenance window**, in this order:
   `DB_PASSWORD` → `REVERB_*` → `FIREBASE_CREDENTIALS` → `APP_KEY` last, because it logs
   everyone out.
4. **Update the deployed `.env` out of band** — never through git. The file is now ignored, so
   deployment must supply it by another mechanism; confirm the deploy process does not expect
   it from the repository.
5. **Verify** login, checkout, a payment callback, and any `encrypted` read.
6. **Record** the rotation date and who performed it. Do not record values.

## 4. History

The values stay in `3c39c160` after this untracking. Purging requires a history rewrite
(`git filter-repo` or BFG) plus a force-push, which breaks every existing clone, fork and open
branch, and does **not** reach copies GitHub has already served. Treat rewriting as a
housekeeping step **after** rotation, not as a substitute for it. **Owner authorization
required.**

## 5. Deployment note

`.env` is no longer in the repository. Before this branch is deployed, confirm the target host
already has its own `.env`, or that the deployment pipeline supplies one. A host that was
relying on the file arriving via `git pull` will come up with no configuration.

---

## 6. The encrypted-at-rest inventory an `APP_KEY` rotation needs first

**P1-OWN-02 (#43), third acceptance criterion:** *"An APP_KEY rotation is preceded by an inventory
of every encrypted-cast column."* This is that inventory. **Rotation itself is deferred by D7**
(*"No production key rotation now. Residual risk accepted and to be documented without claiming
remediation"*) — this section does not change that, it makes the deferred operation safe to
perform when it is authorised.

### The census, and why the criterion's own wording would have produced a wrong answer

The criterion says *"every encrypted-**cast** column"*. Keyed on `$casts`, the census returns
**one** row and is wrong. Three more columns are encrypted **imperatively**, with
`Crypt::encryptString()` in a controller and `Crypt::decryptString()` at four read sites, and carry
**no cast at all** — `ZidStore`'s `$casts` contains only `'expires_at' => 'datetime'`. A census
keyed on the cast declaration finds none of them.

This is the fourth instance in this project of a completeness claim keyed on spelling rather than
on behaviour, and it is recorded here for the same reason as the other three: the next person to
write a rotation runbook will reach for `$casts` too.

### What rotation makes permanently unreadable

| table | column | mechanism | in `$casts`? | after rotation |
|---|---|---|---|---|
| `otp_integrations` | `api_secret` | `'encrypted'` cast (`app/Models/OtpIntegration.php:26`) | **yes** | unreadable. The merchant re-enters it from their OTP provider. **Recoverable.** |
| `zid_stores` | `authorization` | `Crypt::encryptString` (`ZidOAuthController.php:86`) | no | unreadable |
| `zid_stores` | `access_token` | `Crypt::encryptString` (`ZidOAuthController.php:87`) | no | unreadable |
| `zid_stores` | `refresh_token` | `Crypt::encryptString` (`ZidOAuthController.php:88`) | no | unreadable — **and this is the one that matters** |

Read sites for the Zid three: `app/Services/Zid.php:15-16`,
`app/Http/Controllers/DeliverySyriaStatusController.php:196-197`,
`app/Console/Commands/ZidRefreshTokens.php:23`.

**The `refresh_token` is the hard one.** The other three can be re-entered or re-fetched. A refresh
token cannot be re-derived from anything on this side — losing it means every connected merchant
must **re-authorise through Zid's OAuth flow by hand**, and `ZidRefreshTokens` (the scheduled
command that keeps the integration alive) fails for each of them until they do. So a rotation needs
an ordered step: **decrypt and re-encrypt these four columns under the new key in the same
maintenance window**, not "rotate and see what breaks".

### Both tables are UNDECLARED, which is why a schema-driven runbook would miss them entirely

Neither `otp_integrations` nor `zid_stores` is created by any migration — they belong to the
SCHEMA-01 undeclared-table family (`P1-MIG-04`, #41). A rotation runbook that enumerated encrypted
columns by reading `database/migrations/` would find **nothing at all**. The only way to see these
is to read the models and the call sites, which is what this section did.

### What rotation also invalidates, which is not "at rest" but is user-visible

Recorded here so the blast radius is one list rather than three:

- **Every `Crypt::encrypt`ed identifier already in a URL.** Order-detail links in customers'
  inboxes (`{storeSlug?}/order/{id}`), the PayU and Aamarpay return URLs, the admin invoice links
  built in four DataTables. This is the sequencing #73 and #43 share: rotation breaks every emailed
  order link at once, and `OrderDocumentAccessGuard` now refuses anonymous access to those anyway.
- **Every session and `remember_me` cookie.** Laravel's session and cookie encryption both key on
  `APP_KEY`, so rotation signs out every merchant, staff member and shopper simultaneously. That is
  the *desired* effect for P1-OWN-08's "invalidate sessions" obligation and an outage for everyone
  else; the two want the same action for opposite reasons and should be scheduled together.
- **`uploads/` and the LaraBug payloads are unaffected** — neither is encrypted.

### The step this adds to §3's order of operations

Between "put the new key in place" and "restart", insert:

1. Take the backup (already §3's first step) and **verify it restores** — D15's condition, and the
   only thing that makes the next step reversible.
2. With the OLD key still live, read and decrypt the four columns above into a holding store.
3. Swap the key.
4. Re-encrypt and write them back under the new key.
5. Verify by one read through each of the four read sites named above, **not** by a `SELECT` —
   a row that decrypts to garbage looks identical to a correct one in the table.

Until D7 is lifted none of this runs. The inventory exists so that when it does, step 2 has a list
rather than a search.

