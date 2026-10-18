# Operator recovery procedure — Store Syria Integration authorization

Companion to `07-incident-authorization-revoked.md`, which establishes the root cause. This
document is the runbook: who is allowed to do each step, what to check before doing it, how to
verify it worked, and how to undo it.

> **Nothing in here has been executed against production.** No production record, `.env`,
> credential or workspace has been read or written while this was written. Every command below is
> given for an operator to run deliberately, after approval, and every step that writes anything
> is preceded by a read-only check and followed by a rollback.

| | |
|---|---|
| Applies to | Store Syria store `26` → Lynomia account `135` (`al atalssian`), Platform App `4` |
| Symptom | `PlatformApiClient::showAccount(135)` → HTTP 401; `lynomia_store_links` still `active` |
| Outcome | the missing `platform_app_permissibles` row is restored on account `135` |
| Not in scope | creating a workspace, changing an account id, transferring Commerce ownership, rotating any credential |

---

## 1. Preconditions

Both repositories must be deployed at or after these changes, because the procedure depends on
three things that did not exist during the incident:

| Needed for | Where |
|---|---|
| `error_code` on the two Platform API 401s | `lynomiachat/app/controllers/platform_controller.rb` |
| `accounts.provisioned_by_platform_app_id` + the managed-revoke refusal | `lynomiachat` migration `20261101000000`, `custom/app/services/integrations/account_grants.rb` |
| `authorization_lost_at` / recovery form / `AccountLinker::reauthorize` | `syriastore98` migration `2026_11_05_000100`, `app/Services/AuthorizationHealth.php`, `app/Services/AccountLinker.php` |

Until the Lynomia half is deployed, Store Syria classes the revoked-grant 401 as a platform
credential failure (`KIND_UNAUTHORIZED`) and the merchant page will **not** offer the recovery
form. Deploy order is therefore **Lynomia first, Store Syria second**. Running the Store Syria
half alone is harmless but inert; running the Lynomia half alone is also harmless — the new
`error_code` is additive and no existing client reads it.

## 2. Authorization checks, before anything is written

Each step below names the principal that is allowed to perform it. These are the checks; none of
them writes.

### 2.1 Which failure is this actually?

Run on Store Syria, as a platform operator with shell access:

```
php artisan lynomia:reconcile-commerce --dry-run
```

It prints one line per store it could not read, naming the **kind** it classified the failure as:
`store 26: could not ask Lynomia (<kind>)`. That kind is the whole diagnosis:

| Line for store 26 | Who fixes it |
|---|---|
| `(not_permissible)` | **this procedure.** One merchant's grant is gone. Lynomia sent `error_code = non_permissible_resource`. |
| `(unauthorized)` | **Stop.** The platform token is wrong or expired — every merchant is broken and no merchant can fix it. Nothing below applies. |
| `(unreachable)`, `(rate_limited)`, `(remote_error)` | **Stop.** Transient or an outage. Re-run later; the state on the page is deliberately left as Lynomia last reported it. |
| `(conflict)` | an entitlement or claim refusal (`commerce_disabled`, `store_limit_reached`, `installation_claimed_elsewhere`) — §4, or the merchant's Lynomia plan. Not an authorization loss. |
| no line at all | the grant is fine. The diagnosis is wrong; do not continue. |

`--dry-run` writes nothing, including no `authorization_lost_at` — deliberately, so that an
investigation cannot itself change the state being investigated.

### 2.2 Is the workspace actually intact?

As a Lynomia operator, in a Rails console on a **read-only** basis:

```ruby
a = Account.find(135)
a.name                                   # => "al atalssian"
a.users.count, a.inboxes.count, a.conversations.count
a.custom_attributes['store_syria_store_id']        # => "26"
a.provisioned_by_platform_app_id                   # => nil (pre-dates the column) or 4
PlatformAppPermissible.where(permissible: a).pluck(:platform_app_id)   # => [] is the incident
```

If `conversations.count` is 0 and you expected data, **stop** — that is a different incident and
this procedure assumes the account is whole.

### 2.3 Who may mint the proof?

