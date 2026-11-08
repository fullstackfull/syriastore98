# Inventory taxonomy — proposal

**Status: PROPOSAL. Nothing in the matrix has been changed by this document.** The counts are
exactly as the owner asked they remain: **295 surfaces — 24 PREMIUM VERIFIED, 32 REDESIGNED AND
VERIFIED, 225 PENDING, 13 BLOCKED, 1 OUT OF SCOPE.** Written because the owner asked for "a
separate inventory-taxonomy proposal for non-screen endpoints versus genuinely blocked merchant
functionality".

---

## 1. What the question turned out to be

The owner named two kinds. Reading the 13 rows the matrix currently calls BLOCKED found **three**,
and the third is the one worth acting on first.

| | kind | rows | what it means |
|---|---|---|---|
| **A** | **Not a screen** | 2 | a route that returns JSON or an HTML fragment and can never render a page for a merchant to look at |
| **B** | **Blocked functionality** | 2 | a screen that *should* exist for a merchant but cannot be built without an owner decision |
| **C** | **Unclassified** | **9** | the audit returned `unknown`; nobody has rendered it and nobody has decided anything |

### A — not a screen (2 rows)

* **Customer filter result fragment** — `GET customer-filter-data`. Answers HTTP 200 with a
  JSON-encoded HTML string and **has no caller**; the customer grid stopped calling it in P2.5-R-07.
* **Customer filter field metadata** — `GET customer-filter`. Returns `application/json` and never
  a view. Its *output* is already rendered and captured as part of the filter bar (P2.5-PKG-G), so
  claiming it separately would mean reusing that evidence for a second surface.

Neither can earn a screen state at all. Counting them in a denominator of *screens* is a category
error, and leaving them as BLOCKED implies work is owed that is not.

### B — genuinely blocked merchant functionality (2 rows)

* **Edit customer** — a **web/Flutter parity gap**, not missing functionality. A complete
  customer-update backend ships (`routes/api.php:267` → `updateCustomerInfo`) and the Flutter admin
  uses it through an edit bottom-sheet. Only the *web* screen is absent, and its routes are
  deliberately unregistered. **Needs an owner decision**, not design work.
* **Wishlist create / edit / store / update** — four routes resolve, none renders a byte
  (`create()` and `store()` bodies are literally `//`). **Needs an owner decision** on whether
  merchant-authored wishlists are a product feature at all.

These are real screens a merchant could want. They belong in the denominator; they just cannot be
started yet.

### C — unclassified, reported as BLOCKED (9 rows)

```
Theme customize / storefront visual editor      Nav destination 1 — Home/dashboard
Sales Downloadable Product                      Nav destination 2 — Orders
Plan-order bank-transfer review modal           Nav destination 3 — Products
Top brand/category chart fragment               Attribute-option picker fragment
order/create.blade.php and order/edit.blade.php (DEAD)
```

Every one carries the same sentence: *"audit could not determine its state; it needs to be rendered
and classified."* **That is not a blocker.** It says nobody has looked. Three of them are the
Flutter shell's own nav destinations — which certainly render — and one is labelled DEAD in its own
title, so at least four of the nine already have a known answer that the matrix is not carrying.

**This is the finding that matters most in this document**, because BLOCKED reads as "we looked and
cannot proceed" while nine of the thirteen mean "we have not looked". Those are opposite claims and
the matrix currently prints them identically.

---

## 2. Proposed states

Replace the single BLOCKED bucket with three, keeping every row in the 295.

| state | definition | entry rule | exit rule |
|---|---|---|---|
| **NOT A SCREEN** | the route cannot render a merchant-visible page: it returns JSON, or an HTML fragment with no consumer | must name the response type **and** either the consumer that renders its output, or the absence of one | a consumer is built, or the route is retired (then the row leaves the inventory in the same commit, with the route) |
| **BLOCKED** | a merchant-visible screen that cannot be built without a decision this phase may not take | must name **who decides what**, and the evidence that work is impossible until then | the decision is made; the row becomes PENDING |
| **UNCLASSIFIED** | nobody has rendered it; its state is unknown | automatic — this is where an `unknown` audit result lands | somebody renders it and it becomes PENDING, VERIFIED, NOT A SCREEN or BLOCKED |

**UNCLASSIFIED is deliberately uncomfortable.** It is a debt counter, and it should be visible
rather than disguised as a considered judgement.

### What the counts would become

No row moves in or out of the inventory. **295 stays 295.**

| | today | proposed |
|---|---|---|
| PREMIUM VERIFIED | 24 | 24 |
| REDESIGNED AND VERIFIED | 32 | 32 |
| PENDING | 225 | 225 |
| BLOCKED | **13** | **2** |
| NOT A SCREEN | — | **2** |
| UNCLASSIFIED | — | **9** |
| OUT OF SCOPE | 1 | 1 |
| **total** | **295** | **295** |

### The denominator question, answered explicitly

The owner's instruction is *"Do not silently remove inventory entries or reduce the denominator."*
This proposal removes nothing and reduces nothing. It does suggest **two denominators reported side
by side**, because one number cannot answer both questions honestly:

* **295 — every surface in the inventory.** The number the owner's instruction protects. Nothing is
  ever quietly dropped from it.
* **291 — surfaces that can earn a screen state** (295 − 2 NOT A SCREEN − 1 OUT OF SCOPE − 1 … see
  note). The denominator for *"how much of the merchant experience has been designed"*, because a
  JSON endpoint can never be designed and including it makes the percentage permanently unreachable.

> Note: the arithmetic above is deliberately left for the owner to settle. Whether OUT OF SCOPE
> belongs in the second denominator is a judgement about what "the merchant experience" means in
> Phase 2.5, and it is not mine to make.

**Both numbers in every report, always.** A single percentage against a single denominator is how
coverage reporting goes wrong.

---

## 3. What this would cost

Small, and it is worth saying so rather than implying a programme of work.

* `build-coverage-matrix.py` — `BLOCKED_SURFACES` becomes three dicts (or one dict of
  `(state, reason)` pairs); `classify()` returns the declared state. The `unknown` fallback stops
  writing BLOCKED and writes UNCLASSIFIED. ~30 lines.
* The generator's existing surface-count guard (295) is unchanged and keeps its bite.
* The 9 UNCLASSIFIED rows then need rendering — which is work, but it is work the current matrix is
  **hiding**, not work this change creates.

---

## 4. What I have NOT done, and why

* **Not changed the matrix.** The owner asked for a proposal.
* **Not reclassified the 9 UNCLASSIFIED rows** by reading code. At least four look answerable from
  their titles alone — but "it probably renders" is the kind of inference this project has been
  burned by, and each one needs a capture the same as any other surface.
* **Not proposed retiring `customer-filter-data`.** It has no caller and looks dead, but retiring a
  route is a product change and the evidence that nothing calls it is a *grep*, not a proof about
  every vendored module.
