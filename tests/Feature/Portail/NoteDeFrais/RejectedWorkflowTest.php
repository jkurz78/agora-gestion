<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Livewire\Portail\NoteDeFrais\Index;
use App\Livewire\Portail\NoteDeFrais\Show;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    Auth::guard('tiers-portail')->login($this->tiers);
    Storage::fake('local');
});

// ---------------------------------------------------------------------------
// 1. Service delete : tiers peut supprimer une NDF Rejetée (softdelete + PJ)
// ---------------------------------------------------------------------------

it('rejected workflow: tiers peut supprimer une NDF Rejetée (softdelete)', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);
    $path = "associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf";
    Storage::disk('local')->put($path, 'contenu fake');
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sc->id,
        'piece_jointe_path' => $path,
    ]);

    (new NoteDeFraisService)->delete($ndf);

    expect(NoteDeFrais::find($ndf->id))->toBeNull()
        ->and(NoteDeFrais::withTrashed()->find($ndf->id)?->deleted_at)->not->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

// ---------------------------------------------------------------------------
// 2. Service saveDraft : édition d'une NDF Rejetée → repasse en Brouillon,
//    motif_rejet vidé, submitted_at vidé
// ---------------------------------------------------------------------------

it('rejected workflow: saveDraft sur NDF Rejetée remet en Brouillon + vide motif_rejet', function () {
    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);
    $ndf = NoteDeFrais::factory()->rejetee('Justificatif illisible')->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF rejetée',
        'submitted_at' => now()->subDay(),
    ]);

    $data = [
        'id' => $ndf->id,
        'date' => '2026-04-20',
        'libelle' => 'NDF corrigée',
        'lignes' => [
            [
                'libelle' => 'Repas',
                'montant' => 15.00,
                'sous_categorie_id' => $sc->id,
                'piece_jointe_path' => null,
            ],
        ],
    ];

    $updated = (new NoteDeFraisService)->saveDraft($this->tiers, $data);
    $fresh = $updated->fresh();

    expect($fresh->statut)->toBe(StatutNoteDeFrais::Brouillon)
        ->and($fresh->motif_rejet)->toBeNull()
        ->and($fresh->submitted_at)->toBeNull()
        ->and($fresh->libelle)->toBe('NDF corrigée');
});

// ---------------------------------------------------------------------------
// 3. Après saveDraft sur Rejetée → submit() passe en Soumise
// ---------------------------------------------------------------------------

it('rejected workflow: après édition NDF Rejetée, resoumission passe en Soumise', function () {
    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF rejetée',
    ]);
    $path = "associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf";
    Storage::disk('local')->put($path, 'contenu fake');
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sc->id,
        'piece_jointe_path' => $path,
        'montant' => 25.00,
    ]);

    $service = new NoteDeFraisService;

    // saveDraft avec les données de la ligne existante
    $data = [
        'id' => $ndf->id,
        'date' => '2026-04-20',
        'libelle' => 'NDF corrigée',
        'lignes' => [
            [
                'libelle' => 'Repas',
                'montant' => 25.00,
                'sous_categorie_id' => $sc->id,
                'piece_jointe_path' => $path,
            ],
        ],
    ];
    $updated = $service->saveDraft($this->tiers, $data);

    expect($updated->fresh()->statut)->toBe(StatutNoteDeFrais::Brouillon);

    // Submit doit réussir
    $service->submit($updated->fresh());

    expect($updated->fresh()->statut)->toBe(StatutNoteDeFrais::Soumise)
        ->and($updated->fresh()->submitted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 4. Policy update + delete : Rejetée appartenant au tiers → autorisé
// ---------------------------------------------------------------------------

it('rejected workflow: policy update retourne true pour NDF Rejetée du tiers', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(Gate::forUser($this->tiers)->allows('update', $ndf))->toBeTrue();
});

it('rejected workflow: policy delete retourne true pour NDF Rejetée du tiers', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(Gate::forUser($this->tiers)->allows('delete', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 5. Policy : Rejetée d'un autre Tiers → refus isolation
// ---------------------------------------------------------------------------

it('rejected workflow: policy update retourne false pour NDF Rejetée d\'un autre tiers', function () {
    $autreTiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
    ]);

    expect(Gate::forUser($this->tiers)->denies('update', $ndf))->toBeTrue();
});

it('rejected workflow: policy delete retourne false pour NDF Rejetée d\'un autre tiers', function () {
    $autreTiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
    ]);

    expect(Gate::forUser($this->tiers)->denies('delete', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 6. UI Show : composant Show avec NDF Rejetée affiche boutons Modifier + Supprimer
// ---------------------------------------------------------------------------

it('rejected workflow: Show affiche boutons Modifier et Supprimer pour NDF Rejetée', function () {
    $ndf = NoteDeFrais::factory()->rejetee('Pièce jointe manquante')->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF rejetée affichage',
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSeeText('Rejetée')
        ->assertSee('Modifier')
        ->assertSee('Supprimer');
});

// ---------------------------------------------------------------------------
// 7. UI Index : bouton Modifier visible sur NDF Rejetée
// ---------------------------------------------------------------------------

it('rejected workflow: Index affiche bouton Modifier sur NDF Rejetée', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF rejetée liste',
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSee("notes-de-frais/{$ndf->id}/edit")
        ->assertSeeText('Modifier');
});

// ---------------------------------------------------------------------------
// 8. Bonus : saveDraft sur Rejetée sans resoumettre → reste Brouillon
// ---------------------------------------------------------------------------

it('rejected workflow: saveDraft sur Rejetée sans submit → NDF reste en Brouillon', function () {
    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF rejetée test bonus',
    ]);

    $data = [
        'id' => $ndf->id,
        'date' => '2026-04-20',
        'libelle' => 'NDF sans resoumission',
        'lignes' => [
            [
                'libelle' => 'Repas',
                'montant' => 12.00,
                'sous_categorie_id' => $sc->id,
                'piece_jointe_path' => null,
            ],
        ],
    ];

    $updated = (new NoteDeFraisService)->saveDraft($this->tiers, $data);

    // Pas d'appel à submit() — doit rester Brouillon
    expect($updated->fresh()->statut)->toBe(StatutNoteDeFrais::Brouillon);
});
