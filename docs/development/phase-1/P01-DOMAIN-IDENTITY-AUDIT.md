# Store identity, domains and subdomains — audit before any routing change

**Status:** audit only. **No routing, schema, DNS or hosting change is made by this document.**
**Date:** 2026-10-22. **Branch:** `claude/store-syria-p01a-ci-foundation`.

Written in response to the owner directive of 2026-10-22, which asked — before any domain or
subdomain handling is modified — to *recover and verify the previously agreed numeric subdomain
design*, audit the six surfaces it named, and document the actual URL format and migration strategy
first.

Everything below marked **MEASURED** was executed in this session and the command is given. Everything
marked **READ** was read from the named file and line. Nothing here is inferred from the product's
documentation about itself.

---

## 0. The answer to the first question, stated plainly

**There is no previously approved numeric-subdomain design in this repository, and I will not
manufacture one.** The project rule is explicit — *never invent a previous approval* — so this section
states exactly what the record does and does not contain.

What the record **does** contain, in all three places a decision could live:

| Where | What is actually there | Is it an approval? |
|---|---|---|
| `docs/development/phase-1/15-DEFERRED-OWNER-DECISIONS.md` — **the authoritative owner register** | Five entries, D-01 … D-05: `SESSION_SAME_SITE`, the lottery payment rewrite, `auction:order` scheduling, the upstream WorkDo report, and payment-verification posture. **No domain or subdomain entry of any kind.** | — |
| `docs/discovery/10-OPEN-QUESTIONS-AND-DECISIONS.md`, row **D-08** | *"Custom-domain support: keep, defer, or drop."* The audit's own recommendation is **"(a) now, (b) when a paying merchant asks for it"** — where **(a) is "Drop for now: merchants get `storesyria.com/{slug}` only"**. | **No.** It is an auditor's recommendation in a discovery document, never ratified, and it points *away* from host-based addressing. |
| GitHub issue **#111** | Four open questions about domain handling. Two — (a) and (b) — are engineering choices this team can settle. Two — (c) and (d) — are genuine owner decisions, still open. | **No.** Open questions. |
| Shipped code: `app/Services/HostClaims.php` (P1-SEC-29) | A real, hardened **uniqueness and ownership** model for hosts. This is implemented and tested. | It is an implemented security control, **not** an identity architecture. It answers *"which store holds this host?"*, never *"what is this store's permanent name?"* |

So the directive's numeric-subdomain requirement — *a stable, unique numeric identifier per store,
independent of the editable name, slug and custom domain* — **enters the project with the 2026-10-22
message.** That is the authority for it. There is no earlier one to recover, and the one adjacent
recommendation on file (D-08(a)) is in tension with it and should be explicitly superseded rather than
quietly ignored.

**That is a gap in the record, not a gap in the requirement.** The rest of this document establishes
that the requirement is independently justified by measurement, and what it would take to meet it.

**Two of this audit's own first-draft claims were disproved by its own tests and are corrected in place
(§3.1 and §3.2), and one finding (§3.5) exists only because the MySQL script was written.** Both
corrections are left visible rather than silently edited out, because the pattern they illustrate — a
claim keyed on reading rather than on execution — is the one this project keeps paying for.

---

## 1. What carries store identity today

**The editable `stores.slug` column, and nothing else.** READ, `app/Helper/helper.php:2412-2415`:

```php
function getStore($storeSlug = null)
{
    return Store::where('slug', $storeSlug ?? request()->route('storeSlug'))->first();
}
```

Every storefront route is `{storeSlug?}/...`, and every storefront API route is `api/{slug}/...`. This
one function is the tenant resolver for the entire storefront. There is no numeric identifier in the
URL space anywhere.

### The actual URL formats in production today

| Form | Built by | Condition |
|---|---|---|
| `https://storesyria.com/{slug}/...` | the `{storeSlug?}` routes; `app/Models/Utility.php:2264` builds order links this way **into e-mail bodies** | the default; always available |
| `https://{custom domain}/...` | `getFrontThemeUrl()`, `app/Helper/helper.php:2708-2726` | `settings.enable_domain = 'on'` for that store |
| `https://{subdomain host}/...` | the same function, next branch | `settings.enable_subdomain = 'on'` for that store |

READ, `app/Helper/helper.php:2714-2724` — the precedence is custom domain, then subdomain, then
`env('APP_URL')`. Note the last two branches are identical, so `enable_storelink` currently decides
nothing.

