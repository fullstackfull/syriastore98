# Phase 2.5 — merge safety

**Question asked by the owner (final directive, item 9):** *"Ensure merging does not trigger a
live-host deployment or accidentally activate deferred `.htaccess` access restrictions."*

Measured 2026-11-11 on `claude/vibrant-bell-12tquy` in both repositories. Two separate risks; the
second one was real and has been fixed.

---

## 1. Can merging trigger a deployment?

**No — with one element I could not verify myself, named in §3.**

| checked | result |
|---|---|
| Workflows in `syriastore98` | `ci.yml`, `security-attestation.yml`, `security-attestation-push.yml` — three files, no others |
| Workflows in `StoreSyria_app98` | `ci.yml` — one file |
| Every `uses:` across both | `actions/checkout@v4`, `actions/upload-artifact@v4`, `gitleaks/gitleaks-action@v2`, `shivammathur/setup-php@v2`. None can ship files to a host |
| Deploy-shaped words (`rsync`, `scp`, `ssh`, `ftp`, `deploy`, `cpanel`, `appleboy`, `fastlane`, `firebase`, `testflight`) | three matches, **all of them prose inside comments** in `ci.yml` (lines 20, 742, 963). Zero in a `run:` or `uses:` |
| `composer.json` scripts | `post-autoload-dump`, `post-update-cmd`, `post-root-package-install`, `post-create-project-cmd` — Laravel defaults, all local |
| Auto-deploy manifests, on this branch **and** on `origin/main` | none of `.cpanel.yml`, `.deployment`, `deploy.php`, `Procfile`, `Dockerfile`, `docker-compose.yml`, `.platform.app.yaml`, `app.yaml`, `vercel.json`, `netlify.toml`, `fly.toml`, `captain-definition`, `.buddy.yml`, `.gitlab-ci.yml`, `bitbucket-pipelines.yml`, `Jenkinsfile`, `.travis.yml`, `appveyor.yml`, `.circleci/config.yml`, `ftp-deploy.json`, `.ftpconfig` |
| Tracked git hooks | none. The `hooks/` paths in the tree are WorkDo **Blade view** hooks plus one Claude Code hook; `core.hooksPath` is set nowhere |

`ci.yml` **does** run on `push` to `main`, so merging starts a CI run. That run checks out the code,
installs PHP, runs the suites and uploads artefacts. It has no step that reaches the live host.

**The live host is reached only by a human.** Its `.git/HEAD` reads `ref: refs/heads/main`, so the
deployed tree is an ordinary checkout of `main` — somebody updates it by running `git pull` there, or
by FTP. Merging does not cause that.

## 2. Could merging have armed the deferred `.htaccess` rules?

**Yes. That was a real latent defect on this branch, and it is now fixed.**

The branch had committed the P1-SEC-40 deny rules *into* `/.htaccess` (4 `RedirectMatch` directives
plus a `<FilesMatch>` deny block, 79 added lines) and into `public/.htaccess` (2 more). `origin/main`
has **zero** deny rules in either file.

Because the live document root **is** the repository root and the deployed tree is a checkout of
`main`, those directives would have gone live the next time anyone pulled on that host. No deploy
pipeline needed, and nobody intending it. The owner's standing instruction is the opposite:

> "DO NOT block or change public access to the test website or its `.git` directory."

### What was done

The rules were lifted out into **`docs/security/p1-sec-40-apache-deny.conf`**, a file no server
reads, sibling to the nginx equivalent that was already held that way. Both served files keep a
comment naming P1-SEC-40, the deferral, and where the rules went.

They were **not deleted** — deleting a written control would suppress a finding, which the same
instruction also forbids. They remain written, reviewed and test-pinned; only their location changed.

### The proof

```
$ diff <(git show origin/main:.htaccess) .htaccess | grep '^[<>]' | grep -v '^[<>] *#'
  (empty)
$ diff <(git show origin/main:public/.htaccess) public/.htaccess | grep '^[<>]' | grep -v '^[<>] *#'
  (empty)
```

Both served files are **directive-identical to `origin/main`**. The entire diff is comments, which
Apache ignores. Merging therefore cannot change public access to anything.

The one remaining active deny in `/.htaccess` is the stock `<Files .env>` block. It is already live
on `main`, predates this work, and is not ours to remove.

### The pins

`tests/Unit/Security/DumpAndVcsPathsAreDeniedTest.php` — **32 tests, 40 assertions**. The 28 path
cases (18 refused, 10 that must keep working) now answer against the conf. Four structural pins; two
of them guard the deferral rather than the rules:

| pin | falsified by | observed |
|---|---|---|
| neither served `.htaccess` activates the deferred rules | appending a `RedirectMatch` to `/.htaccess` | FAIL — *"../.htaccess has gained an ACTIVE deny directive: RedirectMatch 404 …"* |
| " | appending a `<FilesMatch>`+`Require all denied` to `public/.htaccess` | FAIL — *"public/.htaccess has gained an ACTIVE deny directive: …"* |
| the conf does not bury its rules in the mod_rewrite guard | wrapping a rule in `<IfModule mod_rewrite.c>` | FAIL |
| the rules exist and the path cases hold | deleting the conf | 30 of 32 FAIL |

Restoring each file returned the suite to 32 passed. Unit suite after the change: **1641 passed,
12,225 assertions**.

## 3. What I could not verify

**Repository webhooks.** A push webhook pointing at a listener on the host could make it pull on
merge. `GET /repos/{owner}/{repo}/hooks` is blocked for this session (`HTTP 403`, *"Access to this
GitHub API path is not permitted through this proxy"*), and no MCP tool exposes webhooks, so I have
no way to read them.

**This is the only unchecked path to a live-host change on merge.** The owner can settle it in
seconds: **Settings → Webhooks** on each repository. If the list is empty, §1 is complete. Even if a
webhook exists, §2 means the `.htaccess` restrictions still cannot activate — the rules are not in
any file Apache reads.

## 4. Unchanged by all of this

The exposed `.git` history and the historical credentials on the test host remain
**OWNER-DEFERRED — UNRESOLVED SECURITY RISK**. Nothing here remediates them, and `/.git/HEAD` and
`/.git/objects/info/packs` still answered 200 on 2026-11-11. Making the merge inert is the opposite
of a fix: it is what keeps the deferral the owner asked for from being undone by accident.
