# Phase 5E — closure evidence

Partner consent, messaging eligibility, bidirectional synchronisation, checkout opt-in,
throttling and safety. What was built, what was found broken while building it, and what is
still owed.

> **CORRECTION, ADDED AT P5H.** When this document was first written, four of the things it
> records as "done" did not work on the real wire, and the tests on both sides agreed that
> they did. They are fixed now, and the rows below are accurate as of Store Syria `b9b49501`
> and Lynomia `3331d245` — but the original claims were wrong and are named here rather than
> silently overwritten:
>
> 1. **No checkout opt-in ever reached Lynomia.** The dispatcher sent the bare Store Syria
>    store id where Lynomia holds `{installation_ref}:{id}`, so every delivery was answered
>    404. The projector, the reconciler and both audience-scope resolvers had the same
>    mistake, so inbound events were dropped as belonging to another installation and
>    reconciliation repaired nothing. One `StoreIdentity` helper now; see the Phase 5 report
>    §5.1.
> 2. **A partner-only grant could never produce a campaign recipient.** `audience_contacts`
>    required a NATIVE promotional grant, which a checkout opt-in does not create.
>    `Partners::Eligibility.filter`, written for exactly this, had zero callers.
> 3. **Every native consent change after the first was dropped by Store Syria.** The feed
>    published the consent row's primary key as its `revision`, and that row is updated in
>    place, so the value never rose and the projector read each change as a late duplicate.
> 4. **A suppressed customer link still counted as that store's customer** in the consent
>    read API, its write API and the change feed — so a store an agent had told us was not
>    this shopper's was still sent their opt-outs.
>
> Each is now covered by a test that is red without its fix, and the audience-fields contract
> has a cross-repository artifact of its own.

---

## 1. What P5E had to deliver, and where each piece landed

| Requirement | Where it lives | State |
|---|---|---|
| Partner consent subsystem, reusable, not Store-Syria-specific | `custom/app/models/partner_consent.rb`, `partner_consent_event.rb`, `custom/app/services/partners/` | done |
| Lynomia's native consent product preserved and unchanged | nothing under `custom/app/.../commerce/messaging_consent*` was altered by P5E | done |
| Shared eligibility engine, no second sending engine | `Campaigns::SendGuard` composes `Commerce::MessagingEligibility` + `Partners::Eligibility` | done |
| Messaging eligibility at the final send boundary | `Campaigns::SendGuard.clear_to_send`, called by all three sender services | done |
| Bidirectional synchronisation | outbound `ConsentDecisionDispatcher`; inbound `EventIngressController` → `ConsentStateProjector`; pull `ConsentReconciler` | done |
| Checkout opt-in | `CheckoutConsentCapture` + `CheckoutConsentObserver` + `ViewComposer` + `storefront/marketing-optin.blade.php` | done |
| Merchant consent management, Arabic and English | consent tab (P4E) plus the store ledger panel (P5E); 573 keys in each locale | done |
| Consent-state visibility | `StoreConsentController` + the ledger panel | done |
| Throttling and safety | `Campaigns::Throttle`, `Campaigns::Outbound`; all eight properties in `THROTTLE-SAFETY.md` | done |
| Reconciliation | `lynomia:reconcile-consents`, hourly | done |

## 2. Defects found and fixed while verifying

Five, all confirmed by reproduction before being fixed. Three of them were in code written
earlier in this same phase, which is the argument for verifying rather than reviewing.

