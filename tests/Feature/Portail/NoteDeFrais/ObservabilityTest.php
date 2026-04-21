<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Observabilité — NoteDeFraisService (Portail Tiers Slice 2).
 *
 * Vérifie que les 4 événements métier sont loggés avec les bons payloads
 * et qu'aucun log ne contient un chemin de pièce jointe complet.
 */
beforeEach(function () {
    Storage::fake('local');
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    $this->service = app(NoteDeFraisService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. portail.ndf.created — création d'un brouillon
// ─────────────────────────────────────────────────────────────────────────────
it('observabilité: saveDraft création émet portail.ndf.created avec ndf_id, tiers_id, libelle', function () {
    $spy = Log::spy();

    $this->service->saveDraft($this->tiers, [
        'date' => now()->format('Y-m-d'),
        'libelle' => 'Déplacement Marseille',
        'lignes' => [],
    ]);

    $spy->shouldHaveReceived('info')
        ->with('portail.ndf.created', Mockery::on(fn ($ctx) => array_key_exists('ndf_id', $ctx)
            && ((int) ($ctx['tiers_id'] ?? 0)) === (int) $this->tiers->id
            && ($ctx['libelle'] ?? null) === 'Déplacement Marseille'))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. portail.ndf.updated — mise à jour d'un brouillon existant
// ─────────────────────────────────────────────────────────────────────────────
it('observabilité: saveDraft update émet portail.ndf.updated avec ndf_id, tiers_id', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Libellé initial',
    ]);

    $spy = Log::spy();

    $this->service->saveDraft($this->tiers, [
        'id' => $ndf->id,
        'date' => now()->format('Y-m-d'),
        'libelle' => 'Libellé modifié',
        'lignes' => [],
    ]);

    $spy->shouldHaveReceived('info')
        ->with('portail.ndf.updated', Mockery::on(fn ($ctx) => ((int) ($ctx['ndf_id'] ?? 0)) === (int) $ndf->id
            && ((int) ($ctx['tiers_id'] ?? 0)) === (int) $this->tiers->id))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. portail.ndf.submitted — soumission réussie
// ─────────────────────────────────────────────────────────────────────────────
it('observabilité: submit émet portail.ndf.submitted avec ndf_id, tiers_id, montant_total', function () {
    $sousCategorie = SousCategorie::factory()->create();

    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais soumis',
        'date' => now()->subDay()->format('Y-m-d'),
    ]);

    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'montant' => 42.50,
        'sous_categorie_id' => $sousCategorie->id,
        'piece_jointe_path' => 'associations/1/notes-de-frais/1/ligne-1.pdf',
    ]);

    $spy = Log::spy();

    $this->service->submit($ndf);

    $spy->shouldHaveReceived('info')
        ->with('portail.ndf.submitted', Mockery::on(fn ($ctx) => ((int) ($ctx['ndf_id'] ?? 0)) === (int) $ndf->id
            && ((int) ($ctx['tiers_id'] ?? 0)) === (int) $this->tiers->id
            && array_key_exists('montant_total', $ctx)))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. portail.ndf.deleted — suppression d'un brouillon
// ─────────────────────────────────────────────────────────────────────────────
it('observabilité: delete émet portail.ndf.deleted avec ndf_id, tiers_id', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $spy = Log::spy();

    $this->service->delete($ndf);

    $spy->shouldHaveReceived('info')
        ->with('portail.ndf.deleted', Mockery::on(fn ($ctx) => ((int) ($ctx['ndf_id'] ?? 0)) === (int) $ndf->id
            && ((int) ($ctx['tiers_id'] ?? 0)) === (int) $this->tiers->id))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Sécurité : aucun log ne contient le chemin complet d'une PJ
// ─────────────────────────────────────────────────────────────────────────────
it('observabilité: aucun log ne contient le chemin complet d\'une pièce jointe', function () {
    $sousCategorie = SousCategorie::factory()->create();

    /** @var array<int, array{message: string, context: array<string, mixed>}> $loggedEntries */
    $loggedEntries = [];

    $spy = Log::spy();
    $spy->shouldReceive('info')
        ->andReturnUsing(function (string $message, array $context = []) use (&$loggedEntries): void {
            $loggedEntries[] = ['message' => $message, 'context' => $context];
        });

    // Création avec une NDF contenant une fausse PJ
    $pjPath = 'associations/1/notes-de-frais/99/ligne-1.pdf';

    $ndf = $this->service->saveDraft($this->tiers, [
        'date' => now()->subDay()->format('Y-m-d'),
        'libelle' => 'Test sécurité PJ',
        'lignes' => [
            [
                'libelle' => 'Ligne test',
                'montant' => 10.00,
                'sous_categorie_id' => $sousCategorie->id,
                'piece_jointe_path' => $pjPath,
            ],
        ],
    ]);

    // Vérifier que la PJ n'apparaît pas dans les logs
    foreach ($loggedEntries as $entry) {
        $serialized = json_encode($entry, JSON_THROW_ON_ERROR);

        expect($serialized)->not->toContain($pjPath);
    }
});
