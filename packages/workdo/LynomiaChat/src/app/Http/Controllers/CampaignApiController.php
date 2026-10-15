<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Http\Controllers\Concerns\ProxiesToLynomia;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Services\AgentApiClient;
use Workdo\LynomiaChat\app\Models\LynomiaStoreAddon;
use Workdo\LynomiaChat\app\Services\MarketingAllowanceSync;
use Workdo\LynomiaChat\app\Services\MerchantInboxGateway;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * THE MERCHANT'S CAMPAIGNS: created here, sent there, and reported on from there.
 *
 * NOTHING ABOUT A CAMPAIGN IS STORED ON THIS SIDE, and of the three P5 surfaces this is the
 * one where it matters most. A campaign's status is the record of what was SENT. Lynomia owns
 * the send, the per-recipient snapshot and the outcome of every message; a "status" column
 * here would be a second account of whether a merchant's customers were messaged, and the two
 * would disagree the first time a run was interrupted -- which is exactly when somebody looks.
 *
 * THE PREVIEW IS THE SAME QUERY AS THE SEND, and that is not a convenience. A store-scoped
 * campaign resolves its audience against this installation's links and refuses every
 * recipient who has not agreed; a preview that skipped either would promise the merchant a
 * number their campaign cannot reach. So the preview asks the same endpoint the sender's own
 * audience resolution goes through, with this store's id.
 *
 * CANCELLING KEEPS EVERYTHING. It is a POST, not a DELETE, because deleting a campaign
 * destroys the recipient snapshot -- who was reached before it stopped -- which is the single
 * thing an operator cancelling a half-sent campaign needs afterwards.
 *
 * SCHEDULING IS A TIME, NOT A SCHEDULER. Lynomia's own five-minute sweep picks up a campaign
 * whose `scheduled_at` has passed. This surface sets that time and nothing else; a second
 * scheduler here would be a second thing that can fire a campaign.
 */
class CampaignApiController extends Controller
{
    use ProxiesToLynomia;

    private const RECIPIENTS_PER_PAGE = 25;

    /** The channels that have a one-off campaign sender upstream. */
    private const CAMPAIGN_CHANNELS = ['Channel::Whatsapp', 'Channel::Sms', 'Channel::TwilioSms'];

    public function __construct(private readonly MerchantInboxGateway $gateway)
    {
    }

