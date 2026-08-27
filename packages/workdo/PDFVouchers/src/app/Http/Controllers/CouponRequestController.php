<?php

namespace Workdo\PDFVouchers\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Workdo\PDFVouchers\app\Models\CouponRequest;
use Workdo\PDFVouchers\DataTables\UsersData;
use Illuminate\Support\Facades\Cache;

class CouponRequestController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(UsersData $dataTable)
    {
        return $dataTable->render('pdfvouchers::mail_setting.create');
    }
    

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('pdfvouchers::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function couponRequest(Request $request, $slug)
    {
       
        try {
            $store = getStore($slug);

            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                    'email' => 'required',
                    'detail' => 'required',
                ]
            );
           

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            

            $settings = new CouponRequest();
            $settings->name = $request->input('name');
            $settings->email = $request->input('email');
            $settings->detail = $request->input('detail');
            $settings->store_id = $store->id;
            $settings->save();
           
            return redirect()->back()->with('success', __('Request Send successfully.'));
        } catch (Exception $e) {
            return redirect()->back()->with('error', $e);
        }

    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('pdfvouchers::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('pdfvouchers::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }

    public function couponForm($slug)
    {  
            $store = getStore($slug);
            $return['html'] = view('pdfvouchers::coupon.create', compact('slug','store'))->render();
            return response()->json($return);
    }
}
