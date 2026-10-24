# P1-OWN-01 — server verification runbook for an authorized operator

**Issue #42. This runbook is READ-ONLY. Nothing in it changes the server.**

Prepared because the owner's report that BackupRestore is *disabled in the application settings*
does **not** establish that its routes or its previously generated artefacts are inaccessible.
Measured evidence for that statement:
`tests/Support/Security/bin/verify-backuprestore-module-gating.php` (6 checks, 0 failures) and
`docs/security/P1-OWN-01-BACKUPRESTORE-CONTAINMENT.md`.

---

## Rules for whoever runs this

**Do:** run the three checks below, record the output asked for, and report it.

**Do not:**
- open, download, copy, move or `cat` any backup archive, or extract anything from one;
- print the contents of `.env`, any credential, token, key or password;
- delete, rename, `chmod` or otherwise modify any file;
- enable the module, change `add_on_managers`, or edit any configuration;
- run the module's backup function.

Every command below is a read. Two of them list file metadata; **none reads a file's contents.**
If any command appears to want a write, stop and report instead.

**Record for each check:** the command run, its exact output (with the redactions noted), and the
UTC time. Three short outputs are the whole deliverable.

---

## Check 1 — is there an `add_on_managers` row at all?

**This is the question, not whether the flag is off.** `PackageServiceProvider.php:47` selects
modules with `AddOnManager::pluck('module')` and **no `is_enable` predicate**, so a row's mere
existence loads the module and registers its eight routes. Measured: `is_enable = 0` and
`is_enable = 1` register byte-identical route tables.

Run **one** of these, whichever suits the environment:

```sql
-- From a read-only SQL session:
SELECT id, module, name, is_enable FROM add_on_managers WHERE module = 'BackupRestore';
```

```bash
# Or, if a CLI is easier. Credentials come from the server's own configuration --
# do NOT paste a password into the command line or into your report.
mysql --defaults-file=<the server's option file> -e \
  "SELECT id, module, name, is_enable FROM add_on_managers WHERE module = 'BackupRestore';"
```

**How to read the result:**

| result | meaning |
|---|---|
| **no rows** | **The safe state.** The module's routes do not exist. |
| a row, `is_enable = 0` | **The eight routes DO exist.** "Disabled" is only gating `module_is_active()`. Not an incident by itself, but not the safe state either. |
| a row, `is_enable = 1` | The module is fully enabled, contradicting the settings report. Report this immediately. |

**Report:** row count, and `module` / `is_enable` if a row exists. No other column matters.

---

## Check 2 — what artefacts exist, and are they reachable from the web?

This is the check that can turn into an incident. **Disabling the module does not remove an
artefact already generated.**

### 2a. Do artefact files exist? (metadata only — do not open them)

```bash
ls -la storage/uploads/Database_Backup storage/uploads/Files_Backup 2>&1
```

If that reports no such directory, say so — that is a good answer.

**Report:** for each file, its **name, mode, owner and date**. Nothing else. Do not report
contents, and do not open the file to "check what it is".

### 2b. Is such a file served over HTTP? (the incident test)

Only if 2a found at least one file. Use the name exactly as listed, and use **`-I`** so only
headers are fetched — this deliberately does **not** download the body.

```bash
curl -sS -o /dev/null -D - -I "https://storesyria.com/storage/uploads/Database_Backup/<exact-filename>"
```

Repeat for one file in `Files_Backup` if one exists.

**How to read the result:**

| status | meaning |
|---|---|
| **`404` or `403`** | Not served. Good — record which, since 404 is preferable to 403. |
| **`200`**, with a `Content-Length` and a `Content-Type` of `application/zip`, `application/sql`, `application/octet-stream` or similar | **INCIDENT. STOP AND ESCALATE — see below.** |

**Report:** the status line and the `Content-Length` / `Content-Type` headers. **Do not re-run
without `-I`, and do not save the body.** A `200` is already conclusive; downloading it adds
nothing and creates a second copy of the exposed data.

---

## Check 3 — document root and `.htaccess` coverage

```bash
# Where does the web server think the site root is?
# Apache:
grep -rn "DocumentRoot" /etc/apache2/sites-enabled/ 2>/dev/null
# or, if the panel hides it, the path that `index.php` sits in:
ls -la index.php public/index.php 2>&1

# Does .htaccess already deny the artefact paths?
grep -nEi "storage|_Backup|RedirectMatch|Deny" .htaccess 2>&1 | head -40
```

**Report:** whether the document root is the repository root (an `index.php` at the repository
root, which S1 records as the case), and whether any rule already matches `storage/` or `_Backup`.

---

## If Check 2b returns 200 — immediate incident

The owner's standing instruction: **a confirmed publicly accessible backup artefact is an
incident requiring containment now, not after Phase 01.**

What that artefact is, so the severity is not underestimated: the file archive is a `ZipArchive`
over `base_path()` whose only exclusion is the `zip` extension, so it contains **`.env`** (hence
`APP_KEY`, the database credentials and every configured gateway secret) and **`.git/`**. The
database archive is a full `mysqldump`, so it contains **every tenant's customer, order and
payment data**.

**Containment, in this order** — the first step is the only urgent one:

1. **Make the path unservable.** Add to the document-root `.htaccess`:
   ```apache
   RedirectMatch 404 (?i)/storage/uploads/(Files|Database)_Backup
   RedirectMatch 404 (?i)_Backup/.*\.(zip|sql|gz|tar)$
   ```
   `RedirectMatch 404` rather than `Deny` so the path is indistinguishable from one that never
   existed. **Then re-run Check 2b and confirm it now returns 404**, and confirm the merchant
   admin still loads.
2. **Move the artefacts out of the document root**, to a path the web server does not serve.
   Do not delete them yet — they are evidence of what was exposed, and deciding whether they are
   still needed is the owner's call.
3. **Treat every secret in `.env` as disclosed** and plan rotation. This is already tracked as
   **P1-OWN-02 (#43)** — note that `APP_KEY` is in git history and unrotated regardless
   (queue row S2), so an exposed `.git/` compounds a disclosure that already exists.
4. **Establish the exposure window** from the artefact's mtime (Check 2a) and the web server's
   access logs for those paths — who fetched them, and when. Access-log review is a read.

Steps 1 and 2 change server state and are the operator's to perform under the owner's
authorization. **This session has not performed them and will not.**

---

## What happens with the results

| Check 1 | Check 2a | Check 2b | outcome |
|---|---|---|---|
| no row | no files | n/a | **#42 can close.** Routes do not exist and there is nothing to serve. |
| no row | files exist | 404/403 | Artefacts are present but unserved. Apply containment step 1 as hardening, then #42 closes. |
| row present | either | 404/403 | Routes exist. Decide whether to **delete the row** rather than leave `is_enable = 0` — per Check 1 that is the difference between no routes and eight. |
| any | files exist | **200** | **Incident.** Containment above, then #42 stays open until rotation (#43) is scoped. |

Return the three outputs and the ledger entry and GitHub issue will be updated against them.
**#42 stays BLOCKED until then** — the engineering half is complete and pinned by tests; this is
the half that cannot be answered from the repository.
