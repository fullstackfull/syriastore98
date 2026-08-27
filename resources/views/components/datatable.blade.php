@props(['dataTable'])
@push('css')
   @include('layouts.includes.datatable-css')
@endpush
<div class="card">
   <div class="card-body table-border-style">
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
