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
            'nom' => $this->faker->lastName(),
            'status' => SubscriptionRequestStatus::Pending,
            'subscribed_at' => now(),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Test)',
        ];
    }

    public function inscriptionAtraiter(): self
    {
        return $this->state(fn () => [
            'status' => SubscriptionRequestStatus::Confirmed,
            'tiers_id' => null,
            'ignored_at' => null,
            'confirmed_at' => now()->subHour(),
        ]);
    }

    public function desinscriptionAtraiter(int $tiersId): self
    {
        return $this->state(fn () => [
            'status' => SubscriptionRequestStatus::Unsubscribed,
            'tiers_id' => $tiersId,
            'desinscription_traitee_at' => null,
            'unsubscribed_at' => now()->subHour(),
        ]);
    }

    public function importee(int $tiersId): self
    {
        return $this->state(fn () => [
            'status' => SubscriptionRequestStatus::Confirmed,
            'tiers_id' => $tiersId,
            'ignored_at' => null,
            'confirmed_at' => now()->subDay(),
        ]);
    }

    public function ignoree(): self
    {
        return $this->state(fn () => [
            'status' => SubscriptionRequestStatus::Confirmed,
            'tiers_id' => null,
            'ignored_at' => now()->subHour(),
            'confirmed_at' => now()->subDay(),
        ]);
    }

    public function desinscriptionTraitee(int $tiersId): self
    {
        return $this->state(fn () => [
            'status' => SubscriptionRequestStatus::Unsubscribed,
            'tiers_id' => $tiersId,
            'desinscription_traitee_at' => now()->subMinute(),
            'desinscription_action' => 'optout',
        ]);
    }
}
