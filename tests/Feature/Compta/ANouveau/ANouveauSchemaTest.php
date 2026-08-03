<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Enums\TypeTransaction;
use App\Models\ANouveauGeneration;
use App\Models\ANouveauLigneOrigine;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    SystemeSeeder::seed();
});

it('provisionne les fondations comptables des a-nouveaux', function (): void {
    expect(JournalComptable::AN->value)->toBe('an')
        ->and(JournalComptable::AN->label())->toBe('Journal des à-nouveaux')
        ->and(TypeTransaction::AN->value)->toBe('an')
        ->and(TypeTransaction::AN->label())->toBe('À-nouveau');

    foreach (['102', '120', '129'] as $numero) {
        $compte = Compte::ofNumero($numero);

        expect($compte)->not->toBeNull()
            ->and($compte?->est_systeme)->toBeTrue()
            ->and($compte?->lettrable)->toBeFalse();
    }
});

it('supporte une generation tenant-scopee avec filiation auxiliaire', function (): void {
    $transaction = Transaction::factory()->create([
        'type' => TypeTransaction::AN,
        'journal' => JournalComptable::AN,
        'type_ecriture' => 'an',
    ]);

    $generation = ANouveauGeneration::create([
        'exercice_source' => 2024,
        'exercice_cible' => 2025,
        'transaction_id' => $transaction->id,
        'origine' => OrigineANouveau::Cloture,
        'statut' => StatutANouveau::Active,
    ]);

    $compte401 = Compte::ofNumeroSysteme('401');
    $ligneSource = TransactionLigne::factory()->create([
        'transaction_id' => Transaction::factory()->create()->id,
        'compte_id' => $compte401->id,
        'debit' => '10.00',
        'credit' => '0.00',
    ]);
    $ligneAN = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compte401->id,
        'debit' => '10.00',
        'credit' => '0.00',
    ]);

    $origine = ANouveauLigneOrigine::create([
        'generation_id' => $generation->id,
        'ligne_an_id' => $ligneAN->id,
        'ligne_source_id' => $ligneSource->id,
        'ligne_racine_id' => $ligneSource->id,
    ]);

    expect($generation->association_id)->toBe((int) TenantContext::currentId())
        ->and($generation->origine)->toBe(OrigineANouveau::Cloture)
        ->and($generation->statut)->toBe(StatutANouveau::Active)
        ->and($generation->transaction->is($transaction))->toBeTrue()
        ->and($generation->origines)->toHaveCount(1)
        ->and($origine->association_id)->toBe((int) TenantContext::currentId())
        ->and($origine->ligneAN->is($ligneAN))->toBeTrue()
        ->and($origine->ligneSource->is($ligneSource))->toBeTrue()
        ->and($origine->ligneRacine->is($ligneSource))->toBeTrue()
        ->and(ANouveauGeneration::activePourCible(2025)?->is($generation))->toBeTrue();
});
