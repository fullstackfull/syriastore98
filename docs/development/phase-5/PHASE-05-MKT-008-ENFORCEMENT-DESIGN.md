# MKT-008 / GAP-200 — marketing consent enforcement: design, and the honest state of it (owner decision D8)

> **NOT COMPLETE, AND WIRED INTO ONE PLACE ONLY.** What exists is a first-party consent store, a send gate, and the **email seam** (a
> `MessageSending` listener) that calls it — **inert while enforcement is `off`**, with the consent tables **migrated nowhere**.
> SMS, WhatsApp and push have no seam. **GAP-200 stays open.** Per the owner's direction, consent is not to be reported complete
> while any promotional path can bypass enforcement, and today every path but a classified email one can. Nothing here is a production activation, a production
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
| Consent events — the evidence, **append-only** in three layers | `marketing_consent_events`; `MarketingConsentEvent`. An instance cannot be saved or deleted (model events); the Eloquent builder refuses the mass statements by name; and the **query builder underneath it** refuses `update`, `updateFrom`, `upsert`, `delete` and `truncate`, which is where `touch()`, `updateOrInsert()`, `incrementEach()`, `decrementEach()` and `toBase()->update()` all end. Inserts are untouched | built; **migration applied nowhere**. `DB::table()` and anyone with database access are **not** covered: that needs a trigger or restricted grants, a production decision (§10) |
| Contact state — a rebuildable projection | `marketing_contact_states`; unique on `(store_id, channel, purpose, address_hash)`; the absence of a row, or a row reading `none`, means `none`, which is **not** consent | built; migration applied nowhere |
| Unsubscribe tokens | `marketing_unsubscribe_tokens`; `UnsubscribeTokens` issues a random 256-bit token, **stores only its SHA-256**. **Reusable by design** — an unsubscribe link that stops working is a failure to honour an unsubscribe — so a replay withdraws again, `used_at` records the first use only, and a token never expires. The store the page is served under is a **required** argument | built; **no route exists** to redeem one |
| Ledger | `ConsentLedger`: `grant`, `withdraw`, `suppress`, `liftSuppression`, `withdrawByHash`, `stateOf`, `rebuildState`, and the read-only audit `divergences(storeId)` that compares every projection with a replay of its log | built |
| Send gate | `MarketingSendGate::decide(storeId, Channel, MessageClass, address)` → `SendDecision`. If the store **cannot be read**: `report` allows and says so, `enforce` holds. The decision is made first and its log lines are written inside a guard | built; **called from exactly one file, the email seam** (a census names it) |
| **Email seam** | `App\Services\Marketing\EnforceMarketingConsentOnMail`, registered once on `MessageSending`; classifies the sender from the mail event (`MailSenderClassifier`) against `MailSenderRegistry` (12 Mailables, 2 Notifications, 19 template names) | built; **inert while the mode is `off`** (one configuration read per email, nothing else). §6.2 says what it does and does not do |
| Switch | `config/marketing_consent.php`, `MARKETING_CONSENT_ENFORCEMENT`, default **`off`**. The spellings that can only mean "not on" (empty, `false`, `0`, `no`, `disabled`, `none`, `null`) are `off`; any other unrecognised value is `report`. **`enforce` is read as `report` for SMS and WhatsApp** while `phone_identity_canonical` is `false` | inert |
| Tests | `tests/Unit/Marketing`, `tests/Feature/Marketing` plus the classification pins in `TenantModelClassificationTest` — counts in §10 | passing locally; CI result is the pull request's, not this file's |
| Concurrency evidence | `tests/Support/Security/bin/verify-consent-ledger-concurrency-on-mysql.php`: real processes, the real ledger, MariaDB 10.11 at REPEATABLE READ | 17 checks, 0 failed; registered in `run-mysql-verifications.sh`. **Nothing in CI runs it** (CI is SQLite-only) |

**Not done:** the SMS, WhatsApp and push seams (§6; the EMAIL seam is built, §6.2), a place for a person to opt in, the unsubscribe route and
page, the classification of the sender sites beyond the census's forty (§7), and every production step (§9).

## 3. The data model, and why it is shaped this way

* **Keyed on a hash, not an address.** `address_hash = SHA-256("store|channel|normalised address")`. The raw
  address is never a key and never logged; a **masked hint** (`j***@example.com`; the last four characters of a
  phone number) exists for a human reading the evidence. The store is part of the hash, so the same person at
  two stores is two rows — consent is **per store**, not per platform. **A hash is a pseudonym, not anonymity**
  (§10, T7): an email is a dictionary attack and a phone number is about 10⁸ candidates, so offline the hash plus the
  last-four hint recovers it. Treat the three tables as personal data under the same protection as `customers.mobile`.