Only an **administrator signed in to account 135**. That is not a convenience; it is the entire
authorization basis of the recovery. `Integrations::AccountLinkCode.create` is reachable only
through `POST /api/v1/accounts/135/integrations/account_link_codes`, behind
`check_admin_authorization?`, and the code is stored as an HMAC — nobody, operator included, can
read it back out of the database. An operator cannot mint it on the merchant's behalf, and should
not ask for the code to be sent to them: the merchant pastes it into their own Store Syria page.

If the merchant has no administrator left on account 135, stop here and escalate — restoring an
administrator is a separate, audited Super Admin action, not part of this procedure.

## 3. Recovery

### Step 1 — merchant mints a connection code (merchant, in Lynomia)

Lynomia Chat → **Settings → Integrations → Connect an external platform**. Under *Platform to
connect* choose **Store Syria Integration**, then **Generate code**. (The same page lists what
currently holds access under *Connected platforms*; that is the list the merchant revoked from.)

- Writes: one `Integrations::AccountLinkCode` row (HMAC only), audit
  `integrations.account_link.code_issued`.
- Verify: the page shows the code once — it expires in an hour — and the status becomes *Waiting
  for Store Syria*.
- **Rollback:** none needed. An unredeemed code expires on its own; generating another supersedes
  it. Nothing about the account changed.

### Step 2 — merchant pastes it into Store Syria (merchant, in Store Syria)

Store Syria → **Communication Center**. With `authorization_lost_at` set, the page shows a red
**“Integration authorization needs recovery”** banner with a single field, and the ordinary
*Create / Connect a workspace* choice is **not** offered — offering it here is how a merchant ends
up with a second workspace.

Paste the code → **Restore connection**. That posts to `connect.recover`
(`ConnectionController::recoverAuthorization` → `AccountLinker::reauthorize`).

What `reauthorize` does, in order:

1. refuses unless the link `isProvisioned()`;
2. redeems the code against Lynomia (`POST /platform/api/v1/account_links`) — **proof first, so a
   refusal writes nothing**;
3. **the guard:** refuses unless the redeemed `account_id` equals the link's existing
   `lynomia_account_id`, auditing `lynomia_authorization_recovery_refused` with both ids;
4. updates only `lynomia_user_id`, `lynomia_account_name`, `last_error`, `last_synced_at`;
5. clears `authorization_lost_at`, audits `lynomia_authorization_recovered_by_merchant`.

- Writes, Lynomia side: one `platform_app_permissibles` row (account 135 ← app 4), the user grant
  for the authorizing administrator, audit `integrations.account_link.granted`.
- Writes, Store Syria side: the fields above on `lynomia_store_links` for store 26, and it
  **deletes this workspace's `lynomia_store_identities` rows**. **The state, the account id, the
  link mode and the Commerce installation are not touched.**
- Why the identities go: Lynomia's revoke destroyed the per-**user** permissibles along with the
  account's, and redeeming a code restores only the account one — deliberately, since granting the
  authorizing administrator's own identity would hand the app an unscoped session for them. The
  stale rows name Lynomia users the app no longer holds, and
  `GET /platform/api/v1/users/:id/login` checks the permissible on the **user**. Left in place they
  make SSO keep answering 401 after a "restored" connection, and the next failure re-lights the
  banner — a recovery that loops. Forgetting them makes the next sign-in mint fresh identities,
  which is what re-grants the per-user permissible. The merchant sees nothing of this; their team
  list and roles are rebuilt as people sign in.
- Verify this specifically: after the banner clears, **open the workspace**. A successful redirect
  is the proof the user grants are back; a cleared banner alone is not.
- Verify: the banner is gone, the workspace card reads connected, and
  `php artisan lynomia:reconcile-commerce --dry-run` no longer prints a `(not_permissible)` line
  for store 26.
- Repeating it is safe. `grant_one` is `find_or_create_by!` with a `RecordNotUnique` rescue, so a
  second redemption of a second code is a no-op that still answers `201`.

