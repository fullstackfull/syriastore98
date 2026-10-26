<?php
use App\Models\{User, Store, Role, Permission, Product, Order};
use Illuminate\Support\Facades\{DB, Hash};

// NO REAL CUSTOMER DATA. Every value below is invented; the brief forbids production
// data in screenshots and the #120 dump is not on this branch.

$op = User::firstOrCreate(['email' => 'operator@example.test'], [
    'name' => 'Platform Operator', 'type' => 'super admin', 'password' => Hash::make('password'),
    'is_active' => 1, 'email_verified_at' => now(), 'created_by' => null, 'language' => 'en',
]);

$merchant = User::firstOrCreate(['email' => 'merchant@example.test'], [
    'name' => 'Amina Haddad', 'type' => 'admin', 'password' => Hash::make('password'),
    'is_active' => 1, 'email_verified_at' => now(), 'created_by' => $op->id, 'plan_id' => 1, 'language' => 'en',
]);

$store = Store::where('created_by', $merchant->id)->first();
if (! $store) {
    $store = Store::create([
        'name' => 'Damascus Textiles', 'email' => 'shop@example.test', 'slug' => 'damascus-textiles',
        'theme_id' => 'stylique', 'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
    ]);
}
$second = Store::where('created_by', $merchant->id)->where('slug', 'aleppo-soap')->first();
if (! $second) {
    $second = Store::create([
        'name' => 'Aleppo Soap Co', 'email' => 'soap@example.test', 'slug' => 'aleppo-soap',
        'theme_id' => 'stylique', 'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
    ]);
}
$merchant->current_store = $store->id;
$merchant->save();

// A role holding the merchant permissions, so the sidebar and the screens render.
$role = Role::where('name', 'p2dev-owner')->first();
if (! $role) {
    $role = Role::forceCreate([
        'name' => 'p2dev-owner', 'display_name' => 'Owner', 'guard_name' => 'web',
        'created_by' => $merchant->id, 'store_id' => $store->id,
    ]);
}
foreach ([
    'Manage Dashboard','Manage Order','Show Order','Manage Products','Create Product','Edit Product','Delete Product',
    // THE PLURAL NAMES ARE THE ONES THE CONTROLLER ACTUALLY CHECKS, and the singular ones above are
    // not synonyms for them. `ProductController::update()` gates on `isAbleTo('Edit Products')` and
    // `destroy()` on `'Delete Products'` -- both defined in `app/Models/Utility.php` and granted to a
    // real tenant owner there. The fixture held only `Edit Product` / `Delete Product`, so the
    // merchant could open the editor and never save: the update answered a `redirect()->back()` from
    // the permission gate, which the page showed as nothing at all. Measured, and the same class of
    // fixture gap as F33 -- a missing permission reads exactly like a broken button.
    'Edit Products','Delete Products',
    'Manage Product Category','Manage Product Brand','Manage Customer','Manage User','Manage Role',
    // P2-05-1 grants the EDIT permissions the four converted toggles now require, plus the
    // label and customer-status ones the lists gate their switches on. All five are seeded in
    // app/Models/Utility.php and granted to a real tenant owner's role there, so this brings
    // the dev fixture in line with production rather than loosening anything -- measured: the
    // fixture merchant got 403 on /product-brand-status and was redirected off /product-label.
    'Edit Product Brand','Manage Product Label','Edit Product Label','Status Customer',
    'Manage Store Setting','Manage Reports','Manage Order Reports','Manage Stock Reports','Manage Coupon',
    // P2-04-6. The order detail page gates its ENTIRE notes panel on `Manage Order Note`, and
    // the fixture merchant did not hold it -- so seeding 35 notes changed nothing on screen and
    // the panel still rendered as absent. All three are granted to a real tenant owner's role in
    // `app/Models/Utility.php:2874-2876`, so this brings the fixture in line with production
    // rather than loosening it. Measured: `isAbleTo('Manage Order Note')` was false for the
    // fixture merchant while `Show Order` was true, which is why the page opened at all.
    'Manage Order Note','Create Order Note','Delete Order Note',
] as $name) {
    $p = Permission::firstOrCreate(['name' => $name], ['display_name' => $name]);
    if (! $role->hasPermission($name)) { $role->givePermission($p); }
}
if (! $merchant->hasRole($role->name)) { $merchant->addRole($role); }
$merchant->flushCache();

