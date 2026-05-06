<?php

declare(strict_types=1);

namespace Database\Factories\Association;

use App\Models\Association;
use App\Models\Association\ApiKey;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 */
final class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::current()?->id ?? Association::factory(),
            'key_id' => 'ak_'.bin2hex(random_bytes(16)),    // 35 chars
            'secret_encrypted' => bin2hex(random_bytes(32)),           // 64 chars hex (clair, le cast chiffre au save)
            'label' => $this->faker->randomElement(['Site vitrine prod', 'Dev local', 'Intranet']),
            'scopes' => ['newsletter:subscribe'],
        ];
    }
}
