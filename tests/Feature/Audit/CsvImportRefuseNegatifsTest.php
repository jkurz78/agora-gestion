<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 8 : CsvImportService refuse les montants négatifs.
 *
 * Le service doit :
 *   - Rejeter toute ligne dont montant_ligne <= 0 avec le message standardisé.
 *   - Continuer à traiter les autres lignes (validation exhaustive).
 *   - Émettre un Log::warning portant le numéro de ligne CSV et la raison.
 *   - Dans un import multi-lignes (3 lignes : +50, -30, +20), seule la ligne -30
 *     est rejetée ; les deux autres transactions sont importées si le reste du
 *     fichier est valide.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4 CsvImportService (Step 8)
 */

use App\Enums\TypeCategorie;
use App\Livewire\Concerns\MontantValidation;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\CsvImportService;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

// Helper local : crée un UploadedFile depuis une string CSV.
// Différent du helper global makeCsvFile() défini dans CsvImportServiceTest.php
// pour éviter la collision entre fichiers de test distincts.
function makeAuditCsvFile(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'audit_csv_');
    file_put_contents($path, $content);

    return new UploadedFile($path, 'audit_test.csv', 'text/csv', null, true);
}

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($user);

    $this->compte = CompteBancaire::factory()->create([
        'nom' => 'Compte test négatif',
        'actif_recettes_depenses' => true,
    ]);

    $cat = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $this->sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Charges test']);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('csv_import_rejette_montant_negatif_avec_message_dans_rapport', function (): void {
    // 3 lignes : +50 (ligne 2 CSV), -30 (ligne 3 CSV), +20 (ligne 4 CSV)
    // Toutes ont des références différentes → 3 transactions distinctes si toutes valides.
    // La ligne -30 doit être rejetée ; les 2 autres doivent être importées.
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-POS50;Charges test;50.00;virement;Compte test négatif;Ligne positive 50;;;;\n"
         ."2024-09-16;FAC-NEG30;Charges test;-30.00;virement;Compte test négatif;Ligne négative 30;;;;\n"
         ."2024-09-17;FAC-POS20;Charges test;20.00;virement;Compte test négatif;Ligne positive 20;;;;\n";

    $result = app(CsvImportService::class)->import(makeAuditCsvFile($csv), 'depense');

    // L'import doit échouer globalement car une ligne est invalide
    expect($result->success)->toBeFalse();

    // Exactement une erreur : la ligne -30 (CSV ligne 3 = index+2=3)
    expect($result->errors)->toHaveCount(1);
    $error = $result->errors[0];

    // Numéro de ligne correct
    expect($error['line'])->toBe(3);

    // Message doit mentionner montant_ligne
    expect($error['message'])->toContain('montant_ligne');

    // Message doit exprimer clairement la contrainte positive
    expect($error['message'])->toContain('positif');
});

it('csv_import_rejette_montant_negatif_avec_message_standardise', function (): void {
    // Vérifier que le message contient le texte standardisé défini dans MontantValidation.
    // Le service CsvImportService utilise MontantValidation::MESSAGE dans l'erreur de rapport.
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation;seance;notes\n"
         ."2024-09-16;FAC-NEG;Charges test;-30.00;virement;Compte test négatif;Négatif;;;;\n";

    $result = app(CsvImportService::class)->import(makeAuditCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse();
    expect($result->errors[0]['message'])->toContain(MontantValidation::MESSAGE);
});

it('csv_import_emet_un_log_warning_avec_numero_de_ligne_et_raison', function (): void {
    // Le service doit émettre un Log::warning portant la ligne CSV et la raison.
    Log::shouldReceive('warning')
        ->once()
        ->with(
            Mockery::on(fn (string $msg): bool => str_contains($msg, 'montant') && str_contains($msg, 'négatif')),
            Mockery::on(fn (array $ctx): bool => isset($ctx['csv_line']) && $ctx['csv_line'] === 3),
        );

    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-POS;Charges test;50.00;virement;Compte test négatif;Positif;;;;\n"
         ."2024-09-16;FAC-NEG;Charges test;-30.00;virement;Compte test négatif;Négatif;;;;\n";

    app(CsvImportService::class)->import(makeAuditCsvFile($csv), 'depense');
});

it('csv_import_rejette_montant_zero', function (): void {
    // Montant = 0 est également invalide (doit être strictement positif).
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation;seance;notes\n"
         ."2024-09-16;FAC-ZERO;Charges test;0.00;virement;Compte test négatif;Zéro;;;;\n";

    $result = app(CsvImportService::class)->import(makeAuditCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse();
    expect($result->errors[0]['message'])->toContain('positif');
});
