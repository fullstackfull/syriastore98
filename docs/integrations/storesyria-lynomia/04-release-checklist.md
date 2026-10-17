# Release checklist — not a deployment authorisation

**No production deployment is authorised by this document and none has been performed.** Both
systems are live; the steps below are the order in which a deployment should be done once an
owner approves it.

## 1. Order, and why it is this way round

**Lynomia Chat first. Store Syria second.**

The new behaviour is one new endpoint plus one caller. Deploying the endpoint first means:

* While only Lynomia is deployed, nothing calls the endpoint. The one existing edit to a shared
  code path (`StoresController`'s `storesyria` credential format now referencing
  `Commerce::Providers::Storesyria::TOKEN_FORMAT`) is the same regex, so the manual
  key-pasting path behaves identically. **Lynomia alone is a no-op in production.**
* Deploying Store Syria first would give merchants a "Complete store data setup" button whose
  `PUT` returns 404 from a Lynomia that does not route it — a failure recorded on the row, a
  red state on the merchant's page, and support tickets for a feature that is not there yet.

Partial deployment in the correct order is therefore safe and needs no flag. In the wrong
order it is visibly broken but not damaging: the failure is recorded, nothing is written
upstream, no credential is rotated (an activation that fails before it reaches Lynomia leaves
the version alone, and one that fails after reuses the credential it already had), and the
merchant's retry works the moment Lynomia is up.

## 2. Migrations

One, on Store Syria only:

```
php artisan migrate --path=packages/workdo/LynomiaChat/src/database/migrations/2026_11_03_000100_add_commerce_activation_to_lynomia_store_links.php
```

* Six columns on `lynomia_store_links`: five nullable, one `unsignedInteger default 0`.
* No index, no backfill, no data movement, no lock beyond the `ALTER TABLE` itself.
* Every `after()` clause names a column that exists (asserted by
  `LynomiaMigrationIntegrityTest`, which exists because an earlier migration got this wrong and
  MariaDB rejected it on the first real deploy while SQLite had stayed green).
* **Rollback** is `migrate:rollback` on that one path: a column drop, with nothing to undo
  because nothing was backfilled. Old code reading the table ignores the columns.
* Backward compatible both ways: the previous release does not know these columns and does not
  select them.

Lynomia Chat: **no migration.**

## 3. Configuration

No new environment variable in either system. Everything the new path needs already exists:

| Setting | Where | Already required by |
|---|---|---|
| `LYNOMIA_INTEGRATION_ENABLED` | Store Syria | the whole module |
| `LYNOMIA_BASE_URL`, `LYNOMIA_PLATFORM_TOKEN` | Store Syria | every platform call |
| `LYNOMIA_WEBHOOK_MASTER_SECRET` | Store Syria | webhook signatures and the commerce credential |
| `APP_URL` | Store Syria | sent as `base_url`; must be the merchant-facing HTTPS origin |
| `STORESYRIA_ENABLED` | Lynomia Super Admin | the provider, already on |
| Active Record encryption keys | Lynomia | `commerce_stores.credentials`, already required |

**Two operator preconditions that are not secrets and are not optional:**

1. **`APP_URL` must be the public HTTPS origin.** It is sent as `base_url` and Lynomia parses it
   with `Commerce::StoreUrl` (https, default port, no IP literal) and calls it through
   `Commerce::HttpClient` (SSRF filter, pinned-IP connect). A wrong value fails the install
   loudly rather than quietly.
2. **The plan a provisioned workspace lands on must include the `lynomia_commerce` feature.**
   Otherwise activation is refused with `commerce_disabled`, and the merchant is correctly told
   their plan does not include store data. Set on the trial/default plan in Lynomia's Billing
   Settings, or granted per account through `Billing::OverrideGrant`. **Verify this before
   deploying Store Syria**, or the first merchant to click the button gets an honest refusal.

## 4. Queue workers and scheduling

* No new queue, no new job. The module dispatches none.
* One new scheduled command, `lynomia:reconcile-commerce`, hourly, `withoutOverlapping()`. It
  requires only the existing `schedule:run` cron. If it never runs, the merchant's button still
  works — the sweep repairs beliefs, it does not carry traffic.

