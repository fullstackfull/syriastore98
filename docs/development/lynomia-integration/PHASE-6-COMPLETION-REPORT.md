# Phase 6 Completion Report — Store Syria × Lynomia Chat

**Integration Administration, Partner Operations & Platform Communication Operations**

| | |
|---|---|
| Phase | Integration Phase 6 (P6A–P6F) |
| Authority | FINAL OWNER APPROVAL — INTEGRATION PHASE 6, four owner decisions resolved |
| Branch, both repositories | `claude/store-syria-lynomia-integration-1oa1tt` |
| Store Syria HEAD | `a24c974f`, plus the documentation commit that carries this report |
| Lynomia HEAD | `e08f24e8` |
| Status | **CONDITIONAL GO for staging. NOT production-ready. A1 remains OPEN.** |

---

## 1. Executive summary

Phase 6 asked for four things that did not exist: each side being able to see the other's
view of an installation, an explicit lifecycle for an installation that both sides agree on,
a workspace Store Syria owns and pays for so it can talk to its own merchants, and
administration of all of it from both Super Admins.

All four are implemented. Nothing in this phase was built by adding a second engine: the
transition channel is a facade over `Commerce::StoreConnection`, the health panel is a
projection of `Operations::Health`, `Commerce::StoreHealth` and `Operations::Signal`, the
platform workspace is an ordinary Lynomia account with an ordinary `BillingSubscription`, and
the notices it sends go out through Lynomia's own WhatsApp channel, template store and send
path. The integration gained no messaging engine, no scheduler, no template store and no
consent store.

**Three things are worth the owner's attention before anything else.**

First, the two pre-existing safety issues the approval required to be settled first — B3.1
and B3.2 — were both open and are both now closed, in Lynomia `7db45646` and Store Syria
`cc213d58`, before the platform communication work that would have sat on top of them.
Neither was bypassed.

Second, the authorisation's hardest constraint — that the platform workspace may send
essential operational communication and nothing promotional — is enforced on **Meta's own
classification of the template**, read off the channel's synced list, at the single chokepoint
every WhatsApp template send reaches. There is no field an operator can set to relabel a
MARKETING template as operational, because nothing a caller declares is consulted.

Third, **A1 is still open and this phase could not close it.** No run against a live Lynomia
has ever happened. Every result below was produced against faked HTTP, a local Postgres and a
local SQLite, and that is exactly what the approval permitted and required to be stated
plainly. Nothing here is evidence that a message has ever reached a merchant.

Two adversarial rounds found **thirteen defects between them**, every one a property the
code asserted in its own docblock and did not have. All thirteen are fixed, each red-proofed
by putting the defect back and watching only its own example fail. The second round was run
independently, five lenses with a refutation pass over every finding, and it earned its
keep: it found that **round one's own P6B fix was incomplete, in the method round one had
just edited** — `AccountLinker::revoke` returns the Lynomia account grant before pushing the
stop, so for a linked workspace the push gets 401 and the commerce store stays authorised,
which is exactly the defect P6B existed to close. Ten of its twelve escalated findings
survived refutation; the six it killed are recorded too. §13 has all of it.

---

## 2. P6A–P6F implementation status

| Workstream | Capability | Status |
|---|---|---|
| **P6A** | Lynomia-side installation health, read by the partner, confined by `platform_app_permissibles` | **VERIFIED** |
| P6A | Store Syria Super Admin panel showing Lynomia's view of each installation, fetched per row | **VERIFIED** |
| P6A | Projection of `Operations::Health` / `Commerce::StoreHealth` / `Operations::Signal` — no second health model | **VERIFIED** |
| P6A | Operator-visible signal when an outbound consent decision is retired (B3.2 half) | **VERIFIED** |
| **P6B** | Suspend / resume with a reason, distinct from revoke | **VERIFIED** |
| P6B | Re-authentication: a new commerce credential without reissuing by hand | **VERIFIED** |
| P6B | Decommission, honest about what it keeps (conversations and consent evidence survive) | **VERIFIED** |
| P6B | Every transition carried to Lynomia's own store lifecycle, idempotently | **VERIFIED** |
| P6B | Transitions refused when the state no longer permits them, decided under the row lock | **VERIFIED** |
| **P6C** | Lynomia Super Admin: every partner integration, what it is permitted, which workspaces it holds | **VERIFIED** |
| P6C | Per-installation detail reusing the same projection the partner reads, so the two consoles cannot disagree | **VERIFIED** |
| P6C | Recent partner activity read from the audit trail | **VERIFIED** |
| P6C | No new authorisation model; `PlatformApp` + `platform_app_permissibles` unchanged | **VERIFIED** |
| **P6D** | `accounts.platform_owned`, set at creation, with `platform_owned` / `tenant` scopes | **VERIFIED** |
| P6D | Independent paid subscription, provisioned deliberately, never a comp and never billing-exempt | **VERIFIED** |
| P6D | The platform plan must not be sellable, and must meter — both refused at provisioning | **VERIFIED** |
| P6D | Excluded from the merchant trial, the trial backfill, merchant account health and every billing figure | **VERIFIED** |
| P6D | Never listed to a partner among its merchants' accounts | **VERIFIED** |
| P6D | Its own `campaign_sends` allowance, consumed by its own sending | **VERIFIED** |
| **P6E** | Operational notices to merchants from the platform workspace, through Lynomia's own send path | **IMPLEMENTED** (not transmitted — see §15) |
| P6E | Recipients resolved from the merchant's own record; an operator never types a number | **VERIFIED** |
| P6E | Sent once: one row per occasion and one send per row, both at the database | **VERIFIED** |
| P6E | UTILITY-only for the platform workspace, on Meta's classification, not a caller's label | **VERIFIED** |
| P6E | Metered against the platform allowance on the conversation path as well as the campaign path | **VERIFIED** |
| P6E | A lost answer is never retried automatically; an operator decides, with the row in front of them | **VERIFIED** |
| P6E | Merchant opt-out that is not the shopper surface | **PARTIAL** — the WhatsApp STOP keyword works and blocks both classes; there is no merchant-facing screen for it (§15) |
| P6E | Email as a second channel | **NOT STARTED** — explicitly excluded by Decision 3 |
| **P6F** | Adversarial review of this phase's work, including the previous round's fixes | **VERIFIED** — two rounds, thirteen defects found and fixed (§13) |
| P6F | Browser verification of the new operator surfaces, English and Arabic | **VERIFIED** — 51 checks |
| P6F | Full regression sweep, both repositories | **VERIFIED** — §11 |
| P6F | B3.1 and B3.2 settled before platform communications were built on them | **VERIFIED** |

---

## 3. Reused and extended existing subsystems

Nothing below was reimplemented. Each row is a subsystem Phase 6 called into.

