<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Immobilisations\DotationsExercice;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * A1 — contrôle d'accès sur les mutations de l'écran des dotations
 * (DotationsExercice). Voir AccesIndexTest pour la note sur
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

// ── genererTout() : comptabilise les dotations manquantes de l’exercice ──

test('genererTout — refusé pour Gestionnaire et Consultation, aucune dotation créée', function (RoleAssociation $role): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    $this->actingAs(($this->userAvecRole)($role));

    expect(ImmobilisationDotation::count())->toBe(0);

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('genererTout')
        ->assertForbidden();

    expect(ImmobilisationDotation::count())->toBe(0);

    Carbon::setTestNow();
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('genererTout — autorisé pour Administrateur et Comptable, comptabilise la dotation', function (RoleAssociation $role): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    $this->actingAs(($this->userAvecRole)($role));

    expect(ImmobilisationDotation::count())->toBe(0);

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('genererTout')
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::count())->toBe(1);

    Carbon::setTestNow();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

// ── recalculer() : remplace la dotation comptabilisée par le montant recalculé

test('recalculer — refusé pour Gestionnaire et Consultation, la dotation n’est pas remplacée', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    $this->actingAs(($this->userAvecRole)(RoleAssociation::Admin));
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    $ancienneDotation = ImmobilisationDotation::where('exercice', 2026)->firstOrFail();
    $ancienTransactionId = (int) $ancienneDotation->transaction_id;

    // Change la durée pour créer un écart entre le comptabilisé et le recalculé.
    $immo->update(['duree_mois' => 30]);

    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('recalculer', (int) $immo->id)
        ->assertForbidden();

    $dotation = ImmobilisationDotation::where('exercice', 2026)->firstOrFail();
    expect($dotation->montant)->toEqual('600.00')
        ->and((int) $dotation->transaction_id)->toBe($ancienTransactionId)
        ->and(Transaction::find($ancienTransactionId))->not->toBeNull();

    Carbon::setTestNow();
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('recalculer — autorisé pour Administrateur et Comptable, remplace la dotation', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    $this->actingAs(($this->userAvecRole)(RoleAssociation::Admin));
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');

    $immo->update(['duree_mois' => 30]);

    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('recalculer', (int) $immo->id)
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1)
        ->and(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->montant)->toEqual('1200.00');

    Carbon::setTestNow();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

// ── supprimerDotation() : supprime la dotation comptabilisée et sa transaction

test('supprimerDotation — refusé pour Gestionnaire et Consultation, rien n’est annulé', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    $this->actingAs(($this->userAvecRole)(RoleAssociation::Admin));
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    $transactionId = (int) ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->transaction_id;

    $this->actingAs(($this->userAvecRole)($role));

    expect(ImmobilisationDotation::count())->toBe(1);

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('supprimerDotation', (int) $immo->id)
        ->assertForbidden();

    expect(ImmobilisationDotation::count())->toBe(1)
        ->and(Transaction::find($transactionId))->not->toBeNull();

    Carbon::setTestNow();
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('supprimerDotation — autorisé pour Administrateur et Comptable, annule la dotation', function (RoleAssociation $role): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    $this->actingAs(($this->userAvecRole)(RoleAssociation::Admin));
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    $transactionId = (int) ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->transaction_id;

    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('supprimerDotation', (int) $immo->id)
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::count())->toBe(0)
        ->and(Transaction::withTrashed()->findOrFail($transactionId)->trashed())->toBeTrue();

    Carbon::setTestNow();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

// ── ventiler() : ouvre TransactionForm sur la dotation comptabilisée ─────

test('ventiler — refusé pour Gestionnaire et Consultation, aucun évènement dispatché', function (RoleAssociation $role): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    $this->actingAs(($this->userAvecRole)(RoleAssociation::Admin));
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    $transactionId = (int) ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->transaction_id;
    Carbon::setTestNow();

    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('ventiler', $transactionId)
        ->assertForbidden()
        ->assertNotDispatched('edit-transaction');
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('ventiler — autorisé pour Administrateur et Comptable, dispatche edit-transaction', function (RoleAssociation $role): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    $this->actingAs(($this->userAvecRole)(RoleAssociation::Admin));
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    $transactionId = (int) ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->transaction_id;
    Carbon::setTestNow();

    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('ventiler', $transactionId)
        ->assertDispatched('edit-transaction', id: $transactionId);
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

// ── Affichage : l’écran reste consultable par tous les rôles ─────────────

test('l’écran des dotations reste consultable pour les quatre rôles', function (RoleAssociation $role): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->assertOk()
        ->assertSee('IM00001');

    Carbon::setTestNow();
})->with([RoleAssociation::Admin, RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('le bouton « Générer les dotations manquantes » est masqué pour Gestionnaire et Consultation', function (RoleAssociation $role): void {
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(DotationsExercice::class)
        ->assertDontSeeHtml('wire:click="genererTout"');
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('le bouton « Générer les dotations manquantes » est visible pour Administrateur et Comptable', function (RoleAssociation $role): void {
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(DotationsExercice::class)
        ->assertSeeHtml('wire:click="genererTout"');
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);
