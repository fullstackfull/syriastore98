<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may open or export a report.  P1-SEC-09.
 *
 * ReportController has 29 public methods and no authorization of any kind: the three report permissions (`Manage Reports`,
 * `Manage Order Reports`, `Manage Stock Reports`) were enforced ONLY by the sidebar menu (CompanyMenuListener), so a staff member whose
 * role held none of them received a byte-identical report body to the owner by URL -- order counts, revenue, top products, country
 * breakdown, customer lists, CSV/XLSX exports. Within one tenant (getCurrentStore() scopes the data), a vertical privilege escalation.
 *
 * ONE MECHANISM, attached in ReportController's constructor (`$this->middleware(...)`; Laravel 11's HasMiddleware cannot be implemented on
 * a class that extends this application's legacy Controller -- see PlanCouponController), so a handler added later is covered without a
 * line of its own. The action -> permission map below mirrors the menu entry that links to each screen, and an action NOT in the map is
 * held to `Manage Reports` rather than left open (a census test asserts every routed action is mapped, so that default is a net).
 *
 * ORDER OF THE CHECKS, as everywhere here: the platform operator first (Laratrust has no super-admin bypass, so isAbleTo() alone would
 * refuse the one principal who reads every store); then the tenant OWNER (`creatorId() === id`), because the seeded `admin` role of a
 * merchant created before a permission name existed would otherwise lock the owner out of their own reports -- the same reasoning as
 * StoreSettingsAccess::permits; then the named permission. Staff resolve to their employer's creatorId, so they get no owner pass.
 *
 * A refusal is a redirect with an error (a 403 for a JSON/ajax caller), never a 401: these are web routes, but the shipped Flutter app
 * force-logs-out on any 401 and the rule is applied uniformly.
 */
class ReportPermission
{
    private const REPORTS = 'Manage Reports';
    private const ORDERS = 'Manage Order Reports';
    private const STOCK = 'Manage Stock Reports';

    /** Controller action => the permission of the menu entry that links to it. */
    public const ACTIONS = [
        'index' => self::REPORTS, 'reports_chart' => self::REPORTS, 'export' => self::REPORTS, 'top_product' => self::REPORTS,

        'OrderReport' => self::ORDERS, 'order_reports_chart' => self::ORDERS, 'order_report_export' => self::ORDERS,
        'order_bar_report_export' => self::ORDERS, 'BarChartOrderReport' => self::ORDERS, 'order_reports_chart_data' => self::ORDERS,
        'OrderSaleProductReport' => self::ORDERS, 'order_product_reports' => self::ORDERS, 'product_order_export' => self::ORDERS,
        'OrderSaleCategoryReport' => self::ORDERS, 'order_category_reports' => self::ORDERS, 'category_order_export' => self::ORDERS,
        'OrderDownlodableReport' => self::ORDERS, 'OrderSaleBrandReport' => self::ORDERS, 'order_brand_reports' => self::ORDERS,
        'brand_order_export' => self::ORDERS, 'showOrderStatusReport' => self::ORDERS, 'showCountryOrderReport' => self::ORDERS,

        'StockReport' => self::STOCK, 'getLowStockProducts' => self::STOCK, 'getOutOfStockProducts' => self::STOCK, 'getMostStockedProducts' => self::STOCK,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $action = $route === null ? '' : $route->getActionMethod();
        $permission = self::ACTIONS[$action] ?? self::REPORTS;

        $user = auth('web')->user();

        if ($user !== null) {
            if (strtolower(trim((string) $user->type)) === 'super admin' || (int) $user->creatorId() === (int) $user->id || $user->isAbleTo($permission)) {
                return $next($request);
            }
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['status' => 'error', 'message' => __('Permission denied.')], 403);
        }

        return redirect()->back()->with('error', __('Permission denied.'));
    }
}
