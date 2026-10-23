<?php

namespace App\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\State;

/**
 * RESOLVING A POSTED CITY TO A `cities.id`, WITHOUT EVER WRITING A REQUEST VALUE INTO A FOREIGN KEY.
 * =================================================================================================
 *
 * P1-ORD-15. Twenty-four order writers held the same eight-line block: look a city up, and if it is
 * not found insert one with
 *
 *     $new_billing_city->state_id   = $billing['billing_state'];
 *     $new_billing_city->country_id = $billing['billing_country'];
 *
 * `cities.state_id` and `cities.country_id` are FOREIGN KEYS (nullable, cascade, to `states.id` and
 * `countries.id`). Whatever the request carried went straight into them. On SQLite the insert is
 * refused outright -- `SQLSTATE[23000] FOREIGN KEY constraint failed` -- and the handler 500s AFTER
 * `$order->save()`, so the shopper is charged and told nothing. The row's own measurement of the
 * MySQL side is restated and re-measured by
 * `tests/Support/Security/bin/verify-ord15-billing-geography-on-mysql.php` rather than inherited.
 *
 * WHAT THE CLIENT ACTUALLY POSTS, WHICH IS THE QUESTION THE ROW SAID TO SETTLE FIRST, AND THE
 * ANSWER IS IDS -- NOT NAMES. Established by reading both repositories, not assumed:
 *
 *   1. REPO B (`StoreSyria_app98`, the shipped admin app) NEVER SENDS THESE FIELDS. `billing_state`
 *      and `billing_country` do not occur in any of its 198 Dart files, and it has no place-order
 *      call at all. `state` and `country` appear only in RESPONSE models
 *      (`CustomerBillingInformation.fromJson`, `BillingInformations.fromJson`), rendered as display
 *      strings. So no shipped client constrains the input shape, and no client breaks either way.
 *   2. THE STOREFRONT POSTS IDS. `billing_info[billing_country]` is a select built from
 *      `Country::pluck('name', 'id')` (AccountProfileController:466, :1601) -- option VALUE is the
 *      country id. `billing_info[billing_state]` is filled by `states_list`, which is
 *      `State::...->pluck('name', 'id')` (:251), and the JS writes the response KEY as the option
 *      value (front-theme.js:1012-1014). `billing_city` likewise, from `city_list`.
 *      `addressbook_checkout_edit` pre-selects `$DeliveryAddress->country_id`.
 *   3. THE CODEBASE ALREADY TREATS THEM AS IDS IN THE PLACES THAT MATTER MOST. The
 *      `delivery_addresses` writer wrote `$address->state_id = $billing['billing_state']` -- the
 *      same request key, straight into an id column -- and four of the twenty-four sites already
 *      looked the city up by `id` rather than by `name` (PaymentController and PaymentService).
 *      LINE NUMBERS ARE DELIBERATELY OMITTED FROM THIS LIST: they were pre-change positions, this
 *      change moved them, and a stale `file:line` in a docblock is the census-failure mode the
 *      paragraph below is written to prevent.
 *   4. THE DECIDING EVIDENCE: `order_billing_details.country`, `.state` and `.city` are STRING
 *      columns that hold IDS. `OrderBillingDetail::BillingState()` is
 *      `hasOne(State::class, 'id', 'state')` (:38-41) and `Order::order_detail()` renders
 *      `$OrderBillingDetail->BillingState->name` (Order.php:412-414). The whole pipeline is
 *      id-based and only the display end resolves names.
 *
 * SO THE ROW'S OWN DIAGNOSIS WAS HALF RIGHT, AND THE HALF IT GOT WRONG IS THE LIVE SYMPTOM. It read
 * `billing_state` as a NAME because `billing_city` one line above is read as a name. The posted
 * values are ids; what is wrong is the LOOKUP. At the twenty name-keyed sites
 * `City::where('name', <a city id>)` can never match, so EVERY order through them inserts a fresh
 * `cities` row whose NAME is a number, and `order_billing_details.city` then points at that junk
 * row -- which is why a merchant's order detail shows a number where the city should be, since
 * `BillingCity->name` resolves to it. No foreign key is violated on that path (the state and
 * country values ARE valid ids), which is exactly why it has been accumulating silently instead of
 * erroring.
 *
 * THE FOREIGN-KEY FAILURE IS STILL REAL, for any caller that posts names: the storefront API takes
 * `billing_info` from request input with no validation of these three fields, and
 * `Api\ApiController::place_order` is a public route. Both halves are closed here.
 *
 * WHAT THIS DOES, IN ORDER, AND WHY EACH STEP EXISTS:
 *
 *   1. ABSENT -> 0, unchanged. Every caller already treats 0 as "no city".
 *   2. A VALID `cities.id` -> that id. This is the live storefront's shape and it now takes a
 *      lookup branch instead of an insert branch, which is the whole of acceptance criterion 3.
 *   3. A NAME -> the id of the city of that name, preferring one in the resolved state so that two
 *      cities sharing a name in different states cannot be confused. Acceptance criterion 2.
 *   4. NEITHER -> insert, with `state_id` and `country_id` RESOLVED to real ids, or NULL when they
 *      cannot be. A NULL is honest and the column is nullable; a raw posted value is a foreign-key
 *      violation and a zero is a row pointing at a country that does not exist. This is the one
 *      behaviour the row describes as unacceptable, and it is the only line that had to change for
 *      it.
 *
 * WHY A SERVICE AND NOT TWENTY-FOUR EDITS. The block appears in ten host-app files, identically in
 * eight of them, and two of the ten had already drifted (the `id` lookup above). A second copy of a
 * money-path control is the mistake this project has already recorded for Paymera's two unrouted
 * copies, and `ProductMediaReclaimer` set the precedent in P1-INV-25: extract, then ratchet the
 * extraction so a twenty-fifth copy cannot appear unseen
 * (`tests/Feature/Commerce/BillingGeographyResolutionTest::test_no_order_writer_assigns_a_request_value_to_a_city_foreign_key`).
 *
 * WHICH `order_billing_details` WRITERS WERE CONVERTED, AND WHICH WERE NOT -- the asymmetry is
 * deliberate and this paragraph exists because an earlier draft of it claimed the opposite and
 * contradicted the code two paragraphs below. Of the TWELVE writers of those columns, TWO were
 * converted: `Api\ApiController`'s, at both order writers, because that is the PUBLIC API where a
 * posted NAME is reachable and where `place_order` was additionally discarding the city id it had
 * just resolved. The other TEN -- PaymentController x2, PaymentService, and one each in the seven
 * gateway controllers -- still assign `$billing['billing_state']` and `$billing['billing_country']`
 * verbatim. They are fed by the storefront form, which posts ids, so resolving there is a no-op
 * for every shipped caller and changing ten money-path writes without a measured defect is churn.
 *
 * WHAT THAT LEAVES OPEN, stated rather than implied: an anonymous POST to `{storeSlug}/process-order`
 * (routes/customer.php:182, outside the `auth:customers` group) carrying
 * `billing_info[billing_country]=Syria` still stores the literal name in an id-joined column, and
 * the merchant's order detail then shows a blank country. It is a correctness edge, not a security
 * one -- those two columns carry no foreign key and no tenant meaning -- and no shipped client
 * produces it. The assignment ratchet in `BillingGeographyResolutionTest` does NOT cover it: it
 * matches `->state_id` / `->country_id` / `->city_id`, and these are `->state` / `->country`.
 *
 * `cities` is a PLATFORM-GLOBAL table (no `store_id`, not in `config/tenancy.php`), so none of this
 * is tenant data and no store predicate belongs here.
 */
