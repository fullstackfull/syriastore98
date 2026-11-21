{{--
    MKT-001 — "An independent marketing section, LINKED TO ANALYTICS" (مرتبط بالتحليلات).

    Measured 2026-11-17 and re-measured 2026-11-21: the five Marketing-menu surfaces (Coupon,
    Newsletter, Flash Sale, Wishlist, Abandon Cart) contained ZERO references to any reports,
    analytics or finance route. The marketing→analytics direction of that requirement did not exist.
    `/analytics` does exist since P5-4b, so this is a link, not a feature.

    THE GATE IS `ReportAccess::permits()`, THE ROUTE'S OWN PREDICATE — not `@permission`.
    `/analytics` carries `ReportPermission`, whose action map holds `index => Manage Reports`, and
    that middleware admits the platform OPERATOR and the TENANT OWNER before it ever consults
    Laratrust. `@permission('Manage Reports')` is `isAbleTo()` alone, so it would hide this link
    from exactly the two principals the route lets through: the operator (Laratrust has no
    super-admin bypass) and an owner whose seeded `admin` role predates the permission name.
    `CompanyMenuListener` records the same trap from the other side — "naming a DIFFERENT permission
    here would hide the screen from people who may open it".

    So the question asked here is the question the route will answer, from one predicate, and a
    refused principal is shown nothing rather than a link to a refusal.

    The wrapper is the CALLER's, because the five screens have different action areas: four already
    carry a primary "Add …" button and one carries none. This is `ss-btn--secondary` and never
    `--primary`: the design system's hierarchy rule wants one primary action per screen, and two
    brand-filled buttons side by side means no primary action at all.

    ONE MISMATCH IS LATENT AND IS RECORDED RATHER THAN GUARDED, because it is currently
    unreachable. This asks the MIDDLEWARE's question, but `/analytics` also refuses a principal
    with no resolvable store inside `MerchantAnalyticsController::index` (`resolveStore()`), so a
    platform operator between stores would be shown a link to a refusal. They cannot reach any of
    these five screens today: all five gate on `isAbleTo('Manage X')` alone and an operator holds
    no Laratrust role (`GAP-218`). It becomes live the day one of those five gains an operator
    pass, which is `GAP-218`'s own next action — so whoever closes that reads this.
--}}
@if (\App\Services\ReportAccess::permits(auth('web')->user()))
    <a href="{{ route('analytics.index') }}" class="ss-btn ss-btn--secondary ss-btn--sm me-1">
        <i class="ti ti-chart-line" aria-hidden="true"></i>
        <span>{{ __('Analytics') }}</span>
    </a>
@endif
