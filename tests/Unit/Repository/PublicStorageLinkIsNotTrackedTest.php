<?php

namespace Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;

/**
 * `public/storage` MUST NOT BE A TRACKED REGULAR FILE, and this is what went wrong.
 *
 * MEASURED 2026-11-24. `git ls-files -s public/storage` reported mode **100755** -- a regular,
 * executable file -- holding 62 bytes:
 *
 *     /var/www/html/Product/EcommerceGo/main_file/storage/app/public
 *
 * That is a SYMLINK SERIALISED AS TEXT: git stores a symlink as mode 120000 with the target as its
 * content, and a tree checked out with `core.symlinks=false` or repackaged into a ZIP -- which is
 * how the WorkDo release arrives -- materialises it as a plain file whose contents are the target
 * path from the vendor's own build machine. It entered in `4e878b8d`.
 *
 * WHAT IT COST. In EVERY checkout `public/storage` was a file, so nothing under `/storage/...`
 * resolved: 30 views and 24 PHP files call `Storage::url`, and the operator dashboard's own
 * four-combination audit reported `404 /storage/uploads/profile/avatar.png` on all four shots.
 *
 * AND THE DOCUMENTED REMEDY WAS DEFEATED BY IT. `php artisan storage:link` printed
 *
 *     ERROR  The [public/storage] link already exists.
 *
 * and changed nothing -- Laravel refuses when the path exists and `--force` was not passed. So an
 * operator following the official instruction saw an error and no repair, which is worse than a
 * missing link, because the error reads as "already fine".
 *
 * THE FIX IS TO UNTRACK IT, NOT TO COMMIT A SYMLINK, and the repository had already made that
 * decision once for the sibling path. `.gitignore:1-8` ignores `/public/uploads` and its comment
 * says a dev environment creates `public/uploads -> ../uploads` itself; Laravel's own shipped
 * `.gitignore` ignores `/public/storage` for the same reason. A link whose correct target depends
 * on where the application is installed is per-environment state, not source.
 *
 * Verified by execution after untracking: `storage:link` created the link, and
 * `/storage/uploads/profile/avatar.png` answered **HTTP 200, 8117 bytes** where it had been a 404.
 *
 * WHAT THIS TEST CAN AND CANNOT SEE. It does not shell out to git, so it cannot read the index.
 * What it can check is the two things that would have to be true for the defect to return: the
 * ignore rule is present (so `git add -A` cannot re-add it), and if the path exists at all it is a
 * link rather than a file (so a checkout that materialises one as text fails here rather than
 * silently 404ing every uploaded image).
 */
final class PublicStorageLinkIsNotTrackedTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function gitignore(): string
    {
        return (string) file_get_contents($this->root().'/.gitignore');
    }

    public function test_public_storage_is_ignored_so_it_cannot_be_committed_again(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\/public\/storage$/m',
            $this->gitignore(),
            'The `/public/storage` rule is gone from .gitignore. Without it a `git add -A` in an '
            .'environment where the link exists re-commits it, and every /storage URL 404s again in '
            .'every other checkout.'
        );
    }

    public function test_the_sibling_uploads_rule_is_still_there_so_the_pair_stays_consistent(): void
    {
        // The two paths are the same decision. If one rule is dropped the reasoning has drifted.
        $this->assertMatchesRegularExpression(
            '/^\/public\/uploads$/m',
            $this->gitignore(),
            '`/public/uploads` is no longer ignored. It and `/public/storage` are per-environment '
            .'links for the same reason; losing one means the convention has changed and this test '
            .'needs rewriting rather than relaxing.'
        );
    }

    public function test_if_public_storage_exists_it_is_a_link_and_not_a_regular_file(): void
    {
        $path = $this->root().'/public/storage';

        if (! file_exists($path) && ! is_link($path)) {
            // A fresh clone has no link until `storage:link` runs. That is the intended state and
            // is NOT a failure -- the point of untracking it. Say so rather than skipping, which
            // would break the declared outcome set in .github/known-test-outcomes.json.
            $this->assertTrue(true, 'no public/storage yet; `php artisan storage:link` creates it');

            return;
        }

        $this->assertTrue(
            is_link($path),
            'public/storage exists but is NOT a symlink. That is the exact shape of the defect this '
            .'test exists for: a 62-byte regular file holding a target path, after which nothing '
            .'under /storage/... resolves and `artisan storage:link` refuses to repair it. Delete '
            .'the file and run `php artisan storage:link`.'
        );
    }

    public function test_the_ignore_rule_explains_itself_so_the_next_reader_does_not_undo_it(): void
    {
        $this->assertStringContainsString(
            'storage:link',
            $this->gitignore(),
            'The `/public/storage` rule carries no explanation. It was added because the path had '
            .'been committed as a regular file and defeated `artisan storage:link`; without that '
            .'note the rule looks arbitrary and gets reverted.'
        );
    }
}
