<?php

declare(strict_types=1);

use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

function deleteService(): NoteDeFraisService
{
    return new NoteDeFraisService;
}

// ---------------------------------------------------------------------------
// 1. Softdelete d'un brouillon
// ---------------------------------------------------------------------------

it('delete: softdelete appliqué sur la NDF brouillon', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create(['tiers_id' => $tiers->id]);

    expect(NoteDeFrais::count())->toBe(1);

    deleteService()->delete($ndf);

    expect(NoteDeFrais::count())->toBe(0)
        ->and(NoteDeFrais::withTrashed()->count())->toBe(1)
        ->and(NoteDeFrais::withTrashed()->find($ndf->id)?->deleted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. Les fichiers PJ des lignes sont supprimés du storage
// ---------------------------------------------------------------------------

it('delete: supprime les fichiers PJ des lignes du storage local', function () {
    Storage::fake('local');

    $associationId = TenantContext::currentId();
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create(['tiers_id' => $tiers->id]);

    $sousCategorie = SousCategorie::factory()->create();

    $path1 = "associations/{$associationId}/notes-de-frais/{$ndf->id}/ligne-1.pdf";
    $path2 = "associations/{$associationId}/notes-de-frais/{$ndf->id}/ligne-2.pdf";

    Storage::disk('local')->put($path1, 'fake content 1');
    Storage::disk('local')->put($path2, 'fake content 2');

    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'piece_jointe_path' => $path1,
    ]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'piece_jointe_path' => $path2,
    ]);

    Storage::disk('local')->assertExists($path1);
    Storage::disk('local')->assertExists($path2);

    deleteService()->delete($ndf);

    Storage::disk('local')->assertMissing($path1);
    Storage::disk('local')->assertMissing($path2);
});

// ---------------------------------------------------------------------------
// 3. Les lignes sans PJ ne posent pas de problème
// ---------------------------------------------------------------------------

it('delete: fonctionne quand les lignes n\'ont pas de PJ', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create(['tiers_id' => $tiers->id]);

    $sousCategorie = SousCategorie::factory()->create();
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'piece_jointe_path' => null,
    ]);

    deleteService()->delete($ndf);

    expect(NoteDeFrais::withTrashed()->find($ndf->id)?->deleted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 4. Refus si NDF non-brouillon (soumise)
// ---------------------------------------------------------------------------

it('delete: refuse de supprimer une NDF soumise', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->soumise()->create(['tiers_id' => $tiers->id]);

    expect(fn () => deleteService()->delete($ndf))
        ->toThrow(DomainException::class);

    // NDF toujours présente
    expect(NoteDeFrais::find($ndf->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 5. Refus si NDF validée
// ---------------------------------------------------------------------------

it('delete: refuse de supprimer une NDF validée', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->validee()->create(['tiers_id' => $tiers->id]);

    expect(fn () => deleteService()->delete($ndf))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// 6. Fichiers inexistants en storage ignorés (robustesse)
// ---------------------------------------------------------------------------

it('delete: ne plante pas si un fichier PJ référencé n\'existe pas en storage', function () {
    Storage::fake('local');

    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create(['tiers_id' => $tiers->id]);

    $sousCategorie = SousCategorie::factory()->create();
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'piece_jointe_path' => 'associations/999/notes-de-frais/999/inexistant.pdf',
    ]);

    // Ne doit pas lever d'exception
    deleteService()->delete($ndf);

    expect(NoteDeFrais::withTrashed()->find($ndf->id)?->deleted_at)->not->toBeNull();
});