**Rollback of step 2:** the supported undo is the source platform handing the grant back —
`DELETE /platform/api/v1/account_links/:id` as Platform App 4, or an operator removing the
permissible in Super Admin. That returns the system exactly to the incident state, which is the
only thing "undo" can mean here. There is no partial state to clean up: if the guard refused, or
the redemption failed, nothing was written on either side.

### Step 3 — if the merchant pasted the wrong code

Expected and handled. `lynomia_authorization_recovery_refused` is audited with
`expected_account_id` and `presented_account_id`, the merchant is told *"That code is for a
different Lynomia Chat workspace"*, and **the code they presented was still consumed on the
Lynomia side** — it is one-time by design. They generate a fresh one inside account 135 and repeat
step 1. Nothing on either side changed.

A refusal where `presented_account_id` is a workspace this merchant has nothing to do with is the
cross-tenant case. It cannot succeed — the comparison is against what the link already says, not
against anything the caller supplied — but it is worth an operator's attention, which is why both
ids are recorded.

## 4. Commerce, after authorization is restored

Restoring the grant does **not** restore Commerce. `commerce_stores` has a global
`UNIQUE (provider, external_store_id)` and the legacy **manually installed record under account
1** holds Store Syria's external id for store 26, so `Commerce::PartnerInstallation` answers
`409 installation_claimed_elsewhere` for account 135. That is the tenant-isolation protection
working: `StoreConnection#claim` releases a row from another account only when that row is
`disconnected`.

**This must not be auto-adopted, and the code does not.** No silent transfer, no ownership
rewrite. The record is retired where it lives:

### Step 4 — retire the legacy record (operator, in account 1)

1. **Check first, read-only.** In a Rails console, confirm what the row actually is before
   touching it:

   ```ruby
   s = Commerce::Store.find_by(provider: 'storesyria', external_store_id: <store 26's external id>)
   s.account_id        # => 1, i.e. the installation's own workspace, not a merchant's
   s.status            # => connected
   s.customer_links.count
   Custom::AuditLog.where(auditable: s).order(:created_at).pluck(:created_at, :comment)
   ```

   If `account_id` is anything other than 1, **stop**: that is a merchant's store and this step
   does not apply. If it has customer links and conversations that somebody is using, stop and
   escalate — retiring it deletes those links (below).

2. **Disconnect it, in account 1, through the ordinary merchant surface** — signed in to account 1
   as an administrator: Commerce settings → the Store Syria store → **Disconnect**
   (`DELETE /api/v1/accounts/1/commerce/stores/:id` → `Commerce::StoreConnection#disconnect`).
   This is deliberately the same audited path a merchant uses, not a console `update_column`.

   - Writes: `status = disconnected`, `credentials = nil`, `customer_links` deleted, cache purged,
     audit `commerce.store_disconnected`.
   - The row itself is **kept**, which is what makes step 5 possible.

3. **Rollback of step 4:** reconnect it in account 1, the same way. The credentials are gone and
   must be re-entered, and the deleted `customer_links` are **not** recoverable — they are rebuilt
   from subsequent order events. If the audit trail in step 1 shows links that matter, this step is
   not reversible in the sense that matters and needs the merchant's agreement first.

### Step 5 — the merchant reconnects Commerce (merchant, in Store Syria)

With the legacy row `disconnected`, the ordinary merchant action works untouched: Store Syria →
Communication Center → **Connect store data**. `StoreConnection#claim` now finds a `disconnected`
row owned by another account, destroys it, and installs cleanly under account 135.

- Verify: the merchant's card reads **Commerce Connected**; `commerce_stores` has exactly one row
  for `(storesyria, <external id>)` and its `account_id` is 135.
- **Rollback:** the merchant's own **Disconnect**. Account 1's record is gone at this point and
  does not come back; that is the intent, and it is why step 4 demands the read-only check first.

## 5. Preventing recurrence on this account

Account 135 pre-dates `provisioned_by_platform_app_id`, so until it is recorded the merchant can
revoke again and the refusal will not fire. **Do this last**, after step 2: the task refuses an
account the app does not currently hold a permissible on, which is exactly the account-135 state
before recovery.

