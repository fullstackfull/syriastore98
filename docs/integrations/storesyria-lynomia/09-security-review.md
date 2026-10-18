# Security review — integration ownership, recovery, and the managed-connection restriction

Required by `.github/SECURITY-DIFF-REVIEW.md`, which the `Merge-base regression gate` enforces on
any diff touching `**/Middleware/**` or `routes/**`. This is the review; `07-incident-authorization-revoked.md`
is the root cause and `08-recovery-procedure.md` is the operator runbook.

| | |
|---|---|
| Store Syria | `fullstackfull/syriastore98` PR #6, base `343feeae` |
| Lynomia Chat | `fullstackfull/lynomiachat` PR #39, base `28de9eeb` |
| Method | 9 dimensions reviewed independently, every candidate finding then checked for exploitability by a reviewer instructed to refute it; plus direct enumeration of the paths below |
| Result | 41 candidates, **10 exploitable**, 31 refuted; **187 controls confirmed sound**. 6 fixed in this branch, 4 accepted with reasons. |

> **Nothing was run against production.** No production record, `.env`, credential or workspace was
> read or written; no credential was rotated. Every id below that matches production (store 26,
> account 135, app 4) appears only in an in-memory sqlite fixture or in prose.

---

## 1. Sensitive files changed against the merge base

What the gate flags, by its own pattern, in `syriastore98`:

| File | Why sensitive | Deletions |
|---|---|---|
| `packages/workdo/LynomiaChat/src/app/Http/Middleware/AuthenticateCommerceApi.php` | `**/Middleware/**` — inbound authorization | −1 |
| `packages/workdo/LynomiaChat/src/routes/web.php` | `routes/**` — a new state-changing POST | 0 |

**The gate's pattern is a poor fit for the Lynomia repo and would not have flagged that half on
its merits.** Applied to PR #39 it matches only
`app/javascript/dashboard/routes/dashboard/settings/integrations/AccountLink.vue` and its spec —
on the `routes/` path segment of a Vue directory — while missing every file that actually decides
authorization there:

| File | Why sensitive |
|---|---|
| `app/controllers/platform_controller.rb` | the Platform API authorization filter chain |
| `app/controllers/platform/api/v1/accounts_controller.rb` | account creation; writes the management record |
| `custom/app/controllers/api/v1/accounts/integrations/grants_controller.rb` | the merchant-facing revoke surface |
| `custom/app/services/integrations/account_grants.rb` | the revoke implementation and the restriction |
| `custom/app/models/custom/account.rb`, `custom/db/migrate/20261101000000_*`, `db/schema.rb` | the discriminator column and its foreign key |
| `lib/tasks/ops/adopt_provisioned_workspaces.rake`, `lib/tasks/ops/revoke_managed_connection.rake` | operator writes to the discriminator, and the operator exit |

Recommended follow-up (not done here — it changes a CI gate): extend the pattern with
`controllers/platform/`, `platform_controller`, `account_grants`, and `lib/tasks/ops/`, or add the
same job to the Lynomia repo.

## 2. Request surfaces added or changed, and what authorizes each

