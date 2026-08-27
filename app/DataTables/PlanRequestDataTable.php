<?php

namespace App\DataTables;

use App\Models\PlanRequest;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class PlanRequestDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
        ->addIndexColumn()
        ->editColumn('user_id', function ($prequest) {
            return $prequest->user->name;
        })
        ->editColumn('plan_id', function ($prequest) {
            return $prequest->plan->name;
        })
        ->addColumn('max_products', function ($prequest) {
            return ($prequest->plan->max_products == '-1') ? __('Unlimited') : $prequest->plan->max_products .' Products';
        })
        ->addColumn('max_stores', function ($prequest) {
            return ($prequest->duration == 'Month') ? __('One Month') : (($prequest->duration == 'Year') ? __('One Year') : ($prequest->duration ?? '-'));
        })
        ->editColumn('created_at', function ($prequest) {
            return isset($prequest) ? $prequest->created_at->format('Y-m-d H:i:s') : '-';
        })
        ->addColumn('action', function ($prequest) {
            return view('plan_request.action', compact('prequest'));
        })
        ->filterColumn('user_id', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('user', function ($subQuery) use ($keyword) {
                $subQuery->where('name', 'like', "%$keyword%");
            });
        })
        ->filterColumn('plan_id', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('plan', function ($subQuery) use ($keyword) {
                $subQuery->where('name', 'like', "%$keyword%");
            });
        })
        ->filterColumn('max_products', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('plan', function ($subQuery) use ($keyword) {
                if (stripos('Unlimited', $keyword) !== false) {
                    // Filter for guest customers
                    $subQuery->where('max_products', '-1');
                } else {
                    $subQuery->where('max_products', $keyword);
                }
            });
        })
        ->filterColumn('max_stores', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('plan', function ($subQuery) use ($keyword) {
                if (stripos('Unlimited', $keyword) !== false) {
                    // Filter for guest customers
                    $subQuery->where('max_stores', '-1');
                } else {
                    $subQuery->where('max_stores', $keyword);
                }
            });
        })
        ->rawColumns(['user_id','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(PlanRequest $model): QueryBuilder
    {
        return $model->newQuery()->with(['user','plan']);
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('plan-request-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'user_id' => ['title' => __('Name')],
            'plan_id' => ['title' => __('Plan Name')],
            'max_products' => ['title' => __('Max Products')],
            'max_stores' => ['title' => __('Max Stores')],
            'duration' => ['title' => __('Duration')],
            'created_at' => ['title' => __('Created at')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'PlanRequest_' . date('YmdHis');
    }
}
