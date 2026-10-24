# Root-level `uploads/` is served directly by the webserver

**P1-OWN-10 (#103) · owner decision 11 · escalated 2026-10-23**

Found while verifying the historical-sensitive-data group against the owner's standing instruction
to *"close remaining active code-level disclosure paths"* and to *"escalate any confirmed ongoing
exposure immediately rather than silently ignoring it."* It is **outside P1-OWN-10's own acceptance
criteria**, which is why acting on that row alone would have missed it.

---

## 1. What is exposed

**407 user-uploaded files are tracked in the repository at `uploads/`**, at the repository root
rather than under `public/`.

```
uploads/2/         188 files        uploads/16/     15 files
uploads/uploads/   105 files        uploads/19/     13 files
uploads/15/         45 files        uploads/3/       8 files
uploads/11/         25 files        uploads/18/      3 files
uploads/stylique/    1 file         uploads/receipt/ 1 file
```

The numbered directories are store ids. `uploads/receipt/` holds a payment receipt, which is the
shape of file a customer uploads to prove a bank transfer.

## 2. Why they are reachable, read from the tracked `.htaccess`

Two rules decide it, and both turn on the same condition:

```apache
# .htaccess:23-26  -- the front controller
RewriteCond %{REQUEST_URI} !(\.css|\.js|\.png|\.jpg|\.jpeg|\.gif|robots\.txt|\.ico|...)$ [NC]
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]

# .htaccess:33-36  -- the public/ redirector
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(css|assets|...|uploads|storage|installer|js|vendor)/(.*)$ public/$1/$2 [L,NC]
```

For `GET /uploads/2/<file>.jpg`:

1. the front-controller rule is skipped twice over, by the image-extension condition at `:23` and
   again by `!-f`;
2. the `public/` redirector is skipped by `!-f`, because the file **does** exist at the root;
3. so Apache serves `uploads/2/<file>.jpg` from the repository root — **no application, no route,
   no authorisation, no tenant check**.

`.gitignore:` excludes `/public/uploads/`. It does **not** exclude `/uploads/`. That asymmetry is
how the set came to be tracked at all.

## 3. The one condition this rests on, stated rather than assumed

**The webserver's `DocumentRoot` must be the repository root** rather than `public/`. This document
does not have access to the production webserver configuration and does not claim to have verified
it.

The evidence that it *is* the repository root is the `.htaccess` itself: rules `:33-36` exist
precisely to rewrite `/uploads/...`, `/css/...`, `/js/...` and `/vendor/...` into `public/`, which
is only necessary when requests arrive at the root. A deployment whose `DocumentRoot` already
pointed at `public/` would have no use for them.

**The check that settles it, read-only, from anywhere:**

```
curl -sI https://storesyria.com/uploads/receipt/icon.png_1783941141
```

A `200` confirms the exposure is live. A `404` means `DocumentRoot` is already `public/` and this is
latent rather than active. Either way the containment below is correct; only its urgency changes.

## 4. What has been done here, and what has not

| | |
|---|---|
| **Done** | `.github/scripts/guard-no-new-root-uploads.sh` refuses any **newly added** path under `uploads/`, wired into CI next to the `packages/workdo/` guard. Verified to bite: a probe commit adding `uploads/zz/probe.jpg` exits 1 and names the file. |
| **Not done** | The `.htaccess` change that would make the existing 407 unreachable. See below. |
| **Not done, by owner decision** | Deleting the files or rewriting git history. D11: *"Do not delete or rewrite historical user uploads in Git."* |

### Why the `.htaccess` change is prepared but not applied

The containment is one line — drop the `!-f` condition so `/uploads/...` **always** rewrites into
`public/uploads/...`:

```apache
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(css|assets|market_assets|images|landing|storage|installer|js|vendor)/(.*)$ public/$1/$2 [L,NC]
RewriteRule ^uploads/(.*)$ public/uploads/$1 [L,NC]
```

**It is not applied because it can 404 live product images, and this is a running shop.** If any
merchant's current media resolves from the root copy rather than from `public/uploads/`, that
media disappears the moment this ships. Which of the two holds is a fact about the production
filesystem, and this container cannot see it.

**The one check that decides it,** read-only, on the server:

```
ls public/uploads/ | head
comm -13 <(find public/uploads -type f -printf '%P\n' | sort) <(find uploads -type f -printf '%P\n' | sort) | head
```

An empty second result means every root file also exists under `public/`, and the change is free.

## 5. Accepted risk versus remediated exposure

The owner's instruction is to *"clearly distinguish accepted risk from remediated exposure."*

| | status |
|---|---|
| The 407 files remaining in the working tree and in git history | **ACCEPTED RISK** — D11, explicit |
| The set growing with new uploads | **REMEDIATED** — CI guard, verified to bite |
| The files being served unauthenticated over HTTP | **OPEN, ACTIVE pending the §3 check** — containment prepared, held on the §4 check |
| Secret rotation for anything the files may expose | **ACCEPTED RISK** — D7, no rotation now |

## 6. The durable fix, which is neither of the above

Pointing `DocumentRoot` at `public/` removes this entire class — the root-level `uploads/`, the
tracked `.env` deny rule, `vendor/`, `storage/`, the `.git` directory and every other root path —
in one operator action, and it is already on the operator list. The `.htaccess` change above is a
mitigation for as long as that has not happened.

---

## 7. D11's last criterion — "identify files needing separate legal/security handling"

D11's full text is *"leave them in the tree **and in history**; no deletion, no history rewrite,
no force-push; prevent **new** user uploads being committed; document residual exposure; identify
files needing separate legal/security handling; **do not claim preservation removes
confidentiality risk**"*. Six obligations. Five are discharged in §1–§6 above. This section is the
sixth.

**The method, and its limit, stated first.** This is a **structural** classification — paths,
filenames, extensions and counts, produced with `git ls-files uploads`. The files' **contents were
not inspected**, and not for lack of effort: deciding what is in a merchant's screenshot is a human
judgement about a real person's data, and making it from a session that may not even have the right
to look at them would be the wrong way round. So what follows identifies **which files need a human
to look**, which is what D11 asks for, and nothing more.

### The inventory, by what the name says

| | count | what it is | handling |
|---|---:|---|---|
| `Screenshot_<arabic date>.jpg` | **146** | **Device screenshots**, named by the uploader's own OS in Arabic locale | **LOOK FIRST.** See below |
| other `.jpg` / `.png` under a numeric directory | 240 | product and store imagery, by the filename pattern `<id>_<unixtime>_<originalname>` | ordinary residual (§5) |
| `.svg` | 17 | icons and theme assets | ordinary residual |
| `uploads/receipt/icon.png_1783941141` | 1 | the directory name says *receipt*; the file is an **icon** with a mangled extension, not a receipt | ordinary residual — the directory name is the misleading part |
| `uploads/profile/avatar.png` | 1 | the shipped default avatar | not user data |
| `uploads/stylique/…` | 1 | theme asset | not user data |
| `uploads/68_1789477737_1000219049.jpg` | 1 | a stray at the `uploads/` root rather than under a tenant directory | ordinary residual |

**Total 407**, which agrees with `git ls-files uploads | wc -l` and with the count in §1.

### Why the 146 screenshots are the set that needs a human

A product photograph discloses a product. **A screenshot discloses whatever was on the uploader's
screen** — and the uploader was a merchant using their own phone or desktop. The plausible contents
are an open chat, a banking or payment-provider page, another customer's order, a notification
preview, or an email client. None of that is inferable from the filename, and all of it is a
different question from "a shop's photographs are public anyway".

Three facts make them identifiable to a person, which is what turns this from a security question
into a legal one as well:

1. **They are attributable to a tenant.** The directory is a numeric id (`uploads/2`, `uploads/15`,
   `uploads/11`, `uploads/16`, `uploads/19`, `uploads/3`, `uploads/18`) and the filename begins
   `<id>_<unixtime>_`, so each file names who uploaded it and when.
2. **The capture time is in the filename**, in Arabic-Indic digits, to the minute.
3. **They are in git history**, so every clone anyone has ever taken holds them, and D11 forbids
   the only operation that would remove them.

### What is being handed over, not decided

Per D11 this section **identifies**; it does not purge, rewrite or triage. What a human needs to
decide, with the files in front of them:

- Do any of the 146 contain a third party's personal data, payment-provider screens, or
  credentials? That decides whether this is a documentation item or a disclosure obligation.
- If any do, D11's "no deletion, no history rewrite" and that obligation are in direct tension, and
  **the tension is the owner's to resolve** — it is a decision about a legal duty, not about a
  repository.
- Whether the merchants who uploaded them should be told that files they uploaded are in a
  developer-readable repository.

### The sentence D11 explicitly asks for

**Preserving these files does not remove their confidentiality risk.** They remain readable by
everyone with repository access and, until the §3 containment or the §6 `DocumentRoot` change
lands, by anyone who can reach the server over HTTP. The CI guard stops the set growing; it does
nothing about the 407 already there, and nothing in this document should be read as saying
otherwise.