| Surface | Authentication | Authorization | Absent / wrong / foreign credential |
|---|---|---|---|
| `POST /lynomia/connect/recover` *(new)* | session (`web`, `auth`, `verified`) | `PlanModuleCheck:LynomiaChat` + `Permission:Manage Lynomia Chat` + `EnsureStoreOwnsLink:existing` | anonymous → sign-in redirect; wrong tenant → `You do not have access to this store.` + redirect to dashboard; no link → refused, no wire call |
| `GET /lynomia/` *(changed view)* | same | `EnsureStoreOwnsLink` | same |
| Super Admin commerce credential issue/revoke *(changed gate)* | operator session | Super Admin route group | a merchant cannot reach the route (pinned by an existing spec) |
| `GET /api/lynomia/commerce/v1/*` *(changed predicate)* | `lsc_` commerce credential, Bearer | the credential resolves the store; `arrangementAllowsSso()` + link state | one answer for malformed, forged, revoked and unknown — no oracle |
| `rake lynomia:reconcile-commerce` *(changed gating)* | shell | operator | n/a |
| `GET/DELETE /api/v1/accounts/:id/integrations/grants[/:app]` *(changed)* | user token | `check_admin_authorization?` → `administrator?` **on both actions** | an agent cannot even list grants |
| `POST /platform/api/v1/accounts` *(changed)* | platform access token | `set_platform_app` | invalid token → `401 invalid_access_token` before any lookup |
| the two `PlatformController` 401s *(changed)* | — | — | additive `error_code`; status and prose unchanged |
| `rake ops:adopt_provisioned_workspaces` *(changed)* | shell | operator | see §5 |
| `rake ops:revoke_managed_connection` *(new)* | shell | operator | dry run by default |

CSRF: the new POST is inside `['web','auth','xss','setlocate','verified']`; `bootstrap/app.php`
excludes no `lynomia` path, so `VerifyCsrfToken` applies as it does to its siblings.

## 3. Can a merchant still break a Store Syria-managed integration from inside Lynomia Chat?

Enumerated exhaustively, not sampled.

**Every production path that destroys an Account's permissible:**

| Path | Reachable by a merchant? |
|---|---|
| `Integrations::AccountGrants.revoke` (`account_grants.rb:95`) — the only one | only via `revoke_on_behalf_of_merchant`, which raises `ManagedConnection` → `409 managed_connection` |
| `PlatformApp has_many :platform_app_permissibles, dependent: :destroy_async` | deleting the app — operator only |
| `AgentBot has_many ... dependent: :destroy` | a different `permissible_type` |
| `rake ops:revoke_managed_connection` *(new)* | shell — operator only |

`Account` declares **no** association for permissibles, so deleting an account orphans the row
rather than cascading — pre-existing, handled by `accounts_controller#index`'s `filter_map`, and no
escalation (a merchant deleting their own account gains nothing).

**Every writer of the discriminator the restriction reads:**

| Writer | Principal |
|---|---|
| `platform/api/v1/accounts_controller.rb:40`, at creation | the app that creates the workspace — which is the semantics |
| `rake ops:adopt_provisioned_workspaces` | operator, see §5 |

It is **not** in `api/v1` account params, `api/v2`'s fixed `custom_attributes` merge, the Platform
API's `permitted_params`, or the Administrate dashboard's `FORM_ATTRIBUTES` — so no merchant-facing
or Super Admin form can write or NULL it. The escalation worth hunting (merchant NULLs the column,
restoring their own Disconnect button) has no path.

Enforcement is server-side: `managed:` on the grants listing exists only so the page can say *why*
there is no button, and `grants_spec.rb` pins that the server refuses regardless of what rendered,
that an unrelated app on the same account is unaffected, and that the source platform can still
hand its own grant back.

## 4. Does recovery restore the existing workspace without creating another or bypassing ownership?

**Creates nothing.** Lynomia's `account_links#create` calls `grant_one` —
`find_or_create_by!(permissible: account)` inside a savepoint with a `RecordNotUnique` rescue — and
nothing else. No Account, no User, no identity handed back.

**Ownership is checked on both sides, and neither check trusts the caller:**

- Store Syria: `EnsureStoreOwnsLink` authorizes the **store** from `Store.created_by` against
  `creatorId()` *before* any link lookup, and the routes carry no `{link}` parameter — there is no
  id to tamper with. The controller reads the link from the middleware attribute, never the request.
- Lynomia: the account comes from the **redeemed code**, not from anything the app sends, and
  `resolve_authorizer` re-reads the membership *now* — a demoted or removed administrator's
  still-live code is refused.
- The cross-tenant guard: `reauthorize` refuses unless the redeemed `account_id` equals the link's
  existing `lynomia_account_id`. Takeover is impossible *because* the link already names a
  workspace and the code is used only as proof.

