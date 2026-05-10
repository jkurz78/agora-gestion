<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;

beforeEach(function (): void {
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
    $this->tiers = Tiers::factory()->create();
});

it('observer applique la formule active de la sous-catégorie (priorité 2)', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'mode' => 'exercice',
        'actif' => true,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
    ]);

    $adhesion = Adhesion::first();
    expect($adhesion)->not->toBeNull();
    expect($adhesion->formule_adhesion_id)->toBe($formule->id);
    expect($adhesion->exercice)->toBe(2025);
    expect($adhesion->date_debut)->toBeNull();
    expect($adhesion->date_fin)->toBeNull();
    expect((float) $adhesion->montant_facial)->toBe((float) $tx->montant_total);
    expect($adhesion->deductible_fiscal)->toBe($formule->deductible_fiscal);
    expect($adhesion->mode)->toBe('exercice');
    expect($adhesion->label_formule)->toBe($formule->nom);
});

it('observer en mode durée pose date_debut + date_fin et exercice null', function (): void {
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create([
        'sous_categorie_id' => $this->sc->id,
        'actif' => true,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
    ]);

    $adhesion = Adhesion::first();
    expect($adhesion->formule_adhesion_id)->toBe($formule->id);
    expect($adhesion->date_debut?->toDateString())->toBe('2025-10-15');
    expect($adhesion->date_fin?->toDateString())->toBe('2026-10-14');
    expect($adhesion->exercice)->toBeNull();
});

it('observer applique la formule depuis le mapping HelloAsso (priorité 1)', function (): void {
    // Formule active sur la sous-cat : ne doit PAS être choisie (priorité 2 < priorité 1)
    FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'actif' => true,
        'nom' => 'Adhésion sous-cat fallback',
    ]);

    // Formule HelloAsso : auto-créée par la sync avec helloasso_form_slug + helloasso_tier_id
    $formuleHelloAsso = FormuleAdhesion::factory()->modeDuree(12)->create([
        'sous_categorie_id' => $this->sc->id,
        'actif' => false, // pas active via priorité 2 — choisie via priorité 1 (lookup direct)
        'nom' => 'Adhésion HelloAsso',
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 999,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
        'helloasso_payment_id' => 12345,
        'helloasso_form_slug' => 'cotisation-2025',
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
        'helloasso_tier_id' => 999,
    ]);

    $adhesion = Adhesion::first();
    expect($adhesion->formule_adhesion_id)->toBe($formuleHelloAsso->id);
    expect($adhesion->date_debut?->toDateString())->toBe('2025-10-15');
    expect($adhesion->date_fin?->toDateString())->toBe('2026-10-14');
});

it('observer crée une adhésion legacy si pas de formule paramétrée', function (): void {
    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
    ]);

    $adhesion = Adhesion::first();
    expect($adhesion)->not->toBeNull();
    expect($adhesion->formule_adhesion_id)->toBeNull();
    expect($adhesion->exercice)->toBe(2025);
});

it('observer reste idempotent (multi-cotisations même exercice)', function (): void {
    FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'mode' => 'exercice',
        'actif' => true,
    ]);

    foreach (['2025-10-01', '2025-12-15'] as $d) {
        $tx = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'date' => $d,
        ]);
        TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'sous_categorie_id' => $this->sc->id,
        ]);
    }

    expect(Adhesion::count())->toBe(1);
});

it('observer reste idempotent en mode durée (même date_debut)', function (): void {
    FormuleAdhesion::factory()->modeDuree(12)->create([
        'sous_categorie_id' => $this->sc->id,
        'actif' => true,
    ]);

    foreach (['2025-10-15', '2025-10-15'] as $date) {
        $tx = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'date' => $date,
        ]);
        TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'sous_categorie_id' => $this->sc->id,
        ]);
    }

    expect(Adhesion::count())->toBe(1);
});

it('observer pose mode illimite avec date_fin null', function (): void {
    $formule = FormuleAdhesion::factory()->modeIllimite()->create([
        'sous_categorie_id' => $this->sc->id,
        'actif' => true,
        'nom' => 'Adhésion à vie',
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
    ]);

    $adhesion = Adhesion::first();
    expect($adhesion->mode)->toBe('illimite');
    expect($adhesion->date_debut?->toDateString())->toBe('2025-10-15');
    expect($adhesion->date_fin)->toBeNull();
    expect($adhesion->exercice)->toBeNull();
    expect($adhesion->label_formule)->toBe('Adhésion à vie');
});
