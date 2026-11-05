
{{Form::model($contact, array('route' => array('contacts.update', $contact->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}
<div class="row">
    <div class="form-group col-md-12">
        {!! Form::label('first_name', __('First Name'), ['class' => 'form-label']) !!}
        {!! Form::text('first_name', null, ['class' => 'form-control']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('last_name', __('Last Name'), ['class' => 'form-label']) !!}
        {!! Form::text('last_name', null, ['class' => 'form-control']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('email', __('Email'), ['class' => 'form-label']) !!}
        {!! Form::email('email', null, ['class' => 'form-control']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('contact', __('Contact'), ['class' => 'form-label']) !!}
        {!! Form::text('contact', null, ['class' => 'form-control']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('subject', __('Subject'), ['class' => 'form-label']) !!}
        {!! Form::text('subject', null, ['class' => 'form-control']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('description', __('Description'), ['class' => 'form-label']) !!}
        {!! Form::textarea('description', null, ['rows' => 4, 'class'=>'form-control']) !!}
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}