**The subdomain is not a column.** It is a `settings` row named `subdomain`, paired with a flag row
`enable_subdomain`, resolved by `HostClaims::row()` (READ, `app/Services/HostClaims.php:46,74-87`).
`stores` has no `subdomain`, no `custom_domain` and no identifier column — see §2.

---

## 2. Schema: what `stores` actually has

READ, `database/migrations/2023_12_14_090259_create_stores_table.php` — the complete definition:

```php
$table->id();
$table->string('name');
$table->string('email');
$table->string('theme_id');
$table->string('slug');          // <-- NOT unique
$table->string('default_language')->nullable();
$table->unsignedBigInteger('created_by');
$table->integer('is_active')->default(1);
$table->string('enable_pwa_store')->default('off');
$table->timestamps();
$table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
```

MEASURED — no later migration alters `stores`:

```
$ grep -rln "'stores'" database/migrations/ | sort
database/migrations/2023_12_14_090259_create_stores_table.php
database/migrations/2024_03_21_051038_create_product_brands_table.php      (FK only)
database/migrations/2024_03_21_084240_create_notify_users_table.php        (FK only)
database/migrations/2024_03_26_090355_create_product_labels_table.php      (FK only)
database/migrations/2025_05_22_034433_create_theme_customizes_table.php    (FK only)
database/migrations/2025_05_23_043026_create_theme_customize_orders_table.php (FK only)
```

**So: `stores.slug` — the sole tenant discriminator in every public URL — has no unique index.** The
only uniqueness anywhere is one validation rule on one API rename endpoint
(`app/Http/Controllers/Api/DashboardController.php:7708`), plus the application-level de-duplication in
`User::slugs()`. Neither is a constraint; both are racy.

---

## 3. Why the slug is unsafe *as identity* — four measured defects

These are not arguments from taste. Each was executed in this session.

### 3.1 An Arabic store name produces the slug `-`

This is the finding that matters most, because Arabic is the **primary market** (`CLAUDE.md`: the
product is bilingual Arabic-first; `docs/discovery/10` D-12 recommends Arabic-first).

READ, `app/Models/User.php:115-150`. The generator sanitises twice, and the empty-string fallback sits
**between** the two passes:

```php
$slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $data);  // keeps Arabic letters (\p{L} is Unicode)
...
if (empty($slug)) { $slug = 'store-' . uniqid(); }        // <-- fallback runs HERE, slug is non-empty
...
$slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $slug);     // <-- then strips every Arabic letter
```

The fallback can never fire for an Arabic name, because at that point the slug still holds the Arabic
letters. The second pass then deletes them all.

MEASURED, running the generator's exact body over real names:

| Store name | Resulting slug | Length |
|---|---|---|
| `متجر الشام` | `-` | 1 |
| `سوق دمشق` | `-` | 1 |
| `متجر 2` | `-2` | 2 |
| `مـتجر!!!` | *(empty string)* | 0 |
| `Damascus Bazaar` | `damascus-bazaar` | 15 |

So in production **every Arabic-named store contends for the same slug.** The first takes `-`, and
`getRelatedSlugs()` then hands out `--1`, `--2`, `--3`… — a *double* hyphen, because the `/-+/ => -`
collapse runs **before** de-duplication, so appending `-1` to the base slug `-` re-introduces exactly
what the collapse exists to remove. The second Arabic store's public URL is `storesyria.com/--1`: not a
name, not stable, and determined purely by signup order.

MEASURED by `tests/Feature/Platform/StoreSlugIdentityTest.php`
(`test_two_differently_named_arabic_stores_contend_for_the_same_slug`), driving the real
`User::slugs()` against a real table. My first draft of this section said `-1`; the test said `--1`.
The test is right.

### 3.2 The 101st colliding store gets a NULL slug, and store creation then fails

READ, `app/Models/User.php:143-150` — the de-duplication loop:

```php
for ($i = 1; $i <= 100; $i++) {
    $newSlug = $slug . '-' . $i;
    if (!$allSlugs->contains('slug', $newSlug)) { return $newSlug; }
}
// no fallback return -- the function returns null
```

MEASURED (`test_the_hundred_and_first_colliding_store_gets_a_null_slug_which_is_the_defect`): with
`-` and `--1` … `--100` taken, `User::slugs()` returns `NULL`. **Combined with 3.1 the threshold is 101
Arabic-named stores, not 101 identically-named ones** — a reachable number for an Arabic-first product.

