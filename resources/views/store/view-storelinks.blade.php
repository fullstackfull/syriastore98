<div class="table-responsive ecom-data-table">
<table class="table dataTable">
    <thead>
        <tr>
            <th>{{ __('Store Name') }}</th>
            <th>{{ __('Active/Deactive') }}</th>
            <th class="text-end">{{ __('Store Links') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($stores as $store)
            <tr>
                <td>{{ $store->name }}</td>
                <td>
                    <div class="form-check form-switch">
                        <input type="checkbox" data-id="{{$store->id}}"  class="form-check-input active-store-index" name="is_popular"
                        id="active_store_{{$store->id}}" value="{{$store->is_active}}" {{ $store->is_active ? 'checked' : '' }}>
                        <label class="form-check-label" for="active_store_{{$store->id}}"></label>
                    </div>
                </td>
                <td class="text-end">
                    <input type="text" value="{{ route('landing_page', $store->slug) }}" id="myInput_{{ $store->slug }}"
                        class="form-control d-inline-block theme-link" aria-label="Recipient's username"
                        aria-describedby="button-addon2" readonly>
                    <button class="btn btn-outline-primary btn-badge" type="button"
                        onclick="myFunction('myInput_{{ $store->slug }}')" id="button-addon2"><i
                            class="far fa-copy"></i>
                        {{ __('Store Link') }}</button>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
</div>
