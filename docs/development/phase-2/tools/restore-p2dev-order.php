<?php
/**
 * Puts fixture order 1 back the way `seed-p2dev.php` leaves it, after
 * `check-order-replace-live.cjs` has confirmed a replacement for real.
 *
 * WHY IT IS NEEDED RATHER THAN NICE TO HAVE. Confirming cancels order 1 and writes a replacement.
 * Every later run of that harness would then open a cancelled order, where the control is
 * CORRECTLY absent — so the "the control is on a replaceable order" assertion would fail, and the
 * two state-gate assertions would pass for the wrong reason. A harness that cannot be run twice is
 * not evidence; this is what makes the run repeatable.
 *
 * It touches nothing else: the replacement row is deleted by its link, and order 1's two mutated
 * columns are set back. `order_notes` written by the cancellation are removed by order id, because
 * they would otherwise accumulate one pair per run and the order-detail harness counts them.
 *
 * usage: DB_CONNECTION=sqlite DB_DATABASE=database/p2dev.sqlite php restore-p2dev-order.php
 */
$path = getenv('DB_DATABASE') ?: (__DIR__ . '/../../../../database/p2dev.sqlite');

if (! is_file($path)) {
    fwrite(STDERR, "fixture not found: {$path}\n");
    exit(2);
}

$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$replacements = $db->query('SELECT id FROM orders WHERE replaced_order_id = 1')->fetchAll(PDO::FETCH_COLUMN);

$db->beginTransaction();

foreach ($replacements as $id) {
    $db->prepare('DELETE FROM order_notes WHERE order_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
}

/* The cancellation writes its own notes on the ORIGINAL too. Removed by order id so the count the
   order-detail harness asserts does not drift upward one run at a time. */
$db->prepare("DELETE FROM order_notes WHERE order_id = 1 AND status IN ('Order status change', 'Stock Manage')")->execute();

/* Order 1's seeded state: awaiting fulfilment, unpaid. */
$db->prepare('UPDATE orders SET delivered_status = 0, payment_status = ?, cancel_date = NULL WHERE id = 1')
   ->execute(['Unpaid']);

/*
 * ======================================================================================
 * THE STOCK EVIDENCE D-15 CONDITION 4 DEMANDS, SEEDED HERE [2026-11-01]
 * ======================================================================================
 *
 * WHY THIS WAS ADDED, and it is the whole reason the harness stopped working. The E13
 * engine now refuses a replacement whose stock movement cannot be PROVEN -- condition 4
 * forbids treating silence as evidence that the original consumed stock -- so
 * `OrderReplacement::eligibility()` answers `stock_evidence_unknown` for any order with
 * no `stock_movements` rows. This fixture predates that table, so it had none, and
 * `OrderController::order_view()` therefore (correctly) rendered NO replace control.
 *
 * MEASURED before touching anything, against this very database:
 *
 *     order 1  status=0  payment=Unpaid          -> stock_evidence_unknown
 *     order 2  status=4  payment=Paid            -> already_dispatched
 *     ...  every other seeded order is dispatched
 *     stock_movements: 0 rows
 *
 * So the button's absence was the gate working, not a regression -- and
 * `check-order-replace-live.cjs` reported it as a 30-second Playwright timeout waiting
 * for `a.order_replace`, which reads like a broken feature. The INSTRUMENT was stale,
 * not the product.
 *
 * WHAT IS SEEDED, AND WHY IT IS HONEST. Exactly the rows order 1's own placement would
 * have written: one `out`/`applied` movement per line of its `product_json`, with the
 * line's own quantity, `reason = order_place`. Nothing is invented -- the quantities and
 * product ids are read back out of the order itself rather than hardcoded, so if the
 * fixture's basket ever changes the evidence follows it. This makes order 1 ELIGIBLE, so
 * the harness can drive the positive path again; it does NOT weaken the gate, which still
 * refuses every order whose evidence is absent.
 *
 * Idempotent: the rows are deleted first, so repeated runs do not accumulate.
 */
$db->prepare('DELETE FROM stock_movements WHERE order_id = 1')->execute();

$order = $db->query('SELECT store_id, product_json FROM orders WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$lines = json_decode((string) ($order['product_json'] ?? ''), true);
$seeded = 0;

if (is_array($lines)) {
    $insert = $db->prepare(
        'INSERT INTO stock_movements
            (store_id, order_id, product_id, variant_id, direction, quantity, applied, outcome, reason, created_at, updated_at)
         VALUES (?, 1, ?, ?, \'out\', ?, 1, \'applied\', \'order_place\', ?, ?)'
    );

    $now = date('Y-m-d H:i:s');

    foreach ($lines as $line) {
        if (! is_array($line)) {
            continue;
        }

        $productId = $line['product_id'] ?? null;
        $variantId = $line['variant_id'] ?? null;
        $quantity = $line['quantity'] ?? ($line['qty'] ?? null);

        if (empty($productId) && empty($variantId)) {
            continue;
        }

        $insert->execute([
            (int) ($order['store_id'] ?? 0),
            $productId !== null ? (int) $productId : null,
            empty($variantId) ? null : (int) $variantId,
            (int) ($quantity ?: 1),
            $now,
            $now,
        ]);
        $seeded++;
    }
}

$db->commit();

$left = (int) $db->query('SELECT COUNT(*) FROM orders WHERE replaced_order_id = 1')->fetchColumn();
$state = $db->query('SELECT delivered_status FROM orders WHERE id = 1')->fetchColumn();

if ($left !== 0 || (int) $state !== 0) {
    fwrite(STDERR, "restore INCOMPLETE: {$left} replacement(s) left, delivered_status={$state}\n");
    exit(1);
}

/*
 * AND THE RESTORE REPORTS WHETHER IT ACTUALLY MADE THE ORDER ELIGIBLE, rather than leaving the
 * browser harness to discover it as a timeout. A fixture that cannot be made eligible is a
 * precondition failure and must say so in one line.
 */
$evidence = (int) $db->query('SELECT COUNT(*) FROM stock_movements WHERE order_id = 1 AND direction = \'out\' AND applied = 1')->fetchColumn();

if ($evidence === 0) {
    fwrite(STDERR, "restore INCOMPLETE: no stock evidence seeded for order 1, so "
        . "OrderReplacement::eligibility() will answer stock_evidence_unknown and the replace "
        . "control will correctly NOT render. The harness cannot drive the positive path.\n");
    exit(1);
}

echo "restored: order 1 is delivered_status=0, " . count($replacements) . " replacement(s) removed, "
    . "{$evidence} out/applied stock movement(s) seeded (D-15 condition 4)\n";
