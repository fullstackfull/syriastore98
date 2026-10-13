<?php

namespace Workdo\LynomiaChat\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for connecting an existing Lynomia workspace.
 *
 * ONE FIELD, AND IT NAMES NOTHING. The only input is a connection code the merchant generated
 * inside the Lynomia workspace they are connecting. Deliberately absent: any email, and -- no
 * longer accepted since the linking flow was completed -- any account id.
 *
 * Both of those absences are the same decision. An email is the input that turns linking into
 * the account-takeover primitive AccountLinker exists to avoid. An account id is the input
 * that makes the CLAIMANT the source of truth about who they are: it costs nothing to type a
 * number, so a flow that accepts one can only ever be as strong as whatever human checks it
 * afterwards. A code cannot be guessed and cannot be obtained without signing in to the
 * workspace as an administrator, so the proof arrives with the request instead of being
 * promised by it.
 *
 * The shape is pinned here rather than trusted to the client: 16 characters from a 32-symbol
 * alphabet with no look-alikes, optionally dashed into groups of four as the merchant is shown
 * it. Anything else is rejected at the boundary and never reaches the network -- a merchant
 * who pastes the wrong thing gets a form error, not a round trip and a generic failure.
 */
class LinkExistingAccountRequest extends FormRequest
{
    /**
     * The alphabet Lynomia mints codes from: A-Z and 2-9, with 0/O/1/I left out because
     * merchants read these aloud to support. Mirrors Integrations::AccountLinkCode::ALPHABET.
     */
    private const CODE_PATTERN = '/^[A-HJ-NP-Z2-9]{16}$/';

    public function authorize(): bool
    {
        // Authorization is the route's: auth + verified + PlanModuleCheck + the permission
        // + EnsureStoreOwnsLink. A FormRequest is the wrong place to re-answer it.
        return true;
    }

    /**
     * Normalise before validating, because the merchant copies this by hand.
     *
     * Lynomia shows the code grouped as XXXX-XXXX-XXXX-XXXX and accepts any case on
     * redemption, so rejecting a pasted dash or a lowercase paste would be a rule invented
     * here that the system does not actually have.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('connection_code');

        if (is_string($code)) {
            $this->merge([
                'connection_code' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'connection_code' => ['required', 'string', 'regex:' . self::CODE_PATTERN],
        ];
    }

    public function messages(): array
    {
        return [
            'connection_code.required' => __('Enter the connection code from your Lynomia workspace.'),
            'connection_code.regex' => __('That does not look like a Lynomia connection code. It is 16 characters long.'),
        ];
    }
}
