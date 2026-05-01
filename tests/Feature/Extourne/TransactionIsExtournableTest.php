<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;

test('recette éligible — isExtournable returns true', function (): void {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'statut_reglement' => StatutReglement::EnAttente,
        'helloasso_order_id' => null,
        'extournee_at' => null,
    ]);

    expect($tx->isExtournable())->toBeTrue();
});

test('dépense — isExtournable returns false', function (): void {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
    ]);

    expect($tx->isExtournable())->toBeFalse();
});

test('recette déjà extournée (extournee_at non null) — isExtournable returns false', function (): void {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'extournee_at' => now(),
    ]);

    expect($tx->isExtournable())->toBeFalse();
});

test('recette qui est elle-même une extourne — isExtournable returns false', function (): void {
    $extourne = Extourne::factory()->create();
    $miroir = $extourne->extourne;

    expect($miroir->isExtournable())->toBeFalse();
    expect($miroir->estUneExtourne)->toBeTrue();
});

test('recette qui n est pas une extourne — estUneExtourne returns false', function (): void {
    $tx = Transaction::factory()->create(['type' => TypeTransaction::Recette]);

    expect($tx->estUneExtourne)->toBeFalse();
});

test('recette HelloAsso — isExtournable returns false', function (): void {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'helloasso_order_id' => 999,
    ]);

    expect($tx->isExtournable())->toBeFalse();
});

test('recette portée par facture validée — isExtournable returns false', function (): void {
    $facture = createFacture(StatutFacture::Validee);
    $tx = Transaction::factory()->create(['type' => TypeTransaction::Recette]);
    $facture->transactions()->attach($tx->id);

    expect($tx->fresh()->isExtournable())->toBeFalse();
});

test('recette portée par facture brouillon — isExtournable returns true (pas de blocage)', function (): void {
    $facture = createFacture(StatutFacture::Brouillon);
    $tx = Transaction::factory()->create(['type' => TypeTransaction::Recette]);
    $facture->transactions()->attach($tx->id);

    expect($tx->fresh()->isExtournable())->toBeTrue();
});

test('recette portée par facture annulée — isExtournable returns true (pas de blocage)', function (): void {
    $facture = createFacture(StatutFacture::Annulee);
    $tx = Transaction::factory()->create(['type' => TypeTransaction::Recette]);
    $facture->transactions()->attach($tx->id);

    expect($tx->fresh()->isExtournable())->toBeTrue();
});

function createFacture(StatutFacture $statut): Facture
{
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    return Facture::create([
        'date' => now()->toDateString(),
        'statut' => $statut,
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2026,
    ]);
}

test('recette soft-deleted — isExtournable returns false', function (): void {
    $tx = Transaction::factory()->create(['type' => TypeTransaction::Recette]);
    $tx->delete();

    expect($tx->fresh()->isExtournable())->toBeFalse();
});

test('extourneeVers relation returns the Extourne where this is the origin', function (): void {
    $extourne = Extourne::factory()->create();
    $origine = $extourne->origine;

    expect($origine->extourneeVers)->toBeInstanceOf(Extourne::class);
    expect($origine->extourneeVers->id)->toBe($extourne->id);
});

test('extournePour relation returns the Extourne where this is the mirror', function (): void {
    $extourne = Extourne::factory()->create();
    $miroir = $extourne->extourne;

    expect($miroir->extournePour)->toBeInstanceOf(Extourne::class);
    expect($miroir->extournePour->id)->toBe($extourne->id);
});