| Subsystem | Owner | How Phase 6 used it |
|---|---|---|
| `Commerce::StoreConnection` | Lynomia | `Commerce::InstallationLifecycle` is a facade over `disable` / `enable` / `disconnect`. Those already purge the cache, release provider registrations, keep the plan's store limit and write the audit row |
| `Operations::Health`, `Commerce::StoreHealth`, `Operations::Signal` | Lynomia | `Operations::IntegrationHealth` selects from them and renames nothing, including the escalation to critical at three consecutive failures |
| `PlatformApp` + `platform_app_permissibles` | Lynomia | The only authorisation for every new platform endpoint. Not widened |
| `BillingPlan`, `BillingSubscription`, `Billing::FeatureSync`, `Billing::Entitlements`, `Billing::MeteredUsage` | Lynomia | The platform workspace's subscription is an ordinary row in the existing engine. No parallel billing |
| `Whatsapp::SendOnWhatsappService` | Lynomia (OSS) | Prepended, not forked. The consent and platform gates sit on the one path every template send reaches |
| `Whatsapp::ChannelTemplates` | Lynomia | The template category comes from the shared lookup — the same one the flow node and the automation action use |
| `Commerce::MessagingEligibility`, `Commerce::ConsentListener`, `Commerce::MessagingConsents` | Lynomia | The consent question, asked unchanged. A shopper's or a merchant's STOP writes a withdrawal in any account |
| `Campaigns::Eligibility::TRANSACTIONAL_CATEGORIES` | Lynomia | One definition of "transactional", shared by the campaign path and the conversation path |
| Administrate Super Admin shell | Lynomia | The partner integrations console is hand-written ERB inside it, like the Operations Center, with the required navigation exclusion |
| `AccountProvisioner`, `PlatformApiClient`, `AgentApiClient`, `LynomiaHttp` | Store Syria | `PlatformWorkspaceGateway` mints a scoped token and drives the account API with the existing clients |
| `LynomiaStoreLink`, `LinkState`, `CommerceCredential` | Store Syria | The lifecycle moves the existing link through two new states; the credential path is the existing one |
| `AuditLog` | Store Syria | Every transition and every notice writes to the existing trail |
| Laratrust roles and permissions | Store Syria | The operator surfaces sit behind the existing `PlatformOperatorOnly` middleware. No new role |

---

## 4. New platform workspace architecture

A Lynomia account marked `platform_owned`, owned commercially by Store Syria, from which
Store Syria talks to its own merchants.

```
Store Syria (Laravel)                         Lynomia (Rails)
───────────────────────                       ────────────────────────────
InstallationLifecycle ──┐
PlatformNotifier ───────┤
   ├─ PlatformRecipients│   (who may be told, and at what number — local only)
   └─ PlatformWorkspaceGateway
         ├─ PlatformApiClient ──── POST /platform/api/v1/users/:id/login  (scoped token)
         └─ AgentApiClient ─────── POST /api/v1/accounts/:id/contacts|conversations|messages
                                           │
                                           ▼
                                   Account(platform_owned: true)
                                     ├─ BillingSubscription(source: 'manual')
                                     ├─ AccountUser(service identity, administrator)
                                     ├─ PlatformAppPermissible(account)
                                     ├─ PlatformAppPermissible(sender user)
                                     └─ Channel::Whatsapp  ← the platform's OWN channel
                                           │
                                   Custom::Whatsapp::SendOnWhatsappService
                                     ├─ platform_refusal  (UTILITY only, then meter)
                                     └─ consent_refusal   (withdrawal / suppression)
```

**Why it is provisioned by a service and not by signup.** An account created the ordinary way
gets the merchant trial on an `after_create_commit`, lands in the sales figures and is swept
by the trial backfill. `platform_owned` stops all three — but only if it is set at creation,
which is why this is one deliberate operator act rather than a flag somebody remembers to
tick afterwards.

**Why the service identity and the two permissibles are created in the same transaction.**
Store Syria reaches the workspace through the ACCOUNT api, because there is no platform
messaging endpoint — that namespace has users, accounts, grants and consents and nothing for
contacts, conversations or messages. To mint the scoped token,
`Platform::Api::V1::UsersController#authorized_account_for_scope` requires **both** that the
app holds a permissible on the account **and** that the sender is an account_user of it. A
workspace provisioned without the pair looks correct and cannot send anything.

**The sender is not a person.** Its address is in a reserved domain that cannot receive mail,
so it can never collide with a real user and nobody can be signed in as it by accident — the
same reasoning `AccountProvisioner` uses for a merchant workspace's service address.

| Property | Status |
|---|---|
| Marked platform-owned at creation, never afterwards | **VERIFIED** |
| One per installation — a second is refused | **VERIFIED** |
| Service identity cannot be a real person's address | **VERIFIED** |
| Never returned by the partner's account listing | **VERIFIED** |
| Reachable by id through `show`, which is permissible-checked | **VERIFIED** |
| Nothing verifies, across the wire, that the id Store Syria is configured with IS this account | **BLOCKED** — recorded as B5, §16 |

---

## 5. Independent subscription and entitlement model

Decision 1: a separate **paid** subscription funded by Store Syria. Not a comp, not a
permanently free account, not billing-exempt — and no real charge created in this phase.

| Requirement from Decision 1 | How it is met | Status |
|---|---|---|
| Independent subscription | `BillingSubscription(status: 'active', source: 'manual', plan:, quantity: 1)`, created with the account in one transaction | **VERIFIED** |
| Independent entitlements | `Billing::FeatureSync` applies the plan's features exactly as it does for a merchant | **VERIFIED** |
| Independent quotas | The plan must set `campaign_sends`, refused at provisioning if it does not | **VERIFIED** |
| Billing records | The existing engine's own rows. No parallel model, no new table | **VERIFIED** |
| Preserve Lynomia's billing architecture | Nothing in `custom/app/services/billing/` was changed except to add the new provisioner and three `tenant` scopings | **VERIFIED** |
| Never consumes a merchant allowance | `Billing::MeteredUsage` counters are keyed on `account_id`; the platform workspace has its own | **VERIFIED** |
| Merchants never subsidise it | Excluded from every merchant-facing aggregate — enumerated below | **VERIFIED** |
| No real charge, no production payment | `source: 'manual'`, no `stripe_subscription_id`, no `stripe_price_id`, nothing calls Stripe on this path | **VERIFIED** |
| Not a comp or a free account | Provisioning refuses a plan that is sellable, and refuses one that does not meter | **VERIFIED** |

**Two refusals at provisioning, and why each exists.**

`refuse_sellable_plan!` — a plan that is `active` **and** has a `stripe_price_id` is exactly
what `Billing::Checkout`, `Billing::PlanChange` and the merchant's own upgrade list treat as
available. Using one for the platform workspace would put it in every merchant's upgrade
list. The condition is not invented here; it is copied from those three call sites.

`refuse_unmetered_plan!` — `Billing::Entitlements.limit` returns nil when nothing sets
`campaign_sends`, and `Billing::MeteredUsage.consume` returns immediately on a nil limit
**without writing a counter row**. A plan that omits it would give this workspace unlimited,
unrecorded sending, which is the opposite of the isolation it exists to provide.

**Every merchant-facing aggregate, and where the platform workspace was removed from it.**

| Aggregate | Change |
|---|---|
| `Custom::Account#start_billing_trial` | `return if platform_owned?` — a platform workspace is not a prospect |
| `Billing::TrialStarter.backfill!` | `Account.tenant.where.missing(:billing_subscription)` |
| `Operations::AccountHealth` | `Account.tenant.includes(:billing_subscription)` |
| `Platform::Api::V1::Billing::StatsController` | every figure scoped at source through `tenant_subscriptions`, not only the headline count |
| `Platform::Api::V1::AccountsController#index` | `.reject(&:platform_owned?)` |

Aggregates deliberately left counting it: `SuperAdmin::DashboardController`,
`Operations::Overview` and `Operations::BillingState`. All three are Lynomia's own operator
console, where the installation's own workspace is part of what the installation runs and
hiding it would be the defect. **Status: VERIFIED as deliberate.**

One boundary, stated rather than discovered later:
`Platform::Api::V1::Billing::SubscriptionsController#index` is scoped to the app's
permissibles and would therefore include the platform workspace's own subscription. Store
Syria does not call any billing endpoint — a search of the whole module finds no caller — and
Store Syria is that subscription's commercial owner in any case. **Status: VERIFIED as not a
leak**, recorded because a future caller would need to know.

---

## 6. Partner and installation lifecycle

Two state machines that had no channel between them. Lynomia holds
`{active, disabled, needs_reauth, disconnected}` on `commerce_stores.status`; Store Syria
holds `{pending_verification, active, suspended, revoked, decommissioned}` on
`lynomia_store_links.state`. Before this phase **nothing carried a change from one to the
other** — a partner revoking an installation tore down the account grant and left the commerce
store `active`, so `Partners::Eligibility` went on authorising that installation's campaigns
indefinitely, while the operator who revoked it had every reason to believe they had stopped it.

