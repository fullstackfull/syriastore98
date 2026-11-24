<?php

namespace App\DataTables;

use App\Support\Html\RichText;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Workdo\Helpdesk\DataTables\HelpdeskCategoryData;
use Yajra\DataTables\EloquentDataTable;

/**
 * The Helpdesk module's category list, with the colour swatch written from a stored colour that really is one.  P1-SEC-75.
 *
 * The vendored `HelpdeskCategoryData` (not edited) writes `style="background-color: <stored colour>; ..."` into a raw column. Who can store a colour there is the operator today (the
 * module seeds 'Create Helpdesk Category' for the super admin role only, and a merchant can grant a role only what the merchant holds -- RolePolicy::isGrantableBy), so this is hardening
 * and not a hole; it is done because the next permission change would otherwise make it one, on the operator's page. The colour goes through RichText::cssColor(): a value that is not a
 * colour is dropped with its declaration, and one that is cannot close the attribute. The vendored closure for the cell still runs first (it is a string concatenation and cannot fail),
 * and this one replaces its output.
 */
final class SafeHelpdeskCategoryData extends HelpdeskCategoryData
{
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return parent::dataTable($query)
            ->editColumn('color', fn ($category) => '<div class="btn-badge" style="' . self::background($category->color) . 'width: 20px; height: 20px;"></div>');
    }

    private static function background(?string $color): string
    {
        $color = RichText::cssColor($color);

        return $color === null ? '' : 'background-color: ' . e($color) . '; ';
    }
}
