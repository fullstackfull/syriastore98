<?php

namespace App\Http\Controllers;

use App\Models\Conversion;
use App\Models\Store;
use App\Models\SupportConversion;
use App\Models\SupportTicket;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\DataTables\SupportTicketDataTable;

class SupportTicketController extends Controller
{
    /**
     * THE STORE IDS THIS PRINCIPAL MAY ACT WITHIN -- `null` for the operator's deliberate bypass,
     * and an EMPTY ARRAY meaning REFUSE.
     *
     * WHY THIS EXISTS, AND IT CORRECTS A CLAIM OF MINE. Both this controller and
     * `TicketStatusChangeAudienceTest` stated that these doors are tenant-safe because
     * `SupportTicket` is in `config/tenancy.php`'s `scoped_models` and registers `StoreScope`. That
     * holds only FOR A PRINCIPAL THAT RESOLVES A TENANT. Independent review challenged it and
     * execution settled it against me: `StoreScope` adds NO predicate on two paths --
     * `MODE_SUPER_ADMIN` (deliberate) and `MODE_UNRESOLVED`, which falls to
     * `applyNullTenantPolicy()` and `null_tenant => 'open'`, which adds nothing. That second one is
     * precisely CLAUDE.md's standing warning: never let the scope be the only tenant check.
     *
     * A `web` principal resolves no tenant when `getCurrentStore()` answers null -- an empty
     * `current_store` AND no store of their own. `UserController` copies the creator's
     * `current_store` onto a new sub-user and the operator's own column is normally empty, so an
     * operator-created staff row starts in exactly that state. Measured before this fix, as a staff
     * member of tenant A against tenant B: the status writer answered `{"status":"success"}` and
     * changed B's row, `edit()` returned 200 with B's ticket TITLE and BODY in the page, and
     * `destroy()` removed B's row.
     *
     * THE TENANT IS DERIVED FROM THE PRINCIPAL'S ROOT, NOT FROM `current_store`, which is what makes
     * this a confinement rather than a lockout: a staff member with an empty column whose tenant
     * does own stores still works normally. `creatorId()` is the user's own id for `admin` and
     * `created_by` for everyone else, and the root must be a type-`admin` merchant -- the P1-SEC-67b
     * rule, because every merchant has `created_by = 1` and a merchant who demotes their own `type`
     * would otherwise resolve the OPERATOR as their root and inherit the operator's stores.
     *
     * IT IS DELIBERATELY NOT `->where('store_id', getCurrentStore())`. That helper returns the
     * hardcoded `1` for the operator, so it would silently confine the platform operator to store 1
     * -- the exact failure `StoreScope`'s operator bypass was written to avoid.
     *
     * `D-P6-14`, whether the status writer should have a permission gate at all, is UNCHANGED and
     * still the owner's. This closes a tenancy hole and decides no audience.
     *
     * @return array<int, int>|null
     */
    private function actingTenantStoreIds(): ?array
    {
        $user = auth('web')->user();

        /*
         * UNREACHABLE BY CONSTRUCTION ON THESE ROUTES, AND NO TEST COVERS IT -- said here because a
         * mutation flipping this to `return null` (fail open) SURVIVED, and the honest reading of
         * that is "dead defensive code", not "tested". Every route on this controller carries `auth`
         * (`routes/web.php` group), so an anonymous request never arrives, and `CustomAuth` logs a
         * `Customer` into the `customers` guard rather than `web`. The branch is kept because it
         * costs nothing and because `auth('web')->user()` is typed `?Authenticatable`, not `?User`.
         */
        if (! $user instanceof \App\Models\User) {
            return [];
        }

        if (\App\Policies\UserPolicy::isOperator($user)) {
            return null;
        }

        $root = $user->creatorId();

        if (empty($root)) {
            return [];
        }

        $rootUser = \App\Models\User::withoutGlobalScopes()->find($root);

        if (! $rootUser || strtolower(trim((string) $rootUser->type)) !== 'admin') {
            return [];
        }

        return Store::withoutGlobalScopes()
            ->where('created_by', $root)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Narrow a ticket query to the acting principal's tenant. Callers refuse on an empty set. */
    private function confineToTenant($query, ?array $storeIds)
    {
        return $storeIds === null ? $query : $query->whereIn('store_id', $storeIds);
    }

    public function index(SupportTicketDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->type != 'super admin')
        {
            if(auth()->user() && auth()->user()->isAbleTo('Manage Support Ticket'))
            {
                return $dataTable->render('support.index');
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function edit(SupportTicket $support,$id)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Replay Support Ticket'))
        {
            $storeIds = $this->actingTenantStoreIds();

            if ($storeIds === []) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }

            $ticket = $this->confineToTenant(SupportTicket::query(), $storeIds)->find($id);
            if($ticket)
            {

                return view('support.edit', compact('ticket'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function conversion_store(Request $request,$ticket_id)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Replay Support Ticket'))
        {
            $user =auth()->user();

            $ticket = SupportTicket::find($ticket_id);
            if($ticket) {

                $validator = \Validator::make(
                    $request->all(),
                    [
                        'reply_description' => 'required',
                    ]
                );

                if($validator->fails())
                {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }

                $post = [];
                $post['sender'] = ($user)?$user->id:$ticket->UserData->name;
                $post['ticket_id'] = $ticket->id;
                $post['description'] = $request->reply_description;
                $post['user_id'] = $user->id;
                $post['store_id'] = getCurrentStore();

                $data = [];

                if($request->hasfile('reply_attachments'))
                {
                    $errors=[];
                    foreach($request->file('reply_attachments') as $filekey => $file)
                    {
                        $file_size = $file->getSize();
                        $result = Utility::updateStorageLimit(auth()->user()->creatorId(), $file_size);
                        if($result==1)
                        {
                            $imageName = $file->getClientOriginalName();
                            $dir        = 'uploads/' . getCurrentStore().'/reply_tickets';
                            $path = Utility::keyWiseUpload_file($request,'reply_attachments',$imageName,$dir,$filekey,[]);
                            if($path['flag'] == 1){
                                $data[] = $path['url'];
                            }
                            else{
                                $errors = __($path['msg']);
                            }
                        }
                        else
                        {
                            return redirect()->back()->with('error', $result);
                        }
                    $file   = 'reply_tickets/'.$imageName;

                    }
                }

                $post['attachments'] = json_encode($data);
                $conversion = SupportConversion::create($post);
                // SupportConversion::change_status($ticket_id);

                return redirect()->back()->with('success', __('Reply added successfully') . ((isset($error_msg)) ? '<br> <span class="text-danger">' . $error_msg . '</span>' : '') .((isset($result) && $result!=1) ? '<br> <span class="text-danger">' . $result . '</span>' : ''));
            }else{
                return view('403');
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function destroy(SupportTicket $support,$id)
    {

        if(auth()->user() && auth()->user()->isAbleTo('Delete Support Ticket'))
        {
            $storeIds = $this->actingTenantStoreIds();

            if ($storeIds === []) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }

            // THE TICKET IS RESOLVED FIRST, inside the tenant, and the conversation is deleted by
            // the resolved ticket's own id. Deleting `SupportConversion where ticket_id = $id`
            // directly would destroy another tenant's replies even when the ticket row itself was
            // out of reach: `SupportConversion` is scoped too, so it had the same null-tenant hole.
            $ticket = $this->confineToTenant(SupportTicket::query(), $storeIds)->find($id);

            if (! $ticket) {
                return redirect()->back()->with('error', __('Ticket not found'));
            }

            SupportConversion::where('ticket_id', $ticket->id)->delete();
            SupportTicket::where('id', $ticket->id)->delete();
            return redirect()->back()->with('success', __('Ticket Delete succefully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * THE FIFTH METHOD ON THIS CONTROLLER, AND THE ONLY ONE THAT ASKED NOTHING.
     *
     * Two defects closed here. A THIRD -- the missing permission gate -- is DELIBERATELY NOT fixed,
     * and that is the most important sentence in this docblock.
     *
     *  0. THE MISSING PERMISSION GATE IS `D-P6-14`, AN OWNER DECISION, AND IT STAYS OPEN.
     *     `index()` asks `Manage Support Ticket`, `edit()` and `conversion_store()` ask
     *     `Replay Support Ticket`, `destroy()` asks `Delete Support Ticket`, and this writer asks
     *     for nothing. The consistency argument for adding the siblings' gate is obvious, and it
     *     was WRITTEN AND THEN REMOVED, because `TicketStatusChangeAudienceTest` already settled
     *     what kind of question this is: the route IS tenant-safe (`SupportTicket` is in
     *     `scoped_models` and registers `StoreScope`, so a cross-tenant id answers "Ticket not
     *     found." and nothing moves -- measured there), so the gap is a PRIVILEGE QUESTION INSIDE A
     *     TENANT, not a cross-tenant write. That file pins today's audience as a measurement
     *     rather than an approval, and its failure message says what to do if a gate is added
     *     deliberately: record the decision and re-point the test. There is no such decision, so
     *     adding the gate here would have been me answering `D-P6-14` myself and silently reducing
     *     who can work a support queue.
     *
     *     ONE NEW MEASUREMENT FOR THAT DECISION, taken while the gate was briefly in place, because
     *     it changes how much the answer costs: a principal holding none of the three support
     *     permissions -- INCLUDING A TENANT OWNER with no seeded role -- already gets 302 from
     *     `GET support_ticket.index` and 302 from `GET support_ticket.edit`. So they cannot reach
     *     the ticket list or the screen the status dropdown lives on, and the only way to this
     *     writer is a hand-made POST. Whoever answers `D-P6-14` should know that gating it is
     *     unlikely to take a working control away from anyone.
     *
     *  1. THE TICKET CAME FROM THE REQUEST BODY. The route is
     *     `POST ticket-status-change/{id}` and this read `$request->id`, so a posted `id` beat the
     *     URL. `HelpdeskTicketOwnershipTest` already encodes the opposite rule for the Helpdesk row
     *     routes ("judged by the route parameter not by the body"); this handler did not follow it.
     *     The route parameter is now the only source. BE PRECISE ABOUT WHAT THAT BUYS: it is not an
     *     authorization change. `Request::__get` reads input first and falls back to the route
     *     parameter, so both spellings are caller-supplied and an attacker names the id they want
     *     either way; what the old form additionally allowed was naming a DIFFERENT id than the URL,
     *     which made the written row invisible in access logs and would defeat any future
     *     route-bound policy. Nor is this CLAUDE.md's dangerous body-id shape, which is a FALLBACK
     *     THAT REPLACES THE PRINCIPAL (`if (! auth('customers')->user()) { $id = $request->x; }`) --
     *     there is no such fallback here. The tenant control is `actingTenantStoreIds()` above, not
     *     this line; an earlier draft of this paragraph quoted CLAUDE.md's "the scope must never be
     *     the only tenant check" while leaving the scope as exactly that, which independent review
     *     correctly called self-contradictory.
     *
     *  2. AN UNKNOWN STATUS WAS AN HTTP 500. The cause is on
     *     `SupportTicket::ticket_status_change()`, which returned an implicit NULL for a status it
     *     did not recognise; `$responce['status']` on null raises a warning Laravel promotes to
     *     `ErrorException`. Both halves are fixed: the model returns an array on every path, and
     *     the status is validated here against `SupportTicket::STATUSES` before the writer is
     *     called at all.
     */
    public function ticket_status_change(Request $request, $id)
    {
        if (! in_array($request->status, SupportTicket::STATUSES, true)) {
            return response()->json([
                'status' => 'error',
                'message' => __('That is not a ticket status.'),
            ]);
        }

        $storeIds = $this->actingTenantStoreIds();

        if ($storeIds === []) {
            return response()->json([
                'status' => 'error',
                'message' => __('Ticket not found'),
            ]);
        }

        if ($this->confineToTenant(SupportTicket::query(), $storeIds)->whereKey($id)->doesntExist()) {
            return response()->json([
                'status' => 'error',
                'message' => __('Ticket not found'),
            ]);
        }

        $data['ticket_id'] = $id;
        $data['ticket_status'] = $request->status;
        $responce = SupportTicket::ticket_status_change($data);
        if($responce['status'] == 'success') {
            $return['status'] = 'success';
            $return['message'] = $responce['message'];
            $return['ticket_status'] = $data['ticket_status'];
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = $responce['message'];
            return response()->json($return);
        }
    }

}
