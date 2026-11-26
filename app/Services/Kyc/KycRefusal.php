<?php

namespace App\Services\Kyc;

use RuntimeException;

/**
 * A KYC document operation that was refused, carrying the reason as a MACHINE WORD.
 *
 * TWO MESSAGES, DELIBERATELY. `$reason` is a short word for the access-log row and for tests
 * (`no_kinds`, `unknown_kind`, `file`, `write_failed`, `missing`, `not_confined`, `remote_disk`,
 * `public_root`); `getMessage()` is the sentence shown to the person. The reason NEVER reaches the
 * caller, for the same rule `PathNotConfined` states: *"a refusal must not tell the caller which of
 * the checks they tripped"*.
 *
 * A TYPED THROW RATHER THAN A FALSY RETURN, the posture `PathNotConfined`'s docblock argues for: a
 * caller cannot accidentally ignore a throw, and `ModuleController::remove()` is the measured case
 * of a falsy contract being ignored.
 */
final class KycRefusal extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
