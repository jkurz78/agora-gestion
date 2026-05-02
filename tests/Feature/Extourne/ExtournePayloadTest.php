<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Transaction;

test('fromOrigine — date defaults to today', function (): void {
    $origine = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation Mr Dupont',
        'mode_paiement' => ModePaiement::Cheque,
    ]);

    $payload = ExtournePayload::fromOrigine($origine);

    expect($payload->date->isToday())->toBeTrue();
});

test('fromOrigine — libellé defaults to "Annulation - {origine.libelle}"', function (): void {
    $origine = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation Mr Dupont mars',
        'mode_paiement' => ModePaiement::Cheque,
    ]);

    $payload = ExtournePayload::fromOrigine($origine);

    expect($payload->libelle)->toBe('Annulation - Cotisation Mr Dupont mars');
});

test('fromOrigine — mode_paiement defaults to origine', function (): void {
    $origine = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'mode_paiement' => ModePaiement::Cheque,
    ]);

    $payload = ExtournePayload::fromOrigine($origine);

    expect($payload->modePaiement)->toBe(ModePaiement::Cheque);
});

test('fromOrigine — notes defaults to null', function (): void {
    $origine = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'mode_paiement' => ModePaiement::Cheque,
    ]);

    $payload = ExtournePayload::fromOrigine($origine);

    expect($payload->notes)->toBeNull();
});

test('fromOrigine — overrides accept libellé and mode_paiement', function (): void {
    $origine = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation X',
        'mode_paiement' => ModePaiement::Cheque,
    ]);

    $payload = ExtournePayload::fromOrigine($origine, [
        'libelle' => 'Remboursement geste commercial',
        'mode_paiement' => ModePaiement::Virement,
        'notes' => 'Désistement séance 14/03',
    ]);

    expect($payload->libelle)->toBe('Remboursement geste commercial');
    expect($payload->modePaiement)->toBe(ModePaiement::Virement);
    expect($payload->notes)->toBe('Désistement séance 14/03');
});

test('fromOrigine — overrides accept date as Carbon or string', function (): void {
    $origine = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'mode_paiement' => ModePaiement::Cheque,
    ]);

    $payload1 = ExtournePayload::fromOrigine($origine, ['date' => '2026-04-30']);
    expect($payload1->date->toDateString())->toBe('2026-04-30');

    $payload2 = ExtournePayload::fromOrigine($origine, ['date' => now()->subDay()]);
    expect($payload2->date->isYesterday())->toBeTrue();
});
