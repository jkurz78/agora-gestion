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

it('un gestionnaire reçoit 403 en appelant une méthode sur les reçus fiscaux', function (): void {
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

it('un gestionnaire reçoit 403 en appelant une méthode sur les affectations comptables', function (): void {
    $user = connecterAvecRolePourMutation($this->association, RoleAssociation::Gestionnaire);
    $compte = Compte::factory()->depense()->create();

    Livewire::actingAs($user)->test(UsagesComptables::class)->assertForbidden();

    expect(
        UsageCompte::where('compte_id', $compte->id)
            ->where('usage', 'frais_kilometriques')
            ->exists()
    )->toBeFalse();
});
