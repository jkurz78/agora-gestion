<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use Illuminate\Database\Eloquent\Factories\Factory;

final class UsageSousCategorieFactory extends Factory
{
    protected $model = UsageSousCategorie::class;

    public function definition(): array
    {
        $sc = SousCategorie::factory()->create();

        return [
            'association_id' => $sc->association_id,
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::Don,
        ];
    }
}
