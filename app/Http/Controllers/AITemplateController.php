<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Template;
use Orhanerday\OpenAi\OpenAi;
use App\Models\Utility;
use App\Models\ApikeySetiings;
use Illuminate\Support\Facades\DB;

class AITemplateController extends Controller
{
    /**
     * Server-side ceilings on what ONE call may spend. Both are above any value the shipped form can
     * produce (`generate.blade.php` offers a small `<select>` and defaults the length to 10), so these
     * refuse abuse without changing a legitimate request.
     */
    public const MAX_RESULT_TOKENS = 4000;

    public const MAX_RESULTS = 10;

    /**
     * The OpenAI key this caller may spend: their own tenant's, else the platform's. NEVER another
     * merchant's, which is what `inRandomOrder()` with no predicate did. P1-SEC-22(e).
     *
     * `creatorId()` is the tenant root -- it returns the user's own id for type `admin`/`super admin` and
     * `created_by` otherwise, so a staff member resolves to the merchant who owns the store, and the
     * platform operator resolves to themselves. Ordered, not random: the same caller always gets the same
     * key, which is what makes the charge attributable.
     */
    private function resolveApiKeyForActor(): ?string
    {
        $actor = auth()->user();

        if ($actor === null) {
            return null;
        }

        $own = ApikeySetiings::where('created_by', $actor->creatorId())->orderBy('id')->value('key');

        if (! empty($own)) {
            return $own;
        }

        // OWNER DECISION, 2026-10-22: "Merchants must not consume the platform's OpenAI API key. Enforce
        // this without breaking platform-operator functionality."
        //
        // So the platform limb below is reachable by the OPERATOR ONLY. A merchant -- or their staff --
        // with no key of their own is refused, and gets the handler's existing "no key configured"
        // message rather than spending the platform's budget. This replaces the interim behaviour, which
        // deliberately preserved the platform fallback for every caller while the decision was open.
        //
        // Judged with the shared operator helper (P1-SEC-11), so this agrees with the engine on case and
        // trailing space rather than carrying a fifth copy of the comparison.
        if (! \App\Policies\UserPolicy::isOperator($actor)) {
            return null;
        }

        // The platform's own key, for the operator. Scoped to operator accounts by type, so a merchant row
        // can never be mistaken for the platform's however `created_by` is populated -- the operator may
        // hold the key under a different operator account than the one signed in.
        //
        // `whereRaw`, NOT `whereIn(DB::raw(...))`. `User` is Cachable, and laravel-model-caching's CacheKey
        // cannot stringify an Expression inside an IN clause -- it throws "Object of class
        // Illuminate\Database\Query\Expression could not be converted to string" (CacheKey.php:160), so the
        // first version of this fix turned every call into a 500. Measured: of whereRaw, where, whereIn with
        // plain values and whereIn with DB::raw, only the last one throws.
        $platformOwnerIds = \App\Models\User::whereRaw('LOWER(TRIM(type)) = ?', ['super admin'])->pluck('id');

        if ($platformOwnerIds->isEmpty()) {
            return null;
        }

        $platform = ApikeySetiings::whereIn('created_by', $platformOwnerIds)->orderBy('id')->value('key');

        return empty($platform) ? null : $platform;
    }

