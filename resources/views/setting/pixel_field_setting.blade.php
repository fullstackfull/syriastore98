<div class="card" id="Pixel_Setting">
    <div class="card-header">
        <div class="row g-0">
            <div class="col-6">
                <h5> {{ __('Pixel Fields Settings') }} </h5>
                <small>{{ __('Enter Your Pixel Fields Settings') }}</small>
            </div>
            <div class="col-6 text-end">
                <a href="javascript:;" class="btn btn-sm btn-icon btn-primary me-2" data-ajax-popup="true"
                    data-url="{{ route('pixel-setting.create') }}" data-toggle="tooltip"
                    data-bs-placement="top" title="{{ __('Add New Pixel') }}"
                    data-title="{{ __('Create New Pixel') }}">
                <i class="ti ti-plus"></i>

                </a>
            </div>
        </div>
    </div>
    <div class="">
        <div class="row g-0">
            <div class="card-body table-border-style">
                <div class="datatable-container">
                    <div class="table-responsive custom-field-table">
                        <table class="table dataTable-table" id="pc-dt-simple" data-repeater-list="fields">
                            <thead class="thead-light">
                                <tr>
                                    <th>{{ __('Platform') }}</th>
                                    <th>{{ __('Pixel Id') }}</th>
                                    <th class="text-end">
                                        {{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($PixelFields as $PixelField)
                                    <tr>
                                        <td class="text-capitalize">
                                            {{ $PixelField->platform }}
                                        </td>
                                        <td>
                                            {{ $PixelField->pixel_id }}
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-end">
                                            <button class="btn btn-sm btn-info btn-badge" data-url="{{ route('pixel-setting.edit', $PixelField->id) }}" data-size="md" data-ajax-popup="true" data-title="{{ __('Edit') }}" data-bs-toggle="tooltip" title="{{ __('Edit') }}"> <i class="ti ti-pencil"></i></button>
                                                {!! Form::open([
                                                    'method' => 'DELETE',
                                                    'route' => ['pixel-setting.destroy', $PixelField->id],
                                                    'class' => 'd-inline',
                                                ]) !!}
                                                <button type="button"
                                                    class="btn btn-sm btn-danger btn-badge show_confirm" data-toggle="tooltip"
                                                    title="{{ __('Delete') }}">
                                                    <i class="ti ti-trash"></i>
                                                </button>
                                                {!! Form::close() !!}
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .table.dataTable-table tr td, .table.dataTable-table tr th {
        white-space: nowrap ;
    }
</style>