**What recovery writes:** `lynomia_user_id`, `lynomia_account_name`, `last_synced_at`,
`authorization_lost_at`/`authorization_recovered_at`, `last_error` *only on an active row* (§6.2),
and it deletes the workspace's stale identity rows (§6.1). `state`, `lynomia_account_id`,
`link_mode`, `owner_user_id`, `lynomia_commerce_store_id` and the credential version are untouched.

## 5. Is store 26 / account 135 recoverable?

Yes, and it is now pinned by a test rather than argued:
`LynomiaAuthorizationRecoveryTest::test_the_production_row_shape_for_store_26_is_recoverable`
(26 assertions) builds the row as production holds it — `link_mode = provision`, `state = active`,
`provisioning_stage = complete`, commerce installed, **`ownership_proven_at` NULL** — and walks
every step.

| Step | Deciding code | Holds for this row |
|---|---|---|
| 1. The loss is noticed | `ReconcileLynomiaCommerce` gates on `arrangementAllowsSso()`, so an authorization-lost link is still examined | yes — within the hour, with no merchant action |
| 2. The page says so | `CommunicationCenterController` passes `AuthorizationHealth::lost($link)`; the banner pre-empts the workspace card | yes |
| 3. The merchant can mint a code | `POST /api/v1/accounts/135/integrations/account_link_codes` needs only an account administrator — nothing from the platform app | yes, with the grant gone |
| 4. `reauthorize` accepts the row | its only precondition is `isProvisioned()` (`lynomia_account_id !== null`) | yes |
| 5. No predicate demands proven ownership | every one reads `link_mode !== MODE_LINK_EXISTING \|\| ownershipProven()`, satisfied on the left | yes — this is the field that would have excluded exactly this merchant |
| 6. Lynomia accepts the redemption | `account_links#create` is deliberately **not** behind `validate_platform_app_permissible`; nothing refuses a NULL `provisioned_by` | yes |
| 7. Adoption afterwards | the task requires a live permissible, so it must run **after** step 4 | yes, in the runbook's order |

Under a plausible wrong design — recovery requiring `ownershipProven()` — the entire recovery test
set fails, which is how we know the test is load-bearing and not decorative.

## 6. Findings

Six fixed in this branch. Each fix was verified by **removing the control and watching a named test
fail**, then restoring it (`.github/SECURITY-DIFF-REVIEW.md` item 7).

### 6.1 Recovery restored the account grant but not the user grants, so SSO stayed broken — MEDIUM, fixed

`AccountLinker::reauthorize`. Lynomia's revoke destroys the per-**user** permissibles alongside the
account's (`AccountGrants#release_unneeded_user_grants`); redeeming a code restores only the account
one, deliberately — granting the authorizing administrator's identity would hand the app an
*unscoped* session for someone who consented to one workspace. The stale
`lynomia_store_identities` rows then name Lynomia users the app no longer holds,
`IdentityProvisioner` resolves them, skips creation, and
`GET /platform/api/v1/users/:id/login` answers 401 because that route checks the permissible on the
**user**, not the account.

So the merchant would be told their connection was restored and still be unable to open their
inbox — and the next failure re-lights the banner. **A recovery that loops, on the exact row this
procedure exists for.** `handBackGrant` has forgotten the workspace for precisely this reason since
it was written; recovery did not.

*Fix:* `$this->identities->forgetWorkspace($link->lynomia_account_id)` on success. The next sign-in
mints fresh identities via `POST /platform/api/v1/users`, which re-grants the per-user permissible.
*Test:* `test_recovery_forgets_the_stale_identities_so_sso_works_again`.

### 6.2 A merchant's recovery erased an operator's recorded reason — LOW, fixed

