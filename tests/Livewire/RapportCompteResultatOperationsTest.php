<?php

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Ligne de ventilation compte-first : compte résolu depuis le code_cerfa de la
 * sous-catégorie (matérialisé par SousCategorieCompteObserver), debit/credit
 * posés selon le type de la transaction (dépense: débit, recette: crédit).
 */
function crOpsTestLigne(Transaction $tx, SousCategorie $sc, float $montant, ?int $operationId = null): TransactionLigne
{
    $compte = Compte::where('numero_pcg', $sc->code_cerfa)
        ->where('association_id', $sc->association_id)
        ->firstOrFail();

    $estDepense = $tx->type->value === 'depense';

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'operation_id' => $operationId,
        'montant' => $montant,
        'compte_id' => $compte->id,
        'debit' => $estDepense ? $montant : 0.0,
        'credit' => $estDepense ? 0.0 : $montant,
    ]);
}

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('se rend avec l\'arbre hiérarchique d\'opérations', function () {
    $op = Operation::factory()->create(['association_id' => $this->association->id, 'nom' => 'Festival été']);

    Livewire::test(RapportCompteResultatOperations::class)
        ->assertOk()
        ->assertViewHas('operationTree');
});

it('affiche un message si aucune opération sélectionnée', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSeeHtml('lectionnez');
});

it('affiche les données filtrées par opération', function () {
    $op = Operation::factory()->create(['association_id' => $this->association->id]);
    $cat = Categorie::factory()->depense()->create(['association_id' => $this->association->id, 'nom' => 'Frais']);
    // DC-4 : code_cerfa déclenche la matérialisation Compte/Famille nécessaire à CompteResultatBuilder.
    $sc = SousCategorie::factory()->create(['association_id' => $this->association->id, 'categorie_id' => $cat->id, 'nom' => 'Transport', 'code_cerfa' => '625']);

    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    crOpsTestLigne($d, $sc, 100.00, (int) $op->id);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->assertSee('Transport')
        ->assertSee('100,00');
});

it('supporte parSeances via query string', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSet('parSeances', true)
        ->set('parSeances', false)
        ->assertSet('parSeances', false);
});

it('supporte parTiers via query string', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSet('parTiers', true)
        ->set('parTiers', false)
        ->assertSet('parTiers', false);
});

it('passe les données tiers quand parTiers est actif', function () {
    $op = Operation::factory()->create(['association_id' => $this->association->id]);
    $cat = Categorie::factory()->depense()->create(['association_id' => $this->association->id, 'nom' => 'Frais']);
    // DC-4 : code_cerfa déclenche la matérialisation Compte/Famille nécessaire à CompteResultatBuilder.
    $sc = SousCategorie::factory()->create(['association_id' => $this->association->id, 'categorie_id' => $cat->id, 'nom' => 'Transport', 'code_cerfa' => '625']);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'type' => 'particulier', 'nom' => 'dupont', 'prenom' => 'Jean']);

    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-10-01', 'tiers_id' => $tiers->id, 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    crOpsTestLigne($d, $sc, 100.00, (int) $op->id);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('parTiers', true)
        ->assertSee('DUPONT');
});
