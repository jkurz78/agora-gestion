<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuestionnaireBearerToken;
use App\Models\QuestionnaireCampaign;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class QuestionnaireBearerTokenFactory extends Factory
{
    protected $model = QuestionnaireBearerToken::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'campaign_id' => QuestionnaireCampaign::factory(),
            'token_hash' => hash('sha256', Str::random(48)),
        ];
    }
}
