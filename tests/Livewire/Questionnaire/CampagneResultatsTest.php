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