`last_error` on that row is written by operator decisions — the suspend/decommission reason at
`InstallationLifecycle.php:213`, the revocation reason at `AccountLinker.php:596`, both up to 480
characters — and `reauthorize` nulled it unconditionally. The audit rows keep a copy but truncate to
200. *Fix:* cleared only on an `active` row, where the only possible writer is a failure of the
grant just restored. *Tests:* `test_recovery_does_not_clear_an_operators_reason_on_a_revoked_link`
and its paired `..._clears_the_stale_error_on_an_active_link`, so the guard cannot be over-broad.

### 6.3 A refused recovery left a grant nothing could revoke — LOW, fixed

`redeemLinkCode` burns the code upstream **and creates the permissible** before the cross-tenant
guard runs. So a correct refusal left Store Syria holding access to the other workspace, referenced
by no row in its database and therefore unreachable by any revocation. `request()` has disposed of
this since it was written — *"the orphaned access this flow exists to prevent"* — and `reauthorize`
was modelled on its ordering without carrying the disposal. Reached without an attack: one
merchant, two stores, the wrong code in the wrong form. *Fix:*
`handBackGrantIfUnreferenced($presented)`, which spares a workspace another store's link still
names. *Tests:* `test_a_refused_recovery_hands_back_the_grant_it_accidentally_created` and
`test_a_refused_recovery_leaves_another_stores_workspace_alone`.

### 6.4 The adoption task let an app convert an invited grant into an unrevocable one — MEDIUM, fixed

`lib/tasks/ops/adopt_provisioned_workspaces.rake`. The marker it inferred ownership from lives in
`accounts.custom_attributes`, which `AccountsController#permitted_params` lets a platform app write
on **any account it holds**. An app granted access the ordinary way — a merchant minting a
connection code, a grant that merchant may withdraw — could `PATCH /platform/api/v1/accounts/:id`
to stamp itself a marker, and the next documented adoption run would convert its revocable grant
into one the merchant cannot end from the only merchant-facing surface there is. **The inverse of
what the column exists for**, and it rode along on a run an operator started for one workspace.

*Fix:* the account ids are now a required `+`-separated argument; the marker corroborates rather
than selects. Also refuses `platform_owned?` accounts (which a partner necessarily holds a
permissible on, and which `accounts_controller#index` excludes for the same reason), and prints the
account age, grant age and whether a code redemption is recorded — **as evidence, never an
automatic skip**, because a provisioned workspace recovered through this very procedure has one too,
so skipping on it would refuse exactly account 135.
*Tests:* `spec/lib/tasks/rake/task_adopt_provisioned_workspaces_spec.rb`, 9 examples, including
`never touches an account the operator did not name`.

### 6.5 A managed connection had no operator exit — LOW, fixed

`docs/commerce/37-platform-managed-connections.md` and the code comments asserted that an operator
could end a managed connection. Nothing implemented it: Super Admin exposes `platform_apps` but no
`platform_app_permissibles`. The real options were asking the app to release itself — the exact
party the merchant surface was built not to depend on — deleting the whole PlatformApp and breaking
every merchant it serves, or an unaudited console write. This also failed the original brief's
requirement to *"preserve a secure, explicit disconnect … initiated from the source platform or by
an authorized operator."*

*Fix:* `rake ops:revoke_managed_connection[<app>,<account>[,commit]]`, calling the same
`AccountGrants.revoke` the source platform's hand-back calls, so it releases the user grants, sweeps
outstanding SSO links and writes the same audit row instead of being a second divergent copy. It
leaves `provisioned_by_platform_app_id` alone: that records that the app created the workspace,
which stays true after the grant is gone. *Tests:*
`spec/lib/tasks/rake/task_revoke_managed_connection_spec.rb`, 4 examples.

### 6.6 A comment claimed a control that is not there — LOW, fixed

`platform_controller.rb`. The comment added by this diff said
`validate_platform_app_permissible` answers "the same whether the resource does not exist or is
simply not this app's". It does not: `set_resource` runs first and `AccountsController` uses
`Account.find`, so a missing id is a 404 before that filter is reached — a caller with a valid
platform token can still distinguish "exists, not mine" from "no such account", and ids are
sequential. A comment asserting a security property the code lacks is worse than no comment.
*Fix:* corrected to describe the real behaviour and point at the spec that pins it. The underlying
difference is **accepted**, see §7.1.

