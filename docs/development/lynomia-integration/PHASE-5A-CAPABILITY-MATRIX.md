# Phase 5 — Capability Matrix and Contract Verification

**Scope:** Lynomia (`/home/user/lynomiachat`, Chatwoot v4.18 fork, `custom/` overlay, no `enterprise/`) and Store Syria (`/home/user/syriastore98`, Laravel 11, `packages/workdo/LynomiaChat/src`).
**Method:** eight subsystem readers, eight adversarial challengers, plus a verification pass run against the live checkouts for every claim that decides an objective. Challenger corrections win over the original claim and are marked **[OVERTURNED]** where they changed a verdict.
**Date:** 2026-10-15.

---

## 0. Two facts that condition everything below

Before the objectives, two findings that no single subsystem owns and that change how every verdict should be read.

### 0.1 Neither installation has the integration switched on

| Switch | Location | State as deployed |
|---|---|---|
| `LYNOMIA_INTEGRATION_ENABLED` | `packages/workdo/LynomiaChat/src/config/lynomia.php:29` (default `false`) | **unset** — `/home/user/syriastore98/.env` contains zero `LYNOMIA_*` keys (verified: `grep -c -i lynomia .env` → `0`) |
| `LYNOMIA_BASE_URL`, `LYNOMIA_PLATFORM_TOKEN` | `config/lynomia.php:35,41` (no defaults) | unset → `PlatformApiClient::assertUsable()` throws `KIND_DISABLED` on the first call (`PlatformApiClient.php:450-463`) |
| `LYNOMIA_COMMERCE_EVENTS_ENABLED` | `config/lynomia.php:141` (default `false`) | unset → the commerce outbox is never written (`app/Observers/CommerceEventObserver.php:58`) |
| `Billing::Settings.enforced?` | `custom/app/services/billing/settings.rb:11-14` | **false** — zero `BILLING_*` InstallationConfig rows, `BillingPlan.count == 0` (challenger, runtime) |
| `STORESYRIA_ENABLED` | `custom/app/services/commerce/storesyria/config.rb:9` (default `false`) | unset → every Store Syria store is excluded from every audience condition (`custom/app/services/audience/commerce_condition.rb:36,84`) |
| `whatsapp_campaign` | `config/features.yml:204-206` | `enabled: false` (verified) |
| `lynomia_commerce` | `config/features.yml:307-309` | `enabled: false` (verified) |
| `WHATSAPP_APP_SECRET` / `WHATSAPP_APP_WEBHOOK_VERIFY_TOKEN` | `config/installation_config.yml:184-188` | both unset; `GET /webhooks/whatsapp` returns 401 on Meta's subscription handshake, so the template-status webhook is **unsubscribable**, not merely silent (challenger, probed) |
| Gateway plan activation | `config/payments.php:64-568` | 27 providers, **all** default `false`; `GuardsPlanActivation::assertPlanActivationEnabled()` aborts 403 before any PlanOrder write (`app/Http/Controllers/Concerns/GuardsPlanActivation.php:67-93`) |

**Consequence for Phase 5:** every code path this document grades `PRESENT` is present *in code*. In this environment, none of the Lynomia-facing paths has executed end to end, and `whatsapp_campaign` being off by default means a default account's campaign is `blocked` before `process_audience` ever runs (`app/models/campaign.rb:74-80, 110-112`). **No Phase 5 increment may be reported as verified on the strength of a passing spec suite alone.** Staging verification with these switches on is a release gate, not a formality.

### 0.2 Every Phase 5 upstream surface is administrator-only, and merchant staff are agents

| Surface | Policy | File:line |
|---|---|---|
| Campaign CRUD | `@account_user.administrator?` on every action | `app/policies/campaign_policy.rb:1-21` |
| Per-campaign analytics | `authorize @campaign, :show?` → same policy | `custom/app/controllers/api/v1/accounts/campaigns/analytics_controller.rb:28` (verified) |
| Account-wide analytics | `ReportPolicy#view?` → `administrator?`, **no `custom/` override exists** | `app/policies/report_policy.rb:1-5` |
| Template manager (7 routes) | `administrator?` on all seven, by explicit design | `custom/app/policies/whatsapp/message_template_policy.rb:1-5, 6-34` |
| Shared audience create/update/delete | `Current.account_user.administrator?` | `custom/app/controllers/custom/api/v1/accounts/custom_filters_controller.rb:7,13,20` (verified) |
| Audience preview | `authorize Campaign, :create?` | `custom/app/controllers/api/v1/accounts/campaigns/audience_previews_controller.rb:8` |

Store Syria's BFF mints a **per-person** token whose Lynomia role comes from `IdentityProvisioner::roleFor()` (`app/Services/IdentityProvisioner.php:98`) — administrator for the store owner, agent for everyone else (`app/Models/LynomiaStoreIdentity.php:35,42`).

**There is no custom-role permission key for campaigns, reports or templates anywhere in Lynomia.** Therefore:

> **Phase 5's "per-person permissions" requirement cannot be expressed below the owner/staff line on the Lynomia side. Every campaign, template and analytics surface is reachable only by the merchant principal whose Lynomia `account_user` is `administrator`. Store Syria's five Laratrust permissions can narrow access *further* among its own staff, but they cannot widen it: a staff member holding "Manage Lynomia Campaigns" would still receive 401 from Lynomia.**

This is a contract constraint, not an oversight, and it must be decided before P5D. The options are (a) build the merchant campaign surface as owner-only and say so, (b) have Lynomia add a custom-role key, or (c) elevate every linked merchant principal to Lynomia administrator — which conflicts with the Phase 2 least-privilege posture.

---

## 1. Verdict per Phase 5 objective

### Objective 1 — Native campaign management inside Store Syria, reusing Lynomia's campaign infrastructure

**PARTIAL — deliverable only as "schedule and send one WhatsApp Cloud batch, then delete it."**

Reusable: the full CRUD surface `GET|POST|PATCH|DELETE /api/v1/accounts/:id/campaigns` (`app/controllers/api/v1/accounts/campaigns_controller.rb`), the scheduler (`config/schedule.yml:12-15` → `TriggerScheduledItemsJob` → `Campaigns::TriggerOneoffCampaignJob`), the `whatsapp_campaign` capability gate with the `blocked` holding state (`app/models/campaign.rb:61,74-80,110-130`), and the recipient funnel (`custom/app/models/campaign_recipient.rb`).

Not deliverable:
- **There are exactly two campaign types** (verified: `app/models/campaign.rb:50`, `enum campaign_type: {ongoing: 0, one_off: 1}`). There is no drip, no recurring, no triggered, no A/B.
- **`ongoing` has no server executor at all.** `execute_campaign` (`app/models/campaign.rb:167-176`) has no `Website` branch and `trigger!` returns unless `one_off?`. Website campaigns are delivered by the embedded browser widget POSTing `campaign.triggered` to `/api/v1/widget/events`. Store Syria cannot drive them.
- **Pause / resume / cancel do not exist.** The status enum is not client-settable (`campaign_params` at `campaigns_controller.rb:31-32` does not permit `campaign_status` — verified). The only cancel is `DELETE`, and that cascades `campaign_recipients` (`db/schema.rb:2144`, `ON DELETE CASCADE`).
- Store Syria has **nothing**: zero controllers, routes, services, client methods, models, permissions, menu entries, translation keys or tests mentioning a campaign as a resource. Verified — the only two files containing the word are `CommunicationCenterController.php:19` and `resources/views/merchant/overview.blade.php:115,170`, both prose saying campaigns are a later phase.

### Objective 2 — Verified campaign types and eligible channels, without inventing provider capabilities

**DELIVERABLE, but only by narrowing harder than Lynomia itself does.**

Lynomia's own eligibility check is a trap (§3.1). Phase 5 must gate on `Channel::Whatsapp` **and** `provider == 'whatsapp_cloud'`, not on `inbox_type`. The authoritative predicate already exists for flows: `Flows::ChannelCapabilities.for` returns a capability set only when `channel.is_a?(Channel::Whatsapp) && channel.provider == 'whatsapp_cloud'` (`custom/app/services/flows/channel_capabilities.rb:21`).

The honest channel table for a Phase 5 campaign:

| Channel | Sends? | Recorded? | Phase 5 |
|---|---|---|---|
| `Channel::Whatsapp` provider `whatsapp_cloud` | yes | yes — full recipient funnel | **supported** |
| `Channel::Whatsapp` provider `default` (360dialog) | **no** — raises `'WhatsApp Cloud provider required'` | — | **must be refused at create** |
| `Channel::TwilioSms` medium `whatsapp` | **no** — same raise, same trap | — | **must be refused at create** |
| `Channel::Sms` (Bandwidth) | yes — real outbound | **nothing**: no `campaign_recipients`, no `Message` | **out of scope, or shipped explicitly unmeasured** |
| `Channel::TwilioSms` medium `sms` | yes — real outbound | **nothing** | same |
| Website widget | browser-driven only | creates a Conversation | **out of scope** |

### Objective 3 — Merchant-owned customer audiences and segmentation with strict store isolation

**PARTIAL — and the words "strict store isolation" cannot honestly be applied to commerce aggregates.**

What is reusable and sound: the audience model itself (a `custom_filters` row with `filter_type: contact, shared: true`), live SQL evaluation with no stored membership (`custom/app/models/custom/custom_filter.rb:13-16`), the full commerce condition family (`custom/app/services/audience/commerce_condition.rb:15-33`), bind-parameterised SQL with allow-listed operators, dependency protection (`custom/app/services/audience/usage.rb`), and a trustworthy pre-send count (`POST /campaigns/audience_preview`).

**Account** isolation is enforced in three independent places and is sound (§5.1).

