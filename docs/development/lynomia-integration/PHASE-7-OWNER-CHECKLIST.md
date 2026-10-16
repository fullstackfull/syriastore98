# PHASE 7 — OWNER CHECKLIST, AND THE AUTHORIZATION BEING REQUESTED

**Preparation is complete. Nothing has been deployed, connected, activated or sent. No staging
system exists yet. Nothing in this work is an end-to-end test and none of it closes blocker
A1.**

This is the one page to act from. Everything else is detail behind it:

| | |
|---|---|
| What must exist and be configured | `PHASE-7-PREREQUISITES.md` |
| How to deploy it | `PHASE-7-RUNBOOK-DEPLOY.md` |
| What to verify once deployed | `PHASE-7-RUNBOOK-TEST.md` |
| The two boundaries and their gaps | `PHASE-7-SECURITY-BOUNDARIES.md` |
| Scope and acceptance criteria you approved | `PHASE-7-READINESS-PLAN.md` |
| Everything still open | `PRODUCTION-READINESS-BLOCKERS.md` |

---

## 1. Infrastructure somebody has to provide

None of this can be produced from inside these repositories.

| # | Item | Notes |
|---|---|---|
| 1.1 | A staging host for Store Syria: separate database instance, separate Redis, public HTTPS, valid certificate | **not a separate schema on the production server.** The failure this phase is most likely to produce is a staging misconfiguration pointing at production data, and a schema boundary does not survive a wrong `DB_DATABASE` |
| 1.2 | A staging host for Lynomia: the same, plus Sidekiq | |
| 1.3 | Both reachable from each other over **public HTTPS with real certificates** | a tunnel or self-signed cert changes the TLS path, which is one of the unknowns A1 names. A staging run over a tunnel does not close that part of it |
| 1.4 | NTP on both hosts | the signature window is 300 seconds and **Lynomia does not retry an account webhook** — a delivery rejected for skew is destroyed, not delayed |
| 1.5 | A queue worker **and** a cron running `schedule:run` on Store Syria | five sweeps depend on it. Without the scheduler most of the test runbook passes and §12 fails silently |
| 1.6 | A dedicated non-production WhatsApp number, with templates approved on it | D2. Meta quality ratings are per number and a UAT run can damage one |
| 1.7 | Test-mode billing keys | D4 |
| 1.8 | **A deployment method for Store Syria** | see §4.1 — it has none, and this is an owner decision, not a runbook gap |

## 2. Credentials somebody has to mint

| # | Credential | Who mints it | Rule |
|---|---|---|---|
| 2.1 | `LYNOMIA_PLATFORM_TOKEN` | a Lynomia Super Admin creates the `PlatformApp` | typed straight into the staging environment. Not into a ticket, a chat, this document, or a retained shell history |
| 2.2 | `LYNOMIA_WEBHOOK_MASTER_SECRET` | generated **on the Store Syria host** (`openssl rand -hex 32`) | never leaves it; Lynomia never holds it. Must differ from production's |
| 2.3 | The platform workspace: account, sender user, inbox | `Billing::PlatformWorkspace.provision!` | needs a plan that is **not sellable** and **does** set `campaign_sends`, or it refuses |
| 2.4 | Both rotated after Phase 7, before any production use | | `docs/security/SECRET-ROTATION-CHECKLIST.md`. A staging credential has been handled by more people than a production one should be |

## 3. Decisions outstanding

### 3.1 D3 — the test recipient is still unidentified, and it gates §9 and part of §10

You resolved D3 as *"a consenting test recipient must be explicitly identified and verified
before any real message is sent. No recipient is authorized yet."*

The test runbook honours that with a hard GATE at §9, and records that **stopping at §9.2 with
the capability marked unverified is a legitimate Phase 7 outcome**. To unblock it you need to
name a person, confirm they agree to receive test messages on a specific number, and record
both.

Without it, these do not run: §9.3–§9.7 (campaign UAT), §10.2.2 (the verification code),
§10.3.4–§10.3.9 (every delivery-feed step that needs a real send).

### 3.2 D5 — fault injection needs an execution gate at the moment of running

Approved in principle. §12 of the test runbook deliberately breaks a running staging system —
clock skew, rate-limit exhaustion, killed workers, a 20-minute outage. It carries its own GATE
and requires the §2.2 backup to exist and be restorable first.

### 3.3 The `composer audit` ratchet is failing CI today, and raising it is your call

`AUDIT_CEILING` is 70; the lock audits at **72**. **This is not caused by this branch** —
`composer.lock` is byte-identical to `origin/main` and to the merge-base, and the merge-base
audits at 72 too. No new vulnerable dependency entered the lock; the advisory *database* grew
for packages already present (the two newest are both `league/commonmark`, 2026-09-30).

Deliberately not changed here, because silently raising the ceiling is the drift the ratchet
exists to catch. Two honest options:

| | |
|---|---|
| (a) | raise `AUDIT_CEILING` to 72 **in the same commit** that records the two advisories and their reachability, keeping the ratchet one-way |
| (b) | update the affected dependencies so the count falls, and lower the ceiling to match |

A third thing worth knowing either way: the gate's error text says *"A new vulnerable
dependency entered the lock"*, which is not what happened and would send someone looking in
the wrong place.

### 3.4 B7 — the CSAT survey reaches the provider with no eligibility check at all

**Found during the real-message authorization review, confirmed, and not fixed.**

`CsatSurveyService#send_whatsapp_template_survey` calls the provider directly. It checks the
conversation and the inbox and **nothing about the recipient** — not blocked, not withdrawn,
not provider-suppressed. The same survey is fully gated when it goes out as a *message* and
ungated when it goes out as a *template*; which branch runs is inbox configuration, not policy.

