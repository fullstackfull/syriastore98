<?php

namespace App\DataTables;

use App\Models\Customer;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;
use Illuminate\Http\Request;

class CustomerDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $activitylog = ActivityLog::groupBy('customer_id')->where('store_id', getCurrentStore())->get();
        return (new EloquentDataTable($query))
            ->addIndexColumn()
            ->editColumn('first_name', function (Customer $customer) use ($activitylog) {
                $customer_info = null;
                if ($customer) { // Check if $customer is not null

                    $activityLogEntry = $activitylog->where('customer_id', $customer->id)->first();

                    if ($activityLogEntry) {
                        $customer_info = '<a class="text-primary" href="' . route('customer.timeline', $customer->id) . '">
                        <span class="btn-inner--icon"></span>
                        <span class="btn-inner--text text-capitalize">' . $customer->first_name . ' ' . $customer->last_name . '</span><br></a>' . $customer->mobile;
                    } else {
                        $customer_info = '<a class="text-primary" href="' . route('customer.timeline', $customer->id) . '">
                        <span class="btn-inner--icon"></span>
                        <span class="btn-inner--text text-capitalize">' . $customer->first_name . ' ' . $customer->last_name . '</span><br></a>' . $customer->mobile;
                    }
                }
                return $customer_info;
            })
            ->editColumn('last_active', function (Customer $customer) {
                if ($customer && $customer->last_active) { // Ensure $customer is not null
                    $active = \Carbon\Carbon::parse($customer->last_active);
                    return $active->format('F d, Y');
                }
                return null;
            })
            ->editColumn('regiester_date', function (Customer $customer) {
                if ($customer && $customer->regiester_date) { // Ensure $customer is not null
                    $carbonDate = \Carbon\Carbon::parse($customer->regiester_date);
                    return $carbonDate->format('F d, Y');
                }
                return null;
            })
            ->editColumn('orders', function (Customer $customer) {
                if ($customer) { // Ensure $customer is not null
                    return '<a href="' . route('customer.show', $customer->id) . '">' . $customer->Ordercount() . '</a>';
                }
                return null;
            })
            ->editColumn('total_spend', function (Customer $customer) {
                return $customer ? ($customer->total_spend() ?? 0) : 0; // Ensure $customer is not null
            })
            ->editColumn('aov', function (Customer $customer) {
                if ($customer && $customer->total_spend() && $customer->Ordercount()) {
                    return number_format($customer->total_spend() / $customer->Ordercount(), 2);
                }
                return 0;
            })
            ->addColumn('action', function (Customer $customer) use ($activitylog) {
                if ($customer) {
                    $activityLogEntry = $activitylog->where('customer_id', $customer->id)->first();
                    return view('customer.action', compact('customer', 'activityLogEntry'));
                } else {
                    return null;
                }
            })
            ->addColumn('status', function (Customer $customer) {
                if ($customer) {
                    return view('customer.status', compact('customer'));
                } else {
                    return null;
                }
            })
            ->filterColumn('first_name', function ($query, $keyword) {
                $query->whereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$keyword}%"])->orWhere('mobile', 'Like', "%{$keyword}%");
            })
            ->rawColumns(['first_name', 'email', 'last_active', 'regiester_date', 'orders', 'total_spend', 'aov', 'action', 'status']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Customer $model, Request $request): QueryBuilder
    {
        $query = $model->newQuery()->where('store_id', getCurrentStore());

        $requestData = $request->all();

        if (isset($requestData['field_name']) && ($requestData['field_name'] == 'Name' || $requestData['field_name'] == 'Email')) {
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                // check for name and email filtering
                if ($requestData['selected_name'] === 'Includes') {
                    $query->where(function ($subQuery) use ($requestData) {
                        $subQuery->where('first_name', 'like', '%' . $requestData['text_field'] . '%')
                            ->orWhere('email', 'like', '%' . $requestData['text_field'] . '%');
                    });
                } elseif ($requestData['selected_name'] === 'Excludes') {
                    $query->where(function ($subQuery) use ($requestData) {
                        $subQuery->where(function ($sQuery) use ($requestData) {
                            $sQuery->where('first_name', 'not like', '%' . $requestData['text_field'] . '%')
                                ->orWhere('email', 'not like', '%' . $requestData['text_field'] . '%');
                        });
                    });
                }
            }
        }

        // Check for last active filtering
        if (isset($requestData['field_name']) && $requestData['field_name'] == 'Last Active') {
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                $dateValue = $requestData['text_field'];
                if ($requestData['selected_name'] === 'Before') {
                    $query->whereDate('last_active', '<', $dateValue);
                } elseif ($requestData['selected_name'] === 'After') {
                    $query->whereDate('last_active', '>', $dateValue);
                } else {
                    $query->whereDate('last_active', $dateValue);
                }
            }
        }

        // check for AOV filtering
        if (isset($requestData['field_name']) && $requestData['field_name'] == 'AOV') {
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                $filteredCustomers = [];
                foreach ($query->get() as $key => $value) {
                    $AOV = 0;
                    if ($value->total_spend() != 0 && $value->Ordercount() != 0) {
                        $AOV = number_format($value->total_spend() / $value->Ordercount(), 2);

                        // Check the condition and filter
                        $text_field_float = (float) $requestData['text_field'];
                        $AOV = (float) str_replace(',', '', $AOV);
                        if ($requestData['selected_name'] === 'Less Than' && $AOV < $text_field_float) {
                            $filteredCustomers[] = $value->id;
                        } elseif ($requestData['selected_name'] === 'More Than' && $AOV > $text_field_float) {
                            $filteredCustomers[] = $value->id;
                        } elseif ($requestData['selected_name'] === 'Equal' && $AOV = $text_field_float) {
                            $filteredCustomers[] = $value->id;
                        }
                    }
                }
                $query->whereIn('id', $filteredCustomers);
            }
        }

        // check for number of orders filtering
        if (isset($requestData['field_name']) && $requestData['field_name'] == 'No. of Orders') {
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                $filteredCustomers = [];

                $orderCountValue = (int) $requestData['text_field'];
                foreach ($query->get() as $key => $value) {
                    $counter = $value->Ordercount();

                    if ($requestData['selected_name'] === 'Less Than' && $counter < $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    } else if ($requestData['selected_name'] === 'Less Than' && $counter > $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    } else if ($requestData['selected_name'] === 'Equal' && $counter == $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    }
                }
                $query->whereIn('id', $filteredCustomers);
            }
        }

        // check for Total Spend filtering
        if (isset($requestData['field_name']) && $requestData['field_name'] == 'Total Spend') {
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                $filteredCustomers = [];

                $orderCountValue = (int) $requestData['text_field'];
                foreach ($query->get() as $key => $value) {
                    $counter = $value->total_spend();

                    if ($requestData['selected_name'] === 'Less Than' && $counter < $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    } else if ($requestData['selected_name'] === 'Less Than' && $counter > $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    } else if ($requestData['selected_name'] === 'Equal' && $counter == $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    }
                }
                $query->whereIn('id', $filteredCustomers);
            }
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('customer-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'first_name' => ['title' => __('Customer Info')],
            'email' => ['title' => __('Email')],
            'last_active' => ['title' => __('Last Active')],
            'regiester_date' => ['title' => __('Date Registered')],
            'orders' => ['title' => __('Orders'), 'orderable' => false, 'searchable' => false],
            'total_spend' => ['title' => __('Total Spend'), 'orderable' => false, 'searchable' => false],
            'aov' => ['title' => __('AOV'), 'orderable' => false, 'searchable' => false],
            'status' => ['title' => __('Status'), 'exportable' => false, 'printable' => false, 'width' => 60],
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Customer_' . date('YmdHis');
    }
}
