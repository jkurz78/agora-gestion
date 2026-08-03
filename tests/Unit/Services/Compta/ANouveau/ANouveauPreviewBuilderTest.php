<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\ANouveauInvalideException;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\Migrations\SystemeSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    SystemeSeeder::seed();
});

function compteANP(string $numero, int $classe): Compte
{
    return Compte::create([
        'numero_pcg' => $numero,
        'intitule' => 'Compte '.$numero,
        'classe' => $classe,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
}

/**
 * @param  list<array{compte: Compte, debit?: string, credit?: string, tiers_id?: int}>  $lignes
 * @return list<TransactionLigne>
 */
function ecritureANP(string $date, array $lignes): array
{
    $total = '0.00';
    foreach ($lignes as $ligne) {
        $total = bcadd($total, $ligne['debit'] ?? $ligne['credit'] ?? '0.00', 2);
    }

    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => $date,
        'libelle' => 'Écriture de test AN',
        'montant_total' => $total,
        'mode_paiement' => null,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Od,
    ]);

    return collect($lignes)->map(function (array $ligne) use ($transaction): TransactionLigne {
        return TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'compte_id' => $ligne['compte']->id,
            'debit' => $ligne['debit'] ?? '0.00',
            'credit' => $ligne['credit'] ?? '0.00',
            'montant' => $ligne['debit'] ?? $ligne['credit'] ?? '0.00',
            'tiers_id' => $ligne['tiers_id'] ?? null,
            'libelle' => 'Ligne de test AN',
        ]);
    })->all();
}

it('reporte les comptes de bilan et le resultat sans classes 6 et 7', function (): void {
    $banque = compteANP('5121', 5);
    $charge = compteANP('606', 6);
    $produit = compteANP('706', 7);
    $fournisseur = Tiers::factory()->create();
    $client = Tiers::factory()->create();
    $compte401 = Compte::ofNumeroSysteme('401');
    $compte411 = Compte::ofNumeroSysteme('411');

    ecritureANP('2025-09-10', [
        ['compte' => $banque, 'debit' => '100.00'],
        ['compte' => $produit, 'credit' => '100.00'],
    ]);
    ecritureANP('2026-03-15', [
        ['compte' => $charge, 'debit' => '40.00'],
        ['compte' => $compte401, 'credit' => '40.00', 'tiers_id' => (int) $fournisseur->id],
    ]);
    [$creance] = ecritureANP('2026-04-01', [
        ['compte' => $compte411, 'debit' => '25.00', 'tiers_id' => (int) $client->id],
        ['compte' => $produit, 'credit' => '25.00'],
    ]);
    [, $reglement] = ecritureANP('2026-04-15', [
        ['compte' => $banque, 'debit' => '25.00'],
        ['compte' => $compte411, 'credit' => '25.00', 'tiers_id' => (int) $client->id],
    ]);
    DB::table('transaction_lignes')
        ->whereIn('id', [$creance->id, $reglement->id])
        ->update(['lettrage_code' => 'LT-ANP']);

    $preview = app(ANouveauPreviewBuilder::class)->build(2025);
    $lignes = collect($preview->lignes);

    expect($preview->dateCible->toDateString())->toBe('2026-09-01')
        ->and($preview->equilibree())->toBeTrue()
        ->and($preview->totalDebit)->toBe('125.00')
        ->and($preview->totalCredit)->toBe('125.00')
        ->and($lignes->pluck('numero_pcg'))->toContain('401', '5121', '120')
        ->and($lignes->pluck('numero_pcg'))->not->toContain('411', '606', '706')
        ->and($lignes->firstWhere('numero_pcg', '401')['tiers_id'])->toBe((int) $fournisseur->id)
        ->and($lignes->firstWhere('numero_pcg', '401')['operation_id'] ?? null)->toBeNull();
});

it('reporte chaque poste auxiliaire ouvert meme si le compte est globalement solde', function (): void {
    $compte401 = Compte::ofNumeroSysteme('401');
    $charge = compteANP('606A', 6);
    $banque = compteANP('512A', 5);
    $tiersA = Tiers::factory()->create();
    $tiersB = Tiers::factory()->create();

    ecritureANP('2026-01-10', [
        ['compte' => $charge, 'debit' => '10.01'],
        ['compte' => $compte401, 'credit' => '10.01', 'tiers_id' => (int) $tiersA->id],
    ]);
    ecritureANP('2026-01-11', [
        ['compte' => $compte401, 'debit' => '10.01', 'tiers_id' => (int) $tiersB->id],
        ['compte' => $banque, 'credit' => '10.01'],
    ]);

    $preview = app(ANouveauPreviewBuilder::class)->build(2025);
    $postes401 = collect($preview->lignes)->where('numero_pcg', '401')->values();

    expect($postes401)->toHaveCount(2)
        ->and($postes401->pluck('tiers_id')->all())
        ->toContain((int) $tiersA->id, (int) $tiersB->id)
        ->and($preview->equilibree())->toBeTrue();
});

