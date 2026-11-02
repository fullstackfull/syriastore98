<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToStore;

class OrderNote extends Model
{
    use HasFactory, Cachable, BelongsToStore;
    protected $fillable = [
            'customer_id',
                        'order_id',
'notes',
'note_type',
'status',
'store_id',
        /*
         * P2-04-6. `created_by` IS DELIBERATELY ABSENT FROM THIS LIST, and the first version of
         * this slice had it here with a comment calling it "REQUIRED". That comment was wrong and
         * a bite test proved it: removing the key left all ten tests green, because BOTH human
         * writers assign the property directly (`$note->created_by = Auth::id();`), which
         * bypasses `$fillable` entirely.
         *
         * Leaving it unfillable is therefore not a tidy-up but a CONTROL. Four `OrderNote::create`
         * sites write SYSTEM notes by mass assignment, and `order_note_data()` composes more from
         * gateway callbacks; with the key fillable, any of them -- or any future caller handed
         * request data -- could set an author nobody authenticated. Unfillable means the only way
         * to record an author is the explicit property assignment the two human writers make, in
         * handlers that have already proved who the caller is.
         * `test_an_author_cannot_be_mass_assigned` pins it.
         */

    ];

    /**
     * P2-04-6 -- THE NAME OF WHOEVER WROTE THIS NOTE, as it may be shown to `$viewer`, or NULL.
     *
     * NULL IS THE COMMON ANSWER AND IT IS NOT AN ERROR. A note has no author when it predates
     * the `created_by` column, when it is a SYSTEM note (`order_note_data()` composes the text
     * from event data at 30+ call sites, most of them payment-gateway callbacks with no
     * authenticated user), or when the recorded user can no longer be named -- either because
     * the row is gone (no foreign key was added, deliberately: a CASCADE would delete a
     * merchant's commercial records to tidy up a user row) or because they are outside the
     * viewer's tenant.
     *
     * THE TENANT PREDICATE IS HERE RATHER THAN ARGUED ABOUT. `order_notes.store_id` already
     * scopes the note and the detail page is already behind `OrderDocumentAccessGuard`, so a
     * foreign author should be impossible -- but "should be impossible" is how a name gets
     * printed across a tenant boundary when a caller changes. `User::creatorId()` is the
     * tenant: it returns the user's own id for `admin`/`super admin` and `created_by` for
     * staff, so an owner and all their employees share one value. The operator passes, because
     * the operator legitimately reads every store.
     *
     * A note written BY the operator through the cross-store escape hatch therefore shows no
     * author to the merchant. That is deliberate on both counts: platform staff identity is not
     * a merchant's business, and showing nothing is never false.
     */
    /**
     * D-25 STEP 3 — THE TIMELINE TONE THIS NOTE RENDERS IN, or NULL if the page shows it at all.
     *
     * `order/view.blade.php` carried FIVE near-identical branches, one per tone, which this
     * replaces. The owner's condition for Step 3 was that duplicated note-rendering branches may
     * be unified *"only if their equivalence is proved"*, and it was: normalising whitespace and
     * substituting the single tone class made all five byte-identical, 912 characters each.
     *
     * NULL IS NOT A TIDY-UP OF THE OLD BEHAVIOUR, IT REPRODUCES IT. The old chain had no `@else`,
     * so a note matching none of the five conditions rendered NOTHING. That is reachable and it
     * is **F180**: `OrderController::store()` writes `status => 'Order created'` with a lowercase
     * c, `Api\DashboardController` writes the same and also `'Customer note'` / `'customer'`, and
     * none of the three matches a branch. MEASURED on the running page with all eight shapes
     * present for one order: 5 note blocks rendered of 8 rows.
     *
     * So this method returns null for those, Step 3 changes no behaviour, and the fix for F180 is
     * its own slice — where this one method is the only place that has to change, which is the
     * point of moving the decision out of the view.
     */
    /**
     * THE TINT THIS NOTE'S HEADER CARRIES ON THE ORDER DETAIL PAGE. (F180)
     *
     * It used to return NULL for a shape none of the five branches matched, and the view skipped
     * such a note entirely — because the `@elseif` chain this method replaced had no `@else`. That
     * was reproduced deliberately when the branches were collapsed (D-25 Step 3's condition was to
     * preserve behaviour), and recorded as F180: three of the four `order_notes` writers produce
     * shapes no branch matches, measured as **5 rendered blocks against 8 stored rows**.
     *
     *   `OrderController::store()`      `status => 'Order created'` — a LOWERCASE c, so it misses
     *                                   `'Order Created'` by one character
     *   `Api\DashboardController`       the same lowercase shape, and
     *                                   `status => 'Customer note'` / `note_type => 'customer'`,
     *                                   which matches nothing at all
     *
     * So a note a merchant typed into the manual order form, and every note the admin API records,
     * was stored and never shown. The irony was in the code: the comment at `OrderController:414`
     * said the earlier fix meant the note *"now actually appears"*, and it did not.
     *
     * NOW IT RETURNS `''`, NOT A NEW CLASS. `note-bg-color` is the base class every note header
     * already carries and it is styled in both themes, including the dark-mode contrast work at
     * `public/css/custom.css:4660-4662`. An unmatched note therefore renders with the base
     * styling and no tint — visible, legible, and needing no CSS that did not exist. Inventing a
     * sixth tint would have meant a sixth colour to contrast-check for a note whose meaning the
     * page cannot classify anyway.
     *
     * THE RETURN TYPE IS NOW NON-NULLABLE, which is the real guarantee: the view can no longer
     * skip a note, because there is no value that means "skip". Normalising the three writers onto
     * `OrderNote::addOrderNote()` was the alternative; it is larger, it changes what those handlers
     * store, and it cannot retrofit the rows already written — this renders old rows too, which is
     * what the owner's "compatible with old records" requires. No financial data is touched: this
     * method reads `status` and `note_type` and returns a CSS class.
     */
    public function timelineTone(): string
    {
        $status = (string) $this->status;
        $type = (string) $this->note_type;

        if ($status === 'Stock Manage') {
            return 'stock-mange-note';
        }
        if ($status === 'Order Created') {
            return 'order-create-note';
        }
        if ($status === 'Order status change') {
            return 'order-status-note';
        }
        if ($status === '' && $type === 'private_note') {
            return 'admin-private-note';
        }
        if ($status === '' && $type === 'to_customer') {
            return 'customer-note';
        }

        /* F180. Every note renders; a shape with no tint of its own gets the base styling. */
        return '';
    }

