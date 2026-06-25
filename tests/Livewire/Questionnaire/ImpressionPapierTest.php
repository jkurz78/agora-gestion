<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\ImpressionPapier;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Enums\TypeQuestion;
use Livewire\Livewire;

it('présélectionne tous les participants de l opération au montage', function (): void {
    $op = Operation::factory()->create();
    $p1 = Participant::factory()->create(['operation_id' => $op->id]);
    $p2 = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    $component = Livewire::test(ImpressionPapier::class, ['campagne' => $campagne]);

    $selected = $component->get('selectedParticipants');
    expect($selected)->toHaveCount(2)
        ->toContain($p1->id)
        ->toContain($p2->id);
});

it('imprimer génère les invitations et retourne un téléchargement PDF', function (): void {
    $op = Operation::factory()->create();
    $p = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    // Ajouter une question pour que le PDF soit valide
    QuestionnaireCampaignQuestion::factory()
        ->for($campagne, 'campaign')
        ->create(['libelle' => 'Question test', 'type' => TypeQuestion::TexteCourt, 'ordre' => 1]);

    expect($campagne->invitations()->count())->toBe(0);

    Livewire::test(ImpressionPapier::class, ['campagne' => $campagne])
        ->set('selectedParticipants', [$p->id])
        ->call('imprimer')
        ->assertFileDownloaded("questionnaire-{$campagne->id}.pdf");

    // Les invitations ont été générées de façon idempotente
    expect($campagne->invitations()->count())->toBe(1);
});

it('imprimer ne génère qu une seule invitation par participant si appelé deux fois (idempotent)', function (): void {
    $op = Operation::factory()->create();
    $p = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    QuestionnaireCampaignQuestion::factory()
        ->for($campagne, 'campaign')
        ->create(['libelle' => 'Q idempotent', 'type' => TypeQuestion::TexteCourt, 'ordre' => 1]);

    Livewire::test(ImpressionPapier::class, ['campagne' => $campagne])
        ->set('selectedParticipants', [$p->id])
        ->call('imprimer');

    Livewire::test(ImpressionPapier::class, ['campagne' => $campagne])
        ->set('selectedParticipants', [$p->id])
        ->call('imprimer');

    expect($campagne->invitations()->count())->toBe(1);
});
