<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * A1 — contrôle d'accès sur les mutations de la fiche d'immobilisation
 * (ImmobilisationShow). Voir AccesIndexTest pour la note sur
 * assertForbidden() vs exception PHP côté tests Livewire.
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

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->creerImmo = function (): Immobilisation {
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
    };
});

// ── ouvrirEdition() : pas d'écriture en base, juste l'ouverture du form ──

test('ouvrirEdition — refusé pour Gestionnaire et Consultation', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->call('ouvrirEdition')
        ->assertForbidden();

    expect($immo->fresh()->libelle)->toBe('20 tenues d’escrime');
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('ouvrirEdition — autorisé pour Administrateur et Comptable', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->call('ouvrirEdition')
        ->assertOk()
        ->assertSet('showEditModal', true);
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

// ── enregistrerModification() : modifie la fiche ──────────────────────────

test('enregistrerModification — refusé pour Gestionnaire et Consultation, la fiche n’est pas modifiée', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->set('libelle', 'Tenues renouvelées')
        ->set('quantite', 22)
        ->set('date_mise_en_service', $immo->date_mise_en_service->toDateString())
        ->set('duree_mois', (int) $immo->duree_mois)
        ->call('enregistrerModification')
        ->assertForbidden();

    $fresh = $immo->fresh();
    expect($fresh->libelle)->toBe('20 tenues d’escrime')
        ->and($fresh->quantite)->toBe(20);
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('enregistrerModification — autorisé pour Administrateur et Comptable, modifie la fiche', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->set('libelle', 'Tenues renouvelées')
        ->set('quantite', 22)
        ->set('date_mise_en_service', $immo->date_mise_en_service->toDateString())
        ->set('duree_mois', (int) $immo->duree_mois)
        ->call('enregistrerModification')
        ->assertHasNoErrors();

    $fresh = $immo->fresh();
    expect($fresh->libelle)->toBe('Tenues renouvelées')
        ->and($fresh->quantite)->toBe(22);
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

// ── supprimer() : supprime la fiche et sa transaction d’acquisition ──────

test('supprimer — refusé pour Gestionnaire et Consultation, rien n’est supprimé', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    $transactionId = (int) $immo->transaction_id;
    $this->actingAs(($this->userAvecRole)($role));

    expect(Immobilisation::count())->toBe(1);

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->call('supprimer')
        ->assertForbidden();

    expect(Immobilisation::count())->toBe(1)
        ->and(Immobilisation::find($immo->id))->not->toBeNull()
        ->and(Transaction::find($transactionId))->not->toBeNull();
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('supprimer — autorisé pour Administrateur et Comptable, supprime la fiche', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    $transactionId = (int) $immo->transaction_id;
    $this->actingAs(($this->userAvecRole)($role));

    expect(Immobilisation::count())->toBe(1);

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->call('supprimer')
        ->assertRedirect(route('immobilisations.index'));

    expect(Immobilisation::count())->toBe(0)
        ->and(Transaction::find($transactionId))->toBeNull();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

// ── Affichage : la fiche reste consultable par tous les rôles ────────────

test('la fiche reste consultable pour les quatre rôles', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->assertOk()
        ->assertSee('20 tenues d’escrime');
})->with([RoleAssociation::Admin, RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('les boutons Modifier/Supprimer sont masqués pour Gestionnaire et Consultation', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->assertDontSeeHtml('wire:click="ouvrirEdition"')
        ->assertDontSeeHtml('wire:click="supprimer"');
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('les boutons Modifier/Supprimer sont visibles pour Administrateur et Comptable', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->assertSeeHtml('wire:click="ouvrirEdition"')
        ->assertSeeHtml('wire:click="supprimer"');
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);