// Products, with a deliberate mix of stock states so the list shows real badges.
$rows = [
    ['Damask Cotton Scarf', 24.00, 48, 0],
    ['Hand-loomed Table Runner', 65.00, 3, 5],
    ['Brocade Cushion Cover', 32.50, 0, 5],
    ['Linen Tea Towel Set', 18.00, 120, 10],
    ['Silk Wall Hanging', 180.00, 7, 5],
];
/*
 * P2-06-4 NEEDS MORE THAN ONE PAGE. `check-table-prefs-live.cjs` asserts that a restored page
 * NUMBER is dropped, and with five rows there is no page two -- `t.page(1)` is a no-op, `start`
 * stays 0, and that assertion passes against a feature that stores nothing. Measured: the first
 * version of that check passed with the sanitiser disabled, which is exactly the vacuous shape
 * this project keeps catching. Thirty-two rows gives two pages at a length of 25.
 *
 * "Panel" is in every generated name ON PURPOSE, so a search can match many rows while matching
 * FEWER THAN ALL of them -- a filtered count that differs from the total is what makes
 * "the search term was dropped" measurable rather than assumed.
 */
for ($i = 1; $i <= 27; $i++) {
    $rows[] = [sprintf('Woven Panel No. %02d', $i), 12.00 + $i, ($i % 7) * 4, 5];
}

foreach ($rows as [$name, $price, $stock, $low]) {
    // forceCreate: `created_by` is NOT NULL in the schema and NOT in Product's $fillable,
    // so a mass-assigned create drops it and the insert fails on the constraint.
    $slug = \Illuminate\Support\Str::slug($name);
    if (Product::where('slug', $slug)->where('store_id', $store->id)->exists()) { continue; }
    Product::forceCreate([
        'slug' => $slug, 'store_id' => $store->id,
        'name' => $name, 'description' => 'A sample catalogue row for Phase 02 screenshots.',
        'price' => $price, 'sale_price' => 0, 'track_stock' => 1, 'product_stock' => $stock,
        'low_stock_threshold' => $low, 'status' => 1, 'product_type' => null,
        'variant_product' => 0, 'created_by' => $merchant->id,
        'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
    ]);
}

/*
 * P2-05-1 fixtures: brands, labels and customers, so the three toggles that became POST can be
 * driven in a real browser. Invented data only -- the brief forbids production data in
 * screenshots and the #120 dump is not on this branch.
 *
 * `created_by` and `store_id` are set EXPLICITLY on every row: neither is in the models'
 * $fillable, so a mass-assigned create drops them and the row becomes invisible to the store
 * predicates the handlers now carry.
 */