* **Normalisation per channel** (`ContactAddress::normalise`): an email is **validated as given and then lower-cased**
  (the other order let a non-ASCII character that lower-cases to ASCII — U+212A, the Kelvin sign — share an ASCII
  address's key) and must pass `FILTER_VALIDATE_EMAIL`; a phone number (SMS, WhatsApp) is reduced to its digits —
  **Arabic-Indic, Persian and full-width digits count as digits** (the first version's byte-wise `\D` deleted them) — a
  leading `00` dropped, and must be 7–15 digits; a push token is kept as it is at 16–4096 characters. An unusable address
  is *held* when marketing, never guessed. The recipe is pinned by known answers in `ContactAddressTest`, because
  **changing `normalise()` or `hash()` silently re-keys every stored decision**: old withdrawals become unreachable and
  a fresh opt-in reads `granted`.
* **Which spellings converge** (derived by hand, then pinned where they matter). Phone: `+963 944 123 456`, `00963944123456`,
  `963944123456`, `(963) 944-123 456`, any punctuation or invisible bidi mark, and the same number written with Arabic-Indic
  digits all become `963944123456`. They do **not** converge: `0944123456`, `944123456`, `+963 0944 123 456`
  (→ `9630944123456`) and `011 963 944 123 456` (→ `011963944123456`). Email: case and surrounding whitespace converge;
  plus-addressing (`a+x@`), a quoted local part (`"a"@`) and a trailing-dot domain do not.
* **A known limit that is NOT safe in one direction — and an earlier version of this paragraph said otherwise.** Phone numbers
  are compared as the digits the merchant stored, so `0912345678` and `963912345678` are two keys; making them one needs the
  customer's country, which this repository does not reliably hold, and no phone-number library is installed. The two
  directions differ:
  * a missing **grant** under the other spelling reads as "no consent" and the send is **held** — the safe direction;
  * a **withdrawal or suppression** recorded under one spelling (a provider's STOP webhook arrives in international form, a merchant
    types the local form) is **invisible** to a send addressed under the other, so a person who opted out is still `granted`;
  * two **different people** whose country-less numbers share national digits (a Gulf store: `5012 3456` is any Gulf number with
    those eight digits) share a key, so one person's grant counts for the other.

  **Report mode cannot show either failure**, because it logs only would-hold cases. So `enforce` is **refused for the SMS and
  WhatsApp channels** (read as `report`, and the gate's decision says so) until phone identity is canonical, which is an owner
  decision (§10). An unsubscribe **token** does not have this problem for the next message, because it is issued for the exact
  normalised address the message went to — but it withdraws only that spelling.
* **State machine** (`ConsentState::after`): `none` → `granted` / `withdrawn` / `suppressed`.
  **`suppressed` outranks everything** except an explicit lift, and a lift returns the person to **`withdrawn`,
  not `granted`** — a checkbox ticked at checkout therefore cannot undo a complaint. Only `granted` permits
  marketing, and the gate asks `ConsentState::permitsMarketing()` rather than restating the rule. All sixteen
  state × event cells were checked against D8 by the review.
* **Append-only, and exactly how far that goes.** Evidence rows are written and never changed; the projection is derived, and
  `rebuildState()` recomputes **one contact** from the log (it needs the raw address, so it cannot discover contacts) while
  `divergences(storeId)` compares **every** projection with a replay of the whole store's log. Neither repairs anything: a repair
  is a decision about evidence and belongs to a person.
* **The order of the two writes is the correctness argument.** An event gets its id at `INSERT`, a replay folds in id order, so the
  contact's projection row is **locked first**, the event is inserted under the lock, and the fold happens before it is released.
  The first version inserted first and locked second; two overlapping requests were then folded in *lock* order, and a withdrawal
  could be lost in the row the gate reads (§10, F1). The row is **created before it is read for update** (an upsert), because a
  locking read of an *absent* key takes a gap lock at REPEATABLE READ and writers for even **different** contacts then deadlock (§10,
  F2). A seam must call the ledger **outside** any transaction that can fail afterwards: inside one, a deadlock is not retried and a
  withdrawal rolls back with its caller.
* **No foreign keys** on these tables, which is this repository's house precedent for ledger tables (a cascade
  must never be able to erase evidence). **Not `Cachable`, not tenant-scoped**: every method takes an explicit
  `store_id`, and the classification is now pinned **by name** in `TenantModelClassificationTest` (not in the allow-list, not carrying
  the trait, not `Cachable`) — before the review it was pinned by a *count*, which does not notice a model gaining the trait.
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
4. **Marketing, and the consent store cannot be read** (a table never migrated, a lock timeout, an outage) → `hold` in `enforce`
   (consent cannot be verified, and a marketing message needs it) and `allow` in `report` (report never stops a send), reason
   `consent_store_unavailable`. The log names the exception **class** and nothing else: a database exception's message is the failing
   statement with its values substituted, which includes the contact's hash. Transactional and operational messages never reach the
   store, so they are unaffected.

Modes: `off` · `report` (compute, **log what would be held, send anyway**) · `enforce`. **The spellings that can only mean "not
on"** (empty, `false`, `0`, `no`, `disabled`, `none`, `null`) **are `off`** — Laravel turns the literal `false` into an empty string,
which used to select `report` and read tables an environment may not have. **Any other unrecognised value is `report`** — a typo
must neither disable the control nor stop a merchant's mail, and `report` is visible. **`enforce` is read as `report` for SMS and
WhatsApp** while `phone_identity_canonical` (a literal `false` in `config/marketing_consent.php`, deliberately not an environment
variable) is `false` — §3. Report and enforce logs carry a **12-character hash prefix, never an address**. Nothing in the gate infers
consent: "has ordered" and "is a contact" are not inputs.

**What the gate cannot do, said plainly.** Whether consent applies is decided entirely by the `MessageClass` the caller passes; a
promotional send passed as `Transactional` is allowed in every mode, and nothing in the gate can tell. `SendDecision` does not carry
the class and `report` logs only would-hold marketing, so a **mislabelled** send leaves no trace in the gate's own log. The seams must
log the class they passed (§6), and the sender census is what makes every site choose.

## 6. Where the gate has to be called — the seams, per channel

Measured by the census and the owner decision package §3.4; **only the email seam is wired (§6.2)**, and it is inert while the mode is `off`. All are first-party.

| Channel | Seam | Notes |
|---|---|---|
| **Email** | an `Illuminate\Mail\Events\MessageSending` listener, `app/Providers/EventServiceProvider.php`; returning `false` aborts before the transport | **Measured**: booting this repository's own `bootstrap/app.php` with a refusing listener cancelled the send, **including for vendored-module Mailables**. `Mailer.php:585` is the only `transport->send` in `Illuminate\Mail`. **Measured again 2026-11-18 on Laravel 11.57 (`MessageSending::$data`):** it carries **`__laravel_mailable`, the Mailable's class name**, for any Mailable, sync or queued, and `__laravel_notification` for notification mail; a raw `Mail::raw()` or `Mail::send('view')` carries **neither**, and its call site is recoverable from the backtrace. So no marker on a vendored class is needed: a listener can classify by class name from a registry. There are **12 Mailable classes** (8 first-party, 4 in vendored modules) and **2 Notification classes** (both password resets), and `CommonEmailTemplate` is the carrier of the rest: **61 call sites with a literal first argument, 11 distinct names** (`Stock Status` 24, `Order Created` 12, `Order Created For Owner` 11, `New Helpdesk Ticket Reply` 4, `Status Change` 3, `Pre Order Notification` 2, and one each of `Pre Order Created`, `Pre Order Created For Owner`, `New Helpdesk Ticket`, **`Abandon Wishlist`** and **`Abandon Cart`** — the last two are reminders, i.e. marketing), through `Utility::sendEmailTemplate` and the Helpdesk module's own `EmailTemplate::sendEmailTemplate`; plus **nine `sendOwnerEmailTemplate` call sites in six vendored modules (8 more names)**. Seven vendored modules also build `CommonEmailTemplate` themselves, so `Utility::sendEmailTemplate` is not the seam; the listener is. Anything the registry does not know is an **unclassified sender to report, not to default** (§6.1). |
| **Email, templates** | `Utility::sendEmailTemplate()`, `app/Models/Utility.php:2515` | the narrower seam for the template family; it knows the template, so it can classify; it is bypassed by any module that calls the mailer directly, which is why the listener is the backstop |
| **WhatsApp** | `Utility::SendMsgs()`, `app/Models/Utility.php:2648` | the single chokepoint for every WhatsApp send. Its first argument is a template name looked up in `whatsapp_messages`; the literal call sites are `Stock Status` (24), `Order Created` (11) and `Status Change` (3) — none promotional — plus five whose template is a variable, which are the ones to classify first |
| **SMS** | `Utility::send_twilio_msg()`, `:3460` (stock alerts — operational); `OtpTransport::post()` (one-time passwords — transactional) | the sites found so far are operational or transactional; **no marketing SMS has been located, and that is not the same as none** |
| **Push** | `Utility::sendFCM()`, `:3576`; `FirebaseService::sendToToken` | first-party throughout |

**Rejected on evidence:** `NotificationSending` (fires for **two** senders in the repository, both
transactional — the wrong instrument) and a **custom mail transport** or decorated `MailManager` (the transport
driver name is **tenant-supplied runtime data**, so a transport-keyed interception has a silent-miss mode).


### 6.1 Rules for whoever wires a seam (from the review; the email seam follows the ones that apply to it)

* **Never let a request name the store.** Use the authenticated principal, or the token's own store. A page opened under store B's slug passes
  **B** to `UnsubscribeTokens::redeem()`, which refuses a token that belongs to another store exactly as it refuses an unknown one.
* **The unsubscribe route must not redeem on `GET`.** Link prefetchers and mail scanners follow `GET`s, and a replay of an old link **after the
  person opted in again withdraws the new opt-in** (pinned by a characterisation test). Redeem on `POST` (RFC 8058 one-click) or behind a
  confirmation step, and **throttle** it: every recipient holds a working link and each redemption appends a row that nothing may delete.
* **Call the ledger outside any transaction that can fail afterwards.** A withdrawal recorded inside a checkout that then rolls back is a
  withdrawal not recorded, and a deadlock inside a caller's transaction is not retried.
* **Never forward a request-supplied hash to `withdrawByHash()`.** A hash is computable by anyone who knows a store id and can guess an address;
  only a token proves the holder was sent the link. A census allows exactly one caller (`UnsubscribeTokens`).
* **`MessageClass` has no `unclassified`.** The mail seam's listener "cannot tell a receipt from a coupon", so a message with no marker has
  nothing honest to pass; it must be logged as an unclassified sender (class name or call site, **never an address**) and the census must
  fail when a sender is added without a class. Until the count of unclassified senders is zero, consent is not complete.
* **A confirmed opt-in does not exist yet.** `grant()` turns any well-formed address into `granted` with the actor the caller names; nothing
  verifies that the grantor controls the address (single opt-in, no pending state). Whoever builds the opt-in surface decides whether that is
  acceptable, and `liftSuppression()` clears **any** suppression, a complaint and a bounce alike, because the projection keeps no origin.
* **Enforce-time holds are logged by the gate** (store, channel, reason, hash prefix); *why* a campaign did not send otherwise has no record.

### 6.2 The email seam, as built (2026-11-18)

**What it does.** In `report` or `enforce` it classifies each outgoing email from `MessageSending::$data` — `__laravel_mailable` (the Mailable's
class name; sync or queued), `__laravel_notification`, or neither for a raw message — against `MailSenderRegistry`: **every entry carrying the
evidence for it** (file and line of the code that sends it), **every sender the code does not prove left `null` (undecided)**. A **marketing**
sender is gated **per recipient** (To, Cc, Bcc, matched case-insensitively), at the store the message itself carries; in `enforce` an all-held
message is aborted and a partly-held one is sent to the rest. A **non-marketing** sender is never held. An **undecided** sender is **reported once
per process (per call site for a raw or anonymous one), with where it was sent from — a path, never an address — and sent**, in every mode.
It is registered **once**, and lives in `app/Services/Marketing/` rather than `app/Listeners/` because the framework's EventServiceProvider
discovers `app/Listeners` by `handle()` signature, which would run it twice per message (a test counts).

**What the registry decides.** Marketing: the vendored coupon mail, and the templates `Abandon Cart`, `Abandon Wishlist` and `Review Reminder`
("remind" is marketing in the owner's definition). Transactional: `Order Created`, `Status Change`, `Pre Order Created`, `Pre Order Notification`
(each to the customer about their own order), the OTP mails, the digital-download link, and the two password-reset notifications. Operational
(to the merchant): `Order Created For Owner`, `Pre Order Created For Owner`, `Lottery Remainder`, the merchant's SMTP test. **Undecided, each with the
reason:** `Stock Status` (it goes to the *buyer* at order time, not to the merchant — my first reading was wrong — and its trigger reads as a back-order
notice while its shipped body says "now back in stock, do not miss this opportunity"), `Payment Reminder`, the merchant-composed mailbox mails, the
voucher-status mail (a PDF of every active coupon to the address on a `coupon_requests` row), the helpdesk, lottery-result, product-enquiry and
product-catalogue templates, and three stub Mailables nothing constructs. Every decision is pinned by a test, and a census fails when a Mailable
class, a Notification class or a template name appears in the tree that is not listed, when a listed one disappears, and when a `Mail::raw()` /
`Mail::send('view')` appears at all.

**What it cannot do — each is a finding, not a defect to hide.**

* **The vendored coupon mail carries no store.** `CouponEmail::sendCouponEmail($mailTo, $subject, $content, $store_id)` receives the store and does not put it
  in the Mailable, so the mail event cannot attribute it. In `report` that is logged (`no_store`); in `enforce` it is **held, always**, because consent
  is per store and there is nothing to match. Closing it means either editing the vendored module (not authorised) or a first-party wrapper around its listeners.
* **A template's BODY is data.** The registry classifies what the platform sends a template *for*. Who may write a body is therefore a consent question, and
  it had an answer nobody liked: **any verified user could rewrite the platform-wide body of `Order Created`** (P1-SEC-64, found by this seam's review, fixed
  in the same change — `docs/security/P01-SECURITY-WORK-QUEUE.md`). An authorised editor can still write a promotion into a receipt, and nothing at the mail layer can tell.
* **Undecided senders are not held, even in `enforce`.** Holding a message nobody can identify would stop a one-time code or a receipt. The cost is that an
  undecided promotional sender passes; the reported list is how that cost is driven to zero, and **whether `enforce` should hold an undecided sender instead is
  the owner's call** (§10).
* **A held send looks successful to the code that sent it.** `Utility::sendEmailTemplate()` treats "no exception" as `is_success`, and the cart and wishlist
  reminder buttons answer "Mail send successfully", so a held abandoned-cart mail tells the merchant it was sent. The gate's log, which now names the
  sender, is the only record. Fixing that is a change to shared helpers and is not made here.
* **The seam fails safe, per what it already knew.** It never throws. If it fails after classifying a sender as marketing under `enforce`, the message is
  **held** (marketing that cannot be verified is held, as in the gate); in every other case it goes. A log channel that throws changes neither outcome — an
  earlier version claimed "it never throws" and was not true of its own log lines.

## 7. The sender sites — what has and has not been audited

The census found **214** send sites. **Forty were classified in depth and ten of those were refuted**; the other
**174 were enumerated, not analysed**, and "not analysed" must never be read as "safe".

A second run (164 agents) classified the remainder and has finished. Its output is kept, with every row's
checked-or-not status, in [`evidence/mkt-008-batch2/`](evidence/mkt-008-batch2/README.md) — **as leads, not
findings**: 239 rows over 163 distinct sites, **88 of them never checked by a second reader**, 50 refuted by
the ones that were, and a sample of seven against the code in which one row I read the other way. **Nothing in
this design relies on those classifications**; the only structural conclusion drawn is the one the first census
already supported, that the four seams in §6 (the mail event, `sendEmailTemplate`, `SendMsgs`, `sendFCM`) are the
places a promotional send leaves, with the two `LynomiaChat` campaign calls going to an upstream service whose
own consent boundary is outside this repository.

Until every site has a class **and** a verified seam, the statement GAP-200 can support is exactly this: *the
code has no consent control and no unsubscribe, a control now exists and is wired to the email seam only (inert while enforcement is `off`), and the live blast radius is a
question about the production database and module configuration.* The known promotional senders with no consent
predicate are `CouponEmail` (on registration, first order, and an order-value threshold), `ReviewReminder`, the
newsletter list and the abandoned-cart dispatch (`AbandonedMessageDispatch`); whether any of them fires in
production depends on which modules are enabled and which stores have mail, WhatsApp and SMS credentials
configured, **none of which is visible from the engineering environment**.

## 8. What the rollout would look like — every step after the first needs your authorisation

1. **Done, locally:** the store, ledger, gate and tokens, with enforcement `off`.
2. **Wire the seams in `report` mode** in a development environment, class by class, adding a census test per
   seam that fails when a sender in that family is added without a class. *No behaviour changes in production
   while the switch is `off`.* **Done locally for EMAIL** (§6.2) — `MailSenderRegistryCensusTest` is that census.
   **Not done for SMS, WhatsApp or push.**
3. **A place to opt in**, and the unsubscribe route and page — the only user-visible parts, bilingual
   (Arabic RTL / English LTR) and checked in both directions — built to the rules in §6.1.
4. **Authorised**: apply the three migrations to the target environment. They create three new tables and alter
   nothing that exists, and **unlike GATE F's promotion migrations they are not a no-op against production**:
   they are exactly the persistent migration the owner reserved.
5. **Authorised**: set `report` in production and read the log. This is how an owner learns **which sends would
   be held before any is held** — and it will be a large number, because **every existing customer is `none`**:
   consent is never inferred and nothing is backfilled, so turning `enforce` on stops every marketing send to
   every existing contact until it is collected. That is the intended consequence of D8 and a business decision
   in its own right.
6. **Authorised**: `enforce`. **It is one platform-wide switch, per channel only in the one way described in §3**: there is no per-store
   activation in the code, and an earlier version of this section said "store by store" without it existing. If the owner wants it, it is
   a small addition (an allow-list of store ids in the configuration), not a thing already built. **SMS and WhatsApp stay in `report`
   until phone identity is canonical.**

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
* It does not say phone identity is safe. It is not, in two directions (§3), and report mode cannot show it.
* It does not say the tables are protected against anyone with database access: append-only is a model-layer guarantee (§2), and the
  hash of a phone number plus its last-four hint is recoverable offline (§3).

## 10. The independent review of the built store, and what became of every finding (2026-11-18)

**Method.** Four read-only reviewers, one per lens (tenancy and identity · evidence integrity, projection and concurrency · unsubscribe
tokens and address normalisation · the send gate, configuration, migrations and the tests themselves), each told to cite `file:line`, to
report only what it could show from the code, and to list what it could not establish. They executed nothing. Their output was treated as
**claims**: the two that mattered most were reproduced on a real server before any code changed, and every fix is pinned by a test that
fails when the fix is reverted. A planned second stage — a refuter per finding — was **stopped**: the refuters were reading a tree that had
already been fixed, and each answered "stale: the file no longer contains this", which confirms the findings described the code as it
was and is not a refutation of any of them. The review record is therefore *four reviews plus my own measurements*, not a
claim that every finding survived an adversary.

**Two defects were real, reproduced, and are fixed.** Measured with `tests/Support/Security/bin/verify-consent-ledger-concurrency-on-mysql.php`
— several real processes, each with its own connection, running the **real** `ConsentLedger`, with the interleaving forced by a query
listener — on MariaDB 10.11.14 at its default REPEATABLE READ:

| | before | after |
|---|---|---|
| **F1** a withdrawal lost in the row the gate reads | log `1:granted 2:granted 3:withdrawn`; projection `granted`; replay `withdrawn` | projection `withdrawn` = replay |
| **F2** two first-ever writers for one contact | `1213 Deadlock found`; one request rolled back and its event lost | both recorded, one projection row |
| **F2b** first-ever writers for **different** contacts (not in the review's findings; it asked the question and could not answer it by reading) | with the retry removed, **3 of 200** events lost to `1213` on every run | **0 of 200**, three runs in a row, **with the retry removed** |
| stress: six processes, 72 seeded events on one contact | consistent | consistent, 0 errors |

F2b is why the fix is an upsert-before-lock and not a retry: a retry absorbs a deadlock, it does not remove one, and it hides the
count. The deadlock retry is kept as a seatbelt only.

**Everything else, by finding.** *Fixed* means code **and** a test that fails without it (the mutation count is below).

| id | sev. | finding | disposition |
|---|---|---|---|
| F1 | high | event inserted before the projection is locked: lock order ≠ id order | **FIXED** — lock first, insert under the lock, fold under it; measured above |
| F2 | med | gap-lock deadlock on a first-ever insert | **FIXED** — upsert creates the row before it is read for update; measured above (§3) |
| F3 | med | append-only guard missed `touch`, `updateOrInsert`, `incrementEach`, `decrementEach`, `toBase()` | **FIXED** — `AppendOnlyQueryBuilder` refuses `update`/`updateFrom`/`upsert`/`delete`/`truncate`; all nine paths tested; inserts still work. `DB::table()` stays open and is **documented as open** |
| F4 | med | census matched a few write verbs and missed the evidence model's inserts; no way to compare projection with log | **FIXED** — census inverted to "who may *name* a consent model or table" with a self-test over synthetic shapes and a planted-offender probe; `divergences(storeId)` added |
| F5 | med | one non-UTF-8 byte in the evidence (a User-Agent) turned an unsubscribe into an exception | **FIXED** — evidence is encodable by construction; nothing is dropped without a `_note`; a hostile header cannot stop a withdrawal |
| F6 | low | `purpose`/`source`/`actor_type`/hint written unvalidated into short columns; non-strict MySQL truncates | **FIXED** — validated and refused, hint capped; mask bounded |
| F7 | low | "single use" is false; first-use stamp not atomic; its test could not fail | **FIXED** — reusable by design, said so everywhere; one conditional `UPDATE`; the test freezes and moves time |
| F8 / TI-7 | low | the hash recipe is data, and nothing pins it | **FIXED** — known-answer test; the docblock says what changing it does |
| TI-1 / T1 / T3 | med | **phone identity is not canonical**: withdrawal under one spelling does not bind another; two people can share a country-less key | **NOT FIXABLE HERE — contained.** `enforce` is read as `report` for SMS and WhatsApp (a literal `false` flag, tested, mutation-checked); docs and test messages corrected. **Owner decision** below |
| T2 | med | Arabic-Indic / Persian / full-width digits were *deleted* by the byte-wise `\D` | **FIXED** — converted to ASCII digits before stripping |
| — | low | email folded before validation: U+212A aliased an ASCII address | **FIXED** — validate first, then fold |
| TI-2 | low | the ledger records the actor and source the caller asserts; `liftSuppression` clears any suppression; no confirmed opt-in | **RECORDED** (§6.1) — a seam's responsibility, not the ledger's |
| TI-3 | low | `$guarded = []` on all three models | **FIXED** — explicit `$fillable` |
| TI-4 | low | classification pinned by count only | **FIXED** — pinned by name, plus *not Cachable* |
| TI-5 | low | a database exception copies the evidence, hint and hash into the log | **PARTLY FIXED** — the gate logs the class only. The ledger still lets a `QueryException` propagate to its caller, whose reporter writes it; wrapping it is a decision for the seam author (§6.1) |
| TI-6 / T4 | low | any holder replays a link; GET would redeem; the opening store was optional | **FIXED / RECORDED** — the store is a required argument; the GET, throttle and store rules are in §6.1; replay-after-opt-in is pinned by a characterisation test |
| T5 | low | tokens never expire and cannot be revoked or pruned; nobody decided that | **RECORDED as an owner decision** below |
| T6 | low | `withdrawByHash` is public and takes a bare hash | **FIXED** — a census allows exactly one caller |
| T7 | low | phone hash + last-four hint is enumerable offline | **RECORDED** (§3) — the documents no longer call it anonymous; a keyed hash needs a secret that is not in git, which is the owner's key-management decision |
| G1 | med | the gate had no failure mode; `false` selected `report`, which reads tables | **FIXED** — §5 rule 4; the not-on spellings are `off` |
| G-T1 | med | the rollback test became vacuous when the lock moved first | **FIXED** — the failure now lands on the fold, after the insert |
| G-T2 | low | the lock and the transaction are invisible on SQLite | **FIXED as far as SQLite allows** — the three statements are asserted to share one transaction and the source is asserted to ask for the lock; the rest is the MariaDB script, which **CI does not run** |
| G-C1 | low | the census exempted every migration written on one date | **FIXED** — three exact file names; dynamic call shapes added |
| G-D1 | low | class is caller-asserted; the gate leaves no trace of what it holds | **PARTLY FIXED** — enforce holds are logged; the mislabelled-send gap is recorded in §5 and §6.1 |
| G-D2 | low | the gate restated `permitsMarketing()` | **FIXED** — one source of truth |
| G-M1 | low | an index nobody reads on every issued token; two wrong comments; the guard's names not checked on SQLite | **FIXED** — index removed, comments corrected; the MariaDB script now re-runs each migration's `up()` against the tables it built |
| G-T3 | low | three weak assertions | **FIXED** — message asserted too, retitled, whole public surface of the token service pinned |

**Evidence, measured.** Marketing suites: **142 tests / 532 assertions** (Feature 92 / 412, Unit 50 / 120), against **99 / 322** when the
store was first committed. MariaDB script: **17 checks, 0 failed**, and with the deadlock retry removed it still passes (three runs).
**Mutation:** every fix above was reverted one at a time on the real file with a checksum-verified restore — **35 mutants, 35 killed,
0 survivors**. The consent census was also shown to *fail on the real tree* by planting an offending file in `app/`.

**What this review did not and could not do.** It executed no test (the reviewers could not; mine are above). It did not run against
production, which has none of these tables. It cannot show how a *seam* will behave, because there are none. It confirms nothing about
which promotional senders are live in production, which is a question about the production database.

**Owner decisions this review surfaced (none is taken here):**

1. **Phone identity** — add a store-level default country and a phone-number library (e.g. libphonenumber), make `normalise()` canonical,
   re-key stored rows in the same change, then flip `phone_identity_canonical`. Until then SMS and WhatsApp consent runs in `report` only.
2. **Token lifetime and retention** — tokens never expire (an unsubscribe link must keep working) and rows are never pruned; for a
   deleted customer the row keeps `customer_id` and a masked hint, and the evidence outlives the customer. What retention applies is a legal
   question.
3. **Database-level append-only** — a trigger refusing `UPDATE`/`DELETE`, or grants that withhold them from the application's user. It is
   a production schema decision and belongs with the migration authorisation. The three migrations sit in `database/migrations/`, so
   **the first `php artisan migrate` run in any environment creates three empty tables**; they alter nothing that exists, but the owner
   should know that merging this branch and running migrate *is* the persistent migration D8 reserves.
4. **A keyed hash** — to stop offline enumeration of phone numbers, which needs a server-side secret that is not in git.
5. **Per-store activation** — not built; a small addition if wanted.

### 10.1 The independent review of the email seam (same day)

A second read-only reviewer, with these lenses: can the seam stop an email it must not · can marketing get through · attribution and tenancy ·
partial-hold mechanics · privacy and observability · cost · the tests. It executed nothing. Its report was treated as claims, and each was checked
against the code before anything changed.

| id | finding | disposition |
|---|---|---|
| F1 | **any verified user can rewrite the platform-wide body of a decided-transactional template** (`PUT /email_template_lang/{id}`: no authorisation, no ownership; the screen was the operator's, the route was not) | **REAL, LIVE, OUTSIDE THE SEAM — FIXED as P1-SEC-64.** Ownership on the write and the read; 8 tests, 4 of 4 reverts redden. The most important result of the review, and not a consent finding |
| F2 | "it never throws" was false when the log throws (the `catch`'s own log, and the gate's hold returned only after its line was written) | **FIXED** — every line guarded, decision first; pinned at the seam and at the gate |
| F3 | the census missed `sendOwnerEmailTemplate`, array-form names, `extends CommonEmailTemplate`, aliases, `readonly`, several classes per file, notifications, raw sends | **FIXED** — each shape has a self-test; the claim in the registry now says what it cannot see |
| F4 | tests: production passes a `Store` **model** (every test passed an array); the NUL assertion could not fail; no mixed-case or held-To-kept-Cc case; a decided and an undecided sender were indistinguishable | **FIXED** — each has a test; every decision is pinned |
| F5 | a held send reports success to the merchant (`is_success`; "Mail send successfully") | **RECORDED** (§6.2) — a shared-helper change, not made here; the owner may want it |
| F6 | the hold log did not say which sender; raw and anonymous senders were reported once per process, not per call site; an anonymous Notification's path was logged; notifications had no registry | **FIXED** — a sender-identity line, per-call-site memo, sanitised label, two notifications decided |
| F7 | an internal failure released marketing under `enforce` | **FIXED** — held when the sender is known marketing and the mode is `enforce` |
| F8 | a stored `abandon cart` slipped past the `Abandon Cart` decision (the row is found with a case-insensitive `LIKE`; the decision was an exact match) | **FIXED** — case-insensitive lookup, tested |
| F9 | the vendored coupon mail can never be sent under `enforce` | **RECORDED** (§6.2) — a finding, with its two ways out |
| F10 | undecided entries the code settles | **FIXED where proven** — `Lottery Remainder`, `Pre Order Notification`, `Pre Order Created` and `Pre Order Created For Owner` are decided; the facts for `Stock Status`, the mailbox and the voucher mail are now in their entries |

**Mutation:** 24 mutants of the seam, **23 killed**, and one equivalent — asking the mode of the SMS channel instead of the email channel cannot change the
`off` decision, which is channel-independent.

**Owner decisions this review added** (none is taken here): (a) whether `enforce` should hold an **undecided** sender instead of sending it; (b) whether
`Payment Reminder` is a service message or a "reminder" in your sense; (c) what **`Stock Status`** is for — the trigger and the shipped text disagree;
(d) the **mailbox** and **voucher-status** mails, which a merchant can use to promote; (e) how the **vendored coupon mail** gets a store (a vendored edit, or a
first-party wrapper); (f) whether a **held send should be visible to the merchant** who triggered it.
