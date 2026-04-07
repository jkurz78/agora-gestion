<?php

declare(strict_types=1);

use App\Livewire\ImportCsvTiers;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ---------------------------------------------------------------------------
// Helper: create a fake CSV file from an array of rows (compatible with Livewire)
// ---------------------------------------------------------------------------
function makeCsvUploadForImport(array $headers, array $rows, string $filename = 'tiers.csv'): UploadedFile
{
    $content = implode(';', $headers)."\n";
    foreach ($rows as $row) {
        $content .= implode(';', $row)."\n";
    }

    return UploadedFile::fake()->createWithContent($filename, $content);
}

// ---------------------------------------------------------------------------
// 1. Upload valid CSV -> phase becomes preview, rows displayed with statuses
// ---------------------------------------------------------------------------
it('uploade un CSV valide et passe en phase preview avec les bons statuts', function () {
    Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => null,
        'telephone' => null,
    ]);

    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'entreprise', 'email'],
        [
            ['Dupont', 'Jean', '', 'jean@example.com'],
            ['Martin', 'Marie', '', 'marie@example.com'],
        ]
    );

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->assertSet('parseErrors', [])
        ->assertCount('rows', 2);
});

// ---------------------------------------------------------------------------
// 2. Upload invalid file (no header) -> errors displayed, phase stays upload
// ---------------------------------------------------------------------------
it('affiche des erreurs pour un fichier sans en-tete reconnu', function () {
    $file = makeCsvUploadForImport(
        ['colonne_inconnue', 'autre_colonne'],
        [['valeur1', 'valeur2']]
    );

    $component = Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'upload');

    expect($component->get('parseErrors'))->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 3. Upload file > 2Mo -> validation error
// ---------------------------------------------------------------------------
it('rejette un fichier depassant 2 Mo', function () {
    $file = UploadedFile::fake()->create('tiers.csv', 3000, 'text/csv');

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertHasErrors('importFile');
});

// ---------------------------------------------------------------------------
// 4. Upload .xls file -> rejection error via parser
// ---------------------------------------------------------------------------
it('rejette un fichier .xls avec erreur de validation', function () {
    $file = UploadedFile::fake()->createWithContent('tiers.xls', "nom;prenom\nDupont;Jean\n");

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertHasErrors('importFile');
});

// ---------------------------------------------------------------------------
// 5. Conflict row -> click resolve -> open-tiers-merge dispatched
// ---------------------------------------------------------------------------
it('dispatche open-tiers-merge pour un conflit avec un seul candidat', function () {
    $existing = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@old.com',
        'telephone' => '0600000000',
    ]);

    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email', 'telephone'],
        [['Dupont', 'Jean', 'jean@new.com', '0699999999']]
    );

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('resolveConflict', 0)
        ->assertDispatched('open-tiers-merge', function (string $event, array $params) use ($existing) {
            return $params['tiersId'] === $existing->id
                && $params['context'] === 'csv_import'
                && $params['contextData']['index'] === 0;
        });
});

// ---------------------------------------------------------------------------
// 6. tiers-merge-confirmed updates row to resolved
// ---------------------------------------------------------------------------
it('met a jour la ligne en conflict_resolved_merge apres tiers-merge-confirmed', function () {
    $existing = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@old.com',
    ]);

    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [['Dupont', 'Jean', 'jean@new.com']]
    );

    $component = Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview');

    $component->dispatch('tiers-merge-confirmed',
        tiersId: $existing->id,
        context: 'csv_import',
        contextData: [
            'index' => 0,
            'merge_data' => ['email' => 'jean@new.com'],
            'boolean_data' => ['pour_depenses' => true, 'pour_recettes' => false, 'est_helloasso' => false],
        ],
    );

    $rows = $component->get('rows');
    expect($rows[0]['status'])->toBe('conflict_resolved_merge');
    expect($rows[0]['merge_data'])->toBe(['email' => 'jean@new.com']);
});

// ---------------------------------------------------------------------------
// 7. tiers-merge-create-new updates row to resolved-new
// ---------------------------------------------------------------------------
it('met a jour la ligne en conflict_resolved_new apres tiers-merge-create-new', function () {
    $existing = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@old.com',
    ]);

    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [['Dupont', 'Jean', 'jean@new.com']]
    );

    $component = Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview');

    $component->dispatch('tiers-merge-create-new',
        sourceData: ['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean@new.com'],
        context: 'csv_import',
        contextData: ['index' => 0],
    );

    $rows = $component->get('rows');
    expect($rows[0]['status'])->toBe('conflict_resolved_new');
});