| Transition | Store Syria state | Carried to Lynomia as | Status |
|---|---|---|---|
| Suspend | `active` → `suspended` | `suspend` → `disabled` | **VERIFIED** |
| Resume | `suspended` or `revoked` → `active` | `resume` → `active`, through `enable`, which health-checks | **VERIFIED** |
| Revoke | `active` → `revoked` | `suspend` → `disabled` | **VERIFIED** |
| Restore | `revoked` → `active` | `resume` | **VERIFIED** |
| Re-authenticate | credential version bumped; resume attempted | `resume`, best effort | **VERIFIED** |
| Decommission | any live state → `decommissioned`, terminal | `disconnect` → `disconnected`, credentials dropped | **VERIFIED** |

**Push first, write locally second.** The remote call happens before the local state moves,
so a transition Lynomia refuses does not leave the two sides disagreeing in the direction
that matters — Store Syria believing an installation is stopped while Lynomia goes on
authorising it.

**Idempotent on the Lynomia side.** A partner's outbox is at-least-once like every other
delivery in this integration, so asking for a state the store is already in succeeds and
changes nothing (`applied: false`) rather than erroring and leaving the caller to guess
whether the first attempt landed.

**Re-authentication is best effort by design, and this is the subtle one.** The commerce
credential is never stored on either side: it is minted in Store Syria, shown to an operator
once, and pasted into Lynomia by them. Bumping the version invalidates the credential Lynomia
is holding at that instant, so the resume that follows asks Lynomia to health-check a store
whose credential has deliberately just stopped working. It is expected to fail, and it must
not take the new credential down with it — an operator left looking at an error with no
credential to paste is strictly worse off than before they pressed the button. So the resume
is attempted, its outcome is reported, and the credential is returned either way.

**What `decommission` keeps, stated because it is a compliance question.** The workspace, its
conversations and its consent records all survive. What a person was told, and what they
agreed to, outlives the commercial arrangement that produced it.

**Concurrency.** Both halves of the channel now decide under a lock. Lynomia's
`Commerce::InstallationLifecycle` wraps the read and the write in `store.with_lock`; Store
Syria's `InstallationLifecycle::move` did not until the review of this phase found it (§13,
finding 3).

---

## 7. Operational communications capabilities

Decision 2 authorised essential operational notification only, and named what is not
authorised: promotional campaigns, feature marketing, commercial advertising, product
recommendations, unsolicited acquisition, cross-selling, bulk promotional broadcasts.

| Control the owner asked for | How it is enforced | Status |
|---|---|---|
| Essential operational only | The platform workspace may send **only** a template Meta classifies `UTILITY` | **VERIFIED** |
| Purpose classified by verified provider rules, not a client-provided category | The category is read off `channel.message_templates`, Meta's own synced list, through the shared `Whatsapp::ChannelTemplates` lookup. Nothing a caller passes is consulted | **VERIFIED** |
| An administrator must not be able to relabel promotional as transactional | There is no field to set. `PlatformNotifier` takes a template NAME; the class comes from the provider's record of that template | **VERIFIED** |
| No ordinary staff override of provider restrictions, withdrawals or suppressions | `consent_refusal` runs on every send and is not conditional on who asked. There is no override parameter anywhere on the path | **VERIFIED** |
| Authorised occasions only | `LynomiaPlatformNotice::KINDS` = onboarding, integration_recovery, security, service, support. An unknown kind raises before anything is written | **VERIFIED** |
| Recipients are verified merchants, not an address somebody typed | `PlatformRecipients::resolve` takes a link and nothing else — asserted by a reflection test on its signature | **VERIFIED** |
| Said once | Unique `dedupe_key` for one row per occasion, **and** a conditional `pending → sending` update for one send per row | **VERIFIED** |
| Never silently retried after a lost answer | A transport failure lands on `indeterminate`, which is never reopened. An operator decides, with the row in front of them | **VERIFIED** |

**The order of the two gates matters and is deliberate.** `platform_refusal` runs before
`consent_refusal`, because consent alone would not have done the job: `consent_purpose` falls
back to `nil` for a promotional template in an account without the commerce feature, and the
platform workspace has no commerce store — so consent alone would have let a MARKETING
template through to any merchant who had not explicitly refused. That is the opposite of what
the authorisation says.

**Metered on the conversation path, not only the campaign path.** P6D requires the platform
plan to set `campaign_sends` precisely so platform sending is counted. That meter lives on the
campaign path; this is the conversation path, which would otherwise have bypassed it entirely
— an unmetered, unrecorded channel out of a workspace whose whole point was that its sending
is bounded.

**What a merchant can do about it.** A merchant replying STOP on WhatsApp writes a
`withdrawn` row through `Commerce::ConsentListener`, which is registered unconditionally, and
one keyword withdraws **both** classes. `Commerce::MessagingEligibility` then refuses
transactional as well as promotional. So the opt-out works, it is the provider-native
surface, and it is not the shopper checkout box. What does not exist is a merchant-facing
screen showing their own state or letting them opt back in — recorded as PARTIAL in §15.

**Nothing is transmitted in this phase.** The integration ships with the three platform
workspace ids unset, so `PlatformWorkspaceGateway::configured()` is false, every notice is
recorded `failed` with the reason, and no request is made. That is also the safe test path
Decision 3 asked for: the capability is exercisable end to end against faked HTTP, and
against an unconfigured installation, without a message existing.

---

## 8. Both Super Admin interfaces

Decision 4: integration monitoring in **both** Super Admin platforms, on verified data, not
fabricated health states.

### Store Syria Super Admin — `/super-admin/lynomia-integration`

| Tab | What Phase 6 added | Status |
|---|---|---|
| Overview | Event delivery, commerce outbox backlog with the stores whose events are not being delivered, consent decisions going out including the ones retired after 30 days | **VERIFIED** |
| Merchant Accounts | Per-state lifecycle controls, and **In Lynomia** — the far side's health, fetched per row on demand | **VERIFIED** |
| Merchant notices | Sent / not delivered / awaiting an operator, the recent notices with their failure reasons, and the workspace and inbox they are sent from | **VERIFIED** |
| Automation | Unchanged from Phase 4; counts, never people | **VERIFIED** |

### Lynomia Super Admin — `/super_admin/partner_integrations`

| Surface | What it answers | Status |
|---|---|---|
| List | Every partner integration, how many workspaces it holds, how many open signals across them | **VERIFIED** |
| Detail | What the app is permitted (counted by type, never a roster of people), each workspace with the same `Operations::IntegrationHealth` projection the partner reads, its entitlement grants, and recent partner activity from the audit trail | **VERIFIED** |
| Read only | No operator mutation on this surface at all; every route is a GET | **VERIFIED** |

**The two consoles cannot drift**, because the per-installation view on both sides is the
same `Operations::IntegrationHealth` object. An operator here and an operator there are
looking at the same facts rather than at two renderings that can disagree about the same store.

**Verified data, never a fabricated state.** The Store Syria page is built from local tables
on purpose — the times an operator opens it are the times Lynomia may be what is wrong — and
the one panel that reaches across does so from the browser, per row, so its failure is a
sentence in one cell rather than a page that will not load. The browser harness asserts all
three cases: reachable prints Lynomia's own counts, unreachable and connection-refused print
the failure and **never** a health word, and the rest of the page is still there.

**The navigation obligation was paid in the same commit.** Administrate enumerates every
controller under `super_admin/` and asks each for an index route and a dashboard; a
hand-written one raises inside the navigation partial and breaks **every** Super Admin page,
not just its own. `partner_integrations` is in the exclusion list in
`app/views/super_admin/application/_navigation.html.erb`.

---

## 9. Security and tenant-isolation results

