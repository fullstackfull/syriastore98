# MKT-008 / GAP-200 — marketing consent enforcement: design, and the honest state of it (owner decision D8)

> **NOT COMPLETE, AND NOT WIRED.** What exists is a first-party consent store and a send gate that
> **nothing calls**, with enforcement **off** and its tables **migrated nowhere**. **GAP-200 stays open.**
> Per the owner's direction, consent is not to be reported complete while any promotional path can bypass
> enforcement, and today every one of them can. Nothing here is a production activation, a production
> migration, or a statement about what production sends.

Companion documents: the evidence is [`PHASE-05-MKT-008-SENDER-CENSUS-2026-11-17.md`](PHASE-05-MKT-008-SENDER-CENSUS-2026-11-17.md);
the decision framing is [`PHASE-05-OWNER-DECISION-PACKAGE.md`](PHASE-05-OWNER-DECISION-PACKAGE.md) §3; the gap is
`GAP-200` in [`../MASTER-PLAN-GAP-REGISTER.md`](../MASTER-PLAN-GAP-REGISTER.md).

## 1. What D8 requires

From the owner's decision, in the owner's order of emphasis:

1. A **first-party, persistent, tenant-aware** consent and suppression store with **append-only evidence** and
   a **secure unsubscribe** path. No edit to `packages/workdo/`.
2. **Verify schemas and sender paths first.** Production schema migration and activation are **separate
   authorisations**.
3. **Per-channel enforcement.** Laravel's `MessageSending` event covers outbound **mail only**; SMS, WhatsApp and
   FCM never touch it.
4. **Never infer consent** from orders, contacts, opens or an existing newsletter row. **No backfill.**
5. **Do not block necessary transactional notifications indiscriminately.**
6. **Classify and audit the unreviewed sender sites.** Do not mark consent complete while any promotional path
   can bypass enforcement.

## 2. State today

| Piece | Where | State |
|---|---|---|
| Consent events — the evidence, **append-only** | `marketing_consent_events`; `MarketingConsentEvent` on `AppendOnlyEloquentBuilder`, which throws on `update`, `upsert`, `delete`, `forceDelete` and `increment` | built; **migration applied nowhere** |
| Contact state — a rebuildable projection | `marketing_contact_states`; unique on `(store_id, channel, purpose, address_hash)`; absence of a row means `none`, which is **not** consent | built; migration applied nowhere |
| Unsubscribe tokens | `marketing_unsubscribe_tokens`; `UnsubscribeTokens` issues a random 256-bit token, **stores only its SHA-256**, single use | built; **no route exists** to redeem one |
| Ledger | `ConsentLedger`: `grant`, `withdraw`, `suppress`, `liftSuppression`, `withdrawByHash`, `stateOf`, `rebuildState` | built |
| Send gate | `MarketingSendGate::decide(storeId, Channel, MessageClass, address)` → `SendDecision` | built; **called by nothing** (grep of `app/`, `routes/`, `bootstrap/`, `config/`, `resources/`, `packages/`) |
| Switch | `config/marketing_consent.php`, `MARKETING_CONSENT_ENFORCEMENT`, default **`off`** | inert |
| Tests | `tests/Unit/Marketing`, `tests/Feature/Marketing` — **99 tests / 322 assertions**, mutation-checked | passing locally; CI result is the pull request's, not this file's |

**Not done:** the seams at the send boundaries (§6), a place for a person to opt in, the unsubscribe route and
page, the classification of the sender sites beyond the census's forty (§7), and every production step (§9).

## 3. The data model, and why it is shaped this way

* **Keyed on a hash, not an address.** `address_hash = SHA-256("store|channel|normalised address")`. The raw
  address is never a key and never logged; a **masked hint** (`j***@example.com`; the last four characters of a
  phone number) exists for a human reading the evidence. The store is part of the hash, so the same person at
  two stores is two rows — consent is **per store**, not per platform.
