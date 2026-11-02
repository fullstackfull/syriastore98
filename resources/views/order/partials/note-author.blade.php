{{--
    P2-04-6 / WS E — THE AUTHOR OF ONE ORDER NOTE, or nothing at all.

    Included by every branch of the notes list, which is deliberate and is what makes the rule
    self-consistent: **a note shows an author if and only if one was recorded.** No branch needs
    to know whether it is a system note or a typed one — a system note has no `created_by`, so
    this partial renders nothing for it without being told.

    THE THREE CASES IT RENDERS NOTHING FOR, all real:
      1. `created_by` is NULL — the note predates the column, or it is a SYSTEM note
         (`OrderNote::order_note_data()` composes the text from event data at 30+ call sites,
         most inside payment-gateway callbacks where no user is authenticated), or it is one of
         the two generated "Order created" notes.
      2. the recorded user no longer exists — no foreign key was added, deliberately, because a
         CASCADE would delete a merchant's commercial records to tidy up a user row.
      3. the recorded user is outside the viewer's tenant — including a note written by the
         platform operator through the cross-store escape hatch, whose identity is not a
         merchant's business.

    CASES 1 AND 2 LOOK THE SAME ON SCREEN, AND THAT IS A STATED LIMIT rather than an oversight:
    "no author was recorded" and "an author was recorded whom we cannot name" are different
    facts, and this renders neither as the other. Showing nothing is never false; showing
    "unknown employee" for case 1 would be.

    The predecessor of this partial was `{{ __(' by ') }}{{ Auth::user()->name }}` — the name of
    whoever was READING the page. `OrderDetailTest::test_a_note_is_not_attributed_to_the_person_reading_the_page`
    is the standing guard against its return, and it keeps passing: this names the AUTHOR.
--}}
@php($ssNoteAuthor = $note->authorNameFor(auth()->user()))

@if ($ssNoteAuthor !== null)
    {{-- `class="time"`, THE SAME CLASS AS THE TIMESTAMP BESIDE IT, and not a new one.
         The author and the timestamp are the same kind of thing -- note metadata -- and the
         theme already styles `.time` for exactly this slot in all four directions, dark mode
         included. A new `.ss-note-author` would have to re-establish that contrast from
         scratch, and the only measurement that counts for it is in the running application;
         F8's audit found 14 of 32 dark-mode readings below AA, so an unmeasured new class here
         would be a guess. Reusing the one already verified is the smaller claim. --}}
    <span class="time">{{ __('by') }} {{ $ssNoteAuthor }}</span>
@endif
