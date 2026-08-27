<?php

namespace Workdo\PDFVouchers\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Workdo\PDFVouchers\app\Mail\CouponStatusMail;
use AWS\CRT\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log as FacadesLog;
use Illuminate\Support\Facades\Mail;
use Workdo\PDFVouchers\app\Models\MailSetting;

class MailSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('pdfvouchers::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return redirect()->back();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validator = \Validator::make(
                $request->all(),
                [
                    'subject' => 'required|string|max:255',
                    'from' => 'required|string|max:255',
                    'content' => 'required|string',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            session()->put('app_setting_tab', $request->app_setting_tab);
            $data = $validator->validated();
            MailSetting::updateOrCreate(
                ['store_id' => getCurrentStore()],
                $data
            );

            $message = ['success' => __('Mail Setting saved successfully.')];
        } catch (Exception $e) {
            \Log::error('Error saving Mail Setting: ' . $e->getMessage());
            $message = ['error' => __('There was an error saving the Mail Setting.')];
        }
        return redirect()->back()->with($message);

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

    public function status(Request $request)
    {

        $request->validate([
            'status' => 'in:accept,reject',
        ]);
        if ($request['status'] == 'accept') {

            $store = getCurrentStore();
            $coupons_count = DB::table('coupons')
                ->where('store_id', $store)
                ->where('status', 1)
                ->where('coupon_expiry_date', '>', Carbon::now())
                ->count();

            if ($coupons_count != 0) {

                $MailSetting = MailSetting::where('store_id', $store)->first();
                $MailData = DB::table("coupon_requests")->where('id', $request->id)->first();
                $user = auth()->user();
                $setting = getAdminAllSetting($user->id);

                if ($MailSetting && $MailData && !empty($MailData->email)) {
                    // Generate PDF of available coupons

                    $coupons = DB::table('coupons')
                        ->where('store_id', $store)                        
                        ->where('status', 1)
                        ->where('coupon_expiry_date', '>', Carbon::now())
                        ->get();
                    $pdf = Pdf::loadView('pdfvouchers::pdf.coupons', compact('coupons'));
                    $pdfOutput = $pdf->output();
                    $pdfPath = storage_path('app/public/coupons.pdf');
                    file_put_contents($pdfPath, $pdfOutput);

                    try {
                        config([
                            'mail.driver' => $setting['MAIL_DRIVER'],
                            'mail.host' => $setting['MAIL_HOST'],
                            'mail.port' => $setting['MAIL_PORT'],
                            'mail.encryption' => $setting['MAIL_ENCRYPTION'],
                            'mail.username' => $setting['MAIL_USERNAME'],
                            'mail.password' => $setting['MAIL_PASSWORD'],
                            'mail.from.address' => $setting['MAIL_FROM_ADDRESS'],
                            'mail.from.name' => $setting['MAIL_FROM_NAME'],
                        ]);

                        $mailData = [
                            'customer_name' => $MailData->name,
                            'store_name' => $MailSetting->from,
                            'subject' => $MailSetting->subject,
                            'content' => $MailSetting->content,
                        ];

                        Mail::to($MailData->email)->send(new CouponStatusMail($mailData, $pdfPath));

                        $status = $request->status;
                        $upd = DB::table('coupon_requests')
                            ->where('id', $request->id)
                            ->update(['status' => $status]);

                        return redirect()->back()->with('success', 'Email sent successfully.');
                    } catch (\Exception $e) {
                        FacadesLog::error('Email sending failed:', ['exception' => $e]);
                        return redirect()->back()->with('error', 'Email sending failed. Please check SMTP configuration.');
                    }
                } else {
                    return redirect()->back()->with('error', __('Mail not sent. Email not found or data missing.'));
                }
            } else {
                return redirect()->back()->with('error', __('Coupon not found.'));
            }

        } else {
            $status = $request->status;
            $upd = DB::table('coupon_requests')
                ->where('id', $request->id)
                ->update(['status' => $status]);
            return redirect()->back()->with('error', __('Request reject sucessfully.'));
        }
    }
}
