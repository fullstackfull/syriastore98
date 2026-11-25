# Phase 06 — the merchant ticket-status control, and the gate I wrote and then removed

**Three defects fixed. A fourth deliberately NOT fixed, and that is the part worth reading.**

`POST ticket-status-change/{id}` and the screen that calls it. Written after the change, because this
repository is public.

---

## 1. The gate that came out again

`SupportTicketController` has five methods. Four of them gate:

| method | permission |
|---|---|
| `index` | `Manage Support Ticket` |
| `edit` | `Replay Support Ticket` *(the misspelling is the shipped permission name)* |
| `conversion_store` | `Replay Support Ticket` |
| `destroy` | `Delete Support Ticket` |
| `ticket_status_change` | **none** |

The consistency argument is obvious, I acted on it, and **I was wrong to**. The gate was written, it
passed, and it was removed before commit.

**Why.** `tests/Feature/Security/TicketStatusChangeAudienceTest.php` already exists for this exact
route, and it had already settled what KIND of gap this is. The route is tenant-safe — `SupportTicket`
is one of the eleven models in `config/tenancy.php`'s `scoped_models` *and* it registers `StoreScope`,
so `SupportTicket::find($id)` on another tenant's id answers "Ticket not found." and nothing moves.
That is measured in that file, not inferred. So the missing permission is a **privilege question
inside a tenant**, which is owner decision `D-P6-14`, and that test pins today's audience as a
recorded starting fact rather than an approval. Its own failure message says what to do:

> *A principal holding none of the three support permissions can no longer change a ticket's status.
> That may be the RIGHT behaviour — but it is `D-P6-14`'s answer, and this test records the state the
> question was asked about. If a gate was added deliberately, record the decision and re-point this
> test at the new contract.*

Both of that file's cases went red under my gate. There is no owner decision, so adding it would have
been me answering `D-P6-14` myself and quietly reducing who can work a support queue. The control
worked exactly as designed: it exists so a gate cannot be added by accident.

### One new measurement, which is input to that decision

Taken while the gate was briefly in place, because it changes what the answer costs. A principal
holding none of the three support permissions — **including a tenant owner with no seeded role** —
already gets:

```
owner->isAbleTo('Replay Support Ticket')   false
owner->isAbleTo('Manage Support Ticket')   false
GET  support_ticket.index                  302 -> /
GET  support_ticket.edit                   302 -> /support_ticket
```

So they cannot reach the ticket list or the screen the dropdown lives on. The only route to this
writer is a hand-made POST. **Whoever answers `D-P6-14` should know that gating it is unlikely to
take a working control away from anyone** — which is an argument FOR gating it, and still not mine to
make.

That measurement also caught a trap in my own test: two of its cases acted as a bare owner on the
assumption that an owner can always act in their own store. They failed against the gated handler,
which *looked* like the gate being too strict. It was the fixture. CLAUDE.md records the same trap
from the order-action gate, where a permission check that refused the owner contradicted two tests
encoding the opposite contract.

---

## 1b. THE CLAIM THIS DOCUMENT WAS BUILT ON WAS WRONG, AND THE REVIEW CAUGHT IT

§1 above argued the deferral from "the route is tenant-safe". **That is over-broad, and independent
review refuted it. Execution settled it against me.**

`StoreScope` adds NO predicate on two paths:

- `MODE_SUPER_ADMIN` — returns early, deliberate and documented: the operator administers every store.
- `MODE_UNRESOLVED` — falls to `applyNullTenantPolicy()`, and `config/tenancy.php`'s
  `null_tenant => 'open'` adds nothing. This is CLAUDE.md's standing warning verbatim: *a handler
  that relies on the scope for isolation is unprotected for any principal that doesn't resolve a
  tenant. Never let the scope be your only tenant check.*

A `web` principal resolves no tenant when `getCurrentStore()` answers null — an empty
`current_store` **and** no store of their own. `UserController` copies the **creator's**
`current_store` onto a new sub-user and the operator's own column is normally empty, so an
operator-created staff row starts in exactly that state.

Measured before the fix, as a staff member of tenant A holding the shipped permissions, against
tenant B:

```
POST   /ticket-status-change/{B's ticket}   200  {"status":"success"}   B's status changed
GET    /support_ticket/{B's ticket}/edit    200  leaked B's ticket TITLE and BODY
DELETE /support_ticket/{B's ticket}         302  B's row was GONE
```

**So the gap was a cross-tenant READ, WRITE and DELETE.** Not an audience question. And
`TicketStatusChangeAudienceTest` — my own earlier file, which asserted the tenant boundary — never
reached it, because `makeStaff()` copies the owner's `current_store`, so its staff always resolved a
tenant. A test can assert a boundary and still never visit the principal for whom the boundary is
absent.

### The fix, and what it deliberately is not

