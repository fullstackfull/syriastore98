<?php

namespace App\Support\Filesystem;

use RuntimeException;

/**
 * A caller-named path that could not be proved to be what it claimed, with the reason.
 *
 * A TYPED EXCEPTION RATHER THAN A FALSY RETURN, because the requirement these doors answer to is
 * that failures are EXPLICIT and that nothing partial happens. `ModuleController::remove()` used to
 * read `$module = Module::find($name); if ($module) { ...destroy... }`, and `find()` returns a
 * populated object for any string at all -- so a falsy-return contract is precisely the shape that
 * failed there. A caller cannot accidentally ignore a throw.
 *
 * The message names the reason for a log and for tests. Callers do not render it: a refusal must not
 * tell the caller which of the checks they tripped.
 */
final class PathNotConfined extends RuntimeException
{
}