> **CORRECTION — this section's first draft was wrong, and the correction matters.**
>
> It claimed `Store::create(['slug' => null])` *succeeds* because production runs `strict => false`
> (`CLAUDE.md`), and that `getStore(null)` then becomes `whereNull('slug')` and **fails open**, serving
> the first null-slug store to every unresolved request.
>
> **MEASURED on MySQL 8.0.46 — it does not.** `tests/Support/Security/bin/verify-store-slug-identity-on-mysql.php`
> section A: an explicit NULL into the `NOT NULL` `slug` column is refused under **both** `strict => true`
> and `strict => false` (`SQLSTATE[23000] … 1048 Column 'slug' cannot be null`). Non-strict mode waives
> *implicit defaults and truncation*; it does not waive a NOT NULL violation from an explicit NULL in a
> single-row INSERT.
>
> **So the real consequence is a hard failure, not a silent mis-resolution:** the 101st Arabic-named
> store cannot be created at all and the merchant gets a 500. That is still a defect (stage 4), and it
> is a *different and less severe* one — loud, immediately visible, and with no cross-tenant reading.
> The `whereNull` fail-open remains a live hazard for other callers of `getStore()` that pass an
> unresolved slug, but it is not reachable through a stored row.

### 3.3 Two concurrent signups can take the same slug

`User::slugs()` reads the table (`getRelatedSlugs()`, a `SELECT`) and then `Store::create()` writes. With
no unique index there is nothing between the read and the write. Two simultaneous signups see the same
"free" slug and both take it.

`getStore()` is `->first()` with no `ORDER BY`, so on a duplicate **the lower-id store wins and serves
the other merchant's URL.** This is structurally the same defect P1-SEC-29 fixed for *hosts* — and the
reasoning in `HostClaims`' own docblock applies verbatim to slugs, which that fix did not cover:

> *"nothing kept a host unique, so the row with the lowest id of ANY tenant won"*
> — `app/Services/HostClaims.php:14-16`

### 3.4 The slug is editable, and renaming it breaks links already in the wild

READ, `app/Http/Controllers/Api/DashboardController.php:7708` — a merchant may rename `store_slug`
through the admin API.

READ, `app/Models/Utility.php:2264` — order links are built as
`{theme_url}/{slug}/order/{order}` **and placed in e-mail bodies sent to customers**.

So a rename silently breaks every order link already delivered to a customer's inbox. There is no
redirect, no alias table, and no history of previous slugs. This is exactly the coupling the directive
asks to sever: *"changing the store name, slug, or custom domain does not change the store's permanent
identity or break ownership, authentication, integrations, or existing links."* Today it does.

### 3.5 The collision class is collation-wide, which no SQLite test can show

Found by the MySQL script rather than by reading, and it was not in this audit's first draft.

MEASURED on MySQL 8.0.46 (`verify-store-slug-identity-on-mysql.php` section C). The project connection
collation is `utf8mb4_unicode_ci` — case-, accent- and PAD-SPACE-insensitive — so to the lookup:

| probe | resolves the stored `damascus`? |
|---|---|
| `getStore('Damascus')` | **yes** |
| `getStore('DAMASCUS')` | **yes** |
| `getStore('damascus ')` (trailing space) | **yes** |

And `getRelatedSlugs()`'s own `LIKE 'Damascus%'` folds the same way, so **the de-duplication cannot hand
out a case variant as free** — it correctly sees one row. The practical consequences:

- the set of slugs that collide is wider than the byte-identical set, so §3.3's race has more ways to fire;
- the Feature suite, on SQLite's BINARY collation, **cannot observe any of this** — it is precisely the
  engine-hiding failure mode `CLAUDE.md` names;
- therefore the uniqueness §6.2 asks for **must be a database UNIQUE index under this collation**, not a
  PHP comparison. Section D of the same script is the removal check: with the index added, all three
  probes above are refused as duplicates (error 1062), so the index is shown to be what closes §3.3 and
  §3.5 rather than merely correlated with it.

---

## 4. Hosting and SSL provisioning

### 4.1 No certificate is ever requested

READ, `app/Services/WebuzoDomainService.php:29,43` — the provisioning call sends
`issue_lecert => 0` whenever its `$issueSsl` argument is false. READ,
`app/Http/Controllers/AppSettingController.php:755` and `:868-872` — **both** call sites pass `false`.

So any subdomain or custom domain this code provisions is created **without TLS**. A numeric-subdomain
rollout that reuses this path would provision certificate-less storefronts for every merchant.

### 4.2 It cannot run at all today

