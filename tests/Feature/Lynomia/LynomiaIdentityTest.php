<?php

namespace Tests\Feature\Lynomia;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\IdentityProvisioner;
use Workdo\LynomiaChat\app\Services\SsoLinkIssuer;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * Account-scoped SSO with individually attributable identities.
 *
 * WHAT THIS FILE IS ABOUT. The integration used to sign every member of a merchant's team into
 * Lynomia as one shared service user, which was an administrator of the workspace. These tests
 * are the evidence that it no longer does, and they are written against the properties the
 * decision named rather than against the implementation that happens to deliver them:
 *
 *   individually attributable   two people are two Lynomia users, with their own names, and
 *                               the audit trail on both sides says which one acted.
 *   correct permissions         the tenant owner is an administrator; staff are agents.
 *   no default escalation       including when a staff member is the one who sets the
 *                               workspace up -- being first does not make you the admin.
 *   strict per-store isolation  one person working in two of their own stores has two
 *                               separate identities, and each session reaches one workspace.
 *   no unrelated accounts       every link minted carries the workspace it is confined to.
 *   revocation means something  handing the grant back forgets the identities behind it.
 *
 * NOTHING HERE REACHES THE NETWORK -- see InteractsWithLynomia.
 */
class LynomiaIdentityTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    /** The email a given POST /platform/api/v1/users carried, in send order. */
    private function userCreateAddresses(): array
    {
        $addresses = [];

        foreach (Http::recorded() as [$request]) {
            if (strtoupper($request->method()) === 'POST'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/platform/api/v1/users')) {
                $addresses[] = (string) $request->data()['email'];
            }
        }

        return $addresses;
    }

    /** The role each POST .../account_users attach asked for, in send order. */
    private function attachedRoles(): array
    {
        $roles = [];

        foreach (Http::recorded() as [$request]) {
            if (strtoupper($request->method()) === 'POST'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/account_users')) {
                $roles[] = (string) $request->data()['role'];
            }
        }

        return $roles;
    }

    // ================================================================ roles

    public function test_the_tenant_owner_is_the_workspace_administrator(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $identity = LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $owner->id)->first();

        $this->assertNotNull($identity);
        $this->assertSame(LynomiaStoreIdentity::ROLE_ADMINISTRATOR, $identity->role);
        $this->assertSame(['administrator'], $this->attachedRoles());
    }

    /**
     * THE HEADLINE PROPERTY. A staff member signing in gets their own seat, as an agent.
     *
     * Under the shared service user they arrived as the workspace's ADMINISTRATOR, with the
     * run of every conversation, contact, channel and member in it -- not because anyone
     * decided they should, but because there was only one identity and it had to be able to
     * administer the place.
     */
    public function test_a_staff_member_signs_in_as_themselves_and_as_an_agent(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $staff = $this->staffOf($owner->fresh());
        $this->grantLynomiaPermission($staff);

        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $staff->fresh());

        $ownerIdentity = LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $owner->id)->first();
        $staffIdentity = LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $staff->id)->first();

        $this->assertNotNull($staffIdentity);
        $this->assertSame(LynomiaStoreIdentity::ROLE_AGENT, $staffIdentity->role);

        // Two people, two Lynomia users. Not one seat shared.
        $this->assertNotSame((int) $ownerIdentity->lynomia_user_id, (int) $staffIdentity->lynomia_user_id);
        $this->assertCount(2, array_unique($this->userCreateAddresses()));
        $this->assertSame(['administrator', 'agent'], $this->attachedRoles());
    }

    /**
     * BEING FIRST DOES NOT MAKE YOU THE ADMINISTRATOR.
     *
     * A staff member with the module permission may well be the person who sets the workspace
     * up. Admitting whoever clicked as its administrator would be the default escalation the
     * decision forbids, and it is an easy thing to write by accident -- provision() is handed
     * the ACTOR, and using them directly is one character shorter than resolving the tenant.
     */
    public function test_a_staff_member_provisioning_does_not_become_the_administrator(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $staff = $this->staffOf($owner->fresh());
        $this->grantLynomiaPermission($staff);
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $staff->fresh());

        $this->assertSame(['administrator'], $this->attachedRoles());

        $ownerIdentity = LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $owner->id)->first();
        $this->assertNotNull($ownerIdentity, 'the tenant owner was not given the administrator seat');
        $this->assertSame(LynomiaStoreIdentity::ROLE_ADMINISTRATOR, $ownerIdentity->role);

        // The staff member has no seat at all yet -- they get an agent one when they open it.
        $this->assertNull(
            LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $staff->id)->first()
        );
    }

    /**
     * A DEMOTION IN STORE SYRIA HAS TO REACH LYNOMIA, and nothing else makes it: the identity
     * already exists, so the create path never runs again. Without an explicit re-attach a
     * former owner keeps administrator rights in the workspace for as long as the row lives.
     */
    public function test_a_demotion_re_attaches_the_identity_at_the_lower_role(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);
        $this->assertSame(['administrator'], $this->attachedRoles());

        // The person is now somebody's staff rather than their own tenant: creatorId() points
        // at the new owner, which is exactly what UserController does on a role change.
        $newOwner = $this->merchant();
        $demoted = $owner->fresh();
        $demoted->forceFill(['type' => 'Packing', 'created_by' => $newOwner->id])->save();

        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $demoted->fresh());

        $identity = LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $owner->id)->first();
        $this->assertSame(LynomiaStoreIdentity::ROLE_AGENT, $identity->role);
        $this->assertSame(['administrator', 'agent'], $this->attachedRoles());

        // No second identity was minted for them -- the same seat was downgraded.
        $this->assertSame(1, LynomiaStoreIdentity::forWorkspace((int) $link->lynomia_account_id)->count());
        $this->assertCount(1, $this->userCreateAddresses());
    }

    public function test_an_unchanged_role_costs_no_extra_call(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $owner->fresh());
        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $owner->fresh());

        // One attach, from provisioning. Opening the workspace twice re-sends nothing.
        $this->assertSame(['administrator'], $this->attachedRoles());
        $this->assertCount(1, $this->userCreateAddresses());
    }

    // ================================================================ scope

    /**
     * EVERY LINK NAMES THE WORKSPACE IT IS CONFINED TO. Upstream the parameter is optional and
     * an omitted one means "this person, everywhere they belong" -- which for an identity that
     * is in one account today need not stay true tomorrow.
     */
    public function test_the_sso_link_is_scoped_to_the_stores_workspace(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $owner->fresh());

        parse_str((string) parse_url($this->lastSsoRequest()->url(), PHP_URL_QUERY), $query);
        $this->assertSame((string) $link->lynomia_account_id, (string) $query['account_id']);
    }

    /**
     * STRICT PER-STORE ISOLATION. One merchant, two of their own stores, two workspaces. The
     * same person gets a SEPARATE identity in each, so a session scoped to one is not merely
     * restricted in the other -- it is nobody there at all.
     */
    public function test_one_person_in_two_stores_gets_two_separate_identities(): void
    {
        [$owner, $storeA] = $this->entitledTenant();
        $this->fakeLynomia();
        $linkA = app(AccountProvisioner::class)->provision($storeA, $owner);

        // Read before rescripting: fakeLynomia() resets Http::recorded() so each phase counts
        // its own traffic, which means the first store's calls are gone after the next line.
        $addressInA = $this->userCreateAddresses();

        $storeB = $this->storeFor($owner->fresh());
        $this->fakeLynomia(['account_id' => 7002]);
        $linkB = app(AccountProvisioner::class)->provision($storeB, $owner->fresh());

        $this->assertNotSame((int) $linkA->lynomia_account_id, (int) $linkB->lynomia_account_id);

        $inA = LynomiaStoreIdentity::forWorkspacePerson((int) $linkA->lynomia_account_id, (int) $owner->id)->first();
        $inB = LynomiaStoreIdentity::forWorkspacePerson((int) $linkB->lynomia_account_id, (int) $owner->id)->first();

        $this->assertNotNull($inA);
        $this->assertNotNull($inB);
        $this->assertNotSame((int) $inA->lynomia_user_id, (int) $inB->lynomia_user_id);
        $this->assertNotSame((int) $inA->store_id, (int) $inB->store_id);

        // Two distinct synthetic addresses, which is what makes them two remote users rather
        // than one user in two accounts.
        $this->assertCount(1, $addressInA);
        $this->assertCount(1, $this->userCreateAddresses());
        $this->assertNotSame($addressInA[0], $this->userCreateAddresses()[0]);
    }

    /**
     * The address is derived, never the person's real one. Sending the real address to
     * POST /platform/api/v1/users is the documented takeover primitive: upstream resolves an
     * existing user by email and grants this app a permissible on them.
     */
    /**
     * The password field upstream requires is never used by anyone, but it still has to be
     * ACCEPTED. Lynomia enforces one uppercase, one lowercase, one digit and one special, and
     * Str::password() seeds only one letter of either case -- so about one call in twenty-six
     * thousand was refused with a 422 nothing retried. Asserted over many draws because a
     * single sample proves nothing about a rule that fails rarely.
     */
    public function test_every_identity_secret_satisfies_lynomias_password_rule(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $provisioner = app(IdentityProvisioner::class);
        $secret = new \ReflectionMethod($provisioner, 'throwawaySecret');

        for ($i = 0; $i < 500; $i++) {
            $password = $secret->invoke($provisioner);

            $this->assertSame(32, strlen($password));
            $this->assertMatchesRegularExpression('/[a-z]/', $password, "no lowercase in: {$password}");
            $this->assertMatchesRegularExpression('/[A-Z]/', $password, "no uppercase in: {$password}");
            $this->assertMatchesRegularExpression('/[0-9]/', $password, "no digit in: {$password}");
            // Lynomia's own special-character dictionary (devise-secure_password).
            $this->assertMatchesRegularExpression(
                '/[!@#$%^&*()_+\-=]/',
                $password,
                "no special character in: {$password}"
            );
        }
    }

    public function test_a_persons_real_email_is_never_sent_to_lynomia(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        app(AccountProvisioner::class)->provision($store, $owner);

        $address = $this->userCreateAddresses()[0];

        $this->assertNotSame(strtolower((string) $owner->email), strtolower($address));
        $this->assertStringEndsWith('@stores.storesyria.invalid', $address);
        $this->assertStringContainsString('user-' . $owner->id, $address);
        $this->assertStringContainsString('store-' . $store->id, $address);

        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString((string) $owner->email, (string) $request->body());
        }
    }

    // ================================================================ refusals

    /**
     * The synthetic address is derived from public facts, so on an installation with open
     * signup somebody could register it and wait. Upstream applies submitted attributes only
     * when it CREATES, so the marker coming back is the proof this integration made the user.
     */
    public function test_an_identity_address_somebody_else_holds_is_refused(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['squatted_user' => true]);

        try {
            app(AccountProvisioner::class)->provision($store, $owner);
            $this->fail('a squatted identity address was adopted');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        // It stops BEFORE the attach, so the squatter is never admitted to the workspace...
        $this->assertSame([], $this->attachedRoles());
        // ...and nothing local claims they are anybody.
        $this->assertSame(0, LynomiaStoreIdentity::count());
    }

    public function test_an_identity_cannot_be_minted_in_a_workspace_that_does_not_exist_yet(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = LynomiaStoreLink::create([
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
        ]);

        $this->expectException(LynomiaApiException::class);
        app(IdentityProvisioner::class)->identityFor($link, $owner);
    }

    /**
     * TWO TABS IS THE ORDINARY CASE. Both requests resolve the same remote user, because the
     * address is deterministic and upstream returns the existing one; the only thing left is
     * which local row survives, and the unique index settles it rather than a check that can
     * lose the race.
     */
    public function test_two_concurrent_requests_for_one_identity_make_one_seat(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $staff = $this->staffOf($owner->fresh());
        $this->grantLynomiaPermission($staff);

        // The row the loser's INSERT will collide with, written as if the winner's request had
        // committed between the loser's lookup and its own write. That is the state a genuine
        // race produces, and the one a single process can reproduce honestly.
        LynomiaStoreIdentity::create([
            'lynomia_account_id' => (int) $link->lynomia_account_id,
            'user_id' => (int) $staff->id,
            'lynomia_user_id' => 9999,
            'role' => LynomiaStoreIdentity::ROLE_AGENT,
            'store_id' => (int) $store->id,
        ]);

        $resolved = app(IdentityProvisioner::class)->identityFor($link->fresh(), $staff->fresh());

        $this->assertSame(9999, (int) $resolved->lynomia_user_id, 'the loser did not adopt the winner row');
        $this->assertSame(
            1,
            LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $staff->id)->count()
        );
    }

    public function test_the_unique_index_refuses_a_second_seat_for_one_person(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $this->expectException(\Illuminate\Database\QueryException::class);

        LynomiaStoreIdentity::create([
            'lynomia_account_id' => (int) $link->lynomia_account_id,
            'user_id' => (int) $owner->id,
            'lynomia_user_id' => 9999,
            'role' => LynomiaStoreIdentity::ROLE_AGENT,
            'store_id' => (int) $store->id,
        ]);
    }

    // ================================================================ revocation

    /**
     * REVOCATION HAS TO REACH THE SEATS. Lynomia releases the per-user grants along with the
     * account's, so the remote users these rows point at stop being ones this app may mint a
     * link for. Rows left behind would be resolved by a later re-link, skip creation, and fail
     * at the SSO call with an authorization error the merchant can do nothing about.
     */
    public function test_handing_a_workspace_back_forgets_the_seats_in_it(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $linker = app(AccountLinker::class);
        $claim = $linker->request($store, $owner, 'ABCD2345EFGH6789');
        $link = $claim['link'];
        $linker->approve($link->fresh(), $this->operator(), $claim['verification_code']);

        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $owner->fresh());
        $this->assertSame(1, LynomiaStoreIdentity::forWorkspace((int) $link->lynomia_account_id)->count());

        $linker->revoke($link->fresh(), $this->operator(), 'abuse');

        $this->assertSame(0, LynomiaStoreIdentity::forWorkspace((int) $link->lynomia_account_id)->count());
    }

    public function test_a_revoked_link_cannot_be_opened_at_all(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);
        app(AccountLinker::class)->revoke($link->fresh(), $this->operator(), 'abuse');

        try {
            app(SsoLinkIssuer::class)->issueFor($link->fresh(), $owner->fresh());
            $this->fail('a revoked link minted an SSO url');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }
    }

    /**
     * A PROVISIONED workspace keeps its grant through a revocation -- the account is this
     * app's own creation and dropping the grant would orphan the merchant's conversations
     * beyond recovery -- so its seats survive to be usable again after a restore.
     */
    public function test_a_provisioned_workspace_keeps_its_seats_through_a_revocation(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);
        $seats = LynomiaStoreIdentity::forWorkspace((int) $link->lynomia_account_id)->count();

        app(AccountLinker::class)->revoke($link->fresh(), $this->operator(), 'billing hold');

        $this->assertSame($seats, LynomiaStoreIdentity::forWorkspace((int) $link->lynomia_account_id)->count());

        $restored = app(AccountLinker::class)->restore($link->fresh(), $this->operator());
        $this->assertSame(LinkState::ACTIVE, $restored->state);
        $this->assertNotEmpty(app(SsoLinkIssuer::class)->issueFor($restored->fresh(), $owner->fresh()));
    }

    /**
     * A store re-pointed at a different workspace does not inherit the old one's seats. The
     * lookup is keyed on the ACCOUNT, so a stale row is unfindable rather than merely
     * wrong -- there is no cleanup anybody has to remember.
     */
    public function test_a_new_workspace_starts_with_no_seats(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $this->fakeLynomia(['account_id' => 7001]);
        $linker = app(AccountLinker::class);
        $firstClaim = $linker->request($store, $owner, 'ABCD2345EFGH6789');
        $first = $firstClaim['link'];
        $linker->approve($first->fresh(), $this->operator(), $firstClaim['verification_code']);
        app(SsoLinkIssuer::class)->issueFor($first->fresh(), $owner->fresh());

        $oldAccountId = (int) $first->lynomia_account_id;
        $oldSeat = LynomiaStoreIdentity::forWorkspacePerson($oldAccountId, (int) $owner->id)->first();
        $this->assertNotNull($oldSeat);

        // Back to an open claim, then proven against a DIFFERENT workspace.
        $linker->revoke($first->fresh(), $this->operator(), 'moving');
        $linker->restore($first->fresh(), $this->operator());

        $this->fakeLynomia(['account_id' => 7002]);
        $secondClaim = $linker->request($store->fresh(), $owner->fresh(), 'JKLM2345NPQR6789');
        $second = $secondClaim['link'];
        $linker->approve($second->fresh(), $this->operator(), $secondClaim['verification_code']);
        app(SsoLinkIssuer::class)->issueFor($second->fresh(), $owner->fresh());

        $newSeat = LynomiaStoreIdentity::forWorkspacePerson(7002, (int) $owner->id)->first();

        $this->assertNotNull($newSeat);
        $this->assertNotSame((int) $oldSeat->id, (int) $newSeat->id);
        $this->assertSame(0, LynomiaStoreIdentity::forWorkspace($oldAccountId)->count());
    }

    // ================================================================ audit

    /**
     * ACCURATE USER-LEVEL AUDIT TRAILS. The point of the whole exercise: an action in Lynomia
     * can be traced back to a Store Syria person without anyone correlating timestamps.
     */
    public function test_the_audit_trail_names_the_person_and_the_remote_identity(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $staff = $this->staffOf($owner->fresh());
        $this->grantLynomiaPermission($staff);
        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $staff->fresh());

        $identity = LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $staff->id)->first();

        $created = AuditLog::where('action', 'lynomia_identity_created')->latest('id')->first();
        $this->assertNotNull($created);
        $this->assertSame((int) $staff->id, (int) $created->new_values['user_id']);
        $this->assertSame((int) $identity->lynomia_user_id, (int) $created->new_values['lynomia_user_id']);
        $this->assertSame(LynomiaStoreIdentity::ROLE_AGENT, $created->new_values['role']);

        $issued = AuditLog::where('action', 'lynomia_sso_issued')->latest('id')->first();
        $this->assertNotNull($issued);
        $this->assertSame((int) $staff->id, (int) $issued->new_values['actor_id']);
        $this->assertSame((int) $identity->lynomia_user_id, (int) $issued->new_values['lynomia_user_id']);
        $this->assertSame(LynomiaStoreIdentity::ROLE_AGENT, $issued->new_values['role']);
    }

    public function test_opening_the_workspace_records_when_that_seat_was_last_used(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $this->assertNull(
            LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $owner->id)->first()->last_sso_at
        );

        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $owner->fresh());

        $this->assertNotNull(
            LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $owner->id)->first()->last_sso_at
        );
    }

    /**
     * The SSO URL is a credential for five minutes. It goes to the browser as a redirect and
     * nowhere else -- in particular not into the audit record that says a link was issued.
     */
    /**
     * THE MERCHANT CAN SEE IT, which is half of what "accurate user-level audit trails" is
     * worth: a trail only the operator can read is a promise rather than a control.
     */
    public function test_the_overview_shows_who_has_a_seat_and_with_what_role(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $staff = $this->staffOf($owner->fresh());
        $this->grantLynomiaPermission($staff);
        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $staff->fresh());

        $response = $this->actingAs($owner->fresh())->get('/lynomia');

        $response->assertOk();
        $response->assertSee($owner->name, false);
        $response->assertSee($staff->name, false);
        $response->assertSee('Who has access', false);
    }

    /**
     * A store re-pointed at a different workspace must not show the previous one's people.
     * The listing is keyed on the account the link carries NOW, so this is structural.
     */
    public function test_the_overview_shows_nobody_from_a_workspace_the_store_no_longer_uses(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);
        $staff = $this->staffOf($owner->fresh());
        $this->grantLynomiaPermission($staff);
        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $staff->fresh());

        // The same store, now pointing at a workspace nobody has a seat in yet.
        $link->fresh()->forceFill(['lynomia_account_id' => 7777])->save();

        $response = $this->actingAs($owner->fresh())->get('/lynomia');

        $response->assertOk();
        $response->assertDontSee($staff->name, false);
    }

    public function test_the_sso_url_is_never_written_down(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);
        $url = app(SsoLinkIssuer::class)->issueFor($link->fresh(), $owner->fresh());

        $this->assertStringContainsString('sso_auth_token=', $url);

        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('sso_auth_token', json_encode([$log->old_values, $log->new_values]));
        }
    }
}
