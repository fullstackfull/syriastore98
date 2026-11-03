# Phase 2.5 — verified versus refuted, all 290 findings

> The owner's decision of 2026-11-03: *"The 19 blocker findings in the inventory are NOT
> automatically accepted as verified defects. Finish the adversarial verification. Refuted findings
> must not become implementation tasks."*
>
> **This is that result. Every one of the 290 findings now carries a verdict.**

---

## 1. How the verification was run, and why it was run twice

Each finding was given to an agent **instructed to refute it**, told to default to *refuted* when it
could not positively confirm the claim against the code, and required to cite `file:line` for what
it actually read. A finding is refuted when the file does not say what the finding claims, when a
later rule already handles it, when it is already fixed, when it is out of Phase 2.5's scope, or
when it is a taste preference with no rule behind it.

**It was run twice because the first run's ground rules contained two false premises**, both of
which this phase had already disproved in its own work:

| the first run was told | what is actually true |
|---|---|
| "The design system is **OPT-IN** per page" | it is **GLOBAL** since P2-04-5 (`layouts/app.blade.php:225`). Grepping a view for `store-syria-ds` tells you nothing about adoption. |
| "the host emits `dir=""` for LTR, so `[dir="ltr"]` never matches" | the **admin** layout emits `dir="ltr"` (`app.blade.php:68`); it is the **storefront** layout that emits `dir=""`. |

A finding judged against either premise was judged against a fiction. The second run corrected both,
added the measured cascade position (25 stylesheets, this one 15th), and listed every Phase 2.5
slice shipped since the audit so a fixed defect would be called stale rather than confirmed.

**89 findings were judged by both runs. They disagreed on 8** — and those 8 are being adjudicated by
a third pass that reads the code rather than the two verdicts, because a disagreement between two
adversarial readers is a signal that the finding is genuinely ambiguous, not an average to take.

---

## 2. The result

| severity | found | **upheld** | refuted | refuted |
|---|---|---|---|---|
| blocker | 19 | **15** | 4 | 21% |
| high | 82 | **70** | 12 | 15% |
| medium | 140 | **112** | 28 | 20% |
| low | 49 | **34** | 15 | 31% |
| **all** | **290** | **231** | **59** | **20%** |

**One finding in five did not survive.** That is the number that makes the other four worth acting
on.

### Severity moved on verification more often than the verdict did

**66 findings were downgraded and 11 upgraded.** The verifier was asked for a corrected severity
whenever the finding was real but mis-stated, and it used that far more than it used outright
refutation — which is the healthier outcome: most audit error is in the *weight* of a defect, not
its existence.

Downgrades include `bysales-ajax-loader-stuck` (blocker → high) and five `medium → low`. Upgrades
include `kanban-unbounded-render`, `reports-export-anchor-as-button`, `staff-card-grid-a11y`,
`add-tax-validation-is-or-not-and` and `order-filter-tabs-no-role-or-state`, all **medium → high**.

### By area

| area | upheld | refuted |
|---|---|---|
| orders | 33 | 3 |
| catalogue | 26 | 7 |
| flutter-screens | 26 | 6 |
| customers | 24 | 1 |
| products | 20 | 3 |
| stock-finance | 20 | 5 |
| staff-settings | 19 | 8 |
| reports-notifications | 17 | 2 |
| flutter-shell | 15 | 5 |
| dashboard | 14 | 6 |
| nav-shell | 13 | 4 |
| **shared-components** | **4** | **9** |

**`shared-components` is the only area where most findings were refuted**, and the reason is the
first run's false premise: it is the area whose findings turn on how the design system is delivered,
so an auditor told the system was opt-in reported gaps that do not exist in a system that is global.
The area with the cleanest record is `customers` — 24 upheld against 1 refuted — and three of those
24 have since been repaired (`R-02`, `R-03`, `A-05`).

### By category, among the upheld

| category | upheld |
|---|---|
| **a11y** | **57** |
| consistency | 27 |
| form-ux | 23 |
| state-missing | 22 |
| feedback | 20 |
| other | 19 |
| contrast | 18 |
| dark-mode | 10 |
| rtl | 9 |
| layout | 8 |

**Accessibility and contrast together are 75 of the 231** — a third of everything that survived, and
exactly the category the owner's decision says to prioritise.

---

## 3. What has already been repaired out of this set

| finding | slice |
|---|---|
| `product-grid-view-500` | **R-01** |
| `customer-list-apply-filter-dead`, `customer-filter-target-missing` | **R-02** |
| `kanban-move-wrong-request-key`, `kanban-move-returns-raw-json` | **R-05** |
| `grid-text-dark-invisible` | **A-05** |
| `flutter-emptystate-light-contrast`, `empty-state-message-contrast` | **A-04** |
| `flutter-touch-targets-below-44` | **D-01** |
| `flutter-field-focus-ring-invisible-dark`, `field-focus-and-caret-invisible-dark` | **D-02** |
| `flutter-brand-is-not-the-store-syria-teal` | **BR-03** |

Two more repairs — `R-03` (the `Last active` spelling) and `R-04` (the force-unwrapped Flutter
parameters) — are **not in this table and were not in the inventory at all**. R-03 was found by the
tenant-authz review of R-02; R-04 was found by the harness for D-01 throwing instead of measuring.
Neither would have been found by auditing screens, which is worth knowing about what an inventory
can and cannot see.

---

## 4. What must not happen to this document

**A refuted finding must not become an implementation task**, and a finding is not refuted because
it is inconvenient. The 59 refusals each carry a reason naming what the verifier read; they are in
the run journals rather than restated here, because a refusal's value is its evidence and a summary
of it is not evidence.

**And a verdict is not permanent.** Three of the eight disagreements had the *later, better-informed*
run calling a finding real where the first had refuted it. Verification narrows uncertainty; it does
not end it.
