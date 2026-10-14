# Phase 4 — Native Automation Studio, Flow Builder and Intelligent Bots

**Status: CONDITIONAL GO.** Everything in the approved scope is built, tested and verified in a
real browser. What is not ready for production is named in §18 and §19, and none of it is
inside Phase 4's remit to fix.

---

## 1. What was asked, and what was built

A merchant opening Store Syria can now build, test, publish and watch automations without ever
seeing Lynomia's own interface — and without Store Syria owning a single line of automation
engine. Lynomia's flow runtime, graph validator, automation rules, consent gate and WhatsApp
template machinery are reused exactly as they are; Store Syria adds a client, a contract, and
the authorization boundary between them.

| Sub-phase | Delivered |
|---|---|
| **P4A** | Capability matrix: 301 capabilities, 14 subsystems, file:line evidence, adversarial panel. Two gaps closed before building (lost-update protection, channel eligibility). |
| **P4B** | Automation Studio: list, create, rename, duplicate, publish, turn off, delete, usage against the plan's ceiling. |
| **P4C** | Visual Flow Builder: dependency-free SVG canvas, palette and configuration forms built from the server's own schema, validation per step, draft save under the optimistic lock, publish, Test Mode. |
| **P4D** | Channels: which channels can run an automation, what each is running, connect and disconnect. |
| **P4E** | Commerce automation rules, and a consent surface with its own permission. |
| **P4F** | Validated starter templates, and an execution-history screen answering "is it working" and "what happened". |
| **P4G** | Super Admin tab, a 16-test security sweep, a per-person rate limit, adversarial review, this report. |

---

## 2. The three findings that shaped the architecture

From the P4A matrix, each verified at code level before anything was built:

1. **A flow is an `AgentBot`, and the platform credential cannot create one.** `bot_type` is not
   a permitted platform parameter and cannot be changed afterwards. Every Studio call therefore
   uses the **per-merchant-person scoped credential** Phase 2 already mints — which is the
   correct boundary, not a workaround, because it means Lynomia's own `AgentBotPolicy` decides
   what each person may do.
2. **Lynomia's Vue editor cannot be lifted.** Its condition editor throws without nine populated
   Vuex modules; its API client scrapes the account id out of `window.location.pathname`; it
   authenticates with a cookie a Store Syria page does not have. And the shortcut is worse than
   the problem: brokering administrator tokens to merchants would hand them the whole workspace.
3. **Store Syria has no working JS build.** `vite build` cannot succeed, `@vite` appears zero
   times in 1,232 Blade files, and there is no node-graph library of any kind. The builder is
   therefore SVG and pointer events in Blade, Bootstrap 5 and jQuery — the stack the dashboard
   actually renders with.

---

## 3. The architectural decisions, and how each was honoured

| Decision | How |
|---|---|
| Build a fully native Studio inside Store Syria | Five merchant pages in the dashboard's own stack. No iframe, no redirect to Lynomia. |
| Reuse Lynomia's existing engines | Store Syria contains no flow runtime, graph validator, scheduler, rule engine or consent gate. It renders and authorizes. |
| Never expose privileged credentials to browsers | Every call goes through `MerchantInboxGateway`; the platform token mints the per-person credential and serves no merchant request. A security test asserts no Phase 4 controller can even name `PlatformApiClient`, and that no response carries a token. |
| Preserve per-user identity and real Lynomia authorization | The credential carries the person's own Lynomia identity and role. Only the store owner holds an administrator identity, so only they can manage flows — and the Studio says so in one sentence instead of showing a bare 403. |
| A dependency-free SVG editor is acceptable if genuinely usable | 44 browser assertions say it is: nodes drawn, handles per choice, drag, connect, keyboard nudge and delete, inspector from the served schema, save, conflict, publish, Test Mode. |
| Do not silently reduce the Flow Builder's functionality | All 22 node types are offered, with a generic configuration form driven by a new served field schema. The condition editor is driven by the served vocabulary; the template picker by the served catalogue. |
| Treat channel limitations honestly | A channel that cannot run an automation is listed and **said to be unable**; connect is offered only where it would work; a trigger that cannot fire is shown disabled with its reason. |
| Preserve the optimistic concurrency protection | `lock_version` is required at the BFF boundary, a 409 returns the other editor's graph and a fresh token, and the merchant chooses. Four browser assertions and four PHPUnit tests. |
| Maintain Arabic/English and correct RTL | 527 keys in each of `en.json` and `ar.json`, full parity, plus a namespaced group for four words the host's dictionary was hijacking. Verified in a browser at desktop and phone width. |
| Keep administration in the Super Admin integration pages | One new tab on the existing page. It reports who can build automations and names what stays in Lynomia, rather than duplicating a switch. |

