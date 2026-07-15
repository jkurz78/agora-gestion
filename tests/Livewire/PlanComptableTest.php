<?php

declare(strict_types=1);

use App\Livewire\PlanComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Écran « Plan comptable » — comptes de résultat (classes 6/7) groupés par
 * famille.
 */
beforeEach(function () {
    $this->asso = Association::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->asso->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->asso);
    session(['current_association_id' => $this->asso->id]);
    $this->actingAs($this->admin);
});

afterEach(function () {
    TenantContext::clear();
});

/** Helper local DC-7 : compte de résultat minimal du tenant courant. */
function dc7CreerCompte(string $numero, string $intitule, bool $systeme = false): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numero,
        'intitule' => $intitule,
        'classe' => (int) substr($numero, 0, 1),
        'actif' => true,
        'est_systeme' => $systeme,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);
}

// ── 1. Liste groupée par famille ─────────────────────────────────

it('liste les comptes 6/7 groupés par famille, ordre code ascendant', function () {
    Famille::factory()->create(['association_id' => $this->asso->id, 'code' => '60', 'nom' => 'Achats']);
    Famille::factory()->create(['association_id' => $this->asso->id, 'code' => '70', 'nom' => 'Ventes et prestations']);
    // Créés dans le désordre pour prouver le tri par code
    dc7CreerCompte('706', 'Prestations de services');
    dc7CreerCompte('601', 'Achats de matières');
    // Un compte de bilan ne doit pas apparaître
    Compte::forceCreate([
        'association_id' => $this->asso->id,
        'numero_pcg' => '51299',
        'intitule' => 'Banque hors écran',
        'classe' => 5,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    Livewire::test(PlanComptable::class)
        ->assertSeeInOrder([
            '60', 'Achats',
            '601', 'Achats de matières',
            '70', 'Ventes et prestations',
            '706', 'Prestations de services',
        ])
        ->assertDontSee('51299');
});

// ── 2. Création numéro-first ─────────────────────────────────────

it('crée un compte numéro-first et matérialise la famille orpheline + le miroir', function () {
    Livewire::test(PlanComptable::class)
        ->call('openCreate')
        ->assertSet('showModal', true)
        ->set('numero_pcg', '758')
        ->set('intitule', 'Produits divers de gestion')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $compte = Compte::where('numero_pcg', '758')->first();
    expect($compte)->not->toBeNull()
        ->and($compte->intitule)->toBe('Produits divers de gestion')
        ->and($compte->classe)->toBe(7)
        ->and($compte->actif)->toBeTrue()
        ->and($compte->est_systeme)->toBeFalse()
        ->and($compte->lettrable)->toBeFalse()
        ->and($compte->pour_inscriptions)->toBeFalse();

    // Famille orpheline auto-créée (nom = code) par CompteObserver
    $famille = Famille::where('code', '75')->first();
    expect($famille)->not->toBeNull()
        ->and($famille->nom)->toBe('75');

});

// ── 3. Validation ────────────────────────────────────────────────

it('rejette les numéros invalides (512A, 7, 706a)', function () {
    foreach (['512A', '7', '706a'] as $invalide) {
        Livewire::test(PlanComptable::class)
            ->call('openCreate')
            ->set('numero_pcg', $invalide)
            ->set('intitule', 'Intitulé test')
            ->call('save')
            ->assertHasErrors(['numero_pcg' => 'regex'])
            ->assertSee('Le numéro doit commencer par 6 ou 7');
    }

    expect(Compte::where('intitule', 'Intitulé test')->exists())->toBeFalse();
});

it('rejette un numéro en doublon dans l association', function () {
    dc7CreerCompte('706', 'Prestations');

    Livewire::test(PlanComptable::class)
        ->call('openCreate')
        ->set('numero_pcg', '706')
        ->set('intitule', 'Doublon')
        ->call('save')
        ->assertHasErrors(['numero_pcg' => 'unique'])
        ->assertSee('Ce numéro de compte existe déjà.');

    expect(Compte::where('numero_pcg', '706')->count())->toBe(1);
});

// ── 4. Édition inline ────────────────────────────────────────────

it('édite l intitulé inline', function () {
    $compte = dc7CreerCompte('706', 'Ancien intitulé');

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'intitule', 'Nouvel intitulé');

    expect($compte->fresh()->intitule)->toBe('Nouvel intitulé');
});

it('édite le nom de famille inline, avec création défensive à la volée', function () {
    dc7CreerCompte('706', 'Prestations'); // famille 70 auto-créée (nom = 70)

    Livewire::test(PlanComptable::class)->call('updateFamilleNom', '70', 'Ventes diverses');
    expect(Famille::where('code', '70')->first()->nom)->toBe('Ventes diverses');

    // Défensif : préfixe sans famille → créée à la volée puis nommée
    expect(Famille::where('code', '76')->exists())->toBeFalse();
    Livewire::test(PlanComptable::class)->call('updateFamilleNom', '76', 'Produits financiers');
    expect(Famille::where('code', '76')->first()->nom)->toBe('Produits financiers');
});

// ── 4bis. Renumérotation (D3 : identité immuable dès la première écriture) ──

it('renumérote un compte sans écritures', function () {
    $compte = dc7CreerCompte('706', 'Prestations');

    // Le retour true/false pilote la restauration de la valeur dans la
    // cellule Alpine (constat recette R-3).
    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '706B')
        ->assertReturned(true);

    expect($compte->fresh()->numero_pcg)->toBe('706B');
});

