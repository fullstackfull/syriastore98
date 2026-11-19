<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-77 -- EVERY `unserialize()` IN app/ SAYS WHICH CLASSES IT MAY BUILD.
 * =================================================================================================
 *
 * `unserialize($bytes)` builds whatever class the bytes name and runs its `__wakeup()` / `__destruct()`. That is safe only over bytes nobody but the application
 * wrote, and an independent review of the encrypter change found one in first-party code that reads bytes from somewhere else: `SettingController` unserialized
 * the answer of `http://ip-api.com/php/<ip>` -- plain HTTP, so the remote host's, or anybody on the path's -- with no class restriction. Its vendored twin in the
 * Marketing module (`packages/workdo/Marketing/.../StoreController.php`, the same line) cannot be edited and is recorded with the work queue's P1-SEC-77.
 *
 * `['allowed_classes' => false]` turns every object into an inert `__PHP_Incomplete_Class`. (It does NOT stop an enum -- `ObjectFreeEncrypterTest` pins that -- which
 * is why request bytes go through the encrypter's plain-data check instead, and why this census is about the calls that read something else.)
 *
 * Comments are not calls. The census asks one thing of every call in app/: that its argument list contains `allowed_classes`. A call that really needs to build an
 * object (none does) would be listed in `ALLOWED_TO_BUILD` with its reason, which is the place a reviewer would look.
 */
class UnserializeInAppNamesItsAllowedClassesTest extends TestCase
{
    /** file => reason, for a call that may build objects. Empty on purpose. */
    private const ALLOWED_TO_BUILD = [];

    public function test_every_unserialize_call_in_app_names_the_classes_it_may_build(): void
    {
        $root = dirname(__DIR__, 3) . '/app';
        $calls = [];
        $unrestricted = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // comments are not calls
            $code = (string) preg_replace('#/\*.*?\*/|(?<!:)//[^\n]*|^\s*\*[^\n]*#ms', '', (string) file_get_contents($file->getPathname()));
            $relative = substr($file->getPathname(), strlen(dirname($root)) + 1);

            // a call of the global function: not a method (`->unserialize(`, `::unserialize(`) and not a variable (`$unserialize`)
            preg_match_all('/(?<![\w>:$])unserialize\s*\(/', $code, $found, PREG_OFFSET_CAPTURE);

            foreach ($found[0] as [$text, $offset]) {
                $depth = 0;
                $end = $offset;

                for ($i = $offset + strlen($text) - 1, $length = strlen($code); $i < $length; $i++) {
                    if ($code[$i] === '(') {
                        $depth++;
                    } elseif ($code[$i] === ')' && --$depth === 0) {
                        $end = $i;

                        break;
                    }
                }

                $arguments = substr($code, $offset, $end - $offset + 1);
                $calls[] = $relative;

                if (! str_contains($arguments, 'allowed_classes') && ! isset(self::ALLOWED_TO_BUILD[$relative])) {
                    $unrestricted[] = $relative . ': ' . preg_replace('/\s+/', ' ', substr($arguments, 0, 90));
                }
            }
        }

        $this->assertContains('app/Support/Security/ObjectFreeEncrypter.php', $calls, 'Premise: the scan finds the encrypter\'s own unserialize(), or it matches nothing.');
        $this->assertContains('app/Http/Controllers/SettingController.php', $calls, 'Premise: the scan finds the ip-api.com call, or it matches nothing.');
        $this->assertSame([], $unrestricted, 'An unserialize() in app/ may build any class its bytes name. Pass [\'allowed_classes\' => false] (or list the call in ALLOWED_TO_BUILD with its reason).');
    }
}
