<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

function ecritureTenantANPT(string $numeroBanque, string $montant): void
{
    $banque = Compte::create([
        'numero_pcg' => $numeroBanque,
        'intitule' => 'Banque '.$numeroBanque,
        'classe' => 5,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $produit = Compte::create([
        'numero_pcg' => '706-'.$numeroBanque,
        'intitule' => 'Produit '.$numeroBanque,
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => '2026-05-01',
        'libelle' => 'Écriture tenant AN',
        'montant_total' => $montant,
        'mode_paiement' => null,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Od,
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
        'compte_id' => $produit->id,
        'debit' => '0.00',
        'credit' => $montant,
        'montant' => $montant,
    ]);
}

it('exclut strictement les ecritures du tenant voisin', function (): void {
    $associationCourante = TenantContext::current();
    SystemeSeeder::seed();
    ecritureTenantANPT('512-C', '10.00');

    $associationVoisine = Association::factory()->create();
    TenantContext::boot($associationVoisine);
    SystemeSeeder::seed();
    ecritureTenantANPT('512-V', '999.00');

    TenantContext::boot($associationCourante);
    $preview = app(ANouveauPreviewBuilder::class)->build(2025);

    expect($preview->totalDebit)->toBe('10.00')
        ->and($preview->totalCredit)->toBe('10.00')
        ->and(collect($preview->lignes)->pluck('numero_pcg')->all())
        ->toContain('512-C')
        ->not->toContain('512-V');
});
