<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuperAdminAccessLog>
 */
final class SuperAdminAccessLogFactory extends Factory
{
    protected $model = SuperAdminAccessLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'association_id' => Association::factory(),
            'action' => 'enter_support_mode',
            'payload' => [],
            'created_at' => now(),
        ];
    }
}
