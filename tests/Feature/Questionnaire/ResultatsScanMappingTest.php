<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\CampagneResultats;
use App\Models\Operation;
use App\Models\QuestionnaireBearerToken;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnairePaperScan;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

function campagneAvecQuestionTexte(): array
{
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte', 'anonymise' => true]);
    $question = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Avis', 'type' => 'texte_court', 'ordre' => 1,
    ]);
    $bearer = QuestionnaireBearerToken::create([
        'association_id' => TenantContext::currentId(),
        'campaign_id' => (int) $campagne->id,
        'token_hash' => hash('sha256', 'bearer-'.uniqid()),
    ]);

    return [$campagne, $question, $bearer];
}

function scanTraite(QuestionnaireCampaign $campagne, QuestionnaireBearerToken $bearer, string $fichier): QuestionnairePaperScan
{
    return QuestionnairePaperScan::create([
        'association_id' => TenantContext::currentId(),
        'campaign_id' => (int) $campagne->id,
        'bearer_token_id' => (int) $bearer->id,
        'source' => 'upload',
        'chemin_fichier' => 'questionnaire-scans/'.$fichier,
        'qr_statut' => 'detecte',
        'statut' => 'traite',
    ]);
}

it('persiste le lien scan sur la soumission créée depuis l assistant OCR', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTexte();
    $scan = scanTraite($campagne, $bearer, 'scan-a.pdf');

    $submission = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'Réponse A'],
        paperScanId: (int) $scan->id,
    );

    expect((int) $submission->paper_scan_id)->toBe((int) $scan->id);
});

it('associe chaque réponse papier bearer à SON scan même avec des ordres opposés', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTexte();
    $service = app(QuestionnaireReponseService::class);

    // Scans créés dans l'ordre A puis B ; soumissions validées dans l'ordre A puis B.
    // La page trie les soumissions par submitted_at DESC : sans lien persisté,
    // l'appariement positionnel inversait A et B (bug de recette 2026-07-06).
    $scanA = scanTraite($campagne, $bearer, 'scan-a.pdf');
    $scanB = scanTraite($campagne, $bearer, 'scan-b.pdf');

    $subA = $service->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'Réponse A'],
        paperScanId: (int) $scanA->id,
    );
    $subA->update(['submitted_at' => now()->subMinutes(10)]);

    $subB = $service->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'Réponse B'],
        paperScanId: (int) $scanB->id,
    );
    $subB->update(['submitted_at' => now()]);

    Livewire::test(CampagneResultats::class, ['campagne' => $campagne->fresh()])
        ->assertViewHas('scanParSubmission', function ($mapping) use ($subA, $subB, $scanA, $scanB): bool {
            return (int) $mapping[(int) $subA->id]->id === (int) $scanA->id
                && (int) $mapping[(int) $subB->id]->id === (int) $scanB->id;
        });
});

it('n associe aucun scan aux soumissions bearer historiques sans lien', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTexte();

    scanTraite($campagne, $bearer, 'scan-a.pdf');
    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'Réponse'],
    );
    $sub->update(['paper_scan_id' => null]);

    Livewire::test(CampagneResultats::class, ['campagne' => $campagne->fresh()])
        ->assertViewHas('scanParSubmission', fn ($mapping): bool => ! isset($mapping[(int) $sub->id]));
});