| Property | Evidence | Status |
|---|---|---|
| Both new platform endpoints declare their authorisation rather than inheriting it | `PlatformController` applies `set_resource` and `validate_platform_app_permissible` with `only: [:update, :show, :destroy]`. An action named anything else — `:health`, `:status`, `:create` on a singular resource — inherits NEITHER and would answer any valid token for any account id in the URL. Both controllers are named `show`/`update` **and** restate both filters | **VERIFIED** |
| A non-existent account and a non-permissible one give the same answer | `set_resource` renders `non_permissible_resource` / 401 for both, so whether an account id exists is not something an unauthorised caller learns by asking | **VERIFIED** |
| Every lifecycle transition is a POST | Measured on the **booted router**, not read off the routes file. The first draft of that test read the file and matched the word POST from one route against the name from another — a test that passes while the route is a GET | **VERIFIED** |
| Every lifecycle control on the page posts and carries a CSRF token | Asserted in the browser, per row, on the DOM | **VERIFIED** |
| The health projection carries no credential, customer, phone number or provider body | The allow-list is written out rather than produced by `as_json`; every field is an id, a count, an enum, a timestamp or one of Lynomia's own classified error codes | **VERIFIED** |
| Consent figures are confined to the calling app | `partner_consents` and their event log are scoped by `platform_app_id`, so a second integration on the same workspace cannot read this one's consent traffic through an endpoint about health | **VERIFIED** |
| The partner's own console names no people | `Operations::PartnerOverview#permissions` counts permissibles by type; a listing of every user an app may sign in as is a roster of people | **VERIFIED** |
| The platform credential is never persisted, cast, logged or serialised | Read from the environment per use; the scoped token is cached in the `array` store only, because this installation's default cache driver is `file` and caching a bearer there writes a live credential to disk | **VERIFIED** |
| No administrative credential reaches a merchant browser | Carried forward from Phase 4's property tests, unchanged | **VERIFIED** |
| The irreversible transition asks first | Found broken, fixed, and now asserted in a browser by clicking it (§13, finding 4) | **VERIFIED** |
| A store that changed hands is not messaged | `PlatformRecipients` compares the store's current owner against the owner recorded on the link | **VERIFIED** |
| A decommissioned or revoked installation is not messaged | `REACHABLE_STATES` is `[ACTIVE, SUSPENDED]`. Suspended is included deliberately: a suspended installation is when the merchant most needs to hear from the platform | **VERIFIED** |
| No unofficial WhatsApp integration | Nothing in either diff touches QR, Web or any unofficial transport. Sending is Lynomia's official Cloud API channel | **VERIFIED** |
| Consent enforcement was strengthened, never weakened | B3.1 closed a third outbound surface that asked nothing | **VERIFIED** |
| Installation and account identity checks were strengthened, never bypassed | The lifecycle guard moved **inside** the row lock | **VERIFIED** |
| A partner cannot take a store out of `disconnected` | `suspend` on a disconnected store is a no-op. `disconnect` drops the credentials and leaves that state precisely so the merchant can reconnect, and `StoreConnection#claim` refuses any row that is not in it — so rewriting it to `disabled` closed a door on the merchant's side of the boundary | **VERIFIED** |
| Platform sending fails closed if its plan stops metering | The absence of a `campaign_sends` ceiling is a refusal for a platform-owned account. Provisioning refuses an unmetered plan, but nothing held that true afterwards: an operator, or the partner's own token through the billing plans endpoint, can remove the limit from under a live workspace, and `MeteredUsage.consume` waves a nil limit through without writing a counter | **VERIFIED** |
| The platform allowance is spent after consent, not before | `consume` increments; taking the unit before the consent question spent it on a message the system then refused | **VERIFIED** |
| One remaining isolation gap, stated | `Operations::IntegrationHealth#installations` is scoped by account, not by app, because `commerce_stores` has no `platform_app_id`. Two partner integrations on one workspace would each see the other's `external_store_id`, provider, name and error reason. Not reachable today — the unique index on `(provider, external_store_id)` and the product shape both give one commerce installation per workspace | **PARTIAL**, recorded |

---

## 10. Cross-repository contracts

| Contract | Change in Phase 6 |
|---|---|
| Two SHA-pinned contract artifacts | **Unchanged.** No version bump was needed and none was made |
| `POST /platform/api/v1/accounts/:account_id/installation_lifecycle` | **NEW.** `{provider, external_store_id, transition, reason?, actor?}` → `{provider, external_store_id, status, applied}`. `transition` ∈ {suspend, resume, disconnect}. 422 on a missing or unknown parameter, 404 on an installation this account does not have, 409 on a resume that could not be applied |
| `GET /platform/api/v1/accounts/:account_id/integration_health` | **NEW.** `{account, status, installations[], signals{open,by_source,recent[]}, consent, messaging, generated_at}` |
| `GET /platform/api/v1/accounts` | **CHANGED, narrowing only.** Platform-owned workspaces are no longer listed. A partner that held one in its list loses a row it should never have had; nothing else moves |
| Error-code vocabulary | Extended with `unknown_transition`, `installation_not_found`, `resume_failed`. Store Syria classes `resume_failed` retryable and the other two permanent |
| `platform_app_permissibles` semantics | **Unchanged.** Not widened |

The partner's transition vocabulary is deliberately the partner's: Store Syria does not have
to learn Lynomia's enum, and Lynomia does not have to keep its enum stable for the partner's
sake.

---

## 11. Automated test results

All results below were produced on the frozen heads in the header. Every Lynomia HTTP call in
the Store Syria suites is faked; no request left either machine.

### Store Syria — `fullstackfull/syriastore98`

| Suite | Result |
|---|---|
| Feature (gated) | **1,957 tests, 13,489 assertions, 8 failures, 17 skipped** — phpunit exit 1 |
| CI outcome gate | **PASS** — "the observed outcome set matches the manifest exactly: 8 declared failures, 17 declared skips, 0 errors, and nothing undeclared" (exit 0) |
| Unit | **181 tests, 1,035 assertions, 0 failures** |
| Contract | **14 tests, 43 assertions, 0 failures** |

New and extended test files in this phase:

| File | Examples | What it pins |
|---|---|---|
| `tests/Feature/Lynomia/LynomiaInstallationLifecycleTest.php` | 20 | Every transition, what each carries to Lynomia, idempotency, the states each may start from, a transition decided on a stale read refused under the lock, the order of the stop and the hand-back on a revoke, and a reissue yielding its credential however the resume fails |
| `tests/Feature/Lynomia/LynomiaPlatformNoticeTest.php` | 22 | Said once under concurrency, a lost answer never retried, a refusal recorded with its reason, an occasion that failed before anything was sent tried again, a repeated suspension telling the merchant nothing, and the resolver never taking an address from a caller |
| `tests/Feature/Lynomia/LynomiaIntegrationHealthTest.php` | 10 | The far side's health read, rendered, and failing safely |
| `tests/Feature/Lynomia/LynomiaPhase6SecurityTest.php` | 3 | No `@json` in a double-quoted attribute; every lifecycle route a POST on the booted router; the operator page reaches nothing across the wire |
| `tests/Feature/Lynomia/LynomiaTranslationCoverageTest.php` | 4 | Every `__()` string in both dictionaries, `en.json` an identity registry, every Arabic entry actually Arabic, no dropped placeholder |

### Lynomia — `fullstackfull/lynomiachat`

| Suite | Result |
|---|---|
| Full RSpec sweep | **11,319 examples, 0 failures, 70 pending** — 48m26s, on the frozen tree at `e08f24e8` |
| The six new Phase 6 spec files | **73 examples, 0 failures** |

New spec files in this phase:

