{{ Form::open(['route' => 'roles.store', 'method' => 'post']) }}

<div class="row">
    <div class="form-group">
        {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}
        <div class="form-icon-user">
            {{ Form::text('name', null, ['class' => 'form-control', 'placeholder' => __('Enter Role Name'), 'required' => 'required']) }}
        </div>

        @error('name')
            <span class="invalid-name" role="alert">
                <strong class="text-danger">{{ $message }}</strong>
            </span>
        @enderror
    </div>

    <div class="form-group">
        @if (!empty($permissions))
            <div class="col-sm-12 col-md-10 col-xxl-12 col-md-12">
                <div class="p-3 card mb-2">
                    <ul class="nav nav-pills nav-fill gap-2" id="pills-tab" role="tablist">
                        @foreach ($modules as $module)
@if ((module_is_active($module) || $module == 'General' || $module == 'Finance') && count(get_permission_by_module($module)) > 0)                                <li class="nav-item" role="presentation">
                                    <button class="nav-link text-capitalize {{ $loop->index == 0 ? 'active' : '' }}"
                                        id="pills-{{ strtolower($module) }}-tab" data-bs-toggle="pill"
                                        data-bs-target="#pills-{{ strtolower($module) }}"
                                        type="button">{{ Module_Alias_Name($module) }}</button>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
                <div class="px-0 card-body">
                    <div class="tab-content" id="pills-tabContent">
                        @foreach ($modules as $module)
@if ((module_is_active($module) || $module == 'General' || $module == 'Finance') && count(get_permission_by_module($module)) > 0)                                <div class="tab-pane text-capitalize fade show {{ $loop->index == 0 ? 'active' : '' }}"
                                    id="pills-{{ strtolower($module) }}" role="tabpanel"
                                    aria-labelledby="pills-{{ strtolower($module) }}-tab">
                                    <input type="checkbox" class="form-check-input pointer"
                                        name="checkall-{{ strtolower($module) }}"
                                        id="checkall-{{ strtolower($module) }}"
                                        onclick="Checkall('{{ strtolower($module) }}')">
                                    <small class="text-muted mx-2">
                                        {{ Form::label('checkall-' . strtolower($module), 'Assign ' . Module_Alias_Name($module) . ' Permission to Roles', ['class' => 'form-check-label pointer']) }}
                                    </small>
                                    <div class="table-responsive role-data-table">
                                    <table class="table table-striped mb-0  mt-3" id="dataTable-1">
                                        <thead>
                                            <tr>
                                                <th>

                                                </th>
                                                <th>{{ __('Module') }} </th>
                                                <th>{{ __('Permissions') }} </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {{-- P2-05-5. A first raw-PHP block here recomputed `$permissions`,
                                                 `$m_permissions` and `$module_list` and was IMMEDIATELY overwritten
                                                 by the block below, which recomputes all three and additionally
                                                 applies the P1-SEC-02 `$grantable` filter. Dead code that looked
                                                 load-bearing, and dangerous to leave beside a security filter: a
                                                 later edit could easily have deleted the FILTERED copy and kept the
                                                 unfiltered one.

                                                 NOTE FOR WHOEVER EDITS THIS COMMENT: do not write the raw-PHP
                                                 directive name here literally. Blade extracts those blocks BEFORE it
                                                 strips comments, so the word inside a comment opens a real PHP block
                                                 and swallows markup up to the next closing directive. Writing it
                                                 cost a 500 on this exact screen. --}}
                                            @php
                                                $permissions = get_permission_by_module($module);
                                                $m_permissions = array_column($permissions->toArray(), 'name');
                                                $module_list = [];
                                                foreach ($m_permissions as $key => $value) {
                                                    // P1-SEC-02: see the note below. Fails
                                                    // closed when $grantable is absent.
                                                    if (!in_array($value, $grantable ?? [], true)) {
                                                        continue;
                                                    }
                                                    $parts = explode(' ', $value);
                                                    array_push($module_list, strtolower($parts[1] ?? $parts[0]));
                                                }
                                                $module_list = array_unique($module_list);
                                            @endphp

                                            @foreach ($module_list as $mkey => $list)
                                                <tr>
                                                    <td><input type="checkbox" class="form-check-input ischeck pointer"
                                                            onclick="CheckModule('module_checkbox_{{ $mkey }}_{{ $list }}_{{ strtolower($module) }}')"
                                                            id="module_checkbox_{{ $mkey }}_{{ $list }}_{{ strtolower($module) }}">
                                                    </td>
                                                    {{-- P2-05-5 / F134: `strtolower($module)`, matching the checkbox's own id
                                                         three lines above. Without it the `for` read
                                                         `module_checkbox_0_order_General` while the input was
                                                         `..._general`, and HTML id matching is case-sensitive -- so the
                                                         label named nothing. Measured in the running modal: 13 of 59
                                                         labels broken. `roles/edit.blade.php:77` ALREADY had the
                                                         strtolower(); only this copy drifted. --}}
                                                    <td>{{ Form::label('module_checkbox_' . $mkey . '_' . $list . '_' . strtolower($module), $list, ['class' => 'form-check-label pointer']) }}
                                                    </td>
                                                    <td
                                                        class="module_checkbox_{{ $mkey }}_{{ $list }}_{{ strtolower($module) }}">
                                                        <div class="row">
                                                            @foreach ($permissions as $key => $prermission)
                                                                @php
                                                                    $parts = explode(' ', $prermission->name);
                                                                    $check = strtolower($parts[1] ?? $parts[0]);
                                                                    $name = str_replace($check, '', $prermission->name);
                                                                @endphp
{{-- P1-SEC-02: membership in the set the CONTROLLER computed from `RolePolicy::grantableNames`,
    which shares its per-name rule with `grantPermission()` -- so the form and the write path
    cannot disagree. NOT a Gate call per permission: that shape measured 601 GateEvaluated events
    and 1,742 queries for one render of this modal on a 200-permission table, against 1 and 14
    before the change. FAILS CLOSED: `$grantable ?? []` hides every checkbox if a caller forgets
    to pass it, because an empty form is recoverable and an unfiltered one is an escalation. --}}
                                                                @if (!in_array($prermission->name, $grantable ?? [], true))
                                                                @continue
                                                                @endif
                                                                @if ($list == $check)
                                                                    <div
                                                                        class="col-xl-4 col-ld-6 col-md-12 col-12  form-check">
                                                                        {{ Form::checkbox('permissions[]', $prermission->id, false, [
                                                                            'class' => 'form-check-input checkbox-' . strtolower($module),
                                                                            'id' => 'permission_' . $key . '_' . $prermission->id,
                                                                            'data-module' => 'module_checkbox_' . $mkey . '_' . $list . '_' . strtolower($module),
                                                                            'onclick' =>
                                                                                "CheckPermission('permission_" .
                                                                                $key .
                                                                                '_' .
                                                                                $prermission->id .
                                                                                "', 'module_checkbox_" .
                                                                                $mkey .
                                                                                '_' .
                                                                                $list .
                                                                                '_' .
                                                                                strtolower($module) .
                                                                                "')",
                                                                        ]) }}
                                                                        {{ Form::label('permission_' . $key . '_' . $prermission->id, $name, ['class' => 'form-check-label']) }}
                                                                    </div>
                                                                @endif
                                                            @endforeach
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>


        @endif
    </div>
</div>

<div class="modal-footer pb-0">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Create') }}" class="btn btn-primary btn-badge mx-1">
</div>
{{ Form::close() }}