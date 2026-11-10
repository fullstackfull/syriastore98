# P2.5-WEB-DOCS — two merchant surfaces driven, and the Arabic stutter one of them exposed

Both reached on the dev fixture at 127.0.0.1:8899, signed in as `merchant@example.test`, in all
four language/direction/theme combinations.

| | |
|---|---|
| `_refund_request__*` | `GET /refund-request` — 4 of 4 clean |
| `_shippinglabel_pdf__*` | `GET shippinglabel/pdf/{id}` — the printable courier label |

## The refund request list

4 rows, 0 not clean: correct `dir`, correct theme sheet, and its own page title in both languages.

**It needed a permission the dev fixture did not hold.** `OrderRefundController::index` gates on
`Manage Order Refund Request`, and without it the route answers `redirect()->back()` — so the
harness recorded HTTP 200 and photographed the **dashboard**, four times, reporting `ok` on the
chrome. That is the trap `serve-dev.sh` documents in its own header and it caught this wave too;
the permission was granted to the fixture account only, through the existing
`grant-dev-screen-permissions.php`, and the capture rerun.

## The printable shipping label

It renders correctly in both directions — `FROM`/`To` become `من`/`إلى` on the opposite edge, the
barcode moves with them, and the item table mirrors.

**Its two dark captures are byte-for-byte identical to the light ones**, and that is right rather
than a miss: a print document is white paper and carries no theme sheet. Measured, not assumed —
the two directions differ by 924 pixels and the two themes by none. The route's premise check
reports `PREMISE-FAIL` on the dark pair for exactly that reason, so the row is claimed on what the
pixels show rather than on what the premise column says.

**The invoice logo renders broken in all four, and it is an environment gap rather than a
defect.** `DocumentDirection::invoiceLogoFor()` falls back to `storage/uploads/logo/logo.png`;
this container has `storage/app/public/uploads/` **empty** and `public/storage` is a 62-byte
regular file rather than a working link, so every uploaded asset 404s here. The same gap is what
produces the three `404 (Not Found)` console lines the harness reports on `/dashboard`. Nothing in
`lib/` or `resources/` is at fault and nothing was changed for it.

## What this wave did NOT manage to capture, and why

* **`GET order-receipt/{id}`** answered 200 and the image is of the PREVIOUS page in the
  harness's sequence, not of a receipt — so the row stays **PENDING** rather than being claimed on
  a photograph of something else.

  **The product is not at fault, and that was measured rather than assumed.** Driven directly on
  the same fixture: `Order::order_detail(1)` returns a real order with no `message` and
  `store_id = 1`, and `view('order.receipt', …)->render()` with the controller's own four
  variables produces **8,314 bytes**. The shipping label, which decrypts the same id the same way,
  captured cleanly. So `order_receipt` resolves and renders; what failed is the capture, and the
  honest outcome is a row still owed a photograph rather than a defect report with nothing behind
  it.
* **`GET user-info/{id}`** is a fragment, not a page: no `<title>`, no `dir`, and the raw
  unstyled markup of a store-and-staff panel. It is loaded into a screen over XHR, so its output
  belongs to that screen's capture.

## And the defect the Arabic title exposed

The list's Arabic page title came back **"طلب طلب استرداد"** — *"request request refund"*.
`Order Refund Request` had been translated by gluing "Order" → "طلب" onto "Refund Request" →
"طلب استرداد".

A census over the whole file found **8 of 3,509** values with an immediately repeated word, and
every one was a defect: `Add Addon` → "add add", `View Demo` → "view view", `Enter SVG icon code`
→ "code code the-code", `OTP has been not matched.` → a sentence that never mentions a code. All
eight corrected, and `tests/Unit/Merchant/ArabicDoesNotStutterTest` now fails on a ninth —
measured in both directions by restoring one of them.
