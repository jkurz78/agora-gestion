<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\OrigineANouveau;
use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Enums\TypeTransaction;
use App\Livewire\RapprochementDetail;
use App\Models\ANouveauGeneration;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RapprochementBancaireService;
use App\Services\SoldeService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);

    $this->acteurBanqueAN = User::factory()->create();
    $this->acteurBanqueAN->associations()->attach(TenantContext::currentId(), [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->actingAs($this->acteurBanqueAN);

    $this->compteBancaireAN = CompteBancaire::factory()->create([
        'solde_initial' => '999.00',
        'date_solde_initial' => '2025-01-01',
    ]);
    $this->compte512AN = Compte::create([
        'numero_pcg' => '512-AN',
        'intitule' => 'Banque AN',
        'classe' => 5,
        'compte_bancaire_id' => $this->compteBancaireAN->id,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $this->produitBanqueAN = Compte::create([
        'numero_pcg' => '706-AN',
        'intitule' => 'Produit AN',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
});

function mouvementBanqueAN(
    Compte $banque,
    Compte $contrepartie,
    string $date,
    string $montant,
): Transaction {
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => $date,
        'libelle' => 'Mouvement bancaire AN '.$date,
        'montant_total' => $montant,
        'mode_paiement' => null,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Banque,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $banque->id,
        'debit' => $montant,
        'credit' => '0.00',
        'montant' => $montant,
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $contrepartie->id,
        'debit' => '0.00',
        'credit' => $montant,
        'montant' => $montant,
    ]);

    return $transaction;
}

function genererBanqueAN(User $acteur): ANouveauGeneration
{
    return app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $acteur,
    );
}

it('calcule le solde depuis l AN et les seuls mouvements de l exercice cible', function (): void {
    mouvementBanqueAN($this->compte512AN, $this->produitBanqueAN, '2026-08-31', '100.00');
    genererBanqueAN($this->acteurBanqueAN);
    mouvementBanqueAN($this->compte512AN, $this->produitBanqueAN, '2026-09-10', '20.00');

    $solde = app(SoldeService::class)->solde(
        $this->compteBancaireAN,
        2026,
        new CarbonImmutable('2026-09-30'),
    );

    expect($solde)->toBe(120.0);
});

it('laisse l ancienne operation non pointee candidate mais exclut la piece AN', function (): void {
    // TODO(lot 3) : RapprochementDetail::resoudreCompte512X() reste gardé par
    // config('compta.use_partie_double') (app/Livewire/RapprochementDetail.php:185,194).
    // Ce test exerce ce composant ; à retirer quand le filtre 512X deviendra permanent.
    config(['compta.use_partie_double' => true]);

    $ancienne = mouvementBanqueAN(
        $this->compte512AN,
        $this->produitBanqueAN,
        '2026-08-31',
        '100.00',
    );
    $generation = genererBanqueAN($this->acteurBanqueAN);
    $transactionAN = $generation->transaction;

    $rapprochement = RapprochementBancaire::create([
        'compte_id' => $this->compteBancaireAN->id,
        'date_fin' => '2026-09-30',
        'solde_ouverture' => '100.00',
        'solde_fin' => '100.00',
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->acteurBanqueAN->id,
    ]);

    $transactions = Livewire::test(RapprochementDetail::class, ['rapprochement' => $rapprochement])
        ->viewData('transactions');

    expect($transactions->pluck('id')->all())
        ->toContain((int) $ancienne->id)
        ->not->toContain((int) $transactionAN->id);

    expect(fn () => app(RapprochementBancaireService::class)->toggleTransaction(
        $rapprochement,
        TypeTransaction::Recette->value,
        (int) $transactionAN->id,
    ))->toThrow(InvalidArgumentException::class, 'à-nouveaux');
});
