# S1 `BackupRestore` — verification checklist for an authorized deployment administrator

**For a person with legitimate production access. Not performed by any agent.**
Every step is **read-only**: no writes, no configuration changes, no deletion, no module toggling.
Nothing here downloads a backup — step 5 reads an HTTP *status line* only.

**Stop and escalate the moment step 5 returns `200`.** That is a confirmed exposure of the whole
application root, `.env` included, to anyone on the internet. Do not finish the remaining steps
first.

Full finding and the evidence chain: `docs/security/P01-SECURITY-WORK-QUEUE.md` §S1.

---

## The question

The `BackupRestore` module writes a zip of the **entire application root** — `.env` and `.git/`
inside it — into `storage/uploads/`, `chmod 0777`. The document root is the repository root, and
`.htaccess` serves any path that exists as a real file. So *if the module has ever run on this
server*, the artefacts may be fetchable without authentication.

Three things decide it: **is it active, has it ever run, and is the output reachable.**

---

## Checklist

Run from the application root on the production host.

**1 — Is the module active?** It cannot be answered from the repository. Ask the application
itself, using its own helper — `module_is_active()` checks the module's enabled state FIRST
(`app/Helper/helper.php:1649-1657`) and only then the `add_on_managers` row, so the row alone is
not the whole answer:

```bash
php artisan tinker --execute="var_export(module_is_active('BackupRestore'));"
```

```bash
php artisan tinker --execute="echo \Nwidart\Modules\Facades\Module::has('BackupRestore') ? (\Nwidart\Modules\Facades\Module::find('BackupRestore')->isEnabled() ? 'ENABLED' : 'disabled') : 'NOT INSTALLED';"
```

```bash
php artisan tinker --execute="echo \App\Models\AddOnManager::where('name','BackupRestore')->value('is_enable') ?? 'NO ROW';"
```

Record all three. `false` / `disabled` / `0` across the board is the good answer. **They can
disagree** — the module can be installed and enabled while no `add_on_managers` row exists, and
that still leaves the route live. Treat ANY of the three being positive as "active" for the rest
of this checklist.

**2 — Is the route registered and reachable?**

```bash
php artisan route:list | grep -i -E 'backup|restore'
```

Record every line, including its middleware column. An empty result is a good answer.

**3 — Has it ever produced artefacts?**

```bash
ls -la storage/uploads/Files_Backup storage/uploads/Database_Backup 2>&1
```

"No such file or directory" for both is the best outcome and means steps 4 and 5 are moot.

**4 — If artefacts exist, what are their permissions and sizes?**

```bash
find storage/uploads -maxdepth 2 \( -name '*.zip' -o -name '*.sql' \) -printf '%m  %10s  %p\n' 2>/dev/null
```

Mode `777` or `666`, or any world-readable zip, is the condition the finding describes.
A `0`-byte `.sql` also matters: the module logs a failed dump as a success.

**5 — THE DECIDING CHECK: is any artefact served over HTTP?**
Take one path from step 4 and request **headers only** from the server itself.

```bash
curl -s -o /dev/null -w '%{http_code}\n' "https://<your-production-host>/storage/uploads/Database_Backup/<exact-filename>"
```

* `403` or `404` → not reachable. Good.
* **`200` → CONFIRMED EXPOSURE. Stop here and escalate immediately** (see below).

**6 — Is there a deny rule that would stop it?**

```bash
grep -n -i -E 'storage|backup|uploads' .htaccess
```

Record what you find. No matching rule means nothing but step 5's result is protecting the files.

---

## If step 5 returns 200

1. **Escalate to the owner immediately** — before any remediation, so the decision is theirs.
2. Treat the exposure as live: `.env` contents (database credentials, mail and payment keys,
   `APP_KEY`) must be assumed read by a third party.
3. Do not delete the artefacts before the owner has decided — they are evidence of what was
   exposed and for how long. Capture the file list and timestamps from step 4 first.
4. Web-server access logs for those paths will show whether anything fetched them.

## What this checklist deliberately does NOT do

No module deactivation, no permission change, no `.htaccess` edit, no file deletion, no backup
download, no credential rotation. Each is a remediation decision for the owner, and several would
destroy the evidence that answers "was this actually accessed".

---

## Record the answers

| # | Check | Result |
|---|---|---|
| 1 | module active (`1` / `0` / no row) | |
| 2 | backup routes + middleware | |
| 3 | artefact directories exist | |
| 4 | modes / sizes | |
| 5 | **HTTP status for one artefact** | |
| 6 | `.htaccess` rules covering storage | |

Administrator: ______________________  Date: ____________  Host: ____________________