| # | Defect | Consequence if shipped | Fix |
|---|---|---|---|
| 1 | The send guard ran **before** the throttle's sleep | A withdrawal, kill switch or revocation landing during the wait was read and then ignored. The wait is where a long campaign spends most of its wall clock, so this was the common case, not the edge. | `Campaigns::SendGuard.clear_to_send` — ask, pace, ask again. `Campaigns::Throttle.pace` has exactly one caller now, so a future sender cannot reintroduce it. Lynomia `2b768b11`. |
| 2 | `account.settings`, `store.status` and `contact.blocked` were read off objects loaded **before the run** | The per-account kill switch, a revoked installation and an agent blocking a shopper mid-run all did nothing until the next job — three of the four cases the guard's own docblock promised to honour. | `SendGuard#live` re-reads each by primary key at the gate. Lynomia `2b768b11`. |
| 3 | The degraded-throttle operations signal was silently dropped | `source: 'campaigns'` and `detail: { error: ... }` are outside the allow-lists, and `SignalRecorder` never raises — so an operator would never have learned the throttle had failed. | Corrected to `source: 'campaign'` / `detail: { code: ... }`, with the reason recorded at the call site. Lynomia `2b768b11`. |
| 4 | Native and partner consent states shared one projection row, ordered by incommensurable revisions | A partner grant at revision 10001 overwrote a native withdrawal at revision 5. It could not cause a wrong send (Lynomia's guard unions negatives and is the authority), but it made the **merchant's screen** say a withdrawn customer would be messaged. | `origin` is now part of the unique scope; the effective answer unions the negatives. Migration `2026_10_21_000200`, `LynomiaConsentState::effectiveFor`. |
| 5 | `external_customer_id` selected through a SQL join returned ciphertext | Reconciliation would have matched nothing, silently. Caught by a spec asserting the partner's own customer id comes back. | Resolved through models, scoped to the rows being presented. Lynomia `eb202ac3`. |

## 3. Decisions worth restating

**A checkout tick is never a re-consent.** `re_consent` is false, always. Lynomia refuses a
plain grant that follows a withdrawal, and that refusal is the correct outcome: a shopper who
said STOP on WhatsApp has not changed their mind because they did not notice a box three
screens into a purchase. Re-consent is a separate, explicitly verified workflow.

**Silence is not withdrawal.** An unticked box records nothing. Reading it as a withdrawal
would destroy a grant the customer gave somewhere else.

**A guest is not captured.** `customer_id` is 0 for a guest order and the subject this store
sends is the customer id, so a guest's tick has no stable identity to be recorded against —
it could never be matched to them again, nor honoured when they withdraw.

**Reconciliation pulls and never pushes.** Store Syria is authoritative for what a customer
told *this store*; Lynomia is authoritative for whether a message is sent. A sweep that
re-sent local decisions because the remote disagreed would be this side overruling the
other's refusals — the competing-authorities failure `AUTHORITY.md` exists to prevent.

**Campaign cancellation is deferred to P5D, in writing.** `campaign_status` today is
`active / completed / processing / blocked`, `blocked` is only ever set before a run begins,
and no operator action cancels a campaign mid-run. A check for it would be unreachable code.
The gate where P5D will enforce it is named: `Campaigns::SendGuard.clear_to_send`.

## 4. Tests actually executed

| Suite | Result |
|---|---|
| Lynomia — campaigns, partners, consents, contacts, contracts, platform, 3 senders, campaign models | 495 examples, 0 failures |
| Lynomia — `spec/requests/platform` after the native-collection change | 146 examples, 0 failures |
| Lynomia — concurrency (real threads, non-transactional) | 3 examples, 0 failures |
| Store Syria — `--filter Lynomia` | 339 tests, 4,553 assertions, 0 failures |
| Store Syria — `Contract` suite (real producer artifact, real ingress) | 9 tests, 32 assertions, 0 failures |
| Store Syria — browser, 5 suites including the new P5E one | 132 checks, 0 failures |
| Store Syria — full suite against the known-outcome gate | 1,988 tests, 8 declared failures, 17 declared skips, 0 undeclared |

## 5. What P5E does NOT establish

- **No deployed end-to-end exchange.** Every cross-system test uses a scriptable fake of the
  peer or the shared contract artifact. That proves the two codebases agree about the wire
  format, identity fields, signature construction, ordering key and refusal semantics. It
  proves nothing about DNS, TLS, real credentials, Sidekiq actually delivering, clock skew
  between two hosts, or a webhook subscription that was never created upstream. **Blocker A1
  stays open.**
- **Nothing has run with the switches on.** `LYNOMIA_INTEGRATION_ENABLED`,
  `STORESYRIA_ENABLED`, `whatsapp_campaign` and `lynomia_commerce` are all off as deployed
  (`PHASE-5A-CAPABILITY-MATRIX.md` §0.1). No Lynomia-facing path has executed in this
  environment.
- **Reconciliation is bounded at 200 rows per collection per sweep.** A merchant with more
  recent consent changes than that in one hour would have the oldest of them repaired on a
  later pass. Every change still arrives live over the event feed; this is the net behind it.
