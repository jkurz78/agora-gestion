<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\SlugImmutableException;
use App\Models\Association;

final class ImmutableSlugObserver
{
    public function updating(Association $association): void
    {
        if (! $association->isDirty('slug')) {
            return;
        }

        if (($association->allowSlugChange ?? false) === true) {
            return;
        }

        throw new SlugImmutableException(
            (string) ($association->getOriginal('slug') ?? ''),
            (string) ($association->slug ?? ''),
        );
    }
}
