<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\StatutANouveau;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\ANouveauInvalideException;
use App\Livewire\Exercices\ClotureWizard;
use App\Models\ANouveauGeneration;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    SystemeSeeder::seed();

    $this->userClotureAN = User::factory()->create();
    $this->userClotureAN->associations()->attach(TenantContext::currentId(), [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->actingAs($this->userClotureAN);
    session(['exercice_actif' => 2025]);

    $this->sourceClotureAN = Exercice::create([
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
    $this->cibleClotureAN = Exercice::create([
        'annee' => 2026,
        'statut' => StatutExercice::Ouvert,
    ]);
});

function compteClotureAN(string $numero, int $classe): Compte
{
    return Compte::create([
        'numero_pcg' => $numero,
        'intitule' => 'Compte clôture '.$numero,
        'classe' => $classe,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
}

function ecritureClotureAN(
    Compte $debit,
    Compte $credit,
    string $date = '2026-08-31',
    ?int $tiersDebit = null,
    ?int $tiersCredit = null,
): Transaction {
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => $date,
        'libelle' => 'Écriture clôture AN',
        'montant_total' => '50.00',
        'mode_paiement' => null,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Od,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $debit->id,
        'debit' => '50.00',
        'credit' => '0.00',
        'montant' => '50.00',
        'tiers_id' => $tiersDebit,
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $credit->id,
        'debit' => '0.00',
        'credit' => '50.00',
        'montant' => '50.00',
        'tiers_id' => $tiersCredit,
    ]);

    return $transaction;
}

it('affiche l apercu puis cloture et genere la piece AN atomiquement', function (): void {
    $banque = compteClotureAN('512-C', 5);
    $produit = compteClotureAN('706-C', 7);
    ecritureClotureAN($banque, $produit);

    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->assertSet('step', 2)
        ->call('suite')
        ->assertSet('step', 3)
        ->assertSee('Aperçu des à-nouveaux')
        ->assertSee('512-C')
        ->assertSee('120')
        ->call('cloturer')
        ->assertRedirect(route('exercices.changer'));

    $generation = ANouveauGeneration::activePourCible(2026);
    expect($this->sourceClotureAN->fresh()->statut)->toBe(StatutExercice::Cloture)
        ->and($generation)->not->toBeNull()
        ->and($generation?->statut)->toBe(StatutANouveau::Active)
        ->and($generation?->transaction?->journal)->toBe(JournalComptable::AN)
        ->and($generation?->transaction?->equilibree)->toBeTrue();
});

it('laisse l exercice ouvert si l apercu AN est invalide', function (): void {
    $charge = compteClotureAN('606-C', 6);
    ecritureClotureAN($charge, Compte::ofNumeroSysteme('401'));

    expect(fn () => app(ExerciceService::class)->cloturer(
        $this->sourceClotureAN,
        $this->userClotureAN,
    ))->toThrow(ANouveauInvalideException::class, 'sans tiers');

    expect($this->sourceClotureAN->fresh()->statut)->toBe(StatutExercice::Ouvert)
        ->and(ANouveauGeneration::count())->toBe(0);
});

it('ne génère aucune ligne AN pour une créance déjà soldée à la clôture', function (): void {
    $tiers = Tiers::factory()->create();
    $compte411 = Compte::ofNumeroSysteme('411');
    $banque = compteClotureAN('512-SOLDE', 5);
    $produit = compteClotureAN('706-SOLDE', 7);
    $creance = ecritureClotureAN(
        $compte411,
        $produit,
        tiersDebit: (int) $tiers->id,
    );
    $reglement = ecritureClotureAN(
        $banque,
        $compte411,
        tiersCredit: (int) $tiers->id,
    );
    $lignes411 = TransactionLigne::query()
        ->whereIn('transaction_id', [(int) $creance->id, (int) $reglement->id])
        ->where('compte_id', (int) $compte411->id)
        ->pluck('id');
    DB::table('transaction_lignes')
        ->whereIn('id', $lignes411)
        ->update(['lettrage_code' => 'LT-CLOTURE-SOLDEE']);

    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->call('suite')
        ->call('cloturer')
        ->assertRedirect(route('exercices.changer'));

    $generation = ANouveauGeneration::activePourCible(2026);

    expect($generation)->not->toBeNull()
        ->and($generation?->transaction?->lignes()
            ->where('compte_id', (int) $compte411->id)
            ->exists())->toBeFalse();
});

it('avertit des mouvements en N plus 1 et bloque si la cible est cloturee', function (): void {
    $banque = compteClotureAN('512-M', 5);
    $produit = compteClotureAN('706-M', 7);
    ecritureClotureAN($banque, $produit);
    ecritureClotureAN($banque, $produit, '2026-09-10');

    Livewire::test(ClotureWizard::class)
        ->assertSee('mouvement(s) déjà présent(s) dans l’exercice suivant');

    $this->cibleClotureAN->update(['statut' => StatutExercice::Cloture]);

    Livewire::test(ClotureWizard::class)
        ->assertSee('L’exercice cible 2026-2027 est déjà clôturé')
        ->call('suite')
        ->assertSet('step', 1);
});
