<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\UsageCompte;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageCompte> */
final class UsageCompteFactory extends Factory
{
    protected $model = UsageCompte::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId(),
            'compte_id' => Compte::factory(),
            'usage' => UsageComptable::Don,
        ];
    }
}