// ---------------------------------------------------------------------------
// 8. tiers-merge-cancelled keeps row as conflict
// ---------------------------------------------------------------------------
it('conserve le statut conflict apres tiers-merge-cancelled', function () {
    $existing = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@old.com',
    ]);

    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [['Dupont', 'Jean', 'jean@new.com']]
    );

    $component = Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview');

    $component->dispatch('tiers-merge-cancelled');

    $rows = $component->get('rows');
    expect($rows[0]['status'])->toBe('conflict');
});

// ---------------------------------------------------------------------------
// 9. confirmImport disabled when conflicts unresolved
// ---------------------------------------------------------------------------
it('empeche confirmImport quand il y a des conflits non resolus', function () {
    Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@old.com',
    ]);

    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [['Dupont', 'Jean', 'jean@new.com']]
    );

    $component = Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview');

    expect($component->instance()->hasUnresolvedConflicts())->toBeTrue();

    $component->call('confirmImport')
        ->assertNotSet('phase', 'done');
});

// ---------------------------------------------------------------------------
// 10. confirmImport creates/updates tiers atomically, phase becomes done
// ---------------------------------------------------------------------------
it('confirme l\'import et cree les tiers atomiquement', function () {
    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [
            ['Nouveau', 'Tiers', 'nouveau@example.com'],
            ['Autre', 'Personne', 'autre@example.com'],
        ]
    );

    $countBefore = Tiers::count();

    $component = Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('confirmImport')
        ->assertSet('phase', 'done')
        ->assertDispatched('tiers-saved');

    expect($component->get('reportData'))->not->toBeNull();
    expect(Tiers::count())->toBe($countBefore + 2);
    $this->assertDatabaseHas('tiers', ['nom' => 'Nouveau', 'prenom' => 'Tiers']);
    $this->assertDatabaseHas('tiers', ['nom' => 'Autre', 'prenom' => 'Personne']);
});

// ---------------------------------------------------------------------------
// 11. Report displayed after import with correct counters
// ---------------------------------------------------------------------------
it('affiche le rapport avec les bons compteurs apres import', function () {
    Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Martin',
        'prenom' => 'Marie',
        'email' => null,
        'telephone' => null,
    ]);

    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [
            ['Martin', 'Marie', 'marie@example.com'],
            ['Nouveau', 'Tiers', 'nouveau@example.com'],
        ]
    );

    $component = Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('confirmImport')
        ->assertSet('phase', 'done');

    $reportData = $component->get('reportData');
    expect($reportData['created'])->toBe(1);
    expect($reportData['enriched'])->toBe(1);
    expect($reportData['total'])->toBe(2);
});

// ---------------------------------------------------------------------------
// 12. Cancel resets everything
// ---------------------------------------------------------------------------
it('remet tout a zero apres annulation', function () {
    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [['Dupont', 'Jean', 'jean@example.com']]
    );

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('cancel')
        ->assertSet('phase', 'upload')
        ->assertSet('rows', [])
        ->assertSet('parseErrors', [])
        ->assertSet('originalFilename', '')
        ->assertSet('reportData', null);
});

// ---------------------------------------------------------------------------
// 13. tiers-merge-confirmed from non-csv_import context is ignored
// ---------------------------------------------------------------------------
it('ignore tiers-merge-confirmed d\'un autre contexte', function () {
    $existing = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@old.com',
    ]);

    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [['Dupont', 'Jean', 'jean@new.com']]
    );

    $component = Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview');

    $component->dispatch('tiers-merge-confirmed',
        tiersId: $existing->id,
        context: 'helloasso_sync',
        contextData: ['index' => 0],
    );

    $rows = $component->get('rows');
    expect($rows[0]['status'])->toBe('conflict');
});

// ---------------------------------------------------------------------------
// 14. Homonyme: selectCandidate dispatches open-tiers-merge
// ---------------------------------------------------------------------------
it('dispatche open-tiers-merge apres selection d\'un candidat homonyme', function () {
    $tiers1 = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean1@example.com',
    ]);
    Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean2@example.com',
    ]);

    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [['Dupont', 'Jean', 'jean@new.com']]
    );

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('selectCandidate', 0, $tiers1->id)
        ->assertDispatched('open-tiers-merge', function (string $event, array $params) use ($tiers1) {
            return $params['tiersId'] === $tiers1->id
                && $params['context'] === 'csv_import';
        });
});

// ---------------------------------------------------------------------------
// 15. Toggle panel resets state
// ---------------------------------------------------------------------------
it('remet l\'etat a zero quand le panel est ferme', function () {
    $file = makeCsvUploadForImport(
        ['nom', 'prenom', 'email'],
        [['Dupont', 'Jean', 'jean@example.com']]
    );

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('togglePanel')
        ->assertSet('showPanel', false)
        ->assertSet('phase', 'upload')
        ->assertSet('rows', []);
});
