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
