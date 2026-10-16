# PHASE 7 — PLATFORM ISOLATION AND REAL-MESSAGE AUTHORIZATION

**A review, performed before any staging authorization. Nothing was executed against a live
system; every claim is traced from a real caller to the code that answers it, with file:line.**

Two boundaries, because Phase 7 is the first time either is exercised with real credentials and
(gated) real messages.

| | |
|---|---|
| Boundary 1 | platform account isolation — can a merchant reach, forge into, or be affected by the installation's own workspace |
| Boundary 2 | real-message authorization — what stands between code and a real WhatsApp message, on every path |
| Verdict | Boundary 1 **holds**. Boundary 2 has **two confirmed gaps**, recorded as B7 and B8, neither fixed here |

---

## Boundary 1 — platform account isolation

### 1.1 A merchant cannot address the platform workspace

Every merchant-facing route in the module takes **no account identifier**. The workspace a
merchant reaches is resolved from `lynomia_store_links` by `EnsureStoreOwnsLink`, which matches
on `store_id` **and** `owner_user_id` — so there is no parameter to tamper with and no IDOR
surface. The platform workspace has no link row, so no merchant route can resolve to it.

Upstream reinforces this independently: `Platform::Api::V1::AccountsController#index` rejects
platform-owned accounts from the listing, so the provisioning path that adopts an existing
workspace by marker cannot stumble on it.

**Holds.**

### 1.2 The two signing secrets cannot collide

| Feed | Secret |
|---|---|
| per store | `HMAC(master, "lynomia-webhook:{store_id}")` |
| platform | `HMAC(master, "lynomia-webhook:platform")` |

A store id is numeric and `platform` is not, so no store can produce the platform scope. This
is pinned by `test_a_store_secret_cannot_sign_a_platform_delivery` and **red-proofed**: setting
`PLATFORM_SCOPE` to `'11'` makes it collide with store 11 and the test fails.

An unexpected middleware scope string falls through to the store branch, where
`$request->route('store')` is absent on the platform route and the signature check fails; and
`PlatformEventIngressController` re-asserts `VERIFIED_PLATFORM` independently, so a routing
change that dropped the guard fails closed with a 401 rather than accepting anything.

**Holds.**

### 1.3 Neither feed can write the other's data

`PlatformDeliveryProjector` touches `lynomia_platform_notices` and `lynomia_platform_events`
only, and finds a notice by `lynomia_message_id` — an id Lynomia assigned when *this
installation* sent a notice. `EventIngressController` records every delivery in
`lynomia_inbox_events` and **acts** only on `consent_changed`; a `message_delivery_status`
event posted at a store's ingress is recorded and ignored.

**Holds.**

### 1.4 No operator surface leaks another tenant's people

The Super Admin surfaces report counts, never names, and the browser harness asserts both the
"counts only — never the people" copy and that no phone number appears on the page
(`tests/Browser/p6-operator-console.mjs`).

**Holds.**

---

## Boundary 2 — real-message authorization

### 2.1 Every path that can reach a WhatsApp provider

| # | Path | Gated by | Order |
|---|---|---|---|
| 1 | Agent composer / compose-new | `Custom::Whatsapp::SendOnWhatsappService` | category → consent → allowance |
| 2 | Flow `send_template` node | `Flows::Nodes::SendTemplate` refuses first, then path 1 | eligibility → path 1 |
| 3 | Automation rule template action | `Custom::AutomationRules::TemplateAction` refuses first, then path 1 | eligibility → path 1 |
| 4 | Flow `say` (writes the message directly) | path 1 — which is **why** the gate lives on the send service and not on `Messages::MessageBuilder`, since `Flows::Run#say` calls `conversation.messages.create!` and routes around the builder | category → consent → allowance |
| 5 | Campaigns | `Custom::Whatsapp::OneoffCampaignService` → `Campaigns::SendGuard.clear_to_send` | guard → provider |
| 6 | Store Syria platform notices | `PlatformRecipients` (link state, provisioning, ownership proof, store ownership, `MerchantContact`), then `PlatformWorkspaceGateway::assertPlatformOwned`, then path 1's UTILITY gate | recipient → workspace ownership → category → consent → allowance |
| 7 | B4 contact verification code | path 6's machinery, platform credential only | as above |
| 8 | **CSAT survey, WhatsApp template branch** | **nothing** | — |
| 9 | **CSAT survey, Twilio WhatsApp branch** | **nothing** | — |
| 10 | CSAT survey, message branch | path 1 | category → consent → allowance |

**The order in paths 1 and 6 is load-bearing and deliberate.** Category first, because
`consent_purpose` answers nil for a promotional template in an account with no commerce
feature and the platform workspace has no commerce store — consent alone would let MARKETING
through to anybody who had not explicitly refused. Allowance **last**, because
`Billing::MeteredUsage.consume` increments: running it before consent would spend a unit of the
period on a message that was then refused and never handed to a provider.

### 2.2 B7 — CSAT reaches the provider with no eligibility check at all

**Confirmed, reachable, and not fixed here.**

`CsatSurveyListener` fires `CsatSurveyService#perform` when a conversation is resolved and the
inbox has CSAT enabled. It branches three ways, and two of them call the provider directly:

