{!! Form::open(['route' => 'stores.store', 'method' => 'post', 'enctype' => 'multipart/form-data', 'autocomplete' => 'off']) !!}

    @if((auth()->user()->type == 'super admin') && (!empty($setting['chatgpt_key'])))
        <div class="d-flex justify-content-end">
            <a href="#" class="btn btn-primary ai-btn btn-badge" data-size="lg" data-ajax-popup-over="true" data-url="{{ route('generate',['store']) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Generate') }}" data-title="{{ __('Generate Content With AI') }}">
                <i class="fas fa-robot"></i> {{ __('Generate with AI') }}
            </a>
        </div>
    @endif

    @if((auth()->user()->type == 'admin') && $plan && ($plan->enable_chatgpt == 'on'))
        <div class="d-flex justify-content-end">
            <a href="#" class="btn btn-primary ai-btn btn-badge" data-size="lg" data-ajax-popup-over="true" data-url="{{ route('generate',['store']) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Generate') }}" data-title="{{ __('Generate Content With AI') }}">
                <i class="fas fa-robot"></i> {{ __('Generate with AI') }}
            </a>
        </div>
    @endif

    <div class="row">
        <div class="form-group col-md-12">
            {!! Form::label('storename', __('Store Name'), ['class' => 'form-label']) !!}
            {!! Form::text('storename', null, ['class' => 'form-control', 'id' => 'storename', 'placeholder' =>__('Enter Store Name'), 'required' => 'true']) !!}
        </div>

        @if (auth()->user()->type == 'super admin')
            <div class="form-group col-md-12">
                {!! Form::label('name', __('Name'), ['class' => 'form-label']) !!}
                {!! Form::text('name', null, ['class' => 'form-control','id'=>'name','placeholder'=>__('Enter Name'), 'required' => 'true']) !!}
            </div>
            <div class="form-group col-md-12">
                {!! Form::label('email', __('Email'), ['class' => 'form-label']) !!}
                {!! Form::email('email', null, ['class' => 'form-control','id'=>'email','placeholder' =>__('Enter Email'), 'required' => 'true']) !!}
            </div>
            <div class="form-group col-md-12">
                {!! Form::label('password', __('Password'), ['class' => 'form-label']) !!}
                {{Form::password('password',array('class'=>'form-control','id'=>'password','placeholder' =>__('Enter Password'), 'required' => 'true'))}}
            </div>
        @endif

        <div class="modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
        </div>
    </div>
{!! Form::close() !!}
