<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormulaireToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FormulaireToken>
 */
final class FormulaireTokenFactory extends Factory
{
    protected $model = FormulaireToken::class;

    public function definition(): array
    {
        return [
            'participant_id' => null, // must be provided when creating
            'token' => strtoupper(Str::random(8)),
            'expire_at' => now()->addDays(30),
            'rempli_at' => null,
            'rempli_ip' => null,
        ];
    }

    public function rempli(): static
    {
        return $this->state([
            'rempli_at' => now(),
            'rempli_ip' => '127.0.0.1',
        ]);
    }
}
