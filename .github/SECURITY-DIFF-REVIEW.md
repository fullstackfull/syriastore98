# Human review checklist for security-sensitive diffs

This checklist is mandatory for any change that a CI job flags as touching a sensitive path, and
it exists because of a specific, real incident rather than as a formality.

## What happened

Phase 2 of the Store Syria x Lynomia integration overwrote a file that implemented a channel
configuration audit trail — the control that records a credential rotation by the *names* of the
keys that moved and never by their values. The replacement was an unrelated validation. The audit
emitter ceased to exist.

Nothing failed. The request still returned `200`. Every test the feature shipped with passed,
including the ones exercising the validation that had replaced the audit. The only symptom
anywhere in the system was two red examples in a suite the feature had no reason to run.

Two further defects of the same family were then found **by reading the diff**, with no failing
test anywhere: a webhook signing secret became mutable through the API (silently disabling the
delivery signature, and leaving no audit row because the audited column set excluded it), and a
rate limiter inherited from a shared middleware group was destroying merchant events because the
upstream sender never retries.

The common shape: **a widened surface, an existing control written against the narrower one, and
no loud failure.** Automated tests cannot see this. They assert that the new code works, and the
new code does work. Only a human asking "what did this remove?" finds it.

## When this checklist applies

The `sensitive-diff-review` CI job flags a pull request when the diff touches any of:

- `**/Audit*`, `**/audit/**` — audit and compliance surfaces
- `**/Middleware/**`, `routes/**`, `bootstrap/app.php` — authorization and request gating
- `**/Policies/**`, `**/*Permission*`, `**/*Role*` — authorization
- `**/Billing*`, `**/*Entitlement*`, `**/*Seat*`, `**/*Plan*` — entitlement and quota
- `**/*Signature*`, `**/*Secret*`, `**/*Credential*`, `**/*Token*` — provider credentials
- `**/config/**` — configuration defaults that act as controls
- `**/Providers/**`, `**/*RateLimiter*`, `**/*Throttle*` — rate limits and service bindings
- `**/database/migrations/**` — schema changes that relax a constraint
- `packages/workdo/LynomiaChat/src/app/**` — the integration's own services, jobs and controllers
- `.github/**` — the gates themselves

## The checklist

Answer each in the pull request description. "N/A" is a valid answer; silence is not.

1. **What does this diff REMOVE or NARROW?** List every deleted line that implemented behaviour,
   not just the added ones. `git diff --stat` ordered by deletions is the fastest way in.
2. **Did any file in this diff already exist and do something else?** If a path was overwritten
   rather than extended, show that the previous behaviour is preserved somewhere, or say
   explicitly that it was deliberately dropped and why.
3. **What existing control assumed the surface you widened was narrow?** If a parameter, route,
   permission or column became reachable where it was not, name the controls written against the
   old reach and show each still holds.
4. **Is any audit, logging or compliance emission removed, skipped, or made conditional?**
5. **Does any failure become silent?** A control that stops running while the response stays
   `2xx` is the exact failure mode this checklist exists for.
6. **Is method-resolution order involved?** In the Lynomia fork, a plain module prepended into a
   concern lands *ahead* of it, but an `ActiveSupport::Concern` prepended into another concern is
   registered as a dependency and *included* — which can land it *behind* the method it meant to
   override. Converting one into the other silently stops the override.
7. **Was the change verified by removing it?** For a control, delete the enforcement, watch a test
   fail, and put it back. A test that passes with the control removed is not testing the control.
8. **Does the merge-base regression gate report zero new failures?** A green feature suite is not
   the same claim.

## What this checklist is not

It is not a substitute for the owner reconciling `.github/known-test-outcomes.json`, and ticking
these boxes does not accept any inherited failure.