    public function create($moduleName)
    { 
        $templateName = Template::where('module',$moduleName)->get();
        return view('generate', compact('templateName'));
    }
    public function getKeywords(Request $request, $id)
    {
        // P1-SEC-22(e). `Template::find()` returns null for any id that does not exist, and the next line
        // dereferenced it -- a 500 reachable with any bad id. Same shape as aiGenerate's.
        $template = Template::find($id);
        if ($template === null) {
            return response()->json(['success' => false, 'template' => '', 'tone' => 0], 404);
        }

        $field_data = json_decode($template->field_json);
        if (! is_object($field_data) || ! isset($field_data->field) || ! is_iterable($field_data->field)) {
            return response()->json(['success' => false, 'template' => '', 'tone' => 0], 422);
        }

        $html = "";
        foreach ($field_data->field as  $value) {
            $html .= '<div class="form-group col-md-12">
                     <label class="form-label ">' . $value->label . '</label>';
            if ($value->field_type == "text_box") {

                $html .= '<input type="text" class="form-control" name="' . $value->field_name . '" value="" placeholder="' . $value->placeholder . '" required">';
            }
            if ($value->field_type == "textarea") {
                $html .= '<textarea type="text" rows=3 class="form-control " id="description" name="' . $value->field_name . '" placeholder="' . $value->placeholder . '" required></textarea>';
            }
            $html .= '</div>';
        }
        return response()->json(
            [
                'success' => true,
                'template' => $html,
                'tone'=>$template->is_tone
            ]
        );
    }
    public function aiGenerate(Request $request)
    {
        if ($request->ajax()) {
            // P1-SEC-22(e). THE SPEND IS BOUNDED SERVER-SIDE, and before anything else happens.
            //
            // `result_length` reached OpenAI as `max_tokens` and `num_of_result` as `n`, both straight from
            // the request: one call could ask for n=100 at max_tokens=999999. The form's `<select>`
            // (generate.blade.php:68) and bare `Form::number` (:78) are client-side only.
            //
            // Refused rather than silently clamped: a caller asking for a hundred completions should be
            // told no, not quietly given ten. The maxima are above any value the shipped form can produce,
            // so no legitimate use changes.
            $request->validate([
                'result_length' => 'nullable|integer|min:1|max:' . self::MAX_RESULT_TOKENS,
                'num_of_result' => 'nullable|integer|min:1|max:' . self::MAX_RESULTS,
                'ai_creativity' => 'nullable|numeric|min:0|max:2',
            ]);

            $post = $request->all();

            unset($post['_token'], $post['template_name'], $post['tone'], $post['ai_creativity'], $post['num_of_result'], $post['result_length']);
            $data = array();

            // THE KEY IS THE CALLER'S OWN TENANT'S, OR THE PLATFORM'S -- NEVER ANOTHER MERCHANT'S.
            //
            // This was `ApikeySetiings::inRandomOrder()->limit(1)->first()`: no predicate at all.
            // `apikey_setiings` has `created_by` and NO `store_id` (2024_03_13_084315), the model carries no
            // `BelongsToStore` and is absent from config/tenancy.php's `scoped_models`, so nothing supplied
            // one. Two tenants who both configured a key cross-charged each other at random, and a merchant
            // with no key of their own billed whoever did.
            //
            // SCOPE OF THIS FIX, deliberately narrow. The acceptance criterion also asks WHO may spend the
            // platform's key, and that is an owner decision which is still open -- no AI permission is
            // seeded anywhere, and gating on a permission no row exists for is a permanent 403. So this
            // closes the cross-tenant charge (the live defect) and PRESERVES today's behaviour where the
            // platform holds the only key. If the owner decides merchants may not spend it, dropping the
            // second limb below is the whole change.
            $key_data = $this->resolveApiKeyForActor();
            $model_data = DB::table('settings')->where('name', 'chat_gpt_model')->first();
            
            if ($key_data) {

                $open_ai = new OpenAi($key_data);
            } else {
                $data['status'] = 'error';
                $data['message'] = __('Please set proper configuration for Api Key');
                return $data;
            }
            
            $aiCustomTemplate = '';
            $model = '';
            $text = '';
            $aitokens = '';
            $counter = 1;


            $template = Template::where('id', $request->template_name)->first();

            if ($request->template_name) {
                // P1-SEC-22(e). An unknown id left $template null and `$template->field_json` below
                // dereferenced it -- a 500 for any id that does not exist.
                if ($template === null) {
                    $data['status'] = 'error';
                    $data['message'] = __('Template not found.');

                    return $data;
                }

                $required_field = array();
                $data_field = json_decode($template->field_json);
                if (! is_object($data_field) || ! isset($data_field->field) || ! is_iterable($data_field->field)) {
                    $data['status'] = 'error';
                    $data['message'] = __('Template not found.');

                    return $data;
                }

                foreach ($data_field->field as  $val) {
                    request()->validate([$val->field_name => 'required|string']);
                }

                $aiCustomTemplate = $template->prompt;
                foreach ($data_field->field as  $field) {

                    $text_rep = "##" . $field->field_name . "##";
                    if (strpos($aiCustomTemplate, $text_rep) !== false) {
                        $field->value = $post[$field->field_name];
                        $aiCustomTemplate = str_replace($text_rep, $post[$field->field_name], $aiCustomTemplate);
                    }
                    if ($template->is_tone == 1) {
                        $tone = $request->tone;
                        $param = "##tone_language##";
                        $aiCustomTemplate = str_replace($param, $tone, $aiCustomTemplate);
                    }
                }
            }
            $lang_text = "Provide response in " . $request->language . " language.\n\n ";
            $aitokens = (int)$request->result_length;

            $max_results = (int)$request->num_of_result;
            $ai_creativity = (float)$request->ai_creativity;
            $isChatModel = isset($model_data->value) && $model_data->value == 'gpt-3.5-turbo-instruct';
            if ($isChatModel) {
                $complete = $open_ai->completion([
                    'model' =>isset($model_data->value) && !empty($model_data->value) ? $model_data->value : 'text-davinci-003',
                    'prompt' => $aiCustomTemplate . ' ' . $lang_text,
                    'temperature' => $ai_creativity,
                    'max_tokens' => $aitokens,
                    'n' => $max_results
                ]);

            } else {
                 $complete = $open_ai->chat([
                        'model' => $model_data->value ?? '',
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                            ['role' => 'user', 'content' => $aiCustomTemplate . ' ' . $lang_text],
                        ],
                        'temperature' => $ai_creativity,
                        'max_tokens' => $aitokens,
                        'n' => $max_results
                    ]);
            }
            $response = json_decode($complete, true);
            if ($isChatModel) {
                if (isset($response['choices']) && count($response['choices']) > 1) {
                    foreach ($response['choices'] as $value) {
                        $text .= $counter . '. ' . ltrim($value['text']) . "\r\n\r\n\r\n";
                        $counter++;
                    }
                } else {
                    $text = $response['choices'][0]['text'] ?? null;
                }
            } else {
                if (isset($response['choices']) && count($response['choices']) > 1) {
                    foreach ($response['choices'] as $value) {
                        $text .= $counter . '. ' . ltrim($value['message']['content']) . "\r\n\r\n\r\n";
                        $counter++;
                    }
                } else {
                    $text = $response['choices'][0]['message']['content'] ?? null;
                }
            }
            if (!empty($text)) {
                $data = trim($text);
                return $data;
            } else {
                $data['status'] = 'error';
                $data['message'] = __('Text was not generated due to Invalid API Key');
                return $data;
            }
        }
    }
}
