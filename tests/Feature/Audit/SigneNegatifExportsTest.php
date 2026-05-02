<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 3 : tests de régression exports Excel et PDF.
 *
 * Vérifie que les exports des rapports (Excel + PDF) incluent correctement
 * les montants négatifs, sans filtrage injustifié ni abs() indu.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.2
 */

use App\Enums\StatutExercice;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\Rapports\FluxTresorerieBuilder;
use App\Services\RapportService;
use App\Tenant\TenantContext;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Concerns\MakesAuditTransactions;

uses(MakesAuditTransactions::class);

// ── Fixtures shared via beforeEach ────────────────────────────────────────────

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Catégorie / sous-catégorie de recette
    $this->categorie = Categorie::factory()->create(['association_id' => $this->association->id]);
    $this->sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'association_id' => $this->association->id,
    ]);

    // Compte bancaire réel
    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'solde_initial' => 0.0,
    ]);

    // Exercice 2025 ouvert, session active
    $this->exercice = Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
});

// ── Test 1 — Excel compte de résultat ────────────────────────────────────────

it('export_excel_compte_resultat_somme_negatifs', function () {
    // +100 € et -40 € dans même sous-cat → total net attendu = 60 €
    $this->makeAuditTransaction('recette', 100.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -40.0, $this->sc, $this->compte, 2025);

    // Générer le Spreadsheet directement via le builder (même logique que le controller)
    $rapportService = app(RapportService::class);
    $data = $rapportService->compteDeResultat(2025);

    // Trouver le total des produits (somme algébrique des montant_n)
    $totalProduitsN = collect($data['produits'])->sum('montant_n');

    // Vérification 1 : le builder retourne 60 (algébrique, pas abs)
    expect((float) $totalProduitsN)->toBe(60.0);

    // Vérification 2 : le total net de la sous-catégorie vaut 60
    $scMontants = collect($data['produits'])
        ->flatMap(fn ($cat) => collect($cat['sous_categories'])->pluck('montant_n'));
    expect($scMontants->sum())->toBe(60.0);

    // Vérification 3 : l'export XLSX HTTP retourne 200 avec le bon Content-Type
    $response = $this->get('/rapports/export/compte-resultat/xlsx?exercice=2025');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Vérification 4 : parser le contenu streamé et chercher la valeur 60 dans les cellules
    $content = '';
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();
    expect(strlen($content))->toBeGreaterThan(100, 'Le contenu XLSX streamé ne doit pas être vide');

    // Écrire dans un fichier tmp pour le parser via PhpSpreadsheet
    $tmpFile = tempnam(sys_get_temp_dir(), 'audit_export_').'.xlsx';
    file_put_contents($tmpFile, $content);

    try {
        $spreadsheet = IOFactory::load($tmpFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Chercher la valeur 60 dans les colonnes numériques (D et E = N-1 et N)
        $found60 = false;
        $highestRow = $sheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $valN = $sheet->getCell('E'.$row)->getValue();
            if ($valN !== null && abs((float) $valN - 60.0) < 0.001) {
                $found60 = true;
                break;
            }
        }
        expect($found60)->toBeTrue('Le total 60 € doit apparaître dans la colonne N de l\'Excel');

        // Vérifier qu'on ne trouve PAS 140 (somme des absolues)
        $found140 = false;
        for ($row = 2; $row <= $highestRow; $row++) {
            $valN = $sheet->getCell('E'.$row)->getValue();
            if ($valN !== null && abs((float) $valN - 140.0) < 0.001) {
                $found140 = true;
                break;
            }
        }
        expect($found140)->toBeFalse('La somme des absolues (140) ne doit PAS apparaître dans l\'Excel');
    } finally {
        @unlink($tmpFile);
    }
});

// ── Test 2 — PDF compte de résultat ──────────────────────────────────────────

