{{ Form::open(['route' => ['request.amount.store' , $id], 'method' => 'post']) }}
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('request_amount', __('Request Amount'), ['class' => 'form-label']) }}
                {{ Form::number('request_amount', $netAmount, ['class' => 'form-control', 'placeholder' => __('Enter Request Amount'), 'required' => 'required']) }}
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <button type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary cancel-btn" data-bs-dismiss="modal">{{__('Cancel')}}</button>
        <button type="submit" value="{{__('Send')}}" class="btn btn-badge btn-primary mx-1 continue-btn">{{__('Send')}}</button>
    </div>
{{ Form::close() }}
