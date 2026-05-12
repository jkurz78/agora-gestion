<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\EmailOpen;
use App\Models\Participant;
use App\Models\Tiers;
use App\Services\Tiers\DTO\CommunicationsTimelineDTO;
use App\Services\Tiers\DTO\EmailLogLigneDTO;
use App\Services\Tiers\TiersCommunicationsTimelineService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    $this->service = app(TiersCommunicationsTimelineService::class);
});

it('liste un email lié au tiers via tiers_id direct', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'objet' => 'Hello',
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result)->toBeInstanceOf(CommunicationsTimelineDTO::class)
        ->and($result->total)->toBe(1)
        ->and($result->emails->total())->toBe(1)
        ->and($result->emails->getCollection()->first())
            ->toBeInstanceOf(EmailLogLigneDTO::class)
        ->and($result->emails->getCollection()->first()->objet)->toBe('Hello');
});
