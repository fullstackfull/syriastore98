# F146 / F150 — `orders.payment_type`: what is in the column, and what can honestly be proved about it

**Status: ANALYSIS AND AN ISOLATED-ENVIRONMENT PLAN. No production data is changed by this
document, and none may be.** Owner instruction, 2026-10-28 §1: *"لا أوافق حالياً على تحديث تلقائي
لسجلات الطلبات القديمة في الإنتاج … يمكن إعداد واختبار خطة تصحيح في بيئة معزولة، لكن لا تنفذ Backfill
على بيانات الإنتاج دون موافقة مستقلة."*

The forward fix is already in place and is **not** affected by anything below: `PosController`
writes the literal `'POS'`, so every till sale created from now on is correct in every locale.

---

## 1. The inventory of translated patterns

All **16** shipped locale files were read. `"POS"` is translated in **six** of them:

| locale | stored `payment_type` for a till sale |
|---|---|
| `es` | `Punto de venta` |
| `fr` | `Le point de vente` |
| `ja` | `POS ポイント` |
| `pl` | `Pozycja POS` |
| `ru` | `ПОЗ` |
| `zh` | `没有` |
| `ar`, `da`, `de`, `en`, `he`, `it`, `nl`, `pt`, `pt-br`, `tr` | `POS` (unchanged) |

Note `zh`: `没有` means *"does not have / none"*. The Chinese file does not translate the term —
it mistranslates it into a negation.

## 2. Which records can be proved POS, and which cannot

A value in this column is provable evidence of its writer **only if no other writer could have
produced the same string**. That was measured across all 40 host-order `payment_type` literals ×
all 16 locales:

**PROVABLE — emitted by `PosController` and by nothing else (6 values):**
`POS`, `Punto de venta`, `Le point de vente`, `POS ポイント`, `Pozycja POS`, `ПОЗ`.

**NOT PROVABLE — one value, and this is the finding that matters:**

> `'没有'` is also what the `zh` locale stores for **Aamarpay**, **PayU**, **paytm** and
> **Toyyibpay**. Five different payment methods collapse onto one string, because the Chinese file
> renders all of them as "none".

A row holding `'没有'` therefore **cannot be classified as POS without guessing**, which is exactly
what the instruction forbids. It is excluded from the plan in §5 and must stay excluded unless
corroborating evidence below is first shown to be conclusive **on the real data**.

**Corroborating signals available for the ambiguous rows** (measured in `PosController`, not
assumed): a till sale writes **no `order_billing_details` row at all** (the controller never
mentions the model), sets `delivery_price = ''` and `payment_status = 'Paid'`. Each of these is
suggestive and none is proof on its own; whether their combination is decisive is a question to
settle against a restored dump in the isolated environment, not here.

## 3. F150 — the defect is not POS-specific, and that changes the shape of the question

Measured across `app/`, excluding plan-order writers:

- **40** distinct values are written to `orders.payment_type` by host-order writers.
- **34 of the 40 are written through `__()`**, so the stored value depends on the UI language of
  the session that created the order. Only six are written as literals — and `POS` is one of them
  only because F146 just fixed it.
- The translations are frequently not translations at all but machine-mangled brand names:
  `DPO Pay` → `راتب موظف حماية البيانات` ("the data protection officer's salary"), `Flutterwave` →
  `"موجة التفجير"` ("blast wave"), `Iyzipay` and `Mollie` → `- ¿ Qué ?`, `Sspay` → `- لا` ("no").

**And there are three disagreeing vocabularies for this one column:**

| vocabulary | example | used by |
|---|---|---|
| translated brand name | `__('Bank Transfer')` | the ~34 gateway-callback writers |
| lowercase key | `bank_transfer` | `Utility::payment_data()`'s branches |
| lowercase key | `bank_transfer` | `OrderDataTable`'s display map |

Storefront checkout stores the **request's** `payment_type` (the lowercase keys), while the
gateway-callback writers store a translated brand name. The consequence is visible without any
locale involved: **23 of the 40 written values are absent from the display map**, so Bank Transfer,
PayPal, Khalti, Skrill, PayFast, PhonePe, Sofort and sixteen others render `-` in the order list's
Payment Type column **in every locale, English included**.

F146 was one instance of a 34-instance class. **Recorded for decision; not fixed here** — changing
what 34 writers store is a material change to payment data, which the standing rules reserve to the
owner.

## 4. Impact of any correction

| surface | effect of normalising a stored value to its literal |
|---|---|
| **Payment behaviour** | `Utility::payment_data($order->payment_type, $store_id)` branches on the lowercase keys and returns `status => 'off', name => 'Other Payment'` for anything it does not recognise. A mis-localised value already lands in that default, so normalising to the English literal does **not** make it worse — but it does not make it right either, because the literal is not the key `payment_data()` expects. **No payment is taken or re-taken by any correction: this column is a record of a completed transaction, not an instruction to charge.** |
| **Invoices and the order list** | `OrderDataTable`'s map and the order views key on the literal. Normalising restores the Payment Type cell for the six POS values. The 23 unmapped values stay broken until F150 is decided. |
| **Flutter and Lynomia** | Three branches in `Order::order_detail()` compare `payment_type == 'POS'` and shape the order-detail API response: the product-line reshape, the tax/coupon block, and the customer block. A mis-localised till sale currently takes the **non-POS** path, so it reads an `order_billing_details` row that does not exist and names the buyer `' '` instead of `Walk-in Customer`. Normalising moves it to the correct branch — a **change in the API response for those orders**, which is the intended repair but is still a change, and is why this is a decision rather than a cleanup. No key is added or removed; only values change. |

## 5. The correction plan — for an isolated environment only

**Preconditions.** A restored copy of the production dump in a throwaway database; no application
traffic against it; a full row-level before/after export retained.

**Step 1 — measure, change nothing.** Count the candidates and confirm the ambiguous set is handled
separately:

```sql
SELECT payment_type, COUNT(*) AS rows_affected, MIN(order_date), MAX(order_date)
FROM orders
WHERE payment_type IN ('Punto de venta','Le point de vente','POS ポイント','Pozycja POS','ПОЗ')
GROUP BY payment_type;

-- the ambiguous one, reported separately and NOT included in any UPDATE
SELECT COUNT(*) FROM orders WHERE payment_type = '没有';
```

**Step 2 — corroborate** each candidate against the signals in §2 (no `order_billing_details` row,
`delivery_price` empty, `payment_status = 'Paid'`) and report any row that fails them rather than
updating it.

**Step 3 — the update, five values only, never `'没有'`:**

```sql
UPDATE orders
   SET payment_type = 'POS'
 WHERE payment_type IN ('Punto de venta','Le point de vente','POS ポイント','Pozycja POS','ПОЗ');
```

**Step 4 — verify** that each affected order's `Order::order_detail()` response now takes the POS
branch, that its totals are byte-identical, and that no row outside the measured set changed.

**This is prepared and testable. It is NOT approved to run on production, and nothing in this
repository runs it.** There is no migration, no seeder and no command implementing it — the SQL
above is the whole artefact, deliberately, so it cannot be executed by accident.

## 6. What is deliberately not proposed

- No backfill of `'没有'` rows. Five payment methods share that string.
- No change to the 34 translator-written writers, and no change to the three disagreeing
  vocabularies. That is F150, and it is a material payments decision.
- No change to `Utility::payment_data()`'s branches.
- No repair of the locale files' mangled brand names, which would alter stored values for every
  future order written by those 34 writers and is the same decision from the other end.
