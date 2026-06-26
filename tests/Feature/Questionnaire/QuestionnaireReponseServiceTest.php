<?php

declare(strict_types=1);

use App\Enums\StatutInvitation;
use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Exceptions\Questionnaire\ReponseObligatoireException;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

// -----------------------------------------------------------------------
// Tests supersede / creerDepuisOcr (Lot 7)
// -----------------------------------------------------------------------

it('creerDepuisOcr crée une soumission papier soumise', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Question', 'type' => 'texte_court', 'ordre' => 1, 'obligatoire' => true,
    ]);
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $invitation = $campagne->invitations()->create([
        'association_id' => $campagne->association_id,
        'participant_id' => $participant->id,
        'token_hash' => hash('sha256', 'test-token'),
        'token_chiffre' => 'test-token',
        'code_court' => 'ABCD1234',
        'statut' => StatutInvitation::NonOuvert,
    ]);

    $svc = app(QuestionnaireReponseService::class);
    $sub = $svc->creerDepuisOcr($invitation, [(string) $q1->id => 'Réponse papier'], accepteContact: false);

    expect($sub->statut)->toBe(StatutSubmission::Soumise);
    expect($sub->source)->toBe('papier');
    expect((int) $sub->active_key)->toBe((int) $invitation->id);
    expect($sub->answers)->toHaveCount(1);
});

it('creerDepuisOcr refuse de remplacer sans flag remplacer', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Question', 'type' => 'texte_court', 'ordre' => 1, 'obligatoire' => false,
    ]);
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $invitation = $campagne->invitations()->create([
        'association_id' => $campagne->association_id,
        'participant_id' => $participant->id,
        'token_hash' => hash('sha256', 'test2'),
        'token_chiffre' => 'test2',
        'code_court' => 'EFGH5678',
        'statut' => StatutInvitation::NonOuvert,
    ]);

    $svc = app(QuestionnaireReponseService::class);
    // Create first submission
    $svc->demarrerOuReprendre($invitation);
    $svc->finaliser($invitation->submissions()->first(), accepteContact: false);

    // Try to create OCR submission without remplacer flag
    expect(fn () => $svc->creerDepuisOcr($invitation, [(string) $q1->id => 'val'], accepteContact: false, remplacer: false))
        ->toThrow(HttpException::class);
});

it('creerDepuisOcr remplace l ancienne soumission (supersede non destructif)', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Question', 'type' => 'satisfaction', 'ordre' => 1, 'obligatoire' => true,
    ]);
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $invitation = $campagne->invitations()->create([
        'association_id' => $campagne->association_id,
        'participant_id' => $participant->id,
        'token_hash' => hash('sha256', 'test3'),
        'token_chiffre' => 'test3',
        'code_court' => 'IJKL9012',
        'statut' => StatutInvitation::NonOuvert,
    ]);

    $svc = app(QuestionnaireReponseService::class);
    $ancienne = $svc->demarrerOuReprendre($invitation);
    $svc->enregistrerReponse($ancienne, $q1, 3);
    $svc->finaliser($ancienne, accepteContact: false);

    // Supersede with OCR submission
    $nouvelle = $svc->creerDepuisOcr($invitation, [(string) $q1->id => 5], accepteContact: true, remplacer: true);

    expect($nouvelle->statut)->toBe(StatutSubmission::Soumise);
    expect($nouvelle->source)->toBe('papier');

    $ancienne->refresh();
    expect($ancienne->statut)->toBe(StatutSubmission::Remplacee);
    expect($ancienne->active_key)->toBeNull();
    expect((int) $ancienne->remplacee_par_id)->toBe((int) $nouvelle->id);

    // Invariant: exactly one active
    expect($invitation->submissions()->whereNotNull('active_key')->count())->toBe(1);
});
