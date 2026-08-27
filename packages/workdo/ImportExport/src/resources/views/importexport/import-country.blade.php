{{ Form::open(array('route' => array('country.import'),'method'=>'post', 'enctype' => "multipart/form-data")) }}
    <div>
        <div class="row">
            <div class="col-md-12 mb-6">
                {{Form::label('file',__('Download Sample Country CSV File'),['class'=>'form-label'])}}
                <a href="{{ asset('packages/workdo/ImportExport/src/resources/assets/sample/sample-country.csv')}}" class="btn-badge btn btn-sm btn-primary btn-icon" target="_blank">
                    <input id="file" style="display: none;">
                    <i class="ti ti-download"></i>
                </a>
            </div>
            <div class="col-md-12">
                <div class="choose-file form-group">
                    <label for="csv_file" class="col-form-label">
                        <div>{{__('Select CSV File')}}</div>
                        <input type="file" class="form-control" name="csv_file" id="csv_file" data-filename="upload_file" accept=".csv" required>
                    </label>
                    <p class="upload_file"></p>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Upload')}}" class="btn btn-primary btn-badge mx-1">
    </div>
{{ Form::close() }}