## 7. Accepted, with reasons — for the reviewer to sign off or reject

### 7.1 The Platform API distinguishes a missing account from a forbidden one — LOW

`AccountsController#set_resource` is `Account.find`, so a nonexistent id answers 404 while an id
that exists and is not this app's answers 401. That enumerates account existence to a caller holding
a valid platform token. Collapsing it is a wire-contract change on an endpoint partners branch on
today, predates this incident, and is out of scope for restoring one merchant. Pinned as it behaves
in `spec/requests/platform/api/v1/authorization_signals_spec.rb`. The endpoints added for this
integration use `find_by` and do not have the difference.

### 7.2 Lynomia keeps reading a merchant's orders after the merchant revokes the workspace grant — LOW

`AuthenticateCommerceApi` authorizes the inbound direction on `arrangementAllowsSso()`, so commerce
reads continue while the workspace grant is gone. This is deliberate — the `lsc_` credential proves
the *store*, the merchant withdrew a grant on their *workspace*, and refusing here would flip
Lynomia's commerce store to `needs_reauth` and manufacture a second outage — and it is **not
changed by this diff**: before it, the state stayed `active` and the reads continued identically.

The real gap is that the merchant has no control during the loss: `CommerceActivator::disconnect`
gates on `allowsSso()`, so their Disconnect is refused exactly when they might want it. Not fixed
here because the straightforward fix is wrong: during the loss the pushed
`installationLifecycle('disconnect')` cannot land, while the local credential-version bump would,
leaving Lynomia holding a dead credential that `CommerceState` then reports as `NEEDS_REPAIR`.
**Recommended follow-up:** let `disconnect` proceed locally during an authorization loss (the
version bump *is* the revocation and stops the reads immediately) and let the reconcile sweep settle
the installation record when the grant returns. That changes commerce disconnect semantics during
an outage and is the author's call, not a review fix.

### 7.3 Recovery restores the grant but not the management record — MEDIUM in consequence

Account 135 predates `provisioned_by_platform_app_id`, and `account_links#create` deliberately does
not set it (a code redemption is a merchant *inviting* an app, which must stay revocable — setting
it there would make every invited connection unrevocable). So between step 4 and step 6 of the
runbook the merchant can press Disconnect again. The runbook sequences adoption immediately after
recovery for this reason, and the window is an operator-attended one. **Not auto-closable safely:**
every automatic route runs through claimant-writable evidence, which is §6.4's escalation. The
durable improvement is §7.4.

### 7.4 Nothing reports managed workspaces with no grant — recommended, not built

`provisioned_by_platform_app_id` makes "accounts this app provisioned on which it holds no
permissible" an answerable query, and that set is exactly this incident's population. Nothing asks
it. A periodic operator report would have caught store 26 before the merchant did, and would also
surface §7.3's window. Longer-term.

### 7.5 `account_id: 0` would satisfy the cross-tenant comparison — INFORMATIONAL

`(int) ($proof['account_id'] ?? 0) !== (int) $link->lynomia_account_id` compares 0 against 0 if both
sides are zero. Unreachable: `PlatformApiClient::requireInt` throws `KIND_INVALID_RESPONSE` on an
absent or non-numeric `account_id`, and `isProvisioned()` refuses a NULL link id before the
comparison. Zero impact if reached — the write never changes `lynomia_account_id`, so no store is
repointed. Recorded rather than hardened because a positive-integer check would be the speculative
guard `CLAUDE.md` forbids, and a hostile Lynomia would simply return a real id it controls, which
the guard already handles.

## 8. Credential handling

