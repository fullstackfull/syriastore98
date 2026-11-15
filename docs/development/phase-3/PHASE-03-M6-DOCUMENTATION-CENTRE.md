# Phase 03 / M6 — the bilingual documentation centre

**Status:** engineering complete. Not released; not merged to `main`.
**Branch:** `feature/phase-03-storefront`.

---

## 1. What it is

Merchant-facing help at `/platform/docs`: how to open a shop, choose and edit a theme, run a
catalogue, handle orders and look after the account. Public, because somebody deciding whether to
subscribe should be able to read what using the product is actually like.

**Explicitly not `/api-docs`.** That route is `packages/workdo/Api/src/routes/web.php:18`, it sits
behind the merchant auth stack, it is vendored, and it documents the HTTP API rather than the
product. The owner's brief names it as the thing this is not.

| URL | Name | Content |
|---|---|---|
| `/platform/docs` | `platform.docs.index` | every category with its articles, plus search |
| `/platform/docs/search` | `platform.docs.search` | results, snippets, empty and no-match states |
| `/platform/docs/{category}` | `platform.docs.category` | one category |
| `/platform/docs/{category}/{article}` | `platform.docs.article` | the article, TOC, related, pager |

**5 categories, 16 articles, in both languages — 42 Markdown files.**

## 2. Why the content is in the repository

The same measurement that put the M5 marketing pages in git:

- A database-backed centre only has content after somebody runs a seeder **on production**, which
  Phase 03 is not authorised to do.
- Prose in a row cannot be reviewed in a diff, searched in an editor, or asserted by a test. Prose
  in the repository is all three.
- A bilingual pair is visible as two files side by side; a bilingual pair in a row is a column
  somebody forgets to fill. `DocumentationLibraryTest::the_two_languages_carry_the_same_articles`
  is only possible because they are files.

The brief allows a database-backed search. One is not used: the corpus is a few dozen files, the
index is built from them and cached, and `DocumentationSearch` ranks in PHP. A `LIKE` query against
MySQL would be **worse** here — its default collation is accent- and case-insensitive for Latin and
does nothing at all about Arabic orthography, which is where the real misses are.

```
resources/docs/<locale>/<NN-category>/<NN-article>.md
app/Support/Docs/DocumentationLibrary.php      discovery, front matter, tree, fallback
app/Support/Docs/DocumentationSearch.php       normalisation, ranking, snippets
app/Support/Docs/DocumentationRenderer.php     Markdown -> HTML, heading ids, TOC
app/Http/Controllers/PlatformDocsController.php
resources/views/platform/docs/*.blade.php      7 views
```

The numeric prefix orders files on disk and is stripped from the public slug
(`01-getting-started` → `getting-started`), asserted in both directions.

## 3. Arabic search, and why it needs more than `LIKE`

A reader types what they hear; the author wrote what is correct. In Arabic those differ in ways a
byte comparison cannot see. Each rule below is a named test case in `DocumentationSearchTest`:

| written | typed | rule |
|---|---|---|
| `أحمد` `إدارة` `آخر` `ٱسم` | `احمد` `اداره` `اخر` `اسم` | alef variants fold to bare alef |
| `على` | `علي` | alef maqsura |
| `مسودة` | `مسوده` | ta marbuta |
| `مسؤول` `قائمة` | `مسوول` `قايمه` | hamza carriers |
| `مُسَوَّدَة` | `مسوده` | tashkeel |
| `مســودة` | `مسوده` | tatweel |
| `٢٠٢٦` | `2026` | Arabic-Indic digits |

Without this, a merchant searching `مسوده` misses every article about drafts — silently, with no
error anywhere. `the_normaliser_does_not_collapse_genuinely_different_words` asserts the opposite
direction, because a normaliser returning a constant would satisfy every row above.

Ranking is coarse on purpose: title 8, summary 4, body 1 **per term, not per occurrence**, plus 5
for matching every term. A reader wants the page whose *subject* is what they typed.

**What it deliberately does not do:** stemming, synonyms, fuzzy matching, cross-script search. Each
can be wrong in ways a reader cannot see, and none is needed for the centre to be usable. The
results page says so in a line rather than leaving it implied.

## 4. Rendering, and the two security options

`DocumentationRenderer` runs CommonMark with `html_input => strip` and
`allow_unsafe_links => false`. The corpus is written by us and reviewed in git, so neither is
load-bearing today — they are pinned so they stay true the day somebody pastes markup from a
vendor's site into a help article. The article body is the **only** unescaped output on the
platform site.

Heading ids are added by post-processing rather than by the HeadingPermalink extension, because the
**same `slug()`** builds the contents entry and the heading id and the two therefore cannot drift —
a contents panel whose links go nowhere is worse than none. Arabic headings get Arabic ids
(`## البداية` → `id="البداية"`), which is a valid HTML id and URL fragment; positional ids would
break every saved link the moment somebody inserted a section. The TOC is read from the **Markdown**,
not the HTML, so a heading inside a code fence cannot become an entry.

## 5. Locale, and the fallback that announces itself

The locale is the request's, resolved by `setlocate`. There is no `/en/` or `/ar/` path segment:
that would give every article two addresses, split its link equity, and leave the URL disagreeing
with the language switch already in the site chrome. The cost — a shared link opens in the reader's
language rather than the sender's — is the better failure for help content.

An article present in English and not yet in Arabic is served **in English with a visible notice**,
marked `lang="en"` and `dir="ltr"`. A 404 would punish a reader who followed a link that should
work; a silent swap would hide the gap from us too.

That notice is driven against a **real** gap: the test writes an English-only article, requests it
in Arabic, asserts the notice, and removes the file in `finally`. An earlier version asked for
locale `fr`, which resolves to the English corpus — so the article was never a fallback and the
notice was never reachable. It asserted only that the page did not 404.

## 6. Tests — 51, every control falsified

| Suite | Tests |
|---|---|
| `tests/Feature/Platform/PlatformDocsRoutesTest` | 12 |
| `tests/Feature/Platform/PlatformDocsBilingualTest` | 7 |
| `tests/Unit/Platform/DocumentationLibraryTest` | 9 |
| `tests/Unit/Platform/DocumentationSearchTest` | 23 |

| Falsification | Result |
|---|---|
| normalisation map removed | **12 failures** |
| `html_input => allow`, `allow_unsafe_links => true` | 2 failures |
| heading ids dropped | contents-link test fails |
| one article's front matter broken | 2 failures (parse + parity) |
| a `related:` id pointed at nothing | dangling-id test fails, names it |
| the fallback notice removed from the view | fallback test fails |
| two Arabic chrome keys deleted | chrome test fails |
| the controller forced to locale `en` | 4 failures |

`the_router_sends_each_documentation_url_to_the_right_method` exists because
`/platform/docs/search` and `/platform/docs/{category}` are both two-segment GETs: the later
pattern would swallow the first and answer with a 404 for a category called "search", which from
outside is indistinguishable from a genuinely missing category. It asserts the controller **method**.

`every_article_in_the_corpus_renders` is a sweep, not a sample — a single unparsed file would
otherwise sit undetected until a reader found it.

## 7. Outstanding

| | Owner |
|---|---|
| native Arabic review of the 16 translated articles and the 14 new UI strings | owner |
| more articles (payments per provider, shipping zones, reports) | later phase; the shape supports them with no code change |
| visual capture of the centre in 4 combinations | **M7** |
