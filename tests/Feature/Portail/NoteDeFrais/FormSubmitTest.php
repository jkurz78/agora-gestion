<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Livewire\Portail\NoteDeFrais\Form;
use App\Models\Compte;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

// DC-10a : helper compte-first — compte de charge (classe 6) pour les lignes NDF.
function compteChargeNdfFS(int $assoId): \App\Models\Compte
{
    static $seq = 0;
    $seq++;

    return \App\Models\Compte::create([
        'association_id' => $assoId,
        'numero_pcg' => '625'.$seq,
        'intitule' => 'Charge NDF '.$seq,
        'classe' => 6,
        'actif' => true,
    ]);
}

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id, 'pour_depenses' => true]);
    Auth::guard('tiers-portail')->login($this->tiers);
    Storage::fake('local');
});

// ---------------------------------------------------------------------------
// Test 1 : Édition brouillon existant → form pré-rempli
// ---------------------------------------------------------------------------

it('form edit: brouillon existant pré-remplit le formulaire', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais Paris Mars',
        'date' => now()->subDays(5)->format('Y-m-d'),
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais/{$ndf->id}/edit")
        ->assertStatus(200)
        ->assertSee('Modifier la note de frais')
        ->assertSee('Frais Paris Mars');
});

// ---------------------------------------------------------------------------
// Test 2 : Édition NDF soumise → 200 (autorisé depuis Changement 1)
// ---------------------------------------------------------------------------

it('form edit: NDF soumise est éditable (200)', function () {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais soumis éditables',
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais/{$ndf->id}/edit")
        ->assertStatus(200)
        ->assertSee('Modifier la note de frais');
});

// ---------------------------------------------------------------------------
// Test 3 : Édition NDF d'un autre Tiers → 403
// ---------------------------------------------------------------------------

it('form edit: NDF d\'un autre tiers retourne 403', function () {
    $autreTiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais/{$ndf->id}/edit")
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Test 4 : submit valide → NDF passe à Soumise, redirect show
// ---------------------------------------------------------------------------

it('form submit: brouillon valide soumis passe à statut Soumise', function () {
    $sc = compteChargeNdfFS($this->asso->id);
    $assoId = (int) $this->asso->id;

    // Create a brouillon with a valid ligne + PJ
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais valides',
        'date' => now()->subDays(5)->format('Y-m-d'),
    ]);
    $ligne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'compte_id' => $sc->id,
        'montant' => 50.00,
        'piece_jointe_path' => "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
    ]);

    // Store the fake PJ file
    Storage::disk('local')->put(
        "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
        'fake pdf content'
    );

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Form;
    $component->mount($this->asso, $ndf);
    $component->submit();

    $ndf->refresh();
    expect($ndf->statut)->toBe(StatutNoteDeFrais::Soumise)
        ->and($ndf->submitted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 5 : submit invalide (date future) → erreur, reste à Brouillon
// ---------------------------------------------------------------------------

it('form submit: date future retourne erreur et laisse en brouillon', function () {
    $sc = compteChargeNdfFS($this->asso->id);
    $assoId = (int) $this->asso->id;

    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais futurs',
        'date' => '2030-01-01', // future date
    ]);
    $ligne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'compte_id' => $sc->id,
        'montant' => 50.00,
        'piece_jointe_path' => "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
    ]);
    Storage::disk('local')->put(
        "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
        'fake pdf content'
    );

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Form;
    $component->mount($this->asso, $ndf);
    $component->submit();

    $ndf->refresh();
    expect($ndf->statut)->toBe(StatutNoteDeFrais::Brouillon);
    expect($component->getErrorBag()->has('submit'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 6 : submit sans PJ → erreur
// ---------------------------------------------------------------------------

it('form submit: ligne sans pièce jointe retourne erreur', function () {
    $sc = compteChargeNdfFS($this->asso->id);

    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais sans PJ',
        'date' => now()->subDays(5)->format('Y-m-d'),
    ]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'compte_id' => $sc->id,
        'montant' => 50.00,
        'piece_jointe_path' => null, // No PJ
    ]);

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Form;
    $component->mount($this->asso, $ndf);
    $component->submit();

    $ndf->refresh();
    expect($ndf->statut)->toBe(StatutNoteDeFrais::Brouillon);
    expect($component->getErrorBag()->has('submit'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 7 : removeLigne sur NDF chargée par noteDeFraisId (résistance rehydratation)
// ---------------------------------------------------------------------------

it('removeLigne: fonctionne sur une NDF existante chargée par noteDeFraisId', function () {
    $sc = compteChargeNdfFS($this->asso->id);

    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);
    $ligne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'compte_id' => $sc->id,
        'montant' => 30.00,
    ]);

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Form;
    $component->mount($this->asso, $ndf);

    // Simule la rehydratation : noteDeFraisId est un int, pas un objet Eloquent
    expect($component->noteDeFraisId)->toBe((int) $ndf->id);
    expect($component->lignes)->toHaveCount(1);

    $component->removeLigne(0);

    expect($component->lignes)->toHaveCount(0);
    expect(NoteDeFraisLigne::find((int) $ligne->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 8 : deleteNdf sur NDF chargée par noteDeFraisId
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Test 9 : Pas de doublon NDF si submit échoue puis est retenté
// ---------------------------------------------------------------------------

it('submit: ne crée pas de doublon si submit échoue puis est retenté', function () {
    $assoId = (int) $this->asso->id;
    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Form;
    $component->mount($this->asso);

    // Prépare une ligne standard sans compte_id pour forcer l'échec du submit
    // (la validation métier exige compte_id sur les lignes standard)
    $component->lignes = [[
        'id' => null,
        'type' => 'standard',
        'compte_id' => null,
        'operation_id' => null,
        'seance' => null,
        'libelle' => 'Repas client',
        'montant' => '42.50',
        'piece_jointe_path' => "associations/{$assoId}/notes-de-frais/1/ligne-1.pdf",
        'justif' => null,
        'cv_fiscaux' => null,
        'distance_km' => null,
        'bareme_eur_km' => null,
    ]];
    $component->libelle = 'NDF non-doublon';
    $component->dateInput = now()->subDay()->format('Y-m-d');

    // Premier submit → échoue (compte_id manquant)
    $component->submit();

    expect($component->getErrorBag()->has('submit'))->toBeTrue();

    // Un seul NDF doit exister en DB (créé par le premier saveDraft)
    expect(NoteDeFrais::count())->toBe(1);

    // Second submit → échoue toujours (même raison) mais NE crée pas de doublon
    $component->submit();

    expect(NoteDeFrais::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Test 10 : deleteNdf sur NDF chargée par noteDeFraisId
// ---------------------------------------------------------------------------

it('deleteNdf: supprime la NDF récupérée via noteDeFraisId', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Form;
    $component->mount($this->asso, $ndf);

    expect($component->noteDeFraisId)->toBe((int) $ndf->id);

    $component->deleteNdf();

    $ndf->refresh();
    expect($ndf->trashed())->toBeTrue();
});