| File | Examples | What it pins |
|---|---|---|
| `spec/services/billing/platform_workspace_spec.rb` | 15 | Provisioning, both refusals, the service identity and the two permissibles, and every exclusion from the merchant-facing figures |
| `spec/requests/platform/api/v1/installation_lifecycle_spec.rb` | 14 | Each transition, idempotency, the locked decision, and that a store the merchant disconnected is left alone |
| `spec/requests/platform/api/v1/integration_health_spec.rb` | 13 | The projection's fields, its confinement to the calling app, and that `open` counts every signal while `recent` lists twenty |
| `spec/controllers/super_admin/partner_integrations_controller_spec.rb` | 13 | The Super Admin boundary, the navigation it must not break, counts rather than people, no credential on the page, and the activity table with a row in it |
| `spec/services/whatsapp/composer_consent_spec.rb` | 9 | B3.1 — the composer asking what the person agreed to |
| `spec/services/whatsapp/platform_workspace_sending_spec.rb` | 9 | UTILITY only, metered, the meter taken after consent, a plan that stops metering refused, and a merchant workspace unaffected |

**Three full sweeps were run, and the first one is worth naming.** It reported **4 failures**,
all of them `RedisClient::CannotConnectError — Connection refused 127.0.0.1:6379`: Redis had
not been started after a container restart, so `api_controller_spec`'s readiness endpoint
answered 503 and `Lynomia::QueueHealthJob` could not read Sidekiq's queue depths. None of the
four touches Phase 6 code; with Redis running those same two files pass (13 examples, 0
failures). The second sweep, after starting it, was clean at 11,313 examples. The third, above,
is the one that counts: it ran on the tree that carries round two's corrections.

**All 70 pending are the single MFA guard** — 54 "Skipping since MFA is not configured in this
environment" and 16 "encryption keys missing; see run_mfa_spec workflow". The same family
Phase 4 recorded, and no Phase 6 code is involved.

**One pre-existing failure that this sweep does not show, and why.**
`spec/controllers/platform/api/v1/accounts_controller_spec.rb:48` and `:203` fail whenever
`installation_configs` is populated — which `rails db:migrate` does, and which production
always is — because they assert an exact feature set on a created account while the
installation's own `ACCOUNT_LEVEL_FEATURE_DEFAULTS` adds to it. They pass here because an
earlier spec in the run truncates that table. Bisected across all seven Phase 6 commits and
reproduced identically at `7db45646~1`, before any Phase 6 code existed. Recorded rather than
changed: re-baselining somebody else's spec to match a local database state is how a spec
stops being evidence.

---

## 12. Browser verification results

`bash tests/Browser/run.sh` — nine harnesses, rendering the real views with the real
translations and the real routes against a stand-in layout that loads the dashboard's own
jQuery and Bootstrap.

| Harness | Checks | Result |
|---|---|---|
| p4c-flow-builder | 49 | pass |
| p4d-channels | 14 | pass |
| p4e-commerce-automation | 25 | pass |
| p4f-templates-history | 15 | pass |
| p5b-audiences | 18 | pass |
| p5c-templates | 23 | pass |
| p5d-campaigns | 30 | pass |
| p5e-store-consent | 29 | pass |
| **p6-operator-console** (new) | **62** | **pass** |
| **Total** | **265 checks** | **0 failures** |

`p6-operator-console` is the first non-merchant page in the harness, and it is there because
four of Phase 6's workstreams landed on one Super Admin screen whose correctness is a
property of the DOM rather than of any controller.

| What it proves | Status |
|---|---|
| Per-state control sets: active offers Suspend + Revoke and nothing else; suspended offers Resume, Re-authenticate and Decommission; revoked offers Restore; pending offers Approve; decommissioned offers no control and reads "Ended" | **VERIFIED** |
| Every control is a POST carrying a CSRF token — a GET that suspends is a transition that happens to anything following links | **VERIFIED** |
| Suspend, Resume and Decommission will not submit without a reason; Approve will not submit without the merchant's code | **VERIFIED** |
| Decommissioning asks first, says it cannot be undone, and refusing it navigates nowhere | **VERIFIED** |
| The far side reachable: Lynomia's own counts shown, not recomputed | **VERIFIED** |
| The far side unreachable (502), refused (connection aborted), and **answering 200 with something that is not a health report**: the failure is printed, a health state **never** is, and the rest of the page is still there | **VERIFIED** |
| Each tab's content is visible on its own tab only — asserted on visibility, because a block written as a sibling of the panes is present on every one of them | **VERIFIED** |
| The notices tab names the workspace and inbox it sends from, says "Handed to Lynomia" rather than "sent", counts the rows still in flight, and states in one line what is not known | **VERIFIED** |
| A lost answer raises a warning, and an undelivered notice is never shown as sent | **VERIFIED** |
| Arabic at 390px: RTL, every Phase 6 control translated **by name** (Suspend, Resume, Decommission, Re-authenticate, Restore, Ended, Check, In Lynomia), the health verdict in Arabic rather than Lynomia's English state word, no sideways overflow | **VERIFIED** |

The per-name Arabic assertions exist because a whole-pane Arabic test passes while two labels
sit in English among them — which is exactly what was happening (§13, finding 5).

---

## 13. Adversarial review findings and corrections

Two rounds, because the approval asked for the adversarial pass to run against the previous
round's fixes as well as the new code — three rounds in Phase 5 each found the previous
round's fixes partial, and that is now a known property of this work rather than a surprise.

**Thirteen defects, all fixed.** Each was red-proofed by putting the defect back and
confirming that only its own example failed.

### Round one — reading the phase's own code

Five findings. Every one is a property the code asserted in its own docblock and did not
have.

### Finding 1 — "say it once" was one row per occasion, not one send per row

**Severity: high. Fixed in `ff8a362b`.**

`PlatformNotifier` leaned entirely on the unique index on `dedupe_key`, and its docblock said
so: "two operators acting on the same incident collide on the index; the loser is refused by
Postgres rather than by a race somebody has to get right." The index gives one **row**. It
cannot give one **send**: the attempt that loses the index re-reads the winner's row, and if
it reads before the winner has moved the status, both hold a `pending` row and both call
Lynomia. There is no idempotency anywhere on Lynomia's send path — no key on message create,
no unique index on `messages.source_id` — so that is two WhatsApp messages to the merchant,
which is precisely what the class exists to prevent.

The claim is now the status move, not the insert: one conditional `UPDATE ... WHERE status =
'pending'`, and whoever moves it owns the attempt. The other returns the row having sent
nothing.

*Red-proof:* the conditional restored to an unconditional `save()` →
`test_two_attempts_holding_one_pending_row_produce_one_send` fails, alone.

### Finding 2 — a notice refused for a fixable reason could never be sent

**Severity: medium-high, because it is the common case. Fixed in `ff8a362b`.**

There is no verified merchant phone number on this installation (B4), so `failed` /
`no_phone_on_record` is the **expected first outcome for most merchants**, and the whole
feature ships unconfigured, which produces the same state. The row for the occasion then
existed, so a later attempt found it rather than making one and returned without sending:
fixing the number afterwards, or configuring the workspace afterwards, left that occasion
permanently undeliverable. A capability that cannot deliver its own onboarding notice once
onboarding is possible is not a delivered capability.

`failed` is now the one state a fresh attempt may reopen, with the recipient and parameters
re-resolved because what failed is exactly what may have changed. `sent`, `sending` and
`indeterminate` are not reopened: a message may already be in front of the merchant.

*Red-proof:* the reopen disabled →
`test_an_occasion_that_failed_before_anything_was_sent_can_be_tried_again` fails, alone.

### Finding 3 — a lifecycle transition was decided outside the lock it took

**Severity: high. Fixed in `ff8a362b`.**

`InstallationLifecycle::move` read the link's state, checked it, pushed to Lynomia, and only
then took `lockForUpdate` to write. Two operators therefore both passed the check, both
pushed, and the second write silently won — a resume landing on top of a decommission leaves
Store Syria `ACTIVE` on an installation whose credentials Lynomia has already dropped and
which can never work again. The Lynomia half of the same channel got this right
(`Commerce::InstallationLifecycle` wraps the read and the write in `store.with_lock`); the
Store Syria half did not.

