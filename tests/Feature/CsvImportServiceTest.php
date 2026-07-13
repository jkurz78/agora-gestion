<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\CsvImportService;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;

// Helper : créer un UploadedFile depuis une string CSV
function makeCsvFile(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv_test_');
    file_put_contents($path, $content);

    return new UploadedFile($path, 'test.csv', 'text/csv', null, true);
}

beforeEach(function () {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($user);

    $this->compte = CompteBancaire::factory()->create([
        'nom' => 'Compte principal',
        'actif_recettes_depenses' => true,
    ]);

    $this->compteVentilation = Compte::factory()->depense()->create(['intitule' => 'Fournitures']);
});

afterEach(function () {
    TenantContext::clear();
});

it('importe un CSV valide avec des colonnes compte et compte_bancaire non ambiguës', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Achat test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue()
        ->and($result->transactionsCreated)->toBe(1)
        ->and($result->lignesCreated)->toBe(1)
        ->and($result->errors)->toBeEmpty();

    $ligne = TransactionLigne::first();
    expect((int) $ligne->compte_id)->toBe($this->compteVentilation->id)
        ->and((float) $ligne->debit)->toBe(100.0)
        ->and((float) $ligne->credit)->toBe(0.0);
});

it('refuse explicitement les anciens en-têtes comptables', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
        ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Achat test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('compte');
});

it('refuse un en-tête dont les colonnes sont réordonnées', function () {
    $csv = "reference;date;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
        ."FAC-001;2024-09-15;Fournitures;100.00;virement;Compte principal;Achat test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['line'])->toBe(1)
        ->and($result->errors[0]['message'])->toContain('ordre exact');
});

it('refuse un en-tête contenant une colonne supplémentaire', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes;extra\n"
        ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Achat test;;;;;valeur\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['line'])->toBe(1)
        ->and($result->errors[0]['message'])->toContain('exactement');
});

it('regroupe plusieurs lignes CSV en une seule transaction', function () {
    Compte::factory()->depense()->create(['intitule' => 'Communication']);

    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;;;\n"
         ."2024-09-15;FAC-001;Communication;50.00;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue()
        ->and($result->transactionsCreated)->toBe(1)
        ->and($result->lignesCreated)->toBe(2);
});

it('regroupe des lignes non-contigues de meme date+reference en une seule transaction', function () {
    Compte::factory()->depense()->create(['intitule' => 'Communication']);

    // FAC-001 apparaît en lignes 2 et 4 (non-contigus), FAC-002 en ligne 3
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test1;;;;\n"
         ."2024-09-16;FAC-002;Fournitures;50.00;cheque;Compte principal;Test2;;;;\n"
         ."2024-09-15;FAC-001;Communication;30.00;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue()
        ->and($result->transactionsCreated)->toBe(2)
        ->and($result->lignesCreated)->toBe(3);
});

it('rejette un fichier avec un encodage non-UTF8', function () {
    $content = iconv('UTF-8', 'ISO-8859-1',
        "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
        ."2024-09-15;FAC-001;Catégorie;100.00;virement;Compte;Libellé;;;;\n"
    );

    $result = app(CsvImportService::class)->import(makeCsvFile($content), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('UTF-8');
});

it('ignore le BOM UTF-8 en debut de fichier', function () {
    $bom = "\xEF\xBB\xBF";
    $csv = $bom."date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue();
});

it('rejette un CSV avec un en-tete manquant', function () {
    $result = app(CsvImportService::class)->import(
        makeCsvFile("date;reference;compte;montant_ligne\n2024-09-15;FAC-001;Fournitures;100.00\n"),
        'depense'
    );

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('En-tête');
});

it('rejette une date invalide avec le bon numero de ligne', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."32/13/2024;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['line'])->toBe(2)
        ->and($result->errors[0]['message'])->toContain('date');
});

it('rejette un mode_paiement invalide', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;carte;Compte principal;Test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['line'])->toBe(2)
        ->and($result->errors[0]['message'])->toContain('mode_paiement');
});

it('rejette un mode_paiement vide sur la premiere ligne dun groupe', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;;Compte principal;Test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('mode_paiement');
});

it('rejette un compte vide sur la premiere ligne dun groupe', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;;Test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('compte');
});

it('rejette un compte de ventilation inconnu', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Toto;100.00;virement;Compte principal;Test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('Toto');
});

it('rejette un compte bancaire inconnu', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte inexistant;Test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('Compte inexistant');
});

it('rejette un tiers homonyme', function () {
    Tiers::factory()->create(['type' => 'particulier', 'nom' => 'DUPONT', 'prenom' => 'Jean', 'pour_depenses' => true]);
    Tiers::factory()->create(['type' => 'particulier', 'nom' => 'DUPONT', 'prenom' => 'Jean', 'pour_depenses' => true]);

    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;Jean DUPONT;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('homonyme');
});

it('rejette un tiers sans le flag pour_depenses', function () {
    Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'MARTIN',
        'prenom' => 'Paul',
        'pour_depenses' => false,
        'pour_recettes' => true,
    ]);

    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;Paul MARTIN;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('Paul MARTIN')
        ->and($result->errors[0]['message'])->toContain('dépenses');
});

it('rejette un doublon en base de donnees', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;;;\n";

    $result1 = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');
    expect($result1->success)->toBeTrue();

    $result2 = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');
    expect($result2->success)->toBeFalse()
        ->and($result2->errors[0]['message'])->toContain('FAC-001');
});

it('collecte toutes les erreurs avant de rejeter', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."invalide;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;;;\n"
         ."2024-09-16;FAC-002;SousCatInconnue;100.00;virement;Compte principal;Test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and(count($result->errors))->toBeGreaterThanOrEqual(2);
});

it('ignore les lignes vides', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."\n"
         ."2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;;;\n"
         ."\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue()
        ->and($result->transactionsCreated)->toBe(1);
});

it('est insensible a la casse pour compte et compte_bancaire', function () {
    $csv = "date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes\n"
         ."2024-09-15;FAC-001;FOURNITURES;100.00;virement;COMPTE PRINCIPAL;Test;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue();
});
