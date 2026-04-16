<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class SlugImmutableException extends RuntimeException
{
    public function __construct(string $oldSlug, string $newSlug)
    {
        parent::__construct(
            "Le slug '{$oldSlug}' ne peut pas être changé en '{$newSlug}' ".
            'sans procédure explicite (allowSlugChange = true).'
        );
    }
}
