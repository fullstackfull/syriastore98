# PHASE 7 — STAGING TEST RUNBOOK

**NOT EXECUTED. No step in this document has been performed. It is written to be run by an
operator against two staging hosts AFTER the owner's explicit staging execution
authorization, with the service owner reachable for every GO/NO-GO.**

Nothing here has been run against a live Lynomia, a real provider, or a real recipient. Where
a section would send a real message or inject a fault, it carries its own gate and stops.

| | |
|---|---|
| Run after | `PHASE-7-RUNBOOK-DEPLOY.md` §3 smoke checks all pass |
| Prerequisites | `PHASE-7-PREREQUISITES.md` |
| Fixtures | `PHASE-7-PREREQUISITES.md` §Fixtures — the synthetic merchants, shoppers and orders each section below assumes |
| Scope and rationale | `PHASE-7-READINESS-PLAN.md` — this runbook executes its §4–§13 |
| Open blockers | `PRODUCTION-READINESS-BLOCKERS.md` |

---

## How to run this

**In order.** The sections are a dependency chain, not a checklist to sample. §5 cannot be
judged before §4 has produced a linked workspace; §7 cannot be judged before §6 has produced a
customer; §10's delivery feed cannot be judged before §10.1 has provisioned a platform
workspace. A section that is skipped makes every later section's result unreliable rather than
merely incomplete.

**Record evidence, not ticks.** For each step, capture what you actually observed — the HTTP
status, the row, the screenshot, the timestamp. Phase 7's output is that record. A step marked
"pass" with nothing behind it is the same thing as an unrun step, and the project has already
been bitten twice by verification that looked complete and was not.

**On a failure, stop at the section boundary.** Finish the section you are in (a partial
section is hard to interpret later), then take it to the owner with the evidence. Do not
improvise a fix inside the window.

**Three markers appear below:**

| Marker | Meaning |
|---|---|
| **GATE** | requires a specific owner authorization *at that moment*, not the general staging go |
| **SECURITY** | a property, not a feature. A failure here stops the phase rather than being logged |
| **MEASURE** | capture a number; it is an artifact Phase 7 owes (`PHASE-7-READINESS-PLAN.md` §14.4) |

---

## §4. SSO and account lifecycle

Phase 1 capability, against a real Lynomia for the first time.

| # | Action | Pass condition | Evidence |
|---|---|---|---|
| 4.1 | As merchant A, provision a Lynomia workspace from the Store Syria dashboard | a Lynomia account exists; exactly one `lynomia_store_links` row; both `store_id` and `lynomia_account_id` unique | the row, and the account id in Lynomia |
| 4.2 | Open the inbox via SSO | a Lynomia session opens for the deterministic service user | screenshot |
| 4.3 **SECURITY** | Grep the Store Syria logs, the flash-message store and `lynomia_store_links` for the SSO URL | **absent from all three** | the grep commands and their empty output |
| 4.4 | Provision a second workspace for the same store | refused as a conflict — a 4xx with a message, **not** a 500 and **not** a second row | the response and the row count |
| 4.5 **SECURITY** | As merchant B, claim merchant A's *existing* Lynomia account | a pending claim and a one-time code are created, and **no grant exists yet**. This is the account-takeover primitive Phase 1 refused to build: `AccountLinker` must call nothing that grants | the claim row, and the absence of a permissible in Lynomia |
| 4.6 | Approve the claim as a platform operator | the link activates | |
| 4.7 **SECURITY** | Attempt 4.6 as the merchant themselves | refused | |
| 4.8 | Replay the code from 4.5 | refused; single-use | |
| 4.9 **SECURITY** | As merchant A, point `current_store` at merchant B's store and repeat 4.1 and 4.2 | refused by `EnsureStoreOwnsLink` on **both** `store_id` and `owner_user_id` | both responses |
| 4.10 | Suspend the installation from the partner console; then suspend again | the merchant is notified **once**, on the transition only — not once per attempt | the notice rows |
| 4.11 | Resume | notified once | |
| 4.12 **SECURITY** | Have the merchant disconnect, then push a partner `suspend` | the disconnected row is **not** rewritten to `disabled`; it stays reconnectable | the row before and after, then a successful reconnect |
| 4.13 **SECURITY** | Revoke | the suspend is pushed to the commerce store **before** the account grant is handed back, and the commerce store ends up actually suspended — not left active behind a 401 | the store's state in Lynomia |
| 4.14 | Re-authenticate a link after rotating its credential | succeeds. Then make Lynomia genuinely unreachable and retry: it must surface `LynomiaApiException`, not pass silently | both outcomes |

