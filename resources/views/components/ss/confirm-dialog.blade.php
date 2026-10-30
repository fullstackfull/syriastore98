@props(['id' => 'ss-confirm-dialog'])

{{--
    F184 / D-28c — THE CONFIRMATION SHELL FOR A DESTRUCTIVE ACTION.

    The owner's condition: a variant deletion must be preceded by a visible explicit confirmation
    that names what is about to go, says the loss is permanent, and offers a clear Cancel and
    Confirm — and the trash click itself is not that confirmation.

    WHY A SHELL FILLED BY JS RATHER THAN A SERVER-RENDERED DIALOG PER VARIANT. What is being
    deleted is only known at the click: on the trash path it is the variant whose button was
    pressed, and on the save path it is whatever set the server just refused, which the page
    learns from the refusal payload. One shell per page, filled at open, is the only shape that
    names the right record in both cases.

    SO EVERY STRING THAT IS FIXED IS RENDERED HERE, BY `__()`, and only the record names come from
    JavaScript. `public/js/store-syria-ds.js` has no translator — the same reason
    `StoreSyriaDS.applyFormErrors()` takes its labels as arguments — so a literal in the script
    would ship English to an Arabic merchant. That is the F70 lesson, and this is the shape that
    avoids it.

    `Are you sure?` IS DELIBERATELY NOT USED. `resources/lang/ar.json` renders it
    'هل أنتِ متأكدة؟' — feminine singular — so on a destructive dialog it would read as a
    grammatical error to most merchants. The titles here are pronoun-free and are supplied by the
    caller for the same reason.

    The classes are the design system's own `.ss-dialog` family
    (`public/css/store-syria-ds.css:1332-1410`), which carries the RTL transform flip, the
    dark-mode contrast restatement and the phone `column-reverse` footer. Solid danger on the
    confirming action is what the DS reserves it for, in as many words.
--}}

<div class="ss-dialog-backdrop" data-ss-dialog-backdrop="{{ $id }}" hidden></div>

<div class="ss-dialog"
     id="{{ $id }}"
     role="dialog"
     aria-modal="true"
     aria-labelledby="{{ $id }}-title"
     aria-describedby="{{ $id }}-body"
     hidden>
    <div class="ss-dialog__header">
        {{-- `tabindex="-1"` so the title can take focus when there is no Cancel button to take
             it, which is the fallback `openDialog()` uses. --}}
        <h2 class="ss-dialog__title" id="{{ $id }}-title" tabindex="-1" data-ss-dialog-title></h2>

        <button type="button"
                class="ss-dialog__close"
                data-ss-dialog-close="{{ $id }}"
                aria-label="{{ __('Close') }}">
            <i class="ti ti-x" aria-hidden="true"></i>
        </button>
    </div>

    <div class="ss-dialog__body" id="{{ $id }}-body" data-ss-dialog-body></div>

    <div class="ss-dialog__footer">
        {{-- CANCEL FIRST IN THE DOM, which is what `openDialog()` focuses. A dialog that opens
             with the destroying button focused turns an un-read Enter into the deletion. On a
             phone the DS stacks this footer `column-reverse`, so Cancel still reads below
             Confirm while remaining the first focusable. --}}
        <button type="button" class="ss-btn ss-btn--ghost" data-ss-dialog-cancel>{{ __('Cancel') }}</button>
        <button type="button" class="ss-btn ss-btn--danger" data-ss-dialog-confirm>{{ __('Delete permanently') }}</button>
    </div>
</div>
