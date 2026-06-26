<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\User;
use App\Tenant\TenantContext;

/**
 * Tests de la route GET questionnaires.campagnes.pdf
 * (remplace l'ancien composant Livewire ImpressionPapier).
 */
beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
});

/**
 * Construit une campagne ouverte avec un participant et une question.
 *
 * @return array{campagne: QuestionnaireCampaign, participant: Participant}
 */
function buildPdfRouteFixture(): array
{
    $op = Operation::factory()->create(['nom' => 'Formation Laravel 2026']);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'titre_affiche' => 'Enquête satisfaction',
        'statut' => 'ouverte',
    ]);
    QuestionnaireCampaignQuestion::factory()
        ->for($campagne, 'campaign')
        ->create(['libelle' => 'Question route', 'type' => 'texte_court', 'ordre' => 1]);
    $participant = Participant::factory()->create(['operation_id' => $op->id]);

    return compact('campagne', 'participant');
}

it('GET questionnaires.campagnes.pdf retourne 200 avec Content-Type application/pdf', function (): void {
    ['campagne' => $campagne] = buildPdfRouteFixture();

    $this->get(route('questionnaires.campagnes.pdf', $campagne))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('la réponse PDF commence par %PDF (rendu DomPDF réel)', function (): void {
    ['campagne' => $campagne] = buildPdfRouteFixture();

    $response = $this->get(route('questionnaires.campagnes.pdf', $campagne));

    expect($response->getContent())->toStartWith('%PDF');
});

it('Content-Disposition contient inline (ouverture dans le navigateur)', function (): void {
    ['campagne' => $campagne] = buildPdfRouteFixture();

    $response = $this->get(route('questionnaires.campagnes.pdf', $campagne));

    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('les invitations sont créées pour les participants de l opération (idempotent)', function (): void {
    ['campagne' => $campagne] = buildPdfRouteFixture();

    expect($campagne->invitations()->count())->toBe(0);

    $this->get(route('questionnaires.campagnes.pdf', $campagne));
    expect($campagne->invitations()->count())->toBe(1);

    // Second appel : idempotent
    $this->get(route('questionnaires.campagnes.pdf', $campagne));
    expect($campagne->invitations()->count())->toBe(1);
});