```
app/services/csat_survey_service.rb:115
  message_id = inbox.channel.provider_service.send_template(phone_number, template_info, message)
```

`should_send_csat_survey?` checks that the conversation is resolved, that CSAT is enabled, that
one was not already sent, and that survey label rules allow it. **It does not ask whether the
contact is blocked, whether they withdrew, or whether the provider suppressed their number.**

The asymmetry is sharp: **the same CSAT survey is consent-checked when it goes out as a
message (branch 3, through path 1) and unchecked when it goes out as a template.** The
difference is only which branch the inbox's configuration takes.

**A realistic path, end to end:** a shopper replies STOP on WhatsApp →
`Commerce::ConsentListener` is registered unconditionally and writes a `withdrawn` row → an
agent resolves their conversation → a CSAT template is sent to them over WhatsApp.

The same holds for a contact `blocked?` in Lynomia and for a number the **provider** has
suppressed — and suppression is the one the project's own eligibility module calls out as not
anyone's decision to make: *"the provider says the number cannot receive, so sending is not a
decision anyone gets to make."*

**The fix, not applied.** `Commerce::MessagingEligibility.refusal(contact, nil)` is already
exactly the right rule for CSAT: nil purpose means "only a decision somebody actually made can
block", so it demands no marketing grant — correct for a service message — while a withdrawal,
a suppression or a blocked contact stops it. The established pattern for closing precisely this
class of gap on this fork is a `custom/` prepend, which is how the composer gap (C15) was
closed. `CsatSurveyService` carries no `prepend_mod_with`, so an extension point has to be
added to the OSS file first.

**Why it is recorded rather than fixed.** CSAT is core Chatwoot behaviour for every account on
the fork, not something this integration added, and the owner asked for a review of this
boundary rather than a change to it. Changing core messaging behaviour unilaterally, on the eve
of a gated phase, is the owner's call.

**Mitigation for Phase 7, which costs nothing:** do not enable CSAT on the staging inbox.
`PHASE-7-RUNBOOK-TEST.md` should carry that as a precondition until B7 is resolved.

### 2.3 B8 — a merchant can read their own webhook secret, and forge events for their own store

**Confirmed, and tenant-isolated — but it weakens what the consent record is evidence of.**

```
app/views/api/v1/accounts/webhooks/_webhook.json.jbuilder:6
  json.secret webhook.secret
```

A merchant tenant owner is provisioned into their Lynomia workspace as an **administrator**
(`IdentityProvisioner`: *"Owner means administrator, everyone else means agent"*). An
administrator can `GET /api/v1/accounts/{id}/webhooks` and read the signing secret Store Syria
derived for their store.

With it they can sign a payload that `POST /lynomia/events/{their_store}` accepts as genuine.
`ConsentStateProjector` writes whatever `state` the payload carries, subject only to the
revision watermark — so a forged `consent_changed` with `state: granted` at a high revision
records a grant the shopper never gave, and promotional sends to that shopper are then
permitted.

**What it is not.** It is not a cross-tenant breach. Each store's secret is derived
independently, so a merchant learns their own and nobody else's — the property
`EventSignature`'s own documentation claims ("one leaked secret forges deliveries for one
store, not all of them") holds exactly as written.

**What it is.** The platform's consent record cannot be treated as *independent* evidence of a
shopper's decision where the merchant is motivated to manufacture one — and the platform
permits sends on the strength of that record. The merchant is the party legally responsible for
consent, which bounds the harm, but it is worth the owner knowing that the record is
merchant-attested rather than platform-attested.

**Options, none applied:** stop exposing `secret` on the account webhooks serializer (an OSS
change affecting every account and every legitimate integration that reads it); or treat
`origin: lynomia_native` **grants** arriving on the store feed as merchant-attested and record
them as such, while continuing to honour withdrawals from the same source unconditionally — a
withdrawal a merchant forges only costs the merchant.

### 2.4 Can a sweep send without a human in the loop?

Yes, and by design — but only on the paths that are fully gated.

| Command | Can send? |
|---|---|
| `lynomia:deliver-commerce-events` | no — it delivers events to Lynomia |
| `lynomia:deliver-consent-decisions` | no |
| `lynomia:reconcile-events` | no — reads |
| `lynomia:reconcile-consents` | no — reads, and is deliberately read-only toward Lynomia |
| `lynomia:reconcile-deliveries` | no — reads, plus one webhook registration |

**No scheduled command in this module sends a message.** Automated sends originate from an
automation rule, a flow, a campaign schedule, or an operational notice occasioned by a
lifecycle event — each on a gated path, each with `LYNOMIA_INTEGRATION_ENABLED` in front of it.

---

## What this review changes for Phase 7

1. **Boundary 1 needs no further work before staging.** Its properties are pinned by tests and
   red-proofed.
2. **B7 must be decided before any inbox in staging has CSAT enabled.** The zero-cost
   mitigation is to leave it off; the runbook now says so.
3. **B8 is informational for staging** — synthetic merchants will not forge consent — but it
   belongs in front of the owner before production, because it bears on what the consent record
   means.
4. Neither finding blocks the deployment steps. Both belong on
   `PHASE-7-OWNER-CHECKLIST.md` as decisions, not as tasks for this phase.