**Store isolation inside one account is not enforced.** `commerce_store equal_to [A]` asserts only that the contact *has a counted link* in store A; every order, spend, recency and status aggregate is built from the same `links` fragment, which carries **no store predicate** (`custom/app/services/audience/commerce_condition.rb:77-85`, with aggregates at `:88, :91-93, :95-104, :106-110`). The reader proved by execution that a contact with 0 orders in store A and 9 orders in store B matches `commerce_store = A AND commerce_orders_count > 5`. The repository's own spec asserts cross-store summing as *intended* behaviour (`spec/services/contacts/filter_service_audience_spec.rb:90-94`) and `docs/audience/03-commerce-query-model.md:108-111` documents it.

> A multi-store merchant **cannot express "spent over X in this store."** A Store Syria UI that renders a store filter beside a spend filter and labels the combination store-scoped would be lying, and would target the wrong people. The per-store data exists — `commerce_contact_metrics` is one row per customer link — only the query builder discards the scope.

This is a **new-endpoint-class contract gap** (§4, CG-3): Phase 5 either threads a store predicate through that fragment in Lynomia, or ships audiences with store-scoped *membership* and account-wide *aggregates*, labelled as such in both languages.

### Objective 4 — Template management and approval-status visibility on Lynomia's real provider functionality

**PARTIAL — read-side visibility is deliverable; the write-side lifecycle is not verified and is blocked by policy.**

The manager is real: `Whatsapp::MessageTemplate` rows, a validator against Meta's published rules, and a submission path that genuinely POSTs to Graph with a row-locked double-submit claim (`custom/app/services/whatsapp/templates/submission.rb:31-62`). Seven administrator-only routes at `config/routes/whatsapp_templates.rb:10-15`.

Three challenger findings constrain this hard:

