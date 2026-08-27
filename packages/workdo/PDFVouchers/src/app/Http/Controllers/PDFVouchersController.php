<?php

namespace Workdo\PDFVouchers\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Yajra\DataTables\Facades\DataTables;
use Workdo\PDFVouchers\app\Models\CouponRequest;

class PDFVouchersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $data = CouponRequest::where('status', null)->where('store_id', getCurrentStore());
            return DataTables::of($data)
                ->addColumn('action', function (CouponRequest $item) {
                    return view('pdfvouchers::mail_setting.action', compact('item'));
                })
                ->rawColumns(['action'])
                ->make(true);
        }
        return view('pdfvouchers::mail_setting.create');
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
    public function store(Request $request): RedirectResponse
    {
        //
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
}
