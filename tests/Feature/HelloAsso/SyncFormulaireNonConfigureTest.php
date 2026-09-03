<?php

declare(strict_types=1);

/*
 * Un formulaire HelloAsso mal configuré fait perdre des commandes EN SILENCE.
 *
 * La synchro écarte les formulaires Membership/Donation sans compte de
 * ventilation, et les formulaires Event sans opération. C'est le bon
 * comportement — on ne devine pas une imputation comptable — mais le décompte
 * atterrissait dans le même compteur `skipped` que les formulaires
 * volontairement marqués « ignorer ». Or les deux situations sont opposées :
 * l'une est un choix, l'autre est de l'argent encaissé chez HelloAsso pour
 * lequel aucune écriture n'a été créée.
 *
 * Ces tests verrouillent la distinction et le nommage du formulaire fautif :
 * sans eux, le signal peut disparaître au prochain refactor sans que rien ne
 * bronche — c'est exactement ainsi qu'il a manqué jusqu'ici.
 */

use App\Enums\HelloAssoEnvironnement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $association = Association::firstOrCreate(['id' => 1], [
        'nom' => 'Asso test',
        'slug' => 'test-asso',
    ]);
    TenantContext::boot($association);

    $banque = CompteBancaire::factory()->create();

    $this->parametres = HelloAssoParametres::factory()->create([
        'association_id' => 1,
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $banque->id,
        'compte_versement_id' => $banque->id,
    ]);
});

afterEach(fn () => TenantContext::clear());

function commandeNonConfiguree(string $formSlug, string $formType): array
{
    return [
        'id' => 9101,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => $formSlug,
        'formType' => $formType,
        'payments' => [['id' => 7777, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
        'items' => [[
            'id' => 1234,
            'amount' => 5000,
            'type' => $formType,
            'tierId' => 1,
            'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
        ]],
    ];
}

it('nomme le formulaire de cotisation sans compte de ventilation', function (): void {
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-sans-compte',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation 2025',
        'compte_id' => null,
    ]);

    $resultat = (new HelloAssoSyncService($this->parametres))
        ->synchroniser([commandeNonConfiguree('cotisation-sans-compte', 'Membership')], 2025);

    expect($resultat->aDesFormulairesNonConfigures())->toBeTrue()
        ->and($resultat->commandesNonConfigurees())->toBe(1)
        ->and($resultat->formulairesNonConfigures[0]['slug'])->toBe('cotisation-sans-compte')
        ->and($resultat->formulairesNonConfigures[0]['manque'])->toBe('compte de ventilation');
});

it('nomme le formulaire d evenement sans operation', function (): void {
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'sortie-sans-operation',
        'form_type' => 'Event',
        'form_title' => 'Sortie de juin',
        'operation_id' => null,
    ]);

    $resultat = (new HelloAssoSyncService($this->parametres))
        ->synchroniser([commandeNonConfiguree('sortie-sans-operation', 'Event')], 2025);

    expect($resultat->commandesNonConfigurees())->toBe(1)
        ->and($resultat->formulairesNonConfigures[0]['manque'])->toBe('opération');
});

it('ne signale PAS un formulaire volontairement ignore', function (): void {
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'formulaire-ignore',
        'form_type' => 'Membership',
        'form_title' => 'Test interne',
        'compte_id' => null,
        'ignore' => true,
    ]);

    $resultat = (new HelloAssoSyncService($this->parametres))
        ->synchroniser([commandeNonConfiguree('formulaire-ignore', 'Membership')], 2025);

    // C'est toute la nuance : la commande est bien écartée — `ordersSkipped`
    // la compte — mais c'est un CHOIX, pas une perte. Confondre les deux
    // rendrait l'alerte inutilisable, puisqu'elle crierait sur des
    // formulaires que l'on a délibérément mis de côté.
    expect($resultat->ordersSkipped)->toBe(1)
        ->and($resultat->aDesFormulairesNonConfigures())->toBeFalse();
});

it('regroupe les commandes par formulaire fautif', function (): void {
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-sans-compte',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation 2025',
        'compte_id' => null,
    ]);

    $commandes = [
        commandeNonConfiguree('cotisation-sans-compte', 'Membership'),
        commandeNonConfiguree('cotisation-sans-compte', 'Membership'),
        commandeNonConfiguree('cotisation-sans-compte', 'Membership'),
    ];

    $resultat = (new HelloAssoSyncService($this->parametres))->synchroniser($commandes, 2025);

    // Une entrée par formulaire, pas une par commande : c'est le formulaire
    // qu'il faut aller configurer.
    expect($resultat->formulairesNonConfigures)->toHaveCount(1)
        ->and($resultat->formulairesNonConfigures[0]['commandes'])->toBe(3)
        ->and($resultat->commandesNonConfigurees())->toBe(3);
});
