<?php

namespace Workdo\Campaigns\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\Campaigns\app\Models\Campaigns;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class AllCampaignsData extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (Campaigns $campaigns) {
                return view('campaigns::action', compact('campaigns'));
            })
            ->editColumn('user', function ($campaigns) {
                return ucfirst($campaigns->users->name);
            })
            ->filterColumn('user', function ($query, $keyword) {
                $query->whereHas('users', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('store', function ($campaigns) {
                return ucfirst($campaigns->stores->name);
            })
            ->filterColumn('store', function ($query, $keyword) {
                $query->whereHas('stores', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('product', function ($campaigns) {
                return ucfirst($campaigns->products->name);
            })
            ->filterColumn('product', function ($query, $keyword) {
                $query->whereHas('products', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('status', function ($campaigns) {
                $status = $campaigns->status;
                $badgeClass = '';
                switch ($status) {
                    case 0:
                        $badgeClass = 'bg-danger';
                        break;
                    case 1:
                        $badgeClass = 'bg-info';
                        break;
                    case 2:
                        $badgeClass = 'bg-warning';
                        break;
                    case 3:
                        $badgeClass = 'bg-secondary';
                        break;
                }
                return '<span class="btn btn-sm btn-icon btn-badge ' . $badgeClass . '" style="cursor: default;">' .
                    Campaigns::$statuses[$status] .
                    '</span>';
            })
            ->rawColumns(['action', 'status', 'user', 'store', 'product']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Campaigns $model, Request $request): QueryBuilder
    {
        $query = $model->newQuery();
        $query->with('stores', 'users');

        if (!empty($request->user)) {
            $query->where('user', $request->user);
        }

        if (!empty($request->store)) {
            $query->where('store', $request->store);
        }

        if (!empty($request->product)) {
            $query->where('product', $request->product);
        }

        if (!empty($request->start_date)) {
            $query->where('start_date', '>=', $request->start_date);
        }

        if (!empty($request->end_date)) {
            $query->where('end_date', '<=', $request->end_date);
        }

        $query->orderBy('id', 'desc');

        if (Auth::user()->type == 'admin') {
            $query->where('created_by', Auth::user()->creatorId());
        }

        $currentDate = now();
        $query->get()->each(function ($campaign) use ($currentDate) {
            if ($currentDate->greaterThan($campaign->end_date)) {
                $campaign->status = 2;
                $campaign->save();
            }
        });
        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('campaigns-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Campaign Name')],
            'user' => ['title' => __('User')],
            'store' => ['title' => __('Store')],
            'product' => ['title' => __('Product Name')],
            'start_date' => ['title' => __('Start Date')],
            'end_date' => ['title' => __('End Date')],
            'total_days' => ['title' => __('Total Days')],
            'total_cost' => ['title' => __('Total Amount')],
            'payment_method' => ['title' => __('Payment Method')],
            'status' => ['title' => __('Status')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Campaigns_' . date('YmdHis');
    }
}
