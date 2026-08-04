<?php

declare(strict_types=1);

use App\Exceptions\Compta\CompteIncorrectException;
use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Compta\EcritureGenerator;
use Carbon\Carbon;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
});

it('refuse un compte de classe 2 sans le drapeau', function (): void {
    $generator = app(EcritureGenerator::class);
    $compte2 = Compte::factory()->create(['numero_pcg' => '2188', 'classe' => 2]);
    $tiers = Tiers::factory()->create();

    expect(fn () => $generator->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compte2, 'montant' => 3000.0]],
        dateConstatation: Carbon::parse('2026-09-12'),
    ))->toThrow(CompteIncorrectException::class);
});

it('accepte un compte de classe 2 avec le drapeau', function (): void {
    $generator = app(EcritureGenerator::class);
    $compte2 = Compte::factory()->create(['numero_pcg' => '2188', 'classe' => 2]);
    $tiers = Tiers::factory()->create();

    $tx = $generator->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compte2, 'montant' => 3000.0]],
        dateConstatation: Carbon::parse('2026-09-12'),
        autoriseImmobilisation: true,
    );

    expect($tx->equilibree)->toBeTrue()
        ->and($tx->lignes->firstWhere('compte_id', (int) $compte2->id)->debit)->toEqual('3000.00');
});

it('refuse une classe autre que 2 ou 6 même avec le drapeau', function (): void {
    $generator = app(EcritureGenerator::class);
    $compte7 = Compte::factory()->create(['numero_pcg' => '706', 'classe' => 7]);
    $tiers = Tiers::factory()->create();

    expect(fn () => $generator->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compte7, 'montant' => 100.0]],
        dateConstatation: Carbon::parse('2026-09-12'),
        autoriseImmobilisation: true,
    ))->toThrow(CompteIncorrectException::class);
});
