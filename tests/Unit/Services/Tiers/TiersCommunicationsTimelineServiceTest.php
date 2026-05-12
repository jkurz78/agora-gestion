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

it('liste un email envoyé via un participant du tiers (UNION)', function (): void {
    $tiers = Tiers::factory()->create();
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id]);
    EmailLog::factory()->create([
        'tiers_id' => null,
        'participant_id' => $participant->id,
        'objet' => 'Via participant',
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->total)->toBe(1)
        ->and($result->emails->getCollection()->first()->objet)->toBe('Via participant')
        ->and($result->emails->getCollection()->first()->participantId)->toBe($participant->id);
});

it('ne duplique pas un email cumulant tiers_id ET participant_id du tiers', function (): void {
    $tiers = Tiers::factory()->create();
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id]);
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => $participant->id,
        'objet' => 'Cumul',
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->total)->toBe(1);
});

it('exclut les emails d\'autres tiers', function (): void {
    $tiers = Tiers::factory()->create();
    $autre = Tiers::factory()->create();
    EmailLog::factory()->create(['tiers_id' => $autre->id, 'participant_id' => null]);
    EmailLog::factory()->create(['tiers_id' => $tiers->id, 'participant_id' => null, 'objet' => 'OK']);

    $result = $this->service->forTiers($tiers);

    expect($result->total)->toBe(1)
        ->and($result->emails->getCollection()->first()->objet)->toBe('OK');
});
