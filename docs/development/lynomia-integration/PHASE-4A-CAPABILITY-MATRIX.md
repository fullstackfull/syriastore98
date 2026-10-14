# Phase 4A — Lynomia automation capability matrix

What exists in Lynomia today, verified at code level, and what the Store Syria Automation
Studio may therefore reuse. **301 capabilities across 14 subsystems**, each with file:line
evidence and a test citation, produced by a parallel code-level review with an adversarial panel
instructed to refute every `FUNCTIONAL` verdict.

A capability is only `FUNCTIONAL` here if a path was traced from an entry point to a real effect
**and** a test was found exercising it. Appearing in a menu, an enum or a model is not enough.

---

## The matrix

| Subsystem | Caps | REUSE | EXTEND | ADAPT | BUILD | BLOCKED |
|---|---:|---:|---:|---:|---:|---:|
| Flow data model & versioning | 18 | 11 | 0 | 4 | 3 | 0 |
| Flow execution runtime | 27 | 19 | 1 | 1 | 6 | 0 |
| Node catalogue | 25 | 25 | 0 | 0 | 0 | 0 |
| Graph validation | 25 | 19 | 0 | 2 | 4 | 0 |
| Flows & agent-bot HTTP API | 32 | 11 | 6 | 5 | 9 | 1 |
| Trigger surface | 22 | 17 | 1 | 2 | 1 | 1 |
| Action surface & safety | 33 | 26 | 1 | 4 | 0 | 2 |
| Channel capability policy | 11 | 7 | 1 | 2 | 1 | 0 |
| Chatbots / agent bots | 21 | 14 | 0 | 3 | 3 | 1 |
| Entitlements & quotas | 15 | 9 | 2 | 1 | 3 | 0 |
| Execution history & analytics | 18 | 7 | 3 | 3 | 4 | 1 |
| Simulator / dry run | 16 | 12 | 1 | 2 | 1 | 0 |
| Lynomia Vue editor | 18 | 6 | 0 | 9 | 1 | 2 |
| Store Syria frontend stack | 20 | 7 | 1 | 3 | 7 | 2 |
| **TOTAL** | **301** | **190** | **17** | **41** | **43** | **10** |

**190 of 301 are straight REUSE.** The engines are there; Phase 4 is overwhelmingly a client
and a contract problem, not an engine problem. The build weight sits in exactly two places: the
HTTP API's missing surfaces, and the Store Syria frontend.

---

## The three findings that decide the architecture

### 1. A flow is an AgentBot, and the platform credential cannot create one

A flow is an `AgentBot` with `bot_type: flow`; there is no `flows` table. Graphs live in
`flow_versions` (draft / published / archived), runs in `flow_sessions`. "One draft, one
published" is a **Postgres partial unique index**, not an application convention — a racing
insert raises `RecordNotUnique`, which the controller does not rescue.

**The platform credential literally cannot create or convert a flow bot**: `bot_type` is not a
permitted platform parameter and `Custom::AgentBot#bot_type_unchanged` forbids changing it
afterwards. There is no `/platform/api/v1/flows`.

> **Consequence.** Every Studio call must use the **per-merchant-user scoped token** Phase 2
> already mints (`scoped_access_token` → `AgentApiClient`). This is not a limitation to work
> around; it is the correct boundary, and it means staff permissions are enforced by Lynomia's
> own `AgentBotPolicy` (`update?` = administrator) rather than by anything Store Syria asserts.

### 2. Lynomia's Vue editor cannot be lifted

It is a real canvas builder on `@vue-flow/core@1.48.2`, and it is unliftable for reasons that
are structural rather than stylistic:

- `composables/store.js` **throws** without a Vuex store on the app instance; the condition
  editor transitively needs ~9 populated Vuex modules (agents, campaigns, contacts, inboxes,
  labels, teams, sla, customViews, attributes).
- `useAccount.js` reads `route.params.accountId` from vue-router.
- `api/ApiClient.js` scrapes the account id out of `window.location.pathname.split('/')[3]` and
  only when the path contains `/app/accounts` — on any Store Syria URL this yields an empty
  scope and every call 404s.
- Auth is a devise-token-auth cookie read once at dashboard bootstrap. A Store Syria-origin page
  has no such cookie and no CORS allowance.

> **And the shortcut is worse than the problem.** Brokering Lynomia *administrator* tokens to
> merchants to make a lifted editor authenticate would hand every merchant full account-
> administrator rights, because `FlowsController` authorizes with `AgentBotPolicy#update?`.
>
> Two pieces *are* liftable and are pure data/functions with their own tests: `flowGraph.js` and
> `recipes/flowTemplates.js`.

### 3. Store Syria has no working JS build pipeline at all

Not "a limited one" — **`vite build` cannot currently succeed**: the entry chain
`resources/js/app.js → bootstrap.js → echo.js` imports `laravel-echo` and `pusher-js`, which are
in neither `package.json` nor `node_modules`. `@vite` appears **zero times across 1,232 Blade
files** and `public/build` does not exist. Vite/Tailwind/Alpine are untouched Breeze scaffolding.

The merchant dashboard is **Bootstrap 5 + jQuery 3.6.0**, loaded as ~28 `<link>` and ~33
`<script src>` tags. **Tailwind never reaches the dashboard** (browser-compiled, storefront
only). There is no Vue, no React, and **no node-graph library of any kind** — no jsPlumb,
Drawflow, Cytoscape, LiteGraph, React Flow, dagre, D3 or mermaid. The vendored drag-and-drop
(`dragula`, `jquery.nestable`, `jquery-ui` sortable) does list/tree reordering with no concept
of nodes, edges, ports, zoom or pan.

