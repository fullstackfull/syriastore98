<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Controllers\Concerns\ProxiesToLynomia;
use Workdo\LynomiaChat\app\Services\AgentApiClient;
use Workdo\LynomiaChat\app\Services\MerchantInboxGateway;

/**
 * THE MERCHANT'S WHATSAPP TEMPLATES, MANAGED HERE AND OWNED BY META.
 *
 * THREE PLACES HOLD A TEMPLATE'S STATE AND ONLY ONE IS AUTHORITATIVE. Meta decides whether a
 * template is approved, rejected, paused or flagged, and moves it without telling anyone
 * first. Lynomia mirrors that and records WHEN it last looked. This page shows the mirror and
 * says how old it is. A third copy in Store Syria's database would be the stalest of the
 * three and would be the one a merchant read before sending a campaign on a template Meta had
 * already paused -- so nothing about a template is stored on this side.
 *
 * WHAT MAY BE DONE IS NOT DECIDED HERE EITHER. Meta's rules about which transitions are legal
 * from which state are genuinely intricate -- a rejected template may be edited and
 * resubmitted, an approved one may be edited only in certain ways, a paused one may not be
 * sent on at all -- and Lynomia already derives them per template as `allowed_actions`, and
 * ENFORCES them on every endpoint. This surface renders what it is told and disables the
 * rest. A second copy of those rules here would be a copy that falls behind Meta's, and the
 * action it wrongly offered would cost the merchant a review cycle.
 *
 * `validation_problems` IS NOT A PROMISE. It is what Meta's published rules say is wrong
 * before a submission is spent. Empty means nothing publicly documented is wrong, not that
 * Meta will approve it; the page says so, because a merchant who reads "no problems" as
 * "will be approved" learns otherwise a day later.
 *
 * TENANCY AND AUTHORIZATION COME FROM THE ROUTE. The credential is minted for this workspace
 * only and no path names one. Upstream requires an account ADMINISTRATOR for every template
 * action and only the store owner holds that identity, so a staff member who passes the Store
 * Syria permission is still refused there -- which is why the refusal says who to ask.
 */
class TemplateApiController extends Controller
{
    use ProxiesToLynomia;

