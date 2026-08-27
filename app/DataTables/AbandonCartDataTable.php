<?php

namespace App\DataTables;

use App\Models\Cart;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class AbandonCartDataTable extends DataTable
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
                ->editColumn('customer_id', function (Cart $cart) {
                    return isset($cart->UserData) ? ($cart->UserData->first_name .' '. $cart->UserData->last_name) : null;
                })
                ->editColumn('email', function (Cart $cart) {
                    return isset($cart->UserData) ? $cart->UserData->email : '';
                })
                ->addColumn('product', function (Cart $cart) {
                    $cart_count = Cart::where('customer_id',$cart->customer_id)->where('store_id', getCurrentStore())->count();
                    return view('cart.product', compact('cart','cart_count'));
                })
                ->addColumn('action', function (Cart $cart) {
                    return view('cart.action', compact('cart'));
                })
                ->filterColumn('customer_id', function ($query, $keyword) {
                    // Assuming `permissions` is a relationship, adjust as needed
                    $query->whereHas('UserData', function ($subQuery) use ($keyword) {
                        $subQuery->where('first_name', 'like', "%$keyword%")->orWhere('last_name', 'like', "%$keyword%");
                    });
                })
                ->filterColumn('email', function ($query, $keyword) {
                    // Assuming `permissions` is a relationship, adjust as needed
                    $query->whereHas('UserData', function ($subQuery) use ($keyword) {
                        $subQuery->where('email', 'like', "%$keyword%");
                    });
                })
                ->orderColumn('customer_id', function ($query, $direction) {
                    // Assuming `permissions` is a relationship, adjust as needed
                    $query->whereHas('UserData', function ($subQuery) use ($direction) {
                        $subQuery->orderBy('first_name', $direction)->orderBy('last_name', $direction);
                    });
                })
                ->orderColumn('email', function ($query, $direction) {
                    // Assuming `permissions` is a relationship, adjust as needed
                    $query->whereHas('UserData', function ($subQuery) use ($direction) {
                        $subQuery->orderBy('email', $direction);
                    });
                })
                ->rawColumns(['customer_id','email','product','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Cart $model): QueryBuilder
    {
         return $model->newQuery()
            ->selectRaw('
            MIN(carts.id) as id,
            carts.customer_id,
            MIN(carts.product_id) as product_id,
            MIN(carts.qty) as quantity,
            COUNT(*) as total_carts
        ')->with('UserData')->where('store_id', getCurrentStore())
            ->whereNotNull('carts.customer_id')
            ->groupBy('carts.customer_id');
       
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return buildDataTable('abandoncart-table', $this->builder(), $this->getColumns());        
    }

    /**
     * Get the dataTable columns definition.
     */
    public function getColumns(): array
    {
        return buildDataTableColumn([
            'customer_id' => ['title' => __('Customer'), 'addClass' => 'text-capitalize'],
            'email' => ['title' => __('Email')],
            'product' => ['title' => __('Product'), 'searchable' => false],
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'AbandonCart_' . date('YmdHis');
    }
}