> **Consequence for P4C.** The visual builder is a **from-scratch, dependency-free canvas**
> (SVG + pointer events), shipped as a static asset, styled in Bootstrap 5 with a scoped inline
> `<style>`, following the Phase 2 inbox precedent exactly. Not a Vue port, and not an
> `npm install reactflow`.
>
> Two further constraints: module static assets are served by an **Apache `.htaccess`
> passthrough** and silently 404 under `artisan serve`, so anything vendored belongs in
> `public/`; and there is **no broadcasting** (`BROADCAST_CONNECTION=log`, no Echo client), so
> the Studio polls, as the inbox already does.

---

## What must NOT be offered, and why

These are the traps a plausible-looking Studio would walk into. Each was found by reading the
code, not by reading a menu.

| Do not | Because |
|---|---|
| Mirror the dashboard's `ACTION_TYPES` list as the action catalogue | It contains `add_sla`, which the backend refuses with 422 `Automation actions add_sla not supported` whenever the premium `sla` flag is on. Shipping it ships a broken action. |
| Offer `change_status` | Accepted by the backend but deliberately excluded from the dashboard's own list. An invalid status string raises inside a per-action rescue and is swallowed to Sentry — the merchant gets a rule that silently does nothing. |
| Offer `commerce_cart_abandoned` to a Store Syria merchant | Zid-only by construction, and additionally PRE_UAT for every provider including Store Syria. It cannot fire for them. |
| Copy `flowGraph.js`'s hardcoded `NODE_GROUPS` / `NODE_ICONS` | They are maintained separately from `Flows::NodeTypes::TYPES` and will drift the moment a node type is added — offering nodes the server rejects at publish. Drive the palette from the served catalogue. |
| List bots without filtering `account_id` | Global/system bots (`account_id` NULL) are visible and attachable in **every** account, and attaching one grants its token access to every account it is attached to. Flow bots are immune (`flow_bot_shape` requires an account); webhook bots are not. |
| Reuse the flow-failure operations signal for a merchant screen | It is deliberately installation-wide for the Super Admin console. On a merchant screen it leaks other tenants' failure volumes. |
| Reuse the `data-ajax-popup` modal pattern for node config | Its handler is registered **twice** on the identical selector (`public/js/custom.js:35` and `:311`), so every open fires two AJAX requests and two controller executions, with errors swallowed. For a node-save dialog that is silent double-writes. |

---

## Gaps already closed in P4A

| Gap | Fix | Commit |
|---|---|---|
| **No lost-update protection on draft edits.** `Flows::Versions#save!` was `version.update!(graph:)` with no precondition — two editors silently overwrote each other. Phase 4 adds a second editor, so this had to go first. | Required `lock_version` precondition enforced by the database (`UPDATE … WHERE lock_version = ?`); 409 carries the other editor's graph and a retry token. Mutation-verified. | lynomia `7240fe2d` |
| **No way to tell a client what a flow's own inboxes can run.** `capabilities` is unconditional WhatsApp (correctly — it is what the editor authors *against*, and a new flow has no inbox). | Added `channel_eligibility` additively: per connected inbox, `runs_flows` and the node types it cannot execute, reusing the validator's own call so warning and refusal cannot drift. | lynomia `3fa7bf2b` |
| **The node `data` allow-list had no test anywhere.** `unknown_field` appeared exactly once in the repo — the line emitting it. The Studio builds node `data` from the catalogue, so this allow-list is what stops a typo or injected key being stored and ignored. | Four cases incl. a key valid on one node type not riding in on another, and the deliberate save-vs-publish split. | lynomia `b9bd6893` |
| **The builder contract was asserted by no backend spec.** `node_types`, `capabilities` and `variables` are what the Studio renders its palette from; renaming one broke nothing here and everything there. | Pinned, including that the served catalogue matches the runtime's own type list exactly. | lynomia `b9bd6893` |

---

## Remaining P4A API gaps (for P4B onward)

Ranked by what the Studio cannot do without them:

1. **Usage / entitlement endpoint** — nothing exposes "how many flows may this account have, how
   many does it have". Required for Usage & Limits and for enforcing hard limits at the Lynomia
   mutation boundary rather than in the Store Syria UI.
2. **Execution detail** — `GET /flows/:id/sessions` gives a per-session summary (status, current
   node, failure code). There is no per-node execution trace, so "which branch did it take, and
   where did it stop" is not answerable yet.
3. **Trigger and action catalogues over HTTP** — the node catalogue is served; the *automation
   rule* trigger and action vocabularies are not, so the Studio would have to hardcode them,
   which is precisely the drift trap above.
4. **Server-side duplicate** — duplicate exists only as a client-side composition
   (`show` → `create` → `saveDraft`). Workable, but three round trips and no atomicity.
5. **`RecordNotUnique` is unrescued** on the draft/publish race. A concurrent create answers 500.

---

## Verification note

The adversarial panel returned **3 refutations against 301 capabilities**. Two were real and are
fixed above; both were independently re-verified by hand before being acted on (a repo-wide grep
for `unknown_field` returning only its own emit site, and a grep for `node_types` in `spec/`
returning only unrelated files). The third was a mis-cited line number in a claim whose substance
held.