it('matérialise la famille orpheline du nouveau préfixe à la renumérotation', function () {
    $compte = dc7CreerCompte('706', 'Prestations');
    expect(Famille::where('code', '75')->exists())->toBeFalse();

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '758');

    expect($compte->fresh()->numero_pcg)->toBe('758')
        ->and(Famille::where('code', '75')->first()->nom)->toBe('75');
});

it('refuse la renumérotation d un compte porteur d écritures', function () {
    $compte = dc7CreerCompte('706', 'Prestations');

    TransactionLigne::factory()->create([
        'compte_id' => $compte->id,
        'debit' => 0,
        'credit' => 100,
    ]);

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '706B')
        ->assertSet('flashType', 'danger')
        ->assertReturned(false);

    expect($compte->fresh()->numero_pcg)->toBe('706');
});

it('refuse un changement de classe à la renumérotation', function () {
    $compte = dc7CreerCompte('706', 'Prestations');

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '606')
        ->assertSet('flashType', 'danger')
        ->assertReturned(false);

    expect($compte->fresh()->numero_pcg)->toBe('706');
});

it('refuse un numéro invalide ou en doublon à la renumérotation', function () {
    dc7CreerCompte('758', 'Existant');
    $compte = dc7CreerCompte('706', 'Prestations');

    // Regex (minuscule interdite)
    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '706a')
        ->assertSet('flashType', 'danger');
    expect($compte->fresh()->numero_pcg)->toBe('706');

    // Doublon dans l'association
    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '758')
        ->assertSet('flashType', 'danger');
    expect($compte->fresh()->numero_pcg)->toBe('706');
});

it('refuse la renumérotation d un compte système', function () {
    $compte = dc7CreerCompte('681', 'Dotations aux amortissements', systeme: true);

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '682')
        ->assertSet('flashType', 'danger');

    expect($compte->fresh()->numero_pcg)->toBe('681');
});

// ── 5. Compte système ────────────────────────────────────────────

it('refuse la modification et la suppression d un compte système', function () {
    $compte = dc7CreerCompte('681', 'Dotations aux amortissements', systeme: true);

    Livewire::test(PlanComptable::class)
        ->call('delete', $compte->id)
        ->assertSet('flashType', 'danger');
    expect($compte->fresh())->not->toBeNull();

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'intitule', 'Tentative interdite')
        ->assertSet('flashType', 'danger');
    expect($compte->fresh()->intitule)->toBe('Dotations aux amortissements');

    // Badge visible, pas de bouton supprimer sur la ligne système
    Livewire::test(PlanComptable::class)
        ->assertSee('système')
        ->assertDontSee('wire:click="delete('.$compte->id.')"', false);
});

// ── 6. Suppression d un compte utilisé ───────────────────────────

it('refuse la suppression d un compte porteur d écritures', function () {
    $compte = dc7CreerCompte('706', 'Prestations');

    // transaction_lignes.compte_id est nullOnDelete : sans garde applicative,
    // la suppression passerait silencieusement en orphelinant l'écriture.
    TransactionLigne::factory()->create([
        'compte_id' => $compte->id,
        'debit' => 0,
        'credit' => 100,
    ]);

    Livewire::test(PlanComptable::class)
        ->call('delete', $compte->id)
        ->assertSet('flashType', 'danger');

    expect($compte->fresh())->not->toBeNull()
        ->and(Compte::where('numero_pcg', '706')->exists())->toBeTrue();
});

// ── 7. Suppression d un compte propre ────────────────────────────

it('supprime un compte propre', function () {
    $compte = dc7CreerCompte('758', 'Produits divers');

    Livewire::test(PlanComptable::class)->call('delete', $compte->id);

    expect(Compte::withTrashed()->where('numero_pcg', '758')->exists())->toBeFalse();
});

// ── 8. Redirection de l'ancienne route comptes ───────────────────

it('redirige la route comptes en 301 vers le plan comptable', function () {
    $this->get('/parametres/comptes')
        ->assertStatus(301)
        ->assertRedirect('/parametres/plan-comptable');
});

// ── Smoke host page ──────────────────────────────────────────────

it('affiche la host page Plan comptable (x-app-layout)', function () {
    dc7CreerCompte('706', 'Prestations de services');

    $this->get(route('parametres.plan-comptable'))
        ->assertOk()
        ->assertSeeLivewire(PlanComptable::class)
        ->assertSee('Plan comptable')
        ->assertSee('706')
        ->assertSee('Prestations de services');
});