## 5. Feature flag

None added, and none needed. With Lynomia deployed and Store Syria not, the feature does not
exist. `LYNOMIA_INTEGRATION_ENABLED=false` already disables the whole module including this, and
the manual Commerce workflow (Super Admin issues a token, an administrator pastes it in Lynomia)
is untouched and remains available in every state — which is the disabled-state behaviour the
brief asks a flag to preserve.

## 6. Staging verification, before production

1. Deploy both to staging with its own Platform App and its own `LYNOMIA_INSTALLATION_REF`.
   **Confirm the ref differs from production's** — identical refs across installations is the
   collision `StoreIdentity` exists to prevent.
2. New merchant: Connect → workspace active, store data connected, orders visible in Lynomia.
3. Existing workspace with no commerce: **Complete store data setup** → connected, same account
   id, one link row.
4. Already connected: **Verify connection** → "connected and working", credential version
   unchanged, one `commerce_stores` row.
5. Break it on purpose: point `LYNOMIA_BASE_URL` at a dead host, click Connect, confirm the page
   shows the failure with a retry and the workspace is still active; restore and retry.
6. Cross-tenant: with two merchants, point one's `current_store` at the other's store and POST
   the connect route. Expect a redirect with an error and no call on the wire.
7. `php artisan lynomia:reconcile-commerce --dry-run`, then without.
8. Disable the store in Lynomia's Commerce settings; sweep; confirm the merchant page reads
   **Unavailable** with no retry button.

## 7. Production smoke tests (read-only, after approval)

1. `GET /platform/api/v1/accounts/135/integration_health` with the production platform token —
   expect `installations` to carry one `storesyria` row. This is the §2.6 reading that could not
   be re-checked from the build environment.
2. `php artisan lynomia:reconcile-commerce --dry-run` — expect it to report that store #26 would
   gain `lynomia_commerce_store_id` / `commerce_remote_status` / `commerce_installed_at` (it has
   been connected manually, so this side has never recorded it) **and nothing else**.
3. Then without `--dry-run`, and confirm the merchant page for store #26 reads **Connected**.
4. Do **not** press Connect for store #26. It is already connected; `Verify connection` is the
   safe control and it rotates nothing.
5. Confirm `commerce_credential_version` for store #26 is unchanged throughout.

## 8. Rollback

| Symptom | Action |
|---|---|
| Activation failing for everyone | Revert Store Syria. The endpoint becomes uncalled; Lynomia needs no revert. Existing installations keep working — they are `commerce_stores` rows that predate this and are read by the unchanged provider adapter. |
| A merchant's installation wrongly created | Disconnect it in Lynomia's Commerce settings (existing control), then revoke that store's credential in Super Admin. Either stops reads on its own; both is belt and braces. |
| The migration must go | `migrate:rollback` on the one path. Drops six columns. The merchant page then reports commerce from `lynomia_commerce_store_id` alone, as before. |
| Unsure whether a credential was rotated | `lynomia_commerce_credential_issued` / `_revoked` audit rows carry every version change with its actor. The credential itself is in none of them. |

Reverting Store Syria does **not** require reverting Lynomia, and reverting Lynomia alone would
break Store Syria's activation (the caller would 404) — so if both are reverted, revert Store
Syria first.

## 9. What is explicitly not authorised here

Deploying. Changing a production secret. Regenerating `LYNOMIA_WEBHOOK_MASTER_SECRET` or
`APP_KEY`. Rotating store #26's credential. Any write to account #135. Any destructive database
operation. Creating a second Platform App.

## 10. One more staging check, for the disconnect

9. Fully connected store → **Stop sharing store data** → confirm the page reads *Not connected /
   Your store data is not being shared*, the commerce credential version has advanced with
   `issued_at` cleared, and a commerce read from Lynomia's side now fails. Then **Connect store
   data** → connected again on a new version. Do this on staging only; do not disconnect store
   #26.
10. Repeat the disconnect with `LYNOMIA_BASE_URL` pointed at a dead host, and confirm it still
    succeeds and still stops the reads — that is the property the whole ordering exists for.