`SupportTicketController::actingTenantStoreIds()` derives the tenant **from the principal's root**
and refuses when it cannot: `null` means the operator's deliberate bypass, an empty array means
refuse. Applied in `edit()`, `destroy()` and the status writer. `conversion_store()` was measured
and is already protected — not claimed as fixed.

It is **NOT** `->where('store_id', getCurrentStore())`. That helper returns the hardcoded `1` for
the operator, so it would silently confine the platform operator to store 1 — the precise failure
`StoreScope`'s bypass exists to avoid, and the reviewer flagged the one-liner before I could reach
for it.

The tenant comes from `creatorId()` rather than `current_store`, which is what makes this a
confinement and not a lockout: a staff member with an empty column whose tenant owns stores still
works normally, and a test says so. The root must be a type-`admin` merchant — the P1-SEC-67b rule,
because every merchant has `created_by = 1` and a merchant who demotes their own `type` would
otherwise resolve the **operator** as their root and inherit the operator's stores.

`D-P6-14` is unchanged. One fact was added to it for whoever answers: this writer is the **only**
method on the controller the operator can reach, since the other four `isAbleTo()` calls refuse them
by name.

### Three of my own mutations survived, and each exposed a real gap

| | |
|---|---|
| the resolver fails open for an unknown principal | **SURVIVED — and stayed that way.** No test reaches it; the routes all carry `auth` and `CustomAuth` puts a `Customer` on the `customers` guard. The code now says it is an untested, unreachable-by-construction guard rather than implying coverage. |
| an unresolvable tenant root fails open | SURVIVED → a case with `created_by = null` was added → CAUGHT |
| a non-admin tenant root fails open (P1-SEC-67b) | SURVIVED → **my fixture still owned a store, so the scope confined it and the rule was not what decided.** Rebuilt with a principal owning none → CAUGHT |

The last one is the useful one: a test that passes with the rule removed is not evidence for the rule.

---

## 2. The three defects that have no audience question in them

### 2.1 The ticket came from the request body

The route declares `{id}`; the handler read `$request->id`. A posted body field therefore chose which
ticket was written, and `HelpdeskTicketOwnershipTest` already encodes the opposite rule for the
Helpdesk row routes — *judged by the route parameter, not by the body*. This first-party handler did
not follow it.

**Cross-tenant is NOT the claim.** The audience test proves the scope blocks that. What this fixes is
redirection **within** a tenant: `POST ticket-status-change/A` with `id=B` wrote B. The test that
pins it puts both tickets in the **same store on purpose** — in different stores the scope would
refuse B and the case would pass whether or not the handler reads the route, which is exactly the
"passes for the wrong reason" trap CLAUDE.md records.

### 2.2 An unrecognised status was an HTTP 500

`SupportTicket::ticket_status_change()` compared the status against three literals in three identical
`if` blocks, each returning on a match, with **no `else`**. Any other value fell off the end of the
`if (! empty($ticket))` block and the method returned PHP's implicit NULL. The controller's next line
is `$responce['status']`, and reading an offset on null raises a warning Laravel promotes to
`ErrorException`. Driven with `status=Escalated to legal`: HTTP 500.

Every path now returns an array, and the three literals became one `STATUSES` const the controller
validates against as well. The three shipped statuses — `open`, `In Progress`, `Solved` — are
unchanged, and a test drives each one.

### 2.3 The screen: four separate problems, one cause each

**The dead dropdown.** Three different conditions decided three things that must agree:

```
the `dropdown-toggle` CLASS      in_array($status, ['open', 'In Progress', 'solved'])
the `data-bs-toggle` ATTRIBUTE   in_array($status, ['Solved'])
whether the MENU is rendered     in_array($status, ['open', 'In Progress', 'solved'])
```

The writer stores `'Solved'` with a capital S, so the lowercase `'solved'` in two of those lists
**matches nothing this application ever writes**. For a solved ticket: no menu, but a button still
carrying `data-bs-toggle="dropdown"` from an unconditional copy on the next line — so that attribute
appeared **twice** in that one state — plus `aria-haspopup="true"` and a hard-coded
`aria-expanded="true"`. A screen reader announced an already-expanded menu that was not on the page.

All three now derive from one `$ssTicketTransitions` map, and `aria-expanded` is `false` when closed.
The transitions are unchanged (`open → In Progress, Solved`; `In Progress → Solved`;
`Solved → nowhere`); which transitions exist is product behaviour and was not altered.

**The toast that reported success for every failure.** `show_toastr(... 'Ticket status updated
successfully' ...)` was in the AJAX `complete:` handler, which jQuery fires after success **and**
after failure. A refusal produced two toasts — the error, then the success one, in that order, the
reassuring one last. An HTTP failure (403, 422, 500) skipped `success:` entirely and produced **only**
the success toast: the merchant was told the status had changed when nothing was written. Same class
as ORD-12, where the order screen announced status changes that had not happened. The toast now
follows the response, and a new `error:` handler states the failure.

