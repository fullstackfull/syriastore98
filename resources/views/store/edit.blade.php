{{Form::model($store, array('route' => array('stores.update', $store->id), 'method' => 'PUT')) }}

@if((auth()->user()->type == 'super admin') && (!empty($setting['chatgpt_key'])))
    <div class="d-flex justify-content-end">
        <a href="#" class="btn btn-primary btn-badge ai-btn" data-size="lg" data-ajax-popup-over="true" data-url="{{ route('generate',['store']) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Generate') }}" data-title="{{ __('Generate Content With AI') }}">
            <i class="fas fa-robot"></i> {{ __('Generate with AI') }}
        </a>
    </div>
@endif
@if((auth()->user()->type == 'admin') && $plan && ($plan->enable_chatgpt == 'on'))
    <div class="d-flex justify-content-end">
        <a href="#" class="btn btn-primary btn-badge ai-btn" data-size="lg" data-ajax-popup-over="true" data-url="{{ route('generate',['store']) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Generate') }}" data-title="{{ __('Generate Content With AI') }}">
            <i class="fas fa-robot"></i> {{ __('Generate with AI') }}
        </a>
    </div>
@endif
<div class="row">
    <div class="col-12">
        <div class="form-group">
            {{Form::label('name',__('Name'),array('class'=>'form-label'))}}
            {{Form::text('name',$user->name,array('class'=>'form-control', 'id' => 'name','placeholder'=>__('Enter Name'), 'required' => 'true'))}}
        </div>
    </div>

    <div class="col-12">
        <div class="form-group">
            {{Form::label('storename',__('Store Name'),array('class'=>'form-label'))}}
            {{Form::text('storename',$store->name,array('class'=>'form-control', 'id' => 'storename','placeholder'=>__('Enter Store Name'), 'required' => 'true'))}}
        </div>
    </div>
    <div class="col-12">
        <div class="form-group">
            {{Form::label('email',__('Email'),array('class'=>'form-label'))}}
            {{Form::email('email',null,array('class'=>'form-control', 'id' => 'email','placeholder'=>__('Enter Email'), 'required' => 'true'))}}
        </div>
    </div>

</div>
<div class="modal-footer pb-0">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn btn-primary btn-badge mx-1">
</div>
{{Form::close()}}
