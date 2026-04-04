# Lot 4 — Exports rapports — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Excel and PDF exports on all 4 report screens, with a shared PDF layout for AG annexes.

**Architecture:** One `RapportExportController` dispatches to per-report export methods. A shared PDF Blade layout provides header/footer/pagination. Each Livewire component gets an `exportUrl()` method for the dropdown links (simple enough that a trait would be over-engineering). A controller trait `ResolvesLogos` extracts duplicated logo resolution logic.

**Tech Stack:** Laravel 11, Livewire 4, PhpSpreadsheet, barryvdh/laravel-dompdf, Bootstrap 5

**Spec:** `docs/superpowers/specs/2026-04-04-rapports-lot4-design.md`

---

### Task 1: Icônes menu Rapports

**Files:**
- Modify: `resources/views/layouts/app.blade.php:250-260`

- [ ] **Step 1: Add icons to the two menu entries**

In `resources/views/layouts/app.blade.php`, find:

```html
                                <a class="dropdown-item {{ request()->routeIs('compta.rapports.compte-resultat') ? 'active' : '' }}"
                                   href="{{ route('compta.rapports.compte-resultat') }}">
                                    Compte de résultat
                                </a>
```

Replace with:

```html
                                <a class="dropdown-item {{ request()->routeIs('compta.rapports.compte-resultat') ? 'active' : '' }}"
                                   href="{{ route('compta.rapports.compte-resultat') }}">
                                    <i class="bi bi-journal-text me-1"></i>Compte de résultat
                                </a>
```

Find:

```html
                                <a class="dropdown-item {{ request()->routeIs('compta.rapports.operations') ? 'active' : '' }}"
                                   href="{{ route('compta.rapports.operations') }}">
                                    Compte de résultat par opérations
                                </a>
```

Replace with:

```html
                                <a class="dropdown-item {{ request()->routeIs('compta.rapports.operations') ? 'active' : '' }}"
                                   href="{{ route('compta.rapports.operations') }}">
                                    <i class="bi bi-diagram-3 me-1"></i>Compte de résultat par opérations
                                </a>
```

- [ ] **Step 2: Verify visually**

Open http://localhost in browser, check the Rapports dropdown — all 4 entries should have icons.

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "style(rapports): add icons to menu entries"
```

---

### Task 2: Trait ResolvesLogos

**Files:**
- Create: `app/Http/Controllers/Concerns/ResolvesLogos.php`

- [ ] **Step 1: Create the trait**

Extract the `resolveLogos()` method from `ParticipantPdfController` into a reusable trait. The trait provides two methods: one for operation-specific logos (header=type logo, footer=asso logo) and one for association-only logos (reports).

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Association;
use App\Models\Operation;
use Illuminate\Support\Facades\Storage;

trait ResolvesLogos
{
    /**
     * Resolve header and footer logos for operation documents.
     * Header: type logo if defined, else association logo.
     * Footer: association logo only when header uses the type logo.
     *
     * @return array{0: ?string, 1: string, 2: ?string, 3: string}
     */
    private function resolveLogos(?Association $association, Operation $operation): array
    {
        $assoBase64 = null;
        $assoMime = 'image/png';
        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $assoBase64 = base64_encode(Storage::disk('public')->get($association->logo_path));
            $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
            $assoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        $typeLogo = $operation->typeOperation?->logo_path;
        if ($typeLogo && Storage::disk('public')->exists($typeLogo)) {
            $typeBase64 = base64_encode(Storage::disk('public')->get($typeLogo));
            $ext = strtolower(pathinfo($typeLogo, PATHINFO_EXTENSION));
            $typeMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';

            return [$typeBase64, $typeMime, $assoBase64, $assoMime];
        }

        return [$assoBase64, $assoMime, null, 'image/png'];
    }

    /**
     * Resolve association logo only (for reports without operation context).
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveAssociationLogo(?Association $association): array
    {
        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $base64 = base64_encode(Storage::disk('public')->get($association->logo_path));
            $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';

            return [$base64, $mime];
        }

        return [null, 'image/png'];
    }
}
```

- [ ] **Step 2: Migrate ParticipantPdfController to use the trait**

In `app/Http/Controllers/ParticipantPdfController.php`:
- Add `use Concerns\ResolvesLogos;` inside the class
- Remove the private `resolveLogos()` method (lines 74-94)

- [ ] **Step 3: Run existing tests**

Run: `./vendor/bin/sail test tests/Feature/ --filter=Participant`