it('pdf_compte_resultat_somme_negatifs', function () {
    // +100 € recette, -40 € recette → résultat net = 60 €
    $this->makeAuditTransaction('recette', 100.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -40.0, $this->sc, $this->compte, 2025);

    // Générer les données de vue exactement comme le controller
    $rapportService = app(RapportService::class);
    $data = $rapportService->compteDeResultat(2025);

    $totalProduitsN = collect($data['produits'])->sum('montant_n');
    $totalProduitsN1 = collect($data['produits'])->sum('montant_n1');
    $totalChargesN = collect($data['charges'])->sum('montant_n');
    $totalChargesN1 = collect($data['charges'])->sum('montant_n1');
    $resultatNet = (float) $totalProduitsN - (float) $totalChargesN;

    $viewData = array_merge($data, [
        'labelN' => '2025-2026',
        'labelN1' => '2024-2025',
        'totalChargesN' => $totalChargesN,
        'totalProduitsN' => $totalProduitsN,
        'totalChargesN1' => $totalChargesN1,
        'totalProduitsN1' => $totalProduitsN1,
        'provisions' => collect(),
        'provisionsN1' => collect(),
        'extournes' => collect(),
        'extournesN1' => collect(),
        'totalProvisions' => 0.0,
        'totalProvisionsN1' => 0.0,
        'totalExtournes' => 0.0,
        'totalExtournesN1' => 0.0,
        'resultatBrut' => $resultatNet,
        'resultatBrutN1' => 0.0,
        'resultatNet' => $resultatNet,
        'resultatNetN1' => 0.0,
        'title' => 'Compte de résultat',
        'subtitle' => 'Exercice 2025-2026',
        'association' => $this->association,
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
        'appLogoBase64' => null,
        'footerLogoBase64' => null,
        'footerLogoMime' => null,
    ]);

    // Rendre la vue Blade en HTML (approche pré-PDF) sans passer par dompdf
    $html = view('pdf.rapport-compte-resultat', $viewData)->render();

    // Les montants formatés en français (60,00 €) doivent apparaître
    // number_format() + ' €' produit le symbole € directement (pas &euro;)
    expect(str_contains($html, '60,00 €'))->toBeTrue(
        'La valeur 60,00 € (somme algébrique +100 - 40) doit apparaître dans le PDF HTML'
    );

    // La somme des absolues (140 €) ne doit pas apparaître comme total recettes
    expect(str_contains($html, '140,00 €'))->toBeFalse(
        'La valeur 140,00 € (somme des abs) ne doit PAS apparaître dans le PDF HTML'
    );
});

// ── Test 3 — PDF compte de résultat : sous-catégorie à montant strictement négatif ──

it('pdf_compte_resultat_sous_categorie_negative_visible', function () {
    // Crée une DEUXIÈME sous-catégorie avec seulement -40 €
    // (aucun positif dans cette sous-cat)
    $sc2 = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'association_id' => $this->association->id,
    ]);

    $this->makeAuditTransaction('recette', -40.0, $sc2, $this->compte, 2025);

    $rapportService = app(RapportService::class);
    $data = $rapportService->compteDeResultat(2025);

    $viewData = array_merge($data, [
        'labelN' => '2025-2026',
        'labelN1' => '2024-2025',
        'totalChargesN' => collect($data['charges'])->sum('montant_n'),
        'totalProduitsN' => collect($data['produits'])->sum('montant_n'),
        'totalChargesN1' => collect($data['charges'])->sum('montant_n1'),
        'totalProduitsN1' => collect($data['produits'])->sum('montant_n1'),
        'provisions' => collect(),
        'provisionsN1' => collect(),
        'extournes' => collect(),
        'extournesN1' => collect(),
        'totalProvisions' => 0.0,
        'totalProvisionsN1' => 0.0,
        'totalExtournes' => 0.0,
        'totalExtournesN1' => 0.0,
        'resultatBrut' => -40.0,
        'resultatBrutN1' => 0.0,
        'resultatNet' => -40.0,
        'resultatNetN1' => 0.0,
        'title' => 'Compte de résultat',
        'subtitle' => 'Exercice 2025-2026',
        'association' => $this->association,
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
        'appLogoBase64' => null,
        'footerLogoBase64' => null,
        'footerLogoMime' => null,
    ]);

    $html = view('pdf.rapport-compte-resultat', $viewData)->render();

    // Le montant -40 € doit apparaître dans le HTML.
    // Avant patch : le filtre `$sc['montant_n'] > 0` excluait silencieusement
    // les sous-catégories à montant négatif — elles étaient invisibles dans le PDF.
    // Après patch : `$sc['montant_n'] != 0` les inclut correctement.
    expect(str_contains($html, '-40,00 €'))->toBeTrue(
        'La sous-catégorie avec montant -40 € doit être visible dans le PDF (filtre != 0, pas > 0)'
    );
});

// ── Test 4 — PDF flux de trésorerie ──────────────────────────────────────────

it('pdf_flux_tresorerie_somme_negatifs', function () {
    // +80 € recette, -30 € recette → total_recettes = 50 (algébrique)
    $this->makeAuditTransaction('recette', 80.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -30.0, $this->sc, $this->compte, 2025);

    $rapportService = app(RapportService::class);
    $ftData = $rapportService->fluxTresorerie(2025);

    // Vérification au niveau des données : total_recettes doit être algébrique
    expect($ftData['synthese']['total_recettes'])->toBe(50.0);
    expect($ftData['synthese']['variation'])->toBe(50.0);

    // Rendre la vue Blade en HTML
    $viewData = array_merge($ftData, [
        'title' => 'Flux de trésorerie',
        'subtitle' => 'Exercice 2025-2026',
        'association' => $this->association,
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
        'appLogoBase64' => null,
        'footerLogoBase64' => null,
        'footerLogoMime' => null,
    ]);

    $html = view('pdf.rapport-flux-tresorerie', $viewData)->render();

    // Le total recettes (50 €) doit apparaître dans le HTML
    // number_format() + ' €' produit le symbole € directement (pas &euro;)
    expect(str_contains($html, '50,00 €'))->toBeTrue(
        'Le total recettes 50,00 € (algébrique) doit apparaître dans le PDF flux trésorerie'
    );

    // La somme des absolues (110 €) ne doit PAS apparaître comme total recettes
    expect(str_contains($html, '110,00 €'))->toBeFalse(
        'La valeur 110,00 € (abs naïve) ne doit PAS apparaître dans le PDF flux trésorerie'
    );
});

