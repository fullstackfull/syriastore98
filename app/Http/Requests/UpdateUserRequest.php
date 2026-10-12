<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SEC-F4 -- mass-assignment privilege escalation on UserController::update.
 *
 * The controller did `$input = $request->all(); $user->fill($input)->save();`,
 * so any field in `User::$fillable` was writable by the caller. That includes
 * `type`, `password`, `created_by`, `plan_id`, `storage_limit` and
 * `email_verified_at`.
 *
 * The password limb is the one Phase 0 missed and it is the worst: User.php
 * casts `'password' => 'hashed'`, so `fill(['password' => 'x'])` stores a
 * WORKING credential for the target account.
 *
 * This request allow-lists exactly the three inputs resources/views/users/
 * edit.blade.php actually posts -- `name`, `email` and `role` -- which were
 * enumerated field by field from that form, so switching the controller from
 * all() to validated() cannot silently drop a legitimate input.
 *
 * `type` is NOT accepted from the client. The controller keeps deriving it
 * server-side from the SCOPED role, which is the correct design; the bug was
 * the unscoped role lookup, not the derivation.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Authorization is performed by UserPolicy::update via an explicit
     * $this->authorize() call in the controller, so that the control is visible
     * at the call site and does not depend on this class alone.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var User|null $target */
        $target = $this->route('user');
        $targetId = $target instanceof User ? $target->id : $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->where(function ($query) use ($targetId) {
                    return $query->whereNotIn('id', [$targetId])
                        ->where('created_by', \Auth::user()->creatorId())
                        ->where('current_store', getCurrentStore());
                }),
            ],
            // The role id is validated for existence here and scoped to the
            // caller's own tenant in the controller.
            'role' => ['nullable', 'integer', 'exists:roles,id'],
        ];
    }

    /**
     * The attributes that may be written to the model.
     *
     * Deliberately excludes `role`, which is not a users column, and every
     * privileged column listed in the class docblock.
     */
    public function userAttributes(): array
    {
        return $this->safe()->only(['name', 'email']);
    }
}
