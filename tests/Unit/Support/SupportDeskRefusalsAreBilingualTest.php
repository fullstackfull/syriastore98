<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

/**
 * EVERY SENTENCE THE REWORKED SUPPORT DOORS CAN SAY RENDERS IN ARABIC.
 * =================================================================================================
 *
 * The GAP-176 change reuses the strings already at these doors rather than minting new ones, which is
 * deliberate -- `SupportTicketController::conversion_store()`'s new tenant refusal says exactly what
 * its three already-confined siblings say, so a merchant cannot tell from the wording which rule
 * refused them, and the three support permissions carry no new vocabulary. That reuse is only safe if
 * the strings really are translated, so the set is asserted here through the TRANSLATOR rather than by
 * reading a file: `__()` hands back the key when `ar.json` has no entry, so an Arabic merchant would
 * be shown the English sentence while a one-directional file census stayed green.
 *
 * The expected Arabic is pinned as well as its mere presence. A key whose Arabic value is a copy of
 * the English is indistinguishable from a missing one at the call site, and
 * `ArabicInventoryRatchetTest` counts such rows for the whole file without saying which ones matter.
 */
class SupportDeskRefusalsAreBilingualTest extends TestCase
{
    /**
     * The sentences the changed handlers produce.
     *
     * `Permission denied.` -- `conversion_store()`'s new empty-tenant refusal, and the one its
     * siblings already use. `Ticket not found` -- the shopper's refusal from `ownedTicket()`, which
     * the bounded list makes reachable for a row the list no longer offers. The other three are the
     * success and refusal sentences on the two doors this change rewrote.
     */
    private const SENTENCES = [
        'Permission denied.' => 'تم رفض الإذن',
        'Ticket not found' => 'لم يُعثر على التذكرة',
        'Reply added successfully' => 'الإجابة تم إضافتها بنجاح',
        'Ticket successfully created.' => 'تم إنشاء التذكرة بنجاح',
        'Order not found.' => 'طلب غير موجود',

        /*
         * THE ONE NEW SENTENCE IN THIS FAMILY, and the reason it is a new sentence rather than a
         * reuse. `SUPPORT-004`'s enforced refusal at `StoreController::LoginWithAdmin` must tell
         * the operator what to DO -- ask the merchant for consent -- and none of the five above
         * says that. "Permission denied." would have been a reuse and a lie: the operator HAS the
         * permission; what is missing is the merchant's consent, and an operator who reads
         * "permission denied" will go looking for a role to grant themselves.
         *
         * It is an OPERATOR-facing string on a platform-admin screen, and it is translated anyway:
         * the platform operator's own locale can be Arabic, and CLAUDE.md requires any UI change to
         * be checked in both directions.
         */
        'Support access to that merchant needs their consent. Request access first.'
            => 'يتطلب وصول الدعم إلى بيانات هذا التاجر موافقته. اطلب الوصول أولاً.',
    ];

    public function test_every_sentence_the_changed_support_doors_say_renders_in_arabic(): void
    {
        app()->setLocale('ar');

        foreach (self::SENTENCES as $english => $arabic) {
            $resolved = __($english);

            $this->assertNotSame($english, $resolved,
                'The Arabic support desk prints "' . $english . '" in English, because '
                . 'resources/lang/ar.json has no entry for it.');
            $this->assertSame($arabic, $resolved,
                'The Arabic sentence for "' . $english . '" changed.');
        }
    }

    /** And the English side resolves to itself rather than to a missing-key echo of a renamed key. */
    public function test_every_sentence_is_declared_on_the_english_side_too(): void
    {
        app()->setLocale('en');

        foreach (array_keys(self::SENTENCES) as $english) {
            $this->assertSame($english, __($english),
                'The English support desk no longer resolves "' . $english . '", so the key was '
                . 'renamed and the Arabic row above is now orphaned.');
        }
    }
}
