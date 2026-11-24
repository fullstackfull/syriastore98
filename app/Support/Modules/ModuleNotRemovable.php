<?php

namespace App\Support\Modules;

use RuntimeException;

/**
 * A module-removal request that must not proceed, with the reason it was refused.
 *
 * A TYPED EXCEPTION RATHER THAN A FALSY RETURN, because the requirement is that failures are
 * EXPLICIT and that nothing partial happens. `ModuleController::remove()` previously read
 * `$module = Module::find($name); if ($module) { ...destroy... }`, and `find()` returns a populated
 * object for any string at all -- so a falsy-return contract is exactly the shape that failed. A
 * caller cannot accidentally ignore a throw.
 *
 * The message names the reason for the operator-facing log and for tests. It is NOT rendered to the
 * browser: the handler keeps its existing generic message, so a refusal does not tell a caller
 * which of the checks they tripped.
 */
final class ModuleNotRemovable extends RuntimeException
{
}