final class BillingGeography
{
    /**
     * The `cities.id` for a posted city, inserting one only when neither an id nor a name matches.
     *
     * @param  mixed  $city     the posted city: an id (every shipped client) or a name
     * @param  mixed  $state    the posted state: an id or a name
     * @param  mixed  $country  the posted country: an id or a name
     * @return int  the city id, or 0 when no city was posted
     */
    public static function resolveCityId($city, $state, $country): int
    {
        return self::resolveBilling($city, $state, $country)['city_id'];
    }

    /**
     * The same resolution, returning the state and country ids as well.
     *
     * `Api\ApiController::place_order` needs all three, because it writes
     * `order_billing_details.city`, `.state` and `.country` -- STRING columns that
     * `OrderBillingDetail`'s relations join to `cities.id`, `states.id` and `countries.id`, and
     * which the SHIPPED ADMIN APP renders through exactly those relations
     * (`/api/admin/vieworder` -> `DashboardController::ViewOrder` -> `Order::order_detail()` ->
     * `$OrderBillingDetail->BillingState->name`). So a NAME stored in those columns makes the
     * merchant's order detail show BLANK fields, while the resolved id shows the name. That is why
     * resolving them is required by the client contract rather than a tidy-up.
     *
     * @param  mixed  $city
     * @param  mixed  $state
     * @param  mixed  $country
     * @return array{city_id: int, state_id: int|null, country_id: int|null}
     */
    public static function resolveBilling($city, $state, $country): array
    {
        $countryId = self::countryId($country);
        $stateId = self::stateId($state, $countryId);

        if ($countryId === null && $stateId !== null) {
            // A resolved state knows its own country, and using it is strictly better than a NULL.
            $countryId = self::asId(State::where('id', $stateId)->value('country_id'));
        }

        return [
            'city_id' => self::cityId($city, $stateId, $countryId),
            'state_id' => $stateId,
            'country_id' => $countryId,
        ];
    }