READ, `config/services.php:119-124` — `WEBUZO_HOST`, `WEBUZO_USERNAME` and `WEBUZO_API_KEY` resolve
through bare `env()` with no defaults, and are absent from the committed `.env`. Every call throws. The
custom-domain path converts the throw into an error banner; the subdomain path 500s (discovery V22).

### 4.3 The open hosting unknown

`docs/discovery/10-OPEN-QUESTIONS-AND-DECISIONS.md:229` records an unknown that a numeric-subdomain
design depends on absolutely:

> *whether `domainadd` issues a certificate regardless of `issue_lecert=0`, and whether a server
> wildcard certificate covers tenant subdomains.*

**A wildcard certificate for `*.storesyria.com` changes the entire plan.** With one, numeric subdomains
need no per-store provisioning and no per-store certificate — the whole Webuzo dependency drops out of
the critical path. Without one, every store needs an ACME issuance and the design inherits §4.1 and
§4.2 as blockers. This is a question for the host's panel documentation, and it is **the single
cheapest thing to resolve before any implementation.**

---

## 5. Tenant resolution: the four paths, and which are safe

| Path | Resolver | State |
|---|---|---|
| `/{slug}/...` storefront | `getStore()`, `app/Helper/helper.php:2412` | **unsafe as identity** — §3 |
| `/{slug}/...` settings reads | `getRouteStoreId()`, `app/Helper/helper.php` | hardened (P1-SEC-18 F4); correctly distinguishes `storeSlug` from a page `slug` |
| custom domain / subdomain host | `HostClaims::row()` | **hardened** (P1-SEC-29): store-predicated, flag-gated, `ORDER BY id`, case/accent-fold put to the engine; verified on MySQL 8.0.46 by `tests/Support/Security/bin/verify-host-claims-on-mysql.php` |
| merchant admin session | `getCurrentStore()`, `app/Helper/helper.php:391-415` | **hardcodes `1` for `super admin`** (:407-409) |

That last row is the reason the directive's instruction *"do not assume the numeric identifier must
equal the database primary key"* is not merely cautious — it is **correct on this codebase**:

**Store id `1` is already a sentinel in the authorization layer.** If the public numeric identifier were
the primary key, store 1's public identifier would be the same value the operator fallback uses, and
any confusion between "the store this request addresses" and "the operator's default store" becomes a
cross-tenant bug rather than a cosmetic one. A separate column keeps the public identifier and the
internal sentinel in different namespaces. **Recommend a separate column** — and on the measurement,
not just the directive.

A second, independent reason: the primary key leaks the merchant count and signup order to anyone who
can read a URL, and it makes the identifier unassignable (you cannot give a migrating merchant a chosen
number).

---

## 6. Proposed URL format and migration strategy

**Nothing below is implemented.** It is the documentation the directive requires *before* a routing
change, and it needs an owner answer on §8 before it is built.

### 6.1 Proposed format

```
https://<N>.storesyria.com/            # N = the store's permanent numeric identifier
https://storesyria.com/<slug>/         # kept, permanently, as an alias that 301s to the canonical host
https://<custom domain>/               # unchanged; still governed by HostClaims
```

Recommended shape for `N`: **a 6-digit zero-padded integer from a dedicated sequence starting at
100000**, stored in a new `stores.store_number` column, `unsigned bigint`, `UNIQUE`, `NOT NULL`,
assigned once at creation and **never updated**. Six digits gives 900 000 stores; it is visually
distinct from a primary key; it does not start at 1; and it is short enough to say over a phone, which
matters for a market where support happens by voice.

### 6.2 What makes it *identity* rather than another slug

Four properties, each of which is a concrete engineering requirement, not a sentiment:

1. **A database `UNIQUE` index** — not a validation rule. §3.3 exists because uniqueness was
   application-level.
2. **No update path.** The column is absent from `$fillable`, and the only write is at creation. A
   rename endpoint that can reach it is the whole defect reintroduced.
3. **Assigned from a sequence, not derived from input.** §3.1 exists because the identifier was derived
   from a user-supplied, locale-sensitive string.
4. **A `NOT NULL` constraint,** so no unresolved request can select a row by a null identifier.

MEASURED on MySQL 8.0.46 (`verify-store-slug-identity-on-mysql.php` section E): a column of exactly this
shape — `bigint unsigned NOT NULL`, `UNIQUE` — refuses a duplicate number and refuses a NULL, on the
engine that serves production. So the stage-1 constraints hold; they are not assumed.

### 6.3 Migration strategy — four stages, each independently reversible

**Stage 0 — resolve the wildcard-certificate question (§4.3).** One question to the host. It decides
whether stages 2-3 need per-store provisioning at all. Nothing else should start first.

