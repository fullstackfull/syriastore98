<?php

namespace App\DataTables;

use App\Support\Html\RichText;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Workdo\Helpdesk\DataTables\HelpdeskStatusData;
use Yajra\DataTables\EloquentDataTable;

/**
 * The Helpdesk module's status list, with the colour swatch written from a stored colour that really is one.  P1-SEC-75.
 *
 * The same shape as SafeHelpdeskCategoryData, for `HelpdeskStatusData::status_color`: the vendored closure writes the stored value into a `style` attribute of a raw column; here a value
 * that is not a colour is dropped with its declaration. A status colour of `#fff" onfocus="alert(1)" autofocus tabindex="0` is a script that runs on page load in the operator's browser
 * (executed in Chromium by a reviewer of P1-SEC-75) -- written by whoever may create a status, who is the operator today.
 */
final class SafeHelpdeskStatusData extends HelpdeskStatusData
{
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return parent::dataTable($query)
            ->editColumn('status_color', fn ($status) => '<div class="btn-badge" style="' . self::background($status->status_color) . 'width: 20px; height: 20px;"></div>');
    }

    private static function background(?string $color): string
    {
        $color = RichText::cssColor($color);

        return $color === null ? '' : 'background-color: ' . e($color) . '; ';
    }
}
