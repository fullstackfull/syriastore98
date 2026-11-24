<?php

namespace Tests\Feature\Security;

use App\Support\Security\ObjectFreeEncrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1-SEC-77 -- decrypt() unserializes attacker-shaped ciphertext, because APP_KEY is public.
 *
 * `Encrypter::decrypt($payload)` is `unserialize()` with no class restriction, and APP_KEY is in git history (CLAUDE.md): anyone can encrypt any serialized bytes, and
 * over a hundred call sites in app/ and the modules hand `decrypt()` a value from the request (a route segment, a form field, a payment gateway's return URL).
 * `WakeProbe77` is a HARMLESS stand-in for whatever a forged ciphertext carries: it counts its own `__wakeup`, which `unserialize()` calls on a class it may
 * instantiate. It lets these tests show that an object nobody asked for is BUILT -- on the stock encrypter, and through a real host handler -- without
 * exploiting anything. Whether a usable gadget chain exists in vendor/ is not established and was not attempted.
 *
 * The container's encrypter refuses anything that is not PLAIN DATA (null, a boolean, an integer, a float, a string, an array of those) BEFORE PHP sees it, with the
 * DecryptException a tampered payload gets. A first version stopped at `unserialize($bytes, ['allowed_classes' => false])`, and an independent review executed two
 * things against it that this file pins: an ENUM token comes back as the real enum case, and an enum token naming a class that does not exist makes PHP call the
 * AUTOLOADER with the attacker's chosen name.
 *
 * Every case that matters here goes through `Crypt` and `app('encrypter')` -- the container's binding, which is what the 100+ call sites use -- so the
 * registration in AppServiceProvider is part of what is tested (the case that asks the container for its encrypter fails without it). Forged bytes are made with the
 * FRAMEWORK's encrypter under the application's own key, which is exactly what anyone holding the public key can do.
 */
class ObjectFreeEncrypterTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WakeProbe77::$woken = 0;
    }

    /** The framework's own encrypter, under this application's key and cipher: what anyone who holds the (public) key can build. */
    private function stock(): Encrypter
    {
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;

        return new Encrypter($key, (string) config('app.cipher'));
    }

    /** A ciphertext whose plaintext is exactly $bytes, with a valid MAC. */
    private function forge(string $bytes): string
    {
        return $this->stock()->encrypt($bytes, false);
    }

    /** @return list<string> the names PHP asked the autoloader for while $work ran */
    private function autoloadRequestsDuring(callable $work): array
    {
        $asked = [];
        $recorder = function (string $class) use (&$asked): void {
            $asked[] = $class;
        };

        spl_autoload_register($recorder, true, true);

        try {
            $work();
        } finally {
            spl_autoload_unregister($recorder);
        }

        return $asked;
    }

    /** Swap the container's encrypter, and the facade's copy of it, for the framework's (the control of every end-to-end case). */
    private function useTheStockEncrypter(): void
    {
        $this->app->instance('encrypter', $this->stock());
        Crypt::clearResolvedInstance('encrypter');
    }

    public static function legitimatePayloads(): array
    {
        return [
            'an integer id' => [42],
            'a large id' => [1800000001],
            'a negative integer' => [-7],
            'the largest integer' => [PHP_INT_MAX],
            'the smallest integer' => [PHP_INT_MIN],
            'a string' => ['order-17'],
            'an empty string' => [''],
            'a unicode string' => ['طلب ١٢٣ — ünïcode'],
            'a numeric string' => ['0012'],
            'a string that looks like a token' => ['s:3:"abc";'],
            'a string with quotes, a semicolon and a brace' => ["a\"b;c}d"],
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'a float' => [12.5],
            'a float with an exponent' => [1.0E+25],
            'a gateway return payload' => [['response' => 'success', 'coupon' => '0', 'plan_id' => 3, 'price' => 12.5, 'order_id' => 'ABC123']],
            'a nested array of scalars' => [['a' => ['b' => ['c' => [1, 2, 3]]], 'd' => null]],
            'an array with integer and string keys' => [[5 => 'x', 'k' => true, -3 => 1.5]],
            'an empty array' => [[]],
        ];
    }

    /** @return array<string, array{0: string}> bytes that are NOT the serialization of plain data (see ObjectFreeEncrypter::isPlainData()) */
    public static function tokensThatAreNotPlainData(): array
    {
        $missing = 'P1Sec77\\NoSuchEnum:Case';

        return [
            'an object' => [serialize(new WakeProbe77())],
            'an object inside an array' => [serialize(['id' => 1, 'x' => ['deep' => new WakeProbe77()]])],
            'an enum' => [serialize(Suit77::Hearts)],
            'an enum of a class that does not exist' => ['E:' . strlen($missing) . ':"' . $missing . '";'],
            'a custom-serialised object' => ['C:11:"ArrayObject":0:{}'],
            'a reference' => ['a:2:{i:0;s:1:"x";i:1;R:2;}'],
            'an object reference' => ['a:2:{i:0;O:8:"stdClass":0:{}i:1;r:2;}'],
            'an escaped string' => ['S:1:"\\61";'],
            'an object as an array key' => ['a:1:{O:8:"stdClass":0:{}i:1;}'],
            'a boolean array key' => ['a:1:{b:1;i:1;}'],
            'a null array key' => ['a:1:{N;i:1;}'],
            'a float array key' => ['a:1:{d:1.5;i:1;}'],
            'nothing' => [''],
            'a value and trailing data' => ['i:5;x'],
            'a value and a trailing newline' => ["i:5;\n"],
            'two values' => ['i:1;i:2;'],
            'a leading space' => [' i:5;'],
            'a string shorter than it says' => ['s:5:"ab";'],
            'a string longer than it says' => ['s:1:"abc";'],
            'a length that runs off the end' => ['s:999999999:"a";'],
            'a string length with a leading zero' => ['s:01:"a";'],
            'an array with too few elements' => ['a:2:{i:0;i:1;}'],
            'an array with too many elements' => ['a:0:{i:0;i:1;}'],
            'an array count with a leading zero' => ['a:01:{i:0;i:1;}'],
            'an array that is not closed' => ['a:1:{i:0;i:1;'],
            'an array closed by another byte' => ['a:1:{i:0;i:1;x'],
            'a plus sign' => ['i:+5;'],
            'a leading zero' => ['i:007;'],
            'minus zero' => ['i:-0;'],
            'an integer past the maximum' => ['i:9223372036854775808;'],
            'an integer below the minimum' => ['i:-9223372036854775809;'],
            'twenty digits' => ['i:12345678901234567890;'],
            'a bare integer token' => ['i:;'],
            'a boolean that is neither' => ['b:2;'],
            'a lower-case NAN' => ['d:nan;'],
            'a hex float' => ['d:0x1p3;'],
            'a float with a leading zero' => ['d:007.5;'],
            'a null without its semicolon' => ['N'],
            'a word' => ['abc'],
            'a nest seventeen levels deep' => [str_repeat('a:1:{i:0;', 17) . 'i:1;' . str_repeat('}', 17)],
        ];
    }

    #[DataProvider('legitimatePayloads')]
    public function test_everything_the_application_encrypts_still_round_trips_exactly(mixed $value): void
    {
        $this->assertSame($value, Crypt::decrypt(Crypt::encrypt($value)));
    }

    public function test_the_values_php_cannot_compare_with_assertsame_round_trip_too(): void
    {
        $this->assertNan(Crypt::decrypt(Crypt::encrypt(NAN)));
        $this->assertSame(INF, Crypt::decrypt(Crypt::encrypt(INF)));
        $this->assertSame(-INF, Crypt::decrypt(Crypt::encrypt(-INF)));
    }

    public function test_the_decrypted_value_is_the_one_the_stock_encrypter_returns_for_every_legitimate_payload(): void
    {
        $stock = new Encrypter(random_bytes(32), 'aes-256-cbc');
        $ours = new ObjectFreeEncrypter($stock->getKey(), 'aes-256-cbc');

        foreach (self::legitimatePayloads() as $name => [$value]) {
            $ciphertext = $stock->encrypt($value);

            $this->assertSame($stock->decrypt($ciphertext), $ours->decrypt($ciphertext), "{$name} differs from the stock encrypter's answer.");
        }
    }

    #[DataProvider('legitimatePayloads')]
    public function test_every_legitimate_payload_is_plain_data(mixed $value): void
    {
        $this->assertTrue(ObjectFreeEncrypter::isPlainData(serialize($value)));
    }

    public function test_the_edges_of_what_is_plain_data(): void
    {
        $this->assertTrue(ObjectFreeEncrypter::isPlainData('i:9223372036854775807;'), 'the largest integer');
        $this->assertTrue(ObjectFreeEncrypter::isPlainData('i:-9223372036854775808;'), 'the smallest integer');
        $this->assertTrue(ObjectFreeEncrypter::isPlainData(serialize(NAN)));
        $this->assertTrue(ObjectFreeEncrypter::isPlainData(serialize(-INF)));
        $this->assertTrue(ObjectFreeEncrypter::isPlainData('d:-0;'), 'the float written for negative zero');
        $this->assertTrue(ObjectFreeEncrypter::isPlainData(str_repeat('a:1:{i:0;', 16) . 'i:1;' . str_repeat('}', 16)), 'a nest sixteen levels deep');
        $this->assertTrue(ObjectFreeEncrypter::isPlainData('a:0:{}'));
        $this->assertTrue(ObjectFreeEncrypter::isPlainData('s:0:"";'));
        $this->assertTrue(ObjectFreeEncrypter::isPlainData('N;'));
        $this->assertTrue(ObjectFreeEncrypter::isPlainData('b:0;'));
        $this->assertTrue(ObjectFreeEncrypter::isPlainData('b:1;'));
    }

    #[DataProvider('tokensThatAreNotPlainData')]
    public function test_a_token_that_is_not_plain_data_is_not_plain_data(string $bytes): void
    {
        $this->assertFalse(ObjectFreeEncrypter::isPlainData($bytes));
    }

    /**
     * THE CONTROL: bytes PHP reads as plain data and the validator accepts must be plain data. A seeded fuzz over mutations of valid serializations (replace, insert,
     * delete, truncate) asks, of every mutant the validator ACCEPTS, whether PHP's own `unserialize()` agrees: no warning (the framework turns one into an
     * exception), no object, no call to the autoloader. A validator that is more permissive than PHP's parser would show here. (A local generator: the global one is
     * left alone.)
     */
    public function test_whatever_the_validator_accepts_php_reads_as_plain_data(): void
    {
        $state = 77;
        $next = function (int $max) use (&$state): int {
            $state = ($state * 1103515245 + 12345) & 0x7fffffff;

            return intdiv($state, 65536) % $max;
        };

        $seeds = array_map(fn ($case) => serialize($case[0]), array_values(self::legitimatePayloads()));
        $seeds[] = 'a:3:{i:0;s:3:"E:1";i:1;a:1:{s:1:"k";d:1.5;}i:2;b:1;}';
        $alphabet = ['O', 'C', 'E', 'R', 'r', 'S', ':', ';', '{', '}', '"', 'N', '0', '1', '9', 'a', 's', 'i', 'd', 'b', '-', '.', 'e', '+', "\n", ' ', "\0"];
        $hasObject = function ($value) use (&$hasObject): bool {
            if (is_object($value)) {
                return true;
            }

            foreach (is_array($value) ? $value : [] as $item) {
                if ($hasObject($item)) {
                    return true;
                }
            }

            return false;
        };

        $accepted = 0;
        $warnings = 0;
        $problems = [];
        set_error_handler(function () use (&$warnings) {
            $warnings++;

            return true;
        });

        try {
            for ($n = 0; $n < 6000; $n++) {
                $bytes = $seeds[$next(count($seeds))];

                for ($edits = 1 + $next(3); $edits > 0; $edits--) {
                    $at = $next(max(1, strlen($bytes)));
                    $bytes = match ($next(4)) {
                        0 => substr($bytes, 0, $at) . $alphabet[$next(count($alphabet))] . substr($bytes, $at + 1),
                        1 => substr($bytes, 0, $at) . $alphabet[$next(count($alphabet))] . substr($bytes, $at),
                        2 => substr($bytes, 0, $at) . substr($bytes, $at + 1),
                        default => substr($bytes, 0, $at),
                    };
                }

                if (! ObjectFreeEncrypter::isPlainData($bytes)) {
                    continue;
                }

                $accepted++;
                $before = $warnings;
                $value = null;
                $asked = $this->autoloadRequestsDuring(function () use ($bytes, &$value) {
                    $value = unserialize($bytes, ['allowed_classes' => false]);
                });

                if ($warnings !== $before || $hasObject($value) || $asked !== [] || ($value === false && $bytes !== 'b:0;')) {
                    $problems[] = json_encode($bytes);
                }
            }
        } finally {
            restore_error_handler();
        }

        $this->assertGreaterThan(100, $accepted, 'Premise: the fuzz must reach the validator\'s "accepted" branch often enough to mean something.');
        $this->assertSame([], array_slice($problems, 0, 5), 'The validator accepted bytes that PHP does not read as plain data.');
    }

    public function test_an_object_in_a_ciphertext_is_refused_and_never_built(): void
    {
        try {
            Crypt::decrypt(Crypt::encrypt(new WakeProbe77()));
            $this->fail('An object in a ciphertext was handed back.');
        } catch (DecryptException $e) {
            $this->assertSame('The payload is invalid.', $e->getMessage(), 'A refusal must read as the tampered payload it is, so every handler\'s own refusal path runs.');
        }

        $this->assertSame(0, WakeProbe77::$woken, 'decrypt() instantiated a class named by the ciphertext: an object-injection door.');
    }

    public function test_an_object_hidden_in_an_array_is_refused_too(): void
    {
        $this->expectException(DecryptException::class);

        try {
            Crypt::decrypt(Crypt::encrypt(['id' => 1, 'x' => ['deep' => new WakeProbe77()]]));
        } finally {
            $this->assertSame(0, WakeProbe77::$woken);
        }
    }

    #[DataProvider('tokensThatAreNotPlainData')]
    public function test_a_forged_payload_that_is_not_plain_data_is_refused_before_php_reads_it(string $bytes): void
    {
        $asked = $this->autoloadRequestsDuring(function () use ($bytes) {
            try {
                Crypt::decrypt($this->forge($bytes));
                $this->fail('Bytes that are not plain data were handed back.');
            } catch (DecryptException $e) {
                $this->assertSame('The payload is invalid.', $e->getMessage());
            }
        });

        $this->assertSame(0, WakeProbe77::$woken);
        $this->assertNotContains('P1Sec77\\NoSuchEnum', $asked, 'An enum token made PHP call the autoloader with a name the attacker chose.');
    }

    public function test_the_stock_encrypter_does_build_it_so_the_probe_is_a_real_one(): void
    {
        $stock = new Encrypter(random_bytes(32), 'aes-256-cbc');

        $stock->decrypt($stock->encrypt(new WakeProbe77()));

        $this->assertSame(1, WakeProbe77::$woken, 'Premise: with the framework\'s own decrypt() the probe is built and wakes.');
    }

    /** The premise of the enum cases: the framework's decrypt() returns the enum case, and asks the autoloader for a class the ciphertext names. */
    public function test_the_stock_encrypter_builds_an_enum_and_calls_the_autoloader_for_a_name_the_ciphertext_chose(): void
    {
        $stock = $this->stock();
        $missing = 'P1Sec77\\NoSuchEnum:Case';

        $this->assertSame(Suit77::Hearts, $stock->decrypt($stock->encrypt(Suit77::Hearts)), 'Premise: `allowed_classes` is not even in play on the framework\'s decrypt(); an enum comes back as the real case.');

        set_error_handler(fn () => true);

        try {
            $asked = $this->autoloadRequestsDuring(fn () => $stock->decrypt($stock->encrypt('E:' . strlen($missing) . ':"' . $missing . '";', false)));
        } finally {
            restore_error_handler();
        }

        $this->assertContains('P1Sec77\\NoSuchEnum', $asked, 'Premise: an enum token makes PHP call the autoloader with the attacker\'s chosen name.');
    }

    public function test_a_refusal_is_logged_once_each_without_what_it_refused(): void
    {
        $logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [$message, $context];
        });

        foreach ([new WakeProbe77(), ['id' => 1, 'x' => ['deep' => new WakeProbe77()]]] as $forged) {
            try {
                Crypt::decrypt(Crypt::encrypt($forged));
            } catch (DecryptException) {
                // expected
            }
        }

        Crypt::decrypt(Crypt::encrypt(['ok' => 1]));

        $this->assertCount(2, $logged, 'One line per refusal, and none for an ordinary payload.');
        $this->assertSame('a decrypted payload was not plain data and was refused', $logged[0][0]);
        $this->assertSame([], $logged[0][1]);
        $this->assertStringNotContainsString('WakeProbe77', json_encode($logged));
    }

    public function test_decrypt_string_and_the_unserialize_false_form_are_untouched(): void
    {
        $this->assertSame('x i:1;', Crypt::decryptString(Crypt::encryptString('x i:1;')));
        $this->assertSame('i:5;', Crypt::decrypt(Crypt::encrypt(5), false));
        // the `false` form hands back the plaintext whatever it is: it is not this class's to judge
        $this->assertSame(serialize(new WakeProbe77()), Crypt::decrypt(Crypt::encrypt(new WakeProbe77()), false));
        $this->assertSame(0, WakeProbe77::$woken);
    }

    /**
     * THE TWO FRAMEWORK CONSUMERS THAT CALL decrypt() WITH ITS DEFAULT (unserialize = true): an encrypted session (`SESSION_ENCRYPT=true`) and an encrypted queued
     * job. Both encrypt a payload they have ALREADY serialised, so what the container's encrypter unserializes is a STRING -- plain data -- and the inner,
     * object-bearing `unserialize()` is the framework's own, over bytes only the server wrote. Neither may notice the change.
     */
    public function test_an_encrypted_session_store_still_round_trips_an_object_the_server_itself_put_in_it(): void
    {
        $handler = new \Illuminate\Session\ArraySessionHandler(120);
        $store = new \Illuminate\Session\EncryptedStore('p77', $handler, app('encrypter'));
        $id = $store->getId();

        $store->put('object', (object) ['a' => 1, 'b' => ['c' => 2]]);
        $store->put('number', 5);
        $store->save();

        $again = new \Illuminate\Session\EncryptedStore('p77', $handler, app('encrypter'), $id);
        $again->start();

        $this->assertEquals((object) ['a' => 1, 'b' => ['c' => 2]], $again->get('object'), 'An encrypted session lost an object the server put in it.');
        $this->assertSame(5, $again->get('number'));
    }

    public function test_the_queues_shape_a_serialised_string_inside_the_ciphertext_still_round_trips_an_object(): void
    {
        $job = new \ArrayObject(['id' => 7]);

        $payload = app('encrypter')->encrypt(serialize($job));   // what Queue::createObjectPayload() does for a ShouldBeEncrypted job

        $this->assertEquals($job, unserialize(app('encrypter')->decrypt($payload)), 'An encrypted queued job did not survive the object-free encrypter.');
    }

    public function test_a_tampered_or_foreign_ciphertext_still_fails_as_the_framework_fails(): void
    {
        $other = new Encrypter(random_bytes(32), 'aes-256-cbc');

        $this->expectException(DecryptException::class);

        Crypt::decrypt($other->encrypt(['x' => 1]));
    }

    public function test_the_previous_keys_still_open_a_value_encrypted_before_a_rotation(): void
    {
        $old = random_bytes(32);
        $new = random_bytes(32);
        $before = (new Encrypter($old, 'aes-256-cbc'))->encrypt(['id' => 9]);

        $after = (new ObjectFreeEncrypter($new, 'aes-256-cbc'))->previousKeys([$old]);

        $this->assertSame(['id' => 9], $after->decrypt($before));
    }

    /**
     * The registration builds a NEW encrypter around the framework's, so what the framework's carried -- the key, the cipher and `previous_keys` -- has to be copied by
     * hand. The case above proves the CLASS can hold previous keys; this one proves the CONTAINER hands out one that does (a rotation that kept working for the cookies
     * but not for a stored ciphertext is the kind of thing nobody sees until the day after the rotation).
     */
    public function test_the_container_keeps_the_configured_previous_keys(): void
    {
        $old = random_bytes(32);
        config(['app.previous_keys' => ['base64:' . base64_encode($old)]]);

        // the container builds its encrypter on first use: drop the one this booted application already holds (and the facade's copy), so it is built from the config above
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $before = (new Encrypter($old, (string) config('app.cipher')))->encrypt(['id' => 9]);

        $this->assertInstanceOf(ObjectFreeEncrypter::class, app('encrypter'), 'Premise: the encrypter under test is the registered one.');
        $this->assertSame(['id' => 9], app('encrypter')->decrypt($before));
        $this->assertSame(['id' => 9], Crypt::decrypt($before));
    }

    public function test_the_container_encrypter_and_the_frameworks_agree_on_the_applications_key_and_cipher(): void
    {
        $stock = $this->stock();

        // what the framework made under the application's own key and cipher -- every ciphertext already in a link, a column or a cookie -- opens through the container ...
        $this->assertSame(['id' => 5], app('encrypter')->decrypt($stock->encrypt(['id' => 5])));
        // ... and what the container makes opens for the framework (the cookie middleware, an encrypted queued job)
        $this->assertSame(['id' => 6], $stock->decrypt(app('encrypter')->encrypt(['id' => 6])));
    }

    public function test_the_container_hands_out_the_object_free_encrypter_to_every_consumer(): void
    {
        $this->assertInstanceOf(ObjectFreeEncrypter::class, app('encrypter'));
        $this->assertSame(app('encrypter'), app(\Illuminate\Contracts\Encryption\Encrypter::class));
        $this->assertInstanceOf(ObjectFreeEncrypter::class, Crypt::getFacadeRoot());
    }

    /**
     * THE BROADEST DOOR: no account, no plan, no module. `POST /register` is a guest route and `store()` decrypts `plan_id` as the first thing it does, inside a
     * `try` that treats a failure as "no plan" (plan 1). (The installer check in the controller's constructor needs `storage/installed`, which every registration
     * test already relies on.)
     */
    public function test_a_forged_plan_id_on_registration_does_not_build_an_object_for_a_visitor_with_no_account(): void
    {
        $this->assertTrue(file_exists(storage_path('installed')), 'Premise: the installer marker exists, or the controller would die() before reaching the decrypt.');

        $form = fn (string $planId) => ['plan_id' => $planId, 'name' => 'Visitor', 'email' => 'visitor-p77@example.test', 'password' => 'x', 'password_confirmation' => 'x'];

        $response = $this->post('/register', $form(Crypt::encrypt(new WakeProbe77())));

        $this->assertSame(0, WakeProbe77::$woken, 'A forged plan_id was unserialized into an object for a visitor with no account.');
        $this->assertLessThan(500, $response->status(), 'A forged plan_id was not treated as the invalid one it is.');

        // THE CONTROL, so that the 0 above is the encrypter's doing and not a request that never reached the decrypt: with the framework's own encrypter in the
        // container the same request builds the probe.
        $this->useTheStockEncrypter();
        $this->post('/register', $form($this->stock()->encrypt(new WakeProbe77())));

        $this->assertSame(1, WakeProbe77::$woken, 'Premise: with the framework\'s own decrypt() the same request builds the probe.');
    }

    /**
     * GET /orders/order_view/{id} is in OrderDocumentAccessGuard::TARGETS, and the guard decrypts the segment FIRST (resolveOrderId), so this is the frame that
     * would have built the object, and the controller's show() is never reached. (The next case calls show() itself.)
     */
    public function test_a_forged_order_link_does_not_build_an_object_in_the_order_document_guard(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();

        $response = $this->actingAs($merchant->fresh())->get('/orders/order_view/' . Crypt::encrypt(new WakeProbe77()));

        $this->assertSame(0, WakeProbe77::$woken, 'A forged route segment was unserialized into an object by OrderDocumentAccessGuard.');
        $this->assertLessThan(500, $response->status());

        $this->useTheStockEncrypter();
        $this->actingAs($merchant->fresh())->get('/orders/order_view/' . $this->stock()->encrypt(new WakeProbe77()));

        $this->assertSame(1, WakeProbe77::$woken, 'Premise: with the framework\'s own decrypt() the same request builds the probe in the guard.');
    }

    public function test_a_forged_segment_handed_straight_to_the_order_controller_gets_its_designed_refusal(): void
    {
        $controller = app(\App\Http\Controllers\OrderController::class);

        $response = $controller->show(Crypt::encrypt(new WakeProbe77()));

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response, 'show() has a designed refusal for a payload that does not decrypt: a redirect with "Something was wrong."');
        $this->assertSame(0, WakeProbe77::$woken, 'A forged segment was unserialized into an object by OrderController::show().');

        $this->useTheStockEncrypter();

        try {
            $controller->show($this->stock()->encrypt(new WakeProbe77()));
        } catch (\Throwable) {
            // with an object where an id belongs the handler fails further on: it is the wake-up that matters here
        }

        $this->assertSame(1, WakeProbe77::$woken, 'Premise: with the framework\'s own decrypt() show() builds the probe.');
    }
}

/** A harmless stand-in for whatever a forged ciphertext carries: it counts its own `__wakeup`, which unserialize() calls on a class it may instantiate. */
class WakeProbe77
{
    public static int $woken = 0;

    public function __wakeup(): void
    {
        self::$woken++;
    }
}

/** A stand-in enum: `unserialize()` hands an enum case back whatever `allowed_classes` says. */
enum Suit77: string
{
    case Hearts = 'H';
}
