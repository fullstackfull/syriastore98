<?php

namespace Tests\Unit\Events;

use App\Support\Events\NormaliseDuplicateEventListeners as Normalise;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Testing\Fakes\EventFake;
use Tests\TestCase;

/**
 * THE NORMALISER'S OWN EDGE CASES, which the integration test cannot reach.
 *
 * `RegisteredEventListenerDuplicationTest` drives the real boot sequence and the real notification,
 * so it proves the defect is gone. It cannot construct a listener list containing a closure, or an
 * already-clean list, or a replaced dispatcher. Those are the cases where a careless implementation
 * does damage rather than nothing, so they are built by hand here against a fresh `Dispatcher`.
 *
 * Each case uses a FRESH dispatcher rather than the application's, so nothing here can perturb the
 * container the rest of the suite shares.
 */
class NormaliseDuplicateEventListenersTest extends TestCase
{
    private const A = 'Tests\\Unit\\Events\\Fixtures\\ListenerA';
    private const B = 'Tests\\Unit\\Events\\Fixtures\\ListenerB';

    private function dispatcherWith(array $listeners): Dispatcher
    {
        $events = new Dispatcher();

        foreach ($listeners as $listener) {
            $events->listen(Registered::class, $listener);
        }

        return $events;
    }

    /** @return list<string> */
    private function raw(Dispatcher $events): array
    {
        return array_values(array_map(
            fn ($l) => is_string($l) ? $l : '(closure)',
            $events->getRawListeners()[Registered::class] ?? []
        ));
    }

    public function test_exact_duplicates_are_collapsed_to_one(): void
    {
        $events = $this->dispatcherWith([self::A, self::A, self::A, self::A]);

        $this->assertSame(4, count($this->raw($events)), 'PREMISE: the fixture did not take.');

        $removed = Normalise::run($events);

        $this->assertSame([Registered::class => 3], $removed);
        $this->assertSame([self::A], $this->raw($events));
    }

    /** A SECOND, DIFFERENT LISTENER IS NOT A DUPLICATE. The fix must not disable other modules. */
    public function test_a_distinct_listener_is_kept(): void
    {
        $events = $this->dispatcherWith([self::A, self::B, self::A]);

        Normalise::run($events);

        $this->assertSame([self::A, self::B], $this->raw($events));
    }

    /** The surviving order is the ORIGINAL order, first occurrence wins. */
    public function test_the_surviving_order_is_the_original_order(): void
    {
        $events = $this->dispatcherWith([self::B, self::A, self::B, self::A]);

        Normalise::run($events);

        $this->assertSame([self::B, self::A], $this->raw($events),
            'the listeners were reordered, so a listener that relied on running before another no '
            . 'longer does');
    }

    /**
     * CLOSURES PASS THROUGH UNTOUCHED, and are never treated as duplicates of each other.
     *
     * Two closures are distinct listeners even when their bodies are identical -- there is no
     * meaningful equality to test -- so comparing them would be guesswork. They are kept, all of
     * them, in place.
     */
    public function test_closures_are_preserved(): void
    {
        $events = $this->dispatcherWith([
            self::A,
            function () {},
            self::A,
            function () {},
        ]);

        Normalise::run($events);

        $this->assertSame([self::A, '(closure)', '(closure)'], $this->raw($events));
    }

    /** An already-clean list is left exactly as it is, and reports no removals. */
    public function test_a_clean_list_is_untouched(): void
    {
        $events = $this->dispatcherWith([self::A, self::B]);

        $this->assertSame([], Normalise::run($events));
        $this->assertSame([self::A, self::B], $this->raw($events));
    }

    /** Idempotent: running twice changes nothing the second time. */
    public function test_running_twice_is_a_no_op(): void
    {
        $events = $this->dispatcherWith([self::A, self::A]);

        $this->assertSame([Registered::class => 1], Normalise::run($events));
        $this->assertSame([], Normalise::run($events));
        $this->assertSame([self::A], $this->raw($events));
    }

    /** An event with no listeners at all is not an error. */
    public function test_an_empty_listener_list_is_not_an_error(): void
    {
        $events = new Dispatcher();

        $this->assertSame([], Normalise::run($events));
        $this->assertSame([], $this->raw($events));
    }

    /**
     * A REPLACED DISPATCHER IS DECLINED RATHER THAN GUESSED AT.
     *
     * `Event::fake()` swaps in an `EventFake`, which has no raw listener list. Reading one would
     * throw, and inventing one would be worse: the normaliser would silently rebuild a listener
     * list on an object whose whole purpose is to record dispatches instead of running them.
     */
    public function test_a_faked_dispatcher_is_left_alone(): void
    {
        $fake = new EventFake(new Dispatcher());

        $this->assertSame([], Normalise::run($fake),
            'the normaliser tried to rewrite a faked dispatcher');
    }

    /**
     * THE NORMALISATION RUNS AFTER A MODULE THAT REGISTERS FROM A LATER `booted()` CALLBACK.
     *
     * This is the ordering property `queue()`'s nesting exists for, and without this case it was a
     * SURVIVED mutation: the test environment boots no database-enabled modules, so a plain
     * `$app->booted()` callback happens to see the same listener list and dedupe it, and removing
     * the nesting broke nothing observable.
     *
     * `PackageServiceProvider` loads database-enabled modules from inside its OWN `$app->booted()`
     * callback, and `AppServiceProvider::boot()` -- where `queue()` is called -- runs first, so a
     * plain callback is queued BEFORE the module loader's and runs before the duplicates exist.
     * `Application::fireAppCallbacks()` walks a growing list, so the nested callback is appended
     * while the queue is being fired and therefore runs after it.
     *
     * Built on a bare `Application` rather than the test app, because the test app is already
     * booted and `booted()` on it fires immediately -- which would destroy the ordering this
     * measures.
     */
    public function test_the_normalisation_runs_after_a_late_registration(): void
    {
        $app = new \Illuminate\Foundation\Application(base_path());
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $events);

        // The registration `App\Providers\EventServiceProvider` makes through its `$listen`.
        $events->listen(Registered::class, self::A);

        // Queued where `AppServiceProvider::boot()` queues it.
        Normalise::queue($app);

        // A module provider arriving later and registering the duplicate, exactly as
        // `configureEmailVerification()` does for each one.
        $app->booted(function () use ($events): void {
            $events->listen(Registered::class, self::A);
        });

        $this->assertSame([self::A], $this->raw($events),
            'PREMISE: the duplicate exists before boot, so this does not measure ordering.');

        $app->boot();

        $this->assertSame([self::A], $this->raw($events),
            'the duplicate registered from a LATER booted() callback survived, so the '
            . 'normalisation ran before the module providers rather than after them');
    }

    /** The declared scope is exactly one event, and that is a claim worth pinning. */
    public function test_the_scope_is_only_the_registered_event(): void
    {
        $this->assertSame([Registered::class], Normalise::EVENTS,
            'the normaliser has grown beyond the one event the framework auto-registers a listener '
            . 'for; that is the justification its docblock gives for the scope being complete '
            . 'rather than a sample, so widening it needs a new reason');
    }
}