**Stage 1 — add the column and backfill, changing no routing.**
`store_number` added nullable, backfilled `100000 + row_number()` ordered by `id` (so existing
merchants get stable numbers in signup order), then altered to `NOT NULL UNIQUE`. Zero behaviour
change: nothing reads the column yet. Fully reversible by dropping the column.
*Note: this is a persistent migration, and persistent migrations are outside the approved restrictions
for this phase. It is written, not run.*

**Stage 2 — resolve by number, additively.** `HostClaims` gains a numeric-host branch, and
`getStore()` gains a `store_number` lookup. **The slug path keeps working unchanged** — this is the
backward-compatibility requirement, and it is satisfied by addition, never by replacement.

**Stage 3 — make the numeric host canonical.** Canonical `<link rel="canonical">` and a 301 from
`/{slug}` to the numeric host. The slug route is **kept indefinitely**: every order e-mail already sent
(§3.4) contains a slug URL, and those must not break. There is no stage that removes it.

**Stage 4 — fix the two slug defects that survive regardless.** Independent of all of the above, and
worth doing on its own merits: the Arabic-name collapse (§3.1) and the null return (§3.2) are live
defects in store creation today. They should be fixed whether or not numeric subdomains ship.

### 6.4 What this does *not* change

`HostClaims` stays exactly as it is for custom domains. The P1-SEC-29 model — a host is held by the
store with the row *and* the flag — is correct and tested, and a numeric subdomain is simply another
host that resolves through it. **This audit found no reason to redesign host ownership, and recommends
against touching it.**

---

## 7. Backward compatibility — the explicit checklist

| Surface | Breaks on a numeric-subdomain rollout? | Why |
|---|---|---|
| existing `/{slug}` links | **No** | slug route kept permanently (§6.3 stage 3) |
| order links in already-sent e-mails | **No** | same |
| the shipped Flutter admin app | **No** | it targets `https://storesyria.com/api/admin`, a static path with no slug (`CLAUDE.md`) |
| storefront API clients | **No** | `api/{slug}/...` untouched; `CLAUDE.md` records that no client in either repo calls these |
| merchant custom domains | **No** | `HostClaims` unchanged |
| Sanctum tokens / sessions | **No** | tokens are not host-bound; there is no cookie-domain change at stage 1-2. **A cookie domain change at stage 3 would log every session out** — see §8(d) |
| SEO | **Improves** | a canonical host replaces a signup-order path segment |

---

## 8. What still needs an owner answer

Consolidated to the smallest set, per the directive. **(a) and (b) are engineering and I will settle
them; (c) and (d) are genuinely yours.**

**(a) — engineering, settled here.** Shape of the identifier: 6-digit sequence from 100000 in a new
`UNIQUE NOT NULL` column, not the primary key. Justified by §5.

**(b) — engineering, settled here.** Keep `HostClaims` as the host-ownership model and add numeric
resolution to it rather than building a parallel resolver. Justified by §6.4.

**(c) — OWNER.** *Does the numeric subdomain supersede discovery recommendation D-08(a)?* D-08(a) is
"drop custom domains; slug-only addressing", and it is the only domain recommendation on file. The
directive points the other way. **Recommendation: yes, supersede it** — but say so explicitly, so the
record stops holding two opposed positions. **Impact:** decides whether §4's Webuzo work is in scope at
all.

**(d) — OWNER, and the one with a user-visible cost.** *May stage 3 move session cookies to a parent
domain?* Serving stores on `<N>.storesyria.com` while sessions are issued for `storesyria.com` means
either (i) each store gets its own cookie scope — clean isolation, but a merchant signs in per store,
or (ii) cookies move to `.storesyria.com` — one sign-in, but **every existing session is invalidated at
cutover**, and a shared cookie scope across tenant hosts is a security decision in its own right.
**Recommendation: (i), per-host cookie scope.** It is the isolating choice, and this is a multi-tenant
platform where cookie bleed between tenants is the expensive failure. **Impact:** (ii) logs every
merchant out once; (i) does not, but changes the sign-in flow for multi-store owners.

---

## 9. Restrictions observed

- **No DNS or hosting configuration was touched.** No Webuzo call was made. No certificate was
  requested.
- **No migration was written to `database/migrations/`.** Stage 1 is described, not created.
- **No routing was changed.** `getStore()`, `HostClaims` and every route file are untouched by this
  document.
- **No live data was read or modified.** Every measurement in §3 ran the generator's logic in isolation
  against literal inputs, or read committed source.