---

## 4. What Lynomia gained (8 commits)

Everything here is a **contract**, not an engine. No runtime behaviour changed except the one
defect fix.

| Commit | What |
|---|---|
| `8451f0ca` | A counted `flows` ceiling through the existing `BillingPlan::LIMIT_KEYS` resolver, so `GET /billing/entitlements` answers the usage question with no new endpoint. Plus a server-side `POST /flows/:id/duplicate`. |
| `c02e2cde` | **Defect fix** — a disabled flow lost its graph on the next edit. Plus `state` so a disabled flow and a brand-new one stop being indistinguishable. |
| `2cf66f15` | `node_fields`: the shape of every node type's `data`, every limit read from the validator's own constant. Plus the per-flow sendable-template catalogue, sharing the validator's own candidate rule. |
| `c8b1a6aa` | `GET /automation_rules/vocabulary`: the condition, trigger and action vocabularies, read from `filter_keys.yml`, the account's own attributes, and this fork's audience and Commerce keys. |
| `e451ed0e` | `GET /flows/channels`: which inboxes can run a flow and which one each is running. |
| `fddaef17` | A consent read/write surface, and triggers that say whether they can fire for this account. |
| `b2a3784e` | `Automation::ActionFields`: the shape of each action's params, and which a Commerce trigger refuses. |
| `da105fbc` | `GET /flows/starters` (validated by a spec against the real validator) and `GET /flows/:id/insights`. |

**The anti-drift principle, applied everywhere:** no number, enum or limit is written twice. The
field schema reads `Flows::NodeValidator::REPLY_TYPES`, `MAX_DELAY_SECONDS` and
`ChannelCapabilities::WHATSAPP`; the template catalogue calls
`Flows::TemplateValidator.candidates`; the action schema reports
`Custom::AutomationRule::CUSTOMER_MESSAGE_ACTIONS`. A tripwire spec asserts `node_fields` covers
every data key of every node type **and no others** — a key the validator checks but the schema
omits is a setting no merchant can reach, and a key the schema offers but the validator rejects
is a field that fails with `unknown_field` on save. Both are silent; both are now caught.

---

## 5. What Store Syria gained (5 commits + P4G)

| Commit | What |
|---|---|
| `164490d5` | The Automation Studio, `ProxiesToLynomia` extracted from the Phase 2 inbox, `LynomiaHttp::sendAllowing`, the `Manage Lynomia Automation` permission. |
| `7e40fabf` | The visual Flow Builder — a 1,900-line Blade page with an SVG canvas and a generic inspector. |
| `800f77f3` | The channels panel. |
| `ef2b064c` | Commerce automation rules and the consent tab, with `Manage Lynomia Consent`. |
| `11e1f0ff` | The template gallery and the execution-history screen. |
| P4G (staged) | The Super Admin Automation tab, the per-person rate limit, `LynomiaPhase4SecurityTest`. |

---

## 6. Defects found and fixed during Phase 4

Twenty-four. Nine during construction (below), and fifteen more in a final adversarial review of
the whole diff in both repositories (§6b). **Eleven of the twenty-four were found only by opening
the page in a real browser** and were invisible to PHPUnit. That ratio is the single most useful
thing to report.

| # | Defect | Found by | Fix |
|---|---|---|---|
| 1 | A disabled flow's graph was silently discarded on the next edit — reopening handed the editor a blank two-node starter, and the next save wrote it over the draft. | Writing the Studio's lifecycle view, then reproduced with a probe spec | `create_draft` falls back to the latest version, ordered by `version` |
| 2 | A disabled flow and a never-touched one were indistinguishable over the API. | Same | `state` on `flow_json` |
| 3 | Blade compiled the variable picker's `{{ name }}` token as one of its own echo tags; the picker inserted `+ name +` into the merchant's message. With Blade's `@` escape, the `@` survived into the message. | Browser | Braces concatenated so Blade never sees a tag; a test forbids the literal |
| 4 | Connecting two steps with a mouse did nothing — `setPointerCapture` retargets later pointer events to the capturing element, so `event.target` on `pointerup` was the canvas. | Browser | Resolved by position with `elementFromPoint` |
| 5 | Delete removed the wrong step — Tab moves focus without selecting, so a keyboard user deleted whatever they last clicked. | Browser | The keydown handler acts on the focused step |
| 6 | The host's 2,848-key `ar.json` hijacked four of the module's words: `Live` rendered as **حياً** ("alive"), `Off` as **أطفئ** (the imperative "turn it off!"), Yes and No carried trailing dots. | Browser, reading the Arabic page | Those four moved to a namespaced PHP group the flat JSON cannot override |
| 7 | Bootstrap swallows a modal shown while another is still hiding — Connect on a taken channel closed the panel and the confirmation never appeared, so the replacement silently did not happen. | Browser | Dialogs hand over on `hidden.bs.modal` |
| 8 | Changing a rule's trigger to one that forbids an action left that action in the draft while the select displayed something else — the form said one thing and submitted another. | Browser | Forbidden actions dropped on the switch, and the merchant told which went |
| 9 | The builder's history table rendered nothing and reported no error: it called `formatDate` and `PAGE_LOCALE`, which existed only on the Studio page. | Browser | Both defined in the builder |

