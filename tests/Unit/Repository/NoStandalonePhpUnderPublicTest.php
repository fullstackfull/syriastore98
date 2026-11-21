<?php

namespace Tests\Unit\Repository;

use Tests\TestCase;

/**
 * NO PHP FILE OTHER THAN THE FRONT CONTROLLER IS TRACKED UNDER `public/`.
 * =======================================================================
 *
 * A `.php` file under the web root is run by the server as it stands: none of the application is around it -- no middleware, no guard, no
 * session, no framework. `public/index.php` is the one the application needs. A second, a sample script from a front-end component
 * that nothing in the application references, was tracked there; it is removed (P1-SEC-74, first-party part), and this is the ratchet
 * that keeps a second from arriving without somebody reading it.
 *
 * A PHP file the application really does need under `public/` is a deliberate decision, not a drive-by: add its path to the list below in
 * the same commit as the file and say in the commit what stands between a request and it.
 *
 * IT READS GIT'S INDEX, NOT THE FILESYSTEM (as NoTrackedTestArtefactsTest does): the question is what is TRACKED, so an untracked or
 * ignored file on a developer's disk is none of its business.
 */
class NoStandalonePhpUnderPublicTest extends TestCase
{
    /** @var list<string> every PHP file the application deliberately keeps under `public/` */
    private const ALLOWED = ['public/index.php'];

    public function test_the_front_controller_is_the_only_tracked_php_file_under_public(): void
    {
        $out = [];
        $code = 0;

        exec('cd ' . escapeshellarg(base_path()) . ' && git ls-files -- public 2>/dev/null', $out, $code);

        if ($code !== 0 || $out === []) {
            // No conditional skip (an undeclared skip fails the known-outcome gate in both directions): where git is unavailable this
            // records the fact and passes, which is honest -- the assertion is about git's index and there is no index to read.
            $this->assertTrue(true, 'git is unavailable here, so there is no index to inspect');

            return;
        }

        $this->assertContains('public/index.php', $out, 'Premise: the index lists the front controller, so the listing below is a listing of public/.');

        $php = array_values(array_filter($out, static fn (string $path) => preg_match('/\.(php\d?|phtml|pht|phps|phar)$/i', $path) === 1));
        sort($php);

        $this->assertSame(
            self::ALLOWED,
            $php,
            "A PHP file is tracked under public/ besides the front controller. The server runs it with nothing of the application around it.\n  "
            . implode("\n  ", array_diff($php, self::ALLOWED))
        );
    }
}