**GO/NO-GO 4** — 4.3, 4.5, 4.7, 4.9, 4.12 and 4.13 all held. These are security properties;
the rest are features. No step produced a 500.

---

## §5. Inbox and realtime

| # | Action | Pass condition |
|---|---|---|
| 5.1 | Open the native merchant inbox | conversations render; no Lynomia branding leaks into merchant-facing copy |
| 5.2 **SECURITY** | As merchant A, request merchant B's conversation ids directly | refused |
| 5.3 | Reply from the merchant inbox | appears in the Lynomia agent dashboard |
| 5.4 | Send from the Lynomia agent dashboard | appears in the merchant inbox within `LYNOMIA_INBOX_POLL_SECONDS` (default 10) |
| 5.5 **MEASURE** | Time 5.3 and 5.4 | record both latencies |
| 5.6 | Inbound message from the cooperating handset | reaches both surfaces |
| 5.7 | Assign, change status and mark read from the merchant side | each reflected in Lynomia |
| 5.8 | Inspect `lynomia_inbox_events` | filling; `delivery_id` unique; no duplicates |
| 5.9 | Run every browser harness against the **deployed** pages | all checks pass. These have only ever run against rendered fixtures |
| 5.10 | 5.9 in Arabic at 390px | right-to-left, no sideways overflow, no untranslated key |

```bash
STUDIO_BASE=https://<store-syria-staging> bash tests/Browser/run.sh \
  p4c-flow-builder p4d-channels p4e-commerce-automation p4f-templates-history \
  p5b-audiences p5c-templates p5d-campaigns p5e-store-consent p6-operator-console
```

**GO/NO-GO 5** — messaging works in both directions on real hosts, and 5.9 passes against
deployed pages rather than fixtures.

---

## §6. Commerce API and events

| # | Action | Pass condition |
|---|---|---|
| 6.1 | Lynomia calls Store Syria's commerce API with the store-bound credential | 200, store-scoped |
| 6.2 **SECURITY** | Capture the store identifier **on the wire** | it is the qualified `{installation_ref}:{store_id}`, never a bare number. This single misreading broke four features at once in Phase 5; a test asserting it is not the same as seeing it |
| 6.3 | Create an order; watch for it in an agent's commerce panel | `lynomia_commerce_events` drains within a minute |
| 6.4 **MEASURE** | Time 6.3 | order write → visible in the panel |
| 6.5 | Change an order's payment status **through a gateway controller**, not by dispatching `OrderPaymentStatus` | the model observer catches it. ~52 gateway controllers write `payment_status` directly; the retired Chatwoot integration listened to an event none of them dispatch, and that is why the observer is on the model |
| 6.6 | Abandon a cart | appears |
| 6.7 **SECURITY** | Request store B's customer using store A's credential | refused |
| 6.8 | Stop Store Syria, create several events, restart | the outbox drains; nothing lost, nothing delivered twice |

**GO/NO-GO 6** — 6.2 and 6.7 held on the wire.

---

## §7. Consent synchronisation

The compliance-critical path. Its wire format is already pinned by the `consent_changed.v1.json`
contract artifact; this section is the half a contract cannot cover.