    /**
     * The campaigns, and the channels one may be sent from.
     *
     * THE INBOX LIST IS NOT DECORATION. A campaign is bound to an inbox at creation and
     * upstream validates the campaign type against that inbox's channel, so the merchant
     * has to choose one -- and the page had no way to offer the choice. It sent
     * `inbox_id: 1` on every create, read from a `data-inbox` attribute that nothing ever
     * set, so a campaign either went to whichever inbox happened to be first in the
     * workspace or was refused outright.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) {
            $body = $client->listCampaigns();

            return [
                'campaigns' => $this->project($body['payload'] ?? $body),
                'inboxes' => $this->sendableInboxes($client),
            ];
        });
    }

    /**
     * The workspace's inboxes a one-off campaign can be sent from, which is the three
     * channels that have a campaign sender: WhatsApp, SMS and Twilio SMS. Filtered here
     * rather than in the page, because which channels can carry a campaign is a fact about
     * the platform and not about this screen.
     *
     * @return array<int, array{id: int, name: string, channel_type: string}>
     */
    private function sendableInboxes(AgentApiClient $client): array
    {
        $rows = $client->listInboxes();
        $rows = is_array($rows['payload'] ?? null) ? $rows['payload'] : (is_array($rows) ? $rows : []);

        return array_values(array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'channel_type' => (string) ($row['channel_type'] ?? ''),
        ], array_filter($rows, static fn ($row): bool => is_array($row)
            && in_array($row['channel_type'] ?? '', self::CAMPAIGN_CHANNELS, true))));
    }

    /**
     * THE CAMPAIGN IS MARKED AS THIS INSTALLATION'S WHEN IT IS CREATED, and that one field
     * is what makes the send behave like the preview.
     *
     * Upstream reads `commerce_store_id` in three places -- the audience's commerce
     * aggregates, the partner half of the send boundary, and the preview. This surface sent
     * it to the preview and not to the create, so a merchant was shown "40 of your
     * customers" and the campaign then resolved account-wide against all 180 and consulted
     * nobody's checkout opt-in. The number and the send have to be the same question.
     *
     * ON CREATE ONLY. Which installation a campaign belongs to decides whose consent
     * authorises it, so it is fixed when the campaign is made rather than movable afterwards.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCampaign($request);
        $storeId = (int) $request->attributes->get(EnsureStoreOwnsLink::STORE_ATTRIBUTE);

        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return $this->proxy($request, function (AgentApiClient $client) use ($validated, $storeId, $link) {
            $payload = $this->payload($validated)
                + array_filter(['commerce_store_id' => $this->scopedStoreId($client, $storeId, $link)]);

            return ['campaign' => $this->projectOne($client->createCampaign($payload))];
        });
    }

    /**
     * Editing a campaign that has not sent. Upstream refuses an edit to a completed one, and
     * that refusal is surfaced rather than pre-judged here: whether a campaign has finished
     * is a fact that can change between this request and the next.
     */
    public function update(Request $request, int $campaign): JsonResponse
    {
        $validated = $this->validateCampaign($request, identityFields: false);

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'campaign' => $this->projectOne($client->updateCampaign($campaign, $this->payload($validated))),
        ]);
    }

    public function cancel(Request $request, int $campaign): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'campaign' => $this->projectOne($client->cancelCampaign($campaign, $validated['reason'] ?? null)),
        ]);
    }

    public function destroy(Request $request, int $campaign): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($campaign) {
            $client->deleteCampaign($campaign);

            return ['deleted' => true];
        });
    }

    /**
     * What this campaign actually did, and who it did not reach.
     *
     * THE SKIPPED ROWS CARRY THEIR REASON, which is the half a delivery count cannot tell:
     * "40 of 180 sent" reads as a failure, while "140 skipped: no marketing consent" is an
     * answer the merchant can act on.
     */
    public function results(Request $request, int $campaign): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->proxy($request, function (AgentApiClient $client) use ($campaign, $validated) {
            $recipients = $client->campaignRecipients($campaign, array_filter([
                'status' => $validated['status'] ?? null,
                'page' => $validated['page'] ?? 1,
            ], static fn ($value) => $value !== null));

            $metrics = $client->campaignMetrics($campaign);

            return [
                'metrics' => $metrics,
                // THE BREAKDOWN THAT ANSWERS "WHY" (P5F). The counters say 40 of 180 were
                // sent, which reads as a failure; this says 140 were not sent because there
                // was no marketing consent on record, which is a different fact about a
                // working system and the one a merchant can act on. Ordered largest first,
                // because the reason that explains most of the gap is the one to read.
                'skip_reasons' => $this->projectSkipReasons($metrics['skip_reasons'] ?? []),
                'recipients' => $this->projectRecipients($recipients['payload'] ?? $recipients),
                'per_page' => self::RECIPIENTS_PER_PAGE,
            ];
        });
    }

    /**
     * HOW MANY PEOPLE THIS CAMPAIGN WOULD ACTUALLY REACH, before it is sent.
     *
     * THE SAME QUERY AS THE SEND, which is the whole value of it. Upstream's
     * `audience_preview` resolves the named labels and audiences exactly as the sender does
     * -- each contact once -- and then applies the consent filter, so the number already
     * excludes everyone who has not agreed, has withdrawn, is suppressed or is blocked. The
     * store id narrows the commerce aggregates to this installation's customers, as a
     * campaign from this store will.
     *
     * A number computed any other way would be larger and would be a promise the send cannot
     * keep, which is the failure a preview exists to prevent.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audience' => ['present', 'array', 'max:50'],
            'audience.*.type' => ['required', 'string', 'in:Label,Audience'],
            'audience.*.id' => ['required'],
            // The channel the campaign will send on. Optional, because the page previews as
            // soon as an audience is chosen and the merchant may not have picked one yet.
            'inbox_id' => ['nullable', 'integer', 'min:1'],
            // The template the campaign will send. Optional for the same reason the inbox
            // is -- the page previews as soon as an audience is chosen.
            'template_params' => ['nullable', 'array'],
        ]);
        $storeId = (int) $request->attributes->get(EnsureStoreOwnsLink::STORE_ATTRIBUTE);
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return $this->proxy($request, function (AgentApiClient $client) use ($validated, $storeId, $link) {
            // THE CHANNEL TRAVELS WITH THE PREVIEW. A store-scoped audience is scoped by it:
            // a partner grant taken for WhatsApp does not authorise an SMS blast, so a
            // preview without the inbox answered as SMS for every campaign and under-counted
            // every WhatsApp one.
            // AND SO DOES THE TEMPLATE. Its category decides the campaign's class, and a
            // UTILITY template is transactional -- so a preview that did not name it asked
            // for a marketing grant the send does not require and under-counted every
            // transactional campaign.
            $scoped = $client->previewAudience(
                $validated['audience'],
                // THE SAME RESOLVER AS `store()`. The bare lookup here made the preview
                // permissive while create was strict: in the registered-but-unavailable
                // state the page showed an ACCOUNT-WIDE count -- larger than the truth, and
                // including other stores' customers -- and then refused the save with 409.
                $this->scopedStoreId($client, $storeId, $link),
                isset($validated['inbox_id']) ? (int) $validated['inbox_id'] : null,
                $validated['template_params'] ?? null
            );

            return ['count' => (int) ($scoped['count'] ?? 0)];
        });
    }

    /**
     * WHAT THIS STORE MAY STILL SEND THIS PERIOD (P5G).
     *
     * Read from Lynomia, which is the enforcement authority: a number computed here from the
     * plan and the add-ons would be what Store Syria BELIEVES, and the two would differ the
     * moment an operator set an override on the Lynomia side. The merchant needs the number
     * that will actually refuse them.
     *
     * The add-on ledger beside it is this side's own and explains where the allowance came
     * from -- "2,000 included, 10,000 from a bundle that expires on the 30th" is an answer; a
     * bare 12,000 is not.
     */
    public function allowance(Request $request, MarketingAllowanceSync $marketing): JsonResponse
    {
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return $this->proxy($request, function (AgentApiClient $client) use ($link, $marketing) {
            $limits = $client->entitlements()['limits'] ?? [];
            $sends = is_array($limits['campaign_sends'] ?? null) ? $limits['campaign_sends'] : [];

            return [
                // nil limit upstream means unlimited; it is passed through as null and the
                // page says "no limit" rather than rendering a zero.
                'limit' => $sends['limit'] ?? null,
                'used' => (int) ($sends['used'] ?? 0),
                'included' => $marketing->includedSendsFor($link),
                'addons' => LynomiaStoreAddon::forStore($link->store_id)
                    ->current()
                    ->with('addon')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (LynomiaStoreAddon $grant) => [
                        'name' => $grant->addon?->name,
                        'quantity' => $grant->quantity,
                        'sends' => $grant->sends(),
                        // A trial is one of these with a date here, which is why the page
                        // does not need a separate idea of a trial.
                        'expires_at' => optional($grant->expires_at)->toIso8601String(),
                    ])->all(),
            ];
        });
    }

    // ============================================================ helpers

    /**
     * This store's LYNOMIA-side commerce store id, which is not its Store Syria id: Lynomia
     * knows this installation as a `commerce_stores` row whose `external_store_id` is the
     * Store Syria one. Matched on provider as well, so a merchant who also connected a
     * Shopify store does not have this campaign counted against it.
     */
    private function ownStoreId(AgentApiClient $client, int $storeId): ?int
    {
        foreach (($client->audienceFields()['stores'] ?? []) as $store) {
            if (($store['provider'] ?? null) === 'storesyria' && (string) ($store['external_store_id'] ?? '') === StoreIdentity::of($storeId)) {
                $id = (int) $store['id'];
                // REMEMBERED THE FIRST TIME WE SEE IT. `audience_fields` lists only COUNTED
                // stores, so an installation that later goes to needs_reauth simply vanishes
                // from it -- and "vanished" and "never existed" are the same absence unless
                // we wrote down that we once saw it. See `scopedStoreId`.
                LynomiaStoreLink::where('store_id', $storeId)
                    ->whereNull('lynomia_commerce_store_id')
                    ->update(['lynomia_commerce_store_id' => $id]);

                return $id;
            }
        }

        return null;
    }

    /**
     * A MISSING INSTALLATION IS EITHER "THERE ISN'T ONE" OR "IT IS NOT USABLE RIGHT NOW",
     * AND ONLY THE FIRST MAY WIDEN THE CAMPAIGN.
     *
     * A campaign created without `commerce_store_id` resolves ACCOUNT-WIDE upstream: the
     * native consent path instead of the partner one, so this store's checkout opt-ins
     * authorise nothing and, in an account with more than one connected store, the campaign
     * addresses other stores' customers. That is the right answer for a merchant who has no
     * commerce installation at all -- they still have labels to send to -- and the wrong one
     * for a merchant whose installation exists and is merely unavailable. This dropped a
     * null for both, with a 200 and a campaign that looks right either way.
     *
     * The list searched is Lynomia's `counted_stores`, which is narrower than "connected": a
     * rejected token flips the installation to `needs_reauth` unattended, an operator can
     * disable it, and a Super Admin can switch the provider off installation-wide.
     *
     * Registered but not counted is therefore an operational state, not a deployment bug, so
     * the answer is a sentence the merchant can act on. 409 is what this surface already
     * returns for "connected, but not in a state where this can be done"
     * (ProxiesToLynomia::statusFor), and KIND_CONFLICT is the one kind whose own message is
     * shown to the merchant.
     *
     * THE TWO ARE TOLD APART BY WHAT WE HAVE SEEN, not by what we have configured. The first
     * version of this read `commerce_credential_issued_at`, which was wrong both ways round:
     * that column means a Store Syria operator minted a token, which happens BEFORE
     * registration is possible -- the token is the input to it -- and is cleared on revoke
     * while Lynomia still holds the installation. It therefore refused a merchant during
     * onboarding, before anything existed upstream, and silently widened their campaigns
     * after a revoke, when something did. `lynomia_commerce_store_id` is Lynomia's own
     * answer, recorded the first time this store appeared in `audience_fields`.
     */
    private function scopedStoreId(AgentApiClient $client, int $storeId, ?LynomiaStoreLink $link): ?int
    {
        $id = $this->ownStoreId($client, $storeId);

        if ($id === null && $link?->fresh()?->lynomia_commerce_store_id !== null) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                __('This store is not currently available for campaigns in Lynomia Chat. Reconnect it from the Lynomia Chat page and try again.')
            );
        }

        return $id;
    }

    /**
     * @return array{title: string, message: string, inbox_id?: int, audience?: array, scheduled_at?: string, template_params?: array}
     */
    private function validateCampaign(Request $request, bool $identityFields = true): array
    {
        $rules = [
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'message' => ['required', 'string', 'min:1', 'max:10000'],
            // An audience entry is {type, id}; `present` rather than `required`, because a
            // campaign may legitimately be saved with none yet while it is being drafted.
            'audience' => ['present', 'array', 'max:50'],
            'audience.*.type' => ['required', 'string', 'in:Label,Audience'],
            'audience.*.id' => ['required'],
            'scheduled_at' => ['nullable', 'date'],
            'template_params' => ['nullable', 'array'],
        ];

        if ($identityFields) {
            // The channel a campaign sends on cannot change after creation: upstream binds
            // the campaign to an inbox and validates the type against it.
            $rules['inbox_id'] = ['required', 'integer', 'min:1'];
        }

        return $request->validate($rules);
    }

    private function payload(array $validated): array
    {
        return array_filter([
            'title' => $validated['title'],
            'message' => $validated['message'],
            'inbox_id' => $validated['inbox_id'] ?? null,
            'audience' => $validated['audience'] ?? [],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'template_params' => $validated['template_params'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    private function project($rows): array
    {
        return array_values(array_map(fn (array $row): array => $this->projectOne($row),
            array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }

    private function projectOne(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            // What the cancel and results routes address: upstream looks a campaign up by
            // display_id, which is per account and is what the merchant sees.
            'display_id' => $row['display_id'] ?? ($row['id'] ?? null),
            'title' => (string) ($row['title'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            // active | completed | processing | blocked | cancelled
            'campaign_status' => $row['campaign_status'] ?? null,
            // Why it is not sending, when something stopped it: the capability was off, or
            // an operator cancelled it and left a note.
            'blocked_reason' => $row['blocked_reason'] ?? null,
            'campaign_type' => $row['campaign_type'] ?? null,
            'scheduled_at' => $row['scheduled_at'] ?? null,
            'audience' => is_array($row['audience'] ?? null) ? $row['audience'] : [],
            'inbox' => is_array($row['inbox'] ?? null) ? $row['inbox'] : null,
            'template_params' => is_array($row['template_params'] ?? null) ? $row['template_params'] : null,
        ];
    }

    /** One row per addressed contact: what happened, and -- when nothing did -- why not. */
    private function projectRecipients($rows): array
    {
        return array_values(array_map(static function (array $row): array {
            // NESTED, because that is where upstream puts it: the row carries
            // `contact: {id, name, phone_number}`. Read as a top-level `contact_name` it was
            // always blank, so the results table listed a column of empty names beside the
            // outcomes they belonged to.
            $contact = is_array($row['contact'] ?? null) ? $row['contact'] : [];

            return [
                'contact_name' => (string) ($contact['name'] ?? ''),
                'status' => $row['status'] ?? null,
                // The reason, which is the half a delivery count cannot tell -- read
                // message, then title, then code, which is the order upstream documents and
                // the order in which any one of the three is a usable sentence. Upstream
                // keeps these as fixed strings carrying no contact data, so they are safe
                // to show.
                'reason' => $row['error_message'] ?? ($row['error_title'] ?? ($row['error_code'] ?? null)),
            ];
        }, array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }

    /**
     * @return array<int, array{reason: string, count: int}>
     *
     * Reshaped from a map into a list so the order survives JSON: a merchant reads the
     * biggest reason first, and an object's key order is not something to rely on.
     */
    private function projectSkipReasons($reasons): array
    {
        if (! is_array($reasons)) {
            return [];
        }

        $rows = [];
        foreach ($reasons as $reason => $count) {
            $rows[] = ['reason' => (string) $reason, 'count' => (int) $count];
        }

        return $rows;
    }

    private function merchantMessage(LynomiaApiException $e): string
    {
        return match ($e->kind) {
            LynomiaApiException::KIND_DISABLED,
            LynomiaApiException::KIND_NOT_CONFIGURED => __('Lynomia Chat is not available yet. Please contact support.'),
            LynomiaApiException::KIND_CONFLICT => $e->getMessage(),
            LynomiaApiException::KIND_UNAUTHORIZED,
            LynomiaApiException::KIND_NOT_PERMISSIBLE => __('Only the store owner can manage campaigns.'),
            LynomiaApiException::KIND_NOT_FOUND => __('That campaign no longer exists.'),
            LynomiaApiException::KIND_RATE_LIMITED,
            LynomiaApiException::KIND_UNREACHABLE => __('Lynomia Chat is temporarily unreachable. Please try again shortly.'),
            default => __('Lynomia Chat could not complete that request. Please contact support.'),
        };
    }
}
