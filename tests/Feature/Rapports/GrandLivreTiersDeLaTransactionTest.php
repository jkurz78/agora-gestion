<?php

declare(strict_types=1);

/*
 * Colonne Tiers du grand livre sur les comptes de charge et de produit.
 *
 * Une ligne d'écriture de classe 6 ou 7 ne porte jamais de tiers propre — le
 * tiers est porté par la TRANSACTION. Mesuré sur la base de production au
 * 2026-09-03 : 0 ligne sur 316 en portait un, 316 sur 316 avaient une
 * transaction qui en portait un. La colonne restait donc vide précisément là
 * où l'information existe, et on ne savait pas de qui venait la recette ni à
 * qui la dépense avait été payée.
 *
 * Le repli ne touche QUE le libellé affiché. `tiers_id` reste celui de la
 * ligne : il porte la sémantique auxiliaire (401/411) et sert au lettrage,
 * donc le remplir depuis la transaction déplacerait le regroupement des
 * comptes auxiliaires.
 */

use App\Models\Association;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Rapports\GrandLivreBuilder;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('current_association_id');
});

/** Retrouve une ligne du grand livre par son identifiant de ligne d'écriture. */
function ligneDuGrandLivre(array $grandLivre, int $ligneId): ?array
{
    foreach ($grandLivre['comptes'] as $compte) {
        foreach ($compte['lignes'] as $ligne) {
            if ((int) $ligne['ligne_id'] === $ligneId) {
                return $ligne;
            }
        }
    }

    return null;
}

it('une ligne de charge sans tiers propre affiche le tiers de sa transaction', function (): void {
    $fournisseur = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'DUPONT',
        'prenom' => 'Marie',
    ]);
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Achats non stockés',
    ]);

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2026-03-10',
        'tiers_id' => $fournisseur->id,
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'tiers_id' => null,
        'debit' => 120.00,
        'credit' => 0,
        'montant' => 120.00,
    ]);

    $grandLivre = app(GrandLivreBuilder::class)->grandLivre('2026-01-01', '2026-12-31', ['606']);
    $ligneRendue = ligneDuGrandLivre($grandLivre, (int) $ligne->id);

    expect($ligneRendue)->not->toBeNull()
        ->and($ligneRendue['tiers'])->toBe($fournisseur->displayName())
        // `tiers_id` reste celui de la ligne — nul ici. C'est ce qui garantit
        // que le regroupement des comptes auxiliaires n'est pas déplacé.
        ->and($ligneRendue['tiers_id'])->toBeNull();
});

it('le tiers porte par la ligne reste prioritaire sur celui de la transaction', function (): void {
    $tiersLigne = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'LIGNE',
        'prenom' => 'Paul',
    ]);
    $tiersTransaction = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'TRANSACTION',
        'prenom' => 'Sophie',
    ]);
    $compte = Compte::factory()->numero('401')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournisseurs',
    ]);

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2026-03-10',
        'tiers_id' => $tiersTransaction->id,
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'tiers_id' => $tiersLigne->id,
        'debit' => 0,
        'credit' => 120.00,
        'montant' => 120.00,
    ]);

    $grandLivre = app(GrandLivreBuilder::class)->grandLivre('2026-01-01', '2026-12-31', ['401']);
    $ligneRendue = ligneDuGrandLivre($grandLivre, (int) $ligne->id);

    // Sur un compte auxiliaire, le tiers de la ligne EST l'information
    // comptable : le repli ne doit jamais le recouvrir.
    expect($ligneRendue['tiers'])->toBe($tiersLigne->displayName())
        ->and($ligneRendue['tiers_id'])->toBe((int) $tiersLigne->id);
});

it('une ligne sans tiers nulle part laisse la colonne vide', function (): void {
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Achats non stockés',
    ]);

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2026-03-10',
        'tiers_id' => null,
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'tiers_id' => null,
        'debit' => 90.00,
        'credit' => 0,
        'montant' => 90.00,
    ]);

    $grandLivre = app(GrandLivreBuilder::class)->grandLivre('2026-01-01', '2026-12-31', ['606']);

    expect(ligneDuGrandLivre($grandLivre, (int) $ligne->id)['tiers'])->toBeNull();
});
