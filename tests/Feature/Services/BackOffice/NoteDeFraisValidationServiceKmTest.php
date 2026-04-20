<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutNoteDeFrais;
use App\Enums\TypeCategorie;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\NoteDeFrais\NoteDeFraisValidationService;
use App\Services\NoteDeFrais\ValidationData;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);

    $this->cat = Categorie::factory()->create([
        'association_id' => $this->asso->id,
        'type' => TypeCategorie::Depense->value,
    ]);
    $this->sc = SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->asso->id]);

    $this->service = app(NoteDeFraisValidationService::class);
});

it('peuple transaction_lignes.notes avec libelle + description km pour une ligne kilometrique', function () {
    $ndf = NoteDeFrais::create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'date' => now()->subDay(),
        'libelle' => 'NDF avril',
        'statut' => StatutNoteDeFrais::Soumise->value,
        'submitted_at' => now(),
    ]);

    Storage::disk('local')->put("associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf", 'carte-grise');

    NoteDeFraisLigne::create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Kilometrique->value,
        'libelle' => 'Paris-Rennes AG',
        'montant' => 267.12,
        'metadata' => [
            'cv_fiscaux' => 5,
            'distance_km' => 420,
            'bareme_eur_km' => 0.636,
        ],
        'sous_categorie_id' => $this->sc->id,
        'piece_jointe_path' => "associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
    ]);

    $data = new ValidationData(
        compte_id: $this->compte->id,
        mode_paiement: ModePaiement::Virement,
        date: now()->format('Y-m-d'),
    );

    $tx = $this->service->valider($ndf, $data);

    $ligneTx = $tx->lignes()->first();
    expect($ligneTx->notes)->toBe(
        'Paris-Rennes AG — Déplacement de 420 km avec un véhicule 5 CV au barème de 0,636 €/km'
    );
    expect($ligneTx->piece_jointe_path)->not->toBeNull();
});

it('conserve le comportement actuel pour une ligne standard (notes = libelle)', function () {
    $ndf = NoteDeFrais::create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'date' => now()->subDay(),
        'libelle' => 'NDF avril',
        'statut' => StatutNoteDeFrais::Soumise->value,
        'submitted_at' => now(),
    ]);

    Storage::disk('local')->put("associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf", 'justif');

    NoteDeFraisLigne::create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Standard->value,
        'libelle' => 'Stylos bureau',
        'montant' => 12.50,
        'sous_categorie_id' => $this->sc->id,
        'piece_jointe_path' => "associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
    ]);

    $data = new ValidationData(
        compte_id: $this->compte->id,
        mode_paiement: ModePaiement::Virement,
        date: now()->format('Y-m-d'),
    );

    $tx = $this->service->valider($ndf, $data);
    $ligneTx = $tx->lignes()->first();

    expect($ligneTx->notes)->toBe('Stylos bureau');
});
