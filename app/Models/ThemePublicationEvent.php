<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PHASE 03 M3-C — THE APPEND-ONLY RECORD OF EVERY PUBLISH AND EVERY ROLLBACK.
 * ================================================================================================
 *
 * APPEND-ONLY IS A CONVENTION HERE, NOT A CONSTRAINT -- stated plainly because the migration's own
 * docblock could be read as claiming otherwise. Nothing in the database refuses an `UPDATE` or a
 * `DELETE`; omitting `updated_at` discourages one. `audit_logs` is the same shape.
 *
 * THIS TABLE DELIBERATELY HAS NO FOREIGN KEYS. An audit log the audited thing can delete is not an
 * audit log, and `audit_logs` -- measured, `SHOW CREATE TABLE`: four plain KEYs and not one
 * CONSTRAINT -- is the house precedent. The first draft of the migration gave `store_id` a cascade
 * and so destroyed exactly the record the table exists to keep; a review caught it.
 *
 * THE COST, AND THE RULE IT IMPLIES. `version_id` and `from_version_id` are unconstrained bigints,
 * so a row may name a version that has since been pruned -- and, more importantly, dereferencing one
 * by bare id crosses tenants with NO engine backstop at all, unlike the publish pointer. The event
 * carries its own `store_id` and `theme_id` precisely so the safe dereference is available:
 * `resolveVersion()` below uses the same triple as everywhere else and is the only way this model
 * will hand back a `ThemeVersion`.
 */
class ThemePublicationEvent extends Model
{
    public const ACTION_PUBLISH  = 'publish';
    public const ACTION_ROLLBACK = 'rollback';

    public const ACTIONS = [self::ACTION_PUBLISH, self::ACTION_ROLLBACK];

    /** `created_at` only: a row here is never updated, so there is no `updated_at` to invite one. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id', 'theme_id', 'version_id', 'from_version_id',
        'action', 'payload_hash', 'actor_id',
    ];

    protected $casts = [
        'store_id'        => 'integer',
        'version_id'      => 'integer',
        'from_version_id' => 'integer',
        'actor_id'        => 'integer',
        'created_at'      => 'datetime',
    ];

    /** One store's publication history for one theme, newest first. */
    public static function historyFor(int $storeId, string $themeId)
    {
        return static::query()
            ->where('store_id', $storeId)
            ->where('theme_id', $themeId)
            ->orderByDesc('id');
    }

    /**
     * The version this event names, resolved with the event's OWN store and theme.
     *
     * There is no `belongsTo` relation on purpose: `$event->version` would compile to
     * `where id = ?` and cross tenants silently. Returns null when the version has been pruned,
     * which is a legitimate state for an audit row (see the class docblock).
     */
    public function resolveVersion(): ?ThemeVersion
    {
        return ThemeVersion::ownedBy(
            (int) $this->store_id, (string) $this->theme_id, (int) $this->version_id);
    }

    public function resolveFromVersion(): ?ThemeVersion
    {
        return $this->from_version_id === null
            ? null
            : ThemeVersion::ownedBy(
                (int) $this->store_id, (string) $this->theme_id, (int) $this->from_version_id);
    }
}