// ── Test 5 — Excel flux de trésorerie ────────────────────────────────────────

it('export_excel_flux_tresorerie_somme_negatifs', function () {
    // +60 € recette, -20 € recette → total_recettes = 40 (algébrique)
    $this->makeAuditTransaction('recette', 60.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -20.0, $this->sc, $this->compte, 2025);

    // Vérification builder avant export
    $builder = app(FluxTresorerieBuilder::class);
    $data = $builder->fluxTresorerie(2025);
    expect($data['synthese']['total_recettes'])->toBe(40.0);

    // Vérification HTTP export
    $response = $this->get('/rapports/export/flux-tresorerie/xlsx?exercice=2025');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Parser le fichier Excel pour vérifier la valeur 40
    $content = '';
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();
    expect(strlen($content))->toBeGreaterThan(100, 'Le contenu XLSX streamé ne doit pas être vide');

    $tmpFile = tempnam(sys_get_temp_dir(), 'audit_flux_').'.xlsx';
    file_put_contents($tmpFile, $content);

    try {
        $spreadsheet = IOFactory::load($tmpFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Chercher la valeur 40 dans les colonnes de synthèse (B = Recettes)
        $found40 = false;
        $highestRow = $sheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $valRecettes = $sheet->getCell('B'.$row)->getValue();
            if ($valRecettes !== null && abs((float) $valRecettes - 40.0) < 0.001) {
                $found40 = true;
                break;
            }
        }
        expect($found40)->toBeTrue('Le total recettes 40 € doit apparaître dans la colonne B de l\'Excel flux');

        // Vérifier qu'on ne trouve PAS 80 comme total recettes (qui serait abs(-20)+60)
        // Note : 80 peut être la valeur d'une cellule mensuelle, on cherche 80 en colonne B TOTAL
        $foundTotalRow = false;
        for ($row = 2; $row <= $highestRow; $row++) {
            $labelCell = $sheet->getCell('A'.$row)->getValue();
            if ($labelCell === 'TOTAL') {
                $totalRecettes = (float) $sheet->getCell('B'.$row)->getValue();
                expect($totalRecettes)->toBe(40.0, 'La ligne TOTAL doit avoir recettes=40 (algébrique)');
                $foundTotalRow = true;
                break;
            }
        }
        expect($foundTotalRow)->toBeTrue('La ligne TOTAL doit exister dans l\'Excel flux de trésorerie');
    } finally {
        @unlink($tmpFile);
    }
});

// ── Test 6 — Builder Excel compte de résultat : valeur cellule exacte ─────────

it('export_excel_compte_resultat_builder_valeurs_cellules', function () {
    // 3 transactions dans même sous-cat : +100, -40, +20 → net = 80
    $this->makeAuditTransaction('recette', 100.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -40.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', 20.0, $this->sc, $this->compte, 2025);

    $rapportService = app(RapportService::class);
    $data = $rapportService->compteDeResultat(2025);

    // Le builder doit retourner 80 (somme algébrique)
    $totalProduits = collect($data['produits'])->sum('montant_n');
    expect((float) $totalProduits)->toBe(80.0);

    // La sous-catégorie unique doit avoir montant_n = 80
    $scMontant = collect($data['produits'])
        ->flatMap(fn ($cat) => collect($cat['sous_categories']))
        ->first()['montant_n'];
    expect((float) $scMontant)->toBe(80.0);

    // Export HTTP et parse
    $response = $this->get('/rapports/export/compte-resultat/xlsx?exercice=2025');
    $response->assertOk();

    $content = '';
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();
    expect(strlen($content))->toBeGreaterThan(100, 'Le contenu XLSX streamé ne doit pas être vide');

    $tmpFile = tempnam(sys_get_temp_dir(), 'audit_cr_').'.xlsx';
    file_put_contents($tmpFile, $content);

    try {
        $spreadsheet = IOFactory::load($tmpFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Chercher 80 dans la colonne E (montant_n)
        $found80 = false;
        $highestRow = $sheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $valN = $sheet->getCell('E'.$row)->getValue();
            if ($valN !== null && abs((float) $valN - 80.0) < 0.001) {
                $found80 = true;
                break;
            }
        }
        expect($found80)->toBeTrue('La valeur 80 € (somme algébrique) doit être dans l\'Excel');

        // Vérifier que ni 160 (abs naïf) ni 60 (abs moins) n'apparaissent comme total
        $found160 = false;
        for ($row = 2; $row <= $highestRow; $row++) {
            $valN = $sheet->getCell('E'.$row)->getValue();
            if ($valN !== null && abs((float) $valN - 160.0) < 0.001) {
                $found160 = true;
                break;
            }
        }
        expect($found160)->toBeFalse('160 (somme abs naïve) ne doit pas apparaître');
    } finally {
        @unlink($tmpFile);
    }
});