### 6b. Found by the final adversarial review of the diff

Two independent reviews read the complete Phase 4 diff in each repository with no knowledge of
the other's findings. Every item below was reproduced before it was fixed — through the real
route, in a real browser, or with `rails runner` against the real objects — and every one is
now covered by a test that fails without the fix.

**Store Syria**

| # | Defect | Severity | How it was reproduced |
|---|---|---|---|
| 10 | **A flow with no connections could not be saved at all.** Laravel's `required` refuses an *empty* array, so `graph.edges => required` meant "send me at least one edge". A brand-new flow is one start step and nothing else; a starter graph arrives unwired. Save, Test and Publish all funnel through that call, so all three failed — and the 422 is Laravel's `{message,errors}` shape while the builder reads `body.error`, so the merchant got "please contact support". | **High** | `PUT …/flows/42/draft` with `edges: []` through the real route → 422 |
| 11 | **Two choices could share one option id**, and an option id is an output handle: the duplicate drew over the first handle's position and could never be routed anywhere. `'o' + length` reissued a live id as soon as an earlier choice was deleted. | Medium | Chromium: add 3, delete the first, add → `o2,o3,o3` |
| 12 | **Test Mode froze on an ellipsis** when the pre-save was refused — the explanation went to the page alert strip, behind the modal backdrop. | Medium | Chromium with a 422 draft stub |
| 13 | **Confirming raced the dialog's own teardown.** Bootstrap strips `show` synchronously inside `hide()` but removes the backdrop ~150 ms later, so `whenHidden` — written to prevent exactly this — took its immediate branch at the one site that hides before acting, and the reopened Channels panel lost `modal-open` underneath itself. | Low | Chromium: `hidden.bs.modal` traced firing after the assertion window |
| 14 | **"Stores covered" read 0 for the grant that reaches everyone.** The seeder grants one global `admin` role with a NULL `store_id`, and `COUNT(DISTINCT store_id)` skips NULL — the broadest grant on the installation rendered as the narrowest. | Medium-low | Controller called directly with the production grant shape |
| 15 | **An English sentence reached Arabic dashboards.** `KIND_CONFLICT` is the one kind passed through verbatim; two `fromStatus()` literals were not translated. | Low | Read against `merchantMessage`'s mapping |
| 16 | **The browser harness was verifying a stale build.** `render-studio-pages.php` wrote to one directory while the server served another, with nothing to say so. The first run of fixes 10–15 passed every check *against the pages from before them*; the duplicate-id check only failed once the server was pointed at the fresh render. | **Process** | `curl` of the served page still showed the old minter |

Fix 16 is the one worth dwelling on: a verification harness that can silently verify the wrong
build is worse than no harness, because it produces evidence. `tests/Browser/run.sh` now renders,
serves and runs from one derived root, so the three paths cannot drift apart again.

**Lynomia**

| # | Defect | Severity | How it was reproduced |
|---|---|---|---|
| 17 | **Custom attributes were offered two operators each, whatever their type.** The server has no opinion here — `ConditionValidationService` checks only that the attribute exists — so the real set is the dashboard's `getOperatorTypes`. The Studio was withholding `is_present`/`is_not_present` on text attributes and `is_greater_than`/`is_less_than` on dates: a silent reduction of functionality Lynomia's own UI offers. | Medium | Read `automationHelper.js` against the served payload |
| 18 | **`commerce_event_store` had no picker**, so a client drew a free-text box whose every value `own_stores?` rejects as `invalid_store`. | Medium | Read against `event_errors` |
| 19 | **Three Commerce conditions were declared `text`** while being compared with `is_greater_than`, `days_before` and a boolean `equal_to`. A client cannot draw a number, date or yes/no input for a field it is told is text. | Medium | Read against `CommerceCondition::FIELDS` |
| 20 | **Three keys were served twice** (`labels`, `created_at`, `last_activity_at`) under contradictory scopes. The validator resolves conversation-first, so the contact copy can never be the one evaluated. | Low | `rails runner` tally: each key appeared twice |
| 21 | **The insights panel was five sequential scans.** All five aggregates filter on `agent_bot_id` + `created_at` and no index leads with `agent_bot_id`; the nearest, `(account_id, agent_bot_id, status)`, cannot serve a query that does not name the account, and the association does not. | Medium | `db/schema.rb` index list |
| 22 | **A starter graph shipped with a duplicate output.** `welcome_menu`'s third button used the id `other`, which is the reserved no-match handle. | Medium | `rails runner`: outputs `["order","product","other","other","timeout"]` |
| 23 | **`duplicate_name` read `params[:name]` directly**, so `name[]=x` became the literal `["x"]` as a flow name. Also: `channels` resolved each flow's lifecycle once per attached *inbox*; `TemplateValidator.candidates` loaded inboxes without their channels (a query per candidate, on every draft save); the event vocabulary made eight identical remote round trips per response. | Low | Read, each against its own caller |
| 24 | **A consent filter the server did not understand was dropped, not refused** — so "show me who opted out" answered with every decision, and the opt-ins read as opt-outs. | Low | `GET …/consents?state=opted_out` → 200 with the full list |