The state is re-read and re-checked under the lock, and the loser is a conflict instead of
the final word. The pre-push check stays, so an operator asking for an impossible transition
is still refused without Lynomia being troubled for it.

Also moved the merchant notice **out** of that transaction. It is three HTTP calls to
Lynomia, and inside the transaction they held the link's row lock for a network round trip —
blocking every operation on the installation while the thing being reported as broken was
the thing being waited on — and a rollback afterwards would have erased the record of a
message the merchant had already received.

*Red-proof:* the locked guard removed →
`test_a_transition_decided_on_a_stale_read_is_refused_under_the_lock` fails, alone.

### Finding 4 — the only irreversible transition had no confirmation at all

**Severity: high. Fixed in `ad92d33d`.**

```blade
onsubmit="return confirm(@json(__('Decommissioning cannot be undone…')))"
```

Blade's `@json` does not escape double quotes; `Js::from`, which exists for this, does.
Inside a double-quoted attribute the first quote of the JSON string **ends the attribute**,
so what shipped was the fragment `return confirm(` and the rest of the sentence became stray
markup. The browser logged a syntax error nobody saw, ran no handler, and submitted the form.
Decommission — where Lynomia drops its stored credentials and there is nothing to bring back
— was one unguarded click.

Nothing server-side could have noticed: the request that arrives is identical either way. It
was found by clicking the button in a browser.

*Red-proof:* `@json` restored →
`test_no_view_puts_an_unescaped_json_directive_inside_an_html_attribute` fails, alone.

### Finding 5 — the Arabic operator page was partly English

**Severity: medium. Fixed in `ad92d33d`.**

"Check" and "In Lynomia", the two labels the remote-health column adds, had never been added
to `ar.json`. Laravel's JSON translator falls back to the key, so they rendered in English
beside Arabic headings and no English reader and no test could see it. A module-wide audit
found **21** such strings missing from `ar.json` and **56** absent from `en.json`, which is
the registry a translator reads. With the seven labels this phase's own fixes added, both
dictionaries went from 706 and 671 entries to **734 each**, covering all 730 source strings
the module references (the four extras are retired strings kept deliberately).

Lynomia's four health states were rendered raw as well, so an Arabic operator read
"critical". The state is still Lynomia's — a fifth is not invented and an unrecognised one is
printed as it arrived — but the wording is now translated.

*Red-proof:* a key deleted, a key set to its English text, and a `:plan` placeholder dropped
→ three different arms of `LynomiaTranslationCoverageTest` fail, one each.

### What the review confirmed as sound

Checked and found correct, recorded so a later round does not re-derive them:
`Billing::MeteredUsage.consume`'s return convention (the ceiling when refused, nil when
allowed) is used the right way round; `refuse_sellable_plan!`'s condition is copied from
`Billing::Checkout`, `Billing::PlanChange` and the merchant upgrade list rather than
invented; both new platform controllers restate their authorisation filters, and Rails'
callback de-duplication makes the restatement replace the base's conditional registration
rather than add to it; the platform workspace cannot initiate a free-form WhatsApp message,
because `send_session_message` runs only inside a window the merchant opened; and
`Commerce::ConsentListener` withdraws **both** classes on one keyword, so a merchant's STOP
blocks a transactional platform notice and not only a promotional one.

### One environmental finding, not a Phase 6 regression

`spec/controllers/platform/api/v1/accounts_controller_spec.rb:48` and `:203` fail whenever
`installation_configs` is populated — which `rails db:migrate` does, and which production
always is — because they assert an exact feature set on a created account and the
installation's own `ACCOUNT_LEVEL_FEATURE_DEFAULTS` adds to it. Bisected across all seven
Phase 6 commits and reproduced identically at `7db45646~1`, before any Phase 6 code. It is an
upstream spec that assumes an empty config table. Recorded here rather than changed, because
re-baselining somebody else's spec to match a local database state is how a spec stops being
evidence.

---

### Round two — an independent pass, and a refutation pass over it

The approval asked for the adversarial round to run against the previous round's fixes as
well as against the new code, because three rounds in Phase 5 each found the previous round's
fixes partial. It did, and it was right to: **round two found that round one's own P6B fix
was incomplete, in the same method it had just edited.**

Five lenses, read-only, one per owner decision plus concurrency, each finding then handed to
an independent agent whose task was to refute it. **Twelve findings went to refutation; six
survived, six were killed.** Every survivor below is fixed; each fix is red-proofed by
restoring its own defect.

#### 6 — the partner integrations detail page 500s on any real installation

**High. Lynomia `c99ba9b5`.**

```erb
entry.audited_changes['partner_actor'] || entry.user_name || 'system'
```

The column on `audits` is `username`. `user_name` is not a method on `Custom::AuditLog`, on
`Audited::Audit`, or anywhere else — the whole repository contained exactly one use of it,
this one.

It did not raise in any existing example because `||` short-circuits, and every spec that
rendered this page rendered it with an **empty** activity table, so the loop never ran. It
raises on the ordinary case: `ACTIVITY_EVENTS` includes `commerce.store_connected`,
`commerce.credentials_rotated` and four more that Commerce writes for itself with no
`partner_actor` key — which is exactly what an operator opening the page to diagnose a
merchant's complaint is looking at.

Two examples now render the table with a row in it: one Commerce wrote, one a partner asked
for. Only the first fails without the fix, which is the short-circuit in reverse.

#### 7 — revoking a *linked* workspace still did not stop the installation

**High. Store Syria `1e8a8e44`.**

Round one's P6B work added `stopInstallation` to `AccountLinker::revoke` to close precisely
this: a revoked installation staying `active` in `commerce_stores` while
`Partners::Eligibility` went on authorising its campaigns. It added the call **after** the
hand-back, which left the defect untouched for a linked workspace: `handBackGrant` releases
this app's permissible on the account, `validate_platform_app_permissible` then answers the
lifecycle call with 401, `stopInstallation` logs it at warning level and returns.

Every existing example used a *provisioned* link, which keeps its grant deliberately and so
never exercised the hand-back at all. The installation is now stopped while this app still
has the authority to do it with, and the new example asserts the **order** of the two
recorded calls, because both happen either way.

#### 8 — suspending an already-suspended installation sent the merchant a second WhatsApp

**High. Store Syria `1e8a8e44`.**

`move` deliberately accepts a transition to the state the link is already in, so a retried
request is not an error — but every call writes a fresh audit row, and the notice is keyed on
that row's id. Two Suspends produced two different dedupe keys, and the notifier's
"say it once" guarantee, which is about one *occasion*, had no way to know it was being asked
about the same one twice. Round one had hardened the notifier; the call site defeated it.

The merchant is told when the state actually moves. A suspension after a resume is still a
new occasion and is still said.

#### 9 — the notices tab claimed notices had been sent when it could not know

**High. Store Syria `1e8a8e44`.**

`POST /api/v1/accounts/:id/conversations/:id/messages` answers 200 as soon as the message row
exists; the WhatsApp send runs afterwards in `SendReplyJob`, and it is **there** that the
UTILITY-only gate, the consent gate and the meter run. So a notice Lynomia goes on to refuse
was recorded `sent`, counted in "Notices sent", and — `sent` being terminal — could never be
retried. Nothing on this side ever hears otherwise; there is no delivery callback.

The tile now reads **"Handed to Lynomia"**, the page says in one line what is not known, and
rows still `pending` or `sending` are counted as "Still in flight" — a state that appeared in
no tile at all, being neither a success, a failure, nor an unknown answer.

Recovering a notice Lynomia refused needs a reconciliation this side cannot do yet. Recorded
as L10 rather than papered over.

#### 10 — `signals.open` was the length of a capped list

**Medium. Lynomia `c99ba9b5`.**

