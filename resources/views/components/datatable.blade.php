@props(['dataTable'])
@push('css')
   @include('layouts.includes.datatable-css')
@endpush
{{--
    `ss-dt-card` / `ss-dt-card-body` (P2-03-1) ARE THE HOOK FOR THE PINNED ACTION COLUMN, and
    they carry no appearance of their own. Why a class rather than styling `.card-body` is
    needed, and why the class has to be HERE:

    The merchant tables are a FIXED WIDTH at every viewport -- measured in the running
    application, the product list is 1168px at 1440, 1280, 992, 768 and 390 alike, eleven
    columns, none of which collapse. `.table-responsive` contains that (the DOCUMENT never
    overflows; measured at all five widths), so the row actions sit at the far end of a
    horizontal scroll: at 390px a merchant sees 320 of 1168 pixels and has 848px to drag
    through before Edit and Delete come into view. Pinning the actions column to the trailing
    edge is what fixes that, and `position: sticky` needs a background on the pinned cell or the
    rest of the row scrolls through it.

    THE BACKGROUND IS WHY THIS IS A CLASS AND NOT A TOKEN. `var(--ss-surface)` is the obvious
    choice and it is WRONG in dark mode: measured, the host paints this card `#292a33` while the
    design system's dark surface is `#263331`. Those are different hues, so a pinned cell filled
    with the token would be a visibly off-colour band down every dark table -- the same class of
    defect as ledger F8. So the pinned cell takes its colour from `--ss-dt-card-bg`, a custom
    property declared on THIS element, and a custom property is what makes the class belong
    here: DataTables injects three divs of its own between the wrapper and the table, so
    `background-color: inherit` chained down from the card does not reach the cell (tried, and
    measured at `rgba(0, 0, 0, 0)`), while a custom property inherits through any depth. The
    full reasoning, and the two measured values, are in `public/css/store-syria-ds.css`.

    AND SCOPING IT HERE IS THE FAIL-SAFE. 66 of the 68 DataTables in the application render
    through this one component (39 host views, 27 vendored), so the class reaches the vendored
    tables without a `packages/workdo/` edit. The sticky rule is scoped under `.ss-dt-card-body`,
    so the two tables that build their own markup get no sticky at all rather than a transparent
    pinned cell with rows sliding through it.
--}}
<div class="card ss-dt-card">
   <div class="card-body table-border-style ss-dt-card-body">
      <div class="table-responsive ecom-data-table">
           {!! $dataTable->table(['width' => '100%']) !!}
       </div>
   </div>
</div>
@push('scripts')
   @include('layouts.includes.datatable-js')
   {{ $dataTable->scripts() }}

   @if (module_is_active('BulkDelete'))
      @include('bulk-delete::pages.script')
   @endif

   <script>
   $(document).ready(function () {
      removeSortingOrderFromHeader();
   });
   function removeSortingOrderFromHeader() {
      // Remove 'sorting_desc' class from all <th> elements containing a checkbox
      $('table').each(function() {
         $(this).find('th').each(function() {
            if (($(this).hasClass('sorting_desc') || $(this).hasClass('sorting_asc')) && $(this).find('input[type="checkbox"]').length) {
                  $(this).removeClass('sorting sorting_asc sorting_desc');
            }
         });
      });

      // Ensure class is removed every time sorting is triggered on any DataTable
      $('table').on('order.dt', function() {
         $(this).find('th').each(function() {
            if (($(this).hasClass('sorting_desc') || $(this).hasClass('sorting_asc')) && $(this).find('input[type="checkbox"]').length) {
                  $(this).removeClass('sorting sorting_asc sorting_desc');
            }
         });
      });
   }
   </script>
@endpush