**One review claim was examined and rejected rather than acted on.** A `RecordNotUnique` was
reported as reachable on concurrent draft/publish. It is not: `Flows::Versions#draft` is a
correct double-checked lock inside `@bot.with_lock`. The capability matrix was corrected
instead of adding a guard for a case that cannot occur — per the repository's own rule against
speculative defences.

---

## 7. The financial status fix (accepted, kept isolated)

Commit `50bea59a`, `fix(finance): recognise 'succeeded' as a paid order status` — a **single
behavioural line** in `app/Services/OrderFinancialService::normalizePaymentStatus()`:

```php
'paid', 'success', 'succeeded', 'completed' => 'paid',
```

- **The defect:** `succeeded` is written by the Stripe-family gateways and was falling through to
  `default => 'unpaid'`, so a genuinely paid order was classified unpaid in financial records and
  in the commerce API Lynomia reads.
- **Narrowed correctly.** Every `payment_status` write in `app/` and `packages/` was partitioned
  by owning model first, which is what stopped the obvious over-fix: `Approved`/`Rejected`/
  `Failed` belong to `PlanOrder` and `On Hold`/`Pending payment` to `PartialPayments`, and
  mapping those here would have corrupted two unrelated rails.
- **Tested:** `tests/Feature/Commerce/PaymentStatusNormalizationTest.php`, 41 tests — an
  exhaustive mapping dataprovider, near-miss cases, agreement with
  `CommerceReader::PAYMENT_STATUSES`, and a **census tripwire** that scans `app/` and `packages/`
  for payment-status writes so a new one cannot be added silently.
- **No backfill was performed.** `docs/finance/PAYMENT-STATUS-BACKFILL-ASSESSMENT.md` records the
  root cause, the affected providers, read-only sizing SQL and the four accounting decisions an
  owner must make. Phase 4 did not expand into it, as instructed.
- **Scope honoured:** no gateway was redesigned, no `.env` or `APP_KEY` touched, no real
  transaction executed, no unrelated module modified.

---

## 8. Capability reuse — what was NOT built, deliberately

Store Syria contains **no** flow runtime, graph validator, node executor, session store,
scheduler, automation rule engine, condition evaluator, template renderer, consent gate or
messaging engine. Every one of those is Lynomia's and is called, not reimplemented. Also not
built, on purpose:

- **No second consent store.** A copy on this side would be a second answer to "may we message
  this person", and the gate would keep reading the other one. A security test forbids
  `MessagingConsent`, `DB::table` and `Schema::` in the Studio's controller.
- **No second validator.** The BFF checks the shape of a *request* and forwards a well-formed one
  to be judged where the rules live.
- **No per-node execution trace.** `insights` and `sessions` answer at the session level. A true
  per-node trace needs new storage and was out of scope; it is named in §19.
- **No checkout opt-in capture.** See §19.

---

## 9. Authorization and tenancy

Five layers on every merchant route, read off the **booted router** in tests, never from the
routes file:

```
web (session + CSRF) / auth / xss / setlocate / verified
PlanModuleCheck:LynomiaChat              entitlement — does this tenant's plan include the module
Permission:Manage Lynomia Automation     authorization — may THIS user
Permission:Manage Lynomia Consent        and, for consent, may they decide who is messaged
EnsureStoreOwnsLink:existing             tenancy — is this the tenant's store, and is it linked
throttle:lynomia-studio                  budget, keyed on the person
```

**No identifier in any Studio signature names a tenant.** A flow id, inbox id, rule id and
contact id are accepted and deliberately not trusted: the credential is scoped to one workspace
server side, so another store's id resolves to nothing. A test asserts no route takes an
`account`, `store` or `link` parameter.