* **Normalisation per channel** (`ContactAddress::normalise`): an email is trimmed, lower-cased and must pass
  `FILTER_VALIDATE_EMAIL`; a phone number (SMS, WhatsApp) is reduced to its digits, a leading `00` dropped, and
  must be 7–15 digits; a push token is kept as it is at 16–4096 characters. An unusable address is *held* when
  marketing, never guessed.
* **A known limit, in the code and repeated here.** Phone numbers are compared as the digits the merchant
  stored, so `0912345678` and `963912345678` are two keys; making them one needs the customer's country, which
  this repository does not reliably hold. For a *missing* grant that fails safe (the send is held). It is **not**
  safe in one case: a person who granted under one spelling and was withdrawn by a merchant under another is
  still `granted` to the sender. An unsubscribe **token** does not have that problem, because it is issued for
  the exact normalised address the message went to and so withdraws the key the next send will check.
* **State machine** (`ConsentState::after`): `none` → `granted` / `withdrawn` / `suppressed`.
  **`suppressed` outranks everything** except an explicit lift, and a lift returns the person to **`withdrawn`,
  not `granted`** — a checkbox ticked at checkout therefore cannot undo a complaint. Only `granted` permits
  marketing.
* **Append-only.** Evidence rows are written and never changed; the projection is derived, and
  `rebuildState()` recomputes it from the log so the two can be compared.
* **No foreign keys** on these tables, which is this repository's house precedent for ledger tables (a cascade
  must never be able to erase evidence). **Not `Cachable`, not tenant-scoped**: every method takes an explicit
  `store_id`, and the classification is pinned in `TenantModelClassificationTest`.
* **Unsubscribe tokens are random, not signed.** `APP_KEY` is in git history and unrotated, so a signed URL is
  forgeable by anyone who has read the repository. A random token whose hash alone is stored cannot be derived
  from anything public.

## 4. The three message classes — there is deliberately no default

| Class | Meaning | Blocked by consent? |
|---|---|---|
| `Transactional` | necessary to deliver what the person asked for — an order confirmation, a receipt, a one-time password, a password reset | **never** |
| `Operational` | about the merchant's own store, sent to the merchant or staff — stock alerts, new-order notices, a delivery assignment | **never** |
| `Marketing` | anything whose purpose is to promote, remind, win back or cross-sell | **yes** — only an explicit, current `granted` passes |

**A call site must say which it is.** A helper that assumed "transactional" would let every unclassified sender
bypass enforcement; one that assumed "marketing" would silently stop receipts. The type system refuses to make
the guess, and the census exists to make every site choose.

**The census corrected the module names**, which proved unreliable: ten of the forty classifications were
refuted by the adversarial pass, and the first pass **over-reported** promotional sending — a back-in-stock push
sent only to a person who subscribed to that product, and deleted on sending, is not a campaign.

## 5. The gate — one decision function

Applied in this order (`MarketingSendGate::decide`):

1. **Mode `off`** (the default) → `allow`, **and the database is not touched.** Production has no consent
   tables; an `off` gate must be inert there, not an outage.
2. **Not marketing** → `allow`.
3. **Marketing** with no usable store, no usable address, or no current `granted` → `hold`
   (`no_store`, `address_unusable`, `no_consent_recorded`, `withdrawn`, `suppressed`).

Modes: `off` · `report` (compute, **log what would be held, send anyway**) · `enforce`. **An unrecognised value
is `report`** — a typo must neither disable the control nor stop a merchant's mail, and `report` is visible.
Report mode logs a **12-character hash prefix, never an address**. Nothing in the gate infers consent: "has
ordered" and "is a contact" are not inputs.

## 6. Where the gate has to be called — the seams, per channel

Measured by the census and the owner decision package §3.4; **none is wired**. All are first-party.