```
bundle exec rake 'ops:adopt_provisioned_workspaces[4,store_syria_store_id,135]'          # dry run, writes nothing
bundle exec rake 'ops:adopt_provisioned_workspaces[4,store_syria_store_id,135,commit]'   # writes
```

**The account list is required**, and that is the security property rather than a convenience: the
marker it corroborates against lives in `custom_attributes`, which the Platform API lets an app
write on any account it holds, so a run that *selected* on the marker could be made to adopt a
grant a merchant had merely invited. Naming `135` means nothing else can ride along. Several
accounts are separated with `+` (`135+208`).

Read the dry-run output line by line before committing. For each account it prints the marker value
it is relying on, when the account and the grant were created, and whether a code redemption is
recorded against this app — which this very procedure produces at step 2, so it is information for
you and not a refusal. Accounts it skips are listed with the reason; it refuses one that already
names a provisioning app (including a different one — a conflict for a person to look at), one the
app holds no live grant on, and the installation's own platform workspace.

- Writes: `accounts.provisioned_by_platform_app_id = 4`, one `Custom::AuditLog` row per account
  (`integrations.account_link.adopted_as_managed`) recording the marker it inferred from.
- Verify: the merchant's **Connected platforms** page now shows *"Store Syria Integration set this
  workspace up and manages the connection…"* in place of the Revoke button, and
  `DELETE /api/v1/accounts/135/integrations/grants/4` answers `409 managed_connection`.
- **Rollback:** `Account.find(135).update!(provisioned_by_platform_app: nil)`. The column is
  nullable and nothing cascades from it; the merchant's Revoke button comes back. Record why.

Why this is an operator task and not a migration: the evidence is a marker in
`custom_attributes`, which is writable by any app holding a permissible
(`AccountsController#permitted_params`). Inferring ownership from an attribute the claimant can
write, inside a migration nobody reviews row by row, is how an app would grant itself an
unrevocable connection.

## 6. Rollback summary

| Step | Writes | Undo | Reversible? |
|---|---|---|---|
| 1 mint code | one HMAC row | expires on its own | yes |
| 2 redeem code | the permissible; 4 fields on the link | app hands grant back, or operator removes the permissible | yes, exactly |
| 3 wrong code | audit row only | nothing to undo | n/a |
| 4 retire legacy Commerce | status, credentials, customer links | reconnect in account 1; **customer links are not recoverable** | partially |
| 5 merchant reconnects | new `commerce_stores` row under 135 | merchant disconnects; account 1's row is gone | partially |
| 6 adopt as managed | one column, one audit row | set the column back to NULL | yes |

An operator who needs to **end** a managed connection (rather than record one) has
`rake 'ops:revoke_managed_connection[4,135]'` on the Lynomia side — dry run by default. It calls
the same `revoke` the source platform's own hand-back calls, so it releases the user grants, sweeps
outstanding SSO links and writes the same audit row. It deliberately leaves
`provisioned_by_platform_app_id` alone: that records that this app created the workspace, which
stays true after the grant is gone.

## 7. What this procedure will not do

Named explicitly, because each one is a shortcut somebody will propose under pressure:

- **No replacement workspace.** Account 135 keeps its id, users, inboxes, conversations and
  history. The recovery form deliberately hides the *Create a workspace* choice precisely so this
  cannot happen by accident.
- **No direct Commerce ownership transfer.** No `update_column(:account_id, 135)`. The legacy row
  is retired where it lives and the merchant reinstalls through the ordinary path, so the unique
  constraint and the tenant check both stay in force.
- **No bypassed authorization.** The permissible is restored only by a code an administrator of
  account 135 minted. There is no operator route that fabricates one, and none is added.
- **No credential rotation.** Platform App 4's token, the master secret and every derived `lsc_`
  commerce credential are untouched. Rotating any of them would break every other merchant to fix
  one.
- **No console writes to `lynomia_store_links.state`, `lynomia_account_id` or `link_mode`.** If
  the procedure above does not resolve it, the diagnosis is wrong; go back to §2.1.
