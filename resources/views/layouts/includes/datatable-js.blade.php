<script src="{{asset('vendor/datatables/datatables.min.js')}}"></script>
<script src="{{ asset('vendor/datatables/dataTables.buttons.min.js') }}"></script>
<script src="{{ asset('vendor/datatables/buttons.bootstrap.min.js') }}"></script>
<script src="{{ asset('vendor/datatables/buttons.colVis.min.js') }}"></script>
<script src="{{ asset('vendor/datatables/buttons.server-side.js') }}"></script>
{{--
    P2-06-6 — ColReorder 1.5.4, vendored, MIT.

    VERSION CHOSEN BY ITS PEER REQUIREMENT, not by being newest. The installed bundle is
    DataTables **1.10.20** (`datatables.min.js` header: `#bs4/dt-1.10.20`), and ColReorder's
    dependency on DataTables changes mid-line: 1.5.0–1.5.4 declare `datatables.net ^1.10.15`,
    while **1.5.5 and later require `>= 1.11.3`** and would not be satisfied here. 1.5.4 is
    therefore the last compatible release, checked against the registry's own metadata for every
    1.5.x rather than guessed from a changelog.

    LOADED LOCALLY, NEVER FROM A CDN, per the owner's decision. The file is byte-identical to the
    published package (`npm pack datatables.net-colreorder@1.5.4`, sha1 2b9b273f…), its own
    `/*! ColReorder 1.5.4 ©2010-2021 SpryMedia Ltd */` banner is intact, and the full licence
    text sits beside it as `dataTables.colReorder.LICENSE.txt`.

    AFTER the core bundle and the Buttons extensions, because it registers itself against
    `$.fn.dataTable` and must find it already defined.
--}}
<script src="{{ asset('vendor/datatables/dataTables.colReorder.min.js') }}"></script>
<script>
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