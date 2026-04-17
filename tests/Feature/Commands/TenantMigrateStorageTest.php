<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Les tests de cette commande manipulent le vrai filesystem local sous storage_path().
 * On crée les fichiers manuellement à l'ancien emplacement (storage/app/public/...)
 * et on vérifie les déplacements vers (storage/app/private/associations/{id}/...).
 *
 * Isolation : beforeEach/afterEach nettoient systématiquement les répertoires impliqués.
 * RefreshDatabase réinitialise les IDs depuis 1 entre chaque test, donc on doit aussi
 * nettoyer les fichiers physiques correspondants avant chaque test pour éviter les
 * résidus des tests précédents.
 *
 * Note : la commande ne nécessite pas TenantContext::boot() pour calculer les chemins
 * (elle lit la DB directement). Le context n'est booté qu'en interne par la commande
 * lors de l'itération par association.
 */

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->aid = $this->association->id;

    // Nettoyer les résidus de tests précédents (les IDs DB recommencent depuis 1 après
    // RefreshDatabase, donc des fichiers physiques d'un test précédent pourraient rester).
    File::deleteDirectory(storage_path('app/private/associations/'.$this->aid));
    File::deleteDirectory(storage_path('app/public/association'));
    File::deleteDirectory(storage_path('app/public/type-operations'));
});

afterEach(function () {
    TenantContext::clear();

    // Nettoyage systématique des répertoires créés par le test
    File::deleteDirectory(storage_path('app/private/associations/'.$this->aid));
    File::deleteDirectory(storage_path('app/public/association'));
    File::deleteDirectory(storage_path('app/public/type-operations'));
});

/** Crée un fichier physique à un chemin relatif depuis storage_path('app/'). */
function createLegacyFile(string $relativePath, string $content = 'dummy-content'): string
{
    $full = storage_path('app/'.$relativePath);
    File::ensureDirectoryExists(dirname($full));
    file_put_contents($full, $content);

    return $full;
}

