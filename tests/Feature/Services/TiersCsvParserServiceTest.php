<?php

declare(strict_types=1);

use App\Services\TiersCsvParserService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeCsvUpload(string $content, string $filename = 'tiers.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv_');
    file_put_contents($path, $content);

    return new UploadedFile($path, $filename, 'text/csv', null, true);
}

function makeXlsxUpload(array $rows, string $filename = 'tiers.xlsx'): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    foreach ($rows as $rowIdx => $row) {
        foreach ($row as $colIdx => $value) {
            $sheet->setCellValue([$colIdx + 1, $rowIdx + 1], $value);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'xlsx_').'.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($path);

    return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

// ---------------------------------------------------------------------------
// 1. Parse valid xlsx with 3 rows
// ---------------------------------------------------------------------------
it('parse un xlsx valide avec 3 lignes', function () {
    $file = makeXlsxUpload([
        ['nom', 'prenom', 'entreprise', 'email', 'telephone'],
        ['Dupont', 'Jean', '', 'jean@example.com', '0600000001'],
        ['Martin', 'Marie', '', 'marie@example.com', '0600000002'],
        ['', '', 'ACME Corp', 'acme@example.com', '0100000000'],
    ]);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows)->toHaveCount(3);
    expect($result->rows[0]['nom'])->toBe('Dupont');
    expect($result->rows[0]['prenom'])->toBe('Jean');
    expect($result->rows[0]['email'])->toBe('jean@example.com');
    expect($result->rows[2]['entreprise'])->toBe('ACME Corp');
});

// ---------------------------------------------------------------------------
// 2. Parse valid CSV UTF-8 with comma separator
// ---------------------------------------------------------------------------
it('parse un csv UTF-8 avec virgule comme séparateur', function () {
    $csv = "nom,prenom,entreprise,email\nDupont,Jean,,jean@example.com\nMartin,Marie,,marie@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows)->toHaveCount(2);
    expect($result->rows[0]['nom'])->toBe('Dupont');
    expect($result->rows[1]['prenom'])->toBe('Marie');
});

// ---------------------------------------------------------------------------
// 3. Parse valid CSV Windows-1252 with semicolon separator — accented chars
// ---------------------------------------------------------------------------
it('parse un csv Windows-1252 avec point-virgule et accents', function () {
    $utf8 = "nom;prenom;entreprise;email\nRéné;François;;rene@example.com\n";
    $win1252 = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
    $file = makeCsvUpload($win1252);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows)->toHaveCount(1);
    expect($result->rows[0]['nom'])->toBe('Réné');
    expect($result->rows[0]['prenom'])->toBe('François');
});

// ---------------------------------------------------------------------------
// 4. Auto-detect separator (semicolon vs comma)
// ---------------------------------------------------------------------------
it('détecte automatiquement le séparateur point-virgule', function () {
    $csv = "nom;prenom;entreprise;email\nDupont;Jean;;jean@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows[0]['nom'])->toBe('Dupont');
});

it('détecte automatiquement le séparateur virgule', function () {
    $csv = "nom,prenom,entreprise,email\nDupont,Jean,,jean@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows[0]['nom'])->toBe('Dupont');
});

// ---------------------------------------------------------------------------
// 5. Reject file without recognized header
// ---------------------------------------------------------------------------
it('rejette un fichier sans en-tête reconnu', function () {
    $csv = "colonne_a,colonne_b,colonne_c\nval1,val2,val3\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeFalse();
    expect($result->errors[0]['message'])->toContain('En-tête du fichier non reconnu');
});

// ---------------------------------------------------------------------------
// 6. Reject row without nom nor entreprise
// ---------------------------------------------------------------------------
it('rejette un fichier avec une ligne sans nom ni entreprise', function () {
    $csv = "nom,prenom,entreprise,email\n,,, jean@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeFalse();
    expect($result->errors[0]['message'])->toContain('Ligne 2');
    expect($result->errors[0]['message'])->toContain('nom ou entreprise requis');
});

// ---------------------------------------------------------------------------
// 7. Reject row with invalid email
// ---------------------------------------------------------------------------
it('rejette un fichier avec un email invalide', function () {
    $csv = "nom,prenom,entreprise,email\nDupont,Jean,,pas-un-email\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeFalse();
    expect($result->errors[0]['message'])->toContain('Ligne 2');
    expect($result->errors[0]['message'])->toContain('email invalide');
});

