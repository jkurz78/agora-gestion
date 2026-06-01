<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Transaction;

it('pose journal=vente à la création d\'une recette sans journal explicite', function () {
    $tx = Transaction::factory()->asRecette()->create(['journal' => null]);
    expect($tx->fresh()->journal)->toBe(JournalComptable::Vente);
});

it('pose journal=achat à la création d\'une dépense sans journal explicite', function () {
    $tx = Transaction::factory()->asDepense()->create(['journal' => null]);
    expect($tx->fresh()->journal)->toBe(JournalComptable::Achat);
});

it('préserve un journal explicite (banque) à la création', function () {
    $tx = Transaction::factory()->asRecette()->create(['journal' => JournalComptable::Banque]);
    expect($tx->fresh()->journal)->toBe(JournalComptable::Banque);
});

it('scopeOperationnel exclut le journal de banque', function () {
    $vente = Transaction::factory()->asRecette()->create(['journal' => JournalComptable::Vente]);
    $banque = Transaction::factory()->asRecette()->create(['journal' => JournalComptable::Banque]);

    $ids = Transaction::operationnel()->pluck('id')->all();

    expect($ids)->toContain($vente->id);
    expect($ids)->not->toContain($banque->id);
});

it('scopeOperationnel exclut le journal OD', function () {
    $vente = Transaction::factory()->asRecette()->create(['journal' => JournalComptable::Vente]);
    $od = Transaction::factory()->asRecette()->create(['journal' => JournalComptable::Od]);

    $ids = Transaction::operationnel()->pluck('id')->all();

    expect($ids)->toContain($vente->id);
    expect($ids)->not->toContain($od->id);
});
