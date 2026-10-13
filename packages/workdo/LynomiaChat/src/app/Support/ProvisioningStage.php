<?php

namespace Workdo\LynomiaChat\app\Support;

/**
 * How far provisioning got, so the next attempt resumes instead of starting over.
 *
 * THESE ARE RECORDED AFTER THE FACT, NOT PLANNED AHEAD. Each value means "this remote object
 * exists and its id is in the row", and it is written in the same breath as the id it
 * describes -- never in advance, never batched at the end. A stage that ran ahead of the
 * write it stands for would send the next attempt past a step that had not actually happened.
 *
 * The stage is a BREADCRUMB, not the condition the resume logic branches on. Resumption asks
 * the columns -- "is lynomia_account_id null?" -- because the id is the thing that is either
 * known or not; the stage exists so an operator reading the row can see where it stopped
 * without inferring it from which columns happen to be filled.
 */
final class ProvisioningStage
{
    /** The row exists and owns the store's slot. Nothing has been created in Lynomia yet. */
    public const CLAIMED = 'claimed';

    /**
     * The workspace exists and its id is in lynomia_account_id.
     *
     * A ROW STUCK HERE IS WAITING FOR THE OWNER'S SEAT. The workspace is real and recorded;
     * what has not happened is giving the tenant owner an identity inside it, which is the
     * step that fails when the owner's synthetic address is already taken upstream.
     */
    public const ACCOUNT_CREATED = 'account_created';

    /** The owner has an identity in the workspace. There is nothing left to do. */
    public const COMPLETE = 'complete';

    /**
     * THERE WAS A user_created STAGE HERE, and it went when the shared service user did.
     * Creating that one identity and attaching it were two remote calls with a recordable
     * point between them. Admitting a person is now one call into IdentityProvisioner, which
     * either returns an identity or throws, so a stage between ACCOUNT_CREATED and COMPLETE
     * could never be observed -- it would have been written and overwritten in the same
     * breath, which is the kind of breadcrumb that misleads an operator rather than helping.
     */
    public const ALL = [
        self::CLAIMED,
        self::ACCOUNT_CREATED,
        self::COMPLETE,
    ];

    private function __construct()
    {
    }
}