**The permissions narrow and cannot widen.** A staff member holding every Store Syria permission
still carries an *agent* identity in the workspace; Lynomia refuses them, and the Studio reports
"Only the store owner can manage automations." Both halves are tested.

**A refused request mints no identity.** Reaching the gateway is what creates a Lynomia user for
someone, so a request refused by the permission layer must stop before it — tested.

---

## 10. Security review

`tests/Feature/Lynomia/LynomiaPhase4SecurityTest.php` — **16 tests, 537 assertions**, written as
properties rather than behaviours so they keep holding as the code moves:

- no Phase 4 controller resolves a tenant for itself (`getCurrentStore`, `current_store`,
  `creatorId`, `Auth::user` all forbidden in those files);
- no Studio route takes an account, store or link from the caller;
- every Studio route carries all six gates, with a floor on the route count so the surface
  cannot silently shrink;
- no Phase 4 controller can reach the platform credential;
- no Phase 4 page mentions a credential, and no Studio response carries the scoped token or a
  bot secret (nine endpoints × five secrets);
- no page renders merchant text as HTML (`.html(`, `innerHTML`, `insertAdjacentHTML`,
  `document.write`, `eval`, `new Function` all forbidden);
- no page addresses Lynomia directly;
- a Store Syria permission cannot grant what Lynomia refuses;
- consent is never stored on this side, and a merchant cannot record a provider suppression;
- nothing in the Studio enables a provider or sends a message;
- the Super Admin page duplicates no Lynomia switch.

**One hardening item was added during the review.** The Studio is the first surface that
*amplifies*: an inbox request is roughly one Lynomia call, while opening the builder is the flow
plus four reference reads plus the condition vocabulary. `throttle:lynomia-studio` caps it at 240
requests per minute **per person** — keyed on the person because the cost is per credential
minted, and keying on the store would let one person's stuck poll exhaust their colleagues'.

---

## 11. Tests

New Phase 4 test files on the Store Syria side:

- `tests/Feature/Lynomia/LynomiaAutomationStudioTest.php` — 22 tests, 165 assertions
- `tests/Feature/Lynomia/LynomiaFlowBuilderTest.php` — 27 tests, 191 assertions
- `tests/Feature/Lynomia/LynomiaCommerceAutomationTest.php` — 17 tests, 113 assertions
- `tests/Feature/Lynomia/LynomiaPhase4SecurityTest.php` — 18 tests, 549 assertions
- Lynomia: `spec/services/flows/starters_spec.rb`, `spec/requests/automation/vocabulary_spec.rb`,
  `spec/controllers/api/v1/accounts/commerce/consents_controller_spec.rb`, plus additions to
  `flows_controller_spec.rb`, `versions_spec.rb` and `resource_limit_spec.rb`.

---

## 12. Suite results

All figures below are from the final runs on the committed heads, after the §6b fixes.

### Store Syria

| Run | Result |
|---|---|
| `phpunit --filter Lynomia` (all 15 Lynomia suites, Phase 1–4) | **295 tests, 4,404 assertions, 0 failures** |
| `phpunit` (whole suite) | **1,929 tests, 12,936 assertions — 8 failures, 17 skipped, 0 errors** |
| `.github/scripts/assert-known-test-outcomes.php` | **PASS** — "the observed outcome set matches the manifest exactly" |

The 8 failures and 17 skips are the **pre-existing, declared** set: the GATE F
`owner_menu_settings` family and the drifted tenancy censuses, each recorded in
`.github/known-test-outcomes.json` with a reason and an owner. Phase 4 added none of them and
reclassified none of them. The gate refuses to run at all unless **both** `--log-junit` and
`--log-teamcity` are supplied, so dropping one CI flag cannot silently disable the skip-reason
half of the check.

### Lynomia

| Run | Result |
|---|---|
| `spec/requests/automation/vocabulary_spec.rb` | 17 examples, 0 failures |
| `spec/controllers/api/v1/accounts/flows_controller_spec.rb` | 33 examples, 0 failures |
| `spec/controllers/api/v1/accounts/commerce/consents_controller_spec.rb` | 9 examples, 0 failures |
| `spec/services/flows` + `spec/models/billing` (with the above) | **141 examples, 0 failures** |
| **`spec/services spec/controllers spec/requests spec/models/billing`** | **6,937 examples, 0 failures, 46 pending** (27m15s) |
| `rubocop` on every changed file | clean |

