# Open risks and limitations

Honest, and ordered by what would bite first.

## 1. The Rails suite was not executed

**Blocked by the environment, not by the code.** `.ruby-version` pins 3.4.4;
`cache.ruby-lang.org` is refused by this session's egress proxy
(`curl: (22) … 403`), so `rbenv install 3.4.4` fails and the system Ruby (3.3.6) is rejected by
the `Gemfile`. There is also no PostgreSQL server and no Redis running in the container, both of
which the suite needs.

What was done instead: every new and edited Ruby file was syntax-checked (`ruby -c`), the specs
are written against the patterns of the two sibling endpoints' own specs
(`spec/requests/platform/api/v1/installation_lifecycle_spec.rb`,
`integration_health_spec.rb`) including their shared contexts and factories, and the Store Syria
side's 687 tests — which exercise the full client half of the contract against a scripted fake —
all pass. **The Lynomia specs must be run in CI before deployment.** Nothing in this work should
be treated as verified on the Lynomia side until they are.

## 2. No real end-to-end run

Nothing in this report is a real staging or production E2E result. The end-to-end scenarios are
exercised as **simulated** E2E: a real HTTP request through Store Syria's real middleware,
controller, service, credential derivation and HTTP client, against a scripted Lynomia. The
other side's half is covered by its own request spec, also unexecuted (risk 1). The two halves
have never run against each other.

`04-release-checklist.md` §6 is the staging run that would close this, and it is the gate that
should precede deployment.

## 3. `lynomia_commerce` is an operator precondition

Automatic activation is refused with `commerce_disabled` for an account whose plan does not sell
the commerce module. That refusal is correct — a store connected into such an account is a row
that works and a product that does not — but it means the primary business requirement
("every eligible merchant, no operator involvement") depends on a setting this code does not
touch and deliberately does not grant. Verify it before deploying, per the checklist.

## 4. The workspace event subscription still logs a warning

`EventSubscriptionManager::ensureSubscribed` catches and logs. Investigated and **not** fixed
here: it is not on the path for Commerce activation, SSO or commerce data (Store Syria pushes
commerce events from its own outbox; `Commerce::Providers::Storesyria.registers_webhooks?` is
`false`), it degrades the inbox from push to pull, and `ReconcileLynomiaEvents` sweeps behind it.

It is still a persistent error with a real consequence. The likely cause is on the agent-API
side — the subscription is made with the tenant owner's scoped credential and needs
`WebhookPolicy`'s administrator check to pass for that identity — and diagnosing it needs the
production log line's `kind`, which is not available from here. **Worth its own task.**

## 5. Rotation across the boundary is reactive, not pushed

An operator rotating a credential in Super Admin does not notify Lynomia. The installation then
fails its next provider call and Lynomia moves it to `needs_reauth`; the hourly sweep records
that, and the merchant's page shows **Needs attention** with a **Reconnect store data** button
that repairs it. So it recovers without anybody being paged, but there is a window — up to an
hour plus however long until the merchant looks — in which a rotated store's commerce panel is
stale.

Pushing the new credential on rotation would close it, and was left out as scope the brief does
not ask for: it would make an operator's emergency rotation initiate an outbound install, which
is a behaviour change to the emergency path.

## 6. `disabled` is reported, not reconciled downward

When a Lynomia operator disables an installation, Store Syria records it and shows
**Unavailable** — but it does not suspend the link or stop the credential. That is deliberate
(the link's state is Store Syria's to decide, and a Lynomia operator disabling a store is not a
Store Syria revocation) and it means the commerce credential still authenticates while the
installation is switched off. It grants nothing: the only caller is Lynomia's own adapter, and
Lynomia is not calling it while the store is disabled. Worth stating because it looks asymmetric.

## 7. `commerce_attempts` counts, nothing enforces a ceiling

The column is the observable count and the merchant's button is the retry, so there is no
unattended retry loop to bound — which is the property that matters. But there is also no hard
stop on a merchant clicking a failing button repeatedly. Each click is one `PUT` to an idempotent
endpoint behind a per-link claim, so the cost is bounded per click rather than per campaign of
clicks. If this proves to need a limit, the counter is already there to limit on.

## 8. The sandbox needed a temporary `composer.json` edit

`composer install` cannot complete in this environment: `git.yoomoney.ru` (the VCS source for
`yoomoney/yookassa-sdk-php`, a payment SDK unrelated to this work) is refused by the egress proxy
with a 403. To get a `vendor/` directory and run the PHP suite, two local path-repository stubs
for those packages were added to `composer.json`, `composer update` was run for those two
packages only, and **both `composer.json` and `composer.lock` were then restored byte-for-byte**
— `git status` reports them clean and they are not part of any commit. Consequence for the test
report: the YooKassa SDK classes are stubs in this sandbox. Nothing in the Lynomia module or its
tests references them, and the full suite result below should still be read with that in mind.

## 9. Production state not re-verified

No production call was made from this environment and none was authorised. Specifically
unverified here: that account #135's `integration_health` now reports one installation (§2.6 of
the brief was a pre-Commerce reading), and that store #26's live row looks as described.
`04-release-checklist.md` §7 is the read-only way to close both, after approval.