| # | Action | Pass condition |
|---|---|---|
| 7.1 | Shopper opts in at checkout | a `lynomia_consent_decisions` row; drains within a minute; Lynomia holds a partner grant |
| 7.2 | A shopper with no Lynomia contact yet opts in | held as transient `subject_not_linked`, **not** rejected. Then have them write in: the decision lands |
| 7.3 | Confirm the attempt ceiling | a decision that can never land is retired to a terminal state **and** raised as an operator-visible signal — not retried for ever and not dropped quietly |
| 7.4 **GATE** | The shopper replies **STOP** on the real WhatsApp number | requires the §9 GATE to have been given. `Commerce::ConsentListener` writes a withdrawal, `Consents::ChangeFeed` publishes it, Store Syria's ingress accepts it, the merchant's consent screen shows withdrawn |
| 7.5 **MEASURE** | Time 7.4 end to end | STOP sent → merchant screen shows withdrawn. A withdrawal that takes minutes is a compliance exposure and must be reported as a number, not an impression |
| 7.6 | Replay the same delivery id | no second state change |
| 7.7 **SECURITY** | Deliver an older revision after a newer one | dropped; the state does not walk backwards |
| 7.8 **SECURITY** | Attempt a promotional send to the withdrawn shopper from **all three** surfaces: a campaign, a flow Send-template node, and the agent composer | all three refuse, each with the recorded reason. The composer is the surface that asked nothing until Phase 6 |
| 7.9 | Stop Store Syria for 10 minutes; withdraw on WhatsApp; restart | the delivery is lost — Lynomia does not retry an account webhook. `lynomia:reconcile-consents` repairs it within the hour. **Observe the repair; do not assume it** |

**GO/NO-GO 7** — 7.4, 7.7, 7.8 and 7.9 held. **A withdrawal that does not block a send stops
the phase.** It is not a defect to log and continue past.

---

## §8. Flow Builder and automation

| # | Action | Pass condition |
|---|---|---|
| 8.1 | Build, publish and run **one flow per starter template** | each completes; `flow_sessions` records them |
| 8.2 **SECURITY** | A flow Send-template node aimed at an ineligible recipient | refused before the send, with the reason |
| 8.3 **SECURITY** | An automation rule template action aimed at an ineligible recipient | likewise |
| 8.4 **SECURITY** | A flow that writes a message directly (`Flows::Run#say`) | still passes the eligibility gate. This is the path that routes around `Messages::MessageBuilder`, which is exactly why the consent gate lives on `SendOnWhatsappService` and not on the builder |
| 8.5 **MEASURE** | Concurrent flow sessions on one contact | no deadlock. Record the row count in `flow_sessions` and the time the §B1 concurrent index took at deploy. **Staging cannot close B1** — note what you measured and on how many rows |

**GO/NO-GO 8** — 8.2, 8.3 and 8.4 refuse.

---

## §9. WhatsApp campaign and provider UAT

> **GATE 9 — OWNER AUTHORIZATION REQUIRED AT THIS POINT.**
>
> Everything from here sends real messages to a real handset. Owner decision **D3 is not yet
> resolved**: no test recipient has been identified. Before 9.3 runs, the owner must name the
> recipient, and that person must confirm they agree to receive test messages on that number.
>
> Until that is on record, **stop at 9.2** and mark §9.3–§9.7 and all of §10.3 as not run.
> That is a legitimate Phase 7 outcome: the capability is recorded as unverified rather than
> claimed.

| # | Action | Pass condition |
|---|---|---|
| 9.1 | Build an audience from a store-scoped segment | the count matches a hand-written query. It excludes contacts linked to another of the account's stores and not to this one |
| 9.2 | Create a WhatsApp campaign with an approved template and its placeholder values | the modal refuses to save without them |
| 9.3 **GATE** | Preview, then send to an audience of **one** — the named handset | the message arrives |
| 9.4 **SECURITY** | Send a MARKETING template to a contact with no grant; then a UTILITY template | refused, then allowed. The category comes from the provider's synced list, never from anything an operator types |
| 9.5 | Cancel a campaign mid-run | sends stop; the remainder is recorded as cancelled, not left `queued` reading as in-progress |
| 9.6 | Inspect recipient outcomes | per recipient: delivered / failed / refused, each with a reason |
| 9.7 | Confirm the quota decremented | cross-check against §11 |

