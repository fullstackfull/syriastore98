<?php

namespace App\Services;

use App\Models\User;

/**
 * May this user read or change a store's gateway, SMTP and integration settings?  P1-SEC-18 F5.
 *
 * One decision point, used by the admin API's secret-bearing handlers (Api\DashboardController::EmailSetting, GetEmailSetting,
 * StripePayment, GetStripePayment, TestMail) and by their web twins (SettingController::settingForm, getEmailSettingFields,
 * saveEmailSettings, PaymentSettingController::update), so the two doors cannot drift apart.
 *
 * WHAT IT ASKS, IN THIS ORDER
 *
 *  1. The platform operator (`users.type` is 'super admin'). First, because Laratrust here has no super-admin bypass
 *     (`permissions_as_gates` is off): `isAbleTo()` alone REFUSES the operator. The comparison is the normalised one every
 *     other operator check uses (PlatformOperatorOnly, RolePolicy, resolveOwnedStore): for the canonical spelling limb 2 admits
 *     the operator as well, because creatorId() returns a 'super admin' their own id, but creatorId() compares EXACTLY, so a
 *     non-canonical spelling (' Super Admin ') is the operator here and everywhere else and only this limb says so -- pinned by
 *     AdminApiSettingsSecretsTest. (A tenant cannot set such a type: the reserved-name rules normalise the same way.)
 *  2. The tenant OWNER: `creatorId() === id`. creatorId() returns the user's own id for exactly the types `admin` and
 *     `super admin`, and `created_by` for everyone else, so this is true only for a merchant (staff resolve to their
 *     employer). It is deliberately the application's own definition of "root of a tenant" and not a re-implemented string
 *     comparison: a staff row whose type merely NORMALISES to 'admin' ('Admin', ' admin') is a staff member for creatorId() and
 *     for this gate. An owner is entitled to their own store's credentials whatever their role rows say, so a merchant whose
 *     `admin` role was never seeded with the permission is not locked out of the shipped app.
 *  3. `isAbleTo('Manage Setting')` -- the permission behind the "Settings" menu entry (CompanyMenuListener:954-965, route
 *     setting.index), where the email and payment tabs live. NOT `Manage Store Setting`: that belongs to the separate "Store
 *     Setting" entry (route app-setting.index), and a role holding only it must not be handed the SMTP password.
 *
 * The question this exists for is STAFF: resolveOwnedStore() and getCurrentStore() resolve a staff member to their employer,
 * so an ownership check admits them whatever role the merchant gave them.
 */
final class StoreSettingsAccess
{
    public const PERMISSION = 'Manage Setting';

    public static function permits(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (strtolower(trim((string) $user->type)) === 'super admin') {
            return true;
        }

        if ((int) $user->creatorId() === (int) $user->id) {
            return true;
        }

        return $user->isAbleTo(self::PERMISSION);
    }
}
