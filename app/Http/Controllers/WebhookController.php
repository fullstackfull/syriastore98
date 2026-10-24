<?php

namespace App\Http\Controllers;

use App\Models\Webhook;
use Illuminate\Http\Request;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('webhook.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        session()->put(['setting_tab' => 'webhook_setting']);
        $store = getStoreById(getCurrentStore());

        $validator = \Validator::make(
            $request->all(),
            [
                'module' => 'required|unique:webhooks,module,NULL,id,store_id,' . $store->id,
                'method' => 'required',
                'webbbook_url' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        // P1-SEC-33: the address and method are judged now by the same rule the sender applies, so a refusal is visible to the merchant here
        // rather than as a webhook that silently never arrives.
        if (! \App\Services\SafeWebhook::allows((string) $request->webbbook_url, (string) $request->method)) {
            return redirect()->back()->with('error', __('The webhook address must be a public http or https address, and the method GET or POST.'));
        }

        $webhook            = new Webhook();
        $webhook->module    = $request->module;
        $webhook->url       = $request->webbbook_url;
        $webhook->method    = $request->method;
        $webhook->store_id  = $store->id;
        $webhook->save();

        return redirect()->back()->with('success', __('Webhook setting created successfully'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Webhook $webhook)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $webhook = Webhook::where('id', $id)->where('store_id', getCurrentStore())->get();
        return view('webhook.edit', compact('webhook'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        session()->put(['setting_tab' => 'webhook_setting']);
        $store = getStoreById(getCurrentStore());

        $validator = \Validator::make(
            $request->all(),
            [
                'module' => 'required|unique:webhooks,module,' . $id . ',id,store_id,' . $store->id,
                'method' => 'required',
                'webbbook_url' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        if (! \App\Services\SafeWebhook::allows((string) $request->webbbook_url, (string) $request->method)) {
            return redirect()->back()->with('error', __('The webhook address must be a public http or https address, and the method GET or POST.'));
        }

        // P1-SEC-33: the row is addressed by a bare id and Webhook is not a scoped model, so this used to rewrite -- and, through the
        // `store_id` assignment, MOVE into the caller's store -- any tenant's webhook. It now touches only the caller's own.
        // (the row is read first, not judged by update()'s count: MySQL reports CHANGED rows, so re-saving identical values would look like a miss)
        $webhook = Webhook::where('id', $id)->where('store_id', $store->id)->first();
        if (! $webhook) {
            return redirect()->back()->with('error', __('Something is wrong.'));
        }
        $webhook->module = $request->module;
        $webhook->method = $request->method;
        $webhook->url    = $request->webbbook_url;
        $webhook->save();

        return redirect()->back()->with('success', __('Webhook Setting Succssfully Updated'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        session()->put(['setting_tab' => 'webhook_setting']);
        if (auth()->user() && auth()->user()->type == 'admin') {
            // P1-SEC-33: only the caller's own store's webhook (any `admin`-type user could delete any tenant's by id)
            $webhook = Webhook::where('id', $id)->where('store_id', getCurrentStore())->first();
            if ($webhook) {
                $webhook->delete();
                return redirect()->back()->with('success', __('Webhook Setting successfully deleted .'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