foreach ([['Damascene Silk', 1, 1], ['Aleppo Weave', 1, 0], ['Homs Cotton', 0, 0]] as [$bname, $bstatus, $bpopular]) {
    $bslug = \Illuminate\Support\Str::slug($bname);
    if (! \App\Models\ProductBrand::where('slug', $bslug)->where('store_id', $store->id)->exists()) {
        \App\Models\ProductBrand::forceCreate([
            'name' => $bname, 'slug' => $bslug, 'logo' => null,
            'status' => $bstatus, 'is_popular' => $bpopular,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
    }
}

foreach ([['New Arrival', 1], ['Clearance', 1], ['Limited', 0]] as [$lname, $lstatus]) {
    $lslug = \Illuminate\Support\Str::slug($lname);
    if (! \App\Models\ProductLabel::where('slug', $lslug)->where('store_id', $store->id)->exists()) {
        \App\Models\ProductLabel::forceCreate([
            'name' => $lname, 'slug' => $lslug, 'status' => $lstatus,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
    }
}

foreach ([['Layla', 'Haddad', 1], ['Omar', 'Khalil', 1], ['Nour', 'Saleh', 0]] as [$first, $last, $cstatus]) {
    $email = strtolower($first . '.' . $last) . '@example.test';

    /*
     * RESTORE A SOFT-DELETED FIXTURE CUSTOMER rather than skip it, and the reason is a measured
     * trap rather than tidiness.
     *
     * `withoutGlobalScopes()` drops the SoftDeletingScope along with StoreScope, so an ARCHIVED
     * customer still satisfies `exists()` and the create below was skipped -- permanently. Driving
     * the customer list's delete control archived all three fixture customers, and from then on
     * every reseed left the screen empty while reporting success. A live browser check then
     * measured an empty list and passed its accessible-name and contrast assertions **vacuously**,
     * which is the exact failure mode CLAUDE.md warns about: the fixture was gone, not the defect
     * fixed.
     *
     * `customers.deleted_at` is the only thing to undo -- `CustomerController::destroy` soft
     * deletes -- so the row comes back with its own id, and anything referencing it still resolves.
     */
    $archived = \App\Models\Customer::withoutGlobalScopes()
        ->where('email', $email)
        ->whereNotNull('deleted_at')
        ->first();

    if ($archived !== null) {
        \DB::table('customers')->where('id', $archived->id)->update([
            'deleted_at' => null,
            'status' => $cstatus,
            'regiester_date' => $archived->regiester_date ?: now()->subDays(30),
            'updated_at' => now(),
        ]);
    }

    if (! \App\Models\Customer::withoutGlobalScopes()->where('email', $email)->exists()) {
        /*
         * `regiester_date` (sic -- the column is misspelled in the schema) is REQUIRED for the
         * status switch to render: customer/status.blade.php wraps it in
         * `@if ($customer->regiester_date != null)`. Measured -- without it the customer list
         * showed three raw zeros where the switch belongs, which read as a defect and was a
         * fixture gap.
         */
        \App\Models\Customer::forceCreate([
            'first_name' => $first, 'last_name' => $last, 'email' => $email,
            'password' => Hash::make('password'), 'status' => $cstatus,
            'regiester_date' => now()->subDays(30),
            'store_id' => $store->id,
        ]);
    }
}

// Orders across the statuses the dashboard partitions, including 7 and 8 -- the two
// values that are NOT fulfilment states (ledger F0), so the preview's three-reading
// presentation has something real to show.
$orders = [
    ['ORD-2041', 0, 'Unpaid', 148.00],
    ['ORD-2042', 4, 'Paid', 65.00],
    ['ORD-2043', 6, 'Paid', 212.50],
    ['ORD-2044', 1, 'Paid', 36.00],
    ['ORD-2045', 2, 'Unpaid', 180.00],
    ['ORD-2046', 7, 'Partially Paid', 97.00],
    ['ORD-2047', 8, 'Unpaid', 54.00],
];

/*
 * `orders.product_id` IS WHAT THE DASHBOARD'S BEST-SELLING BLOCK READS, not `product_json`.
 * `HomeController` plucks `product_id`, splits it on commas and counts the ids. Leaving it at
 * its default of '0' meant `topSellingProductIds` was `[0]`, nothing matched, and the block
 * rendered its EMPTY state while looking perfectly healthy -- so the feature was built, shipped
 * and never once seen with data in it. Found by driving the dashboard and reading the card.
 *
 * The lists below give the orders overlapping products, so one is genuinely the best seller
 * rather than every product tying at one.
 */
$ssOrderProducts = [
    'ORD-2041' => '1,4',
    'ORD-2042' => '1,2',
    'ORD-2043' => '1,3',
    'ORD-2044' => '2,4',
    'ORD-2045' => '1',
    'ORD-2046' => '2',
    'ORD-2047' => '5',
];

foreach ($orders as [$ref, $status, $pay, $total]) {
    if (Order::where('product_order_id', $ref)->exists()) { continue; }
    Order::create([
        'product_order_id' => $ref,
        'order_date' => now()->subDays(random_int(0, 20)),
        'customer_id' => 0, 'is_guest' => 1,
        'product_json' => json_encode([
            ['product_id' => 1, 'name' => 'Damask Cotton Scarf', 'qty' => 2, 'price' => 24.00,
             'total_orignal_price' => 48.00, 'final_price' => 48.00],
            ['product_id' => 4, 'name' => 'Linen Tea Towel Set', 'qty' => 1, 'price' => 18.00,
             'total_orignal_price' => 18.00, 'final_price' => 18.00],
        ]),
        // `product_price` is what order_detail() reports as the SUBTOTAL
        // (Order.php:516, `SetNumber($order->product_price)`). Leaving it unset made the
        // panel show "Subtotal $0,00" beside real line items -- a fixture artifact, found in
        // a screenshot of the running application.
        'product_price' => 66.00,
        // See the note above the list: this, not product_json, is what the dashboard counts.
        'product_id' => $ssOrderProducts[$ref] ?? '1',
        'tax_price' => 0, 'delivery_price' => 0, 'coupon_price' => 0,
        'final_price' => $total, 'payment_type' => 'cod', 'payment_status' => $pay,
        'delivered_status' => $status, 'store_id' => $store->id,
    ]);
}

/*
 * THE ORDER DETAIL PAGE'S THREE EMPTY BLOCKS.
 * =================================================================================================
 *
 * `/order-view/{id}` renders a shipping card, a billing card and an order-notes panel, and NONE of
 * them has ever been seen with content in it: `order_billing_details` and `order_notes` were both
 * EMPTY in this fixture, and so were `countries`, `states` and `cities`, which the two address
 * cards resolve their state/country/city through. Every field rendered blank and the notes panel
 * rendered nothing at all.
 *
 * That is P2-02-9's defect on a second page. An empty block looks exactly like a healthy block
 * with nothing to show, which is what it is for -- so a screenshot of a blank card is not evidence
 * that the card is wrong, and it is not evidence that it is right either. Seeding these before the
 * design-system pass is the only way the pass gets reviewed against the page merchants see.
 *
 * ONE ROW PER ORDER, and ONE NOTE OF EACH OF THE FIVE KINDS, because the notes panel styles them
 * differently and the five branches had already drifted apart (two carry `text-white` on the
 * delete icon and three do not). All five have to be on screen at once to see that.
 */
$ssCountry = DB::table('countries')->where('name', 'Syria')->value('id')
    ?: DB::table('countries')->insertGetId(['name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);

$ssState = DB::table('states')->where('name', 'Damascus Governorate')->value('id')
    ?: DB::table('states')->insertGetId([
        'name' => 'Damascus Governorate', 'country_id' => $ssCountry,
        'created_at' => now(), 'updated_at' => now(),
    ]);

$ssCity = DB::table('cities')->where('name', 'Damascus')->value('id')
    ?: DB::table('cities')->insertGetId([
        'name' => 'Damascus', 'country_id' => $ssCountry, 'state_id' => $ssState,
        'created_at' => now(), 'updated_at' => now(),
    ]);

/*
 * NOT REAL PEOPLE AND NOT REAL CONTACT DETAILS. The brief forbids real customer data in a
 * screenshot, and this fixture exists to be screenshotted. The name is invented, the address is a
 * street that is not one, and the mailbox and phone are in the reserved ranges
 * (`example.test`, +963 9XX XXX XXX with an all-zero subscriber part).
 */
foreach (Order::where('store_id', $store->id)->get() as $ssOrder) {
    if (DB::table('order_billing_details')->where('order_id', $ssOrder->id)->exists()) { continue; }

    DB::table('order_billing_details')->insert([
        'order_id' => $ssOrder->id,
        'product_order_id' => $ssOrder->product_order_id,
        'first_name' => 'Rana', 'last_name' => 'Khalil',
        'email' => 'rana.khalil@example.test',
        'telephone' => '+963 900 000 000',
        'company_name' => '',
        'address' => '12 Souk Al-Hamidiyah', 'postcode' => '11001',
        'country' => $ssCountry, 'state' => $ssState, 'city' => $ssCity,
        'delivery_address' => '12 Souk Al-Hamidiyah', 'delivery_postcode' => '11001',
        'delivery_country' => $ssCountry, 'delivery_state' => $ssState, 'delivery_city' => $ssCity,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/*
 * The five note kinds, on EVERY order rather than on one.
 *
 * The first attempt put them on `orderBy('id')->first()` and the browser check opened the order
 * the LIST shows first, which is a different one -- so the panel was still empty and the run
 * still reported `notes=0`. Rather than guess which order a reviewer will open, or encode the
 * list's sort order here where it would silently go stale, every order carries the set. Five
 * notes on seven orders is a fixture, not a dataset.
 *
 * The `status` / `note_type` pairs are the ones `order/view.blade.php` branches on, read from
 * the view rather than invented -- otherwise the fixture would exercise a sixth branch that
 * does not exist and leave one of the five real ones unseen.
 */
foreach (Order::where('store_id', $store->id)->get() as $ssNoteOrder) {
    if (\App\Models\OrderNote::where('order_id', $ssNoteOrder->id)->exists()) { continue; }

    foreach ([
        ['Stock Manage',        null,            'Stock reduced by 2 for Damask Cotton Scarf.'],
        ['Order Created',       null,            'Order placed from the storefront.'],
        ['Order status change', null,            'Status changed to Confirmed.'],
        ['',                    'private_note',  'Customer asked for delivery after 5pm. Not visible to them.'],
        ['',                    'to_customer',   'Your order is being prepared and ships tomorrow.'],
    ] as [$ssStatus, $ssType, $ssText]) {
        \App\Models\OrderNote::create([
            'order_id' => $ssNoteOrder->id,
            // NULL, not 0: `order_notes.customer_id` carries a FOREIGN KEY to `customers`, and
            // SQLite enforces it, so 0 fails the insert. These notes are written by staff and
            // have no customer anyway -- which is the same reason the page cannot attribute
            // them to anyone (there is no author column at all).
            'customer_id' => null,
            'notes' => $ssText,
            'note_type' => $ssType,
            'status' => $ssStatus,
            'store_id' => $store->id,
        ]);
    }
}

/*
 * A PLAN, because the product editor cannot save without one.
 *
 * `ProductController::store()` gates on the merchant's plan:
 *
 *     $plan = Plan::find(User::find($user->creatorId())->plan_id);
 *     ... $total_products < $plan->max_products || $plan->max_products == -1
 *
 * The fixture merchant carries `plan_id = 1` and the `plans` table was EMPTY, so `$plan` was
 * null and the save died with
 *
 *     {"flag":"error","msg":"Attempt to read property \"max_products\" on null"}
 *
 * -- store()'s own try/catch returning the exception text verbatim to the browser. Another
 * fixture gap that reads exactly like a broken save button (the F33 lesson), and the last one
 * standing between this fixture and a product that actually saves.
 *
 * `max_products = -1` is the unlimited sentinel the gate itself names, so the fixture does not
 * silently cap itself at an arbitrary number.
 */
if (\App\Models\Plan::count() === 0) {
    \App\Models\Plan::forceCreate([
        'id' => 1,
        'name' => 'P2 Dev Plan',
        'price' => 0,
        'duration' => 'Unlimited',
        'themes' => '',
        'max_stores' => -1,
        'max_products' => -1,
        'max_users' => -1,
        'storage_limit' => '-1',
        'enable_domain' => 0,
        'enable_subdomain' => 0,
        'trial' => 0,
        'trial_days' => 0,
        'is_disable' => 0,
        'modules' => '',
    ]);
}

/*
 * CATEGORIES, TAGS AND A TAX, so the product editor can actually be SAVED.
 *
 * WHY THIS WAS MISSING AND WHAT IT COST. `ProductController::store()` validates
 * `category_id` as required, and `create()` fills the picker from
 * `Category::where('store_id', getCurrentStore())`. The fixture seeded 32 products but no
 * categories, so the picker held nothing but its placeholder and EVERY attempt to create a
 * product from the UI answered
 *
 *     {"flag":"error","msg":"The category id field is required."}
 *
 * which looks exactly like a broken save button. It is the F33 lesson again: the first browser
 * run reported failures that were all fixture gaps, and reading them as product defects would
 * have produced a wrong fix. `tags` and `taxes` were empty for the same reason -- `tag_id[]`
 * carries the HTML `required` attribute on the create form with zero options to satisfy it.
 *
 * INVENTED DATA ONLY, like everything else in this file.
 */
$categories = [];
foreach ([
    ['name' => 'Textiles',  'slug' => 'textiles'],
    ['name' => 'Soaps',     'slug' => 'soaps'],
    ['name' => 'Homeware',  'slug' => 'homeware'],
] as $row) {
    $categories[] = \App\Models\Category::firstOrCreate(
        ['slug' => $row['slug'], 'store_id' => $store->id],
        [
            'name' => $row['name'],
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => $store->id,
        ]
    );
}

/*
 * `tags.slug` is NOT NULL, which was worth knowing beyond this fixture. Of the FOUR places
 * ProductController creates a Tag from an unresolved posted value, two set the slug and two
 * did not -- the two variant branches, store():639 and update():1696. Those two hit the same
 * constraint this seeder did. They are now fixed (F62); the comment is kept because the
 * asymmetry is the kind a later edit re-introduces, and because the earlier version of this
 * note said "update() passes NO slug", which was true of one of its two tag blocks and false
 * of the other.
 */
foreach (['handmade', 'damascus', 'cotton'] as $tagName) {
    if (! \App\Models\Tag::where('name', $tagName)->where('store_id', $store->id)->exists()) {
        /* forceCreate: `created_by` is NOT NULL and is not in the model's $fillable, so a
           mass-assigned create drops it and the insert fails on the constraint. */
        \App\Models\Tag::forceCreate([
            'name' => $tagName,
            'slug' => $tagName,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);
    }
}

if (\Illuminate\Support\Facades\Schema::hasTable('taxes')
    && \App\Models\Tax::where('store_id', $store->id)->count() === 0) {
    /* Columns asserted against the live table rather than assumed: `taxes` has no `type` here,
       and a seeder that guesses a schema fails the way this one first did. */
    $taxColumns = \Illuminate\Support\Facades\Schema::getColumnListing('taxes');
    $tax = array_filter([
        'name' => 'Standard',
        'rate' => 10,
        'status' => 1,
        'store_id' => $store->id,
        'created_by' => $merchant->id,
    ], static fn ($k) => in_array($k, $taxColumns, true), ARRAY_FILTER_USE_KEY);
    \App\Models\Tax::forceCreate($tax);
}

/* Give the existing products a category, so the list's category column and its filter have
   something to show and the fixture is self-consistent. */
$catIds = array_map(static fn ($c) => $c->id, $categories);
if ($catIds) {
    foreach (\App\Models\Product::where('store_id', $store->id)->get() as $i => $p) {
        if (empty($p->category_id)) {
            $p->category_id = $catIds[$i % count($catIds)];
            $p->save();
        }
    }
}

/* ---------------------------------------------------------------------------------------
 * P2-03-4. ATTRIBUTES, VARIANTS AND IMAGES.
 *
 * Without these the media and variant halves of the product editor CANNOT BE DRIVEN AT ALL,
 * which is what the first survey of them measured: `product_attributes`,
 * `product_attribute_options`, `product_variants` and `product_images` were all at 0 rows, so
 * `#attribute_id` rendered with zero options and stayed invisible even with the variant toggle
 * on, and the existing-image gallery had nothing to show. Same class as the categories/Plan/
 * permission gaps that blocked P2-03-3 (ledger F33): a fixture that cannot reach the screen.
 *
 * THE ENCODING IS COPIED FROM THE CONTROLLER, NOT INVENTED. `ProductController::store():189-216`
 * builds `products.attribute_id` as `implode(',', $request->attribute_no)` and
 * `products.product_attribute` as a JSON array of
 *   { attribute_id, values[], visible_attribute_<id>, for_variation_<id> }
 * so a fixture that guessed a different shape would exercise a product the application could
 * never have produced.
 * ------------------------------------------------------------------------------------------ */

$attrSpec = [
    /* `M/L` IS A DELIBERATE CANARY, not a typo. F72: the variant key the application builds from
       these terms becomes a jQuery class SELECTOR, and only spaces are stripped -- so a term
       containing any CSS-special character (`/`, `.`, `#`, `:`) produced
       `.down_product_M/L-Natural`, an invalid selector that THREW and aborted the handler, so the
       downloadable / virtual / manage-stock toggles stopped working for every variant on the page.
       `product_attribute_options.terms` is validated `required` and nothing else, so this is an
       ordinary thing for a merchant to type. Keeping one in the fixture means any future page-error
       check exercises it instead of discovering it again. */
    'Size'   => ['S', 'M', 'L', 'M/L'],
    'Colour' => ['Natural', 'Indigo'],
];
$attrIds = [];
foreach ($attrSpec as $attrName => $terms) {
    $attr = \App\Models\ProductAttribute::where('name', $attrName)->where('store_id', $store->id)->first();
    if (! $attr) {
        // `slug` is NOT NULL with no default on this table too.
        $attr = \App\Models\ProductAttribute::forceCreate([
            'name' => $attrName,
            'slug' => \Illuminate\Support\Str::slug($attrName),
            'store_id' => $store->id,
        ]);
    }
    $attrIds[$attrName] = $attr->id;

    foreach ($terms as $order => $term) {
        $exists = \App\Models\ProductAttributeOption::where('attribute_id', $attr->id)
            ->where('terms', $term)->where('store_id', $store->id)->exists();
        if (! $exists) {
            // `order` is NOT NULL default '0'; it is set explicitly so the terms have a
            // stable sequence rather than insertion order.
            \App\Models\ProductAttributeOption::forceCreate([
                'attribute_id' => $attr->id,
                'terms' => $term,
                'order' => $order,
                'store_id' => $store->id,
            ]);
        }
    }
}

/* A product that really is a variant product, so the combination table has something to
 * render. `variant`, `sku` and `price` are NOT NULL with no default on product_variants. */
$variantProduct = Product::where('store_id', $store->id)->orderBy('id')->first();
if ($variantProduct) {
    /* The flag and the rows are seeded INDEPENDENTLY. An earlier version wrapped both in
       `if ((int) $variantProduct->variant_product !== 1)`, so once the flag was set a re-run
       skipped the variant ROWS entirely -- and after the rows were deleted to correct their
       separator, re-seeding reported `variants=0` and the variant UI had nothing to show again.
       A fixture has to be idempotent per THING, not per flag. */
    $optionRows = [];
    foreach ($attrSpec as $attrName => $terms) {
        $id = $attrIds[$attrName];
        $optionRows[] = [
            'attribute_id' => (string) $id,
            'values' => $terms,
            'visible_attribute_' . $id => 'on',
            'for_variation_' . $id => 'on',
        ];
    }
    $variantProduct->variant_product = 1;
    $variantProduct->attribute_id = implode(',', array_values($attrIds));
    $variantProduct->product_attribute = json_encode($optionRows);
    $variantProduct->save();

    $i = 0;
    foreach ($attrSpec['Size'] as $size) {
        foreach ($attrSpec['Colour'] as $colour) {
            /* THE SEPARATOR IS THE APPLICATION'S OWN, NOT A GUESS. ProductController builds the
               variant key as `$str .= '-' . str_replace(' ', '', $item)` (`:1766-1768`), so a
               Size/Colour product really is `S-Natural`. The first version of this fixture used
               `/` and produced a page error the UI could never have caused -- a jQuery selector
               `.down_product_S/Natural`. An unfaithful fixture manufactures its own defects. */
            $label = $size . '-' . $colour;
            $exists = \App\Models\ProductVariant::where('product_id', $variantProduct->id)
                ->where('variant', $label)->exists();
            if ($exists) { continue; }
            \App\Models\ProductVariant::forceCreate([
                'product_id' => $variantProduct->id,
                'variant' => $label,
                'sku' => 'SKU-' . strtoupper(substr(md5($variantProduct->id . $label), 0, 8)),
                'price' => 39 + ($i * 2),
                'stock' => 4 + $i,
                'low_stock_threshold' => 2,
                'stock_status' => 'in_stock',
                'store_id' => $store->id,
            ]);
            $i++;
        }
    }
}

/* Gallery images, with REAL FILES on disk, because the gallery renders an <img> and a broken
 * one is indistinguishable from a missing affordance when you are surveying the screen.
 * `/public/uploads/` is gitignored, so nothing here is committed. */
/* The path must be `uploads/<storeId>/...`. P1-SEC-28's StoredPathPolicy refuses anything
   whose first segment under `uploads/` is not the owning store's folder, and it refused this
   fixture's first attempt at `uploads/p2dev/` -- the control working exactly as designed, on a
   fixture rather than on an attacker. */
/* THE FIXTURE MUST WRITE WHERE THE APPLICATION WRITES, or the dev environment is not faithful.
   `Utility::upload_file()` stores through the `theme` disk, whose root is `base_path()` -- so a
   real upload lands at `<repo>/uploads/<store>/...`, and all 337 tracked upload files in this
   repository are at that same repo-root path (0 under `public/uploads/`). This line used
   `public/uploads/`, so the SEEDED images resolved over HTTP while images the application itself
   uploaded did not, and a harness checking "the new cover renders" failed against working code.
   The stored DB path is unchanged -- `uploads/<store>/p2dev/...`, which is what `get_file()`
   expects -- only the disk location is corrected to match it. */
$uploadDir = dirname(__DIR__) . '/uploads/' . $store->id . '/p2dev';
if (! is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
$swatches = ['a' => [196, 164, 132], 'b' => [ 72, 101, 128], 'c' => [140, 120, 150]];
foreach ($swatches as $key => $rgb) {
    $file = $uploadDir . '/swatch-' . $key . '.png';
    if (file_exists($file)) { continue; }
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(240, 240);
        imagefill($im, 0, 0, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
        imagepng($im, $file);
        imagedestroy($im);
    } else {
        // 1x1 PNG, so the path at least resolves where GD is absent
        file_put_contents($file, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));
    }
}
$galleryTargets = Product::where('store_id', $store->id)->orderBy('id')->limit(2)->get();
foreach ($galleryTargets as $gp) {
    /* A COVER IMAGE TOO, because its absence was mistaken for a defect. The edit view renders
       `<img id="upcoverImg" src="{{ get_file($product->cover_image_path) }}">` and the fixture left
       `cover_image_path` NULL, so a survey of the editor reported "the existing cover is not
       previewed" against code that renders it correctly -- `get_file(null)` simply returns null.
       Seeding one means the media half of the editor can be driven with an existing cover rather
       than only with a freshly chosen file. Written through Eloquent, not a raw update, because
       `Product` paths go through StoredPathPolicy on `saving` (P1-SEC-28) and a raw write would
       skip the control this fixture should be exercising.
       THIS SITS ABOVE THE `$have >= 3` GUARD ON PURPOSE: the first version put it after, so the
       `continue` skipped it for exactly the products that already had their gallery -- which is
       every product on a second run -- and the cover stayed NULL. */
    if (empty($gp->cover_image_path)) {
        $coverPath = 'uploads/' . $store->id . '/p2dev/swatch-a.png';
        $gp->cover_image_path = $coverPath;
        $gp->cover_image_url = $coverPath;
        $gp->save();
    }

    $have = \App\Models\ProductImage::where('product_id', $gp->id)->count();
    if ($have >= 3) { continue; }
    foreach (array_keys($swatches) as $key) {
        $path = 'uploads/' . $store->id . '/p2dev/swatch-' . $key . '.png';
        if (\App\Models\ProductImage::where('product_id', $gp->id)->where('image_path', $path)->exists()) { continue; }
        \App\Models\ProductImage::forceCreate([
            'product_id' => $gp->id,
            'image_path' => $path,
            'image_url' => $path,
            'store_id' => $store->id,
        ]);
    }

}

echo "seeded: merchant={$merchant->id} store={$store->id}"
    . " categories=" . \App\Models\Category::where("store_id", $store->id)->count()
    . " tags=" . \App\Models\Tag::where("store_id", $store->id)->count()
    . " products=" . Product::where('store_id', $store->id)->count()
   . " orders=" . Order::where('store_id', $store->id)->count()
   . " billing=" . DB::table('order_billing_details')->count()
   . " notes=" . \App\Models\OrderNote::where('store_id', $store->id)->count()
   . " attrs=" . \App\Models\ProductAttribute::where('store_id', $store->id)->count()
   . " attrOptions=" . \App\Models\ProductAttributeOption::where('store_id', $store->id)->count()
   . " variants=" . \App\Models\ProductVariant::where('store_id', $store->id)->count()
   . " images=" . \App\Models\ProductImage::where('store_id', $store->id)->count() . "\n";
