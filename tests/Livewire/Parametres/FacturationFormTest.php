<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Parametres\FacturationForm;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Deuxième des cinq déplacements qui vident AssociationForm (cf.
 * tests/Feature/Parametres/DecoupeAssociationFormTest.php) : les quatre
 * réglages de facturation (conditions de règlement, mentions légales,
 * mentions pénalités B2B, compte bancaire par défaut) quittent l'onglet
 * Facturation pour cet écran dédié, ouvert au Comptable en plus de l'Admin.
 *
 * SIRET et forme juridique NE migrent PAS ici : ce sont des attributs
 * d'identité légale, ils restent sur AssociationForm (déplacés seulement
 * visuellement vers l'onglet Informations). Voir le test dédié en fin de
 * fichier.
 */
function connecterAvecRolePourFacturation(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

it('charge les quatre réglages de facturation de l\'association au montage', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $compte = CompteBancaire::factory()->create(['association_id' => $association->id]);
    $association->fill([
        'facture_conditions_reglement' => 'Payable à réception',
        'facture_mentions_legales' => 'TVA non applicable, art. 261-7-1° du CGI',
        'facture_mentions_penalites' => 'Pénalités de retard : 3 fois le taux légal',
        'facture_compte_bancaire_id' => $compte->id,
    ])->save();

    $user = connecterAvecRolePourFacturation($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(FacturationForm::class)
        ->assertSet('facture_conditions_reglement', 'Payable à réception')
        ->assertSet('facture_mentions_legales', 'TVA non applicable, art. 261-7-1° du CGI')
        ->assertSet('facture_mentions_penalites', 'Pénalités de retard : 3 fois le taux légal')
        ->assertSet('facture_compte_bancaire_id', $compte->id);
});

it('enregistre les quatre réglages de facturation', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $compte = CompteBancaire::factory()->create(['association_id' => $association->id]);
    $user = connecterAvecRolePourFacturation($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(FacturationForm::class)
        ->set('facture_conditions_reglement', 'Payable sous 30 jours')
        ->set('facture_mentions_legales', 'Mentions légales de test')
        ->set('facture_mentions_penalites', 'Pénalités de test')
        ->set('facture_compte_bancaire_id', $compte->id)
        ->call('save')
        ->assertHasNoErrors();

    $association->refresh();
    expect($association->facture_conditions_reglement)->toBe('Payable sous 30 jours');
    expect($association->facture_mentions_legales)->toBe('Mentions légales de test');
    expect($association->facture_mentions_penalites)->toBe('Pénalités de test');
    expect($association->facture_compte_bancaire_id)->toBe($compte->id);
});

/**
 * Le test de la faille inter-tenant. CompteBancaire étend TenantModel (scopé
 * association_id), mais exists:comptes_bancaires,id interroge la table brute
 * et contourne ce scope. Et facture_compte_bancaire_id est une propriété
 * publique Livewire, donc modifiable côté client : rien n'empêchait de poser
 * l'identifiant du compte bancaire d'une AUTRE association.
 */
it('refuse le compte bancaire d\'une autre association', function (): void {
    $association = Association::factory()->create();
    $autreAssociation = Association::factory()->create();
    $compteAutreAsso = CompteBancaire::factory()->create(['association_id' => $autreAssociation->id]);

    TenantContext::boot($association);
    $user = connecterAvecRolePourFacturation($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(FacturationForm::class)
        ->set('facture_compte_bancaire_id', $compteAutreAsso->id)
        ->call('save')
        ->assertHasErrors(['facture_compte_bancaire_id']);

    $association->refresh();
    expect($association->facture_compte_bancaire_id)->toBeNull();
});

it('accepte un compte bancaire de l\'association courante', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $compte = CompteBancaire::factory()->create(['association_id' => $association->id]);
    $user = connecterAvecRolePourFacturation($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(FacturationForm::class)
        ->set('facture_compte_bancaire_id', $compte->id)
        ->call('save')
        ->assertHasNoErrors();

    $association->refresh();
    expect($association->facture_compte_bancaire_id)->toBe($compte->id);
});

it('un comptable monte FacturationForm sans erreur', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourFacturation($association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(FacturationForm::class)->assertOk();
});

it('un gestionnaire est refusé au montage de FacturationForm', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourFacturation($association, RoleAssociation::Gestionnaire);

    Livewire::actingAs($user)->test(FacturationForm::class)->assertForbidden();
});
