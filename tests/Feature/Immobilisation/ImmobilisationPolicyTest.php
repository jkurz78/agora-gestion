<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * A1 — couverture directe d'App\Policies\ImmobilisationPolicy, indépendante
 * de Livewire (qui convertit AuthorizationException en réponse 403 pendant
 * les tests plutôt que de la laisser remonter — voir AccesIndexTest et
 * consorts pour la vérification bout-en-bout côté composant).
 *
 * Seuls Administrateur et Comptable ont canWrite(Espace::Compta) ; les
 * quatre rôles ont canRead(Espace::Compta) (RoleAssociation::canRead
 * retourne toujours true — la consultation reste ouverte à tous).
 */
beforeEach(function (): void {
    $this->association = TenantContext::current();

    $this->userAvecRole = function (RoleAssociation $role): User {
        $user = User::factory()->create();
        $user->associations()->attach($this->association->id, [
            'role' => $role->value,
            'joined_at' => now(),
        ]);
        $user->update(['derniere_association_id' => $this->association->id]);

        return $user;
    };
});

function policyImmoCreerFiche(): Immobilisation
{
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    return app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );
}

// ── viewAny / view : consultation ouverte aux quatre rôles ────────────────

test('viewAny — autorisé pour les quatre rôles', function (RoleAssociation $role): void {
    $this->actingAs(($this->userAvecRole)($role));

    expect(Gate::allows('viewAny', Immobilisation::class))->toBeTrue();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('view — autorisé pour les quatre rôles', function (RoleAssociation $role): void {
    $immo = policyImmoCreerFiche();
    $this->actingAs(($this->userAvecRole)($role));

    expect(Gate::allows('view', $immo))->toBeTrue();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

// ── create ──────────────────────────────────────────────────────────────

test('create — autorisé pour Administrateur et Comptable', function (RoleAssociation $role): void {
    $this->actingAs(($this->userAvecRole)($role));

    expect(Gate::allows('create', Immobilisation::class))->toBeTrue();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

test('create — refusé pour Gestionnaire et Consultation', function (RoleAssociation $role): void {
    $this->actingAs(($this->userAvecRole)($role));

    expect(Gate::denies('create', Immobilisation::class))->toBeTrue();

    expect(fn () => Gate::authorize('create', Immobilisation::class))
        ->toThrow(AuthorizationException::class);
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

// ── update ──────────────────────────────────────────────────────────────

test('update — autorisé pour Administrateur et Comptable', function (RoleAssociation $role): void {
    $immo = policyImmoCreerFiche();
    $this->actingAs(($this->userAvecRole)($role));

    expect(Gate::allows('update', $immo))->toBeTrue();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

test('update — refusé pour Gestionnaire et Consultation', function (RoleAssociation $role): void {
    $immo = policyImmoCreerFiche();
    $this->actingAs(($this->userAvecRole)($role));

    expect(Gate::denies('update', $immo))->toBeTrue();

    expect(fn () => Gate::authorize('update', $immo))
        ->toThrow(AuthorizationException::class);
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

// ── delete ──────────────────────────────────────────────────────────────

test('delete — autorisé pour Administrateur et Comptable', function (RoleAssociation $role): void {
    $immo = policyImmoCreerFiche();
    $this->actingAs(($this->userAvecRole)($role));

    expect(Gate::allows('delete', $immo))->toBeTrue();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

test('delete — refusé pour Gestionnaire et Consultation', function (RoleAssociation $role): void {
    $immo = policyImmoCreerFiche();
    $this->actingAs(($this->userAvecRole)($role));

    expect(Gate::denies('delete', $immo))->toBeTrue();

    expect(fn () => Gate::authorize('delete', $immo))
        ->toThrow(AuthorizationException::class);
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);
