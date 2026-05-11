<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Adhesion\NouvelleAdhesionDTO;
use App\Services\AdhesionService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
    $this->tiers = Tiers::factory()->create();
    $this->user = User::factory()->create();
    $this->compte = CompteBancaire::factory()->create();
});

it('creerDepuisTransaction : formule duree_jours=10, tx date 2025-10-15 → date_fin=2025-10-24', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'mode' => 'duree',
        'duree_mois' => null,
        'duree_jours' => 10,
        'actif' => true,
    ]);

    $service = app(AdhesionService::class);

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
    ]);

    $adhesion = $service->creerDepuisTransaction($tx);

    expect($adhesion)->not->toBeNull();
    expect($adhesion->date_debut->toDateString())->toBe('2025-10-15');
    expect($adhesion->date_fin->toDateString())->toBe('2025-10-24'); // 10 jours inclusifs
    expect($adhesion->exercice)->toBeNull();
});

it('creerDepuisWizard : formule duree_jours=300 (saison ~10 mois), date_debut=2025-09-01 → date_fin=2026-06-27', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'mode' => 'duree',
        'duree_mois' => null,
        'duree_jours' => 300,
        'actif' => true,
    ]);

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: null,
        dateDebut: Carbon::parse('2025-09-01'),
        montant: 0,
        notes: null,
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );

    $service = app(AdhesionService::class);
    $adhesion = $service->creerDepuisWizard($dto, $this->user);

    expect($adhesion->date_debut->toDateString())->toBe('2025-09-01');
    expect($adhesion->date_fin->toDateString())->toBe('2026-06-27'); // 300 jours inclusifs = 2025-09-01 + 300j - 1j
    expect($adhesion->exercice)->toBeNull();
});

it('creerDepuisTransaction : formule duree_mois=12 reste inchangé (régression)', function (): void {
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create([
        'sous_categorie_id' => $this->sc->id,
        'actif' => true,
    ]);

    $service = app(AdhesionService::class);

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
    ]);

    $adhesion = $service->creerDepuisTransaction($tx);

    expect($adhesion)->not->toBeNull();
    expect($adhesion->date_debut->toDateString())->toBe('2025-10-15');
    expect($adhesion->date_fin->toDateString())->toBe('2026-10-14'); // 12 mois - 1 jour
    expect($adhesion->exercice)->toBeNull();
});
