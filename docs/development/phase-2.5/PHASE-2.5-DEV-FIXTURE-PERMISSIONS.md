# The development-fixture permissions the web harness needs

Owner instruction (2026-11-08): *"document the 13 development-fixture permissions required by the
web harness. Prefer a reproducible, test-only setup with minimum necessary permissions. Do not
silently grant privileges to production users or environments."*

**Nothing here touches production.** Every tool below refuses to run against anything but the
dev SQLite fixture, and fails closed before the application boots.

---

## 1. Why a permission gap is dangerous rather than merely inconvenient

`render-matrix.cjs` signs in as `merchant@example.test` and drives real pages. A WorkDo controller
the fixture cannot open **does not answer with an error**. It redirects — and the harness then
records **HTTP 200**, a valid direction, a valid theme sheet, and a screenshot of a page that is
not the one requested. That is the most dangerous reading this project can take, because it looks
exactly like evidence.

Measured, P2.5-WAVE-13: `/customer/1` answered 200 titled "Dashboard" in all four combinations.
Nine routes once produced eleven distinct images from seventeen drives.

### There are TWO redirect shapes, and the second defeated the original rule

The documented defence was *"a capture titled `Store Syria - Dashboard` is a refusal redirect"*.
That is true only for handlers that redirect to the dashboard. **Many WorkDo controllers refuse
with `redirect()->back()`**, which lands on the **referrer** — so the browser stays on whatever
page the harness visited last, and the capture carries that page's entirely legitimate title.

Measured 2026-11-09, driving `/coupon`, `/tag`, `/faqs` in that order:

| requested | landed | title | every old premise |
|---|---|---|---|
| `/coupon` | `/coupon` | `Store Syria - Coupon` | passed (correctly) |
| `/tag` | `/coupon` | `Store Syria - Coupon` | **passed — wrongly** |
| `/faqs` | `/coupon` | `Store Syria - Coupon` | **passed — wrongly** |

Three screenshots of the coupon page would have become evidence for three different surfaces. The
distinct-capture guard could not have caught it either: a `back()` refusal from a different
referrer yields a different image each time.

**`render-matrix.cjs` now asserts the landed path equals the requested path**, which catches every
redirect-based refusal regardless of where it points. It is phrased as an equality, not as a list
of known-bad destinations, because the known-bad list is what failed.

---

## 2. The thirteen, and what each one unblocks

Granted by `tools/grant-dev-harness-permissions.sh` — idempotent, computes the MISSING set first
and grants only those, so a clean fixture is left untouched.

| permission | the surface it makes reachable |
|---|---|
| `Manage Deliveryboy` | delivery-staff list |
| `Manage Menu` | storefront menu builder |
| `Manage Page` | CMS pages list |
| `Manage Blog` | blog post list |
| `Manage Blog Category` | blog category list |
| `Manage Contact Us` | contact-enquiry list |
| `Manage Newsletter` | newsletter subscriber list |
| `Manage Shipping Class` | shipping classes |
| `Manage Shipping Zone` | shipping zones |
| `Manage Shipping` | shipping methods |
| `Manage Tax` | tax rates (also needs the `taxes=on` store setting, which the script writes) |
| `Manage Support Ticket` | support ticket list |
| `Manage Stock Report` | stock report |

The script also enables the `taxes` store setting, because `Manage Tax` alone still leaves the tax
screen hidden.

---

## 3. Granting anything else

`tools/grant-dev-screen-permissions.php` is the general form — it takes permission names as
arguments, so a new surface needs **no new script**:

```bash
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file php docs/development/phase-2.5/tools/grant-dev-screen-permissions.php merchant@example.test "Manage Tag" "Manage Faqs"
```

Used on 2026-11-09 for the row-action group: `Manage Tag`, `Manage Faqs`, `Manage Testimonial`,
`Manage Product Question`, `Manage Product Label`, `Manage Product Attribute`,
`Manage Product Brand`, `Manage Product Category`. All four previously-refusing routes then landed
with their own titles in all four combinations.

`tools/grant-dev-finance-permissions.php` is the earlier, finance-specific version kept because
existing evidence references it.

---

## 4. The safety properties, which are the point

1. **Fixture only.** Each tool asserts the connection is the dev SQLite file *before* the
   application boots, so nothing can resolve a connection to the wrong database first. Run against
   a real database these tools would alter live records and print success — so they refuse.
2. **Minimum necessary.** The set is grown one measured refusal at a time. No permission is here
   because it seemed useful; each was added when a named route was observed to refuse.
3. **The fixture is never made a `super admin`.** It stays `users.type == 'admin'` — the root of
   its own tenant, which is what a merchant is. Elevating it would make seven settings tabs appear
   (Cookie, Cache, Storage, reCAPTCHA, ChatGPT, Style, SEO) and any capture of them would be a
   platform-operator surface photographed while pretending to be a merchant. Those are Phase 06.
4. **Idempotent.** Safe to re-run at the start of any session; with nothing missing it calls
   nothing.
5. **No controller was weakened.** A fixture gap is fixed in the fixture. Not once has a
   permission check been relaxed to make a screen render.
6. **`Role::forceCreate`, and a `flushCache()` at the end.** Plain `create()` drops `store_id`;
   and `Role`/`Permission` are among the few models that are **not** `Cachable` while `User` is,
   so a stale `User` row would keep the old role set for the rest of the process.

---

## 5. Running the harness from cold

```bash
docs/development/phase-2.5/tools/grant-dev-harness-permissions.sh
```

```bash
docs/development/phase-2.5/tools/serve-dev.sh &
```

Then confirm a route answers with **its own title** before trusting any capture — and now also
that `landed on` does not appear in the premise column.
