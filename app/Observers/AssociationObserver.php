<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Association;
use App\Support\MonoAssociation;

/**
 * Invalidates the MonoAssociation memo whenever the count of associations
 * changes (creation, deletion, restoration).
 *
 * MonoAssociation::isActive() is memoized via a static property scoped to the
 * current PHP process. On PHP-FPM / Octane / Swoole a process can persist
 * across many HTTP requests, so the stale "mono" flag must be cleared the
 * moment a second association is added or removed — not merely at the next
 * process boot.
 */
final class AssociationObserver
{
    public function created(Association $association): void
    {
        MonoAssociation::flush();
    }

    public function deleted(Association $association): void
    {
        MonoAssociation::flush();
    }

    public function restored(Association $association): void
    {
        MonoAssociation::flush();
    }
}
