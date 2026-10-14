# Store Syria × Lynomia Chat — Phase 2 Completion Report

Native omnichannel inbox, channel exclusivity, included agent seats and secure real-time delivery.

Branch (both repositories): `claude/store-syria-lynomia-integration-1oa1tt`

---

## 1. Executive completion summary

Phase 2 is **implemented and tested, and is not production-ready** — those are different claims and
this report keeps them apart throughout.

A merchant can open **Inbox** in the Store Syria dashboard and work real Lynomia conversations:
read a filtered, paginated list, open a thread, send a reply or an internal note, assign it, change
its status, and see the customer alongside. Every one of those actions is served by a Store Syria
endpoint that authenticates the person, resolves the store from their session, acts with **that
person's own Lynomia identity** confined to **that store's workspace**, and returns a narrowed
response. No Lynomia credential of any kind reaches the browser.

Underneath it, three foundations the later phases need are in place: one channel identity can
belong to only one Store Syria store, enforced by a partial unique index and validated at the one
point all nine of Lynomia's inbox-creation paths pass through; a Store Syria plan can include
Lynomia agent seats, granted without creating a Lynomia subscription so the merchant cannot be
charged twice; and Lynomia's existing signed webhooks drive a cursor-based event stream that the
inbox resynchronises from.

**What is not done** is listed in §15 rather than buried: outbound attachments, channel
self-service onboarding (deliberately — the rule had to land first), the Lynomia-side Super Admin
page, automatic re-sync on a Store Syria plan change, and a `channel disconnected` event that
Lynomia cannot currently emit.

---

## 2. Architecture

```
MERCHANT BROWSER  (Blade + Bootstrap 5 + jQuery; no Lynomia credential, ever)
      │  session cookie
      ▼
STORE SYRIA  /lynomia/inbox/api/*          ← the BFF
      │   EnsureStoreOwnsLink   store ownership, from the session
      │   PlanModuleCheck       tenant entitlement
      │   Laratrust permission  Manage / Reply / Assign  (per action)
      │
      ├── MerchantInboxGateway
      │      ├── IdentityProvisioner   this person's Lynomia identity in this workspace
      │      └── PlatformApiClient     ── platform token (env only) ──┐
      │                                                              │
      │      ← scoped_access_token: one person, one account, expiring┘
      ▼
   AgentApiClient  ──  api_access_token: <scoped> ──▶  LYNOMIA  /api/v1/accounts/{id}/*
                                                        │  EnsureCurrentAccountHelper
                                                        │  refuses any other account
                                                        ▼
                                                   conversations, messages,
                                                   contacts, assignments

LYNOMIA  ── signed webhook ──▶  STORE SYRIA  POST /lynomia/events/{store}
  X-Chatwoot-Signature  sha256=HMAC(secret, "{ts}.{body}")   secret = HMAC(master, store)  [derived]
  X-Chatwoot-Timestamp  freshness window
  X-Chatwoot-Delivery   unique index → idempotency
                                   │
                                   ▼   ids only, never content
                          lynomia_inbox_events  ──▶  GET /lynomia/inbox/api/events?after=<cursor>
                                                          │
                                                          ▼  browser re-fetches through the BFF
```

The separation that matters: the **platform token** can create workspaces and reaches every account
this app holds — it is used for exactly one thing, asking Lynomia for a per-person credential, and
never serves a merchant request.

---

## 3. Native inbox feature inventory

| Area | Delivered |
|---|---|
| Conversation list | list, search, channel badge, contact name, last-message preview, unread count, assignee, status, last activity, status + assignment filters, pagination |
| Thread | message history, direction, sender, timestamps, delivery status, cursor paging, reply composer, internal notes, attachments (inbound, linked) |
| Actions | send reply, add internal note, assign/unassign, change status, mark read |
| Customer panel | Lynomia contact, email, phone, channel, conversation status |
| States | loading, empty, error, disconnected, permission-aware, "read-only" composer |
| i18n / RTL | all strings translated; `dir="rtl"` and `style-rtl.css` inherited from the layout; logical utilities only (`ms-*`, `me-*`, `border-inline-*`) |
| Accessibility | labelled controls, `role="list"`/`log`, `aria-live`, keyboard-activatable rows, visually-hidden labels |
| Safety | every customer-controlled string written with `textContent`; attachment links `rel="noopener noreferrer"` |

**Stack note, measured not assumed:** the merchant dashboard renders with Bootstrap 5 + jQuery 3.6
from `public/assets`. No dashboard view invokes the Vite directive, and `npx vite build` **fails**
on a clean `npm install` because `resources/js/echo.js` imports `laravel-echo` and `pusher-js`,
neither of which is in `package.json`. Building the inbox in Tailwind/Alpine would have produced an
unstyled page.