No secret reaches a merchant, a log, an audit row, a flash, a view or a response in either diff.
Specifically checked: `AuthorizationHealth` audit payloads carry ids and timestamps only;
`lynomia_authorization_recovery_refused` records `expected_account_id` and `presented_account_id` —
this app's own view of a workspace it was just shown, not another tenant's data, and **not** the
other workspace's name; the connection code is never echoed into a flash, a log, a validation error
or an audit row on any path, success or failure; `AuthenticateCommerceApi::refuse` logs reason,
status and path only; `IntegrationController::issueCommerceCredential` keeps the credential out of
the database, the log and the audit row, and its one-time flash is unchanged. The `Secret scan` job
passes on every head, including the two docs' placeholder `lsc_26_3_<64 hex>` forms.

The new `error_code` on the two 401s leaks nothing: an invalid token is refused by
`set_platform_app` **before** `set_resource`, so it cannot be used as an existence oracle, and a
valid-token caller learns only *why* it was refused — a distinction it could already draw from the
401/404 status difference in §7.1.

## 9. Lifecycle permissions

`authorization_lost_at` is an axis orthogonal to `state`, and reachable only on an `ACTIVE` row:
all three writers (`SsoLinkIssuer`, `CommerceActivator`, the reconcile sweep) sit behind predicates
requiring `state ∈ SSO_ALLOWED = [ACTIVE]`. Moving to `SUSPENDED` or `DECOMMISSIONED` afterwards
requires a platform push that 401s while the grant is gone, so `InstallationLifecycle::move` throws
and the state cannot change; only `AccountLinker::revoke`, best-effort on the wire, can land
`REVOKED` on a flagged row. In that one combination recovery grants the merchant **no capability** —
every surface still requires `ACTIVE` — and the flash says so rather than claiming the connection
was restored (`test_a_revoked_link_is_not_told_its_connection_is_restored`).

No state gate is added to `reauthorize`, deliberately: it would fold health back into state — the
collapse the `allowsSso()` / `arrangementAllowsSso()` split exists to undo — and would block a
merchant restoring the grant *before* an operator's `restore()`, whose `stopInstallation('resume')`
cannot succeed without it.

Also confirmed: widening `$permitted` in `AuthenticateCommerceApi` cannot widen the recovery-window
exception below it, because that branch is gated on `! $permitted`. It now fires *less* — an
authorization-lost link no longer burns an operator's recovery window on a routine identity read.

## 10. Test evidence

| Suite | Result |
|---|---|
| `php artisan test tests/Feature/Lynomia` | **708 passed**, 6987 assertions |
| `vendor/bin/phpunit --testsuite Unit` | 175 passed |
| `vendor/bin/phpunit --testsuite Contract` | 26 passed |
| `bundle exec rspec spec/requests/platform spec/requests/api/v1/accounts/integrations` | 249 passed |
| `bundle exec rspec spec/lib/tasks/rake/task_adopt_provisioned_workspaces_spec.rb` | 9 passed |
| `bundle exec rspec spec/lib/tasks/rake/task_revoke_managed_connection_spec.rb` | 4 passed |
| `bundle exec rubocop --parallel` | 3334 files, no offenses |
| Merge-base regression gate | `new failures: 0`, `inherited (UNACCEPTED drift): 0` |

Controls verified by removal (item 7) — each fails its named test with the control gone and passes
restored: the cross-tenant `account_id` comparison; `EnsureStoreOwnsLink` on `connect.recover`; the
link-state gate remaining in `AuthenticateCommerceApi`; the operator credential gate; the
`last_error` guard; the orphaned-grant hand-back; the identity forgetting; and recovery's acceptance
of a `MODE_PROVISION` row with unproven ownership.

The two pre-existing `tests/Feature` failures (`Auth\RegistrationTest::test_new_users_can_register`,
`ProfileTest::test_profile_page_is_displayed`) reproduce with this branch stashed and are matched in
`.github/known-test-outcomes.json`. Per the checklist's closing note, this review does not accept
them; reconciling that manifest remains the owner's.