**The broad sweep completed and is green.** All 46 pending examples are the same environment
guard — `skip('Skipping since MFA is not configured in this environment') unless
Chatwoot.encryption_configured?` — declared in five spec files
(`spec/services/mfa/authentication_service_spec.rb`, `spec/services/mfa/token_service_spec.rb`,
`spec/controllers/super_admin/devise/sessions_controller_spec.rb`,
`spec/controllers/devise_overrides/sessions_controller_spec.rb`,
`spec/requests/api/v1/profile/mfa_controller_spec.rb`). That is a property of this container,
which has no MFA encryption key, and is unrelated to Phase 4: it is the *only* skip condition
present anywhere in the swept tree. **Zero failures and no regression of any kind.**

---

## 13. Browser verification

Chromium 1194 via Playwright, headless, driving the **real Blade views rendered by the real
application** with the real translations and the real route table. Harnesses are committed under
`tests/Browser/` so the run is repeatable:

| Harness | Assertions | Covers |
|---|---|---|
| `p4c-flow-builder.mjs` | 49 | canvas, palette, inspector from the served schema, drag, connect, keyboard, save, the 409 conflict, publish errors, Test Mode (including a refused pre-save), option-id minting after a deletion, Arabic RTL, 390px phone |
| `p4d-channels.mjs` | 14 | eligibility shown not hidden, connect/disconnect, the replacement confirmation, the panel surviving the dialog's teardown, Arabic phone |
| `p4e-commerce-automation.mjs` | 25 | the unavailable trigger, offerable actions, the dropped-action defect, consent notes, the absent tab, Arabic phone |
| `p4f-templates-history.mjs` | 15 | templates listed with what they need, the graph actually saved, stop reasons as sentences, Arabic phone |
| **Total** | **103** | all passing, 0 uncaught JavaScript errors |

**Run them with `tests/Browser/run.sh`, not by hand.** It renders the pages, serves them and runs
the harnesses from one derived root. That is not convenience — rendering and serving used to be
two steps with two different directories, and defect 16 in §6b is what that cost.

`docs/development/lynomia-integration/PHASE-4C-BROWSER-VERIFICATION.md` records exactly what the
harness does and does not cover. **What it does not cover: a live Lynomia.** Every API response
in every run is a fixture shaped to match the controller's own projections.

---

## 14. Arabic, RTL and mobile

- `en.json` and `ar.json` both hold **527 keys, at full parity**; the only value identical to its
  key is a format placeholder.
- Four words moved to a namespaced PHP group because the host's own dictionary was answering for
  them with something else — found by reading the Arabic page, not the files.
- **The chrome flips and the canvas does not,** deliberately: a left-to-right graph would have to
  be mirrored for Arabic, and mirroring means either rewriting every stored `x` on load (so the
  same flow has two different graphs) or drawing it backwards from what is saved. The flow runs
  top to bottom, which reads the same in both directions. A browser assertion pins that the start
  node is at `translate(140,40)` in Arabic exactly as in English.
- No horizontal page scroll at 390 px on any of the four pages, in either language.
- Keyboard: Tab between steps, Enter to configure, Delete to remove, arrows to nudge — all
  verified in a browser.

---

## 15. Production restrictions honoured

Nothing in Phase 4 was deployed, merged, activated or connected.

| Restriction | Status |
|---|---|
| Do not modify `.env`, `APP_KEY`, production credentials | Untouched. No credential created, rotated or read into any new store. |
| Do not deploy, merge or activate production messaging | Nothing deployed or merged. The Studio sends no message; it edits a definition Lynomia runs. |
| Do not connect live provider accounts | None connected. |
| Do not bypass provider UAT restrictions | `commerce_cart_abandoned` is reported **unavailable** for Store Syria precisely because its recovery is PRE_UAT. Nothing sets `COMMERCE_ALLOW_PRE_UAT_PROVIDERS`; a security test forbids the string in Phase 4 sources. |
| Do not disable existing security controls | None disabled. One added (the Studio throttle). |
| Do not modify unrelated product modules | The only files outside `packages/workdo/LynomiaChat/` are `tests/`. |
| Do not rebuild Lynomia's automation engine | Nothing of the kind exists on the Store Syria side. |
| Do not begin Phase 5 | Not begun. |
| Existing accepted failures not reclassified | The known-outcome manifest is unchanged; see §12. |

---

## 16. The traps the capability matrix named, and what was done about each

