<style>
    .accordion-button::after {
        width: 0px !important;
        height: 0px !important;
        background-image: url() !important;
    }
    @media (min-width: 310px){
        .col-md-12 {
            flex: 0 0 auto;
            width: 100%;
        }
    }
    @media (min-width: 310px){
        .col-md-6 {
            flex: 0 0 auto;
            width: 50%;
        }
    }
</style>
<div class="modal-body p-2">
    <div class="faq justify-content-center">
        <div class="row">
            <div class="accordion accordion-flush setting-accordion" id="accordionExample">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingOne">
                        <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapseOne"
                            aria-expanded="false" aria-controls="collapseOne">
                            <div class="col-md-12 d-flex align-items-center">
                                <div class="col-md-6">
                                    <label class="form-check-label" for="platformswt1"><b>{{__('Database Backup')}}</b></label>
                                </div>
                                <div class="col-md-6 text-end">
                                    <div class="form-check form-switch form-switch-right mb-2 custom-switch-v1">
                                        <input class="form-check-input" type="checkbox" role="switch" id="platformswt1" name="database">
                                    </div>
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div id="collapseOne" class="accordion-collapse collapse"
                        aria-labelledby="headingOne"
                        data-bs-parent="#accordionExample">
                        <div class="accordion-body p-4">
                            <div class="col-md-12">
                                <div class="input-edits">
                                    <div class="form-group">
                                        {{ Form::label('database_name', __('Database Name'),['class'=>'col-form-label']) }}
                                        {{ Form::text('database_name', null, array('class' => 'form-control database_name','id'=>'database_name','multiple'=>'','required'=>'required')) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingOne">
                        <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapseTwo"
                            aria-expanded="false" aria-controls="collapseTwo">
                            <div class="col-md-12 d-flex align-items-center">
                                <div class="col-md-6">
                                    <label class="form-check-label" for="platformswt1"><b>{{__('Files Backup')}}</b></label>
                                </div>
                                <div class="col-md-6 text-end">
                                    <div class="form-check form-switch form-switch-right mb-2 custom-switch-v1">
                                        <input class="form-check-input" type="checkbox" role="switch" id="platformswt2" name="files">
                                    </div>
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingOne" data-bs-parent="#accordionExample">
                        <div class="accordion-body p-4">
                            <div class="col-md-12">
                                <div class="input-edits">
                                    <div class="form-group">
                                        {{ Form::label('file_name', __('File Name'),['class'=>'col-form-label']) }}
                                        {{ Form::text('file_name', null, array('class' => 'form-control file_name','id'=>'file_name','multiple'=>'','required'=>'required')) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer pb-0">
    <button type="button" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">{{__('Cancel')}}</button>
    <button class="btn btn-primary btn-badge mx-1" type="button" disabled="" id="backing_up" style="display: none;">
        <span class="spinner-border spinner-border-sm" role="status"></span>
        {{__('Backing Up...')}}
    </button>
    <button type="button" id="submitForm" value="{{ __('Backup') }}" class="btn btn-primary btn-badge mx-1"> {{ __('Backup') }} </button>
    <div class="row col-md-12 text-end">
        <span class="refresh_note" id="refresh_note" style="display: none;">{{ __('Note: Do not refresh the page until the backup is complete.') }}</span>
    </div>
</div>
