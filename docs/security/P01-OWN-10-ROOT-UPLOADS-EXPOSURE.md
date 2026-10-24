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
