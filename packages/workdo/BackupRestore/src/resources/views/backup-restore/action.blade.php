<span class="d-flex gap-1 justify-content-end">
@if (check_file('storage/uploads/Files_Backup') || check_file('storage/uploads/Database_Backup'))
    @if (check_file('storage/uploads/Files_Backup' . '/' . $backup->file_name) ||
            check_file('storage/uploads/Database_Backup' . '/' . $backup->file_name))
        @if ($backup->type == 'File')
            <a class="btn btn-sm btn-badge btn-primary"
                href="{{ get_file('storage/uploads/Files_Backup') . '/' . $backup->file_name }}" download="" data-bs-toggle="tooltip" title="{{ __('Download') }}">
                <i class="ti ti-download"></i>
            </a>
        @endif
        @if ($backup->type == 'Database')
            <a class="btn btn-sm btn-badge btn-primary"
                href="{{ get_file('storage/uploads/Database_Backup') . '/' . $backup->file_name }}" download="" data-bs-toggle="tooltip" title="{{ __('Download') }}">
                <i class="ti ti-download"></i>
            </a>
        @endif
    @endif
@endif
@permission('backup&restore delete')
    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['backuprestore.destroy', $backup->id],
        'class' => 'd-inline',
    ]) !!}
    <button type="button" class="btn btn-sm btn-badge btn-danger show_confirm"  data-confirm="{{ __('Are You Sure?') }}"
    data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  data-bs-toggle="tooltip" title="{{ __('Delete') }}">
        <i class="ti ti-trash"></i>
    </button>
@endpermission
</span>