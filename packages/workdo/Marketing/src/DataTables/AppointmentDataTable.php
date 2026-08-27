<?php

namespace Workdo\Marketing\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Workdo\Marketing\app\Models\AppointmentDeatail;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;
use Illuminate\Http\Request;

class AppointmentDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $rowColumn = ['name', 'status'];
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->editColumn('name', function ($row) {
                return ucfirst($row->name);
            })
            ->editColumn('status', function (AppointmentDeatail $appointmentDetails) {
                if ($appointmentDetails->status == "pending") {
                    $html = '<span class="badge fix_badge bg-warning bg-info p-2 px-3 rounded">' . ucFirst($appointmentDetails->status) . '</span>';
                } else {

                    $html = '<span class="badge fix_badge bg-primary p-2 px-3 rounded">' . ucFirst($appointmentDetails->status) . '</span>';
                }

                return $html;
            });

        $dataTable->addColumn('action', function (AppointmentDeatail $appointmentDetails) {
            return view('marketing::appointments.action', compact('appointmentDetails'));
        });
        $rowColumn[] = 'action';

        return $dataTable->rawColumns($rowColumn);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(AppointmentDeatail $model,Request $request): QueryBuilder
    {
        $user = auth()->user();
        $store_id = $user->current_store;

        $appointment = $model->newQuery()
            ->where('created_by', $user->id)
            ->where('appointment_deatails.store_id', $store_id);

        if (!empty($request->start_date)) {
            $appointment=$appointment->where('date', '>=', $request->start_date);
        }
        if (!empty($request->end_date)) {
            $appointment=$appointment->where('date', '<=', $request->end_date);
        }
            $appointment=$appointment->orderBy('date', 'DESC');
        return $appointment;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('marketing-appointment-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'date' => ['title' => __('Date')],
            'time' => ['title' => __('Time')],
            'name' => ['title' => __('Name')],
            'email' => ['title' => __('Email')],
            'phone' => ['title' => __('Phone')],
            'status' => ['title' => __('Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Appointment_' . date('YmdHis');
    }
}
