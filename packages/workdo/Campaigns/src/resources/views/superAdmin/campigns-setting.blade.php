{{-- show in landingpage --}}
<a href="{{ route('campaigns.system.setup.create') }}" class="list-group-item list-group-item-action border-0 {{ (Request::route()->getName() == 'campaigns.system.setup.create') ? ' active' : '' }}">{{ __('Campagins') }} <div class="float-end"><i class="ti ti-chevron-right"></i></div></a>
