<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailLog;
use App\Models\EmailOpen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailOpen>
 */
final class EmailOpenFactory extends Factory
{
    protected $model = EmailOpen::class;

    public function definition(): array
    {
        return [
            'email_log_id' => EmailLog::factory(),
            'opened_at' => now(),
            'ip' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
        ];
    }
}
