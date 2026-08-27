<?php

namespace Workdo\Marketing\app\Http\Controllers;

use Workdo\Marketing\app\Models\AppointmentDeatail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Utility;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Controllers\Controller;
use Workdo\Marketing\DataTables\AppointmentDataTable;

class AppointmentController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */


    public function index(Request $request,AppointmentDataTable $dataTable)
    {


        if (auth()->user() && auth()->user()->isAbleTo('Manage Appointment')) {
            $user = auth()->user();

            $store_id = $user->current_store;
            $id = $store_id;

            $objUser = auth()->user();
            if ($id == null) {
                $appointents = AppointmentDeatail::where('created_by', auth()->user()->creatorId());
                if (!empty($request->start_date)) {
                    $appointents->where('date', '>=', $request->start_date);
                }
                if (!empty($request->end_date)) {
                    $appointents->where('date', '<=', $request->end_date);
                }
                $appointents = $appointents->get();
            } else {
                $appointents = AppointmentDeatail::where('store_id', $id)->where('created_by', auth()->user()->creatorId());
                if (!empty($request->start_date)) {
                    $appointents->where('date', '>=', $request->start_date);
                }
                if (!empty($request->end_date)) {
                    $appointents->where('date', '<=', $request->end_date);
                }
                $appointents = $appointents->get();
            }

            $arrayJson = [];
            foreach ($appointents as $appointent) {
                $time = explode('-', $appointent->time);
                $stime = isset($time[0]) ? trim($time[0]) . ':00' : '00:00:00';
                $etime = isset($time[1]) ? trim($time[1]) . ':00' : '00:00:00';
                $start_date = date("Y-m-d", strtotime($appointent->date)) . ' ' . $stime;
                $end_date = date("Y-m-d", strtotime($appointent->date)) . ' ' . $etime;

                $arrayJson[] = [
                    "title" => '(' . $stime . ' - ' . $etime . ') ' . $appointent->name . '-' . $appointent->getStoreName(),
                    "start" => $start_date,
                    "end" => $end_date,
                    "app_id" => $appointent->id,
                    "url" => route('appointment.details', $appointent->id),
                    "className" => 'event-info',
                    "allDay" => false,
                    "store_id" => $id,
                ];
            }
            return $dataTable->with('request', $request)->render('marketing::appointments.index', compact('arrayJson'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));

        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }
    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {

        $slug = $request->slug;
        $store_id = $request->business_id;
        $business = Store::where('slug', $slug)->where('id', $store_id)->first();
        $appointment_deatails = AppointmentDeatail::where('store_id', $store_id)->get();


        if ($appointment_deatails) {
            foreach ($appointment_deatails as $key => $value) {

                if ($value->date == $request->date && $value->time == $request->time) {
                    $data['msg'] = __("The appointment already booked.Please select another date or time.");
                    $data['flag'] = false;
                    return $data;

                }
            }
        }
        $user = User::where('id', $business->created_by)->first();

        $appointment = AppointmentDeatail::create([
            'store_id' => $request->business_id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'date' => $request->date,
            'time' => $request->time,
            'created_by' => $business->created_by
        ]);

        $module = 'New Appointment';

        if ($user) {
            $webhook = Utility::webhook($module, $business->created_by);

        } else {
            $webhook = Utility::webhook($module, $business->created_by);
        }

        if ($webhook) {
            $parameter = json_encode($appointment);
            $status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);
        }
    }


    public function destroy(Request $appointment_deatail, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Appointment')) {
            $app = AppointmentDeatail::find($id);
            if ($app) {
                $app->delete();
                return redirect()->back()->with('success', __('Appointment successfully deleted.'));
            } else {
                return redirect()->back()->with('error', __('Appointment not found.'));
            }

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function getCalenderAllData($id = null)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Calendar Appointment')) {
            $objUser = auth()->user();
            if ($id == null) {
                $appointents = AppointmentDeatail::where('created_by', auth()->user()->creatorId())->get();
            } else {
                $appointents = AppointmentDeatail::where('store_id', $id)->where('created_by', auth()->user()->creatorId())->get();
            }

            $arrayJson = [];
            foreach ($appointents as $appointent) {
                $time = explode('-', $appointent->time);
                $stime = isset($time[0]) ? trim($time[0]) . ':00' : '00:00:00';
                $etime = isset($time[1]) ? trim($time[1]) . ':00' : '00:00:00';
                $start_date = date("Y-m-d", strtotime($appointent->date)) . ' ' . $stime;
                $end_date = date("Y-m-d", strtotime($appointent->date)) . ' ' . $etime;

                $arrayJson[] = [
                    "title" => '(' . $stime . ' - ' . $etime . ') ' . $appointent->name . '-' . $appointent->getStoreName(),
                    "start" => $start_date,
                    "end" => $end_date,
                    "app_id" => $appointent->id,
                    "url" => route('appointment.details', $appointent->id),
                    "className" => 'event-info',
                    "allDay" => false,
                    "store_id" => $id,
                ];
            }
            return view('marketing::appointments.calender', compact('arrayJson', 'id'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function getAppointmentDetails($id)
    {
        $ad = AppointmentDeatail::find($id);
        if ($ad) {
            return view('marketing::appointments.calender-modal', compact('ad'));
        } else {
            return redirect()->back()->with('error', __('Appointment not found.'));
        }
    }

    public function add_note($id)
    {
        $appointment = AppointmentDeatail::where('id', $id)->first();
        if ($appointment) {
            return view('marketing::appointments.add_note', compact('appointment'));
        } else {
            return redirect()->back()->with('error', __('Appointment not found.'));
        }
    }

    public function note_store($id, Request $request)
    {

        if (auth()->user()->isAbleTo('Edit Appointment')) {
            $appointment = AppointmentDeatail::where('id', $id)->first();
            $appointment->status = $request->status;
            $appointment->note = $request->note;
            $appointment->save();
            return redirect()->back()->with('success', __('Note added successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function get_appointment_data(Request $request)
    {
        $objUser = auth()->user();
        $store_id = $objUser->current_business;

        $id = $request->id;
        if ($request->get('is_live') == '1') {
            $type = 'appointents';
            $arrayJson = Utility::getCalendarData($type);
        } else {
            if ($id == null) {
                if ($store_id != null) {
                    $appointents = AppointmentDeatail::where('store_id', $store_id)->where('created_by', auth()->user()->creatorId());
                    if (!empty($request->start_date)) {
                        $appointents->where('date', '>=', $request->start_date);
                    }
                    if (!empty($request->end_date)) {
                        $appointents->where('date', '<=', $request->end_date);
                    }
                    $appointents = $appointents->get();
                } else {
                    $appointents = AppointmentDeatail::where('created_by', auth()->user()->creatorId());
                    if (!empty($request->start_date)) {
                        $appointents->where('date', '>=', $request->start_date);
                    }
                    if (!empty($request->end_date)) {
                        $appointents->where('date', '<=', $request->end_date);
                    }
                    $appointents = $appointents->get();
                }

            } else {

                $appointents = AppointmentDeatail::where('store_id', $id)->where('created_by', auth()->user()->creatorId());
                if (!empty($request->start_date)) {
                    $appointents->where('date', '>=', $request->start_date);
                }
                if (!empty($request->end_date)) {
                    $appointents->where('date', '<=', $request->end_date);
                }
                $appointents = $appointents->get();
            }

            $arrayJson = [];
            foreach ($appointents as $appointent) {
                $time = explode('-', $appointent->time);
                $stime = isset($time[0]) ? trim($time[0]) . ':00' : '00:00:00';
                $etime = isset($time[1]) ? trim($time[1]) . ':00' : '00:00:00';
                $start_date = date("Y-m-d", strtotime($appointent->date)) . ' ' . $stime;
                $end_date = date("Y-m-d", strtotime($appointent->date)) . ' ' . $etime;

                $arrayJson[] = [
                    "title" => '(' . $stime . ' - ' . $etime . ') ' . $appointent->name . '-' . $appointent->getStoreName(),
                    "start" => $start_date,
                    "end" => $end_date,
                    "app_id" => $appointent->id,
                    "url" => route('appointment.details', $appointent->id),
                    "className" => 'event-info',
                    "allDay" => false,
                    "store_id" => $id,
                ];
            }
        }
        return $arrayJson;
    }


}