**GO/NO-GO 9** — either the owner gave GATE 9 and 9.3–9.7 passed, or GATE 9 was withheld and
§9.3 onward is explicitly recorded as not run.

---

## §10. Platform operational notifications

**The section most likely to find something.** The delivery feed has never run against a real
Lynomia, and its subscription step is the one defect that every test on both sides missed.

### 10.1 The platform workspace

| # | Action | Pass condition |
|---|---|---|
| 10.1.1 **SECURITY** | Confirm the account named by `LYNOMIA_PLATFORM_WORKSPACE_ACCOUNT_ID` is the **platform-owned** one | `accounts.platform_owned` is true for it in Lynomia. Pointed at a merchant's account this would send from that merchant's WhatsApp channel, and Lynomia's UTILITY-only gate — which keys on this flag — would not apply either. See `PHASE-7-PREREQUISITES.md` for what Store Syria can now check programmatically and what still needs a human |
| 10.1.2 | The Super Admin notices tab displays the three configured ids | they match what you set |
| 10.1.3 | Confirm the platform plan carries `campaign_sends` | provisioning refuses without it |

### 10.2 Merchant contact identity (B4)

| # | Action | Pass condition |
|---|---|---|
| 10.2.1 | Super Admin → merchant contacts panel | a count per reason: confirmed / none on record / placeholder / unconfirmed. **On a fresh staging install most merchants are unreachable, and that is correct** |
| 10.2.2 **GATE** | A merchant requests a code for their own number | one code, by WhatsApp, sent with the **platform's** credential — never through the merchant's own `otp_integrations`, because a merchant who controls the gateway would be self-verifying. Needs GATE 9 |
| 10.2.3 | Enter the code | `users.mobile_verified_at` is set; the merchant becomes reachable |
| 10.2.4 **SECURITY** | Enter a wrong code five times | refused, and the attempt counter increments **before** the comparison |
| 10.2.5 | Ask for a second code within 60 seconds | refused by the cooldown |
| 10.2.6 | Ask for an 11th code within the hour | refused by `throttle:lynomia-contact-verify`, keyed on the **person**, not the number |
| 10.2.7 **SECURITY** | Change the number after verifying — on the **web** path, then on the **API** path | verification is forgotten on both |
| 10.2.8 **SECURITY** | Submit `+91 7878787878` | classified `placeholder`, not as a well-formed 12-digit number. The normaliser made the Super Admin placeholder look legitimate once a country code was prefixed; the classifier matches it as a suffix for that reason |

### 10.3 The delivery feed

| # | Action | Pass condition |
|---|---|---|
| 10.3.1 **SECURITY** | Already done at deploy §2.8 — re-confirm the webhook exists in Lynomia | the platform workspace carries a webhook at `https://<store-syria-staging>/lynomia/platform-events` subscribed to `message_delivery_status`. **This is the step whose absence 25 passing tests missed** |
| 10.3.2 | Delete that webhook in Lynomia; run `php artisan lynomia:reconcile-deliveries` | it comes back |
| 10.3.3 | Add an unrelated subscription to that webhook, delete `message_delivery_status`, re-run | the sweep **widens** rather than replaces: the unrelated subscription survives |
| 10.3.4 **GATE** | Send an operational notice to the verified merchant | recorded `sent` / `accepted` |
| 10.3.5 **MEASURE** | Watch the console | `provider_accepted` arrives, then `delivered`, then `read` if the handset opens it. **Record each latency.** `provider_accepted` exists as a feed event only because the hook also fires on `source_id` — a successful provider submission changes no status |
| 10.3.6 **GATE** | Withdraw the merchant's consent; send again | `sent` / **`refused`** / `consent_withdrawn`. **This is the case the entire feed exists for**: our attempt succeeded and Lynomia refused it. `status` alone cannot express it |
| 10.3.7 | Send to a merchant with no verified number | `failed` / `refused` / `no_phone_on_record` — recorded as an outcome, not left blank |
| 10.3.8 | Verify that merchant's number, then send the same occasion again | the notice reopens and the previous attempt's outcome is **cleared**, not left showing |
| 10.3.9 **GATE** | Block the ingress at the firewall; send a notice; unblock | the feed delivery is lost. Within 15 minutes `lynomia:reconcile-deliveries` resolves the outcome from Lynomia's read endpoint, and the audit row carries a `reconcile-` delivery id |
| 10.3.10 | Replay a captured delivery verbatim | 200, `applied: false`, still one event row |
| 10.3.11 **SECURITY** | Replay an older delivery after a newer one | 200, `applied: false`, `skipped: stale`; the notice does not move |
| 10.3.12 **SECURITY** | Sign a platform delivery with a **merchant's** store secret | 401 |
| 10.3.13 **SECURITY** | Send a delivery naming a different account | 404 |
| 10.3.14 | Super Admin notices tab | "Awaiting an outcome" and "Delivery reports received today" are both consistent with what was sent |