1. **[OVERTURNED — "submission works"]** `Channel::Whatsapp#template_access_token` (`app/models/channel/whatsapp.rb:85-88`) returns `provider_config['api_key']` unless `ChatwootApp.chatwoot_cloud?`, which requires `ChatwootApp.enterprise?` — **hardcoded `false`** at `lib/chatwoot_app.rb:19-21` in this fork. The `business_management_token` branch is unreachable. `Whatsapp::Templates::Operation#client` builds on that same selector (`custom/app/services/whatsapp/templates/operation.rb:34-36`), and the file's own header (`:4-7`) says the api_key "would read every template and fail every write." The four passing submit specs assert `'Authorization' => 'Bearer test_key'` against a WebMock stub (`spec/controllers/api/v1/accounts/whatsapp/message_templates_controller_spec.rb:182-183`). **Nothing in this repository establishes that create/submit/edit/delete succeed against a live WABA.** The reader's flat claim "Phase 5 objective 4 is not blocked" is withdrawn.
2. **[OVERTURNED — "approval status arrives within seconds"]** The WABA-scoped webhook carries no `value.metadata`, so channel resolution returns nil and signature verification falls back to an unset installation-wide secret; probed, `POST /webhooks/whatsapp` → 401 with and without a signature, and `GET` (Meta's handshake) → 401. `WHATSAPP_APP_WEBHOOK_VERIFY_TOKEN` is absent from the `whatsapp_embedded` allow-list (`app/controllers/super_admin/app_configs_controller.rb:76`), so an operator cannot even turn it on from the WhatsApp settings page. **Both stores are stale until the 3-hourly sync; the divergence window is the normal case, not the exception.**
3. **Administrator-only policy** (§0.2) means a merchant staff member cannot reach any of the seven endpoints.

What *is* deliverable: projecting `meta_status`, `rejected_reason`, `rejection_info`, `last_event`, `missing_at_meta`, `allowed_actions` and `validation_problems` — all already served by `custom/app/views/api/v1/accounts/whatsapp/message_templates/_message_template.json.jbuilder:13-27`, with en/ar locale parity at 199 keys each.

What is **not** deliverable from the existing flow catalogue: `Flows::Template.sendable` filters to `approved?(template) && sendable?(template)` and `catalogue_entry` emits only `{name, language, category, slots}` (verified: `custom/app/services/flows/template.rb:24-36`). **A pending or rejected template is invisible, not shown as pending.** Objective 4 needs a different upstream read (CG-4).

### Objective 5 — Enforce marketing consent, opt-out, suppression and provider sending rules

**NOT DELIVERABLE BY REUSE. This is the hardest finding in the document and the one most likely to cause real harm.**

Verified by direct grep across every file in the campaign send chain: `app/services/whatsapp/oneoff_campaign_service.rb`, `custom/app/services/custom/whatsapp/oneoff_campaign_service.rb`, `app/services/sms/oneoff_sms_campaign_service.rb`, `app/services/twilio/oneoff_sms_campaign_service.rb`, `app/models/campaign.rb`, `custom/app/models/custom/campaign_audience.rb`, `custom/app/services/campaigns/recipient_snapshot.rb` — **zero references to `MessagingEligibility`, `MessagingConsent` or a `blocked?` contact check.** The only `blocked` hits are the campaign's own `blocked` *status* and the authentication-template guard.

`Campaign#audience_contacts` is, verbatim (`app/models/campaign.rb:103-106`):

```ruby
def audience_contacts
  label_ids = audience.select { |entry| entry['type'] == 'Label' }.pluck('id')
  account.contacts.tagged_with(account.labels.where(id: label_ids).pluck(:title), any: true)
end
```

No consent predicate. No `blocked` predicate. The generated SQL was captured by a challenger and contains nothing but the account id and the tagging `EXISTS`.

`Commerce::MessagingEligibility` has exactly **three** production callers (verified by repo-wide grep): `custom/app/services/custom/automation_rules/template_action.rb:69-71`, `custom/app/services/commerce/recovery_messages.rb:53`, and the read-only preview at `custom/app/controllers/api/v1/accounts/commerce/consents_controller.rb:121`. Its own header claims "Every automated outbound path in Commerce passes through here" — true of two paths, false of campaigns, flows and the generic Message send.

Reproduced by the readers: a label audience returned **all four** of a blocked contact, a `withdrawn` contact, a `suppressed` contact and a clean contact, while `MessagingEligibility.refusal` correctly returned `:contact_unavailable`, `:consent_withdrawn`, `:messaging_suppressed` and `nil` respectively. **`suppressed` is the state reserved for "the provider says this number cannot receive"** — re-sending to it is precisely what damages a WhatsApp sender quality rating.

Two further structural facts make this an *architectural* decision, not a bug fix:

- **The send fires server-side with Store Syria out of the loop.** `TriggerScheduledItemsJob` (sidekiq-cron, every 5 minutes) selects and enqueues; Store Syria is not in the request path. **Any consent filter applied in Store Syria at compose time is advisory only.** Enforcement must be in Lynomia's send path.
- **`Campaigns::RecipientSnapshot::SKIP_REASONS` is a closed set of six deliverability reasons** (`custom/app/services/campaigns/recipient_snapshot.rb:24-34`) with no consent entry. Even once consent is enforced, the analytics surface has no vocabulary to report it, and the current absence of such rows reads as "nobody was skipped for consent" rather than "consent was never checked."

The one *adjacent* mechanism that works is the opt-out keyword listener (`custom/app/listeners/commerce/consent_listener.rb:12-26`), which is Arabic-capable and correctly matches whole messages only. It writes rows that nothing on the campaign path reads — which makes the already-shipped consent UI **actively misleading**: it renders a live "this send would be refused" field (`consents_controller.rb:113-122`) while the next campaign sends anyway.

### Objective 6 — Safe scheduling, campaign lifecycle controls and execution monitoring

**PARTIAL.**

- **Scheduling: deliverable**, with limits that must be surfaced in the UI — 5-minute resolution not exact-time; a `scheduled_at` older than 3 days is silently never selected (`app/jobs/trigger_scheduled_items_job.rb:8`, verified); no future-dating validation; no per-campaign timezone.
- **Lifecycle controls: not deliverable.** No pause, no resume, no cancel, no abort. `Campaign#unblock!` exists, is spec-covered, and has **zero callers outside specs** — no route, no controller action, no UI button. A blocked campaign is visible and unrecoverable over HTTP.
- **Execution monitoring: deliverable for WhatsApp Cloud**, via `GET /campaigns/:campaign_id/analytics/metrics` and `/contacts` — subject to the primary-key trap (§3.2) and poll-only (no event, webhook or ActionCable broadcast for a `CampaignRecipient` transition; `custom/app/models/campaign_recipient.rb` has no `after_commit`).
- **Throttling: absent.** A whole campaign is one Sidekiq job on the `low` queue iterating its entire audience in a bare synchronous loop — `contacts.each { |contact| process_contact(contact) }` (`app/services/whatsapp/oneoff_campaign_service.rb:62-69`) — with no sleep, no batching, no concurrency cap, no per-second ceiling and no backoff on provider 429s. Quality rating and messaging-limit tier are fetched, persisted and displayed (`app/services/whatsapp/health_service.rb:25-26`) and read by **nothing** that can slow or stop a send.

### Objective 7 — Reliable campaign performance analytics, distinguishing supported from unavailable metrics

**DELIVERABLE for the supported subset, provided the unavailable list ships with it.**

Supported and well built (WhatsApp Cloud only): per-recipient ladder with four real timestamps, forward-only under a row lock, idempotent against Meta's out-of-order and redelivered reports (`custom/app/models/campaign_recipient.rb:58-80`); eight count KPIs, three rate KPIs (nil not 0 when nothing targeted), three series and four breakdowns (`custom/app/services/analytics/campaigns/metrics.rb`); a machine-readable capability contract at `GET /analytics` (`custom/app/controllers/api/v1/accounts/analytics_controller.rb:84-99`).

**Must be declared unavailable** (no table, no column, no code — verified by repo-wide grep): click tracking, link shortening, URL attribution, open rate, conversion attribution, revenue/ROI, unsubscribe counter, bounce class, cost per message, Meta conversation-category pricing. `read_rate` is the honest substitute for opens.

**Must be labelled honestly:**
- the audience breakdown counts **campaigns, not recipients**, and says why (`metrics.rb:157-163`) — a recipient cannot be attributed to the label or audience that selected it, because membership resolves at send time and is never stored;
- series are bucketed by **send date, not outcome date** (`metrics.rb:84-91, 123-126`);
- `delivered` means different things in the campaign family (`delivered_at IS NOT NULL OR read_at IS NOT NULL`) and the WhatsApp family (`status` reached delivered or read, understated after a late failure). Showing both on one screen without saying so is a reporting defect.

**[OVERTURNED]** The reader graded account-wide WhatsApp delivery analytics as covering "campaign, flow, automation, agent." A campaign send creates no `Message` (`app/services/whatsapp/oneoff_campaign_service.rb:95-100` passes `nil` as the message argument), and `Analytics::Whatsapp::Metrics#sends` reads `@account.messages` (`custom/app/services/analytics/whatsapp/metrics.rb:123-131`). **Campaign sends are structurally impossible to count in that family**, while the class header at `:3-4` asserts the opposite. That header is a trap for Phase 5.

### Objective 8 — Configurable commercial entitlements: feature availability, usage ceilings, trials, add-ons

**SPLIT VERDICT. Two of four are deliverable; two are not.**

| Sub-objective | Verdict | Why |
|---|---|---|
| **Feature availability** | **DELIVERABLE** | `BillingPlan.assignable_features` (42 at runtime) includes both `campaigns` and `whatsapp_campaign`; `Billing::FeatureSync` writes plan features into the account's flag bitmask (`custom/app/services/billing/feature_sync.rb:17-41`), so every existing `account.feature_enabled?` gate keeps working. Per-account exceptions go through `Billing::OverrideGrant`. |
| **Usage ceilings (campaign/message volume)** | **NOT DELIVERABLE without an upstream change** | `BillingPlan::LIMIT_KEYS` is verified as exactly `%w[agents inboxes stores flows]` (`custom/app/models/billing_plan.rb:11`), and `PlatformAppEntitlementGrant` validates `resource` against that list (`custom/app/models/platform_app_entitlement_grant.rb:9`, verified). A grant naming `campaigns` or `messages` is refused 422 today. Worse: **every `COUNTS` lambda is a live count over current state with no time window** (`custom/app/models/billing/resource_limit.rb:28-36`, verified) — there is no counting model for "sends this period" anywhere in either codebase. Store Syria's one metered counter, `users.storage_limit`, is a lifetime running total with no period anchor. |
| **Trials** | **NOT DELIVERABLE as feature- or add-on-level** | Lynomia trials are plan-level and account-level (`custom/app/services/billing/trial_starter.rb`); `BillingTrialUsage` is keyed by email only, with no feature, plan or add-on dimension. Store Syria's is "one free trial of one paid plan, forever, per account" (`users.trial_plan`, never reset). Neither can express "campaigns on trial for 14 days." And a time-boxed **feature** override — exactly the shape of an add-on trial — **does not end when it expires** (§3.6). |
| **Add-ons** | **NOT DELIVERABLE — nothing to extend on either side** | Lynomia: no add-on model, column, join table or line item; Checkout builds exactly one line item (`custom/app/services/billing/checkout.rb:58`). Store Syria: `add_on_managers` is the **platform module registry**, not a catalogue — `is_enable` is a single installation-wide switch with no tenant column, and `monthly_price`/`yearly_price` are display-only. The module's own seeder says so (`packages/workdo/LynomiaChat/src/database/seeders/MarketPlaceSeederTableSeeder.php:11-16`). The two "add-on session" routes bind to non-existent controller methods and 500 on contact; the public module cart posts to a route registered nowhere. |

### Objective 9 — Preserve independent Lynomia subscriptions; no double charging or entitlement stacking

**DELIVERABLE for the resources the grant table accepts — but the property is architectural abstention, not a checkable flag.**

The working mechanism: Store Syria pushes a `PlatformAppEntitlementGrant` and **never creates a `BillingSubscription`**. Lynomia composes `override > max(plan, grant) > unlimited` — **MAX, not sum**, commented as deliberate (`custom/app/services/billing/entitlements.rb:68-87`) — and honours a grant with no subscription at all (`:80-83`). `limit_source` returns `:override | :plan | :integration | :default`. This is real, tested (`spec/requests/platform/api/v1/entitlement_grants_spec.rb`, 29 examples including the concurrency race) and already live for `agents`.

Constraints that must be respected, not solved by hope:

1. **`billing_subscriptions` has no provenance column.** No `platform_app_id`, no `created_by`, no `origin`. `source` ∈ {stripe, manual} is the payment *mechanism*. **Code cannot distinguish a Store-Syria-caused subscription from a self-serve one.** The anti-double-charge property holds only as long as Store Syria abstains.
2. **One subscription per account** — unique index plus `validates :account_id, uniqueness: true` (`custom/app/models/billing_subscription.rb`). A merchant who self-subscribes and a bundled package compete for the same row.
3. **The merchant can still self-subscribe.** Any account administrator reaching `/app/accounts/:id/settings/subscription` is shown Lynomia's own catalogue and a working pay button (`custom/app/controllers/api/v1/accounts/billing_controller.rb:73-80`). Latent only because `Billing::Settings.enforced?` is false today.
4. **Store Syria never reads back what Lynomia computed.** `PlatformApiClient::effectiveEntitlement()` is defined at `PlatformApiClient.php:413` and has **zero callers** — verified. The MAX composition and the `source` provenance that objective 9 depends on are invisible to the granting side.
5. **`plans.lynomia_agent_seats` is written and audited locally; the wire is inert.** With `lynomia.enabled` false, `SeatAllowanceSync::syncQuietly()` catches the exception and counts a failure (`SeatAllowanceSync.php:95-105`). The 18 passing tests bind a fake client.

### Objective 10 — Complete the relevant integration settings within the existing dedicated Super Admin pages

**DELIVERABLE — additive, with the container and conventions already in place.**

One page, `super-admin/lynomia-integration`, four tabs (Overview, Agent Seats, Automation, Merchant Accounts), `PlatformOperatorOnly` on all seven routes, `AuditLog::record` on every mutating action, and a deliberate design that reaches Lynomia for nothing so it still renders when the integration is down (`SuperAdmin/IntegrationController.php:84-87`). The established convention — stated in the controller docblock at `:28-34` — is to **add a tab, never a second menu entry**.

Ten tabs are named in that docblock and do not exist: Plans & Features, Add-ons, Trials, Promotional Grants, Merchant Exceptions, Quotas, Channel Status, Subscription Sync, Integration Errors, Security & Audit. All greenfield; nothing half-built.

One debt Phase 5 should close rather than add to: the commerce-credential operator controls (`routes/web.php:363-369`, `IntegrationController.php:295-358`) are routed and audited with **no UI anywhere**, and the one-time credential they flash as `lynomia_commerce_credential` is rendered by no blade — so a credential issued through the route is unrecoverable.

---

## 2. The capability matrix

| Capability | Lynomia | Store Syria | Evidence | Phase 5 action |
|---|---|---|---|---|
| Campaign CRUD API | **PRESENT** (admin-only, display_id-keyed) | **ABSENT** | `app/controllers/api/v1/accounts/campaigns_controller.rb:27,31-32`; `app/policies/campaign_policy.rb:1-21` | Proxy via new BFF controller using `ProxiesToLynomia` |
| Campaign types | **PARTIAL** — 2 only; `ongoing` has no server executor | ABSENT | `app/models/campaign.rb:50,167-176` (verified) | Expose `one_off` only |
| Channel eligibility | **TRAP** — `inbox_type` allow-list admits two unsupported providers | ABSENT | `app/models/campaign.rb:191-195` (verified); `app/services/whatsapp/oneoff_campaign_service.rb:27-29,143-145` | Gate on `Channel::Whatsapp` + `provider == 'whatsapp_cloud'` |
| Scheduling | **PRESENT** (5-min, 3-day window) | ABSENT | `config/schedule.yml:12-15`; `app/jobs/trigger_scheduled_items_job.rb:8,15-18` (verified) | Reuse; surface the limits in the UI |
| Pause / resume / cancel | **ABSENT** | ABSENT | enum at `app/models/campaign.rb:61`; `campaign_params` omits `campaign_status` (verified) | Do not offer. Delete-as-cancel must warn about data loss |
| `Campaign#unblock!` | **TRAP** — no caller outside specs | ABSENT | `app/models/campaign.rb:87-100`; grep: only model, one comment, one spec | Do not expose a "resume" control |
| Audience model | **PRESENT** (`custom_filters` + `shared`) | ABSENT | `custom/app/models/custom/custom_filter.rb:4-23`; `db/schema.rb:1233-1244` | Drive the existing CRUD |
| Audience → campaign binding | **PRESENT** (union of Label + shared Audience) | ABSENT | `custom/app/models/custom/campaign_audience.rb:12-31` | Reuse verbatim |
| Audience base-relation parity | **TRAP** — Label leg and Audience leg use different base relations | ABSENT | `app/models/campaign.rb:104-105` vs `app/services/contacts/filter_service.rb:32-34` → `app/models/contact.rb:183-187` | Warn, or offer only one reference type |
| Commerce segmentation | **PRESENT** (9 field families, pure SQL) | ABSENT | `custom/app/services/audience/commerce_condition.rb:15-33,51-57` | Reuse |
| **Store isolation of commerce aggregates** | **TRAP / ABSENT** | n/a | `custom/app/services/audience/commerce_condition.rb:77-85` — `links` has no store predicate | Thread a store predicate upstream, or relabel |
| Audience sharing authorization | **TRAP** — string guard vs AR boolean cast | ABSENT | `custom_filters_controller.rb:7,13,41` vs `:37` (verified) | Must be fixed upstream before merchants reach it |
| Audience size preview | **PRESENT** (admin-only, count only) | ABSENT | `custom/app/controllers/api/v1/accounts/campaigns/audience_previews_controller.rb:7-13` | Proxy; **use this, not `/contacts/filter`** |
| Evaluate a *saved* audience by id | **ABSENT** | ABSENT | no endpoint accepts a `custom_filter` id and returns count or members | CG-2 |
| Audience vocabulary discovery | **PARTIAL** — keys absent from `filter_keys.yml` | ABSENT | `custom/app/services/custom/contacts/filter_service.rb:6-8`; frontend hardcodes at `audienceProvider.js:13-47` | Mirror from Ruby constants, not the JS file |
| Template snapshot (send gate) | **PRESENT** — 3-hourly sync | — | `app/services/whatsapp/providers/whatsapp_cloud_service.rb:35-62` | Pre-send checks must read this |
| Template manager rows | **PRESENT** but **WhatsApp Cloud only** | ABSENT | `custom/app/services/whatsapp/templates/mirror.rb`; 360dialog produces 0 rows (probed) | Gate on provider |
| Template submission to Meta | **PARTIAL / UNVERIFIED** — wrong token class by the code's own contract | ABSENT | `app/models/channel/whatsapp.rb:85-88` + `lib/chatwoot_app.rb:19-21` **[OVERTURNED]** | Do not promise until proven on a live WABA |
| Approval status visibility | **PRESENT** in the manager API | **ABSENT** — flow catalogue strips it | `_message_template.json.jbuilder:13-27` vs `custom/app/services/flows/template.rb:24-36` (verified) | New BFF read of the manager API (CG-4) |
| Real-time template webhook | **TRAP** — unsubscribable in this installation | — | 401 on handshake (probed) **[OVERTURNED]** | Assume 3h staleness |
| Mirror vs local draft | **TRAP** — a sync overwrites a same-identity draft, unaudited | — | `custom/app/services/whatsapp/templates/mirror.rb:72-90`, `:54` **[OVERTURNED]** | Do not build authoring on drafts until fixed |
| Consent model (write) | **PRESENT** — keyword listener, Arabic-capable | **PARTIAL** — per-contact UI only | `custom/app/listeners/commerce/consent_listener.rb:12-26`; `AutomationApiController.php:577-664` | Reuse the vocabulary |
| **Consent enforcement on campaign send** | **ABSENT** | n/a (not in the send path) | verified: zero hits across all 7 campaign-path files | **New enforcement in Lynomia** (CG-1) |
| `contacts.blocked?` on campaign send | **ABSENT** | n/a | `app/models/campaign.rb:103-106` (verified) | Same gate as CG-1 |
| Suppression list | **TRAP** — enum read and clearable, **no production writer** | ABSENT | `custom/app/models/commerce/messaging_consent.rb:26`; only spec setup creates one | Do not rely on provider-side suppression |
| Send throttling | **ABSENT** | **ABSENT** (per-minute HTTP limiters on a lossy file cache only) | `oneoff_campaign_service.rb:62-69`; `LynomiaChatServiceProvider.php:140-143` | New, and it belongs in Lynomia |
| Per-recipient delivery funnel | **PRESENT** — WhatsApp Cloud only | ABSENT | `custom/app/models/campaign_recipient.rb:24-80` | Reuse; label SMS unmeasured |
| Per-campaign metrics API | **PRESENT** but **PK-keyed** | ABSENT | `analytics_controller.rb:27` vs `_campaign.json.jbuilder:1` (both verified) | Carry both ids in the projection |
| Account-wide campaign analytics | **PRESENT** | ABSENT | `custom/app/services/analytics/campaigns/metrics.rb`; `config/routes/analytics.rb:16` | Proxy, admin-only |
| Clicks / opens / conversion / revenue / cost | **ABSENT** | ABSENT | repo-wide grep: no table, column, redirect or webhook | Publish as an explicit unavailable list |
| Delivery-outcome push | **ABSENT** | ABSENT | no `after_commit` on `CampaignRecipient`; `EventSubscriptionManager.php:41-47` has 5 conversation events | Poll, and say so |
| Feature availability as a commercial lever | **PRESENT** for `whatsapp_campaign`; **TRAP** for `campaigns` | **PARTIAL** — module CSV, binary | `app/models/campaign.rb:110-112` vs no server gate on `campaigns` **[OVERTURNED]** | Sell `whatsapp_campaign`; do not rely on `campaigns` |
| Usage ceiling rail | **PRESENT** for 4 keys; closed | **PRESENT** for `agents` only; wire inert | `billing_plan.rb:11`; `platform_app_entitlement_grant.rb:9`; `SeatAllowanceSync.php:57-59` (all verified) | New LIMIT_KEY + enforcement concern, or no campaign quota |
| Period-windowed usage meter | **ABSENT** | **ABSENT** | every `COUNTS` lambda is current-state (verified); `users.storage_limit` is lifetime | New counting model |
| Trials | **PARTIAL** — plan-level, never actively ended | **PARTIAL** — one ever, and expiry does not revoke modules | `trial_starter.rb`; `app/Models/User.php:202` (`if(!empty($modules))`) **[challenger, probed]** | Do not promise feature-level trials |
| Add-ons | **ABSENT** | **ABSENT** (registry mistaken for a catalogue) | `custom/app/services/billing/checkout.rb:58`; `MarketPlaceSeederTableSeeder.php:11-16` | Greenfield or descope |
| Super Admin container | — | **PRESENT**, 4 of 14 tabs | `superadmin/integration.blade.php:16-34`; `IntegrationController.php:28-35` | Add tabs |
| BFF proxy pattern | — | **PRESENT** | `app/Http/Controllers/Concerns/ProxiesToLynomia.php:42-59` | Reuse for every new controller |
| Per-person token minting | — | **PRESENT** (with 3 platform-token exceptions on connect routes) | `MerchantInboxGateway.php:44-118`; `ConnectionController.php:27-31` **[challenger]** | Reuse |
| Projection discipline | — | **PRESENT**, with leak assertions | `AutomationApiController.php:755-787,834-840`; `LynomiaPhase4SecurityTest.php:222-223` | Extend, including recipient phone numbers |
| Browser harness | — | **PRESENT** | `tests/Browser/run.sh`, 4 Playwright scripts, en + ar at 390×844 | Add a campaign target |

---

## 3. The traps

Ordered by what they would have cost a client that trusted them.

### 3.1 A 360dialog or Twilio-WhatsApp inbox passes campaign validation, then strands the campaign in `processing` forever
`Inbox#inbox_type` is `channel.name` (`app/models/inbox.rb:178-180`), and `Channel::TwilioSms#name` returns `'Whatsapp'` when `medium == whatsapp` (`app/models/channel/twilio_sms.rb:54-56`). Both pass `validate_campaign_inbox`'s allow-list (verified, `app/models/campaign.rb:191-195`), are routed to `Whatsapp::OneoffCampaignService`, and are then refused by `raise 'WhatsApp Cloud provider required'` (`:27-29`) — **after** `mark_processing!` has already flipped the row. The scheduler selects only `campaign_status: :active` (verified, `trigger_scheduled_items_job.rb:15-18`), so it is never retried; a manual re-run is a silent no-op; no `blocked_reason`, no Operations signal, no recipient row, no recovery API. Both reproduced end to end.
**Cost if trusted:** a merchant on the wrong WhatsApp provider schedules a campaign, sees it accepted, and it never sends and never errors. Compounded by 3.4: the stuck campaign permanently freezes its shared audience against deletion.

### 3.2 Campaign analytics is unreachable with the id the campaigns API publishes
Verified directly: `app/views/api/v1/models/_campaign.json.jbuilder:1` is `json.id resource.display_id`; `campaigns_controller.rb:27` resolves `find_by(display_id:)`; `analytics_controller.rb:27` resolves `Current.account.campaigns.find(params[:campaign_id])` — **the global primary key**. `display_id` comes from a per-account sequence (`app/models/campaign.rb:225-227`). Two readers reproduced the 404 independently with interleaved accounts. The dangerous case is worse than a 404: when an account's own `display_id` collides with one of its *own* other campaigns' primary keys, the endpoint returns **another campaign's funnel with a 200 and no warning**.
The same confusion reaches four more surfaces: the `by_campaign` breakdown emits PKs (`metrics.rb:136-140`); the contact activity timeline emits a PK (`campaigns_adapter.rb:34`); `conversations.campaign_id` is written as a PK (`campaign_conversation_builder.rb:28,38`) while the dashboard filter sends `display_id` (`provider.js:241-243`) — reproduced: `all_count=0` with the display_id, `all_count=1` with the PK; and automation-rule `campaign_id` conditions use the same YAML. **Nothing in the public API ever publishes the primary key.**
**Cost if trusted:** a Store Syria BFF that forwards the list `id` 404s in the normal case and shows the wrong campaign's delivery numbers in the bad one. The existing request spec misses it because it builds the URL from the PK (`spec/requests/campaign_analytics_spec.rb:19-20`).

### 3.3 PATCH of `inbox_id` silently converts a widget campaign into an immediate SMS blast
`ensure_correct_campaign_attributes` is a `before_validation` callback that runs on **every** save (verified, `app/models/campaign.rb:69,198-207`), rewriting `campaign_type` and `scheduled_at` from the inbox. The controller permits `:inbox_id` and refuses an update only when already `completed` (`:233`). A challenger reproduced it: changing nothing but the inbox on an `ongoing` Website campaign produced `type=one_off scheduled_at=<now> status=active`, the scheduler selected it, and `trigger!` made **three real outbound SMS of the live-chat greeting text** to the whole label audience, writing zero recipient rows. The reverse also holds — a `processing` WhatsApp campaign PATCHed onto a Website inbox becomes `ongoing` with `scheduled_at` nulled and can never complete.
**Cost if trusted:** any Store Syria campaign-edit endpoint that forwards `inbox_id` inherits an uncontrolled-outbound path. **This is the single highest-risk finding for "no uncontrolled outbound messaging."**

### 3.4 Deleting a campaign is the only cancel, and it erases the only record the send ever happened
`campaign_recipients.campaign_id` carries `ON DELETE CASCADE` (`db/schema.rb:2144`) and the association is deliberately `dependent: nil` (`custom/app/models/custom/campaign.rb:7-14` — **[OVERTURNED]**: the reader claimed no association exists; it does, and explicitly declines to act). Because a campaign send creates no Message and no Conversation, that table is the *only* evidence, and every analytics surface counts off it. Reproduced: destroying a completed campaign holding one `delivered` row took the account's count 1 → 0 with no orphans; `destroy!` also succeeds on a campaign in `processing` with recipients already written.
A **second, far more ordinary** route to the same loss: re-running a campaign. `Campaigns::RecipientSnapshot#write` upserts with `update_only: OUTCOME_COLUMNS + %i[inbox_id]` (`custom/app/services/campaigns/recipient_snapshot.rb:113-122`), writing `source_id`, all four timestamps and all three error columns as NULL for any re-addressed contact, while preserving `created_at` (`:17`) so the blanked rows stay in the already-reported bucket. **[OVERTURNED]** — the reader cited `:110-114` as nil-protection; that protection is in a different file (`campaign_recipient.rb:110-114`) and applies only to the provider-report path.
**Cost if trusted:** a closed reporting period silently shrinks. Lynomia cannot be treated as the system of record for completed campaigns.

### 3.5 `enabled: false` does not hold a scheduled campaign
The REST API permits `:enabled` on any campaign (`campaigns_controller.rb:31`), but the scheduler's predicate has no `enabled` term (verified, `trigger_scheduled_items_job.rb:15-18`) and `trigger!` never reads it. The only reader is the widget index (`app/controllers/api/v1/widget/campaigns_controller.rb:10`). Reproduced: a disabled one-off SMS campaign scheduled one minute ago was selected.
**Cost if trusted:** a BFF that sets `enabled: false` expecting a hold still sends.

### 3.6 A lapsed FEATURE entitlement override never reverts the account's flag
Granting writes the account's feature bit (`custom/app/services/billing/override_grant.rb:114-116`); expiry only drops the row from the `.live` scope. There is **no sweeper** (`config/schedule.yml` has 13 cron entries, none billing-related) and `FeatureSync` runs on only two triggers. So `account.feature_enabled?` — what every real gate reads — keeps returning the expired override's answer indefinitely. Reproduced; **[OVERTURNED detail]** `Billing::Entitlements.source` flips to `:default`, not `:plan`, in the normal case. LIMIT and CHANNEL overrides lapse correctly because those resolvers query `.live` on every call.
**Cost if trusted:** this is *exactly* the shape of an add-on trial. A 14-day campaign trial granted this way never ends.

### 3.7 The `campaigns` plan feature is a UI toggle, not a commercial control
**[OVERTURNED]** The reader graded both campaign flags as working commercial levers. `whatsapp_campaign` is genuinely server-enforced (`app/models/campaign.rb:110-112`). `campaigns` is **not enforced anywhere on the server** for the dashboard API: `campaigns_controller.rb` has only `check_authorization` against a policy with no feature check, and the only two readers of the flag in the whole tree are a frontend route guard and the widget delivery query. An account on a plan excluding `campaigns` can still POST a campaign, and a Twilio/Bandwidth SMS one-off **is actually sent**.
**Cost if trusted:** a bundled package that "excludes campaigns" sells nothing.

### 3.8 The Flow Builder offers an "If in audience" node whose picker can never be filled
The node is real upstream (`custom/app/services/flows/node_types.rb:20`), the Store Syria builder renders it as a first-class palette entry (`builder.blade.php:406,532`), the vocabulary binds `contact_audience` → `'audiences'` (verified, `custom/app/services/automation/vocabulary.rb:34`), and `references()` returns exactly seven keys with **no `audiences`** (verified, `AutomationApiController.php:227-238`). The merchant drags it, gets one empty dropdown, and the flow is refused at publish by `Flows::NodeValidator#check_audience_condition`. **Invisible to both test layers**: the feature fixture carries only `start` and `send_message` node types, and `grep -rn audience tests/` finds two prose comments.
**Cost if trusted:** a shipped Phase 4 node that cannot be completed. Serving `audiences` from `references()` repairs it — the cheapest win in Phase 5.

### 3.9 An agent can mint an account-wide shared audience with `shared: 1`
`sharing_requested?` is a **string** comparison, `params.dig(:custom_filter, :shared).to_s == 'true'` (verified, `custom_filters_controller.rb:41`), while persistence goes through `permitted_payload` (`:37`, permits `:shared`) into ActiveRecord's boolean cast, which treats `1`, `"TRUE"`, `"yes"`, `"on"`, `"t"` as true. A challenger probed all of them: HTTP 200, `persisted_shared=true`. Only the literal JSON `true` is caught (401). `CustomFilterPolicy` grants create/update/destroy to agents, so the string check is the only barrier. End to end: an agent-minted shared audience was successfully targeted from `POST /campaigns/audience_preview`.
The **un-share** guard has the identical defect (`:14`, `.to_s == 'false'`): `{shared: 0}` and `{shared: 'f'}` both bypass the in-use check, leaving a scheduled campaign whose `audience_contacts` then raises `RecordNotFound` and an automation rule that raises at every matching event. `DELETE` is genuinely protected.
**Cost if trusted:** the admin-only sharing rule, which Phase 5's audience permission model would be built on, does not hold.

### 3.10 An unrecognised audience `type` is accepted, validates clean, and addresses nobody
`campaign_params` permits `audience: [:type, :id]` with no allow-list; `audience_contacts` selects only `type == 'Label'` and the overlay only `'Audience'`. Reproduced: `[{type:'Contact', id:N}]` → `valid? => true, errors => []`, `audience_contacts.count => 0`, campaign completes `completed` having addressed nobody.
**Cost if trusted:** a Store Syria segment type Lynomia does not know becomes a silently empty send with a success report.

### 3.11 `crm_v2` silently narrows every audience to `contact_type = 'lead'`
`app/models/contact.rb:183-184` is `return where(contact_type: 'lead') if use_crm_v2` — an **exclusive** branch. Every `customer` and `visitor` disappears from every saved audience, every campaign referencing one, every preview count and every automation `contact_audience` test, with no error. Off by default (`config/features.yml:207-210`) but an ordinary Super Admin toggle, advertised nowhere as a marketing-reach change. Paired with `STORESYRIA_ENABLED` defaulting false, which empties the commerce *conditions*: one switch empties the conditions, the other empties the population.

### 3.12 Label recipients and Audience recipients disagree about who exists
The Label leg is a bare `account.contacts.tagged_with(...)` (verified, `app/models/campaign.rb:104-105`); the Audience leg goes through `resolved_contacts`, which requires a non-blank email, phone or identifier (`app/services/contacts/filter_service.rb:32-34` → `app/models/contact.rb:183-187`). Proven: a contact with all three null, carrying the label, is a recipient via the label and not via an audience. The same person; the same intent; a different count depending on which reference type the operator picked.

### 3.13 Audience conditions join in a flat, unparenthesised AND/OR chain
Each condition is parenthesised; the chain is not (`custom/app/services/custom/contacts/filter_service.rb:44` + `app/services/filter_service.rb:195-198`). Captured SQL shows `A OR B AND C`, which Postgres reads as `A OR (B AND C)` while the UI's flat list reads as `(A OR B) AND C`. **For a marketing audience the failure direction is outward** — a merchant writing "VIP OR loyal, AND consented" gets the whole first group unconditionally.

### 3.14 The suppression state has no producer
`Commerce::MessagingConsent` state `suppressed` is read by the eligibility engine, clearable through a dedicated API action, and documented at length as an operator workflow — and **nothing in production ever writes it** (exhaustive grep: only the reader, the clearer, the enum and comments; every other hit is under `spec/`). There is no suppression list, no bounce handler and no hard-fail handler. Email's `bounced?` predicate (`app/presenters/mail_presenter.rb:172-173`) has **zero callers**.

### 3.15 A contact merge destroys a shopper's opt-out
`commerce_messaging_consents` has **no foreign key** (`db/schema.rb:955-967`), Contact declares no association, and `Contacts::MergeRelocation` — whose header is an exhaustive inventory of everything a merge must carry — does not name it. Proven: after merging, the survivor has no consent row, `refusal(:transactional)` flips from `:consent_withdrawn` to **nil (allowed again)**, and `refusal(:promotional)` degrades to `:promotional_consent_missing`, so the system can no longer distinguish "this person said STOP" from "we never asked." Reachable from an ordinary agent action. **This defeats consent even on the two paths that do enforce it.**

### 3.16 `Whatsapp::Templates::Query#sendable_for` / `#for_inbox` have no production callers
Both file headers present them as "the one door every new surface reads templates through — the campaign and flow selectors." Repo-wide grep finds callers only in their own spec and two docs files. The composer and campaign picker filter **client-side** in JavaScript (`inboxes.js:72`); the flow builder uses a separate server implementation. **Three independent expressions of "which templates may be offered."**

### 3.17 Smaller traps worth naming
- **`scheduled_plan_id` / `stripe_schedule_id`**: columns, associations and a serialised field for a deferred plan change that **nothing ever writes**.
- **Platform API silently drops `channel_entitlements`**: not in `plan_params`, not in the serializer — and an empty channel list means *sell every channel*.
- **`accounts.limits`**: the Platform API accepts it, persists it to a real column, and **echoes it back**, while the only consumer returns the constant `100_000` (`app/models/account.rb:159-164`). Worse than a dropped param: the round-trip looks like confirmation.
- **An expired trial still counts as a billed subscriber** (`BILLED_STATUSES` has no time check), blocking plan narrowing and inflating `subscribers_count` — while being structurally invisible to the operator's "locked accounts" list.
- **Store Syria `assignPlan()` revokes modules from `\Auth::user()`, not the beneficiary** (`app/Models/User.php:234`). Every downgrade is operator-initiated, so the **operator's** rows are deleted and the merchant keeps LynomiaChat. In CLI/queue it **throws** mid-method (challenger, probed). And the whole block is skipped when the new plan's `modules` is empty — true of the seeded free plan.
- **Store Syria paid-plan expiry stops nothing mid-session**: the refusal redirect is commented out at `app/Http/Middleware/SetLocate.php:79`.
- **`PlanController::planDisable` has no authorization at all** (`routes/web.php:662`): any authenticated merchant can rewrite the platform's plan catalogue, including plan 1 with live subscribers.
- **The BFF's `consents()` silently drops an unrecognised filter** (`AutomationApiController.php:668-671,580-583`), returning the unfiltered list with a 200 — on the exact endpoint whose upstream wrote a paragraph explaining why that is dangerous (`consents_controller.rb:73-75`).
- **A *claimed* workspace never gets a webhook subscription**: `ensureSubscribed` has one caller, on the provision path only (`AccountProvisioner.php:199`); `AccountLinker.php` has no reference to events. And `ensureSubscribed` never reconciles an existing subscription list, with no `updateWebhook` to do it with.

---

## 4. Contract gaps

Preference order as instructed: existing endpoint used differently → computed in Store Syria → new Lynomia endpoint.

| # | What Phase 5 needs | Resolution | Why |
|---|---|---|---|
| **CG-1** | **Consent / opt-out / suppression / blocked enforced on the campaign send path** | **NEW — Lynomia, in the send path** | No existing endpoint can serve this. The scheduler fires server-side with Store Syria out of the loop, so a Store Syria filter is advisory only. The single shared entry point is `Campaign#audience_contacts` (`app/models/campaign.rb:103-106`) and the overlay's `process_contact` hooks. Enforcing at audience resolution makes the refusal invisible in the snapshot; enforcing per contact records it as a skip reason at the cost of a per-contact query. **`SKIP_REASONS` must gain a consent entry either way** (`custom/app/services/campaigns/recipient_snapshot.rb:24-34`). |
| **CG-2** | Evaluate a saved audience by id (count or members) | **EXISTING, used differently** | Use `POST /campaigns/audience_preview` with `[{type:'Audience', id}]` — it returns the number the campaign would actually send to and is already deduplicated and sharing-validated. **Do not use `POST /contacts/filter`**: it is viewer-scoped for conversation conditions (`custom/app/services/audience/conversation_condition.rb:35-39`) and will disagree with the send. |
| **CG-3** | Store-scoped order/spend aggregates | **NEW — Lynomia, one place** | `custom/app/services/audience/commerce_condition.rb:77-85` is the single location a store predicate would thread through. No composition of existing calls produces it; Store Syria cannot compute it because the aggregates live in Lynomia's `commerce_contact_metrics`. |
| **CG-4** | Template approval status for the merchant | **EXISTING, used differently** | `GET /api/v1/accounts/:id/whatsapp/message_templates` already returns `meta_status`, `rejected_reason`, `rejection_info`, `last_event`, `missing_at_meta`. **Blocked by the administrator-only policy**, so it resolves to "existing endpoint" only for an owner principal; otherwise it becomes a new-policy request. |
| **CG-5** | "Is this template sendable *right now*" pre-flight | **EXISTING, used differently** | Read `GET /inboxes/:id/message_templates` — the **snapshot**, which is what the send gate reads (`app/services/whatsapp/template_processor_service.rb:29-35`) — not the manager row. Apply sendability server-side in the BFF; the upstream endpoint applies none. |
| **CG-6** | Lynomia campaign **primary key** in a payload | **NEW — Lynomia (small)** | The PK appears in no campaign JSON. Store Syria cannot compute it. Two options: add the field to the jbuilder, or make the analytics controller resolve by `display_id` to match CRUD — the latter changes an existing URL contract. Until then, **the BFF must send display_id to CRUD and the PK to analytics for the same campaign**, which it has no way to obtain. |
| **CG-7** | Campaign recipient / message-volume quota | **NEW — Lynomia, two parts** | (a) a `LIMIT_KEYS` entry *plus its enforcement concern in the same commit* — adding the key alone makes the ceiling visible everywhere (`Billing::ApiSerializer.usage` iterates the constant) while refusing nothing; (b) a **period-windowed counting model**, which has no precedent: every `COUNTS` lambda is a current-state count (verified). |
| **CG-8** | Store Syria grants a **feature** (not a limit) | **NEW — Lynomia** | `PlatformAppEntitlementGrant` validates `resource` against `LIMIT_KEYS` (verified) and models a number only. The one API-reachable alternative is the account-features write routed through `Billing::OverrideGrant#write_features!`, which **inherits trap 3.6** (a lapsed feature override never reverts). |
| **CG-9** | Read back the composed entitlement and its provenance | **EXISTING, already written, never called** | `PlatformApiClient::effectiveEntitlement()` (`PlatformApiClient.php:413`) — zero callers, verified. `GET /platform/api/v1/accounts/:id/entitlement_grants` returns `{limit, source, used}` per key. Wiring this is a few lines and closes the objective-9 blind spot. |
| **CG-10** | Feature availability + trial state at the merchant surface | **EXISTING, already fetched, discarded** | `/billing/entitlements` is called on **every** Studio list request and returns the full feature map, all four `{used,limit}` pairs, and (for administrators) `plan`, `ends_at` and `cancel_at_period_end` where `ends_at` is the trial end. `projectUsage()` keeps only `limits.flows` (verified, `AutomationApiController.php:953-961`). **Surfacing this is a projection change, not an integration.** |
| **CG-11** | Audience vocabulary discovery | **Store Syria side, from Ruby constants** | The keys are deliberately absent from `filter_keys.yml`. Mirror `Audience::CommerceCondition::FIELDS`/`STATUSES` (`commerce_condition.rb:15-33`) — **not** the existing JS copy at `audienceProvider.js:13-47`, which is already a second copy with nothing reconciling it. |
| **CG-12** | Delivery-outcome push | **Not resolvable — poll** | No `after_commit` on `CampaignRecipient`, no dispatcher, no listener; the webhook subscription list is a const written once at activation and never reconciled, and one of the two connection paths never subscribes at all. Each poll costs the full aggregate. |
| **CG-13** | Store Syria capability advertisement | **Store Syria side** | `CommerceApiController.php:62-67` is a hand-written literal. Any new capability not added there is invisible to the Lynomia adapter however well it works. |

---

## 5. Isolation and authorization findings

### 5.1 Account isolation — **sound, do not rebuild**

Three independent pins, all verified by the readers and uncontested by the challengers:

- Every audience query starts from `@account.contacts.resolved_contacts(...)` (`app/services/contacts/filter_service.rb:32-34`) and `query_builder` can only *append* a WHERE (`app/services/filter_service.rb:198`).
- Every commerce subquery hard-pins `audience_links.account_id = :audience_account` as a **bind value** inside the correlated EXISTS (`custom/app/services/audience/commerce_condition.rb:80-81`, bound at `:53`); the account is never client-supplied.
- `Current.account` is resolved from the URL **and** verified against the user's membership (`app/controllers/concerns/ensure_current_account_helper.rb:4-23`); analytics never reads `params[:account_id]` at all (`custom/app/controllers/analytics/request_scoped.rb:10-13`).

Supporting: `Analytics::FilterSet` proves every filter id belongs to the account before it reaches a query and raises 422 on an unsupported filter rather than dropping it (`custom/app/services/analytics/filter_set.rb:47-54,62-98`). Delivery reports are scoped to the webhook's own inbox (`custom/app/services/whatsapp/delivery_report.rb:58-62`). Cross-account campaign/audience/template access returns 404, not a refusal.

**One divergence from the stated invariant:** the template **webhook write path** is WABA-scoped with no account predicate — `Whatsapp::MessageTemplate.where(business_account_id: @waba_id)` (`custom/app/services/whatsapp/templates/status_update.rb:73`). A challenger proved one event flips rows in two accounts sharing a WABA. Bounded to accounts that genuinely hold that WABA, so not a forgery route, but it is weaker than the "scoped to `Current.account`" claim the controller makes.

### 5.2 Store isolation within an account — **WEAKER than Phases 1–4 established. Flagged.**

Phases 1–4 established store isolation through `EnsureStoreOwnsLink`, which authorizes the **store** from `Store.created_by` against `$user->creatorId()` *before* any link lookup (`EnsureStoreOwnsLink.php:65-73`) and then scopes the link by both store and owner (`:75`). No merchant route accepts a store, link or account identifier — verified against the booted router. That remains sound, and a campaign route declared inside the same group inherits it.

**But Phase 5's own store-isolation requirement is not met upstream.** Within one Lynomia account holding several Store Syria stores, `commerce_store` scopes link *existence* only; every aggregate sums across all counted stores (§3, trap in `commerce_condition.rb:77-85`). The schema supports the distinction — `commerce_contact_metrics` is one row per customer link — so this is a query-builder gap, not a data gap.

Two further store-scope inconsistencies on the Store Syria side that Phase 5 must decide, not inherit:
- **Ceilings disagree about their subject.** `max_stores` and `max_users` count per owner (`StoreController.php:128` → `User::countStore()`), `max_products` counts per **store** (`User::countProducts()` filters `store_id = getCurrentStore()`), so `max_stores=5 × max_products=10` permits 50 products. **[Challenger correction]** the user ceiling is worse still: `UserController.php:98` computes `$creator` and never uses it, counting and reading the plan off the **actor** — giving every staff member a fresh allowance.
- **`user_active_modules` has no `store_id`.** Module entitlement is owner-wide. A per-store campaign entitlement needs a new grant dimension.

### 5.3 Per-person permissions — **structurally capped at the owner/staff line**

Store Syria's layer is real and well-factored: five permissions defined once in `IntegrationController::MODULE_PERMISSIONS` (`:62-68`) and consumed by both the seeder and the Super Admin reach report, enforced by Laratrust middleware configured to `abort(403)`. The measured route stacks confirm 15 layers on merchant routes and that `Permission:Reply`/`Assign` genuinely differentiate.

Three weaknesses to carry into Phase 5:

1. **The `admin` role is one global row.** `Role::where('name','admin')->first()` with no `store_id` filter — granting a permission to it grants it to **every merchant owner on the installation at once** (`PermissionTableSeeder.php:44-49`, which documents this). Per-person narrowing works only for staff roles, which do carry `store_id`. The seeder's own comment is explicit that the permission layer is **not** the tenancy control.
2. **`$guarded = []` is defeated by Laratrust's non-empty `$fillable`** (`App\Models\Permission.php:9`, `App\Models\Role.php:9`). Verified by booting the app: `Permission::fill(['name'=>'X','module'=>'LynomiaChat'])` yields `{"name":"X"}`; `Role::isFillable('store_id')` is false. **A Phase 5 permission seeded with a bare `create()` lands with `module` NULL** — invisible to the reach report and to module-removal cleanup — **and a per-store role created with `create()` silently becomes platform-wide.** Use `forceCreate`/`Model::unguard()`, as the existing seeder does.
3. **Upstream is a flat administrator check everywhere** (§0.2). Store Syria permissions can only narrow.

**Also weaker than Phase 2 established:** the reader's claim that a privileged platform token never serves a merchant request is **PARTIAL**. `ConnectionController` does not use `ProxiesToLynomia` and injects `AccountProvisioner`, `AccountLinker` and `SsoLinkIssuer`, all of which hold `PlatformApiClient` — so `POST /lynomia/connect/provision`, `POST /lynomia/connect/link` and `GET /lynomia/open` reach Lynomia on the platform token, as does first-time identity creation inside the BFF path. These are connection-management acts rather than merchant data reads, which is why the Phase 2 property survives in spirit, but the flat statement is false and Phase 5 must not extend it to any data-bearing route.

---

## 6. The entitlement architecture question (objectives 8 and 9)

### What exists on each side

**Store Syria — bundled commercial authority.** A single flat `plans` table: four integer ceilings (`max_stores`, `max_products`, `max_users`, `storage_limit`), six on/off string flags, a `modules` CSV denormalised into `user_active_modules` at assignment time, one `trial`/`trial_days` pair, and one nullable integer added by this module (`plans.lynomia_agent_seats`). Money becomes entitlement through ~12 still-live gateway controllers plus a bank-transfer rail with the best pattern in the codebase (operator-gated, idempotent, transactional, audited: `BankTransferController.php:138-175`). One operator form pushes a ceiling to Lynomia (`IntegrationController::updateSeats`).

**Lynomia — technical enforcement authority.** `BillingPlan` (features, limits, channel entitlements), one `BillingSubscription` per account, `BillingEntitlementOverride` (audited per-account exception), `PlatformAppEntitlementGrant` (integration-included ceiling). `Billing::Entitlements` answers every question with a documented precedence; `Billing::ResourceLimit` owns both the counting query and **the gate** — `exceeded` reads the ceiling, takes `account.lock!`, then counts (verified, `resource_limit.rb:43-51`), with a docstring recording the race the lock fixes and an explicit warning that `reached?` is *not* a gate.

### How they compose today

For `agents`, and only `agents`: Store Syria writes `plans.lynomia_agent_seats`, pushes it as a `PlatformAppEntitlementGrant` with `external_ref='plan:{id}'`, and **never creates a `BillingSubscription`**. Lynomia resolves `override > max(plan, grant) > unlimited` — MAX, not sum — and honours a grant with no subscription at all. The refusal message follows the source, so an integration-funded merchant is told to ask their provider rather than to upgrade a Lynomia plan they do not hold (`resource_limit.rb:61-71`). That is a correct, tested instance of the intended architecture.

### The hard problems any Phase 5 design must satisfy

1. **The grant table is closed at four numeric resources.** `inclusion: { in: BillingPlan::LIMIT_KEYS }` (verified). A campaign or message ceiling is refused 422 today. **Constraint: no new ceiling can be pushed without an upstream `LIMIT_KEYS` change, and that change must ship its enforcement concern in the same commit** — adding the key alone makes the ceiling render as enforced everywhere while refusing nothing.
2. **The grant table cannot grant a feature or a channel.** It models a number. The only API-reachable feature grant routes through `Billing::OverrideGrant`, which inherits the lapsed-override trap. **Constraint: a time-boxed capability grant must either stop using the feature-flag write, or ship a sweeper.**
3. **There is no period-windowed meter anywhere.** Every Lynomia `COUNTS` lambda is current-state; Store Syria's one meter is a lifetime total with no period column and a decrement that does not floor at zero. **Constraint: a monthly send quota needs a new counting model and a decided period anchor, and it must be enforced where the send happens (Lynomia), because the scheduler fires without Store Syria.**
4. **Provenance is unrecorded and unread.** `billing_subscriptions` has no originator column, and `effectiveEntitlement()` — the one call that would tell Store Syria what Lynomia actually computed — has zero callers. **Constraint: the no-double-charge property is preserved by abstention, so any design that has Store Syria create or modify a Lynomia subscription breaks it immediately; and the composed answer must be read back before it is shown to a merchant.**
5. **Auto-trial fires on platform-provisioned accounts.** `after_create_commit :start_billing_trial` is unconditional on origin; when the trial lapses, `AccessGuard` returns 402 on **every** `/api/v1/accounts/:id/*` request — which includes the Phase 2 BFF's per-person calls — and AccessGuard knows nothing about `PlatformAppEntitlementGrant`, even though `Billing::Entitlements` deliberately honours a grant with no accessible subscription. **[Challenger: latent today because `Settings.enforced?` is false; live the moment an operator saves a trial plan or a Stripe key.]** **Constraint: the target installation's trial settings must be confirmed before grant-only provisioning is relied on, and the intended subscription state for a grant-funded merchant (no row, or an indefinite manual grant) must be decided — `AccessGuard` accepts only the latter.**
6. **The two Lynomia administrative authorities are duplicated, not shared.** `Billing::AdminActions` and `SuperAdmin::BillingSubscriptionsController` each implement `extend_trial`, `grant_plan`, `cancel` and `stripe_paid?` independently. They agree today line for line, which is exactly why a divergence will be silent. **Constraint: any Phase 5 commercial action must be written in both or one surface silently lacks it.**
7. **`refuse_narrowing_while_billed` is a model validation, enforced on the Platform API path too.** Store Syria cannot shrink a shared plan once merchants are on it; a downgrade means moving that account to a different plan or a per-account override. And `plans#create` has no permissible check, so any Platform App can write into the installation-wide catalogue, where the full catalogue (prices, limits, Stripe ids, subscriber counts) is readable by any valid token — as is `GET /platform/api/v1/billing/stats`, which returns installation-wide MRR with **no account scoping at all**, by documented design. **Constraint: Phase 5 should make an explicit decision about that endpoint rather than inherit it.**
8. **Store Syria cannot revoke anything mid-session**, and its downgrade path does not revoke modules at all (§3.17). **Constraint: every Phase 5 ceiling is unenforceable against a lapsed Store Syria subscriber until `SetLocate.php:79` and `User::assignPlan()`'s revocation target are addressed, or until enforcement lives entirely in Lynomia.**
9. **Grants have no foreign keys and no cleanup path.** No `add_foreign_key` on `platform_app_entitlement_grants`; `PlatformApp` has no association to them; `ceiling_for` never checks the granting app still exists or is still permissible. Deleting the app leaves its ceiling raising the account forever under MAX, withdrawable only from a Rails console. **Constraint: the one lever Store Syria holds over Lynomia enforcement has no revocation path for an orphan.**

---

## 7. Recommended increment order

Ordered so that the riskiest unknowns are resolved before anything is built on them, and so that nothing outbound ships before its brake does.

| # | Increment | Gating risk |
|---|---|---|
| **P5B.0** | **Environment and capability gate.** Stand up staging with `LYNOMIA_INTEGRATION_ENABLED`, base URL and platform token set; `whatsapp_campaign`, `lynomia_commerce` and `STORESYRIA_ENABLED` on; a `BillingPlan` row; and confirm `Billing::Settings.enforced?` and the trial settings. Confirm the WhatsApp provider is `whatsapp_cloud`. Prove the three dead client methods and one live campaign CRUD round trip. | **Everything below assumes paths that have never run here (§0.1). If `Settings.enforced?` turns out true with a trial plan, the auto-trial 402 locks the Phase 2 inbox before any Phase 5 code runs.** Do not start P5C until this is green. |
| **P5B** | **Audiences and segmentation.** Serve `audiences` from `references()` (repairs trap 3.8 — cheapest win in the phase). Audience CRUD via the existing `custom_filters` API. Preview via `audience_preview`, never `/contacts/filter`. Mirror the vocabulary from the Ruby constants. | **Store isolation (CG-3) is unmet.** Decide now: thread a store predicate upstream, or ship account-wide aggregates with explicit Arabic/English labelling. Shipping a store filter beside a spend filter without one of those two is the worst outcome in this phase. Also: the admin-only sharing guard is bypassable (3.9) and must be fixed before merchants reach it. |
| **P5C** | **Template approval visibility (read-only).** New BFF read of the manager API, projected. Sendability pre-flight reads the **snapshot**, not the manager row. | Administrator-only policy (§0.2) decides whether this is owner-only. **Do not build authoring or submission here** — the write path's token class is unverified (3, [OVERTURNED]) and the mirror overwrites same-identity drafts unaudited. |
| **P5E** | **Consent, suppression and the outbound brake — BEFORE any campaign can send.** Enforcement at the Lynomia campaign send path (CG-1), a consent entry in `SKIP_REASONS`, a `contacts.blocked?` check, a send throttle, and a kill switch checked **where the send happens**. | **This must precede P5D.** Building the campaign composer first means a working uncontrolled-send path exists for however long P5E takes. Also fix the merge-destroys-consent defect (3.15) or every enforcement below it is defeatable by an agent clicking Merge. |
| **P5D** | **Campaign management.** Create / schedule / delete for `one_off` on `whatsapp_cloud` only. Server-side provider gate. Validate the template reference at the request boundary. **Never forward `inbox_id` on an edit** (3.3). Carry both campaign ids in the projection (CG-6). | Traps 3.1, 3.3, 3.5, 3.10 are all live on this surface. Delete-as-cancel must carry an explicit data-loss warning (3.4). |
| **P5F** | **Analytics and delivery outcomes.** Proxy the per-campaign funnel and the account-wide family. Publish the unavailable-metric list as a first-class part of the UI. | The PK/display_id contract (CG-6) must be closed first or this 404s. Label `delivered` definitions and send-date bucketing honestly. Poll-only (CG-12). |
| **P5G** | **Commercial entitlements.** Surface feature availability and trial state from the already-fetched `/billing/entitlements` (CG-10) and wire `effectiveEntitlement()` (CG-9) — both are projection changes. **Descope or explicitly defer campaign usage ceilings, add-ons and feature-level trials** unless CG-7/CG-8 are accepted as upstream work. | §6 constraints 1–3. Shipping a visible-but-unenforced ceiling (the `LIMIT_KEYS`-without-a-concern failure) would be the worst possible outcome here. |
| **P5H** | **Super Admin tabs, adversarial review, browser verification, report.** Add tabs to the existing page; close the UI-less commerce-credential routes rather than adding more. | Browser verification must cover Arabic/RTL at 390×844 for every new surface, following the existing harness shape. The leak assertions must be extended to recipient phone numbers and rendered message bodies — the drilldown endpoint is the most sensitive surface in the phase. |

---

## 8. What must NOT be built

1. **A second campaign scheduler.** `TriggerScheduledItemsJob` + `Campaigns::TriggerOneoffCampaignJob` already own this, including the duplicate-send guard under a row lock (`mark_processing!`, `app/models/campaign.rb:146-157`).
2. **A second audience engine.** `Audience::CommerceCondition` and `Audience::ConversationCondition` over `Contacts::FilterService` are the segmentation engine. They are SQL-injection-safe, account-pinned and bounded (MAX_CONDITIONS 10, MAX_VALUES 50).
3. **A stored audience membership table.** Membership is deliberately dynamic and resolved at read time (`custom/app/models/custom/custom_filter.rb:13-16`). A materialised copy would diverge from what the campaign sends to.
4. **A Store Syria copy of the recipient funnel.** `CampaignRecipient#record_outcome!` is forward-only under a row lock and idempotent against Meta's out-of-order and redelivered reports. Project it; do not mirror it. (But also do not treat Lynomia as the system of record for completed campaigns — see 3.4.)
5. **A Store Syria delivery-report ingester.** `Whatsapp::DeliveryReport` already routes Meta's `statuses[]` scoped to the webhook's inbox.
6. **A client-side template approval filter.** Three independent expressions of "which templates may be offered" already exist (3.16). Add a server-side rule; do not add a fourth client copy.
7. **A second quota primitive.** `Billing::ResourceLimit.exceeded` is the locked gate, with its race documented. Any Phase 5 quota that calls `reached?` instead is walkable by two concurrent requests. Equally: **do not copy Store Syria's inline `count() < max` pattern** — it is racy by the module's own migration comment, and a racy ceiling on outbound sends is an uncontrolled-messaging hazard, not an over-count.
8. **A second entitlement composition.** `override > max(plan, grant) > unlimited` with MAX-not-sum is the anti-double-charge property. A Phase 5 ceiling enforced in Store Syria instead would be a second, disagreeing switch.
9. **A Store Syria consent decision function.** `Commerce::MessagingEligibility` is correct. The gap is that campaigns never call it — fix the caller, do not write a second policy that will drift.
10. **A new Super Admin menu entry.** The convention, stated at `IntegrationController.php:28-34`, is to add a tab to the one existing page.
11. **A second analytics envelope.** `Analytics::Result` + `MetricFamily` already carry `kind`, `meta.notes`, `source_reason` and honest nil-vs-zero rates. Route any new filter through `MetricFamily::ALL_FILTERS` + `FilterSet`.
12. **Anything on `add_on_managers`.** It is the platform module registry, not an add-on catalogue, and its two "session" routes bind to methods that do not exist.

---

## Appendix — where the readers were wrong

Recorded because a matrix that hides its uncertainty is worse than one that shows it. Each of these changed a verdict or an evidence line.

| Claim as reported | Status | Correction |
|---|---|---|
| "Submission to Meta exists and works; objective 4 is not blocked" | **OVERTURNED** | `template_access_token`'s management-token branch is unreachable (`enterprise?` hardcoded false); by the code's own header every write sends the wrong token class. Specs pass against WebMock. |
| "Approval status arrives within seconds via webhook" | **OVERTURNED** | The webhook is unsubscribable in this installation — 401 on Meta's handshake; the verify token is not settable from the WhatsApp settings page. |
| "Mirror can never overwrite a local draft" | **OVERTURNED** | Probed: a same-identity Meta template takes the draft over wholesale, inside `without_auditing`. |
| "`campaigns` and `whatsapp_campaign` are both working commercial levers" | **OVERTURNED** | `campaigns` has no server-side gate on the dashboard API; an excluded account can still create and send SMS campaigns. |
| "Audience sharing is administrator-only" | **OVERTURNED** | String guard vs ActiveRecord boolean cast; `shared: 1`, `"yes"`, `"on"`, `"t"` all persist as shared from an agent. The un-share in-use guard has the identical defect. |
| "Precedence system > override > plan > default is implemented in `allowed?`" | **CORRECTED** | `allowed?` is system → override → `feature_enabled?`; it never reads `plan.features`. Only `source` has the plan rung, and the two disagree. |
| "A lapsed feature override leaves `source` at `:plan`" | **CORRECTED** | `:default` in the normal case. Trap upheld. |
| "`Campaign` declares no `has_many :campaign_recipients`" | **CORRECTED** | It does, at `custom/app/models/custom/campaign.rb:14`, with `dependent: nil` deliberately. Conclusion upheld. |
| "`RecipientSnapshot` refuses to overwrite a stored code with nil" | **OVERTURNED** | The opposite: `update_only` blanks every unset outcome column. The cited protection is in a different file. |
| "WhatsApp account-wide analytics covers campaign sends" | **OVERTURNED** | Campaign sends create no Message; structurally uncountable there, while the class header claims otherwise. |
| "`conversations.campaign_id` is read by nothing" | **CORRECTED** | It is a supported conversation filter key; accurate statement is "no file under `custom/app/services/analytics/` reads it." |
| "MessagingEligibility has two production callers" | **CORRECTED** | Three (verified) — the consent controller preview is the third. Verdict unchanged. |
| Campaign spec count "170 examples" | **CORRECTED** | Two cited spec files do not exist; the real list gives 183, 0 failures. `Campaigns::RecipientMetrics` — the whole per-campaign analytics implementation — has **no dedicated unit spec**. |
| Analytics spec per-file counts | **CORRECTED** | Six of eight inflated (one by 4×); totals arithmetically right, so the breakdown was reconstructed not observed. The 4-file command gave **1 failure** on a non-pristine database. |
| "Consent listener spec covers opt-out" | **CORRECTED** | 2 of 8 examples fail deterministically on this checkout, from committed orphan rows and global-count assertions. |
| "~25 gateway controllers route money to entitlement" | **CORRECTED** | 27 providers are hard-gated off by `GuardsPlanActivation` with no env keys set; ~12 remain live. |
| "The seat allowance wire works" | **CORRECTED** | Local write and audit work; the push throws `KIND_DISABLED` and is counted as a failure. The 18 passing tests bind a fake client. |
| "Trial expiry is enforced" (Store Syria) | **CORRECTED** | The plan pointer moves; `user_active_modules` is untouched when the new plan's `modules` is empty — an expired trial keeps LynomiaChat. |
| "A privileged platform token never serves a merchant request" | **CORRECTED** | Three `ConnectionController` routes plus first-time identity creation use it. |
| "Ten controllers / 11 controllers" | **CORRECTED** | 9 controller classes plus 1 trait; `CommerceEventObserver` and the module `EventServiceProvider` were omitted from the inventory. |
| "No suppression list as a collection" | **CORRECTED** | `state=suppressed` is accepted and served; the real limits are a hard 100-row cap with no pagination and a UI that never sends a filter. |