| Channel | Seam | Notes |
|---|---|---|
| **Email** | an `Illuminate\Mail\Events\MessageSending` listener, `app/Providers/EventServiceProvider.php`; returning `false` aborts before the transport | **Measured**: booting this repository's own `bootstrap/app.php` with a refusing listener cancelled the send, **including for vendored-module Mailables**. `Mailer.php:585` is the only `transport->send` in `Illuminate\Mail`. The listener **cannot tell a receipt from a coupon**, so it needs the class from the sender (a marker on the mailable); an unmarked message must be treated as a defect to classify, not defaulted. |
| **Email, templates** | `Utility::sendEmailTemplate()`, `app/Models/Utility.php:2515` | the narrower seam for the template family; it knows the template, so it can classify; it is bypassed by any module that calls the mailer directly, which is why the listener is the backstop |
| **WhatsApp** | `Utility::SendMsgs()`, `app/Models/Utility.php:2648` | the single chokepoint for every WhatsApp send |
| **SMS** | `Utility::send_twilio_msg()`, `:3460` (stock alerts — operational); `OtpTransport::post()` (one-time passwords — transactional) | the sites found so far are operational or transactional; **no marketing SMS has been located, and that is not the same as none** |
| **Push** | `Utility::sendFCM()`, `:3576`; `FirebaseService::sendToToken` | first-party throughout |

**Rejected on evidence:** `NotificationSending` (fires for **two** senders in the repository, both
transactional — the wrong instrument) and a **custom mail transport** or decorated `MailManager` (the transport
driver name is **tenant-supplied runtime data**, so a transport-keyed interception has a silent-miss mode).

## 7. The sender sites — what has and has not been audited

The census found **214** send sites. **Forty were classified in depth and ten of those were refuted**; the other
**174 were enumerated, not analysed**, and "not analysed" must never be read as "safe". A further classification
run was in progress when this was written and **nothing from it is relied on here**: its results are not in this
repository until they are committed with their evidence.

Until every site has a class **and** a verified seam, the statement GAP-200 can support is exactly this: *the
code has no consent control and no unsubscribe, a control now exists unwired, and the live blast radius is a
question about the production database and module configuration.* The known promotional senders with no consent
predicate are `CouponEmail` (on registration, first order, and an order-value threshold), `ReviewReminder`, the
newsletter list and the abandoned-cart dispatch (`AbandonedMessageDispatch`); whether any of them fires in
production depends on which modules are enabled and which stores have mail, WhatsApp and SMS credentials
configured, **none of which is visible from the engineering environment**.

## 8. What the rollout would look like — every step after the first needs your authorisation

1. **Done, locally:** the store, ledger, gate and tokens, with enforcement `off`.
2. **Wire the seams in `report` mode** in a development environment, class by class, adding a census test per
   seam that fails when a sender in that family is added without a class. *No behaviour changes in production
   while the switch is `off`.*
3. **A place to opt in**, and the unsubscribe route and page — the only user-visible parts, bilingual
   (Arabic RTL / English LTR) and checked in both directions.
4. **Authorised**: apply the three migrations to the target environment. They create three new tables and alter
   nothing that exists, and **unlike GATE F's promotion migrations they are not a no-op against production**:
   they are exactly the persistent migration the owner reserved.
5. **Authorised**: set `report` in production and read the log. This is how an owner learns **which sends would
   be held before any is held** — and it will be a large number, because **every existing customer is `none`**:
   consent is never inferred and nothing is backfilled, so turning `enforce` on stops every marketing send to
   every existing contact until it is collected. That is the intended consequence of D8 and a business decision
   in its own right.
6. **Authorised**: `enforce`, store by store.

## 9. What this document does not claim

* It does not say any promotional path is blocked. None is.
* It does not say production is exposed, or that it is not (owner decision package §3.6).
* It does not cover the **`lynomia_consent_states`** mechanism, which is enforced *upstream*, outside this
  repository, and is keyed on a subject id with no address column — a good design template and not usable as
  the record the send boundary needs.
* It does not close `P1-SEC-40` / issue #120, a different matter that remains open.
* The production column sets of `customers`, `newsletters` and `users` have **never been measured**
  (`GATE-F-VERIFIED-SCHEMA.md` covers fifteen other tables), which is one more reason the store is its own
  tables rather than columns on those.