---

## 4. Server-side BFF API map

| Store Syria route | Permission | Lynomia call |
|---|---|---|
| `GET  /lynomia/inbox` | Manage | — (shell) |
| `GET  …/api/conversations` | Manage | `GET /api/v1/accounts/{id}/conversations` (page-based, 25) |
| `GET  …/api/conversations/{id}/messages` | Manage | `GET …/messages` (**cursor** `before=`, cap 20) |
| `POST …/api/conversations/{id}/reply` | **Reply** | `POST …/messages` (not retried) |
| `POST …/api/conversations/{id}/status` | **Assign** | `POST …/toggle_status` |
| `POST …/api/conversations/{id}/assign` | **Assign** | `POST …/assignments` |
| `POST …/api/conversations/{id}/read` | Manage | `POST …/update_last_seen` |
| `GET  …/api/agents` | Manage | `GET …/agents` (ids + names only, no emails) |
| `GET  …/api/contacts/{id}` | Manage | `GET …/contacts/{id}` |
| `GET  …/api/events` | Manage | — (local cursor) |
| `POST /lynomia/events/{store}` | HMAC signature | — (ingress) |

Three upstream contract details were read rather than assumed, and each would have been a silent
bug: conversations are addressed by **`display_id`** (per-account and sequential, so another store's
"7" is a valid "7"); messages paginate by **cursor**, not page; and the upstream message `create`
has **no strong parameters at all**, so the whitelist lives in `AgentApiClient`.

---

## 5. Real-time event and transport map

| Required event | Lynomia webhook | Covered |
|---|---|---|
| New incoming / outgoing message | `message_created` | yes (`message_type` distinguishes) |
| Conversation created | `conversation_created` | yes |
| Conversation updated | `conversation_updated` | yes |
| Status changed | `conversation_status_changed` | yes |
| Delivery / read update | `message_updated` | yes |
| Assignment changed | — | **incidentally** — changes the conversation, so `conversation_updated` fires |
| Unread changed | — | **incidentally**, same mechanism |
| Channel disconnected / needs reauth | — | **NOT covered** — needs an additive Lynomia adapter |

`Webhook::ALLOWED_WEBHOOK_EVENTS` is frozen at twelve events with no overlay seam, so widening it
would be an OSS edit; the incidental coverage is sufficient because an event carries only ids and
the client re-reads state.

**Transport: cursor, not socket — a measured choice.** No Echo client is loadable (see §3), Reverb's
server is in `composer.json` but `BROADCAST_CONNECTION` defaults to `null` and nothing runs the
daemon. A socket would have shipped an inbox that silently never updates. The cursor gives up
sub-second latency and keeps every guarantee asked for: reconnection and catch-up are the *same
call* as an ordinary poll (so the recovery path is exercised constantly, not only after a failure),
duplicates and out-of-order delivery are harmless, and an unreachable server degrades the inbox to
what it was a moment ago.

---

## 6. Channel-exclusivity evidence

Rule: **one channel identity → one Store Syria store**, including across two stores of one merchant.

- `channel_identity_claims`, partial unique index on `(platform_app_id, provider, identity)` **where
  `state = 'active'`** — released claims stay as history without blocking the next holder.
- Keyed on the **platform app**, so an account no integration holds is untouched. Independent
  Lynomia customers are unaffected; this was required and is tested.
- **Normalization per provider**: phones to E.164-ish with `00` folded, email lowercased,
  provider-issued ids compared **exactly**. Without it the rule would be a formatting rule — five
  spellings of one number are tested.
- **Two enforcement points**, because one is not enough: `Channels::ExclusivityGuard` on `Inbox`
  (the only point downstream of all **nine** inbox-creation paths — six never touch the controller)
  and `Custom::Channelable` on the channel row (because `EDITABLE_ATTRS` makes identity mutable, so
  a create-only rule is evadable in two steps).
- **Reverified gaps:** `channel_facebook_pages` is unique on `(page_id, account_id)` only and
  `channel_twitter_profiles` on `(account_id, profile_id)` only — both confirmed at this HEAD. X is
  unreachable in this fork. Neither matters for this rule, which is about *stores*.
- **Not claimable, correctly:** web widget and API channels mint their own identifiers, so there is
  nothing a second store could collide with.
- **Two states only** — `active`, `released`. The other six in the approved lifecycle have no writer
  in Phase 2; Phase 1 had to remove `LinkState::SUSPENDED` for exactly that reason.
- **Concurrency tested for real**: two threads, two connections, real Postgres — exactly one wins.