    /**
     * The city id, creating the city when neither an id nor a name matches.
     *
     * @param  mixed  $city
     */
    private static function cityId($city, ?int $stateId, ?int $countryId): int
    {
        if (! self::present($city)) {
            return 0;
        }

        /*
         * AN INTEGER SPELLING IS AN ID OR IT IS NOTHING -- it never becomes a city NAME. Without
         * this second limb a posted id that matches no row (a city deleted since the page was
         * rendered, a stale client cache, a hand-written request) would be inserted as a city
         * CALLED '999', which is defect (a) of this task reappearing through the branch that is
         * supposed to close it. 0 means "no city", which every caller already handles and which
         * renders as an empty field rather than as a number.
         *
         * `filter_var` is asked directly here rather than through `asId()`, because the question is
         * different: `asId()` asks "is this a usable id", and this asks "did the caller write an
         * integer at all" -- so '-1' and '0' are caught too. A leading-zero spelling ('03') is the
         * one remainder: FILTER_VALIDATE_INT refuses it, as this project has already recorded, so
         * it still reaches the name branch.
         */
        $looksLikeAnId = is_scalar($city) && filter_var(trim((string) $city), FILTER_VALIDATE_INT) !== false;

        if ($looksLikeAnId) {
            $id = self::asId($city);

            return ($id !== null && City::where('id', $id)->exists()) ? $id : 0;
        }

        $name = self::name($city);

        /*
         * NARROWED BY STATE, AND BY COUNTRY WHEN THE STATE DID NOT RESOLVE. The second limb is the
         * same reasoning `stateId()` already applies one level up: an unnarrowed name match returns
         * whichever row was inserted first, globally, so a posted country of 101 with an
         * unresolvable state would have matched a same-named city anywhere on earth. No tenancy
         * consequence -- `cities` is platform-global -- but it files an order under the wrong
         * geography, which is this task's subject.
         */
        $existing = City::where('name', $name)
            ->when($stateId !== null, fn ($query) => $query->where('state_id', $stateId))
            ->when($stateId === null && $countryId !== null, fn ($query) => $query->where('country_id', $countryId))
            ->first();

        if (! empty($existing)) {
            return (int) $existing->id;
        }

        $new = new City();
        $new->name = $name;
        $new->state_id = $stateId;
        $new->country_id = $countryId;
        $new->save();

        return (int) $new->id;
    }

