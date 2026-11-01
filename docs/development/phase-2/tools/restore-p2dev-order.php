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

$db->commit();

$left = (int) $db->query('SELECT COUNT(*) FROM orders WHERE replaced_order_id = 1')->fetchColumn();
$state = $db->query('SELECT delivered_status FROM orders WHERE id = 1')->fetchColumn();

if ($left !== 0 || (int) $state !== 0) {
    fwrite(STDERR, "restore INCOMPLETE: {$left} replacement(s) left, delivered_status={$state}\n");
    exit(1);
}

echo "restored: order 1 is delivered_status=0, " . count($replacements) . " replacement(s) removed\n";
