@if(count($fields_data) > 0)
    <div class="bg-white rounded-xl shadow-md">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h4 class="text-lg font-semibold text-gray-800">{{ __('Additional Fields') }}</h4>
        </div>
        <div class="px-6 py-4">
            <div class="max-h-[200px] overflow-x-auto px-3 scrollbar-thin scrollbar-thumb-gray-400 scrollbar-track-gray-200">
                <table class="min-w-full text-sm text-left text-gray-700">
                    <thead class="bg-gray-100 text-gray-700 font-semibold uppercase text-xs">
                        <tr>
                            <th class="px-4 py-2">{{ __('Name') }}</th>
                            <th class="px-4 py-2">{{ __('Value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fields_data as $data)
                            <tr class="border-b hover:bg-gray-50">
                                <td class="px-4 py-2 font-medium text-gray-800">{{ $data->field_name }}</td>
                                <td class="px-4 py-2">{{ !empty($data->value) ? $data->value : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
