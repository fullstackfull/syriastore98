<?php

namespace Workdo\LynomiaChat\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for an existing-account claim.
 *
 * Only the Lynomia account id is accepted. Notably ABSENT: any email field. The claim
 * deliberately cannot be made by naming an address, because an email is exactly the input
 * that would turn this into the takeover primitive AccountLinker exists to avoid.
 */
class LinkExistingAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is the route's (auth + verified + PlanModuleCheck + EnsureStoreOwnsLink).
        return true;
    }

    public function rules(): array
    {
        return [
            'lynomia_account_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'lynomia_account_id.required' => __('Enter the Lynomia account ID you want to connect.'),
            'lynomia_account_id.integer' => __('The Lynomia account ID must be a number.'),
        ];
    }
}
