# Store Syria × Lynomia — Integration Phase 6 scope and implementation plan

**Integration Administration, Partner Operations & Platform Communication Operations.**

**Status: proposed, for approval before any implementation.** Nothing in this document has
been built. It is grounded in what the two repositories contain at Lynomia `3402f299` and
Store Syria `305e8e99`; every "exists" below was read from the code, and every "does not
exist" was established by searching for it.

**This is NOT the Store Syria platform roadmap's Phase 6.** That one
(`docs/discovery/09-DEVELOPMENT-ROADMAP.md` §9) is "Arabic & Storefront Experience" and
belongs to the platform plan, phases 0–9. It is not touched by this phase or this branch.

---

## 1. What already exists, so the plan is about the gap and not the ground

Read from the code rather than assumed. This is the central input: most of what Phase 6
names has foundations, and the phase is smaller than its title suggests.

| Capability the phase names | What exists today | Where |
|---|---|---|
| Store Syria Super Admin integration panel | **Substantial.** One page and nine operations: approve, revoke and restore a link; seats; marketing allowance; add-on catalogue and grants; issue and revoke the commerce credential | `SuperAdmin/IntegrationController` (`show` + 9), `routes/web.php:483-523`, `superadmin/integration.blade.php` |
| Lynomia Super Admin | **22 controllers.** The eight bespoke ones are not about this integration (billing plans, billing subscriptions, operations, platform support, portals, articles, categories) — but of the 14 upstream, **Platform Apps is**: routed, sidebar-linked CRUD over the `PlatformApp` whose token is this integration's whole credential, with `AccessTokens` beside it. It shows the name and the token and nothing else: not what the app is permitted, not which accounts it holds, not what it has done | `custom/app/controllers/super_admin/`, `app/controllers/super_admin/platform_apps_controller.rb`, `app/dashboards/platform_app_dashboard.rb`, `config/routes.rb:647` |
| Installation lifecycle | **Exists**, as `commerce_stores.status` (active / disabled / needs_reauth / disconnected) and `lynomia_store_links.state`, with the credential version as the revocation mechanism | `Commerce::Store`, `LynomiaStoreLink`, `IntegrationController` |
| Merchant account linkage | **Exists and is hardened**: provisioning, the claim-and-approve flow for an existing account, one-way SSO, both unique indexes | P1, `AccountLinker`, `AccountProvisioner`, `SsoLinkIssuer` |
| Integration health / operational monitoring | **Partly.** `Operations::Signal` is a real subsystem with 8 sources and a Super Admin feed, and `commerce_store` and `campaign` are already among its sources. Store Syria has no equivalent and no view of Lynomia's | `custom/app/models/operations/signal.rb`, `super_admin/operations_controller.rb` |
| Platform communication operations | **Does not exist.** Nothing in either repository sends Store Syria's own messages to its merchants | searched |
| An isolated Lynomia workspace for platform communications | **Does not exist.** Every account Store Syria provisions belongs to a merchant | searched |
| Governance / auditing | **Exists** on both sides: `AuditLog` (Store Syria) and `Custom::AuditLog` + `audited` (Lynomia), and every Super Admin action above is audited | verified per action |
| Feature controls | **Exists**: `LYNOMIA_INTEGRATION_ENABLED`, per-account feature flags, `PlanModuleCheck`, plan entitlements and `PlatformAppEntitlementGrant` | P1–P5 |
| Reuse of Lynomia's engines | **The project's standing rule**, held through five phases: no second messaging engine, scheduler, automation engine or consent store has been built | P1–P5 reports |

**The honest shape of Phase 6.** Two of its seven bullets are genuinely new build
(platform communications, and the isolated workspace they need). Three are extensions of
subsystems that exist (admin panels, lifecycle, monitoring). Two are policy and plumbing
over things already built (governance, reuse).

---

## 2. What Phase 6 should deliver, in dependency order

### P6A — Operational visibility across the boundary (foundation)

Store Syria operators cannot see Lynomia's view of an installation, and Lynomia operators
cannot see Store Syria's. Every incident in Phase 5's register is of this shape: a token
rejected unattended, a store dropping out of `counted_stores`, an outbound consent decision
retired, a campaign blocked for a missing capability.

- A read-only **installation health panel** on the Store Syria Super Admin page: the
  Lynomia-side status of this installation, its credential version, its last successful
  call, its outbox backlog and its open Lynomia signals.
- A **platform API read endpoint** on Lynomia for exactly that, confined by
  `platform_app_permissibles` as every other platform endpoint is.
- Extend `Operations::Signal` rather than inventing a second feed. It already carries
  `commerce_store` and `campaign` sources.

*Why first:* everything below is easier to operate, and to verify in staging, once each side
can see the other.

### P6B — Partner and installation lifecycle, completed

