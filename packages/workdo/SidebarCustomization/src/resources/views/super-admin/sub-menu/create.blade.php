
{{ Form::open(['route' => 'submenus.store', 'method' => 'post', 'id' => 'choice_form', 'enctype' => 'multipart/form-data']) }}
<div class="modal-body">
    <div class="row">
        <div class="col-md-12">
            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-6">
                        {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}
                        {{ Form::text('name', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Enter name')]) }}
                    </div>
                    <div class="form-group col-md-6">
                        {{ Form::label('linktype', __('Link Type'), ['class' => 'form-label']) }}
                        <div class="col-md-12">
                            @foreach ($links as $key => $link)
                                <div class="form-check form-check-inline">
                                    <input type="radio" id="{{ $key }}" value="{{ $key }}"
                                        name="link_type" class="form-check-input" checked>
                                    <label class="form-check-label"
                                        for="{{ $key }}">{{ $link }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="form-group col-md-12" id="links">
                        {{ Form::label('link', __('Link'), ['class' => 'form-label']) }}
                        <div class="input-group mb-3" id="internal-link-section">
                            <span class="input-group-text" id="basic-addon3">{{ env('APP_URL') }}</span>
                            <input type="text" class="form-control" id="internal-link" name="links"
                                aria-describedby="basic-addon3" placeholder="{{ __('Enter site link') }}">
                        </div>
                        <div class="input-group mb-3" id="external-link-section">
                            <select class="form-control" name="link[href][]" style="max-width: 100px;">
                                @foreach ($hrefs as $key => $href)
                                    <option value="{{ $key }}">
                                        {{ $href }}</option>
                                @endforeach
                            </select>
                            <input type="text" class="form-control" id="external-link" name="link[type][]"
                                placeholder="{{ __('Enter Out-site link') }}">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="form-group col-md-6" id="position">
                        {{ Form::label('position', __('Position'), ['class' => 'form-label']) }}
                        {{ Form::number('position', null, ['class' => 'form-control', 'placeholder' => __('Enter position')]) }}
                    </div>
                    @php
                        function renderModules($modules, $level = 0) {
                            foreach ($modules as $module) {
                                echo '<option value="' . $module->name . '" data-menu-type="mainmenu" style="padding-left:' . ($level * 20) . 'px;">' . $module->title . '</option>';
                                if (!empty($module->children)) {
                                    renderModules($module->children, $level + 1);
                                }
                            }
                        }
                    @endphp
                    <div class="form-group col-md-6" id="modules">
                        {{ Form::label('module', __('Custom Main Menu'), ['class' => 'form-label']) }}
                        <select class="form-control" name="modules" id="moduleDropdown">
                            <option value="">{{ __('Select Module') }}</option>
                                @foreach ($modules as $module)
                                    @php renderModules([$module]); @endphp
                                @endforeach
                        </select>
                        <span id="moduleMessage"
                            style="display: none; color: red;">{{ __('Selected parent module would be change with hash link') }}</span>
                    </div>
                </div>
                <div class="row">
                    <div class="form-group col-md-12">
                        {{ Form::label('showwindow', __('Show In Windows'), ['class' => 'form-label']) }}
                        <div class="col-md-12">
                            @foreach ($show_window as $key => $window)
                                <div class="form-check form-check-inline">
                                    <input type="radio" id="{{ $key }}" value="{{ $key }}"
                                        name="window" class="form-check-input" checked>
                                    <label class="form-check-label" for="{{ $key }}">{{ $window }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                    <div class="form-group col-md-5 mb-3">
                        {{ Form::label('is_enable', __('Enable Menu'), ['class' => 'form-label']) }}
                        <div class="form-check form-switch custom-switch-v1 float-end">
                            <input type="checkbox" name="is_enable" class="form-check-input input-primary pointer" id="is_enable">
                            <label class="form-check-label" for="is_enable"></label>
                        </div>
                    </div>
            </div>
            <input type="hidden" name="parent_menu" value="{{ $sideMenu->name }}">
            <div class="modal-footer">
                <button type="button" class="btn  btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                {{ Form::submit(__('Create'), ['class' => 'btn  btn-primary']) }}
            </div>
        </div>
    </div>
</div>

{{ Form::close() }}

<script type="text/javascript">
    $('input[type="radio"]').on('change', function(e) {
        var selectedLinkType = $('input[name="link_type"]:checked').val();
        var selectedMenuType = $('input[name="menu_type"]:checked').val();

        $('#internal-link-section').hide();
        $('#external-link-section').hide();
        $('#modules').hide();

        if (selectedLinkType === 'internal') {
            $('#internal-link-section').show();
            $('#links').show();
            $('#internal-link').prop('required', true);
            $('#external-link').prop('required', false);
        } else if (selectedLinkType === 'external') {
            $('#external-link-section').show();
            $('#links').show();
            $('#external-link').prop('required', true);
            $('#internal-link').prop('required', false);
        } else {
            $('#links').hide();
            $('#internal-link').prop('required', false);
            $('#external-link').prop('required', false);
        }

        $('#modules').show();
        $('#position').show();
    });

    $('input[type="radio"]:checked').trigger('change');
</script>
<script>
    $(document).ready(function() {
        $('#moduleDropdown').change(function() {
            var selectedOption = $(this).find('option:selected');
            var menuType = selectedOption.data('menu-type');

            $('#moduleMessage').hide();
            
        });
    });
</script>
