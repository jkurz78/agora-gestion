<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * Tests de la commande `audit:compta-v5-preparation` (Step 2 du plan slice 1).
 *
 * Stratégie de seed :
 *   - Le bootstrap global `tests/Pest.php` crée une Association et boote TenantContext.
 *   - Chaque test crée son propre jeu de données dans cette association ; pour le test
 *     d'isolation tenant on crée explicitement une seconde association.
 *
 * Stratégie d'audit :
 *   - On capture le chemin du JSON produit par la commande pour en vérifier le contenu
 *     plutôt que de parser uniquement la sortie stdout.
 *   - Le dossier `storage/audits/` est nettoyé en `beforeEach` pour repartir propre.
 */

beforeEach(function (): void {
    $this->auditDir = storage_path('audits');
    File::deleteDirectory($this->auditDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->auditDir);
});

function latestAuditFile(string $dir): ?string
{
    if (! is_dir($dir)) {
        return null;
    }
    $files = glob($dir.'/compta-v5-*.json') ?: [];
    if ($files === []) {
        return null;
    }
    sort($files);

    return end($files);
}

/**
 * @return array<string, mixed>
 */
function loadAuditJson(string $dir): array
{
    $file = latestAuditFile($dir);
    expect($file)->not->toBeNull('Fichier JSON d\'audit absent dans '.$dir);
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('passes when all data is clean', function (): void {
    // Tout est en règle : sous-catégorie avec code_cerfa, aucune transaction
    $cat = Categorie::factory()->create();
    SousCategorie::factory()->create([
        'categorie_id' => $cat->id,
        'code_cerfa' => '706',
    ]);

    $exitCode = Artisan::call('audit:compta-v5-preparation');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('0 issue');

    $json = loadAuditJson($this->auditDir);
    expect($json['sections']['sous_categories_sans_code_cerfa']['count'])->toBe(0);
    expect($json['sections']['modes_paiement_inconnus']['count'])->toBe(0);
});

it('flags sous_categories without code_cerfa', function (): void {
    $cat = Categorie::factory()->create();
    SousCategorie::factory()->create([
        'categorie_id' => $cat->id,
        'code_cerfa' => null,
        'nom' => 'Sous-cat orpheline',
    ]);
    SousCategorie::factory()->create([
        'categorie_id' => $cat->id,
        'code_cerfa' => '706',
        'nom' => 'Cotisations',
    ]);

    $exitCode = Artisan::call('audit:compta-v5-preparation');

    // Section 1 bloquante => exit code 1
    expect($exitCode)->toBe(1);

    $json = loadAuditJson($this->auditDir);
    expect($json['sections']['sous_categories_sans_code_cerfa']['count'])->toBe(1);
    expect($json['sections']['sous_categories_sans_code_cerfa']['items'][0]['nom'])
        ->toBe('Sous-cat orpheline');
});

it('flags unknown payment modes', function (): void {
    $cat = Categorie::factory()->create();
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $cat->id,
        'code_cerfa' => '706',
    ]);
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();
    $tiers = Tiers::factory()->create();

    // Insertion brute pour bypasser le cast d'enum sur mode_paiement
    DB::table('transactions')->insert([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette->value,
        'date' => '2026-01-15',
        'libelle' => 'TX mode inconnu',
        'montant_total' => 42.00,
        'mode_paiement' => 'bon_cadeau',
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $exitCode = Artisan::call('audit:compta-v5-preparation');
    expect($exitCode)->toBe(0); // pas bloquant

    $json = loadAuditJson($this->auditDir);
    expect($json['sections']['modes_paiement_inconnus']['count'])->toBeGreaterThan(0);
    $modes = collect($json['sections']['modes_paiement_inconnus']['items'])
        ->pluck('mode_paiement')
        ->all();
    expect($modes)->toContain('bon_cadeau');
});

it('reports transactions without tiers', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    // 2 transactions sans tiers — insertion brute pour éviter la cascade
    // factory qui crée des sous-catégories sans code_cerfa (orphelines)
    // qui ferait basculer la section bloquante.
    for ($i = 0; $i < 2; $i++) {
        DB::table('transactions')->insert([
            'association_id' => TenantContext::currentId(),
            'type' => TypeTransaction::Recette->value,
            'date' => '2026-02-0'.($i + 1),
            'libelle' => "TX sans tiers #$i",
            'montant_total' => 100.00 + $i,
            'mode_paiement' => ModePaiement::Cheque->value,
            'tiers_id' => null,
            'compte_id' => $compte->id,
            'saisi_par' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $exitCode = Artisan::call('audit:compta-v5-preparation');
    expect($exitCode)->toBe(0); // informational uniquement

    $json = loadAuditJson($this->auditDir);
    expect($json['sections']['transactions_sans_tiers']['count'])->toBe(2);
    expect($json['sections']['transactions_sans_tiers']['examples'])->toHaveCount(2);
});

it('produces a json audit file in storage/audits', function (): void {
    Artisan::call('audit:compta-v5-preparation');

    $file = latestAuditFile($this->auditDir);
    expect($file)->not->toBeNull();
    expect(basename($file))->toMatch('/^compta-v5-\d{4}-\d{2}-\d{2}-\d{6}\.json$/');

    $json = loadAuditJson($this->auditDir);
    expect($json)->toHaveKey('generated_at');
    expect($json)->toHaveKey('sections');
    expect($json['sections'])->toHaveKeys([
        'sous_categories_sans_code_cerfa',
        'modes_paiement_inconnus',
        'transactions_sans_tiers',
        'extournes_incoherentes',
        'helloasso_inhabituels',
    ]);
});

it('respects tenant scope', function (): void {
    // Tenant A (celui booté par défaut)
    $assoA = TenantContext::current();
    $catA = Categorie::factory()->create();
    SousCategorie::factory()->create([
        'categorie_id' => $catA->id,
        'code_cerfa' => '706',
    ]);

    // Tenant B avec problème (sous-cat sans code_cerfa)
    $assoB = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($assoB);
    $catB = Categorie::factory()->create();
    SousCategorie::factory()->create([
        'categorie_id' => $catB->id,
        'code_cerfa' => null,
        'nom' => 'Orpheline B',
    ]);

    // Re-boot tenant A pour cibler explicitement A
    TenantContext::clear();
    TenantContext::boot($assoA);

    $exitCode = Artisan::call('audit:compta-v5-preparation', [
        '--asso' => $assoA->id,
    ]);

    expect($exitCode)->toBe(0);

    $json = loadAuditJson($this->auditDir);
    expect($json['sections']['sous_categories_sans_code_cerfa']['count'])->toBe(0);

    // Vérification croisée : audit explicite sur B remonte bien le problème
    $exitCode = Artisan::call('audit:compta-v5-preparation', [
        '--asso' => $assoB->id,
    ]);
    expect($exitCode)->toBe(1);

    $jsonB = loadAuditJson($this->auditDir);
    expect($jsonB['sections']['sous_categories_sans_code_cerfa']['count'])->toBe(1);
});
