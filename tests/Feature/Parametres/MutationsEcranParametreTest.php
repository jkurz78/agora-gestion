<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Parametres\Comptabilite\UsagesComptables;
use App\Livewire\Parametres\RecusFiscaux;
use App\Models\Association;
use App\Models\Compte;
use App\Models\UsageCompte;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Le GET initial est gardé par CheckEspaceAccess (DroitsParEcranTest), mais
 * Livewire ne rejoue que des middlewares d'authentification sur
 * /livewire/update : sans garde de composant, un écran monté accepterait des
 * appels de méthode sans contrôle de rôle. Ce fichier vérifie donc la
 * MUTATION elle-même, pas seulement l'affichage — le cœur de la tâche est que
 * le GET et l'appel de méthode disent la même chose.
 */
beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

function connecterAvecRolePourMutation(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

it('un comptable peut réellement enregistrer les reçus fiscaux', function (): void {
    $user = connecterAvecRolePourMutation($this->association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->set('eligibleRecuFiscal', true)
        ->set('signataireNom', 'Marie Curie')
        ->call('enregistrer')
        ->assertHasNoErrors();

    $this->association->refresh();
    expect($this->association->eligible_recu_fiscal)->toBeTrue();
    expect($this->association->signataire_nom)->toBe('Marie Curie');
});

it('un comptable peut réellement enregistrer les affectations comptables', function (): void {
    $user = connecterAvecRolePourMutation($this->association, RoleAssociation::Comptable);
    $compte = Compte::factory()->depense()->create();

    Livewire::actingAs($user)->test(UsagesComptables::class)
        ->set('fraisKmSelectedId', $compte->id)
        ->call('saveFraisKilometriques')
        ->assertHasNoErrors();

    expect(
        UsageCompte::where('compte_id', $compte->id)
            ->where('usage', 'frais_kilometriques')
            ->exists()
    )->toBeTrue();
});

it('un gestionnaire est refusé dès le montage du composant (reçus fiscaux)', function (): void {
    // booted() s'exécute à CHAQUE requête Livewire, y compris le montage
    // initial que déclenche test() — chaîner ->call() après un montage déjà
    // refusé casserait sur un snapshot invalide plutôt que de re-vérifier le
    // même garde. C'est le montage lui-même qui rejoue ici la requête
    // /livewire/update qu'un rôle rétrogradé en cours de session enverrait.
    $user = connecterAvecRolePourMutation($this->association, RoleAssociation::Gestionnaire);

    Livewire::actingAs($user)->test(RecusFiscaux::class)->assertForbidden();

    $this->association->refresh();
    expect($this->association->eligible_recu_fiscal)->toBeFalse();
});

it('un gestionnaire est refusé dès le montage du composant (affectations comptables)', function (): void {
    $user = connecterAvecRolePourMutation($this->association, RoleAssociation::Gestionnaire);
    $compte = Compte::factory()->depense()->create();

    Livewire::actingAs($user)->test(UsagesComptables::class)->assertForbidden();

    expect(
        UsageCompte::where('compte_id', $compte->id)
            ->where('usage', 'frais_kilometriques')
            ->exists()
    )->toBeFalse();
});

it('une rétrogradation de rôle coupe les mutations d’un composant déjà monté', function (): void {
    // Le vrai motif de booted() plutôt que mount() : un composant LÉGITIMEMENT
    // monté (Comptable, autorisé sur recus-fiscaux) dont l'autorisation tombe
    // ensuite — rétrogradation de rôle en cours de session, ou requête
    // /livewire/update rejouée. Si booted() était un jour remplacé par
    // mount(), ce test resterait seul à casser : mount() ne se rejoue pas sur
    // un composant déjà hydraté, donc la mutation ci-dessous passerait à tort.
    $user = connecterAvecRolePourMutation($this->association, RoleAssociation::Comptable);

    $composant = Livewire::actingAs($user)->test(RecusFiscaux::class);
    $composant->assertOk();

    // Encore Comptable ici : ce set() est un aller-retour Livewire à part
    // entière, autorisé à ce stade — il pose l'état que la mutation bloquée
    // plus bas ne doit PAS persister.
    $composant->set('eligibleRecuFiscal', true)
        ->set('signataireNom', 'Marie Curie')
        ->assertOk();

    // Rétrogradation en base — currentRole() (App\Models\User::currentRole())
    // ne met rien en cache : il réinterroge le pivot association_user à chaque
    // appel via $this->associations()->where(...)->first(), donc la requête
    // Livewire suivante voit le nouveau rôle sans artifice de test. Un seul
    // appel après la rétrogradation : enchaîner derrière un 403 casserait sur
    // un snapshot invalide plutôt que de revérifier le même garde (cf. les
    // deux tests de refus au montage, ci-dessus).
    $user->associations()->updateExistingPivot($this->association->id, [
        'role' => RoleAssociation::Gestionnaire->value,
    ]);

    $composant->call('enregistrer')->assertForbidden();

    $this->association->refresh();
    expect($this->association->eligible_recu_fiscal)->toBeFalse();
    expect($this->association->signataire_nom)->not->toBe('Marie Curie');
});