**Two unguarded dereferences, each an HTTP 500 — and this one was found by a test failing for a
reason that was not the dropdown.** The first run of the new view tests returned
`Attempt to read property "name" on null (View: resources/views/support/edit.blade.php)`. Two sites:

- `$ticket->UserData->name` — `customers` is both soft-deleted and store-scoped, so the relation
  answers null for a shopper who deleted their account and for one the resolved scope cannot see;
- `$conversion->replyBy()->name` — `replyBy()` ends in `->first()`, so it returns null when the
  sender's row is gone, and it runs **once per reply**, so one orphaned reply broke the whole
  conversation rather than hiding one name.

Both are null-safe now, falling back to `Unknown`, a key that already exists in both language files.
`replyBy()` is also an N+1; re-shaping it into a real relation is wider than this screen's repair and
is recorded here rather than done.

---

## 3. Evidence

| | |
|---|---|
| failing first | 5 of 9 door cases, and 4 of 8 control cases, failed against the unfixed code. The four door cases that passed from the start are the regression guards (anonymous refused by middleware; a missing ticket already handled; the three shipped statuses still write) |
| after | door **6 passed**, control **8 passed**, plus the pre-existing `TicketStatusChangeAudienceTest` **2 passed** — 16 together |
| mutations | **8 on the original three defects, 8 CAUGHT.** Two more targeted the gate and were deleted with it. **9 on the tenant confinement, 8 CAUGHT and 1 deliberately left surviving** (see §1b) |
| confinement | `SupportTicketTenantConfinementTest` **10 passed** — four hostile principals refused, three legitimate ones still working, the operator bypass asserted |
| neighbourhood | 111 passed + 1 declared skip across `CatalogueEndpointIntegrityTest`, `SupportTicketStoredXssTest` (P1-SEC-79's), `TicketStatusChangeAudienceTest`, `CustomerAccountBoundaryTest`, `BulkDeleteAuthorizationTest`, `BladeDivBalanceTest`, `IconOnlyControlNameTest`, `DataTableRawColumnsTest` |
| blocking suites | Unit **6,199 passed / 1 skipped**; Contract **45 passed** |

The eight mutations:

| | |
|---|---|
| the ticket id comes from the body again | CAUGHT |
| the model's status allowlist is removed (implicit NULL returns) | CAUGHT |
| the dropdown is offered for every status again | CAUGHT |
| the toggle attribute is unconditional again | CAUGHT |
| a closed menu is announced as expanded again | CAUGHT |
| the success toast moves back into `complete:` | CAUGHT |
| the customer name is dereferenced unguarded again | CAUGHT |
| the reply sender's name is dereferenced unguarded again | CAUGHT |

### Two censuses checked and NOT affected, measured rather than assumed

- `CatalogueEndpointIntegrityTest::DEAD_ROUTE_ACTIONS` names `SupportTicketController@create/@show/@store/@update`. None is implemented here, so the declared set is byte-identical. (Implementing them later **will** fail that test until the four entries are deleted — its own line says so. Budget it into that change.)
- `BulkDeleteAuthorizationTest` maps `'Delete Support Ticket' => 'SupportTicketController'`. `destroy()` is untouched.

### The script half is asserted on the source, and scoped

The toast is jQuery in a browser; a Feature test gets the HTML. So those two cases read the view's
source with comments stripped (`StripsSourceComments`, plus `{{-- --}}`) and **scoped to the `$.ajax`
call**, not the whole file — because the new comment block mentions both `complete:` and the success
string, and CLAUDE.md records a census being satisfied by prose about the thing it measures four
times in one day.

### Why the orphaned-reply fixture drives the merchant branch

`replyBy()` branches on `sender`: `'user'` reads `customer_id`, anything else reads `sender` as a
`users` id. The customer branch **cannot** be driven with a dangling id —
`support_conversions.customer_id` is a foreign key to `customers` with `ON DELETE CASCADE`, so SQLite
refuses the insert (my first fixture did exactly that). The merchant branch reads an id from a plain
text column with no constraint, which is precisely what a deleted staff account leaves behind, and it
reaches the identical `->first()` → null → `->name` path.

---

## 4. Recorded, not fixed

- **`D-P6-14`, the permission gate.** Owner's. §1 has the new measurement for it.
- **`SupportConversion::replyBy()` is an N+1** and is shaped as a method returning `->first()` rather
  than a relation. Null-safe now; re-shaping it is wider than this slice.
- **`edit()` returns nothing when the ticket is missing** inside its permitted branch — an empty HTTP
  200 rather than a 404. Same family as the model's implicit NULL, not the same door; no test drives
  it yet.
- **Five of the ten merchant ticket routes are dead** (`create`, `store`, `show`, `update`,
  `support_ticket.view`), answering `BadMethodCallException`/500. Already declared and pinned as a
  census set, so a known tracked condition. Implementing them is a feature, not a defect fix.