`recent` is limited to `SIGNAL_LIMIT` so a page does not render thirty rows; `open` was that
capped list's `.size`, so it saturated at 20 while `by_source`, in the same payload about the
same installation, went on summing to the real number. Both consoles render `open` as
"open issues", so an operator was shown two figures that contradicted each other precisely
when an installation was at its worst.

#### 11 — the platform allowance was spent before the consent gate, and a plan that stopped metering was a free pass

**Medium. Lynomia `c99ba9b5`.**

`Billing::MeteredUsage.consume` *increments*. Running it before `consent_refusal` spent a unit
of the platform's period on a message the system then refused and never handed to a provider.
The meter counts attempts deliberately — a message given to Meta is spent whatever becomes of
it — but a message refused here was never an attempt. The order is now category, then
consent, then the unit.

The second half is the serious one. `consume` returns immediately on a nil limit **without
writing a counter row**: right for a merchant whose plan does not meter sends, and the reason
that feature could ship inert. `Billing::PlatformWorkspace` refuses to provision this
workspace on a plan with no `campaign_sends` precisely so its sending is bounded — and
nothing held that true afterwards. An operator editing the plan, or the partner's own token
through the billing plans endpoint (the platform account is one of its permissibles), can
remove the limit from under a live workspace, and every send after that is uncounted.

The absence of a ceiling is now a refusal for a platform-owned account, with its own reason
string. It can only be the result of somebody editing the plan, and a workspace that quietly
stops counting looks exactly like one that is working.

#### 12 — the consent panel rendered on all five tabs at once

**Medium. Store Syria `1e8a8e44`.**

It was written as a sibling of the tab-panes rather than a child of one. Bootstrap hides
`.tab-pane:not(.show)` and nothing else, so a plain div at that level is always visible. The
harness now asserts **visibility**, not presence — presence is exactly what a mistake like
this leaves intact.

#### 13 — an unreadable health answer rendered as good news

**Medium. Store Syria `1e8a8e44`.**

A 200 whose body is not a health report fell through every `|| 0` and `|| []` in the renderer
and printed `Not known — 0 installations, no open issues`, which an operator reads as nothing
wrong. Verified by running the pre-fix renderer against such a body. It is now a refusal in
red, and the browser harness has a fourth remote-health case for it.

### What round two checked and could not break

Six findings were killed by the refutation pass, and they are recorded so a later round does
not re-derive them:

| Claim | Why it does not hold |
|---|---|
| `CsatSurveyService` sends a template straight to the provider, bypassing the platform gate | The bypass is real — it calls `provider_service.send_template` directly — but no platform-owned path reaches it. CSAT fires on conversation resolution for an inbox with CSAT enabled, which the platform workspace does not have |
| The P6E gate is attached only to `send_template_message`, so free-form and contact-info sends are ungated | Both branches are real and both are unreachable without a window the merchant opened by replying, which is support — explicitly authorised by Decision 2. The platform workspace cannot *initiate* a free-form message |
| An unconstrained `provider` on `installation_lifecycle` lets a partner reach another integration's stores | The lookup is `@account.commerce_stores`, already confined by the permissible check. The parameter confers no authority the caller does not already hold |
| Flipping `active` on the platform plan puts it in every merchant's upgrade list | The upgrade list is `active AND stripe_price_id present`; the platform plan has no price, and creating one is a separate deliberate act |
| Super Admin can put the platform workspace on the merchant trial plan through `extend_trial` | The mechanism does not exist — neither copy of `extend_trial` reaches the merchant trial plan for an account that already has a subscription |
| Push-first never reconciles a lost answer | Mechanism real, characterisation wrong: it is a disclosed, deliberate trade-off, and the alternative (writing locally first) is the failure that matters — Store Syria believing an installation is stopped while Lynomia goes on authorising it |

---

## 14. Commit IDs in both repositories

Branch `claude/store-syria-lynomia-integration-1oa1tt`, pushed in both.

### `fullstackfull/lynomiachat`

| Commit | What |
|---|---|
| `7db45646` | fix(whatsapp): the composer asks what the person agreed to (**B3.1**, prerequisite) |
| `3fab5203` | feat(platform): what Lynomia sees of one installation, said to the partner (**P6A**) |
| `010be18c` | feat(platform): a transition channel between the two installation lifecycles (**P6B**) |
| `4fb1ac5b` | feat(super-admin): partner integration administration (**P6C**, P6A Lynomia side) |
| `e454cb4e` | feat(billing): a platform-owned workspace, and the wall around it (**P6D**) |
| `d49f0008` | fix(platform): never list the installation's own workspace to a partner (**P6D**) |
| `266b8483` | feat(whatsapp): what the installation's own workspace may send, and at what cost (**P6E**) |
| `c99ba9b5` | fix(partner): three defects a second adversarial pass found (**P6F**, round two) |
| `e08f24e8` | fix(commerce): a partner suspend must not shut the door on a store the merchant disconnected (**P6F**) |

29 files, +2,103 / −14.

### `fullstackfull/syriastore98`

| Commit | What |
|---|---|
| `cc213d58` | feat(lynomia): bound the consent retry, and let each console see the other (**B3.2**, P6A) |
| `6137b93c` | feat(lynomia): suspend, resume, re-authenticate and decommission an installation (**P6B**) |
| `e33d5f65` | feat(lynomia): record which Lynomia workspace is Store Syria's own (**P6D**) |
| `c54b4442` | feat(lynomia): operational notices to merchants, sent once (**P6E**) |
| `40695d6e` | feat(lynomia): tell the merchant when their installation is suspended (**P6E**) |
| `ad92d33d` | fix(lynomia): restore the confirmation on the one transition with no way back (**P6F**, round one) |
| `ff8a362b` | fix(lynomia): three defects the Phase 6 adversarial review found (**P6F**, round one) |
| `1e8a8e44` | fix(lynomia): four defects a second adversarial pass found (**P6F**, round two) |
| `a24c974f` | test(lynomia): pin that a reissue yields its credential however the resume fails (**P6F**) |
| *this commit* | docs(lynomia): the Phase 6 completion report, matrix and register (**P6F**) |

27 non-documentation files, +3,809 / −13.

---

## 15. Remaining limitations

Stated as limitations of delivered work, not as things to be discovered later.

| # | Limitation | Status |
|---|---|---|
| L1 | **No message has ever been sent.** Every result here was produced against faked HTTP. The capability is implemented and exercised; it has not transmitted | **BLOCKED** by A1 and by the phase's own restriction |
| L2 | **No merchant can be reached today** whose store was created through Super Admin store creation, which hardcodes `7878787878`, or who self-registered, which writes `''`. `PlatformRecipients` records them unreachable with the reason rather than guessing | **PARTIAL** — see B4 |
| L3 | **No merchant-facing opt-out screen.** Replying STOP on WhatsApp works and withdraws both classes, which is the provider-native surface and is honoured by the same chokepoint. What does not exist is a screen in Store Syria showing a merchant their own state, or letting them opt back in without messaging | **PARTIAL** |
| L4 | **Email is not built.** Decision 3 said WhatsApp first and explicitly not email this phase. The send path is behind `PlatformWorkspaceGateway`, so a second channel is an added gateway and not a change to the notifier | **NOT STARTED**, as directed |
| L5 | **No operator-initiated notice.** The only caller is the automated suspension notice. There is no "send this merchant a notice" button, which also means there is nothing for a staff member to abuse — but onboarding and security notices have no trigger yet | **PARTIAL** |
| L6 | **An `indeterminate` notice has no resolution action.** It is counted, warned about and listed with its reason; an operator cannot mark it resolved or re-send it from the page | **PARTIAL** |
| L7 | **`Operations::IntegrationHealth#installations` is account-scoped, not app-scoped**, because `commerce_stores` carries no `platform_app_id`. Two partner integrations on one workspace would each see the other's installations. Not reachable in the current product shape | **PARTIAL** |
| L8 | **Nothing verifies that the configured workspace is the platform-owned one** | **BLOCKED** — recorded as B5 |
| L9 | **A `sending` row stranded by a crash stays `sending` for ever.** That is the honest state for "we do not know" and it is deliberately not swept, because a sweep that reopened it would be the double-send this design refuses. It is now at least counted, as "Still in flight"; there is no operator action for it | **PARTIAL** |
| L10 | **A notice Lynomia accepts and then refuses is never learned about.** Lynomia answers 200 when the message row exists; the UTILITY gate, the consent gate and the meter run afterwards in a job, and nothing reports back. The console says "Handed to Lynomia" rather than "sent" and states the gap, but settling such a row needs a reconciliation — the message status read back, or a `message_updated` event the platform workspace does not subscribe to | **PARTIAL** |
| L11 | **Consent decisions discarded because the link is not active are counted by nothing.** The dispatcher drops them deliberately — an installation that is suspended or revoked has no business pushing a shopper's decision across — but no console figure reflects it, so a merchant's screen can show "not sent" with nothing on the operator side explaining why | **PARTIAL** |
| L12 | **Lynomia's own operator figures still count the platform workspace.** `SuperAdmin::DashboardController`, `Operations::Overview` and `Operations::BillingState` include it. Deliberate, and the opposite of the partner-facing exclusion: the installation's own workspace is part of what the installation runs, and hiding it from Lynomia's operator would be the defect. Recorded because the asymmetry looks like an oversight and is not | **VERIFIED as deliberate** |