it('ne reporte que le reliquat non lettré d une créance partiellement encaissée', function (): void {
    $compte411 = Compte::ofNumeroSysteme('411');
    $produit = compteANP('706-R', 7);
    $tiers = Tiers::factory()->create();

    [$creance] = ecritureANP('2026-08-20', [
        ['compte' => $compte411, 'debit' => '100.00', 'tiers_id' => (int) $tiers->id],
        ['compte' => $produit, 'credit' => '100.00'],
    ]);
    $creance->update(['debit' => '70.00', 'credit' => '0.00', 'montant' => '70.00']);

    $fractionReglee = $creance->replicate(['id', 'lettrage_code', 'deleted_at']);
    $fractionReglee->fill([
        'debit' => '30.00',
        'credit' => '0.00',
        'montant' => '30.00',
        'poste_tiers_parent_id' => (int) $creance->id,
        'lettrage_code' => 'LT-ANP-RELIQUAT',
    ]);
    $fractionReglee->save();

    $preview = app(ANouveauPreviewBuilder::class)->build(2025);
    $poste411 = collect($preview->lignes)
        ->first(fn (array $ligne): bool => $ligne['numero_pcg'] === '411');

    expect($poste411)->not->toBeNull()
        ->and($poste411['tiers_id'])->toBe((int) $tiers->id)
        ->and($poste411['debit'])->toBe('70.00')
        ->and($poste411['credit'])->toBe('0.00');
});

it('ne reporte pas un poste tiers entièrement lettré', function (): void {
    $compte411 = Compte::ofNumeroSysteme('411');
    $produit = compteANP('706-S', 7);
    $banque = compteANP('512-S', 5);
    $tiers = Tiers::factory()->create();

    [$creance] = ecritureANP('2026-08-20', [
        ['compte' => $compte411, 'debit' => '100.00', 'tiers_id' => (int) $tiers->id],
        ['compte' => $produit, 'credit' => '100.00'],
    ]);
    [, $reglement] = ecritureANP('2026-08-21', [
        ['compte' => $banque, 'debit' => '100.00'],
        ['compte' => $compte411, 'credit' => '100.00', 'tiers_id' => (int) $tiers->id],
    ]);
    DB::table('transaction_lignes')
        ->whereIn('id', [(int) $creance->id, (int) $reglement->id])
        ->update(['lettrage_code' => 'LT-ANP-SOLDE']);

    $preview = app(ANouveauPreviewBuilder::class)->build(2025);

    expect(collect($preview->lignes)->contains(
        fn (array $ligne): bool => $ligne['numero_pcg'] === '411'
            && $ligne['tiers_id'] === (int) $tiers->id
    ))->toBeFalse();
});

it('porte un deficit au debit du compte 129 au centime exact', function (): void {
    $banque = compteANP('512D', 5);
    $charge = compteANP('606D', 6);

    ecritureANP('2026-08-31', [
        ['compte' => $charge, 'debit' => '0.03'],
        ['compte' => $banque, 'credit' => '0.03'],
    ]);

    $preview = app(ANouveauPreviewBuilder::class)->build(2025);
    $resultat = collect($preview->lignes)->firstWhere('numero_pcg', '129');

    expect($resultat['debit'])->toBe('0.03')
        ->and($resultat['credit'])->toBe('0.00')
        ->and($preview->totalDebit)->toBe('0.03')
        ->and($preview->totalCredit)->toBe('0.03');
});

it('refuse un poste 401 ou 411 sans tiers', function (): void {
    $compte401 = Compte::ofNumeroSysteme('401');
    $charge = compteANP('606T', 6);

    ecritureANP('2026-02-01', [
        ['compte' => $charge, 'debit' => '12.00'],
        ['compte' => $compte401, 'credit' => '12.00'],
    ]);

    expect(fn () => app(ANouveauPreviewBuilder::class)->build(2025))
        ->toThrow(ANouveauInvalideException::class, 'sans tiers');
});
