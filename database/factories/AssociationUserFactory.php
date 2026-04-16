<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

final class AssociationUserFactory extends Factory
{
    protected $model = AssociationUser::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'association_id' => Association::factory(),
            'role' => 'consultation',
            'joined_at' => now(),
        ];
    }

    public function admin(): self
    {
        return $this->state(['role' => 'admin']);
    }
}
