<?php

declare(strict_types=1);

use App\Enums\StatutFactureDeposee;
use App\Events\Portail\FactureDeposeeRejetee;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Services\Portail\FacturePartenaireService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

// ---------------------------------------------------------------------------
// Helper : dépôt Soumise avec un PDF sur le disk
// ---------------------------------------------------------------------------
function makeDepotSoumiseAvecFichier(int $associationId, int $tiersId): FacturePartenaireDeposee
{
    $pdfPath = "associations/{$associationId}/factures-deposees/2026/04/2026-04-24-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake-content');

    return FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $associationId,
        'tiers_id' => $tiersId,
        'pdf_path' => $pdfPath,
    ]);
}

// ---------------------------------------------------------------------------
// 1. Rejet valide — statut Rejetee
// ---------------------------------------------------------------------------
it('passe le statut du dépôt à Rejetee après rejet', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotSoumiseAvecFichier((int) $tiers->association_id, (int) $tiers->id);

    (new FacturePartenaireService)->rejeter($depot, 'Facture illisible');

    $depot->refresh();
    expect($depot->statut)->toBe(StatutFactureDeposee::Rejetee);
});

// ---------------------------------------------------------------------------
// 2. motif_rejet renseigné
// ---------------------------------------------------------------------------
it('renseigne motif_rejet sur le dépôt après rejet', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotSoumiseAvecFichier((int) $tiers->association_id, (int) $tiers->id);

    (new FacturePartenaireService)->rejeter($depot, 'Document manquant');

    $depot->refresh();
    expect($depot->motif_rejet)->toBe('Document manquant');
});

// ---------------------------------------------------------------------------
// 3. Event FactureDeposeeRejetee émis
// ---------------------------------------------------------------------------
it('dispatche l\'event FactureDeposeeRejetee', function () {
    Event::fake([FactureDeposeeRejetee::class]);

    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotSoumiseAvecFichier((int) $tiers->association_id, (int) $tiers->id);

    (new FacturePartenaireService)->rejeter($depot, 'Facture dupliquée');

    Event::assertDispatched(FactureDeposeeRejetee::class, function ($event) use ($depot) {
        return (int) $event->depot->id === (int) $depot->id;
    });
});

// ---------------------------------------------------------------------------
// 4. PDF conservé sur le disk (pas de suppression)
// ---------------------------------------------------------------------------
it('conserve le fichier PDF sur le disk après rejet', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotSoumiseAvecFichier((int) $tiers->association_id, (int) $tiers->id);
    $pdfPath = $depot->pdf_path;

    (new FacturePartenaireService)->rejeter($depot, 'Montant erroné');

    Storage::disk('local')->assertExists($pdfPath);
});

// ---------------------------------------------------------------------------
// 5. Log émis avec la bonne clé et le contexte attendu
// ---------------------------------------------------------------------------
it('émet le log portail.facture-partenaire.rejetee avec depot_id, tiers_id et motif', function () {
    Log::spy();

    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotSoumiseAvecFichier((int) $tiers->association_id, (int) $tiers->id);
    $depotId = $depot->id;
    $tiersId = $tiers->id;

    (new FacturePartenaireService)->rejeter($depot, 'Pièce jointe incorrecte');

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $key, array $context) use ($depotId, $tiersId): bool {
            return $key === 'portail.facture-partenaire.rejetee'
                && (int) $context['depot_id'] === (int) $depotId
                && (int) $context['tiers_id'] === (int) $tiersId
                && isset($context['motif']);
        });
});

// ---------------------------------------------------------------------------
// 6. Guard : statut Traitee → DomainException, aucun changement
// ---------------------------------------------------------------------------
it('lève DomainException si le dépôt est au statut Traitee', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = "associations/{$tiers->association_id}/factures-deposees/2026/04/2026-04-24-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, 'fake-pdf');

    $depot = FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'pdf_path' => $pdfPath,
    ]);

    expect(fn () => (new FacturePartenaireService)->rejeter($depot, 'Motif quelconque'))
        ->toThrow(DomainException::class);
});

it('ne modifie pas le statut si le dépôt est Traitee', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = "associations/{$tiers->association_id}/factures-deposees/2026/04/2026-04-24-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, 'fake-pdf');

    $depot = FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'pdf_path' => $pdfPath,
    ]);

    try {
        (new FacturePartenaireService)->rejeter($depot, 'Motif quelconque');
    } catch (DomainException) {
    }

    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($depot->id);
    expect($fresh->statut)->toBe(StatutFactureDeposee::Traitee);
});

// ---------------------------------------------------------------------------
// 7. Guard : statut déjà Rejetee → DomainException, aucun changement
// ---------------------------------------------------------------------------
it('lève DomainException si le dépôt est déjà au statut Rejetee', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = "associations/{$tiers->association_id}/factures-deposees/2026/04/2026-04-24-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, 'fake-pdf');

    $depot = FacturePartenaireDeposee::factory()->rejetee('Déjà rejeté')->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'pdf_path' => $pdfPath,
    ]);

    expect(fn () => (new FacturePartenaireService)->rejeter($depot, 'Nouveau motif'))
        ->toThrow(DomainException::class);
});

it('ne modifie pas le motif_rejet si le dépôt est déjà Rejetee', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = "associations/{$tiers->association_id}/factures-deposees/2026/04/2026-04-24-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, 'fake-pdf');

    $depot = FacturePartenaireDeposee::factory()->rejetee('Motif original')->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'pdf_path' => $pdfPath,
    ]);

    try {
        (new FacturePartenaireService)->rejeter($depot, 'Nouveau motif');
    } catch (DomainException) {
    }

    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($depot->id);
    expect($fresh->motif_rejet)->toBe('Motif original');
});

// ---------------------------------------------------------------------------
// 8. Guard : motif vide → DomainException (trim avant vérification)
// ---------------------------------------------------------------------------
it('lève DomainException si le motif est une chaîne vide', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotSoumiseAvecFichier((int) $tiers->association_id, (int) $tiers->id);

    expect(fn () => (new FacturePartenaireService)->rejeter($depot, ''))
        ->toThrow(DomainException::class);
});

it('lève DomainException si le motif ne contient que des espaces', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotSoumiseAvecFichier((int) $tiers->association_id, (int) $tiers->id);

    expect(fn () => (new FacturePartenaireService)->rejeter($depot, '   '))
        ->toThrow(DomainException::class);
});

it('ne modifie pas le statut si le motif est vide', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotSoumiseAvecFichier((int) $tiers->association_id, (int) $tiers->id);

    try {
        (new FacturePartenaireService)->rejeter($depot, '');
    } catch (DomainException) {
    }

    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($depot->id);
    expect($fresh->statut)->toBe(StatutFactureDeposee::Soumise);
});