The states exist; the transitions between them are partly manual and partly unattended.

- An explicit **suspend / resume** for an installation, distinct from revoke, with a reason.
- **Re-authentication**: the path out of `needs_reauth` without reissuing a credential by
  hand. Today a rejected token is an unattended transition with no merchant-facing remedy — the
  same family as the closed B2.2 and B2.3, which were both about an installation's identity
  moving or failing silently with nobody told.
- A **decommission** path that is honest about what it keeps: Phase 5 established that
  consent evidence outlives the installation.

### P6C — Lynomia-side integration administration

Lynomia's Super Admin already administers the `PlatformApp` itself — name, token, create and
destroy — but nothing about what an app is permitted or holds. That existing screen is the
place to extend, not a page to rebuild, and it makes this section smaller than it first
looked.

- **Extend the existing Platform Apps screen** with what it does not show: the app's
  permissibles, the accounts it holds, and its recent activity. Creating and destroying an
  app, and its token, are already there.
- **Per-installation consent and campaign visibility** for an operator diagnosing a
  merchant's complaint, reusing the existing per-account screens.
- No new authorisation model: `PlatformApp` + `platform_app_permissibles` already confines
  everything.

### P6D — A strictly isolated workspace for platform communications

The phase asks for Store Syria to message its own merchants through Lynomia. That workspace
must not be a merchant's.

- A **dedicated Lynomia account** owned by the platform, provisioned by the same
  `AccountProvisioner`, marked so it can never be returned by a merchant-facing lookup.
- **Isolation to be proven, not asserted**: a merchant must not reach it, it must not appear
  in any merchant's store list, and its contacts must not mix with a merchant's. Phase 2's
  two-installation fixture pattern is the precedent.
- **Its own entitlements**, so platform messaging cannot consume a merchant's allowance.

### P6E — Platform communication operations

Onboarding, support, reminders and notifications from Store Syria to its merchants.

- Reuse Lynomia's campaign, automation and template engines through the existing BFF
  surfaces. **No new sender.**
- Merchants are the recipients, so **merchant consent is a real question** and not a
  formality: a platform notification about an account is transactional; a platform
  announcement about a new feature is marketing. Phase 5's two-class model applies
  unchanged, and the same mistake — reading the class from the sender rather than the
  template — must not be made a fourth time.
- An **unsubscribe surface for merchants** that is not the shopper one.
- **Close B3.1 first, or decide it deliberately.** The register's B3.1 is that an agent
  composing a WhatsApp template is asked nothing about consent. Platform communications put
  Store Syria's own staff on exactly that surface, messaging merchants, so the phase either
  closes it or records a decision that an operator may override a merchant's opt-out. It
  should not be discovered a second time.

### P6F — Governance, hardening, adversarial review and closure

As every phase has ended, and with one addition this phase has earned: **the adversarial
round runs against the previous round's fixes as well as against the new code.** Three
rounds in Phase 5 each found the previous round's fixes partial; that is now a known
property of this work and should be planned for rather than rediscovered.

---

## 3. What Phase 6 must not do

- No second messaging engine, scheduler, automation engine, template store or consent store.
- No change to the two SHA-pinned contract artifacts without a version bump on both sides.
- No widening of `platform_app_permissibles` semantics.
- No merchant data in the platform workspace, and no platform data in a merchant's.
- Nothing in the Store Syria platform roadmap's Phase 6 (Arabic & Storefront).

---

## 4. Open questions for the owner, which change the shape of the work

1. **Who owns the platform workspace commercially?** A Lynomia account has a billing
   subscription and entitlements. Is the platform workspace comped, on its own plan, or
   outside billing altogether? P6D cannot be specified without this.
2. **What may Store Syria message a merchant about without consent?** "Your integration is
   broken" is transactional on any reading; "here is our new feature" is not. The boundary
   between them is a policy decision, not an engineering one.
3. **Which channel?** Merchant notifications over WhatsApp need merchant phone numbers and
   approved templates; email needs an email channel Lynomia does not currently provision
   for this integration.
4. **Is P6A's cross-boundary health panel wanted on both sides, or only on Store Syria's?**
   The Lynomia side is more work and serves a different operator.

---

## 5. Sequencing, and what should precede coding

P6A → P6B → (P6C ∥ P6D) → P6E → P6F.

**A1 should close before P6D and P6E.** Those two are the first things in this project that
would send real messages to real people from the platform's own workspace, and the Phase 5
register's argument for A1 is now three rounds deep: every one of its defects passed both
sides' offline tests. Building a platform-to-merchant messaging path on an integration that
has never run against a deployed Lynomia would repeat the mistake at a higher cost.

P6A and P6B are the right work to do while a staging environment is being stood up: they are
exactly what makes a staging run diagnosable.
