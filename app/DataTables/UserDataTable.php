<?php

namespace App\DataTables;

use App\Models\User;
use App\Support\DsBadge;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class UserDataTable extends DataTable
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
            /*
             * P2-05-5. THREE THINGS WERE WRONG WITH ONE CELL, and the live sweep found all three.
             *
             * It was `<a><img … id="blah3"></a>`:
             *   - an ANCHOR WITH NO href and no text, so every row contributed a control with no
             *     accessible name (3 of them on the dev fixture, in all eight configurations);
             *   - `id="blah3"` emitted ONCE PER ROW, so the document carried duplicate ids —
             *     which makes every `for=` and `getElementById` on the page ambiguous;
             *   - the image had no `alt`.
             *
             * The avatar is decoration beside the name column, not a link and not information, so
             * it is a bare `<img>` with an empty alt and `aria-hidden`: announced by nothing,
             * which is correct, rather than announced as an unnamed link. The URL is escaped
             * because this cell is built by concatenation inside a raw column.
             */
            ->editColumn('avatar', function (User $user) {
                $avatarUrl = check_file($user->profile_image) ? get_file($user->profile_image) : get_file('storage/uploads/avatar.png');

                return '<img src="' . e($avatarUrl) . '" class="img-fluid rounded-circle card-avatar" width="35" alt="" aria-hidden="true">';
            })
            /*
             * `ss-badge`, not `badge bg-primary`: white on the host brand green measures 2.59:1,
             * the figure P2-04-4, P2-05-3 and F133 all recorded. `DsBadge` carries the dot that
             * keeps the state from being signalled by colour alone, and escapes its own label.
             */
            ->editColumn('type', function (User $user) {
                return DsBadge::render('brand', (string) $user->type);
            })
            ->addColumn('action', function (User $user) {
                return view('users.action', compact('user'));
            })
            ->rawColumns(['avatar','name','type','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(User $model): QueryBuilder
    {
        return $model->newQuery()->where('created_by','=',\Auth::user()->creatorId())->where('current_store', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('user-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'avatar' => ['title' => __('Avatar'), 'orderable' => false, 'searchable' => false, 'exportable' => false],
            'name' => ['title' => __('Name')],
            'email' => ['title' => __('Email')],
            'type' => ['title' => __('Role')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'User_' . date('YmdHis');
    }
}
