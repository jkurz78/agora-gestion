<?php

declare(strict_types=1);

use App\Enums\StatutInvitation;
use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Exceptions\Questionnaire\ReponseObligatoireException;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;

function makeInvitation(): QuestionnaireInvitation
{
    $campagne = QuestionnaireCampaign::factory()->create();
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => true,
    ]);

    return QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
}

it('get-or-create : une seule soumission active par invitation', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();

    $s1 = $svc->demarrerOuReprendre($invitation);
    $s2 = $svc->demarrerOuReprendre($invitation);

    expect($s1->id)->toBe($s2->id);
    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Commence);
});

it('enregistre une réponse typée par question', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);
    $question = $invitation->campaign->questions()->first();

    $svc->enregistrerReponse($submission, $question, '4');

    $answer = $submission->fresh()->answers()->first();
    expect($answer->value_integer)->toBe(4);
    expect($answer->value_text)->toBeNull();
});

it('refuse de finaliser tant qu une question obligatoire est vide', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);

    expect(fn () => $svc->finaliser($submission, accepteContact: false))
        ->toThrow(ReponseObligatoireException::class);
});

it('finalise et marque invitation soumis', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);
    $svc->enregistrerReponse($submission, $invitation->campaign->questions()->first(), '5');

    $svc->finaliser($submission, accepteContact: true);

    expect($submission->fresh()->statut)->toBe(StatutSubmission::Soumise);
    expect($submission->fresh()->accepte_contact)->toBeTrue();
    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Soumis);
    expect($invitation->fresh()->submitted_at)->not->toBeNull();
});

it('enregistre note et commentaire sur la même ligne', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::Satisfaction, 'ordre' => 1,
        'config' => ['commentaire' => true, 'commentaire_libelle' => 'Pourquoi ?'],
    ]);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);

    $svc->enregistrerReponse($sub, $q, '4', commentaire: 'Très bon accueil');

    $a = $sub->fresh()->answers()->first();
    expect($a->value_integer)->toBe(4);
    expect($a->value_text)->toBe('Très bon accueil');
});

it('obligatoire : la note seule valide, le commentaire seul ne valide pas', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => true,
        'config' => ['commentaire' => true],
    ]);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);

    // commentaire seul (pas de note) → bloque
    $svc->enregistrerReponse($sub, $q, null, commentaire: 'un avis');
    expect(fn () => $svc->finaliser($sub, accepteContact: false))
        ->toThrow(ReponseObligatoireException::class);

    // note fournie → passe
    $svc->enregistrerReponse($sub, $q, '5', commentaire: 'un avis');
    $svc->finaliser($sub, accepteContact: false);
    expect($sub->fresh()->statut->value)->toBe('soumise');
});

// ── SatisfactionTexteLong ────────────────────────────────────────

it('STL : enregistrerReponse stocke note (value_integer) ET texte (value_text)', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::SatisfactionTexteLong, 'ordre' => 1,
        'obligatoire' => true, 'config' => [],
    ]);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);

    $svc->enregistrerReponse($sub, $q, '3', commentaire: 'Bonne expérience');

    $a = $sub->fresh()->answers()->first();
    expect($a->value_integer)->toBe(3);
    expect($a->value_text)->toBe('Bonne expérience');
});

it('STL : enregistrerReponse stocke la note seule quand le texte est vide', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::SatisfactionTexteLong, 'ordre' => 1,
        'obligatoire' => false, 'config' => [],
    ]);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);

    $svc->enregistrerReponse($sub, $q, '5', commentaire: null);

    $a = $sub->fresh()->answers()->first();
    expect($a->value_integer)->toBe(5);
    expect($a->value_text)->toBeNull();
});

it('STL champsManquants : note obligatoire vide → erreur q_{id}', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::SatisfactionTexteLong, 'ordre' => 1,
        'obligatoire' => true, 'config' => ['texte_obligatoire' => false],
    ]);

    $erreurs = $svc->champsManquants($q, null, null);
    expect($erreurs)->toHaveKey("q_{$q->id}");
    expect($erreurs)->not->toHaveKey("q_{$q->id}_commentaire");
});

it('STL champsManquants : texte obligatoire vide → erreur q_{id}_commentaire', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::SatisfactionTexteLong, 'ordre' => 1,
        'obligatoire' => false, 'config' => ['texte_obligatoire' => true],
    ]);

    $erreurs = $svc->champsManquants($q, '4', null);
    expect($erreurs)->not->toHaveKey("q_{$q->id}");
    expect($erreurs)->toHaveKey("q_{$q->id}_commentaire");
});

it('STL champsManquants : note + texte tous les deux obligatoires vides → deux erreurs', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::SatisfactionTexteLong, 'ordre' => 1,
        'obligatoire' => true, 'config' => ['texte_obligatoire' => true],
    ]);

    $erreurs = $svc->champsManquants($q, null, null);
    expect($erreurs)->toHaveKey("q_{$q->id}");
    expect($erreurs)->toHaveKey("q_{$q->id}_commentaire");
});

it('STL champsManquants : note + texte tous les deux présents et rien d obligatoire → aucune erreur', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::SatisfactionTexteLong, 'ordre' => 1,
        'obligatoire' => false, 'config' => ['texte_obligatoire' => false],
    ]);

    expect($svc->champsManquants($q, '2', 'un texte'))->toBe([]);
});

it('STL verifierObligatoires : texte obligatoire manquant bloque la finalisation même si la note est fournie', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::SatisfactionTexteLong, 'ordre' => 1,
        'obligatoire' => false, 'config' => ['texte_obligatoire' => true],
    ]);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);

    // Enregistrer la note sans le texte → le texte est obligatoire
    $svc->enregistrerReponse($sub, $q, '4', commentaire: null);

    expect(fn () => $svc->finaliser($sub, accepteContact: false))
        ->toThrow(ReponseObligatoireException::class);
});

it('STL verifierObligatoires : note + texte présents → finalisation OK', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $campagne = QuestionnaireCampaign::factory()->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::SatisfactionTexteLong, 'ordre' => 1,
        'obligatoire' => true, 'config' => ['texte_obligatoire' => true],
    ]);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);

    $svc->enregistrerReponse($sub, $q, '5', commentaire: 'Excellent');
    $svc->finaliser($sub, accepteContact: false);

    expect($sub->fresh()->statut->value)->toBe('soumise');
});

it('réouverture admin : invitation et soumission repassent en cours, submitted_at nul', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);
    $svc->enregistrerReponse($submission, $invitation->campaign->questions()->first(), '5');
    $svc->finaliser($submission, accepteContact: false);

    $svc->rouvrir($invitation);

    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Commence);
    expect($invitation->fresh()->submitted_at)->toBeNull();
    expect($submission->fresh()->statut)->toBe(StatutSubmission::EnCours);
    expect($submission->fresh()->submitted_at)->toBeNull();
    // Réponses conservées :
    expect($submission->fresh()->answers)->toHaveCount(1);
});
