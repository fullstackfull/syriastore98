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

    /** The workspace exists and its id is in lynomia_account_id. */
    public const ACCOUNT_CREATED = 'account_created';

    /** The service user exists and its id is in lynomia_user_id. */
    public const USER_CREATED = 'user_created';

    /** The user is attached to the account. There is nothing left to do. */
    public const COMPLETE = 'complete';

    public const ALL = [
        self::CLAIMED,
        self::ACCOUNT_CREATED,
        self::USER_CREATED,
        self::COMPLETE,
    ];

    private function __construct()
    {
    }
}
