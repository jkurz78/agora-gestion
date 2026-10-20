<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\EcritureGenerator;
use App\Services\RemiseBancaireService;
use Illuminate\Support\Facades\View;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
});

// Bordereau imprimé le 2026-10-20 : le total était doublé, l'écriture de dépôt
// en banque (T4) figurant parmi les chèques remis.
it('n imprime que les pièces remises, pas l écriture de dépôt en banque', function (): void {
    $cheques = collect([30.00, 45.00])->map(fn (float $montant): Transaction => app(EcritureGenerator::class)
        ->pourRecetteComptant(
            tiers: Tiers::factory()->create(),
            ventilations: [['compte' => $this->compte706, 'montant' => $montant]],
            mode: ModePaiement::Cheque,
            compteTresorerie: $this->compte512X,
            date: new DateTimeImmutable('2026-05-20'),
            libelle: 'Chèque',
        ));

    $service = app(RemiseBancaireService::class);
    $remise = $service->creer([
        'date' => '2026-05-21',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
    ]);
    $service->comptabiliser($remise, $cheques->pluck('id')->all());

    expect(Transaction::where('remise_id', $remise->id)->count())->toBe(3); // 2 chèques + T4

    $donnees = null;
    View::composer('pdf.remise-bancaire', function ($vue) use (&$donnees): void {
        $donnees = $vue->getData();
    });

    $this->get(route('banques.remises.pdf', $remise))->assertOk();

    expect($donnees['transactions']->pluck('id')->sort()->values()->all())
        ->toBe($cheques->pluck('id')->sort()->values()->all())
        ->and((float) $donnees['transactions']->sum('montant_total'))->toBe(75.0)
        ->and($donnees['montantTotal'])->toBe(75.0);
});