// ---------------------------------------------------------------------------
// 8. Detect internal duplicate (same nom+prenom+email)
// ---------------------------------------------------------------------------
it('détecte un doublon interne nom+prénom+email', function () {
    $csv = "nom,prenom,entreprise,email\nDupont,Jean,,jean@example.com\nDupont,Jean,,jean@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeFalse();
    expect($result->errors[0]['message'])->toContain('Lignes 2 et 3');
    expect($result->errors[0]['message'])->toContain('doublon');
});

// ---------------------------------------------------------------------------
// 8b. Homonymes with different emails are accepted
// ---------------------------------------------------------------------------
it('accepte des homonymes avec des emails différents', function () {
    $csv = "nom,prenom,entreprise,email\nDupont,Jean,,jean1@example.com\nDupont,Jean,,jean2@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows)->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// 9. Detect internal duplicate (same entreprise+email)
// ---------------------------------------------------------------------------
it('détecte un doublon interne entreprise', function () {
    $csv = "nom,prenom,entreprise,email\n,,ACME Corp,acme@example.com\n,,ACME Corp,acme@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeFalse();
    expect($result->errors[0]['message'])->toContain('Lignes 2 et 3');
    expect($result->errors[0]['message'])->toContain('doublon');
    expect($result->errors[0]['message'])->toContain('ACME Corp');
});

// ---------------------------------------------------------------------------
// 10. Type deduction: entreprise filled → type=entreprise
// ---------------------------------------------------------------------------
it('déduit le type entreprise quand entreprise est renseignée', function () {
    $csv = "nom,prenom,entreprise,email\n,,ACME Corp,acme@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows[0]['type'])->toBe('entreprise');
});

// ---------------------------------------------------------------------------
// 11. Type deduction: no entreprise → type=particulier
// ---------------------------------------------------------------------------
it('déduit le type particulier quand entreprise est vide', function () {
    $csv = "nom,prenom,entreprise,email\nDupont,Jean,,jean@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows[0]['type'])->toBe('particulier');
});

// ---------------------------------------------------------------------------
// 12. Default flags: no columns → both true
// ---------------------------------------------------------------------------
it('met les flags à true par défaut quand les colonnes sont absentes', function () {
    $csv = "nom,prenom,email\nDupont,Jean,jean@example.com\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows[0]['pour_depenses'])->toBeTrue();
    expect($result->rows[0]['pour_recettes'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// 13. Default flags: explicit values respected (1/0, oui/non)
// ---------------------------------------------------------------------------
it('respecte les valeurs explicites des flags', function () {
    $csv = "nom,prenom,entreprise,email,pour_depenses,pour_recettes\nDupont,Jean,,jean@example.com,1,0\nMartin,Marie,,marie@example.com,oui,non\nDurand,Pierre,,pierre@example.com,true,false\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();

    // Row 1: 1/0
    expect($result->rows[0]['pour_depenses'])->toBeTrue();
    expect($result->rows[0]['pour_recettes'])->toBeFalse();

    // Row 2: oui/non
    expect($result->rows[1]['pour_depenses'])->toBeTrue();
    expect($result->rows[1]['pour_recettes'])->toBeFalse();

    // Row 3: true/false
    expect($result->rows[2]['pour_depenses'])->toBeTrue();
    expect($result->rows[2]['pour_recettes'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// 14. Default pays: empty → France
// ---------------------------------------------------------------------------
it('met France par défaut quand pays est vide', function () {
    $csv = "nom,prenom,entreprise,email,pays\nDupont,Jean,,jean@example.com,\nMartin,Marie,,marie@example.com,Belgique\n";
    $file = makeCsvUpload($csv);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeTrue();
    expect($result->rows[0]['pays'])->toBe('France');
    expect($result->rows[1]['pays'])->toBe('Belgique');
});

// ---------------------------------------------------------------------------
// 15. Reject .xls file
// ---------------------------------------------------------------------------
it('rejette un fichier .xls', function () {
    $path = tempnam(sys_get_temp_dir(), 'xls_');
    file_put_contents($path, 'fake xls content');
    $file = new UploadedFile($path, 'tiers.xls', 'application/vnd.ms-excel', null, true);

    $result = app(TiersCsvParserService::class)->parse($file);

    expect($result->success)->toBeFalse();
    expect($result->errors[0]['message'])->toContain('.xls');
    expect($result->errors[0]['message'])->toContain('pas supporté');
});
