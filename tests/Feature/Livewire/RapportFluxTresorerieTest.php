<?php

declare(strict_types=1);

use App\Livewire\RapportFluxTresorerie;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => 'ouvert']);
    session(['exercice_actif' => 2025]);
    CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'solde_initial' => 10000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Une recette encaissée telle qu'elle existe en partie double : l'en-tête ET
 * sa ligne de trésorerie. Le rapport de flux se lit sur le grand livre des
 * comptes de classe 5 — une transaction sans ligne ne déplace rien et n'y
 * figure pas, ce qui est le comportement voulu.
 */
function recetteEncaissee(object $ctx, string $date, float $montant): Transaction
{
    $compteBancaire = CompteBancaire::where('association_id', $ctx->association->id)->firstOrFail();

    $tx = Transaction::factory()->create([
        'association_id' => $ctx->association->id,
        'type' => 'recette',
        'date' => $date,
        'montant_total' => $montant,
        'compte_id' => $compteBancaire->id,
    ]);

    $compte512 = Compte::firstOrCreate(
        ['association_id' => (int) $ctx->association->id, 'numero_pcg' => '512'.$compteBancaire->id],
        [
            'intitule' => 'Banque '.$compteBancaire->id,
            'classe' => 5,
            'actif' => true,
            'lettrable' => false,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'compte_bancaire_id' => $compteBancaire->id,
        ]
    );

    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte512->id,
        'debit' => $montant,
        'credit' => 0,
        'montant' => $montant,
    ]);

    return $tx;
}

it('affiche le composant avec la synthèse', function () {
    recetteEncaissee($this, '2025-10-01', 5000.00);

    Livewire::test(RapportFluxTresorerie::class)
        ->assertSee('Rapport provisoire')
        ->assertSee('5 000,00')
        ->assertSee('10 000,00');
});

it('affiche la ligne flux dépliable avec les totaux annuels', function () {
    recetteEncaissee($this, '2025-10-01', 8000.00);

    Livewire::test(RapportFluxTresorerie::class)
        // Libellé explicite : ce flux est celui de la TRÉSORERIE, distinct du
        // résultat affiché au tableau de bord. Le rapport montre le chemin de
        // l'un à l'autre juste au-dessus.
        ->assertSeeHtml('Flux de trésorerie de l\'exercice')
        ->assertSee('8 000,00');
});

it('contient le détail mensuel dans le HTML (masqué par Alpine)', function () {
    // Alpine.js gère l'affichage côté client, le HTML est rendu côté serveur
    Livewire::test(RapportFluxTresorerie::class)
        ->assertSeeHtml('Septembre 2025');
});

it('affiche rapport définitif quand exercice clôturé', function () {
    Exercice::where('association_id', $this->association->id)->where('annee', 2025)->update([
        'statut' => 'cloture',
        'date_cloture' => '2026-09-15 10:00:00',
    ]);

    Livewire::test(RapportFluxTresorerie::class)
        ->assertSee('Rapport définitif')
        ->assertSee('15/09/2026');
});

it('affiche le bloc rapprochement avec le nombre d\'écritures non pointées', function () {
    recetteEncaissee($this, '2025-10-15', 1500.00);

    Livewire::test(RapportFluxTresorerie::class)
        ->assertSee('Rapprochement bancaire')
        ->assertSee('1 500,00');
});
