<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * A1 — contrôle d'accès sur les mutations du livre des immobilisations
 * (ImmobilisationIndex). Seuls Administrateur et Comptable peuvent écrire
 * en comptabilité (RoleAssociation::canWrite). Gestionnaire et Consultation
 * doivent être refusés côté serveur ET ne rien écrire en base — vérifié ici
 * par un comptage avant/après, pas seulement par le statut HTTP : c'est la
 * seule façon de prouver qu'un authorize() mal placé n'a pas laissé passer
 * l'écriture avant de refuser.
 *
 * Note : Livewire::test() désactive la gestion d'exception par défaut, sauf
 * pour AuthorizationException (voir RequestBroker::temporarilyDisableException
 * HandlingAndMiddleware) — elle est donc convertie en réponse HTTP 403,
 * vérifiable via assertForbidden(), plutôt que relancée comme exception PHP.
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
});

// ── ouvrirModal() : sème le kit de comptes (ImmobilisationComptesSeeder) ──

test('ouvrirModal — refusé pour Gestionnaire et Consultation, rien n’est semé', function (RoleAssociation $role): void {
    $this->actingAs(($this->userAvecRole)($role));

    expect(Compte::where('classe', 2)->count())->toBe(0);

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->assertForbidden();

    expect(Compte::where('classe', 2)->count())->toBe(0);
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('ouvrirModal — autorisé pour Administrateur et Comptable, sème le kit de comptes', function (RoleAssociation $role): void {
    $this->actingAs(($this->userAvecRole)($role));

    expect(Compte::where('classe', 2)->count())->toBe(0);

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->assertOk()
        ->assertSet('showModal', true);

    expect(Compte::where('classe', 2)->count())->toBeGreaterThan(0);
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

// ── enregistrer() : crée la fiche et son écriture d’acquisition ──────────

test('enregistrer — refusé pour Gestionnaire et Consultation, aucune fiche créée', function (RoleAssociation $role): void {
    ImmobilisationComptesSeeder::seed();
    $tiers = Tiers::factory()->create();
    $this->actingAs(($this->userAvecRole)($role));

    expect(Immobilisation::count())->toBe(0);

    Livewire::test(ImmobilisationIndex::class)
        ->set('libelle', '20 tenues d’escrime')
        ->set('quantite', 20)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '3000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->call('enregistrer')
        ->assertForbidden();

    expect(Immobilisation::count())->toBe(0);
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('enregistrer — autorisé pour Administrateur et Comptable, crée la fiche', function (RoleAssociation $role): void {
    ImmobilisationComptesSeeder::seed();
    $tiers = Tiers::factory()->create();
    $this->actingAs(($this->userAvecRole)($role));

    expect(Immobilisation::count())->toBe(0);

    Livewire::test(ImmobilisationIndex::class)
        ->set('libelle', '20 tenues d’escrime')
        ->set('quantite', 20)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '3000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->call('enregistrer')
        ->assertHasNoErrors();

    expect(Immobilisation::count())->toBe(1);
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);

// ── Affichage : le registre reste consultable par tous les rôles ─────────

test('le registre reste consultable pour les quatre rôles', function (RoleAssociation $role): void {
    ImmobilisationComptesSeeder::seed();
    app(ImmobilisationService::class)->acquerir(
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

    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationIndex::class)
        ->assertOk()
        ->assertSee('20 tenues d’escrime');
})->with([RoleAssociation::Admin, RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('le bouton « Nouvelle immobilisation » est masqué pour Gestionnaire et Consultation', function (RoleAssociation $role): void {
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationIndex::class)
        ->assertDontSeeHtml('wire:click="ouvrirModal"');
})->with([RoleAssociation::Gestionnaire, RoleAssociation::Consultation]);

test('le bouton « Nouvelle immobilisation » est visible pour Administrateur et Comptable', function (RoleAssociation $role): void {
    $this->actingAs(($this->userAvecRole)($role));

    Livewire::test(ImmobilisationIndex::class)
        ->assertSeeHtml('wire:click="ouvrirModal"');
})->with([RoleAssociation::Admin, RoleAssociation::Comptable]);