    public function __construct(private readonly MerchantInboxGateway $gateway)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) {
            $body = $client->listMessageTemplates();

            return [
                'templates' => $this->project($body['payload'] ?? $body),
                // Which WABA each inbox belongs to and when its mirror was last refreshed.
                // Rendered as "last checked N minutes ago", because a status with no age is a
                // status a merchant cannot weigh.
                'waba_contexts' => is_array($body['waba_contexts'] ?? null) ? $body['waba_contexts'] : [],
            ];
        });
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateDraft($request);

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'template' => $this->projectOne($client->createMessageTemplate($validated)),
        ]);
    }

    /**
     * Name, language and category are omitted deliberately: upstream refuses to change them
     * after creation, because at Meta they are part of a template's identity rather than its
     * content. Sending them would be asking for a refusal the merchant cannot act on.
     */
    public function update(Request $request, int $template): JsonResponse
    {
        $validated = $this->validateDraft($request, identityFields: false);

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'template' => $this->projectOne($client->updateMessageTemplate($template, $validated)),
        ]);
    }

    public function destroy(Request $request, int $template): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($template) {
            $client->deleteMessageTemplate($template);

            return ['deleted' => true];
        });
    }

    /** Hand a draft to Meta. Whether this template may be submitted is upstream's answer. */
    public function submit(Request $request, int $template): JsonResponse
    {
        return $this->proxy($request, fn (AgentApiClient $client) => [
            'template' => $this->projectOne($client->submitMessageTemplate($template)),
        ]);
    }

    /**
     * Copy an existing template into a new local draft.
     *
     * The usual way to change an approved template: Meta treats a name and language as an
     * identity, so "edit the approved one" and "make a new one like it" are different acts
     * with different consequences, and a merchant reworking copy wants the second.
     */
    public function duplicate(Request $request, int $template): JsonResponse
    {
        return $this->proxy($request, fn (AgentApiClient $client) => [
            'template' => $this->projectOne($client->duplicateMessageTemplate($template)),
        ]);
    }

    // ============================================================ helpers

    /**
     * Shape and size only. WHICH categories exist, which languages Meta accepts and what a
     * component may contain are Meta's rules, mirrored by Lynomia's validator, and restating
     * them here would be a second rulebook falling behind the first.
     */
    private function validateDraft(Request $request, bool $identityFields = true): array
    {
        $rules = [
            'components' => ['required', 'array', 'min:1', 'max:10'],
            'parameter_format' => ['nullable', 'string', 'max:40'],
        ];

        if ($identityFields) {
            $rules += [
                'inbox_id' => ['required', 'integer', 'min:1'],
                'name' => ['required', 'string', 'min:1', 'max:512'],
                'language' => ['required', 'string', 'max:20'],
                'category' => ['required', 'string', 'max:40'],
            ];
        }

        return $request->validate($rules);
    }

    private function project($rows): array
    {
        return array_values(array_map(fn (array $row): array => $this->projectOne($row),
            array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }

    /**
     * Every field the page renders, and nothing else.
     *
     * `allowed_actions` and `validation_problems` are passed through unchanged, because they
     * are the server's answer to "what may be done" and "what is wrong" and re-deriving them
     * here is the duplication this class exists to avoid.
     */
    private function projectOne(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'name' => (string) ($row['name'] ?? ''),
            'language' => (string) ($row['language'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'parameter_format' => $row['parameter_format'] ?? null,
            'components' => is_array($row['components'] ?? null) ? $row['components'] : [],
            // draft | submitting | remote -- whether Meta has ever seen it.
            'state' => (string) ($row['state'] ?? ''),
            // Meta's own word once it owns the template: APPROVED, REJECTED, PAUSED, ...
            'meta_status' => $row['meta_status'] ?? null,
            'rejected_reason' => $row['rejected_reason'] ?? null,
            'rejection_info' => $row['rejection_info'] ?? null,
            'quality_score' => $row['quality_score'] ?? null,
            'last_event' => $row['last_event'] ?? null,
            'submitted_at' => $row['submitted_at'] ?? null,
            'submission_error' => $row['submission_error'] ?? null,
            // When the mirror was last refreshed, and whether the last refresh of this WABA
            // saw other templates but not this one -- which is how a template deleted at Meta
            // becomes visible here instead of lingering as "approved" for ever.
            'last_seen_at' => $row['last_seen_at'] ?? null,
            'missing_at_meta' => (bool) ($row['missing_at_meta'] ?? false),
            'inboxes' => is_array($row['inboxes'] ?? null) ? $row['inboxes'] : [],
            'allowed_actions' => is_array($row['allowed_actions'] ?? null) ? $row['allowed_actions'] : [],
            'validation_problems' => is_array($row['validation_problems'] ?? null) ? $row['validation_problems'] : [],
        ];
    }

    private function merchantMessage(LynomiaApiException $e): string
    {
        return match ($e->kind) {
            LynomiaApiException::KIND_DISABLED,
            LynomiaApiException::KIND_NOT_CONFIGURED => __('Lynomia Chat is not available yet. Please contact support.'),
            // Upstream answers 422 naming what Meta's rules refuse -- which input and why is
            // exactly what a merchant editing a template needs.
            LynomiaApiException::KIND_CONFLICT => $e->getMessage(),
            LynomiaApiException::KIND_UNAUTHORIZED,
            LynomiaApiException::KIND_NOT_PERMISSIBLE => __('Only the store owner can manage message templates.'),
            LynomiaApiException::KIND_NOT_FOUND => __('That template no longer exists.'),
            LynomiaApiException::KIND_RATE_LIMITED,
            LynomiaApiException::KIND_UNREACHABLE => __('Lynomia Chat is temporarily unreachable. Please try again shortly.'),
            default => __('Lynomia Chat could not complete that request. Please contact support.'),
        };
    }
}
