<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\CampagneResultats;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;
use Livewire\Livewire;

it('anonymise=false : l identité du répondant (sans consentement) est visible dans les résultats', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte', 'anonymise' => false]);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create(['type' => 'texte_court']);
    $svc = app(QuestionnaireReponseService::class);

    // Répondant SANS consentement (non anonyme → doit quand même apparaître)
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, 'Retour utile');
    $svc->finaliser($sub, accepteContact: false);

    $identite = $inv->participant->tiers->displayName();

    Livewire::test(CampagneResultats::class, ['campagne' => $campagne])
        ->assertSee($identite)               // identité visible car questionnaire nominatif
        ->assertDontSee('petit groupe', false); // avertissement anonymat absent
});

it('les blocs Information sont exclus des résultats', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte', 'anonymise' => false]);
    $qInfo = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Titre section info', 'type' => 'information', 'ordre' => 1,
    ]);
    $qReal = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Votre avis', 'type' => 'texte_court', 'ordre' => 2,
    ]);
    $svc = app(QuestionnaireReponseService::class);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $qReal, 'Très satisfait');
    $svc->finaliser($sub, accepteContact: false);

    Livewire::test(CampagneResultats::class, ['campagne' => $campagne])
        ->assertSee('Votre avis')
        ->assertDontSee('Titre section info');
});

it('satisfaction_texte_long : affiche la distribution de notes ET les verbatims', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte', 'anonymise' => false]);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note + commentaire', 'type' => 'satisfaction_texte_long', 'ordre' => 1,
    ]);
    $svc = app(QuestionnaireReponseService::class);

    $inv1 = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub1 = $svc->demarrerOuReprendre($inv1);
    $svc->enregistrerReponse($sub1, $q, '4', commentaire: 'Très bien dans l ensemble');
    $svc->finaliser($sub1, accepteContact: false);

    $inv2 = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub2 = $svc->demarrerOuReprendre($inv2);
    $svc->enregistrerReponse($sub2, $q, '2', commentaire: 'Peut mieux faire');
    $svc->finaliser($sub2, accepteContact: false);

    Livewire::test(CampagneResultats::class, ['campagne' => $campagne])
        ->assertStatus(200)
        // Note moyenne / distribution présente
        ->assertSee('3,0')          // moyenne (4+2)/2 = 3,0
        ->assertSee('4')            // note 4 dans la distribution
        ->assertSee('2')            // note 2 dans la distribution
        // Verbatims présents
        ->assertSee('Très bien dans l ensemble')
        ->assertSee('Peut mieux faire');
});

it('n expose l identité que pour les répondants ayant consenti', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create(['type' => 'texte_court']);
    $svc = app(QuestionnaireReponseService::class);

    $invA = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $subA = $svc->demarrerOuReprendre($invA);
    $svc->enregistrerReponse($subA, $q, 'RAS');
    $svc->finaliser($subA, accepteContact: false); // anonyme

    $invB = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $subB = $svc->demarrerOuReprendre($invB);
    $svc->enregistrerReponse($subB, $q, 'Rappelez-moi');
    $svc->finaliser($subB, accepteContact: true); // consent

    $identiteAnonyme = $invA->participant->tiers->displayName();    // ne doit PAS apparaître
    $identiteConsentante = $invB->participant->tiers->displayName(); // doit apparaître

    Livewire::test(CampagneResultats::class, ['campagne' => $campagne])
        ->assertSee('Rappelez-moi')         // verbatim visible (anonyme par défaut)
        ->assertSee('petit groupe', false)  // avertissement présent
        ->assertSee($identiteConsentante)   // identité exposée car consentement
        ->assertDontSee($identiteAnonyme);  // identité du non-consentant masquée
});