---

## 7. Agent-seat entitlement

```
effective limit = operator override
                ?? max(Lynomia plan, integration grant)
                ?? unlimited
```

- **MAX, not SUM.** 10 paid + 5 included = 10. Not 15 (additive stacking) and not 5 (downgrading
  paid rights). Both directions tested.
- **A separate layer, not the override row.** An override is documented as an operator's explicit,
  audited decision; a machine-maintained allowance following an external plan is a different thing,
  and writing it there would let a sync silently overwrite a human — the hazard `Billing::FeatureSync`
  is documented as avoiding for features.
- **No duplicate billing.** The grant gives a real, enforced ceiling **without a Lynomia
  subscription**. Before it, an account with no subscription resolved to *unlimited*, so enforcing
  seats and double-billing were the same switch.
- **`limit_source`** returns `:override | :plan | :integration | :default` — the provenance asked for.
- **No new guard.** `Billing::AgentLimit` on `AccountUser` create already covers every path including
  the Platform API, and takes `account.lock!` inside the save's transaction. Concurrency tested with
  real threads.
- **No hardcoded default.** `plans.lynomia_agent_seats` starts null on every plan; null **withdraws**
  the grant rather than granting zero, so an unconfigured plan locks nobody out.
- **A downgrade deletes nobody.** Withdrawing changes what happens next, not who exists.
- **The refusal now points somewhere useful**: a merchant whose seats come from Store Syria is told
  "Ask your provider", not "Upgrade your plan" — they have no Lynomia plan to upgrade.

---

## 8. Permission and isolation evidence

| Property | Where proven |
|---|---|
| Owner reads own conversations | `LynomiaInboxBffTest` |
| Staff read but cannot reply | ditto — and the refused reply never reaches Lynomia |
| Staff cannot assign or change status | ditto |
| Staff without the module reach nothing | ditto |
| Store A cannot reach store B | ditto — asserted on the account id on the wire |
| One person, two stores, correctly scoped | ditto — both directions |
| No Lynomia credential in any response | ditto — token, SSO param and base URL all absent |
| Forged conversation id confined to own account | ditto |
| Forged event cursor cannot cross stores | `LynomiaEventIngressTest` |
| Scoped token refused on a sibling account | `scoped_access_tokens_spec.rb` (401, where the personal token still gets 200) |
| Anonymous refused on every BFF route | `LynomiaInboxBffTest` |

**Mutation-verified** (the enforcement was removed and the tests were watched to fail): per-action
route permissions (2), per-person credential (1), access-token account scope (2), both exclusivity
layers (3), no-stacking rule (3), replay window and per-store key derivation (3).

---

## 9. Store Syria Super Admin

Existing page `Lynomia Chat Integration` gains an **Agent Seats** tab: included seats per plan, seats
in use per store, and event-delivery health. It reads only from this side, so it still answers when
Lynomia is unreachable — which is when an operator opens it.

## 10. Lynomia Super Admin

**Not built.** See §15.

---

## 11. UI / RTL / mobile validation

Static and structural, not visual: RTL is inherited from `layouts/app.blade.php` (`dir="rtl"` plus
`style-rtl.css`, forced for `ar`/`he`), and the inbox stylesheet contains **no physical
left/right** — only logical properties. Responsive behaviour is a single `@media (max-width: 991.98px)`
rule that stacks the three panes and hides the empty context pane. The page renders in tests
(`assertOk` + markup assertions). **No browser screenshot was taken** — see §15.

---

## 12. Test results

| Suite | Result |
|---|---|
| Store Syria — Lynomia module | **133 tests, 2 985 assertions, 0 failures** |
| Store Syria — full suite | **1 716 tests, 13 failures** — the *identical* pre-existing set |
| Lynomia — Phase 2 specs | scoped tokens 15, channel claims 24, entitlement grants 22 — 0 failures |
| Lynomia — broad regression | **8 813 examples** — see §12a |
| RuboCop (Lynomia) | clean on all changed files |

The 13 Store Syria failures are the documented baseline from `f2d16cab`: 8 `owner_menu_settings`
Auth/Profile and 5 tenancy-census. Phase 2 added 46 Store Syria tests (19 ingress + 17 BFF + 10 seat allowance) and **no new failure**. Per the brief they
are out of scope, and this report does not claim all tests pass.

New tests: 17 BFF, 19 event ingress, 10 seat allowance (Store Syria); 15 scoped token, 24 channel
claim, 22 entitlement grant (Lynomia) — **107 new examples**.

---

## 12a. The broad Lynomia regression — and the defect it caught

**This run was not a formality. It found a real defect in Phase 2, and that defect was mine.**