**GO/NO-GO 10** — 10.3.1 holds against a real Lynomia, 10.3.6 distinguishes our attempt from
Lynomia's refusal, 10.3.9 proves reconciliation, and 10.3.11–13 hold.

**If 10.3.1 does not work against a real Lynomia, stop the phase.** It would mean the one
defect this hardening was built to prevent is present in a different form.

---

## §11. Billing and quota

Test mode only. No real charge at any point.

| # | Action | Pass condition |
|---|---|---|
| 11.1 | A plan with a `campaign_sends` limit | the merchant's allowance screen matches |
| 11.2 | Send campaigns up to the limit | the next is refused with `allowance_exhausted`, not silently dropped |
| 11.3 | Confirm the meter counts **attempts** | the order is category → consent → allowance. A message the system itself refused was never an attempt and must not have spent a unit |
| 11.4 **SECURITY** | Remove `campaign_sends` from the **platform** plan and send | refused with `platform_allowance_unset`. A plan edit must not silently make platform sending unmetered |
| 11.5 **SECURITY** | Attempt to grant a trial from the client | refused; the rule is server-side |
| 11.6 **SECURITY** | An un-entitled merchant opens a module route | 403, not a seat |

**GO/NO-GO 11** — 11.2, 11.4, 11.5 and 11.6 held.

---

## §12. Failure, retry and recovery

> **GATE 12 — OWNER AUTHORIZATION REQUIRED AT THIS POINT (decision D5).**
>
> Every step here deliberately breaks a running staging system. D5 is approved **in principle**
> and requires an explicit execution gate at the moment of running. Confirm the §2.2 backup
> exists and is restorable before the first injection.

Restore after each one before starting the next.

| # | Injection | Pass condition |
|---|---|---|
| 12.1 | Lynomia returns 503 for two minutes | writes retry per the ladder; **reads are not retried across a timeout** — a read timeout means the answer was lost, and retrying a send posts a second reply the merchant never wrote |
| 12.2 **SECURITY** | Drop the TCP connection mid-send of an operational notice | the notice is `indeterminate` / `unknown`, **never** `failed`, and is not retried automatically |
| 12.3 **MEASURE** | Stop Store Syria for 20 minutes while events occur on both feeds | the per-store feed loses them; `lynomia:reconcile-events` and `lynomia:reconcile-consents` repair what they cover. **Write down precisely what is NOT repaired.** That list is a Phase 7 deliverable, not a footnote |
| 12.4 **MEASURE** | Skew one host's clock by 400 seconds | every delivery fails the 300-second signature window. Restore and confirm recovery. Also record the **real** skew between the two hosts under NTP — a named A1 unknown |
| 12.5 **SECURITY** | Exhaust the per-store ingress limit with signed traffic; then flood the same store's path **unsigned** | signed traffic 429s; the unsigned flood **cannot** consume the store's budget. The signature is checked before the per-store throttle precisely so an anonymous caller cannot destroy a merchant's deliveries |
| 12.6 | `LYNOMIA_INTEGRATION_ENABLED=false`, restart | no remote call from any surface or sweep. Restore |
| 12.7 | Kill the Store Syria worker mid-notice | the row is left `sending`; the console's in-flight tile shows it; nothing retries it automatically |
| 12.8 **SECURITY** | POST a 300 KiB body to each ingress | 413, before the body is parsed |
| 12.9 | Restart Lynomia's Sidekiq with webhooks queued | they deliver; nothing duplicated at the receiver |

