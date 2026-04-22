<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ReservedSlug implements ValidationRule
{
    private const MESSAGE = 'Slug réservé';

    /**
     * Run the validation rule.
     *
     * Rejects any slug that appears in the `reserved_slugs` list defined in
     * `config/tenancy.php`. The comparison is case-insensitive and trims
     * surrounding whitespace before matching.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = mb_strtolower(trim((string) $value));

        /** @var array<int,string> $reserved */
        $reserved = config('tenancy.reserved_slugs', []);

        if (in_array($normalized, $reserved, strict: true)) {
            $fail(self::MESSAGE);
        }
    }
}
