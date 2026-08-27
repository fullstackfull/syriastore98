@extends('layouts.app')

@section('page-title', __('Campaigns'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Campaigns Settings') }}</li>
@endsection
@section('action-button')
    @if (Auth::user()->type == 'admin')
        <div class="col-xl-12 col-lg-12 col-md-12 d-flex align-items-center justify-content-between justify-content-md-end"
            data-bs-placement="top">
            <a href="#" data-size="lg" data-url="{{ route('campaigns.create') }}" data-ajax-popup="true"
                data-bs-toggle="tooltip" title="{{ __('Create') }}" data-title="{{ __('Create New Campaigns') }}"
                class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endif
@endsection
@section('content')
<div class="row">
    <div class="col-12">
        <div class="row">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5>{{ __('Cost Per Day Settings') }}</h5>
                        <a href="#" data-repeater-create type="button" class="btn btn-badge btn-sm btn-primary" data-bs-toggle="tooltip" title="{{ __('Add More') }}" data-title="{{ __('Add More') }}">
                            <i class="ti ti-plus"></i>
                        </a>
                    </div>

                    {{ Form::open(['route' => 'campaigns.cost-setting', 'method' => 'post']) }}
                    <div class="card-body table-border-style">
                        <div class="row my-2">
                            <div class="col-12 repeater">
                                <div data-repeater-list="store_group" class="form-group">
                                    @if (!empty($costDetail) && count($costDetail) > 0)
                                        @foreach ($costDetail as $key => $cost)
                                            <div data-repeater-item class="row row-gap mb-3 align-items-end">
                                                <input type="hidden" name="id" class="cat_id" value="{{ $cost->id }}" />
                                                <div class="col-lg-3">
                                                    {{ Form::label('min', __('Min Days')) }}
                                                    <input class="dtpiker form-control" type="text" name="min" placeholder="{{__('Enter Min Days')}}"
                                                        value="{{ $cost->min }}" />
                                                </div>
                                                <div class="col-lg-3">
                                                    {{ Form::label('max', __('Max Days')) }}
                                                    <input class="dtpiker form-control" type="text" name="max" placeholder="{{__('Enter Max Days')}}"
                                                        value="{{ $cost->max }}" />
                                                </div>
                                                <div class="col-lg-3">
                                                    {{ Form::label('price', __('Per Day Price')) }}
                                                    <input class="dtpiker form-control" type="text" name="price" placeholder="{{__('Enter Price')}}"
                                                        value="{{ $cost->price }}" />
                                                </div>
                                                <div class="col-lg-3">
                                                    <a data-repeater-delete href="javascript:void(0)" class="btn btn-sm btn-danger mb-2 mt-1 btn-badge" title="{{ __('Delete') }}" data-bs-toggle="tooltip">
                                                        <i class="ti ti-trash"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        @endforeach
                                    @else
                                        <div data-repeater-item class="row row-gap mb-3 align-items-end">
                                            <div class="col-lg-3">
                                                {{ Form::label('min', __('Min Days'), ['class'=>'mb-2']) }}
                                                <input class="dtpiker form-control" id="min" type="text" name="min" value="" />
                                            </div>
                                            <div class="col-lg-3">
                                                {{ Form::label('max', __('Max Days'), ['class'=>'mb-2']) }}
                                                <input class="dtpiker form-control" id="max" type="text" name="max" value="" />
                                            </div>
                                            <div class="col-lg-3">
                                                {{ Form::label('price', __('Per Day Price'), ['class'=>'mb-2']) }}
                                                <input class="dtpiker form-control" id="price" type="text" name="price" value="" />
                                            </div>
                                            <div class="col-lg-3">
                                                <a data-repeater-delete href="javascript:void(0)" class="btn btn-sm btn-danger btn-badge mt-1 mb-2" title="{{ __('Delete') }}" data-bs-toggle="tooltip">
                                                    <i class="ti ti-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                <input type="hidden" name="deleted_ids" id="deleted_ids" value="">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-badge btn-print-invoice btn-primary']) }}
                    </div>
                    {{ Form::close() }}
                </div>
        </div>
    </div>
</div>

@endsection
@push('scripts')
    <script src="{{ asset('assets/js/jquery.repeater.min.js') }}"></script>
    <script>
        $(document).ready(function() {
            var deletedIds = [];

            // Initialize the repeater
            $('.repeater').repeater({
                initEmpty: {{ empty($costDetail) ? 'true' : 'false' }},
                defaultValues: {
                    'min': '',
                    'max': '',
                    'price': ''
                },
                show: function() {
                    $(this).slideDown();
                    // Reinitialize tooltips for new elements
                    initializeTooltips();
                },
                hide: function(deleteElement) {
                    var id = $(this).find('.cat_id').val();
                    if (id) {
                        deletedIds.push(id);
                        $('#deleted_ids').val(deletedIds.join(','));
                    }
                    $(this).slideUp(deleteElement);
                },
                ready: function(setIndexes) {
                    // Optional callback when repeater is ready
                }
            });

            // Reinitialize tooltips after adding new item
            function initializeTooltips() {
                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.forEach(function(tooltipTriggerEl) {
                    new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }

            // Manually add a new item when the "Add More" button is clicked
            $('[data-repeater-create]').on('click', function(e) {
                e.preventDefault();

                // Clone the last repeater item, clear its values, and append it to the repeater list
                var $repeaterList = $('.repeater [data-repeater-list]');
                var $newItem = $repeaterList.children().last().clone();

                // Clear input values in the cloned item
                $newItem.find('input').val('');
                $newItem.find('.cat_id').val(''); // Clear any existing id in the new item

                // Append the new item to the repeater list and reinitialize tooltips
                $repeaterList.append($newItem);
                initializeTooltips();
            });

            // Initialize tooltips on page load
            initializeTooltips();
        });
    </script>
@endpush
