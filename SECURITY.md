# Security

## Reporting

Report a suspected vulnerability privately to the repository owner. Do not open a public
issue, and do not include credentials or customer data in a report.

## Open risk: credentials in version control

**Status: OPEN. Accepted and deferred by the project owner. NOT fixed, NOT
production-ready.**

`.env` is tracked in this repository. It was added in commit `3c39c160` ("Initial commit
98") and is present on every branch. It contains a live `APP_KEY`, the database credential
set, and further integration secrets. Because the file is in history, it is recoverable by
anyone who has ever had read access to the repository or to any clone, fork or backup of it.

No secret value is reproduced in this file, and none should ever be added to it.

### Why a `.gitignore` rule does not help

An already-tracked file cannot be ignored. `git check-ignore .env` reports no match for
this reason, and adding `.env` to `.gitignore` changes nothing while the file remains in
the index.

### Known consequence

`APP_KEY` is Laravel's encryption and signing key. Anything the application encrypts or
signs with it — including values passed through `encrypt()` / `decrypt()` in route
parameters — is forgeable by anyone holding the key. An encrypted identifier is therefore
**not** an access control in this codebase, and must not be treated as one.

### Remediation order, when the owner chooses to act

The order matters, and the first step is the one that actually ends the exposure.

1. **Rotate every credential first.** Database password, `APP_KEY`, broadcast/Reverb
   secrets, integration and Firebase credentials. Removing the file from the index does
   **not** un-leak anything: history retains it. Until rotation happens, the exposure is
   live whatever Git says.
   - Rotating `APP_KEY` invalidates every value encrypted under the old key and every
     existing session. It needs a migration plan for encrypted columns, not a swap.
2. **Then stop tracking it**, and have deployment provision `.env` out of band.
   - `git rm --cached .env` is **not** safe to run casually: any deployment that pulls from
     Git would have its `.env` deleted by the next `git pull`, taking the site down. Change
     how the file is provisioned before, or at the same time as, untracking it.
3. **Optionally rewrite history** afterwards. This is disruptive, requires every clone to
   be re-made, and does not substitute for step 1, since the credentials must be assumed
   compromised already.

### How CI treats this today

The pipeline neither hides the finding nor blocks development on it, because the owner has
required both:

- `.github/scripts/guard-no-env-files.sh` **reports** the known tracked `.env` as a GitHub
  warning annotation on every run and exits 0 for that one accepted state — and **fails**
  on any other live env file, any other tracked env file, or a `.env` written by a build
  step. The exposure cannot grow quietly.
- The gitleaks history scan is **reporting only** (`continue-on-error: true`), because a
  history scan cannot come back clean until rotation happens.
- The diff screen (`.github/scripts/scan-secrets.sh`) is **blocking**. It sees only changed
  lines, so it fails exactly when a *new* secret is introduced.

When rotation and untracking are done, delete the exemption in
`guard-no-env-files.sh` and remove `continue-on-error` from the gitleaks step. Leaving
either in place indefinitely is how a scanner stops meaning anything.

## Mobile application secrets

`android/app/google-services.json` and `ios/Runner/GoogleService-Info.plist` are tracked in
the Flutter repository. Firebase **client** configuration is designed to be embedded in a
shipped app bundle and is extractable from any installed copy, so its presence in version
control is not a leak in the same sense. The real control is **API key restriction** in the
Google Cloud console, plus Firebase security rules. Removing the files is not the remedy.

Release signing material (`*.jks`, `*.keystore`, `android/key.properties`) must never be
committed; that is enforced by test and by `.gitignore` in that repository.
