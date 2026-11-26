<?php

namespace App\Support\Filesystem;

use App\Http\Requests\InstallAddonRequest;

/**
 * THE ONE PLACE THAT PROVES A CALLER-NAMED DIRECTORY SITS INSIDE THE DIRECTORY IT IS ALLOWED TO.
 *
 * Extracted from `App\Support\Modules\RemovableModule`, which was written for the module-removal
 * door, the moment a SECOND destructive door needed the identical check. The reason it is shared
 * rather than copied is recorded in that class's own docblock and is not hypothetical: this codebase
 * has already had one recursive-delete-by-caller-input defect fixed at `AddonController::destroy`
 * (SEC-F5) and then had the same shape appear again, under its own number, at
 * `ModuleController::remove()` -- because the fix lived in one controller and the other never learned
 * it. A third copy is how a third door gets it wrong.
 *
 * TWO SEPARATE QUESTIONS, deliberately two methods, because they fail for different reasons and only
 * the caller knows which it needs:
 *
 *  - `safeSegment()` is about the NAME: is this one path component, made only of characters that
 *    cannot alter a path? It touches no filesystem and is therefore cheap to call first.
 *  - `within()` is about the PLACE: does `$root/$segment` canonically resolve to a real directory
 *    strictly inside `$root`? It resolves symlinks, so a target that is a link out of the tree is
 *    refused rather than followed.
 *
 * NEITHER IS A POLICY CHECK. Whether a particular module may be removed, or a particular language
 * deleted, is the caller's decision and stays with the caller -- `RemovableModule` keeps its
 * protected-name list and its manifest test, and `LanguageController` keeps its default-and-fallback
 * refusal. This class only answers "is this path what it claims to be".
 *
 * WHAT IT DOES NOT COVER, stated because the first version of the module fix claimed more than it
 * held: a symlink NESTED INSIDE an accepted directory. `within()` canonicalises `$root/$segment` and
 * nothing below it. The recursion itself has to refuse to walk through a link, and that belongs in
 * whichever delete helper does the walking.
 */
final class ConfinedDirectory
{
    /**
     * A single path component made only of `[A-Za-z0-9_-]`, returned unchanged.
     *
     * REFUSED ON MISMATCH, NOT SANITISED AND CONTINUED. `safeDirectoryName()` strips every character
     * that could alter a path, so `..` becomes '' and `Api/../Other` becomes `ApiOther`. ACTING on
     * the sanitised value would destroy something the caller did not name, which is a worse outcome
     * than refusing. So the only accepted name is one that survives sanitising UNCHANGED.
     *
     * COMPARED AGAINST THE RAW STRING, AND THAT IS NOT PEDANTRY. The first draft of this logic
     * trimmed first: PHP's `trim()` strips "\0" among its defaults, so `"Api\0"` became `"Api"` and
     * passed every later check -- a caller naming a NUL-bearing identifier would have had the real
     * `Api` destroyed. That is exactly the sanitise-and-continue behaviour the paragraph above
     * forbids, committed in the method that forbids it. Surrounding whitespace is refused for the
     * same reason: guessing what the caller meant is the behaviour being removed.
     *
     * @throws PathNotConfined
     */
    public static function safeSegment(mixed $candidate, string $noun = 'name'): string
    {
        if (! is_string($candidate)) {
            throw new PathNotConfined('the ' . $noun . ' is not a string');
        }

        if ($candidate === '') {
            throw new PathNotConfined('the ' . $noun . ' is empty');
        }

        if (InstallAddonRequest::safeDirectoryName($candidate) !== $candidate) {
            throw new PathNotConfined('the ' . $noun . ' contains characters that could alter a path');
        }

        return $candidate;
    }

    /**
     * The canonical, symlink-resolved directory for `$segment`, proven to sit strictly inside `$root`.
     *
     * `realpath()` does the work that matters: it resolves `.`, `..` AND symlinks, and returns false
     * for a path that does not exist. So a target that is a symlink pointing outside the root fails
     * the prefix test rather than being followed.
     *
     * THE ROOT ITSELF IS REFUSED SEPARATELY from "outside the root", because it is the more dangerous
     * of the two and reads as success: a traversal that resolves back to the root would hand a
     * recursive delete the whole collection instead of one member of it.
     *
     * The two nouns are parameters so each door's refusal reads in its own vocabulary; the message is
     * for a log and for tests and is never rendered to a caller.
     *
     * PRECONDITION: `$segment` should be a value `safeSegment()` has returned. Called with a nested
     * path this method still refuses anything that escapes, but `'Api/src'` resolves inside the root
     * and passes -- so a caller that skips `safeSegment()` can confine a path it did not mean to name.
     *
     * @throws PathNotConfined
     */
    public static function within(
        string $root,
        string $segment,
        string $rootNoun = 'root directory',
        string $targetNoun = 'directory'
    ): string {
        $canonicalRoot = realpath($root);

        if ($canonicalRoot === false) {
            throw new PathNotConfined('the ' . $rootNoun . ' does not exist');
        }

        $canonicalTarget = realpath($root . DIRECTORY_SEPARATOR . $segment);

        if ($canonicalTarget === false) {
            throw new PathNotConfined('the ' . $targetNoun . ' does not exist');
        }

        if ($canonicalTarget === $canonicalRoot) {
            throw new PathNotConfined('the ' . $targetNoun . ' resolves to the ' . $rootNoun . ' itself');
        }

        if (! str_starts_with($canonicalTarget, $canonicalRoot . DIRECTORY_SEPARATOR)) {
            throw new PathNotConfined('the ' . $targetNoun . ' resolves outside the ' . $rootNoun);
        }

        if (! is_dir($canonicalTarget)) {
            throw new PathNotConfined('the ' . $targetNoun . ' path is not a directory');
        }

        return $canonicalTarget;
    }
}
