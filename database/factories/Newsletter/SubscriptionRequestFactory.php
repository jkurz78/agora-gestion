<?php

declare(strict_types=1);

namespace Database\Factories\Newsletter;

use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Models\Newsletter\SubscriptionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionRequest>
 */
final class SubscriptionRequestFactory extends Factory
{
    protected $model = SubscriptionRequest::class;

    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'prenom' => $this->faker->firstName(),
            'status' => SubscriptionRequestStatus::Pending,
            'subscribed_at' => now(),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Test)',
        ];
    }
}