Expected: All existing participant PDF tests still pass.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Concerns/ResolvesLogos.php app/Http/Controllers/ParticipantPdfController.php
git commit -m "refactor: extract ResolvesLogos trait from ParticipantPdfController"
```

---

### Task 3: Shared PDF Layout

**Files:**
- Create: `resources/views/pdf/rapport-layout.blade.php`

- [ ] **Step 1: Create the shared PDF layout**

```blade
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 14px;
            color: #212529;
            line-height: 1.4;
            margin: 15mm;
        }
        table { width: 100%; border-collapse: collapse; }

        /* Header */
        .header { margin-bottom: 14px; }
        .header .logo { max-height: 96px; max-width: 192px; }
        .association-name { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 12px; color: #6c757d; }
        .doc-title { font-size: 18px; font-weight: bold; color: #A9014F; text-align: right; }
        .doc-subtitle { font-size: 13px; color: #6c757d; text-align: right; margin-top: 2px; }

        /* Footer */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #999;
        }
        .footer-table { width: 100%; }
        .footer-table td { padding: 0; border: none; }
        .page-number:after { content: counter(page) " / " counter(pages); }

        /* Data table */
        .data-table { margin-top: 10px; }
        .data-table th {
            background-color: #fff;
            color: #212529;
            padding: 5px 6px;
            font-size: 12px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #212529;
        }
        .data-table td {
            padding: 4px 6px;
            border-bottom: 1px solid #dee2e6;
            font-size: 12px;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-muted { color: #6c757d; }
        .fw-bold { font-weight: bold; }

        /* Report-specific styles */
        .cr-section-header td { background: #3d5473; color: #fff; font-weight: 700; font-size: 14px; border-bottom: none; padding: 6px 10px; }
        .cr-cat td { background: #dce6f0; color: #1e3a5f; font-weight: 600; border-bottom: 1px solid #b8ccdf; padding: 5px 10px; font-size: 12px; }
        .cr-sub td { background: #f7f9fc; color: #444; border-bottom: 1px solid #e2e8f0; padding: 4px 10px; font-size: 11px; }
        .cr-total td { background: #5a7fa8; color: #fff; font-weight: 700; font-size: 13px; border-bottom: none; padding: 7px 10px; }
        .cr-result-pos { background: #2E7D32; color: #fff; font-weight: 700; font-size: 14px; padding: 10px; text-align: center; margin-top: 10px; }
        .cr-result-neg { background: #B5453A; color: #fff; font-weight: 700; font-size: 14px; padding: 10px; text-align: center; margin-top: 10px; }

        @yield('styles')
    </style>
</head>
<body>
    {{-- Footer (position fixed = every page) --}}
    <div class="footer">
        <table class="footer-table">
            <tr>
                <td style="text-align:left;width:33%;">{{ config('app.name') }}</td>
                <td style="text-align:center;width:34%;"><span class="page-number"></span></td>
                <td style="text-align:right;width:33%;">Généré le {{ now()->format('d/m/Y à H:i') }}</td>
            </tr>
        </table>
    </div>

    {{-- Header --}}
    <table class="header">
        <tr>
            <td style="width:60%">
                @if($headerLogoBase64 ?? false)
                    <img class="logo" src="data:{{ $headerLogoMime }};base64,{{ $headerLogoBase64 }}" alt="Logo">
                @endif
                @if($association ?? false)
                    <div class="association-name">{{ $association->nom }}</div>
                    <div class="association-address">
                        {{ $association->adresse }}
                        @if($association->code_postal || $association->ville)
                            — {{ $association->code_postal }} {{ $association->ville }}
                        @endif
                    </div>
                @endif
            </td>
            <td style="width:40%">
                <div class="doc-title">{{ $title }}</div>
                @if($subtitle ?? false)
                    <div class="doc-subtitle">{{ $subtitle }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Content --}}
    @yield('content')
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/pdf/rapport-layout.blade.php
git commit -m "feat(rapports): shared PDF layout with header/footer/pagination"
```

---

### Task 4: Route and RapportExportController skeleton

**Files:**
- Create: `app/Http/Controllers/RapportExportController.php`
- Modify: `routes/web.php:102`
- Create: `tests/Feature/RapportExportTest.php`

- [ ] **Step 1: Write the test for the route**

Create `tests/Feature/RapportExportTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('requires authentication for export', function () {
    auth()->logout();
    $this->get('/compta/rapports/export/compte-resultat/xlsx')
        ->assertRedirect(route('login'));
});

it('rejects invalid rapport name', function () {
    $this->get('/compta/rapports/export/invalid-report/xlsx')
        ->assertNotFound();
});

it('rejects invalid format', function () {
    $this->get('/compta/rapports/export/compte-resultat/csv')
        ->assertNotFound();
});

it('rejects pdf format for analyse reports', function () {
    $this->get('/compta/rapports/export/analyse-financier/pdf')
        ->assertNotFound();
});

it('exports compte-resultat as xlsx', function () {
    $this->get('/compta/rapports/export/compte-resultat/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports compte-resultat as pdf', function () {
    $this->get('/compta/rapports/export/compte-resultat/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
```

- [ ] **Step 2: Run tests to see them fail**

Run: `./vendor/bin/sail test tests/Feature/RapportExportTest.php`

Expected: FAIL — route not found.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/RapportExportController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesLogos;
use App\Models\Association;
use App\Services\ExerciceService;
use App\Services\RapportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RapportExportController extends Controller
{
    use ResolvesLogos;

    /** Rapports and their allowed formats */
    private const RAPPORTS = [
        'compte-resultat' => ['xlsx', 'pdf'],
        'operations' => ['xlsx', 'pdf'],
        'flux-tresorerie' => ['xlsx', 'pdf'],
        'analyse-financier' => ['xlsx'],
        'analyse-participants' => ['xlsx'],
    ];

    /** PDF orientations */
    private const PDF_ORIENTATION = [
        'compte-resultat' => 'portrait',
        'operations' => 'landscape',
        'flux-tresorerie' => 'portrait',
    ];

    /** Human-readable rapport names (for filenames and titles) */
    private const TITLES = [
        'compte-resultat' => 'Compte de resultat',
        'operations' => 'CR par operations',
        'flux-tresorerie' => 'Flux de tresorerie',
        'analyse-financier' => 'Analyse financiere',
        'analyse-participants' => 'Analyse participants',
    ];

    public function __invoke(
        Request $request,
        string $rapport,
        string $format,
        RapportService $rapportService,
        ExerciceService $exerciceService,
    ): Response {
        if (! isset(self::RAPPORTS[$rapport]) || ! in_array($format, self::RAPPORTS[$rapport], true)) {
            throw new NotFoundHttpException;
        }

        $exercice = $request->integer('exercice', $exerciceService->current());
        $label = $exerciceService->label($exercice);

        $association = Association::find(1);
        $filename = $this->buildFilename($association, $rapport, $label, $format);

        return match ($format) {
            'xlsx' => $this->exportXlsx($rapport, $exercice, $label, $request, $rapportService, $exerciceService, $filename),
            'pdf' => $this->exportPdf($rapport, $exercice, $label, $request, $rapportService, $association, $filename),
        };
    }

    private function buildFilename(?Association $association, string $rapport, string $label, string $format): string
    {
        $prefix = $association?->nom
            ? Str::ascii($association->nom).' - '
            : '';

        return $prefix.self::TITLES[$rapport].' '.$label.'.'.$format;
    }

    // ── Excel exports ─────────────────────────────────────────────────────────

    private function exportXlsx(
        string $rapport,
        int $exercice,
        string $label,
        Request $request,
        RapportService $rapportService,
        ExerciceService $exerciceService,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = match ($rapport) {
            'compte-resultat' => $this->xlsxCompteResultat($rapportService, $exercice, $label),
            'operations' => $this->xlsxOperations($rapportService, $exercice, $request),
            'flux-tresorerie' => $this->xlsxFluxTresorerie($rapportService, $exercice),
            'analyse-financier' => $this->xlsxAnalyse('financier', $exercice, $exerciceService),
            'analyse-participants' => $this->xlsxAnalyse('participants', $exercice, $exerciceService),
        };

        $this->autoSizeColumns($spreadsheet);

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(function () use ($writer): void {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
        ]);
    }

    private function xlsxCompteResultat(RapportService $rapportService, int $exercice, string $label): Spreadsheet
    {
        $data = $rapportService->compteDeResultat($exercice);
        $labelN1 = ($exercice - 1).'-'.$exercice;
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Compte de résultat');

        $row = 1;
        $sheet->fromArray([['Type', 'Catégorie', 'Sous-catégorie', $labelN1, $label, 'Budget', 'Écart']], null, 'A'.$row);
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $row++;

        foreach ([['Charge', $data['charges']], ['Produit', $data['produits']]] as [$type, $sections]) {
            foreach ($sections as $cat) {
                foreach ($cat['sous_categories'] as $sc) {
                    $ecart = ($sc['budget'] !== null && $sc['montant_n'] !== null)
                        ? (float) $sc['montant_n'] - (float) $sc['budget']
                        : null;
                    $sheet->fromArray([[
                        $type,
                        $cat['label'],
                        $sc['label'],
                        $sc['montant_n1'] !== null ? (float) $sc['montant_n1'] : null,
                        (float) $sc['montant_n'],
                        $sc['budget'] !== null ? (float) $sc['budget'] : null,
                        $ecart,
                    ]], null, 'A'.$row);
                    $row++;
                }
                // Category subtotal
                $sheet->fromArray([[
                    $type,
                    $cat['label'],
                    'TOTAL',
                    $cat['montant_n1'] !== null ? (float) $cat['montant_n1'] : null,
                    (float) $cat['montant_n'],
                    $cat['budget'] !== null ? (float) $cat['budget'] : null,
                    ($cat['budget'] !== null) ? (float) $cat['montant_n'] - (float) $cat['budget'] : null,
                ]], null, 'A'.$row);
                $sheet->getStyle('A'.$row.':G'.$row)->getFont()->setBold(true);
                $row++;
            }
        }

        // Format number columns
        $sheet->getStyle('D2:G'.$row)->getNumberFormat()->setFormatCode('#,##0.00');

        return $spreadsheet;
    }

    private function xlsxOperations(RapportService $rapportService, int $exercice, Request $request): Spreadsheet
    {
        $operationIds = array_map('intval', (array) $request->query('ops', []));
        $parSeances = $request->boolean('seances');
        $parTiers = $request->boolean('tiers');

        $data = $rapportService->compteDeResultatOperations($exercice, $operationIds, $parSeances, $parTiers);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('CR par opérations');

        $seances = $data['seances'] ?? [];
        $row = 1;

        // Header row
        if ($parSeances) {
            $headers = ['Type', 'Catégorie', 'Sous-catégorie'];
            if ($parTiers) {
                $headers[] = 'Tiers';
            }
            foreach ($seances as $s) {
                $headers[] = $s === 0 ? 'Hors séances' : 'S'.$s;
            }
            $headers[] = 'Total';
        } else {
            $headers = ['Type', 'Catégorie', 'Sous-catégorie'];
            if ($parTiers) {
                $headers[] = 'Tiers';
            }
            $headers[] = 'Montant';
        }
        $sheet->fromArray([$headers], null, 'A'.$row);
        $sheet->getStyle('A1:'.chr(64 + count($headers)).'1')->getFont()->setBold(true);
        $row++;

        foreach ([['Charge', $data['charges']], ['Produit', $data['produits']]] as [$type, $sections]) {
            foreach ($sections as $cat) {
                foreach ($cat['sous_categories'] as $sc) {
                    if ($parTiers && ! empty($sc['tiers'])) {
                        foreach ($sc['tiers'] as $t) {
                            $values = [$type, $cat['label'], $sc['label'], $t['label']];
                            if ($parSeances) {
                                foreach ($seances as $s) {
                                    $values[] = (float) ($t['seances'][$s] ?? 0);
                                }
                                $values[] = (float) ($t['total'] ?? 0);
                            } else {
                                $values[] = (float) ($t['montant'] ?? 0);
                            }
                            $sheet->fromArray([$values], null, 'A'.$row);
                            $row++;
                        }
                    }
                    // Sous-catégorie subtotal row
                    $values = [$type, $cat['label'], $sc['label']];
                    if ($parTiers) {
                        $values[] = 'TOTAL';
                    }
                    if ($parSeances) {
                        foreach ($seances as $s) {
                            $values[] = (float) ($sc['seances'][$s] ?? 0);
                        }
                        $values[] = (float) ($sc['total'] ?? 0);
                    } else {
                        $values[] = (float) ($sc['montant'] ?? 0);
                    }
                    $sheet->fromArray([$values], null, 'A'.$row);
                    if ($parTiers) {
                        $sheet->getStyle('A'.$row.':'.chr(64 + count($headers)).$row)->getFont()->setBold(true);
                    }
                    $row++;
                }
                // Category total row
                $values = [$type, $cat['label'], 'TOTAL'];
                if ($parTiers) {
                    $values[] = '';
                }
                if ($parSeances) {
                    foreach ($seances as $s) {
                        $values[] = (float) ($cat['seances'][$s] ?? 0);
                    }
                    $values[] = (float) ($cat['total'] ?? 0);
                } else {
                    $values[] = (float) ($cat['montant'] ?? 0);
                }
                $sheet->fromArray([$values], null, 'A'.$row);
                $sheet->getStyle('A'.$row.':'.chr(64 + count($headers)).$row)->getFont()->setBold(true);
                $row++;
            }
        }

        // Format number columns
        $firstNumCol = $parTiers ? 'E' : 'D';
        $lastCol = chr(64 + count($headers));
        if ($row > 2) {
            $sheet->getStyle($firstNumCol.'2:'.$lastCol.($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        }

        return $spreadsheet;
    }

    private function xlsxFluxTresorerie(RapportService $rapportService, int $exercice): Spreadsheet
    {
        $data = $rapportService->fluxTresorerie($exercice);
        $spreadsheet = new Spreadsheet;

        // Sheet 1: Synthèse + Mensuel
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Synthèse + Mensuel');

        $row = 1;
        $sheet->fromArray([['', 'Recettes', 'Dépenses', 'Solde (R-D)', 'Trésorerie cumulée']], null, 'A'.$row);
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $row++;

        $sheet->fromArray([['Solde ouverture', null, null, null, $data['synthese']['solde_ouverture']]], null, 'A'.$row);
        $sheet->getStyle('A'.$row.':E'.$row)->getFont()->setBold(true);
        $row++;

        foreach ($data['mensuel'] as $m) {
            $sheet->fromArray([[$m['mois'], $m['recettes'], $m['depenses'], $m['solde'], $m['cumul']]], null, 'A'.$row);
            $row++;
        }

        // Totaux
        $sheet->fromArray([['TOTAL', $data['synthese']['total_recettes'], $data['synthese']['total_depenses'], $data['synthese']['variation'], $data['synthese']['solde_theorique']]], null, 'A'.$row);
        $sheet->getStyle('A'.$row.':E'.$row)->getFont()->setBold(true);
        $sheet->getStyle('B2:E'.$row)->getNumberFormat()->setFormatCode('#,##0.00');

        // Sheet 2: Rapprochement
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Rapprochement');
        $row = 1;

        $sheet2->fromArray([['Élément', 'Montant']], null, 'A'.$row);
        $sheet2->getStyle('A1:B1')->getFont()->setBold(true);
        $row++;

        $sheet2->fromArray([['Solde théorique', $data['rapprochement']['solde_theorique']]], null, 'A'.$row);
        $row++;

        $sheet2->fromArray([['- Recettes non pointées ('.$data['rapprochement']['nb_recettes_non_pointees'].')', $data['rapprochement']['recettes_non_pointees']]], null, 'A'.$row);
        $row++;
        $sheet2->fromArray([['+ Dépenses non pointées ('.$data['rapprochement']['nb_depenses_non_pointees'].')', $data['rapprochement']['depenses_non_pointees']]], null, 'A'.$row);
        $row++;

        foreach ($data['rapprochement']['comptes_systeme'] as $cs) {
            $sheet2->fromArray([['- '.$cs['nom'].' ('.$cs['nb_ecritures'].' écr.)', $cs['solde']]], null, 'A'.$row);
            $row++;
        }

        $sheet2->fromArray([['= Solde bancaire réel', $data['rapprochement']['solde_reel']]], null, 'A'.$row);
        $sheet2->getStyle('A'.$row.':B'.$row)->getFont()->setBold(true);
        $sheet2->getStyle('B2:B'.$row)->getNumberFormat()->setFormatCode('#,##0.00');

        return $spreadsheet;
    }

    private function xlsxAnalyse(string $mode, int $exercice, ExerciceService $exerciceService): Spreadsheet
    {
        // Re-use the AnalysePivot data logic
        $pivot = new \App\Livewire\AnalysePivot;
        $pivot->mode = $mode;
        $pivot->filterExercice = $exercice;

        $data = $mode === 'participants'
            ? $pivot->getParticipantsDataProperty()
            : $pivot->getFinancierDataProperty();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($mode === 'participants' ? 'Participants' : 'Analyse financière');

        if (empty($data)) {
            $sheet->setCellValue('A1', 'Aucune donnée');

            return $spreadsheet;
        }

        // Headers from first row keys
        $headers = array_keys($data[0]);
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->getStyle('A1:'.chr(64 + count($headers)).'1')->getFont()->setBold(true);

        $row = 2;
        foreach ($data as $entry) {
            $sheet->fromArray([array_values($entry)], null, 'A'.$row);
            $row++;
        }

        // Format "Montant" or "Montant prévu" column as number
        $montantCol = null;
        foreach ($headers as $i => $h) {
            if (in_array($h, ['Montant', 'Montant prévu'], true)) {
                $montantCol = chr(65 + $i);
                break;
            }
        }
        if ($montantCol && $row > 2) {
            $sheet->getStyle($montantCol.'2:'.$montantCol.($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        }

        return $spreadsheet;
    }

    // ── PDF exports ───────────────────────────────────────────────────────────

    private function exportPdf(
        string $rapport,
        int $exercice,
        string $label,
        Request $request,
        RapportService $rapportService,
        ?Association $association,
        string $filename,
    ): Response {
        [$headerLogoBase64, $headerLogoMime] = $this->resolveAssociationLogo($association);

        $orientation = self::PDF_ORIENTATION[$rapport];
        $subtitle = 'Exercice '.$label;

        $viewData = match ($rapport) {
            'compte-resultat' => $this->pdfCompteResultatData($rapportService, $exercice, $label),
            'operations' => $this->pdfOperationsData($rapportService, $exercice, $request),
            'flux-tresorerie' => $this->pdfFluxTresorerieData($rapportService, $exercice),
        };

        if (isset($viewData['subtitle'])) {
            $subtitle = $viewData['subtitle'];
        }

        $data = array_merge($viewData, [
            'title' => self::TITLES[$rapport],
            'subtitle' => $subtitle,
            'association' => $association,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
        ]);

        $view = 'pdf.rapport-'.str_replace('-', '-', $rapport);

        $pdf = Pdf::loadView($view, $data)->setPaper('a4', $orientation);

        return $pdf->stream($filename);
    }

    private function pdfCompteResultatData(RapportService $rapportService, int $exercice, string $label): array
    {
        $data = $rapportService->compteDeResultat($exercice);
        $totalChargesN = collect($data['charges'])->sum('montant_n');
        $totalProduitsN = collect($data['produits'])->sum('montant_n');

        return [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'labelN' => $label,
            'labelN1' => ($exercice - 1).'-'.$exercice,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'resultatNet' => $totalProduitsN - $totalChargesN,
        ];
    }

    private function pdfOperationsData(RapportService $rapportService, int $exercice, Request $request): array
    {
        $operationIds = array_map('intval', (array) $request->query('ops', []));
        $parSeances = $request->boolean('seances');
        $parTiers = $request->boolean('tiers');

        $data = $rapportService->compteDeResultatOperations($exercice, $operationIds, $parSeances, $parTiers);
        $seances = $data['seances'] ?? [];

        $totalCharges = $parSeances
            ? collect($data['charges'])->sum('total')
            : collect($data['charges'])->sum('montant');
        $totalProduits = $parSeances
            ? collect($data['produits'])->sum('total')
            : collect($data['produits'])->sum('montant');

        return [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'seances' => $seances,
            'parSeances' => $parSeances,
            'parTiers' => $parTiers,
            'totalCharges' => $totalCharges,
            'totalProduits' => $totalProduits,
            'resultatNet' => $totalProduits - $totalCharges,
        ];
    }

    private function pdfFluxTresorerieData(RapportService $rapportService, int $exercice): array
    {
        return $rapportService->fluxTresorerie($exercice);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function autoSizeColumns(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $highestCol = $sheet->getHighestColumn();
            $col = 'A';
            while ($col !== $highestCol) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
                $col++;
            }
            $sheet->getColumnDimension($highestCol)->setAutoSize(true);
        }
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, after line 101 (the redirect line for rapports), add:

```php
        Route::get('/rapports/export/{rapport}/{format}', RapportExportController::class)->name('rapports.export');
```

Also add the import at the top of the file:

```php
use App\Http\Controllers\RapportExportController;
```

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/sail test tests/Feature/RapportExportTest.php`

Expected: All 6 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/RapportExportController.php tests/Feature/RapportExportTest.php routes/web.php
git commit -m "feat(rapports): export controller with Excel + PDF for all reports"
```

---

### Task 5: PDF view — Compte de résultat

**Files:**
- Create: `resources/views/pdf/rapport-compte-resultat.blade.php`

- [ ] **Step 1: Create the PDF view**

```blade
@extends('pdf.rapport-layout')

@section('content')
    @php
        $fmt = fn(?float $v): string => $v !== null ? number_format($v, 2, ',', ' ') . ' €' : '—';
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'isCharge' => true, 'total' => $totalChargesN],
               ['data' => $produits, 'label' => 'RECETTES', 'isCharge' => false, 'total' => $totalProduitsN]] as $section)
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            <tr class="cr-section-header">
                <td colspan="2">{{ $section['label'] }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN1 }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">Budget</td>
                <td class="text-right" style="width:80px;font-weight:400;font-size:10px;opacity:.85;">Écart</td>
            </tr>
            @foreach ($section['data'] as $cat)
                @php
                    $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) =>
                        $sc['montant_n'] > 0 || ($sc['montant_n1'] !== null && $sc['montant_n1'] > 0) || ($sc['budget'] !== null && $sc['budget'] > 0)
                    );
                @endphp
                @if (! $scVisibles->isEmpty())
                    <tr class="cr-cat">
                        <td colspan="2">{{ $cat['label'] }}</td>
                        <td class="text-right">{!! $fmt($cat['montant_n1']) !!}</td>
                        <td class="text-right">{!! $fmt($cat['montant_n']) !!}</td>
                        <td class="text-right">{!! $fmt($cat['budget']) !!}</td>
                        <td class="text-right">
                            @if ($cat['budget'] !== null)
                                {{ number_format((float)$cat['montant_n'] - (float)$cat['budget'], 2, ',', ' ') }} €
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @foreach ($scVisibles as $sc)
                        <tr class="cr-sub">
                            <td style="width:20px;"></td>
                            <td style="padding-left:20px;">{{ $sc['label'] }}</td>
                            <td class="text-right">{!! $fmt($sc['montant_n1']) !!}</td>
                            <td class="text-right">{!! $fmt($sc['montant_n']) !!}</td>
                            <td class="text-right">{!! $fmt($sc['budget']) !!}</td>
                            <td class="text-right">
                                @if ($sc['budget'] !== null)
                                    {{ number_format((float)$sc['montant_n'] - (float)$sc['budget'], 2, ',', ' ') }} €
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
            <tr class="cr-total">
                <td colspan="2">TOTAL {{ $section['label'] }}</td>
                <td class="text-right">—</td>
                <td class="text-right">{{ number_format($section['total'], 2, ',', ' ') }} €</td>
                <td class="text-right">—</td>
                <td class="text-right">—</td>
            </tr>
        </tbody>
    </table>
    @endforeach

    <div class="{{ $resultatNet >= 0 ? 'cr-result-pos' : 'cr-result-neg' }}">
        {{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }} : {{ number_format(abs($resultatNet), 2, ',', ' ') }} €
    </div>
@endsection
```

- [ ] **Step 2: Test manually**

Open http://localhost/compta/rapports/export/compte-resultat/pdf in browser. Verify header, footer, pagination, and content render correctly.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pdf/rapport-compte-resultat.blade.php
git commit -m "feat(rapports): PDF view for compte de résultat"
```

---

### Task 6: PDF view — CR par opérations

**Files:**
- Create: `resources/views/pdf/rapport-operations.blade.php`

- [ ] **Step 1: Create the PDF view**

```blade
@extends('pdf.rapport-layout')

@section('styles')
    .cr-tiers td { background: #fff; color: #666; font-size: 10px; border-bottom: 1px solid #f0f0f0; }
@endsection

@section('content')
    @php
        $fmt = fn(float $v): string => number_format($v, 2, ',', ' ') . ' €';
        $colCount = $parSeances ? count($seances) + 3 : 3;
        if ($parTiers) $colCount++;
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'totalMontant' => $totalCharges],
               ['data' => $produits, 'label' => 'RECETTES', 'totalMontant' => $totalProduits]] as $section)
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            {{-- Header --}}
            <tr class="cr-section-header">
                @if ($parTiers)
                    <td colspan="2"></td>
                @else
                    <td colspan="2"></td>
                @endif
                @if ($parSeances)
                    @foreach ($seances as $s)
                        <td class="text-right" style="width:70px;font-size:9px;opacity:.85;">{{ $s === 0 ? 'Hors S.' : 'S'.$s }}</td>
                    @endforeach
                    <td class="text-right" style="width:70px;font-size:9px;opacity:.85;">Total</td>
                @else
                    <td class="text-right" style="width:100px;font-size:10px;opacity:.85;">Montant</td>
                @endif
            </tr>
            <tr class="cr-section-header">
                <td colspan="{{ $colCount }}">{{ $section['label'] }}</td>
            </tr>

            @foreach ($section['data'] as $cat)
                @php
                    $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) =>
                        ($parSeances ? ($sc['total'] ?? 0) : ($sc['montant'] ?? 0)) > 0
                    );
                @endphp
                @if (! $scVisibles->isEmpty())
                    {{-- Category row --}}
                    <tr class="cr-cat">
                        <td colspan="{{ $parTiers ? 2 : 2 }}">{{ $cat['label'] }}</td>
                        @if ($parSeances)
                            @foreach ($seances as $s)
                                <td class="text-right">{{ ($cat['seances'][$s] ?? 0) > 0 ? $fmt($cat['seances'][$s]) : '—' }}</td>
                            @endforeach
                            <td class="text-right">{{ $fmt($cat['total']) }}</td>
                        @else
                            <td class="text-right">{{ $fmt($cat['montant']) }}</td>
                        @endif
                    </tr>

                    @foreach ($scVisibles as $sc)
                        {{-- Sub-category row --}}
                        <tr class="cr-sub">
                            <td style="width:20px;"></td>
                            <td>{{ $sc['label'] }}</td>
                            @if ($parSeances)
                                @foreach ($seances as $s)
                                    <td class="text-right">{{ ($sc['seances'][$s] ?? 0) > 0 ? $fmt($sc['seances'][$s]) : '—' }}</td>
                                @endforeach
                                <td class="text-right fw-bold">{{ $fmt($sc['total']) }}</td>
                            @else
                                <td class="text-right">{{ $fmt($sc['montant']) }}</td>
                            @endif
                        </tr>

                        {{-- Tiers rows --}}
                        @if ($parTiers && ! empty($sc['tiers']))
                            @foreach ($sc['tiers'] as $t)
                                @if (($parSeances ? ($t['total'] ?? 0) : ($t['montant'] ?? 0)) > 0)
                                <tr class="cr-tiers">
                                    <td style="width:20px;"></td>
                                    <td style="padding-left:30px;">{{ $t['label'] }}</td>
                                    @if ($parSeances)
                                        @foreach ($seances as $s)
                                            <td class="text-right">{{ ($t['seances'][$s] ?? 0) > 0 ? $fmt($t['seances'][$s]) : '—' }}</td>
                                        @endforeach
                                        <td class="text-right">{{ $fmt($t['total']) }}</td>
                                    @else
                                        <td class="text-right">{{ $fmt($t['montant']) }}</td>
                                    @endif
                                </tr>
                                @endif
                            @endforeach
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Section total --}}
            @php
                $totalSectionSeances = [];
                if ($parSeances) {
                    $totalSectionSeances = array_fill_keys($seances, 0.0);
                    foreach ($section['data'] as $cat) {
                        foreach ($seances as $s) {
                            $totalSectionSeances[$s] += $cat['seances'][$s] ?? 0.0;
                        }
                    }
                }
            @endphp
            <tr class="cr-total">
                <td colspan="2">TOTAL {{ $section['label'] }}</td>
                @if ($parSeances)
                    @foreach ($seances as $s)
                        <td class="text-right">{{ $fmt($totalSectionSeances[$s]) }}</td>
                    @endforeach
                    <td class="text-right">{{ $fmt($section['totalMontant']) }}</td>
                @else
                    <td class="text-right">{{ $fmt($section['totalMontant']) }}</td>
                @endif
            </tr>
        </tbody>
    </table>
    @endforeach

    <div class="{{ $resultatNet >= 0 ? 'cr-result-pos' : 'cr-result-neg' }}">
        {{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }} : {{ number_format(abs($resultatNet), 2, ',', ' ') }} €
    </div>
@endsection
```

- [ ] **Step 2: Test manually**

Open http://localhost/compta/rapports/export/operations/pdf?ops[]=1&seances=1&tiers=1 in browser (adjust IDs to match your data).

- [ ] **Step 3: Commit**

```bash
git add resources/views/pdf/rapport-operations.blade.php
git commit -m "feat(rapports): PDF view for CR par opérations"
```

---

### Task 7: PDF view — Flux de trésorerie

**Files:**
- Create: `resources/views/pdf/rapport-flux-tresorerie.blade.php`

- [ ] **Step 1: Create the PDF view**

```blade
@extends('pdf.rapport-layout')

@section('styles')
    .ft-section td { background: #3d5473; color: #fff; font-weight: 700; font-size: 13px; padding: 8px 12px; border: none; }
    .ft-row td { padding: 8px 12px; font-size: 12px; border-bottom: 1px solid #e2e8f0; }
    .ft-row-bold td { padding: 8px 12px; font-size: 12px; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
    .ft-total td { background: #5a7fa8; color: #fff; font-weight: 700; font-size: 13px; padding: 9px 12px; border: none; }
    .ft-result td { background: #3d5473; color: #fff; font-weight: 700; font-size: 14px; padding: 10px 12px; border: none; }
    .ft-rappr td { background: #f7f9fc; padding: 6px 12px; font-size: 12px; border-bottom: 1px solid #e2e8f0; }
    .ft-rappr-detail td { padding: 4px 12px 4px 36px; font-size: 11px; color: #666; border-bottom: 1px solid #f0f0f0; }
    .ft-rappr-result td { background: #dce6f0; font-weight: 700; padding: 8px 12px; font-size: 13px; }
@endsection

@section('content')
    @php
        $fmt = fn(float $v): string => number_format($v, 2, ',', ' ') . ' €';
    @endphp

    {{-- Status badge --}}
    @if ($exercice['is_cloture'])
        <div style="background:#d4edda;color:#155724;padding:6px 12px;font-size:11px;margin-bottom:10px;border-radius:4px;">
            Rapport définitif — Exercice {{ $exercice['label'] }} clôturé le {{ $exercice['date_cloture'] }}
        </div>
    @else
        <div style="background:#d1ecf1;color:#0c5460;padding:6px 12px;font-size:11px;margin-bottom:10px;border-radius:4px;">
            Rapport provisoire — Exercice {{ $exercice['label'] }} en cours
        </div>
    @endif

    {{-- Synthèse + Mensuel --}}
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            <tr class="ft-section">
                <td></td>
                <td class="text-right" style="width:110px;font-weight:400;font-size:10px;">Recettes</td>
                <td class="text-right" style="width:110px;font-weight:400;font-size:10px;">Dépenses</td>
                <td class="text-right" style="width:110px;font-weight:400;font-size:10px;">Solde (R-D)</td>
                <td class="text-right" style="width:130px;font-weight:400;font-size:10px;">Trésorerie cumulée</td>
            </tr>

            <tr class="ft-row-bold">
                <td>Solde de trésorerie au {{ \Carbon\Carbon::parse($exercice['date_debut'])->translatedFormat('j F Y') }}</td>
                <td></td><td></td><td></td>
                <td class="text-right fw-bold">{{ $fmt($synthese['solde_ouverture']) }}</td>
            </tr>

            @foreach ($mensuel as $m)
                <tr class="ft-row">
                    <td style="padding-left:24px;">{{ $m['mois'] }}</td>
                    <td class="text-right">{{ $fmt($m['recettes']) }}</td>
                    <td class="text-right">{{ $fmt($m['depenses']) }}</td>
                    <td class="text-right" style="color:{{ $m['solde'] >= 0 ? '#2E7D32' : '#B5453A' }}">
                        {{ $m['solde'] >= 0 ? '+' : '' }}{{ $fmt($m['solde']) }}
                    </td>
                    <td class="text-right fw-bold">{{ $fmt($m['cumul']) }}</td>
                </tr>
            @endforeach

            <tr class="ft-total">
                <td>TOTAL</td>
                <td class="text-right">{{ $fmt($synthese['total_recettes']) }}</td>
                <td class="text-right">{{ $fmt($synthese['total_depenses']) }}</td>
                <td class="text-right">{{ $synthese['variation'] >= 0 ? '+' : '' }}{{ $fmt($synthese['variation']) }}</td>
                <td class="text-right">{{ $fmt($synthese['solde_theorique']) }}</td>
            </tr>

            <tr class="ft-result">
                <td colspan="4">Solde de trésorerie théorique au {{ \Carbon\Carbon::parse($exercice['date_fin'])->translatedFormat('j F Y') }}</td>
                <td class="text-right">{{ $fmt($synthese['solde_theorique']) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Rapprochement --}}
    <table class="data-table">
        <tbody>
            <tr class="ft-section">
                <td colspan="2">Rapprochement bancaire</td>
            </tr>
            <tr class="ft-rappr">
                <td>Solde théorique</td>
                <td class="text-right" style="width:150px;">{{ $fmt($rapprochement['solde_theorique']) }}</td>
            </tr>
            <tr class="ft-rappr">
                <td style="padding-left:24px;">− Recettes non pointées ({{ $rapprochement['nb_recettes_non_pointees'] }})</td>
                <td class="text-right">{{ $fmt($rapprochement['recettes_non_pointees']) }}</td>
            </tr>
            @foreach (collect($ecritures_non_pointees)->where('type', 'recette') as $e)
                <tr class="ft-rappr-detail">
                    <td>{{ $e['date'] }} · {{ $e['tiers'] }} · {{ $e['libelle'] }}</td>
                    <td class="text-right">{{ $fmt($e['montant']) }}</td>
                </tr>
            @endforeach
            <tr class="ft-rappr">
                <td style="padding-left:24px;">+ Dépenses non pointées ({{ $rapprochement['nb_depenses_non_pointees'] }})</td>
                <td class="text-right">{{ $fmt($rapprochement['depenses_non_pointees']) }}</td>
            </tr>
            @foreach (collect($ecritures_non_pointees)->where('type', 'depense') as $e)
                <tr class="ft-rappr-detail">
                    <td>{{ $e['date'] }} · {{ $e['tiers'] }} · {{ $e['libelle'] }}</td>
                    <td class="text-right">{{ $fmt($e['montant']) }}</td>
                </tr>
            @endforeach
            @foreach ($rapprochement['comptes_systeme'] as $cs)
                <tr class="ft-rappr">
                    <td style="padding-left:24px;">− {{ $cs['nom'] }} ({{ $cs['nb_ecritures'] }} écr.)</td>
                    <td class="text-right">{{ $fmt($cs['solde']) }}</td>
                </tr>
                @foreach ($cs['ecritures'] as $e)
                    <tr class="ft-rappr-detail">
                        <td>{{ $e['date'] }} · {{ $e['tiers'] }} · {{ $e['libelle'] }}</td>
                        <td class="text-right">{{ $fmt($e['montant']) }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr class="ft-rappr-result">
                <td>= Solde bancaire réel</td>
                <td class="text-right">{{ $fmt($rapprochement['solde_reel']) }}</td>
            </tr>
        </tbody>
    </table>
@endsection
```

- [ ] **Step 2: Test manually**

Open http://localhost/compta/rapports/export/flux-tresorerie/pdf in browser.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pdf/rapport-flux-tresorerie.blade.php
git commit -m "feat(rapports): PDF view for flux de trésorerie"
```

---

### Task 8: Export buttons on Compte de résultat

**Files:**
- Modify: `app/Livewire/RapportCompteResultat.php`
- Modify: `resources/views/livewire/rapport-compte-resultat.blade.php`

- [ ] **Step 1: Remove exportCsv and add export URL generation**

In `app/Livewire/RapportCompteResultat.php`, replace the entire `exportCsv()` method (lines 13-51) with:

```php
    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('compta.rapports.export', [
            'rapport' => 'compte-resultat',
            'format' => $format,
            'exercice' => $exercice,
        ]);
    }
```

- [ ] **Step 2: Update the Blade view**

In `resources/views/livewire/rapport-compte-resultat.blade.php`, replace lines 22-27 (the CSV export button):

```html
    {{-- Export --}}
    <div class="d-flex justify-content-end mb-3">
        <button wire:click="exportCsv" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download"></i> Exporter CSV
        </button>
    </div>
```

With:

```html
    {{-- Export --}}
    <div class="d-flex justify-content-end mb-3">
        <div class="btn-group">
            <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-download me-1"></i>Exporter
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="{{ $this->exportUrl('xlsx') }}"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a></li>
                <li><a class="dropdown-item" href="{{ $this->exportUrl('pdf') }}" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a></li>
            </ul>
        </div>
    </div>
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/sail test tests/Livewire/RapportCompteResultatTest.php`

Expected: All pass (the CSV test, if any, should be updated or removed).

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/RapportCompteResultat.php resources/views/livewire/rapport-compte-resultat.blade.php
git commit -m "feat(rapports): replace CSV button with Excel/PDF dropdown on compte de résultat"
```

---

### Task 9: Export buttons on CR par opérations

**Files:**
- Modify: `app/Livewire/RapportCompteResultatOperations.php`
- Modify: `resources/views/livewire/rapport-compte-resultat-operations.blade.php`

- [ ] **Step 1: Add export URL method to the component**

In `app/Livewire/RapportCompteResultatOperations.php`, add before the `render()` method:

```php
    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('compta.rapports.export', [
            'rapport' => 'operations',
            'format' => $format,
            'exercice' => $exercice,
            'ops' => $this->selectedOperationIds,
            'seances' => $this->parSeances ? '1' : '0',
            'tiers' => $this->parTiers ? '1' : '0',
        ]);
    }
```

- [ ] **Step 2: Add the export dropdown in the Blade view**

In `resources/views/livewire/rapport-compte-resultat-operations.blade.php`, inside the filter bar `<div class="d-flex align-items-center gap-3 flex-wrap">` (line 6), after the two toggle switches (after line 108), add:

```html
                {{-- Export dropdown --}}
                @if (! empty($selectedOperationIds))
                <div class="ms-auto">
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-download me-1"></i>Exporter
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ $this->exportUrl('xlsx') }}"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a></li>
                            <li><a class="dropdown-item" href="{{ $this->exportUrl('pdf') }}" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a></li>
                        </ul>
                    </div>
                </div>
                @endif
```

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/RapportCompteResultatOperations.php resources/views/livewire/rapport-compte-resultat-operations.blade.php
git commit -m "feat(rapports): export dropdown on CR par opérations"
```

---

### Task 10: Export buttons on Flux de trésorerie

**Files:**
- Modify: `app/Livewire/RapportFluxTresorerie.php`
- Modify: `resources/views/livewire/rapport-flux-tresorerie.blade.php`

- [ ] **Step 1: Add export URL method**

In `app/Livewire/RapportFluxTresorerie.php`, add before the `render()` method:

```php
    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('compta.rapports.export', [
            'rapport' => 'flux-tresorerie',
            'format' => $format,
            'exercice' => $exercice,
        ]);
    }
```

- [ ] **Step 2: Add the export dropdown in the Blade view**

In `resources/views/livewire/rapport-flux-tresorerie.blade.php`, after the status badge (after line 42, before the main table), add:

```html
    {{-- Export --}}
    <div class="d-flex justify-content-end mb-3">
        <div class="btn-group">
            <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-download me-1"></i>Exporter
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="{{ $this->exportUrl('xlsx') }}"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a></li>
                <li><a class="dropdown-item" href="{{ $this->exportUrl('pdf') }}" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a></li>
            </ul>
        </div>
    </div>
```

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/RapportFluxTresorerie.php resources/views/livewire/rapport-flux-tresorerie.blade.php
git commit -m "feat(rapports): export dropdown on flux de trésorerie"
```

---

### Task 11: Export button on Analyse pivot

**Files:**
- Modify: `app/Livewire/AnalysePivot.php`
- Modify: `resources/views/livewire/analyse-pivot.blade.php`

- [ ] **Step 1: Add export URL method**

In `app/Livewire/AnalysePivot.php`, add before the `render()` method:

```php
    public function exportUrl(): string
    {
        $rapport = $this->mode === 'participants' ? 'analyse-participants' : 'analyse-financier';

        return route('compta.rapports.export', [
            'rapport' => $rapport,
            'format' => 'xlsx',
            'exercice' => $this->filterExercice,
        ]);
    }
```

- [ ] **Step 2: Add the export link in the Blade view**

In `resources/views/livewire/analyse-pivot.blade.php`, inside the header `<div class="d-flex gap-3 align-items-center">` (line 7), before the subtotal toggle (before line 6), add:

```html
            <a href="{{ $this->exportUrl() }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Exporter en Excel
            </a>
```

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/AnalysePivot.php resources/views/livewire/analyse-pivot.blade.php
git commit -m "feat(rapports): Excel export link on analyse pivot"
```

---

### Task 12: Full integration test + Pint

**Files:**
- Modify: `tests/Feature/RapportExportTest.php`

- [ ] **Step 1: Add integration tests for all export routes**

Append to `tests/Feature/RapportExportTest.php`:

```php
it('exports operations as xlsx with filters', function () {
    $this->get('/compta/rapports/export/operations/xlsx?ops[]=1&seances=1&tiers=1')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports operations as pdf', function () {
    $this->get('/compta/rapports/export/operations/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exports flux-tresorerie as xlsx', function () {
    $this->get('/compta/rapports/export/flux-tresorerie/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports flux-tresorerie as pdf', function () {
    $this->get('/compta/rapports/export/flux-tresorerie/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exports analyse-financier as xlsx', function () {
    $this->get('/compta/rapports/export/analyse-financier/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports analyse-participants as xlsx', function () {
    $this->get('/compta/rapports/export/analyse-participants/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
```

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/sail test tests/Feature/RapportExportTest.php`

Expected: All 12 tests pass.

- [ ] **Step 3: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/sail test`

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "test(rapports): full integration tests for all export routes + Pint"
```
