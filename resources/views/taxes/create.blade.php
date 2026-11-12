{{ Form::open(['route' => 'taxes.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
    <div class="row">
        <div class="form-group col-md-12">
            {{-- P2.5-WAVE-25: `Form::label('')` emits `for=""`, which points at nothing, so the
                 field had no programmatic label at all. `Form::text('name', ...)` generates
                 `id="name"`, so naming it here is the whole fix. --}}
            {!! Form::label('name', __('Name'), ['class' => 'form-label']) !!}
            {!! Form::text('name', null, ['class' => 'form-control']) !!}
        </div>
        <div class="modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
        </div>
    </div>
{!! Form::close() !!}
