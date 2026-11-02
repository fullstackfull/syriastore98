<?php
/**
 * F184 harness fixture: an order-free variant product, so the PERMITTED confirmation path can be
 * driven in a browser.
 *
 * The dev fixture has exactly one variant-bearing product (id 1) and all seven of its orders name
 * it, so every confirmed delete there is correctly refused by the D-20 order-history block. That
 * proves "confirmation does not override a hard block" and proves nothing about the permitted
 * path — a server that refused everything would pass it. Hence this product.
 *
 * Idempotent: drops and recreates its own rows on every call, so each browser configuration starts
 * from the same three variants.
 */
$db = new PDO('sqlite:/home/user/syriastore98/database/p2dev.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = 9184;

$db->exec("DELETE FROM product_variants WHERE product_id = {$id}");
$db->exec("DELETE FROM products WHERE id = {$id}");

$cols = [];
foreach ($db->query("PRAGMA table_info(products)") as $c) { $cols[$c['name']] = $c; }

$row = [
    'id' => $id,
    'name' => 'F184 Order-free Scarf',
    'slug' => 'f184-order-free-scarf',
    'price' => 0,
    'product_stock' => 0,
    'status' => 1,
    'variant_product' => 1,
    'store_id' => 1,
    'created_by' => 2,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
];

/* Every NOT NULL column with no default has to be given something, or the insert fails and the
   harness reports "the order-free product rendered its variants: cards=0" — a fixture failure
   dressed as a finding. */
foreach ($cols as $name => $c) {
    if (array_key_exists($name, $row)) { continue; }
    if ((int) $c['notnull'] === 1 && $c['dflt_value'] === null) {
        $row[$name] = str_contains(strtolower($c['type']), 'int') || str_contains(strtolower($c['type']), 'dec')
            || str_contains(strtolower($c['type']), 'double') || str_contains(strtolower($c['type']), 'float') ? 0 : '';
    }
}

$names = array_keys($row);
$sql = 'INSERT INTO products (' . implode(',', $names) . ') VALUES (:' . implode(',:', $names) . ')';
$st = $db->prepare($sql);
foreach ($row as $k => $v) { $st->bindValue(':' . $k, $v); }
$st->execute();

$n = 0;
foreach ([['S-Plain', 101, 201, 31], ['M-Plain', 102, 202, 32], ['L-Plain', 103, 203, 33]] as [$variant, $vp, $pr, $stock]) {
    $n++;
    $db->prepare("INSERT INTO product_variants
        (product_id, variant, sku, price, variation_price, stock, low_stock_threshold, variation_option, store_id, created_at, updated_at)
        VALUES (:p, :v, :s, :pr, :vp, :st, 1, '', 1, :c, :u)")
       ->execute([':p' => $id, ':v' => $variant, ':s' => sprintf('F184-%04d', $n), ':pr' => $pr, ':vp' => $vp,
                  ':st' => $stock, ':c' => date('Y-m-d H:i:s'), ':u' => date('Y-m-d H:i:s')]);
}

printf("fixture: product %d with %d variants, no order history\n", $id, $n);
