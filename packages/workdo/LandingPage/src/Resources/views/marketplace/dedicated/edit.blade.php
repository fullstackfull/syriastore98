{{Form::model(null, array('route' => array('dedicated_theme_section_update',[$slug , $key]), 'method' => 'POST','enctype' => "multipart/form-data")) }}
<div class="">
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('dedicated_theme_section_heading', __('Heading'), ['class' => 'form-label']) }}
                {{ Form::text('dedicated_theme_section_heading',$dedicated_theme['dedicated_theme_section_heading'], ['class' => 'form-control ', 'placeholder' => __('Enter Heading'),'required'=>'required', 'id' => 'dedicated_theme_section_heading']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('dedicated_theme_section_description', __('Description'), ['class' => 'form-label']) }}
                {{ Form::textarea('dedicated_theme_section_description', $dedicated_theme['dedicated_theme_section_description'], ['class' => 'summernote form-control', 'placeholder' => __('Enter Description'), 'id'=>'dedicated_theme_section_description','required'=>'required']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('dedicated_theme_section_image', __('Image'), ['class' => 'form-label']) }}
                <div class="logo-content mt-4 pb-5">
                    <img id="image" src="{{ get_file($dedicated_theme['dedicated_theme_section_image'])}}"
                        class="w-50 logo"  style="filter: drop-shadow(2px 3px 7px #011C4B);">
                </div>
                <input type="file" name="dedicated_theme_section_image" id="dedicated_theme_section_image" class="form-control">
            </div>
        </div>
        <div class="border" >
            <div class="row pt-3">
                <div class="col"><h5>{{ __("Section Cards") }}</h5></div>
                <div class="col-auto text-end">
                    <button id="add-cards-details"
                        class="btn btn-sm btn-primary btn-icon"
                         title="{{ __('Add More Cards') }}">
                        <i class="ti ti-plus"></i>
                    </button>
                </div>
            </div>
            @if (isset($dedicated_theme['dedicated_theme_section_cards']) )
                @foreach (( $dedicated_theme['dedicated_theme_section_cards']) as $key => $card)
                    <div id="{{ 'add-cards'.$key }}" class="border-bottom row py-2">
                        <div class="col-md-10">
                            <div class="form-group">
                                {{ Form::label('title', __('Title'), ['class' => 'form-label']) }}
                                {{ Form::text('dedicated_theme_section_cards['.$key.'][title]',$card['title'], ['class' => 'form-control', 'placeholder' => __('Enter Title'), 'id' => 'title']) }}
                            </div>
                            <div class="form-group">
                                {{ Form::label('description', __('Description'), ['class' => 'form-label']) }}
                                {{ Form::text('dedicated_theme_section_cards['.$key.'][description]',$card['description'], ['class' => 'form-control ', 'placeholder' => __('Enter Description'), 'id' => 'description']) }}
                            </div>
                        </div>
                        <div class="col-md-2 d-flex text-center align-items-center">
                            <a href="#" id="{{ 'delete-card'.$key  }}" class="card-delete btn btn-danger btn-sm align-items-center bs-pass-para" title="{{__('Delete')}}" data-title="{{__('Delete')}}" data-original-title="{{__('Delete')}}">
                                <i class="ti ti-trash"></i>
                            </a>
                        </div>
                    </div>
                @endforeach
            @else
                <div id="add-cards1" class="border-bottom row py-2">
                    <div class="col-md-10">
                        <div class="form-group">
                            {{ Form::label('title', __('Title'), ['class' => 'form-label']) }}
                            {{ Form::text('dedicated_theme_section_cards[1][title]', null, ['class' => 'form-control', 'placeholder' => __('Enter Title'), 'id' => 'title']) }}
                        </div>
                        <div class="form-group">
                            {{ Form::label('description', __('Description'), ['class' => 'form-label']) }}
                            {{ Form::text('dedicated_theme_section_cards[1][description]',null, ['class' => 'form-control ', 'placeholder' => __('Enter Description'), 'id' => 'description']) }}
                        </div>
                    </div>
                    <div class="col-md-2 d-flex text-center align-items-center">
                        <a href="#" id="delete-card1" class="card-delete btn btn-danger btn-badge btn-sm align-items-center bg-danger" title="{{__('Delete')}}" data-title="{{__('Delete')}}" data-original-title="{{__('Delete')}}">
                            <i class="ti ti-trash"></i>
                        </a>
                    </div>
                </div>
            @endif
        </div>

    </div>
</div>
<div class="modal-footer pb-0">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
</div>
{{ Form::close() }}

@push('css')
    <link href="{{  asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.css')  }}" rel="stylesheet">
@endpush

@push('scripts')
    <script src="{{ asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.js') }}"></script>
@endpush
<script src="{{ asset('packages/workdo/LandingPage/src/Resources/assets/js/repeater.js') }}"></script>
 <script src="{{ asset('packages/workdo/LandingPage/src/Resources/assets/js/editorplaceholder.js') }}"></script>

<script>
    $("#add-cards-details").click(function(e){
        e.preventDefault()

    // get the last DIV which ID starts with ^= "another-participant"
    var $div = $('div[id^="add-cards"]:last');

    // Read the Number from that DIV's ID (i.e: 1 from "another-participant1")
    // And increment that number by 1
    var num = parseInt( $div.prop("id").match(/\d+/g), 10 ) +1;

    // Clone it and assign the new ID (i.e: from num 4 to ID "another-participant4")
    var $klon = $div.clone().prop('id', 'add-cards'+num );

    $klon.find('a').each(function() {
        this.id = "delete-card"+num;
    });

    // for each of the inputs inside the dive, clear it's value and
    // increment the number in the 'name' attribute by 1
    $klon.find('input').each(function() {
    this.value= "";
    let name_number = this.name.match(/\d+/);
    name_number++;
    this.name = this.name.replace(/\[[0-9]\]+/, '['+name_number+']')
    });
    // Finally insert $klon after the last div
    $div.after( $klon );

    });


    $(document).on('click', '.card-delete', function(e) {
        e.preventDefault()

        var id = $(this).attr('id');
        var num = parseInt( id.match(/\d+/g), 10 );
        var card = document.getElementById("add-cards"+num);
        if(num != 1){
            card.remove();
        }
    });
</script>
