<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderNoteController extends Controller
{
    /**
     * Store a newly created resource in storage.
     *
     * WHAT CHANGED AND WHY. The gate was `isAbleTo('Create Order Note')` and
     * nothing else; `$note->order_id = $request->order_id` took the order id
     * straight off the request, never looked the order up, and never checked
     * whose store it was in. The note itself was then stamped with
     * getCurrentStore(), so a merchant could mint notes referencing another
     * store's order ids, and nothing validated note_type at all -- it was
     * persisted verbatim while the API sibling restricts it to
     * `in:private_note,to_customer` (Api/DashboardController::AddOrderNote:111).
     *
     * The decision now lives in App\Policies\OrderNotePolicy, so the permission
     * and the tenancy test cannot drift apart from the delete path.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'order_id'  => 'required|integer',
            'note'      => 'required|string|max:5000',
            // Matched to the API sibling rather than invented here, so the two
            // write paths cannot disagree about what a note_type is.
            'note_type' => 'required|string|in:private_note,to_customer',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        // Looked up WITHOUT a store predicate on purpose: the tenancy decision
        // belongs to the policy, which can then tell "this order is not yours"
        // apart from "there is no such order" and answer the same way for both.
        $order = Order::find($request->order_id);

        if (!Auth::user() || !Auth::user()->can('create', [OrderNote::class, $order])) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $note                 = new OrderNote;
        $note->order_id       = $order->id;
        $note->notes          = $request->note;
        $note->note_type      = $request->note_type;
        // The ORDER's store, not getCurrentStore(). For a merchant the policy
        // has already proved the two are equal, so nothing changes; for a
        // platform admin using the cross-store escape hatch, getCurrentStore()
        // would have stamped the note with the OPERATOR's current store and
        // orphaned it from the order it annotates -- OrderController::order_view
        // lists notes with `where('store_id', getCurrentStore())`
        // (OrderController.php:291), so the merchant would never see it. This
        // matches the API sibling, which stamps `$store->id`.
        $note->store_id       = $order->store_id;
        /*
         * P2-04-6. THE AUTHOR, recorded by the writer. `Auth::user()` is non-null here -- the
         * policy check above returns early otherwise -- and this is the one place in the
         * application where a human demonstrably TYPED the note's text, which is the only thing
         * that justifies attributing it. The generated notes (`order_note_data()`, and the two
         * "Order created" notes the manual-order writers emit) leave this NULL on purpose: they
         * describe an event rather than state somebody's words.
         */
        $note->created_by     = Auth::id();
        $note->save();

        return redirect()->back()->with('success', __('Order Note successfully created.'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * R4-04 shape: the implicitly bound OrderNote was the ONLY authorization
     * token, so any holder of 'Delete Order Note' -- which the shared `admin`
     * role carries by default, Utility.php:2957 -- deleted any store's note.
     *
     * @param  \App\Models\OrderNote  $OrderNote
     * @return \Illuminate\Http\Response
     */
    public function destroy(OrderNote $OrderNote)
    {
        if (!Auth::user() || !Auth::user()->can('delete', $OrderNote)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $OrderNote->delete();

        return redirect()->back()->with('success', __('Note delete successfully.'));
    }
}
