<?php

namespace App\Support\Marketing;

/**
 * A way of reaching a person. D8 / GAP-200 / MKT-008.
 *
 * ONE ENUMERATION, BECAUSE ENFORCEMENT IS PER CHANNEL. Laravel's `MessageSending` event covers
 * outbound MAIL only. An SMS sent through a gateway's own client, a WhatsApp message posted by
 * `Utility::SendMsgs()` and an FCM push never touch the mailer, so a consent store enforced at the
 * mail layer would leave three of the four channels open. Each channel therefore has its own
 * normalisation rule here and its own seam at the call sites, and `docs/development/phase-5/
 * PHASE-05-MKT-008-ENFORCEMENT-DESIGN.md` says which seam is which.
 */
enum Channel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Push = 'push';

    /** A channel addressed by telephone number, whose identity is NOT yet canonical (see ContactAddress and MarketingSendGate::mode()). */
    public function isPhoneNumber(): bool
    {
        return $this === self::Sms || $this === self::Whatsapp;
    }
}
