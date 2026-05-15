<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Tiers;
use App\Services\Tiers\DTO\EmailLogLigneDTO;
use App\Services\Tiers\TiersCommunicationsTimelineService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    $this->service = app(TiersCommunicationsTimelineService::class);
});

it('override pageSize : 30 logs, pageSize=25 → page 1 has 25, total=30, lastPage=2', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->count(30)->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
    ]);

    $result = $this->service->forTiers($tiers, null, 1, 25);

    expect($result->emails->count())->toBe(25)
        ->and($result->emails->total())->toBe(30)
        ->and($result->emails->lastPage())->toBe(2);
});

it('compat back-office : forTiers sans pageSize utilise PAGE_SIZE (50)', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->count(30)->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
    ]);

    $result = $this->service->forTiers($tiers);

    // PAGE_SIZE = 50, 30 logs → tous dans page 1
    expect($result->emails->count())->toBe(30)
        ->and($result->emails->lastPage())->toBe(1);
    expect(TiersCommunicationsTimelineService::PAGE_SIZE)->toBe(50);
});

it('tiersAUnMessage retourne true quand tiers a au moins un email', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
    ]);

    expect($this->service->tiersAUnMessage($tiers))->toBeTrue();
});

it('tiersAUnMessage retourne false quand tiers n\'a aucun email', function (): void {
    $tiers = Tiers::factory()->create();

    expect($this->service->tiersAUnMessage($tiers))->toBeFalse();
});

it('EmailLogLigneDTO::fromEmailLog hydrate corpsHtml et attachmentPath', function (): void {
    $tiers = Tiers::factory()->create();
    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'corps_html' => '<p>Bonjour</p>',
        'attachment_path' => 'emails/12/doc.pdf',
    ]);

    $log->load('opens', 'participant', 'participant.tiers', 'operation', 'campagne', 'envoyePar');

    $dto = EmailLogLigneDTO::fromEmailLog($log);

    expect($dto->corpsHtml)->toBe('<p>Bonjour</p>')
        ->and($dto->attachmentPath)->toBe('emails/12/doc.pdf');
});

it('EmailLogLigneDTO::fromEmailLog hydrate attachmentPath null quand absent', function (): void {
    $tiers = Tiers::factory()->create();
    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'corps_html' => '<p>Test</p>',
        'attachment_path' => null,
    ]);

    $log->load('opens', 'participant', 'participant.tiers', 'operation', 'campagne', 'envoyePar');

    $dto = EmailLogLigneDTO::fromEmailLog($log);

    expect($dto->corpsHtml)->toBe('<p>Test</p>')
        ->and($dto->attachmentPath)->toBeNull();
});