`spec/requests spec/controllers spec/models spec/services spec/lib` — **8 813 examples**, run for the
first time in this engagement at the end of Phase 2. Result before the fix: **2 failures**, both in
`spec/requests/audit_capture_spec.rb` (B-17, B-18 — channel configuration).

### What broke

`custom/app/models/custom/channelable.rb` **already existed**. It is a plain module prepended into
the `Channelable` concern, filling in the empty `create_audit_log_entry` hook that
`app/models/concerns/channelable.rb:10` declares, and what it implements is the **channel
configuration audit trail** — the control that records a credential rotation by the *names* of the
keys that moved and never by their values.

Phase 2B **overwrote that entire file** with the channel-exclusivity validation: 31 insertions,
80 deletions. The audit emitter ceased to exist.

### Why it was silent

The PATCH still returned **200**. Nothing raised, nothing logged, no Phase 2 spec failed. The only
symptom anywhere was two red examples in a suite that Phase 2 had no reason to run. A compliance
control was removed and the product reported success.

### The fix

1. `custom/app/models/custom/channelable.rb` **restored byte-for-byte** from `3cd137e5` (verified
   with `diff` against `git show`), so the audit behaviour is exactly what it was — not a
   reimplementation.
2. The validation moved to a new file, `custom/app/models/channels/identity_guard.rb`
   (`Channels::IdentityGuard`). A guard and an audit log are different concerns and no longer
   share a file.
3. Injected explicitly in `config/initializers/billing.rb`, beside `Channels::ExclusivityGuard`,
   over the channel classes derived from `Channels::Identity::IDENTITY_ATTRIBUTES` — so both halves
   of the exclusivity rule are declared in one readable list.

There is an MRO reason this matters beyond tidiness: a **plain** module prepended into a Concern
lands *ahead* of it, but an `ActiveSupport::Concern` prepended into another Concern is registered as
a dependency and gets *included*, which can land it *behind*. Keeping the audit module plain
preserves the override; the guard, which only needs `validate`, is free to be a Concern.

### Verified after the fix

- `audit_capture_spec.rb` + `channel_claims_spec.rb`: **47 examples, 0 failures**.
- Runtime ancestor check on all twelve channel classes: `Custom::Channelable` present on **12/12**
  (audit restored); `Channels::IdentityGuard` present on **10/10** claimable channels and correctly
  **absent** from `Channel::WebWidget` and `Channel::Api`, which mint their own identifiers.
- `Inbox` still carries both `Billing::InboxLimit` and `Channels::ExclusivityGuard`.
- **Mutation-verified**: disabling the new injection fails 2 channel-claim examples, so it is
  load-bearing rather than decorative.
- Full suite re-run after the fix: **in flight when this report was committed.** The pre-fix run
  was 8 813 examples / 2 failures, and those two are the ones fixed and independently re-verified
  green above, so the expected result is 0 — but it is not claimed until measured. The final count
  is recorded in a follow-up commit to this file.

### What this says about the rest of Phase 2

Every Phase 2 spec passed while this defect was live, so Phase 2's own tests could not have caught
it. A sweep of the other eleven modified Lynomia files, and of the Store Syria module, was run
specifically for the same signature — a modification that removes pre-existing behaviour.
Result on the Store Syria side, completed: `PlatformApiClient` is the only module file with a
large deletion (−68) and all **nine** of its pre-existing public methods survive — the deletion was
transport extraction into `LynomiaHttp`. The remaining modified files delete between one and four
lines each, all accounted for. The equivalent sweep of the other eleven modified **Lynomia** files
was still running when this was committed; its result is recorded in a follow-up commit.

Also reverted: Phase 2 had accidentally committed a `package-lock.json` upgrade (lockfileVersion
1 → 3, 182 added entries) with **no `package.json` change** — an artifact of probing `npx vite
build` while measuring the frontend stack. It is restored to its pre-Phase-2 state.

---

## 13. Cross-repository commit list

**lynomiachat** — `claude/store-syria-lynomia-integration-1oa1tt`

| Commit | Scope |
|---|---|
| `64dddd55` | account-scoped, expiring access tokens; `scoped_access_token` endpoint |
| `8892bd1c` | channel identity claims; exclusivity at the Inbox + channel chokepoints |
| `ec96b43b` | integration entitlement grants; receiver-chosen webhook secret |

**syriastore98** — `claude/store-syria-lynomia-integration-1oa1tt`

| Commit | Scope |
|---|---|
| `c56fc1a0` | BFF + native inbox |
| `f20fdb6e` | channel-claim consumer |
| `a9bcd51d` | included seats + event ingress/delivery |
| `3b19e148` | Agent Seats admin tab |

