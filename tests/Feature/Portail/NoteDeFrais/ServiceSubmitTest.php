<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use Illuminate\Validation\ValidationException;

function submitService(): NoteDeFraisService
{
    return new NoteDeFraisService;
}

function validLigne(int $noteDeFraisId, array $override = []): NoteDeFraisLigne
{
    $sousCategorie = SousCategorie::factory()->create();

    return NoteDeFraisLigne::factory()->create(array_merge([
        'note_de_frais_id' => $noteDeFraisId,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => 25.00,
        'piece_jointe_path' => 'associations/1/notes-de-frais/1/ligne-1.pdf',
    ], $override));
}

// ---------------------------------------------------------------------------
// 1. Submit brouillon valide → statut=Soumise + submitted_at renseigné
// ---------------------------------------------------------------------------

it('submit: brouillon valide passe au statut Soumise avec submitted_at', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'tiers_id' => $tiers->id,
        'date' => now()->subDay()->format('Y-m-d'),
        'libelle' => 'Déplacement valide',
    ]);
    validLigne($ndf->id);

    submitService()->submit($ndf);

    $ndf->refresh();

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Soumise)
        ->and($ndf->submitted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. Refus : date future
// ---------------------------------------------------------------------------

it('submit: refuse une date future', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'tiers_id' => $tiers->id,
        'date' => now()->addDays(5)->format('Y-m-d'),
        'libelle' => 'Déplacement futur',
    ]);
    validLigne($ndf->id);

    expect(fn () => submitService()->submit($ndf))
        ->toThrow(ValidationException::class);

    try {
        submitService()->submit($ndf);
    } catch (ValidationException $e) {
        $errors = $e->errors();
        $allMessages = array_merge(...array_values($errors));
        expect(implode(' ', $allMessages))->toContain('La date ne peut pas être dans le futur.');
    }
});

// ---------------------------------------------------------------------------
// 3. Refus : libellé vide
// ---------------------------------------------------------------------------

it('submit: refuse un libellé vide', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'tiers_id' => $tiers->id,
        'date' => now()->subDay()->format('Y-m-d'),
        'libelle' => '',
    ]);
    validLigne($ndf->id);

    try {
        submitService()->submit($ndf);
        $this->fail('ValidationException attendue');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        $allMessages = array_merge(...array_values($errors));
        expect(implode(' ', $allMessages))->toContain('Le libellé est obligatoire.');
    }
});

// ---------------------------------------------------------------------------
// 4. Refus : 0 ligne
// ---------------------------------------------------------------------------

it('submit: refuse si aucune ligne', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'tiers_id' => $tiers->id,
        'date' => now()->subDay()->format('Y-m-d'),
        'libelle' => 'Déplacement sans ligne',
    ]);
    // Pas de ligne créée

    try {
        submitService()->submit($ndf);
        $this->fail('ValidationException attendue');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        $allMessages = array_merge(...array_values($errors));
        expect(implode(' ', $allMessages))->toContain('Au moins une ligne est requise.');
    }
});

// ---------------------------------------------------------------------------
// 5. Refus : ligne sans sous-catégorie
// ---------------------------------------------------------------------------

it('submit: refuse une ligne sans sous-catégorie', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'tiers_id' => $tiers->id,
        'date' => now()->subDay()->format('Y-m-d'),
        'libelle' => 'Déplacement',
    ]);
    validLigne($ndf->id, ['sous_categorie_id' => null]);

    try {
        submitService()->submit($ndf);
        $this->fail('ValidationException attendue');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        $allMessages = array_merge(...array_values($errors));
        expect(implode(' ', $allMessages))->toContain('La sous-catégorie est obligatoire.');
    }
});

// ---------------------------------------------------------------------------
// 6. Refus : montant ≤ 0
// ---------------------------------------------------------------------------

it('submit: refuse un montant nul ou négatif', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'tiers_id' => $tiers->id,
        'date' => now()->subDay()->format('Y-m-d'),
        'libelle' => 'Déplacement',
    ]);
    validLigne($ndf->id, ['montant' => 0.00]);

    try {
        submitService()->submit($ndf);
        $this->fail('ValidationException attendue');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        $allMessages = array_merge(...array_values($errors));
        expect(implode(' ', $allMessages))->toContain('Le montant doit être supérieur à zéro.');
    }
});

// ---------------------------------------------------------------------------
// 7. Refus : ligne sans PJ
// ---------------------------------------------------------------------------

it('submit: refuse une ligne sans pièce jointe', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'tiers_id' => $tiers->id,
        'date' => now()->subDay()->format('Y-m-d'),
        'libelle' => 'Déplacement',
    ]);
    validLigne($ndf->id, ['piece_jointe_path' => null]);

    try {
        submitService()->submit($ndf);
        $this->fail('ValidationException attendue');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        $allMessages = array_merge(...array_values($errors));
        expect(implode(' ', $allMessages))->toContain('Un justificatif est obligatoire pour chaque ligne.');
    }
});

// ---------------------------------------------------------------------------
// 8. Refus : NDF déjà soumise
// ---------------------------------------------------------------------------

it('submit: refuse une NDF déjà soumise (DomainException)', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->soumise()->create(['tiers_id' => $tiers->id]);

    expect(fn () => submitService()->submit($ndf))
        ->toThrow(DomainException::class);
});
