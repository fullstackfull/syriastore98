<?php

namespace Workdo\PDFVouchers\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;
use Workdo\PDFVouchers\app\Models\CouponRequest;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;

class UsersData extends DataTable
{
 
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $datatable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->editColumn('name', function (CouponRequest $request) {
                return $request->name ? $request->name : '';
            })
            ->editColumn('email', function (CouponRequest $request) {
                return $request->email ? $request->email : '';
            })
            ->editColumn('detail', function (CouponRequest $request) {
                return $request->detail ? $request->detail : '';
            })
            ->addColumn('status', function (CouponRequest $request) {
                return view('coupon.action', compact('request'))->render();
            })
            ->rawColumns(['status','detail','email','name']);
        return $datatable;
    }


    /**
     * Get the query source of dataTable.
     */
    public function query(CouponRequest $model): QueryBuilder
    {
        $store = Store::find(getCurrentStore());
        if ($store) {
            $model = $model->where('status', null)->where('store_id', $store->id);
        }

        return $model->newQuery();
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('users-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'email' => ['title' => __('E-Mail')],
            'detail' => ['title' => __('Detail')],
            'status' => ['title' => __('Status')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Users_' . date('YmdHis');
    }
}