| Trap | Outcome |
|---|---|
| Mirroring the dashboard's `ACTION_TYPES` (contains `add_sla`, refused by the backend) | Not mirrored. Actions come from the server, and only those with a described field schema are offered. |
| Offering `change_status` (accepted but swallowed on an invalid value) | Not offered — it has no entry in `Automation::ActionFields`. |
| Offering `commerce_cart_abandoned` to a Store Syria merchant | Shown, **disabled**, with the reason. |
| Copying `flowGraph.js`'s hardcoded `NODE_GROUPS` / `NODE_ICONS` | Not copied. The palette is driven by the served catalogue; a type this build does not know still appears, under its raw id, rather than vanishing. |
| Listing bots without filtering `account_id` | The Studio lists only `Current.account.agent_bots.flow`. Connecting resolves the flow through an account-scoped endpoint **before** attaching, so an installation-wide bot cannot be attached; the ORDER of those two calls is asserted. |
| Reusing the installation-wide flow-failure signal on a merchant screen | Not reused. `insights` is per flow, per account. |
| Reusing the `data-ajax-popup` modal pattern (handler registered twice → double writes) | Not used. The Studio's dialogs are plain Bootstrap modals created once. |

---

## 17. Remaining API gaps from P4A, and their disposition

| Gap | Disposition |
|---|---|
| 1. Usage / entitlement endpoint | **Closed without a new endpoint** — `flows` became a `LIMIT_KEYS` resource, so the existing `GET /billing/entitlements` answers it. |
| 2. Execution detail (per-node trace) | **Partly closed.** `insights` plus the session list answer "is it working" and "where did it stop"; a true per-node trace needs new storage and is listed in §19. |
| 3. Trigger and action catalogues over HTTP | **Closed** — `GET /automation_rules/vocabulary`. |
| 4. Server-side duplicate | **Closed** — `POST /flows/:id/duplicate`, atomic. |
| 5. `RecordNotUnique` unrescued on the draft/publish race | **Re-examined and found not to be reachable.** `Flows::Versions#draft` is a correct double-checked lock inside `@bot.with_lock`, and `publish!` holds the same lock; the version number is computed under it. The matrix entry was a panel finding that did not survive verification, and is corrected here rather than acted on — adding a rescue for an unreachable path is the kind of speculative guard this codebase's own guidance forbids. |

---

## 18. Known limitations

1. **No live Lynomia was exercised.** Every test, in both repositories and in the browser, runs
   against the other side's contract as this work understands it. The contract is pinned by
   specs on the Lynomia side and by a scriptable fake on the Store Syria side, and the two were
   written from the same reading of the code — but an end-to-end run against a deployed Lynomia
   has not happened and is the first thing a staging environment should do.
2. **GATE F still blocks end-to-end financial testing.** Fifteen tables that live code reads and
   writes have no migration; six of them carry money. Unchanged by Phase 4 and unchanged by the
   financial fix, whose test file asserts the tables are still absent so the limit stays visible.
3. **The `APP_KEY` exposure remains an accepted, unresolved operational blocker.** Phase 4 added
   no new secret to the database and persists no credential, which is the only mitigation
   available from inside this scope.
4. **Store Syria still has no working JS build.** The builder works because it needs none. Any
   future work that wants a bundler has to fix `resources/js/app.js` first.
5. **Test Mode saves the draft first.** Running an unsaved graph is not possible — the simulator
   runs the stored draft — so pressing Test commits what is on screen. It is announced in the
   dialog, but it is a behaviour a merchant could be surprised by.
6. **The condition editor supports one value per condition.** The engine accepts a list; the UI
   writes a single-element array. Adequate for every condition the vocabulary marks as
   enumerated or id-valued, and a limitation for free-text `contains`-style conditions.
7. **A billed plan can no longer be given a flows ceiling.** Making `flows` a
   `BillingPlan::LIMIT_KEYS` resource — which is how the P4A usage gap was closed without
   adding an endpoint — puts it under `refuse_narrowing_while_billed`, so an operator cannot
   introduce a flows limit on a plan that already has paying accounts. That is the rule working
   as designed (a ceiling applied retroactively would break live workspaces), but it means a
   flows limit has to be set on a new plan, and it is worth knowing before somebody tries.
8. **The condition vocabulary still makes one Redis read per Lynomia key.**
   `LynomiaCondition#available?` calls `Automation::Extensions.enabled?` per instance, about
   twelve times per response. The eight duplicated *event* lookups were removed; removing these
   means either request-local memoisation on a shared class or restating `available?`'s rule in
   a second place, and neither was worth it for twelve sub-millisecond reads on an endpoint
   called once per page load. Recorded rather than fixed.
9. ~~A broad Lynomia `rspec` sweep did not complete inside this session.~~ **Resolved.** The
   sweep completed: 6,937 examples, 0 failures, 46 pending, all 46 being the single
   MFA-encryption-not-configured environment guard. See §12.

---

## 19. Not built, and recommended for Phase 5

- **Checkout opt-in capture.** The consent model and its API are complete and a merchant can
  record a decision; what does not exist is a tick-box on Store Syria's own checkout that writes
  a `granted` promotional consent with evidence. Building it means editing the live storefront
  checkout, which is a bigger blast radius than Phase 4's remit and deserves its own change.
