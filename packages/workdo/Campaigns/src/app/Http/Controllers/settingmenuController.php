<?php

namespace Workdo\Campaigns\app\Http\Controllers;


use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\LandingPage\Entities\LandingPageSetting;
use App\Models\Store;
use Workdo\Campaigns\app\Models\Campaigns;

class settingmenuController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        if (\Auth::user() && \Auth::user()->type == 'super admin') {
            return view('campaigns::category.index');
        } else {
            return redirect()->back()->with('error', __('Permission Denied'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        //for create on landing page
        if (\Auth::user() && \Auth::user()->type == 'super admin') {
            return view('campaigns::superAdmin.sidebar');
        } else {
            return redirect()->back()->with('error', __('Permission Denied'));
        }
    }
    public function store(Request $request)
    {
        //store data in landingpage
        if ($request->product_campaign) {
            $product_campaign = 'on';
            $validator = \Validator::make(
                $request->all(),
                [
                    'product_campaign_title' => 'required',
                    'product_campaign_heading' => 'required',
                    'product_campaign_description' => 'required',
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        } else {
            $product_campaign = 'off';
        }
        $data['product_campaign'] = $product_campaign;
        $data['product_campaign_title'] = $request->product_campaign_title;
        $data['product_campaign_heading'] = $request->product_campaign_heading;
        $data['product_campaign_description'] = $request->product_campaign_description;

        foreach ($data as $key => $value) {
            LandingPageSetting::updateOrCreate(['name' =>  $key], ['value' => $value]);
        }
        return redirect()->back()->with(['success' => __('Campaign update successfully')]);
    }

    public function campaginSorting(Request $request)
    {
        $currentDate = \Carbon\Carbon::now();

        $orderby = $request->get('orderby', 'latest');
        $campaignsQuery = Campaigns::join('stores', 'campaigns.store', '=', 'stores.id')
            ->where('campaigns.status', 1)
            ->select('stores.*', 'campaigns.status as campaign_status', 'campaigns.name', 'campaigns.user', 'campaigns.store', 'campaigns.created_at', 'campaigns.end_date');

        // Apply date filtering for active campaigns
        $campaignsQuery->whereDate('campaigns.start_date', '<=', $currentDate)
            ->whereDate('campaigns.end_date', '>=', $currentDate);

        if ($orderby === 'latest') {
            $campaignsQuery->orderBy('campaigns.updated_at', 'desc');
        } elseif ($orderby === 'popularity') {
            $campaignsQuery->leftJoin('shetabit_visits', 'stores.id', '=', 'shetabit_visits.store_id')
                ->leftJoin('campaigns as c', 'stores.id', '=', 'c.store')
                ->select(
                    'stores.id',
                    'stores.slug',
                    'stores.name',
                    'c.user as user',
                    'c.store as store',
                    'c.status as status',
                    'c.name as name',

                    \DB::raw('COUNT(shetabit_visits.id) as visit_count')
                )
                ->where('c.status', 1)
                ->groupBy(
                    'stores.id',
                    'stores.slug',
                    'stores.name',
                    'c.status',
                    'c.name',
                    'c.user',
                    'c.store',

                )
                ->orderBy('visit_count', 'desc');
        }
        $productDetail = $campaignsQuery->get();
        $return = view('campaigns::superAdmin.campaigns_sort', compact('productDetail'))->render();
        return response()->json($return);
    }
    public function share($slug)
    {
        $productDetail = getStore($slug);
        return view('campaigns::superAdmin.share_campaigns', compact('productDetail'));
    }
    public function contactData($id)
    {
        return view('campaigns::superAdmin.contact_view');
    }
}