/** Chemin absolu vers le nouveau layout d'une association. */
function newPath(int $aid, string $suffix): string
{
    return storage_path('app/private/associations/'.$aid.'/'.$suffix);
}

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 1 — Dry-run
// ─────────────────────────────────────────────────────────────────────────────
it('dry-run liste les opérations sans modifier les fichiers ni la DB', function () {
    $aid = $this->aid;

    // Setup : logo legacy
    $legacySrc = createLegacyFile('public/association/logo.png');

    $this->association->update(['logo_path' => 'logo.png']);

    // Dry-run (sans --force)
    $exitCode = Artisan::call('tenant:migrate-storage', [
        '--association' => $aid,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    // Le fichier source est toujours là
    expect(is_file($legacySrc))->toBeTrue();
    // Le fichier destination n'a PAS été créé
    expect(is_file(newPath($aid, 'branding/logo.png')))->toBeFalse();
    // La sortie mentionne DRY-RUN
    expect($output)->toContain('DRY-RUN');
    // La DB n'a pas changé
    expect($this->association->fresh()->logo_path)->toBe('logo.png');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 2 — Force : déplace le fichier et vérifie l'intégrité
// ─────────────────────────────────────────────────────────────────────────────
it('--force déplace le logo vers associations/{id}/branding/', function () {
    $aid = $this->aid;

    $legacySrc = createLegacyFile('public/association/logo.png', 'logo-content');

    $this->association->update(['logo_path' => 'logo.png']);

    $exitCode = Artisan::call('tenant:migrate-storage', [
        '--association' => $aid,
        '--force' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    // La source est supprimée
    expect(is_file($legacySrc))->toBeFalse();
    // La destination existe et a le bon contenu
    $dest = newPath($aid, 'branding/logo.png');
    expect(is_file($dest))->toBeTrue();
    expect(file_get_contents($dest))->toBe('logo-content');
    // La sortie indique MOVED
    expect($output)->toContain('MOVED');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 3 — Reverse : restaure le fichier depuis le nouveau vers l'ancien emplacement
// ─────────────────────────────────────────────────────────────────────────────
it('--force --reverse restaure le fichier à son ancien emplacement', function () {
    $aid = $this->aid;

    // Setup : fichier déjà migré (nouveau emplacement)
    $newDest = newPath($aid, 'branding/logo.png');
    File::ensureDirectoryExists(dirname($newDest));
    file_put_contents($newDest, 'logo-migrated');

    $this->association->update(['logo_path' => 'logo.png']);

    $exitCode = Artisan::call('tenant:migrate-storage', [
        '--association' => $aid,
        '--force' => true,
        '--reverse' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    // Le fichier est restauré à l'ancien emplacement
    $oldPath = storage_path('app/public/association/logo.png');
    expect(is_file($oldPath))->toBeTrue();
    expect(file_get_contents($oldPath))->toBe('logo-migrated');
    // La destination (nouveau) est supprimée
    expect(is_file($newDest))->toBeFalse();
    expect($output)->toContain('MOVED');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 4 — Collision : skip si la destination existe déjà
// ─────────────────────────────────────────────────────────────────────────────
it('--force skip (collision) si le fichier destination existe déjà', function () {
    $aid = $this->aid;

    // Fichier aux DEUX emplacements
    $legacySrc = createLegacyFile('public/association/logo.png', 'old-content');
    $dest = newPath($aid, 'branding/logo.png');
    File::ensureDirectoryExists(dirname($dest));
    file_put_contents($dest, 'new-content');

    $this->association->update(['logo_path' => 'logo.png']);

    $exitCode = Artisan::call('tenant:migrate-storage', [
        '--association' => $aid,
        '--force' => true,
    ]);

    $output = Artisan::output();

    // Code retour 0 (succès partiel)
    expect($exitCode)->toBe(0);
    // Les deux fichiers sont intacts
    expect(is_file($legacySrc))->toBeTrue();
    expect(file_get_contents($legacySrc))->toBe('old-content');
    expect(file_get_contents($dest))->toBe('new-content');
    // La sortie mentionne collision ou exists
    expect(strtolower($output))->toMatch('/(collision|exists)/');
    // Les stats indiquent au moins 1 collision
    expect($output)->toContain('collisions=1');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 5 — Filtre --association : seuls les fichiers du tenant cible sont déplacés
// ─────────────────────────────────────────────────────────────────────────────
it('--association filtre les opérations au tenant spécifié', function () {
    $aid1 = $this->aid;
    $asso2 = Association::factory()->create();
    $aid2 = $asso2->id;

    // Cleanup préventif pour asso2 aussi
    File::deleteDirectory(storage_path('app/private/associations/'.$aid2));

    // Association 1 : logo legacy
    $src1 = createLegacyFile('public/association/logo.png', 'asso1-logo');
    $this->association->update(['logo_path' => 'logo.png']);

    // Association 2 : cachet legacy (nom de fichier distinct)
    $src2 = createLegacyFile('public/association/cachet.png', 'asso2-cachet');
    $asso2->update(['cachet_signature_path' => 'cachet.png']);

    // Migrer uniquement l'association 1
    $exitCode = Artisan::call('tenant:migrate-storage', [
        '--association' => $aid1,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0);

    // Asso 1 : logo déplacé
    $dest1 = newPath($aid1, 'branding/logo.png');
    expect(is_file($dest1))->toBeTrue();
    expect(is_file($src1))->toBeFalse();

    // Asso 2 : cachet INTACT (pas touché car --association=aid1 seulement)
    expect(is_file($src2))->toBeTrue();
    $dest2 = newPath($aid2, 'branding/cachet.png');
    expect(is_file($dest2))->toBeFalse();

    // Cleanup asso2
    File::deleteDirectory(storage_path('app/private/associations/'.$aid2));
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 6 — Idempotence : second run → source manquante → skip
// ─────────────────────────────────────────────────────────────────────────────
it('second --force après migration complète est idempotent', function () {
    $aid = $this->aid;

    $legacySrc = createLegacyFile('public/association/logo.png', 'logo-content');

    $this->association->update(['logo_path' => 'logo.png']);

    // Premier run
    Artisan::call('tenant:migrate-storage', ['--association' => $aid, '--force' => true]);
    expect(is_file($legacySrc))->toBeFalse();

    // Second run
    $exitCode = Artisan::call('tenant:migrate-storage', [
        '--association' => $aid,
        '--force' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    // Aucune opération supplémentaire : source manquante → skip
    expect($output)->toContain('missing=1');
    // moved=0 sur le second run
    expect($output)->toContain('moved=0');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 7 — TypeOperation logo migré
// ─────────────────────────────────────────────────────────────────────────────
it('--force déplace le logo d\'un TypeOperation vers associations/{id}/type-operations/{tid}/', function () {
    $aid = $this->aid;
    TenantContext::boot($this->association);

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $aid,
        'logo_path' => 'logo-type.png',
    ]);
    $tid = $typeOp->id;

    $legacySrc = createLegacyFile("public/type-operations/{$tid}/logo-type.png", 'type-logo');

    TenantContext::clear();

    $exitCode = Artisan::call('tenant:migrate-storage', [
        '--association' => $aid,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(is_file($legacySrc))->toBeFalse();
    expect(is_file(newPath($aid, "type-operations/{$tid}/logo-type.png")))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 8 — Résumé global (moved/skipped/collisions/missing dans stdout)
// ─────────────────────────────────────────────────────────────────────────────
it('la sortie contient les statistiques moved/skipped/collisions/missing', function () {
    $aid = $this->aid;

    $exitCode = Artisan::call('tenant:migrate-storage', [
        '--association' => $aid,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('moved=');
    expect($output)->toContain('skipped=');
    expect($output)->toContain('collisions=');
    expect($output)->toContain('missing=');
});