**GO/NO-GO 12** — 12.2 records `unknown`, 12.5 cannot be aimed at a merchant, 12.8 refuses
early, and the 12.3 "not repaired" list is written down.

---

## §13. Security and performance gates

### 13.1 Security — all absolute

| # | Check | Pass condition |
|---|---|---|
| 13.1.1 | `grep` both hosts' logs for the platform token, the master secret, any SSO URL, any bearer | zero hits |
| 13.1.2 | Dump every module table and search for a secret, token or signing key | zero hits |
| 13.1.3 | Read the **booted router** on the deployed host, not the routes file | every merchant route carries `web`, `auth`, `xss`, `setlocate`, `verified`, `PlanModuleCheck:LynomiaChat` and `EnsureStoreOwnsLink` |
| 13.1.4 | Both ingress routes, unsigned | 401 |
| 13.1.5 | As merchant A, walk **every** merchant route against merchant B's store | refused at each |
| 13.1.6 | Every Super Admin surface | counts only — never another tenant's people |
| 13.1.7 | Every operator page | no merchant phone number rendered anywhere |
| 13.1.8 | TLS on both hosts | no weak ciphers, HSTS present, certificates not self-signed |
| 13.1.9 | The staging `APP_KEY` | **not** the published production one (blocker A2) |

### 13.2 Performance — produce numbers

| # | **MEASURE** | Why |
|---|---|---|
| 13.2.1 | Inbox list and conversation open, p95, ~1,000 conversations | the merchant-facing path |
| 13.2.2 | Ingress throughput at the configured limit, sustained one minute | includes HMAC cost against the 256 KiB cap |
| 13.2.3 | `lynomia:deliver-commerce-events` against a 10,000-row backlog | must finish inside its minute, or the schedule overlaps. `withoutOverlapping` protects correctness, not latency |
| 13.2.4 | `lynomia:reconcile-deliveries` with 500 unresolved notices | confirm the default `limit` of 50 is the right page size |
| 13.2.5 | **The new message hook under load** | `Delivery::StatusFeed.publish` now runs in-process on every outgoing `status` **or** `source_id` change — Lynomia's highest-volume write. Measure the per-message cost against a run with the hook disabled. **This has never been measured and the number is owed** |
| 13.2.6 | `flow_sessions` concurrent-index behaviour and table size | blocker B1. Staging cannot close it; record what you can |

**GO/NO-GO 13** — 13.1.1, 13.1.2, 13.1.5 and 13.1.7 are absolute and a failure in any of them
stops the phase. 13.2 produces numbers; a material one is a pre-production finding, not a
reason to stop the window.

---

## §14. Closing the window

1. Mark every GO/NO-GO in a signed copy of this document.
2. Assemble the artifacts `PHASE-7-READINESS-PLAN.md` §14.4 requires:
   the latency table (§5.5, §6.4, §7.5, §10.3.5), the §12.3 not-repaired list, the §13.2.5
   number, the defect list with each item classified blocking / pre-production / recorded.
3. Classify every defect found. Fix and re-verify the blocking ones **before** the phase is
   called complete.
4. Rotate `LYNOMIA_PLATFORM_TOKEN` and `LYNOMIA_WEBHOOK_MASTER_SECRET`. A staging credential
   has been handled by more people than a production one should be
   (`docs/security/SECRET-ROTATION-CHECKLIST.md`).
5. Confirm no production system was touched, no production charge created and no message sent
   to anyone other than the named GATE 9 recipient.

**Phase 7 completing closes blocker A1 and authorises nothing else.** A2, A3, A4, B1, B5 and
B6 stay open; B4 closes as merchants verify their own numbers, not on a date.