---

## 14. Deployment prerequisites

1. `LYNOMIA_WEBHOOK_MASTER_SECRET` — **required**; unset is a hard failure by design.
2. `LYNOMIA_CREDENTIAL_CACHE_STORE` — leave as `array` unless a **non-file** store exists. The
   default costs one extra Lynomia call per request and writes no credential to disk.
3. Five Phase 2 migrations — Store Syria: `..._000200_add_lynomia_agent_seats_to_plans`,
   `..._000300_create_lynomia_inbox_events_table`; Lynomia:
   `20261014090000_add_account_scope_and_expiry_to_access_tokens`,
   `20261014100000_create_channel_identity_claims`,
   `20261014110000_create_platform_app_entitlement_grants`.
4. `LYNOMIA_INTEGRATION_ENABLED=true` and a non-production platform token.
5. The Lynomia ingress must be able to reach the Store Syria URL.
6. Set `lynomia_agent_seats` on each plan — null grants nothing.
7. If `LYNOMIA_WEBHOOK_MASTER_SECRET` or `APP_URL` ever changes after a store is subscribed,
   the registered Lynomia webhook must be deleted and re-created — see §15.

---

## 15. Known blockers and gaps

| Item | Status |
|---|---|
| **APP_KEY published and unrotated** | Unresolved, accepted. Drove every "derive, never store" decision. |
| Outbound attachments | Not implemented; inbound render and link. |
| Channel self-service onboarding | Deliberately not exposed — the rule landed first, as required. |
| Lynomia Super Admin page | Not built. |
| Re-sync on Store Syria plan change | Syncs at activation only; a plan change needs a hook. |
| `channel disconnected` event | Lynomia cannot emit it. |
| ActionCable | No connection-level auth (`pubsub_token`, origin checks disabled) — Store Syria does not proxy it. |
| `vite build` broken | Pre-existing; irrelevant to merchant pages, blocks any future Vite asset. |
| Browser/visual validation | Not performed. |
| Ingress rate limiting | Not added. |
| Webhook re-subscription | `EnsureSubscribed` matches an existing webhook **by URL only**. Rotating `LYNOMIA_WEBHOOK_MASTER_SECRET`, or changing `APP_URL`, leaves the registered webhook signing with the old secret (deliveries then fail verification) or pointing at the old URL. Both need an operator re-subscribe; neither is a security defect, and neither is automated. |

---

## 16. Rollback

Both repositories are on a feature branch, unmerged and undeployed.

- **Code:** `git revert` the commits in §13, or abandon the branch. No shared branch is touched.
- **Data:** all five migrations are additive. `access_tokens.account_id/expires_at` and
  `plans.lynomia_agent_seats` are nullable with null = previous behaviour; the three new tables are
  droppable without touching existing data.
- **Runtime kill switches, no deploy required:** `LYNOMIA_INTEGRATION_ENABLED=false` stops every
  remote call; deleting `PlatformAppEntitlementGrant` rows restores previous ceilings; deleting
  `ChannelIdentityClaim` rows makes the exclusivity guard inert.
- **Nothing to un-charge:** no Lynomia subscription is created and no payment surface is touched.

---

## 17. Recommendation — **CONDITIONAL GO**

Conditional on, and only on:

1. **APP_KEY rotation** before any production credential exists. Every design decision here routes
   around a published key; that is mitigation, not a fix.
2. **A browser pass** on the inbox in Arabic and at mobile width. Structurally correct is not the
   same as verified.
3. **An operator decision on seat counts per plan**, since null grants nothing.
4. **Rate limiting on the ingress** before it is publicly reachable.
5. **The broad Lynomia suite (`spec/requests spec/controllers spec/models spec/services spec/lib`,
   8 813 examples) must be a release gate for this integration.** Phase 2 removed a compliance
   control — the channel-configuration audit trail — and *every Phase 2 spec still passed*. The
   targeted suites a feature brings with it cannot see what the feature deleted. See §12a.

Not conditional on the 13 pre-existing Store Syria failures or on the gaps in §15, which are scoped
work rather than defects in what was built.

**Implementation is complete for Phase 2's stated scope. Production readiness is not claimed.**
Phase 3 is not started.

One caveat on confidence, stated plainly: a defect that silently removed an audit control shipped
in commit `8892bd1c` and survived every Phase 2 test. It was caught only by running the whole
Lynomia suite, and is fixed and verified (§12a). It is reasonable to treat the rest of Phase 2 as
carrying some residual risk of the same kind until the broad suite is green in CI rather than once
on a developer's machine.
