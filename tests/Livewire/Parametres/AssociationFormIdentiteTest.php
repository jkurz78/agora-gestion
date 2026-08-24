<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Parametres\AssociationForm;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Dernier des cinq déplacements qui vident AssociationForm (cf.
 * tests/Feature/Parametres/DecoupeAssociationFormTest.php) : les quatre
 * précédents ont vidé la coquille à onglets jusqu'à ne laisser qu'un seul
 * onglet, « infos ». Cette tâche retire la coquille elle-même — le composant
 * ne porte plus que les 10 réglages d'identité de l'association.
 *
 * Écrit AVANT la suppression des onglets (TDD) : les tests 1 à 4 couvrent le
 * comportement du composant, inchangé par le retrait des onglets. Le test 5
 * couvre spécifiquement le risque de cette tâche — voir son commentaire.
 */
function connecterAvecRolePourIdentite(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

it('charge les dix réglages d\'identité de l\'association au montage', function (): void {
    $association = Association::factory()->create([
        'nom' => 'Mon Association',
        'adresse' => '1 rue de la Paix',
        'code_postal' => '75001',
        'ville' => 'Paris',
        'email' => 'contact@monasso.fr',
        'telephone' => '0102030405',
        'logo_path' => 'logo.png',
        'cachet_signature_path' => 'cachet.png',
        'siret' => '12345678901234',
        'forme_juridique' => 'Association loi 1901',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourIdentite($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(AssociationForm::class)
        ->assertSet('nom', 'Mon Association')
        ->assertSet('adresse', '1 rue de la Paix')
        ->assertSet('code_postal', '75001')
        ->assertSet('ville', 'Paris')
        ->assertSet('email', 'contact@monasso.fr')
        ->assertSet('telephone', '0102030405')
        ->assertSet('logo_path', 'logo.png')
        ->assertSet('cachet_signature_path', 'cachet.png')
        ->assertSet('siret', '12345678901234')
        ->assertSet('forme_juridique', 'Association loi 1901');
});

it('enregistre les dix réglages d\'identité', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourIdentite($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(AssociationForm::class)
        ->set('nom', 'Nouvelle Association')
        ->set('adresse', '2 avenue des Champs')
        ->set('code_postal', '69000')
        ->set('ville', 'Lyon')
        ->set('email', 'nouveau@monasso.fr')
        ->set('telephone', '0405060708')
        ->set('siret', '98765432109876')
        ->set('forme_juridique', 'Fédération')
        ->call('save')
        ->assertHasNoErrors();

    $association->refresh();
    expect($association->nom)->toBe('Nouvelle Association');
    expect($association->adresse)->toBe('2 avenue des Champs');
    expect($association->code_postal)->toBe('69000');
    expect($association->ville)->toBe('Lyon');
    expect($association->email)->toBe('nouveau@monasso.fr');
    expect($association->telephone)->toBe('0405060708');
    expect($association->siret)->toBe('98765432109876');
    expect($association->forme_juridique)->toBe('Fédération');
});

it('refuse un SIRET de plus de 14 caractères', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourIdentite($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(AssociationForm::class)
        ->set('nom', 'Mon Association')
        ->set('siret', '123456789012345')
        ->call('save')
        ->assertHasErrors(['siret' => 'max']);
});

it('un comptable est refusé au montage d\'AssociationForm', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourIdentite($association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(AssociationForm::class)->assertForbidden();
});

/**
 * Test de rendu, pas de comportement — assumé. La protection contre la perte
 * de modifications non enregistrées (isDirty / showUnsavedModal / pendingUrl)
 * vit entièrement côté Alpine : impossible de l'exercer depuis PHP. Ce que
 * cette tâche retire, c'est `tab: @entangle('activeTab'),` du même bloc
 * x-data — un copier-coller trop large tuerait la protection en silence,
 * sans qu'aucun test PHP ne le voie. On fige donc ici la composition exacte
 * du bloc x-data attendu après le retrait des onglets : les clés de la
 * protection doivent survivre, `tab`/`activeTab` doit avoir disparu.
 */
it('conserve la protection contre la perte de modifications non enregistrées après le retrait des onglets', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourIdentite($association, RoleAssociation::Admin);

    $html = Livewire::actingAs($user)->test(AssociationForm::class)->html();

    expect($html)->toContain('isDirty: false')
        ->toContain('showUnsavedModal: false')
        ->toContain("pendingUrl: ''")
        ->not->toContain('activeTab')
        ->not->toContain('tab:');
});
