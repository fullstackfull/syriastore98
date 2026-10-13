<?php

namespace Workdo\LynomiaChat\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a platform operator approving a pending claim.
 *
 * The code is required and is compared against the claim's stored hash in AccountLinker.
 * There is no "approve without a code" path: an approval that does not demonstrate the
 * merchant presented the code issued for THIS claim is not verification, it is a rubber stamp.
 */
class ApproveLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verification_code' => ['required', 'string', 'max:64'],
        ];
    }
}