    /**
     * The same three values as ids, WITHOUT creating anything -- for `delivery_addresses`, whose
     * `country_id`, `state_id` and `city_id` are foreign keys too (:30-33 of its migration) and
     * which `PaymentController::handleCustomerRegistration()` wrote straight from request input
     * (:1352-1354). A caller posting names made that write a foreign-key violation IN CHECKOUT, on
     * the registration path, which is the same defect as the city insert and found by the same
     * census. Creating a city for an ADDRESS would be wrong -- an address records where someone
     * lives, it is not an authority on the geography table -- so an unresolvable value is NULL,
     * exactly as the nullable columns allow.
     *
     * @param  mixed  $city
     * @param  mixed  $state
     * @param  mixed  $country
     * @return array{city_id: int|null, state_id: int|null, country_id: int|null}
     */
    public static function resolveIds($city, $state, $country): array
    {
        $resolved = self::resolveBilling(null, $state, $country);
        $stateId = $resolved['state_id'];
        $countryId = $resolved['country_id'];

        $cityId = null;

        if (self::present($city)) {
            $id = self::asId($city);

            if ($id !== null && City::where('id', $id)->exists()) {
                $cityId = $id;
            } else {
                $cityId = self::asId(
                    City::where('name', self::name($city))
                        ->when($stateId !== null, fn ($query) => $query->where('state_id', $stateId))
                        ->value('id')
                );
            }
        }

        return ['city_id' => $cityId, 'state_id' => $stateId, 'country_id' => $countryId];
    }

    /**
     * A `states.id` for a posted state, or null.
     *
     * The country is passed so that a posted state NAME resolves within the posted country: 'Idlib'
     * is unambiguous, plenty of state names are not, and picking the first row of a global
     * name match would silently file an order under another country's state.
     *
     * @param  mixed  $state
     * @param  int|null  $countryId
     */
    private static function stateId($state, $countryId): ?int
    {
        if (! self::present($state)) {
            return null;
        }

        $id = self::asId($state);

        if ($id !== null && State::where('id', $id)->exists()) {
            return $id;
        }

        $byName = State::where('name', self::name($state))
            ->when($countryId !== null, fn ($query) => $query->where('country_id', $countryId))
            ->value('id');

        return self::asId($byName);
    }

    /**
     * A `countries.id` for a posted country, or null.
     *
     * @param  mixed  $country
     */
    private static function countryId($country): ?int
    {
        if (! self::present($country)) {
            return null;
        }

        $id = self::asId($country);

        if ($id !== null && Country::where('id', $id)->exists()) {
            return $id;
        }

        return self::asId(Country::where('name', self::name($country))->value('id'));
    }

    /**
     * A positive integer, or null.
     *
     * FILTER_VALIDATE_INT rather than `is_numeric`, which is the project's recorded rule: is_numeric
     * accepts '5.9' and rejects '5abc', while MySQL coerces '5abc' to 5 in a `WHERE id = ?`
     * comparison -- so is_numeric and the database disagree about the same two strings.
     *
     * @param  mixed  $value
     */
    private static function asId($value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $id = filter_var(trim((string) $value), FILTER_VALIDATE_INT);

        return ($id === false || $id <= 0) ? null : $id;
    }

    /** @param  mixed  $value */
    private static function present($value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '' && trim((string) $value) !== '0';
    }

    /**
     * The name to store or match on. `cities.name` is a VARCHAR(255), so a longer value would be
     * truncated by MySQL (silently, on this non-strict connection) or refused.
     *
     * @param  mixed  $value
     */
    private static function name($value): string
    {
        return mb_substr(trim((string) $value), 0, 255);
    }
}