    public function authorNameFor(?User $viewer): ?string
    {
        if ($this->created_by === null || $viewer === null) {
            return null;
        }

        $author = User::find($this->created_by);

        if ($author === null) {
            return null;
        }

        if (! \App\Policies\UserPolicy::isOperator($viewer)
            && (int) $author->creatorId() !== (int) $viewer->creatorId()) {
            return null;
        }

        $name = trim((string) $author->name);

        return $name === '' ? null : $name;
    }

    public static function order_note_data($data= [])
    {
        $ordernote = new OrderNote();
        $ordernote->order_id = $data['order_id'];
        $ordernote->customer_id = !empty($data['customer_id']) ? $data['customer_id']: null;
        $ordernote->status = $data['status'];
        if($data['status'] == 'Order Created')
        {
            $ordernote->notes = 'Successfully created order of ' . $data['product_order_id'] . '. Order status changed to ' . $data['delivery_status'] . '.
            Payment is to be made upon delivery. ';
        }elseif($data['status'] == 'Stock Manage')
        {
            if($data['variant_product'] == 0)
            {
                $ordernote->notes = 'Stock levels reduced: The stock of ' . $data['product_name'] . ' is below the specified stock. Current Stock: ' . $data['product_stock'] . '. ';
            }else{
                $ordernote->notes = 'Stock levels reduced: The stock of ' . $data['product_name'] . '-' . $data['product_variant_name'] . ' is below the specified stock. Current Stock: ' . $data['product_stock'] . '. ';
            }

        }elseif($data['status'] == 'Order status change')
        {
            $ordernote->notes = 'Order status changed to '.$data['changeble_status'].'.';
        }
        $ordernote->store_id = $data['store_id'];
        $ordernote->save();
    }


    public static function order_entry($status= '')
    {
        if($status == 'Order Created')
        {
            $message = 'Payment to be made upon delivery. Order status changed from Pending payment to Processing.';
        }elseif($status == 'Stock Manage')
        {
            $message = 'Stock levels reduced:';
        }elseif($status == 'Order status change'){
            $message = 'Order status changed from ';
        }
        return $message;

    }
}