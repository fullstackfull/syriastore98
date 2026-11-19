<?php

namespace App\Support\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

/**
 * AN ENCRYPTER WHOSE decrypt() WILL NOT HAND PHP ANYTHING BUT PLAIN DATA.  P1-SEC-77.
 * =====================================================================================================================================
 *
 * `Encrypter::decrypt($payload)` is `unserialize(<the decrypted bytes>)` with NO class restriction. That is safe only while nobody but the
 * application can produce a ciphertext, and this application's APP_KEY is in git history and unrotated (CLAUDE.md): anyone can encrypt whatever
 * serialized bytes they like. A grep of app/ and the modules finds 107 calls to `decrypt(` / `Crypt::decrypt(` that unserialize, in 72 files;
 * nearly all hand it a value that came from the request -- a route segment (`orders/order_view/{id}`, `edit-ticket/{ticketId}`), a form field (`plan_id`,
 * `data`), a payment gateway's return URL. Each is an object-injection door: the request names the class that `unserialize()` instantiates, and its
 * `__wakeup`/`__destruct` run. Whether a usable gadget chain exists in vendor/ is not established here and was not attempted; the door is closed regardless.
 *
 * WHAT THE APPLICATION ACTUALLY ENCRYPTS, by census of every `encrypt(` call in app/, resources/ and the modules: an id (`$plan->id`, `$order->id`,
 * `$user->id`, `$ticket->ticket_id`) or an array of scalars (a gateway's return payload). Never an object, a model or a collection. So refusing anything
 * else changes nothing a legitimate request can observe, and that is what lets the control sit at the one place every site passes through instead of at
 * 107 call sites (each of which would have to be read, and the next one would be unprotected).
 *
 * WHAT IT DOES. `decrypt($payload)` -- the default, `$unserialize = true` -- decrypts and verifies exactly as the framework does (the MAC, the keys, the
 * previous keys). It then looks at the decrypted bytes BEFORE PHP does: they must be, exactly, the serialization of PLAIN DATA -- null, a boolean, an integer, a
 * float, a string, or an array of those -- and nothing else (see isPlainData()). Anything else is written to the log (once, no content: it is the attacker's
 * text) and refused with the DecryptException a tampered payload gets, so every handler's own refusal path is the one that runs.
 *
 * WHY NOT JUST `unserialize($bytes, ['allowed_classes' => false])`, which is where the first version of this class stopped. Two things an independent review
 * EXECUTED against it on PHP 8.3: (1) `allowed_classes` does not stop an ENUM token -- `E:13:"Suit77:Hearts";` comes back as the real enum case; (2) an
 * enum token naming a class that does not exist makes PHP call the AUTOLOADER with the attacker's chosen name. And an `__PHP_Incomplete_Class` it did hand
 * back re-serializes to the original bytes, class name included, so a handler that stored it would have carried a live payload to a later `unserialize`.
 * Looking at the bytes first closes all three: nothing that is not plain data ever reaches `unserialize()`, which is then still called with
 * `allowed_classes => false` as the second layer.
 *
 * `decrypt($payload, false)` and `decryptString()` are the framework's and are untouched.
 *
 * WHAT IT DOES NOT DO. It does not make an encrypted id a control -- a forged id is still a forged id; the callers' ownership checks (P1-SEC-68, the
 * guards) are what judge it. It does not touch raw `unserialize()` calls that read other data (the ip-api.com answer was one: see SettingController, and its
 * vendored twin in the Marketing module that cannot be edited). And the real fix is the owner's: ROTATE APP_KEY (rotation is not done here and is not mine to
 * do; it also invalidates every stored ciphertext).
 */
final class ObjectFreeEncrypter extends Encrypter
{
    /** The deepest array nesting accepted. The application's own payloads nest four levels at most; PHP itself stops at 4096. */
    private const MAX_DEPTH = 16;

    public function decrypt($payload, $unserialize = true)
    {
        // The framework's own checks (MAC, key, previous keys), without its unserialize().
        $decrypted = parent::decrypt($payload, false);

        if (! $unserialize) {
            return $decrypted;
        }

        if (! self::isPlainData($decrypted)) {
            Log::warning('a decrypted payload was not plain data and was refused');

            throw new DecryptException('The payload is invalid.');
        }

        return unserialize($decrypted, ['allowed_classes' => false]);
    }

    /**
     * Is $bytes, EXACTLY, the serialization of plain data: null, a boolean, an integer, a float, a string, or an array (keys integer or string) of those?
     * Nothing may follow the value, nothing may be missing, and every length must be what it says. A string's bytes are skipped by their declared length and
     * never read, so no byte inside a string can be mistaken for a token. Objects (`O:`, `C:`), enums (`E:`), references (`r:`, `R:`), escaped strings (`S:`)
     * and anything else are not plain data.
     */
    public static function isPlainData(string $bytes): bool
    {
        $at = 0;

        return self::value($bytes, $at, 0) && $at === strlen($bytes);
    }

    private static function value(string $bytes, int &$at, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        switch ($bytes[$at] ?? '') {
            case 'N':
                return self::token($bytes, $at, '/\GN;/');

            case 'b':
                return self::token($bytes, $at, '/\Gb:[01];/');

            case 'i':
                // exactly what serialize() writes: no plus sign, no leading zero, no `-0`, and inside the integer range (PHP warns about an overflow, and the
                // framework turns a warning into an exception)
                if (preg_match('/\Gi:(-?)(0|[1-9]\d{0,18});/', $bytes, $m, 0, $at) !== 1 || ($m[1] === '-' && $m[2] === '0')) {
                    return false;
                }

                if (strlen($m[2]) === 19 && strcmp($m[2], $m[1] === '-' ? '9223372036854775808' : '9223372036854775807') > 0) {
                    return false;
                }

                $at += strlen($m[0]);

                return true;

            case 'd':
                return self::token($bytes, $at, '/\Gd:(?:-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?|NAN|-?INF);/');

            case 's':
                if (preg_match('/\Gs:(0|[1-9]\d{0,8}):"/', $bytes, $m, 0, $at) !== 1) {
                    return false;
                }

                // the string's own bytes are skipped by their declared length and never read; what follows them must be the closing `";` (substr() past the end is
                // shorter than two bytes, so a length that runs off the end of the input is refused here too)
                $end = $at + strlen($m[0]) + (int) $m[1];

                if (substr($bytes, $end, 2) !== '";') {
                    return false;
                }

                $at = $end + 2;

                return true;

            case 'a':
                if (preg_match('/\Ga:(0|[1-9]\d{0,8}):\{/', $bytes, $m, 0, $at) !== 1) {
                    return false;
                }

                $at += strlen($m[0]);

                for ($left = (int) $m[1]; $left > 0; $left--) {
                    // a key is an integer or a string; a value is anything plain
                    if (! in_array($bytes[$at] ?? '', ['i', 's'], true) || ! self::value($bytes, $at, $depth + 1) || ! self::value($bytes, $at, $depth + 1)) {
                        return false;
                    }
                }

                if (($bytes[$at] ?? '') !== '}') {
                    return false;
                }

                $at++;

                return true;

            default:
                return false;
        }
    }

    private static function token(string $bytes, int &$at, string $pattern): bool
    {
        if (preg_match($pattern, $bytes, $m, 0, $at) !== 1) {
            return false;
        }

        $at += strlen($m[0]);

        return true;
    }
}