---

## 16. Production-readiness blockers

`docs/development/lynomia-integration/PRODUCTION-READINESS-BLOCKERS.md` is the register.
Phase 6's effect on it:

### Closed by this phase

| # | What | Where |
|---|---|---|
| **C15** (was B3.1) | A third outbound surface asked nothing about consent — an agent picking a WhatsApp template in the composer reached `send_template_message` with no eligibility call anywhere on the path, and the picker offers MARKETING | Lynomia `7db45646` |
| **C16** (was B3.2) | A consent decision waiting on a subject link was retried for ever, with nothing operator-visible | Store Syria `cc213d58` |

Both were required by the approval to be settled before platform communications were built
on the same surface. Both were open, both were closed first, neither was bypassed.

### Opened by this phase

| # | What | Severity |
|---|---|---|
| **B5** | Nothing verifies that the configured platform workspace is the platform-owned one. Three environment settings, all typed by an operator, and `platform_owned` is Lynomia's flag and is not reported to the partner. Pointed at a merchant's account and inbox this would send from a merchant's own WhatsApp channel, and the UTILITY-only gate would not apply because it keys on the account being platform-owned | Low likelihood, high impact |

Mitigated, not closed: the notices tab now names the workspace and inbox it sends from, so
the operator who typed the ids is shown what they typed.

### Unchanged and still open

| # | What | Why Phase 6 could not close it |
|---|---|---|
| **A1** | No run against a live Lynomia has ever happened | Needs a real non-production peer. The phase was explicitly forbidden from activating one |
| **A2** | `APP_KEY` was published and has not been rotated | Out of scope; forbidden by the phase's restrictions |
| **A3** | GATE F — 15 tables live code reads and writes have no migration | Out of scope |
| **A4** | Store Syria is PRE_UAT for cart recovery | Out of scope |
| **B1** | The `flow_sessions` concurrent index on a production-sized table | Out of scope |
| **B4** | No verified merchant phone number in Store Syria | Needs merchant-performed verification and a repair of two writers. Raised by P6E, which refuses rather than guesses |

---

## 17. Rollback requirements

Both branches are unmerged and undeployed, so rollback today is `git checkout` of the base.
What follows is what a deployment of this phase would need to be able to undo.

### Schema

| Migration | Repository | Reversible | Note |
|---|---|---|---|
| `20261027000100_add_platform_owned_to_accounts` | Lynomia | Yes | Adds a non-null boolean defaulting to `false` and a partial index. Dropping it loses only the marking; it strands no data |
| `2026_10_28_000100_create_lynomia_platform_notices` | Store Syria | Yes, but **do not** | The table is the only record of which merchant was told what, and when, and whether anybody knows if it arrived. Rolling back the code should leave the table |
| `lynomia_store_links` new states | Store Syria | N/A — no migration | `SUSPENDED` and `DECOMMISSIONED` are values in an existing string column. Older code reading a link in one of those states falls through every `elseif` and renders no control, which is safe |

### Order, if both sides must be rolled back

1. **Store Syria first.** It is the caller. Rolling Lynomia back first leaves Store Syria
   calling two endpoints that no longer exist; every such call is already classed permanent
   and surfaces as a refusal, but there is no reason to produce them.
2. **Then Lynomia.** Dropping the two platform routes and `partner_integrations` is safe once
   nothing calls them.

### What must be done by hand

| Action | Why |
|---|---|
| Unset the three `LYNOMIA_PLATFORM_WORKSPACE_*` environment settings | Code rollback does not unset them, and a later re-deploy would resume addressing the workspace |
| Decide what becomes of the platform workspace account | Rolling the migration back clears `platform_owned`, after which the account is an ordinary merchant account: it would be listed to the partner, swept by the trial backfill and counted in the billing figures. **Delete or suspend it before dropping the column** |
| Leave `lynomia_platform_notices` in place | See above |
| Re-check any installation in `suspended` or `decommissioned` | Older Store Syria code does not know those states and offers no control for them. An installation parked in one is stuck until the code is rolled forward again |

### What cannot be undone

Transitions already carried to Lynomia. A `disconnect` dropped Lynomia's stored credentials;
rolling back Store Syria's code does not bring them back, and the installation must be
re-provisioned. This is the same property the decommission confirmation exists to make an
operator think about.

---

## 18. GO / CONDITIONAL GO / NO-GO assessment

### **CONDITIONAL GO — for staging, on a non-production Lynomia, with real messaging still off.**

### **NO-GO for production.** A1 is open.

**What is unconditionally done.** P6A, P6B, P6C and P6D are implemented and verified against
automated tests, against the browser, and against two adversarial rounds that found thirteen
defects between them and had every fix red-proofed. The two prerequisite safety issues are closed.
Nothing in this phase added a second engine, widened an authorisation model, or touched a
contract artifact.

**What is conditional, and on what.**

| Condition | Why it gates |
|---|---|
| A real non-production Store Syria ↔ Lynomia peer-to-peer run | A1. Everything in §11 is faked HTTP. The contract has never been exercised against the other side's actual code |
| A provisioned platform workspace on that peer, with a real WhatsApp channel and at least one approved UTILITY template | P6E's send path has never carried a message. The UTILITY gate has never read a real synced template list |
| A verified merchant phone number for at least one test merchant | B4. Today every Super Admin-created merchant is unreachable, which is most of them |
| Confirmation that the three workspace ids point at the platform-owned account | B5. Nothing checks it across the wire |

**What must not be read into this report.**

- No message has been transmitted to anyone. The UTILITY-only gate, the meter and the consent
  gate are verified against faked provider responses, not against Meta.
- No charge was created. The subscription is `source: 'manual'` with no Stripe identifiers.
- The suite results are the suites' results. The Store Syria Feature suite fails 8 tests and
  skips 17, exactly the set the project has recorded, and the gate asserts that set by name —
  that is a statement that nothing new broke, not a statement that the suite is green.
- A1 is open, so **the integrated system is not production-ready**, and nothing in this phase
  was permitted to make it so.

**Recommended next step, and it is not Phase 7.** Stand up the non-production peer and close
A1. Until that run exists, every phase after this one adds code to a contract that has never
been exercised against the other side.

---

*Phase 7 has not been started. Neither branch has been merged or deployed. No `.env`,
`APP_KEY` or production credential was modified. No WhatsApp provider account was connected
and no live messaging was activated. No production payment was executed.*
