<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HelloAssoEnvironnement;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelloAssoParametres>
 */
final class HelloAssoParametresFactory extends Factory
{
    protected $model = HelloAssoParametres::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'client_id' => $this->faker->uuid(),
            'client_secret' => $this->faker->uuid(),
            'organisation_slug' => $this->faker->slug(2),
            'environnement' => HelloAssoEnvironnement::Sandbox,
            'compte_helloasso_id' => CompteBancaire::factory(),
            'compte_versement_id' => CompteBancaire::factory(),
        ];
    }
}