A shopper who replies STOP, whose conversation is then resolved, receives a CSAT survey.

Not fixed because CSAT is core Chatwoot behaviour for every account on the fork, not something
this integration added, and you asked for a review of this boundary rather than a change to
it. **Phase 7 mitigation, which costs nothing and is already written into the test runbook:
leave CSAT disabled on every staging inbox.** The fix, when you want it, is the same `custom/`
prepend pattern that closed the composer gap (C15), applying
`Commerce::MessagingEligibility.refusal(contact, nil)`.

### 3.5 B8 — a merchant can read their own webhook secret

`_webhook.json.jbuilder` renders `json.secret`, and a merchant tenant owner is an
administrator of their own workspace. They can therefore sign events their own store's ingress
accepts as genuine — including a `consent_changed` **grant** the shopper never gave.

**Not a cross-tenant breach**: secrets are derived per store, so a merchant learns their own
and nobody else's. What it changes is what the consent record *is*: merchant-attested, not
platform-attested, while the platform permits sends on the strength of it. Informational for
staging (synthetic merchants will not forge consent); your call before production.

### 3.6 Lynomia has no known-failure baseline, and its security gate is permanently red

Store Syria asserts its outcome **set** bidirectionally as a blocking CI step. Lynomia has no
manifest, no allowlist and no gate — so on that side there is no automated way to tell an
inherited failure from a new regression. Its only blocking *security* job fails at
`bundle-audit` and has in every recent run, so it carries no signal.

Neither blocks Phase 7. Both are why a contaminated measurement could stand unchallenged (§4.3).

---

## 4. What preparation found that you should know before authorizing

### 4.1 Store Syria has no deployment story at all

Lynomia ships `deployment/deploy.sh` (ten stages, pre-deploy backup, `EXPECT_SHA` guard, drain,
readiness poll), systemd units, an nginx config and `ROLLBACK.md`. Store Syria has **no deploy
script, no Dockerfile, no systemd unit and no rollback document**; `composer.json` carries only
Laravel's stock lifecycle hooks.

`PHASE-7-RUNBOOK-DEPLOY.md` §2 spells the sequence out by hand so one window can proceed. That
is a stopgap, not a deployment story, and it needs an owner decision before any production
conversation.

### 4.2 B5 is closed in code; its human half is not

Lynomia now exposes `platform_owned` read-only on an endpoint the partner already holds, and
Store Syria refuses to act as a workspace that is not its own — a merchant's account is refused
on the flag, any other account is refused 401 by the permissible check, and a Lynomia that
cannot answer is refused as *unconfirmed* rather than assumed false. Nothing is sent in any of
those cases and nothing retries. Red-proofed in both repositories.

**The operator still chooses which account to provision in the first place**, so §10.1.1 keeps
the human confirmation.

### 4.3 A correction to a figure in this branch's own history

An earlier commit recorded a Lynomia sweep as *"5869 examples, 21 failures, all pre-existing"*.
The conclusion was right; the number was not. On a clean database it is **1 failure, not 21** —
the other twenty were an artifact of running several rspec processes against one shared test
database. The mechanism was reproduced deliberately: one planted row reproduces exactly the
four failures in the file that was cited, and they are precisely the four examples asserting
absolute global counts.

It matters here because you are being asked to judge a regression baseline, and a figure that
overstates breakage twentyfold cannot be judged. Recorded in `PHASE-7-PREREQUISITES.md` §5a.4
with the standing rule it produced: reset the test database and run one suite at a time.

### 4.4 Where the gates stand today

| Gate | State |
|---|---|
| Store Syria known-outcome gate | **passes.** 2025 tests, 8 failures, 17 skips, 0 errors — the declared set exactly, verified by independent set-difference. All 8 reproduce on the merge-base |
| Store Syria `composer audit` ratchet | **fails**, 72 vs 70. §3.3 |
| Store Syria `flag-sensitive-diff` | **fails** until a human puts `SECURITY-DIFF-REVIEW: completed` in the PR body |
| Lynomia `rubocop` | **passes.** It was failing with 14 branch-owned offences; fixed |
| Lynomia `security-scan` | **fails**, and always has. §3.6 |
| Lynomia specs | branch-relevant suites green; the broad sweep is 1 order-dependent failure on a clean database |

---

## 5. The authorization being requested

**Nothing further happens without an explicit answer to this.**

> **Authorize execution of `PHASE-7-RUNBOOK-DEPLOY.md` and `PHASE-7-RUNBOOK-TEST.md` §3–§8 and
> §11 and §13 against two isolated staging environments, with §9, §10.2.2, §10.3.4–§10.3.9 and
> all of §12 withheld pending their own gates.**

What that authorizes:

| Authorized | Withheld pending a further, separate gate |
|---|---|
| deploying both sides to staging | §9 and the real-send parts of §10 — **GATE 9**, which needs the D3 named recipient |
| SSO and account lifecycle, inbox and realtime, commerce, consent synchronisation, Flow Builder, billing and quota, the security and performance gates | §12 fault injection — **GATE 12**, D5's execution gate |
| seeding synthetic fixtures | any production deployment, merge, live provider, real charge, or `.env`/`APP_KEY` change |

What it does **not** do, in your own words and mine:

- It does not authorize production. Phase 7 completing closes **blocker A1 and nothing else**.
- A2, A3, A4, B1, B5's human half, B6, B7 and B8 stay open.
- B4 closes as merchants verify their own numbers, not on a date.
- None of the work done so far is an end-to-end test, and none of it closes A1.

**Also needed, and independent of the above:** a decision on §3.3 (the audit ceiling) before CI
can be green, and a decision on §3.4 (B7) before any staging inbox has CSAT enabled.