- **Per-node execution trace.** Needs a new table and a write per node execution; worth the cost
  only once flows are running in production and somebody is debugging one.
- **Multi-value conditions** in the rule and flow condition editors.
- **A `send_webhook_event` and attachment action surface**, each with its own authorization story.

---

## 20. Production-readiness assessment

**Ready, once a staging run against a real Lynomia passes.**

| Dimension | Assessment |
|---|---|
| Authorization | **Strong.** Six gates per route, asserted off the booted router; permissions proven to narrow and not widen; no tenant identifier accepted from a caller. |
| Credential handling | **Strong.** No credential persisted, logged or sent to a browser; nine endpoints asserted clean of five known secrets. |
| Data exposure | **Strong.** Every response projected; no channel credential, agent email, external identifier, conversation id or message content crosses the boundary. |
| Correctness under concurrency | **Good.** The optimistic lock is preserved end to end and the conflict path is exercised in a browser. |
| Honesty of the interface | **Strong**, and tested as such: unavailable triggers, ineligible channels and undescribed actions are each shown-and-refused or withheld, never offered-and-broken. |
| Localisation | **Good.** Full parity, verified in a browser, with the host-dictionary hazard found and closed. |
| Load | **Adequate.** The amplification is capped per person. No load test was run. |
| Observability | **Adequate.** Outcome counts, failure reasons and the last 50 runs per flow. No alerting. |
| End-to-end confidence | **Unproven.** See §18.1 — this is the one dimension that is not ready, and no amount of work inside these two repositories can change it. |

**Recommendation:** deploy to staging with a real Lynomia, run the four browser harnesses against
the deployed pages, create and publish one flow per starter template on a test WhatsApp number,
and confirm the consent gate refuses a promotional send to a contact with no grant. Then go.

**Gates still owed before merge, none of them blocked on anything:**

1. ~~A full `rspec` run on Lynomia's CI.~~ **Done** — 6,937 examples, 0 failures (§12).
2. `rails db:migrate` on a production-shaped database for
   `20261020000002_add_agent_bot_created_at_index_to_flow_sessions`. It is written
   `disable_ddl_transaction!` + `algorithm: :concurrently` so it does not lock the table, and
   it ran clean against the test database — but a concurrent index build on a large
   `flow_sessions` is the one migration here that deserves to be watched.
3. The staging run above.

**What would make this NOT ready, and is not the case:** an unproven authorization story, a
credential reachable from a browser, a UI that offers what the server refuses, or a test suite
whose failures nobody has accounted for. Each of those was checked and each came back clean.
The one genuinely unproven dimension is end-to-end against a live Lynomia, and that is a
staging task rather than a code task.

---

## 21. Commits

**Lynomia** (`claude/store-syria-lynomia-integration-1oa1tt`), 8 commits from `b9bd6893`:

```
8451f0ca  feat(flows): a flow ceiling the Studio can read, and a server-side duplicate
c02e2cde  fix(flows): keep a disabled flow's graph, and say which of three states it is in
2cf66f15  feat(flows): serve the field shapes and template catalogue a builder needs
c8b1a6aa  feat(automation): serve the condition, trigger and action vocabularies
e451ed0e  feat(flows): say which channels can run an automation and what each is running
fddaef17  feat(commerce): a consent surface, and triggers that say whether they can fire
b2a3784e  feat(automation): describe what each action takes, and which a Commerce trigger refuses
da105fbc  feat(flows): starting points the server has checked, and how a flow is running
```

**Lynomia**, review round:

```
88630724  fix(automation): serve the vocabulary the server really accepts, and index the
          insights scan        -- §6b items 17-24
```

**Store Syria** (`claude/store-syria-lynomia-integration-1oa1tt`), 5 commits from `6571a09e`
plus P4G:

```
164490d5  feat(lynomia): the native Automation Studio -- listing, lifecycle, permissions and usage
7e40fabf  feat(lynomia): the native visual Flow Builder
800f77f3  feat(lynomia): connect an automation to a channel, honestly
ef2b064c  feat(lynomia): commerce automation rules and a consent surface
11e1f0ff  feat(lynomia): start from a template, and see how an automation is running
ce88f8b9  P4G: Super Admin automation tab, a Studio budget, and the Phase 4 security sweep
634c5aed  fix(lynomia): unbreak the first save of every flow, and three Studio defects
          behind it        -- §6b items 10-16
```

**The financial fix, isolated as instructed:** `50bea59a  fix(finance): recognise 'succeeded' as
a paid order status` — one behavioural line, 41 regression tests, a backfill assessment document,
and no backfill performed.
