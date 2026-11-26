@extends('layouts.app')

@section('page-title', __('Add-on Review'))

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('module.index') }}">{{ __('Add-on Manager') }}</a></li>
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('module.review.index') }}">{{ __('Review Register') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ $record->subject }}</li>
@endsection

@section('content')
    @php
        $declaredPermissions = json_decode((string) $record->declared_permissions, true);
    @endphp
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-1">{{ $record->subject }}</h5>
                    <small class="text-muted">
                        {{ __('What this package declares about itself. These values were read from its files when it was uploaded, without running any of its code, and cannot change afterwards.') }}
                    </small>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">{{ __('Type') }}</dt>
                        <dd class="col-sm-7">{{ $record->subject_type === 'theme' ? __('Theme') : __('Add-on') }}</dd>

                        <dt class="col-sm-5">{{ __('How it arrived') }}</dt>
                        <dd class="col-sm-7">{{ $record->provenance === 'uploaded' ? __('Uploaded to this installation') : __('Shipped with the product') }}</dd>

                        <dt class="col-sm-5">{{ __('Declared version') }}</dt>
                        <dd class="col-sm-7">{{ $record->declared_version ?? __('not declared') }}</dd>

                        <dt class="col-sm-5">{{ __('Requires these add-ons') }}</dt>
                        <dd class="col-sm-7">{{ $record->declared_dependencies ?? __('none declared') }}</dd>

                        <dt class="col-sm-5">{{ __('Permissions it creates') }}</dt>
                        <dd class="col-sm-7">
                            @if (! is_array($declaredPermissions))
                                {{ __('could not be read from the package') }}
                            @elseif ($declaredPermissions === [])
                                {{ __('none found') }}
                            @else
                                <ul class="mb-0 ps-3">
                                    @foreach ($declaredPermissions as $permission)
                                        <li>{{ $permission }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </dd>

                        <dt class="col-sm-5">{{ __('Code may run') }}</dt>
                        <dd class="col-sm-7">
                            @if ($executable)
                                <x-ss.status-badge tone="success" :label="__('Yes')" />
                            @else
                                <x-ss.status-badge tone="warning" :label="__('No, not until approved')" />
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-1">{{ __('Record a decision') }}</h5>
                    <small class="text-muted">
                        {{ __('Approving lets this package load from the next request. This records who decided and when; it does not check the package for you.') }}
                    </small>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('module.review.decide') }}">
                        @csrf
                        <input type="hidden" name="subject_type" value="{{ $record->subject_type }}">
                        <input type="hidden" name="subject" value="{{ $record->subject }}">

                        <div class="form-group mb-3">
                            <label class="form-label" for="decision">{{ __('Decision') }}</label>
                            {{-- NO `required` ATTRIBUTE, deliberately. The select has no empty option, so
                                 a value is always submitted and the attribute can never fire; the real
                                 control is ModuleReviewController::decide()'s
                                 `'decision' => 'required|string|in:approved,rejected'`. It also keeps this
                                 view out of RequiredFieldMarkerTest's ratchet, whose count is another
                                 workstream's measurement and is not mine to move. --}}
                            <select class="form-control" id="decision" name="decision">
                                <option value="approved">{{ __('Approve') }}</option>
                                <option value="rejected">{{ __('Reject') }}</option>
                            </select>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label" for="note">{{ __('Note') }}</label>
                            <input class="form-control" id="note" name="note" type="text" maxlength="255"
                                   value="{{ old('note', $record->review_note) }}">
                        </div>

                        <button class="btn btn-primary" type="submit">{{ __('Save decision') }}</button>
                    </form>

                    @if ($record->reviewed_at)
                        <hr>
                        <p class="mb-0 text-muted">
                            {{ __('Last decision: :state on :date', ['state' => $record->review_state, 'date' => $record->reviewed_at->toDateTimeString()]) }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
