# Documents prévisionnels (Devis & Pro forma) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the ability to emit Devis and Pro forma documents from the règlement tab of an operation, per participant, with versioning and PDF/A-3 generation.

**Architecture:** New independent module (model `DocumentPrevisionnel`, service `DocumentPrevisionnelService`, PDF controller) that reads reglement data but does not modify the existing facturation system. Documents are snapshots stored in DB with JSON lines and archived PDF on disk.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, dompdf, atgp/factur-x (PDF/A-3), Bootstrap 5

**Spec:** `docs/superpowers/specs/2026-04-01-devis-proforma-design.md`

---

### Task 1: Enum & Migration

**Files:**
- Create: `app/Enums/TypeDocumentPrevisionnel.php`
- Create: `database/migrations/2026_04_01_100000_create_documents_previsionnels_table.php`

- [ ] **Step 1: Create the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeDocumentPrevisionnel: string
{
    case Devis = 'devis';
    case Proforma = 'proforma';

    public function label(): string
    {
        return match ($this) {
            self::Devis => 'Devis',
            self::Proforma => 'Pro forma',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Devis => 'D',
            self::Proforma => 'PF',
        };
    }
}
```

- [ ] **Step 2: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents_previsionnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('operations');
            $table->foreignId('participant_id')->constrained('participants');
            $table->string('type');
            $table->string('numero')->unique();
            $table->unsignedInteger('version');
            $table->date('date');
            $table->decimal('montant_total', 10, 2);
            $table->json('lignes_json');
            $table->string('pdf_path')->nullable();
            $table->foreignId('saisi_par')->constrained('users');
            $table->integer('exercice');
            $table->timestamps();

            $table->unique(['operation_id', 'participant_id', 'type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_previsionnels');
    }
};
```

- [ ] **Step 3: Run the migration**

Run: `./vendor/bin/sail artisan migrate`
Expected: Migration successful, table `documents_previsionnels` created.

- [ ] **Step 4: Commit**

```bash
git add app/Enums/TypeDocumentPrevisionnel.php database/migrations/2026_04_01_100000_create_documents_previsionnels_table.php
git commit -m "feat(devis): add TypeDocumentPrevisionnel enum and migration"
```

---

### Task 2: Model `DocumentPrevisionnel`

**Files:**
- Create: `app/Models/DocumentPrevisionnel.php`

- [ ] **Step 1: Create the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeDocumentPrevisionnel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DocumentPrevisionnel extends Model
{
    protected $table = 'documents_previsionnels';

    protected $fillable = [
        'operation_id',
        'participant_id',
        'type',
        'numero',
        'version',
        'date',
        'montant_total',
        'lignes_json',
        'pdf_path',
        'saisi_par',
        'exercice',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeDocumentPrevisionnel::class,
            'date' => 'date',
            'montant_total' => 'decimal:2',
            'lignes_json' => 'array',
            'version' => 'integer',
            'exercice' => 'integer',
            'operation_id' => 'integer',
            'participant_id' => 'integer',
            'saisi_par' => 'integer',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }
}
```

- [ ] **Step 2: Add relationship on Participant model**

In `app/Models/Participant.php`, add the `documentsPrevisionnels` relationship after the existing `emailLogs` relationship:

```php
public function documentsPrevisionnels(): HasMany
{
    return $this->hasMany(DocumentPrevisionnel::class);
}
```

Also add the import at the top of the file:

```php
use App\Models\DocumentPrevisionnel;
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/DocumentPrevisionnel.php app/Models/Participant.php
git commit -m "feat(devis): add DocumentPrevisionnel model with relationships"
```

---

### Task 3: Service — `emettre()` method with tests (TDD)

**Files:**
- Create: `tests/Feature/Services/DocumentPrevisionnelServiceTest.php`
- Create: `app/Services/DocumentPrevisionnelService.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\Association;
use App\Models\DocumentPrevisionnel;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\DocumentPrevisionnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(DocumentPrevisionnelService::class);

    Association::create(['nom' => 'Test Asso', 'siret' => '123 456 789 00012']);
    Exercice::create([
        'annee' => 2025,
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
        'statut' => 'ouvert',
    ]);
});

function createOperationWithReglements(int $nbSeances = 3, float $montant = 50.00): array
{
    $typeOp = TypeOperation::factory()->create();
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-01-15',
    ]);

    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => '2025-09-15',
    ]);

    $seances = [];
    for ($i = 1; $i <= $nbSeances; $i++) {
        $seance = Seance::create([
            'operation_id' => $operation->id,
            'numero' => $i,
            'date' => "2025-10-" . str_pad((string) ($i * 7), 2, '0', STR_PAD_LEFT),
            'titre' => "Séance $i",
        ]);
        $seances[] = $seance;

        Reglement::create([
            'participant_id' => $participant->id,
            'seance_id' => $seance->id,
            'montant_prevu' => $montant,
        ]);
    }

    return [$operation, $participant, $seances];
}

describe('emettre()', function () {
    it('creates a devis with one aggregated line', function () {
        [$operation, $participant] = createOperationWithReglements(3, 50.00);

        $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        expect($doc)->toBeInstanceOf(DocumentPrevisionnel::class)
            ->and($doc->type)->toBe(TypeDocumentPrevisionnel::Devis)
            ->and($doc->version)->toBe(1)
            ->and((float) $doc->montant_total)->toBe(150.00)
            ->and($doc->numero)->toStartWith('D-2025-')
            ->and($doc->exercice)->toBe(2025)
            ->and($doc->saisi_par)->toBe($this->user->id);

        $lignes = $doc->lignes_json;
        expect($lignes)->toHaveCount(2); // 1 header texte + 1 montant
        expect($lignes[0]['type'])->toBe('texte');
        expect($lignes[1]['type'])->toBe('montant');
        expect((float) $lignes[1]['montant'])->toBe(150.00);
    });

    it('creates a proforma with one line per seance', function () {
        [$operation, $participant] = createOperationWithReglements(3, 50.00);

        $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Proforma);

        expect($doc->type)->toBe(TypeDocumentPrevisionnel::Proforma)
            ->and($doc->numero)->toStartWith('PF-2025-')
            ->and((float) $doc->montant_total)->toBe(150.00);

        $lignes = $doc->lignes_json;
        expect($lignes)->toHaveCount(4); // 1 header texte + 3 montant lines
        expect($lignes[0]['type'])->toBe('texte');
        expect($lignes[1]['type'])->toBe('montant');
        expect($lignes[2]['type'])->toBe('montant');
        expect($lignes[3]['type'])->toBe('montant');
    });

    it('increments version for same operation/participant/type', function () {
        [$operation, $participant] = createOperationWithReglements(2, 30.00);

        $doc1 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
        $doc2 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        expect($doc1->version)->toBe(1);
        expect($doc2->version)->toBe(2);
    });

    it('maintains separate numbering for devis and proforma', function () {
        [$operation, $participant] = createOperationWithReglements(2, 30.00);

        $devis = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
        $proforma = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Proforma);

        expect($devis->numero)->toStartWith('D-2025-');
        expect($proforma->numero)->toStartWith('PF-2025-');
    });

    it('returns existing document if amounts unchanged', function () {
        [$operation, $participant] = createOperationWithReglements(2, 30.00);

        $doc1 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
        $doc2 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        // Same amounts → should return existing, not create new version
        expect($doc2->id)->toBe($doc1->id);
        expect(DocumentPrevisionnel::count())->toBe(1);
    });

    it('creates new version when amounts change', function () {
        [$operation, $participant, $seances] = createOperationWithReglements(2, 30.00);

        $doc1 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        // Change a reglement amount
        Reglement::where('participant_id', $participant->id)
            ->where('seance_id', $seances[0]->id)
            ->update(['montant_prevu' => 50.00]);

        $doc2 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        expect($doc2->id)->not->toBe($doc1->id);
        expect($doc2->version)->toBe(2);
        expect((float) $doc2->montant_total)->toBe(80.00);
    });

    it('uses singular seance when only one', function () {
        [$operation, $participant] = createOperationWithReglements(1, 50.00);

        $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        $headerLine = $doc->lignes_json[0];
        expect($headerLine['libelle'])->toContain('1 séance :');
        expect($headerLine['libelle'])->not->toContain('séances');
    });

    it('throws when exercice is closed', function () {
        Exercice::first()->update(['statut' => 'cloture']);

        [$operation, $participant] = createOperationWithReglements(2, 30.00);

        $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
    })->throws(\App\Exceptions\ExerciceCloturedException::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Services/DocumentPrevisionnelServiceTest.php`
Expected: All tests FAIL (service class doesn't exist yet).

- [ ] **Step 3: Write the service implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\DocumentPrevisionnel;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class DocumentPrevisionnelService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    public function emettre(
        Operation $operation,
        Participant $participant,
        TypeDocumentPrevisionnel $type,
    ): DocumentPrevisionnel {
        $exercice = $this->exerciceService->current();
        $this->exerciceService->assertOuvert($exercice);

        $seances = Seance::where('operation_id', $operation->id)
            ->orderBy('numero')
            ->get();

        $reglements = Reglement::where('participant_id', $participant->id)
            ->whereIn('seance_id', $seances->pluck('id'))
            ->get()
            ->keyBy('seance_id');

        $lignes = $this->buildLignes($operation, $seances, $reglements, $type);

        $montantTotal = collect($lignes)
            ->where('type', 'montant')
            ->sum('montant');

        // Check if last version has same amounts → return existing
        $lastVersion = DocumentPrevisionnel::where('operation_id', $operation->id)
            ->where('participant_id', $participant->id)
            ->where('type', $type)
            ->orderByDesc('version')
            ->first();

        if ($lastVersion !== null && (float) $lastVersion->montant_total === (float) $montantTotal) {
            // Compare line-by-line to detect structural changes too
            $lastMontants = collect($lastVersion->lignes_json)
                ->where('type', 'montant')
                ->pluck('montant')
                ->map(fn ($m) => (float) $m)
                ->values()
                ->toArray();

            $newMontants = collect($lignes)
                ->where('type', 'montant')
                ->pluck('montant')
                ->map(fn ($m) => (float) $m)
                ->values()
                ->toArray();

            if ($lastMontants === $newMontants) {
                return $lastVersion;
            }
        }

        return DB::transaction(function () use ($operation, $participant, $type, $lignes, $montantTotal, $exercice): DocumentPrevisionnel {
            $version = (int) DocumentPrevisionnel::where('operation_id', $operation->id)
                ->where('participant_id', $participant->id)
                ->where('type', $type)
                ->max('version') + 1;

            $seq = (int) DocumentPrevisionnel::where('type', $type)
                ->where('exercice', $exercice->annee)
                ->max('version'); // count by type+exercice

            // Better: count all documents of this type for this exercice
            $seq = DocumentPrevisionnel::where('type', $type)
                ->where('exercice', $exercice->annee)
                ->count() + 1;

            $numero = sprintf('%s-%d-%03d', $type->prefix(), $exercice->annee, $seq);

            return DocumentPrevisionnel::create([
                'operation_id' => $operation->id,
                'participant_id' => $participant->id,
                'type' => $type,
                'numero' => $numero,
                'version' => $version,
                'date' => now()->toDateString(),
                'montant_total' => $montantTotal,
                'lignes_json' => $lignes,
                'pdf_path' => null,
                'saisi_par' => Auth::id(),
                'exercice' => $exercice->annee,
            ]);
        });
    }

    /**
     * @return array<int, array{type: string, libelle: string, montant?: float, seance_id?: int}>
     */
    private function buildLignes(
        Operation $operation,
        \Illuminate\Database\Eloquent\Collection $seances,
        \Illuminate\Support\Collection $reglements,
        TypeDocumentPrevisionnel $type,
    ): array {
        $nbSeances = $seances->count();
        $firstDate = $seances->first()?->date;
        $lastDate = $seances->last()?->date;

        $seanceWord = $nbSeances === 1 ? 'séance' : 'séances';

        $headerLibelle = sprintf(
            '%s du %s au %s en %d %s :',
            $operation->nom,
            $firstDate ? $firstDate->format('d/m/Y') : '—',
            $lastDate ? $lastDate->format('d/m/Y') : '—',
            $nbSeances,
            $seanceWord,
        );

        $lignes = [
            ['type' => 'texte', 'libelle' => $headerLibelle],
        ];

        if ($type === TypeDocumentPrevisionnel::Devis) {
            $total = $reglements->sum('montant_prevu');

            $lignes[] = [
                'type' => 'montant',
                'libelle' => sprintf('%s — %d %s', $operation->nom, $nbSeances, $seanceWord),
                'montant' => (float) $total,
            ];
        } else {
            // Proforma: one line per séance
            foreach ($seances as $seance) {
                $reglement = $reglements->get($seance->id);
                $montant = $reglement ? (float) $reglement->montant_prevu : 0.0;

                $lignes[] = [
                    'type' => 'montant',
                    'libelle' => sprintf(
                        'Séance %d — %s',
                        $seance->numero,
                        $seance->date ? $seance->date->format('d/m/Y') : '—',
                    ),
                    'montant' => $montant,
                    'seance_id' => $seance->id,
                ];
            }
        }

        return $lignes;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Services/DocumentPrevisionnelServiceTest.php`
Expected: All 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DocumentPrevisionnelService.php tests/Feature/Services/DocumentPrevisionnelServiceTest.php
git commit -m "feat(devis): add DocumentPrevisionnelService with emettre() and tests"
```

---

### Task 4: PDF generation

**Files:**
- Create: `resources/views/pdf/document-previsionnel.blade.php`
- Modify: `app/Services/DocumentPrevisionnelService.php` (add `genererPdf` method)
- Create: `tests/Feature/DocumentPrevisionnelPdfTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\Association;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\DocumentPrevisionnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(DocumentPrevisionnelService::class);

    Association::create(['nom' => 'Test Asso', 'siret' => '123 456 789 00012']);
    Exercice::create([
        'annee' => 2025,
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
        'statut' => 'ouvert',
    ]);
});

function createDocPrevSetup(int $nbSeances = 2, float $montant = 50.00): array
{
    $typeOp = TypeOperation::factory()->create();
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => '2025-10-01',
        'date_fin' => '2025-12-15',
    ]);

    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => '2025-09-15',
    ]);

    for ($i = 1; $i <= $nbSeances; $i++) {
        $seance = Seance::create([
            'operation_id' => $operation->id,
            'numero' => $i,
            'date' => "2025-10-" . str_pad((string) ($i * 7), 2, '0', STR_PAD_LEFT),
            'titre' => "Séance $i",
        ]);

        Reglement::create([
            'participant_id' => $participant->id,
            'seance_id' => $seance->id,
            'montant_prevu' => $montant,
        ]);
    }

    return [$operation, $participant];
}

it('generates a PDF for a devis', function () {
    [$operation, $participant] = createDocPrevSetup();

    $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
    $pdfContent = $this->service->genererPdf($doc);

    expect($pdfContent)->toBeString()
        ->and(strlen($pdfContent))->toBeGreaterThan(100)
        ->and(str_starts_with($pdfContent, '%PDF'))->toBeTrue();
});

it('generates a PDF for a proforma', function () {
    [$operation, $participant] = createDocPrevSetup();

    $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Proforma);
    $pdfContent = $this->service->genererPdf($doc);

    expect($pdfContent)->toBeString()
        ->and(str_starts_with($pdfContent, '%PDF'))->toBeTrue();
});

it('stores the PDF on disk and updates pdf_path', function () {
    [$operation, $participant] = createDocPrevSetup();

    $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
    $this->service->genererPdf($doc);

    $doc->refresh();
    expect($doc->pdf_path)->not->toBeNull();
    expect(Storage::disk('local')->exists($doc->pdf_path))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/DocumentPrevisionnelPdfTest.php`
Expected: FAIL — `genererPdf` method doesn't exist yet.

- [ ] **Step 3: Create the PDF Blade template**

Create `resources/views/pdf/document-previsionnel.blade.php`:

```blade
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $document->type->label() }} {{ $document->numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #212529;
            line-height: 1.4;
            margin: 15mm;
        }
        table { width: 100%; border-collapse: collapse; }
        table.layout td { vertical-align: top; padding: 0; }

        .header { margin-bottom: 18px; }
        .header .logo { max-height: 60px; max-width: 120px; }
        .association-name { font-size: 14px; font-weight: bold; margin-bottom: 2px; }
        .association-subtitle { font-size: 10px; color: #6c757d; margin-bottom: 2px; }
        .association-address { font-size: 10px; color: #6c757d; }

        .title-block { margin-bottom: 16px; }
        .doc-title { font-size: 22px; font-weight: bold; color: #0d6efd; }
        .doc-info { font-size: 11px; margin-top: 4px; }
        .doc-info span { display: block; margin-bottom: 2px; }

        .client-block {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }
        .client-label {
            font-size: 9px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-weight: bold;
        }
        .client-name { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
        .client-address { font-size: 10px; color: #555; }

        .lines-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 10px;
        }
        .lines-table thead tr { background-color: #e9ecef; }
        .lines-table thead th {
            padding: 6px 8px;
            text-align: left;
            font-size: 10px;
            color: #212529;
            font-weight: bold;
            border-bottom: 2px solid #dee2e6;
        }
        .lines-table thead th.text-end { text-align: right; }
        .lines-table tbody td {
            padding: 5px 8px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
        }
        .lines-table tbody td.text-end { text-align: right; }
        .lines-table .row-even { background-color: #f9f9f9; }
        .lines-table .ligne-texte td {
            font-weight: bold;
            color: #333;
            padding-top: 8px;
        }
        .lines-table tfoot tr {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .lines-table tfoot td {
            padding: 6px 8px;
            border-top: 2px solid #dee2e6;
        }
        .lines-table tfoot td.text-end { text-align: right; }

        .footer-section {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #dee2e6;
            font-size: 9px;
            color: #6c757d;
        }
        .footer-section p { margin-bottom: 4px; }
    </style>
</head>
<body>

    {{-- HEADER --}}
    <div class="header">
        <table class="layout">
            <tr>
                <td style="width: 60%;">
                    @if ($headerLogoBase64)
                        <img src="data:{{ $headerLogoMime }};base64,{{ $headerLogoBase64 }}" class="logo" alt="Logo">
                    @endif
                    @if ($association)
                        <div class="association-name">{{ $association->nom }}</div>
                        @if ($association->forme_juridique)
                            <div class="association-subtitle">{{ $association->forme_juridique }}</div>
                        @endif
                        <div class="association-address">
                            @if ($association->adresse){{ $association->adresse }}<br>@endif
                            @if ($association->code_postal || $association->ville){{ $association->code_postal }} {{ $association->ville }}<br>@endif
                            @if ($association->email){{ $association->email }}@endif
                            @if ($association->email && $association->telephone) &mdash; @endif
                            @if ($association->telephone){{ $association->telephone }}@endif
                        </div>
                        @if ($association->siret)
                            <div class="association-address" style="margin-top: 2px;">SIRET : {{ $association->siret }}</div>
                        @endif
                    @endif
                </td>
                <td style="width: 40%; text-align: right;">
                    <div class="doc-title">{{ mb_strtoupper($document->type->label()) }}</div>
                    <div class="doc-info">
                        <span><strong>N&deg; :</strong> {{ $document->numero }}</span>
                        <span><strong>Date :</strong> {{ $document->date->format('d/m/Y') }}</span>
                        @if ($document->version > 1)
                            <span><strong>Version :</strong> {{ $document->version }}</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- CLIENT BLOCK --}}
    <div class="client-block">
        <div class="client-label">Destinataire</div>
        <div class="client-name">{{ $tiers->displayName() }}</div>
        <div class="client-address">
            @if ($tiers->adresse_ligne1){{ $tiers->adresse_ligne1 }}<br>@endif
            @if ($tiers->code_postal || $tiers->ville){{ $tiers->code_postal }} {{ $tiers->ville }}@endif
        </div>
    </div>

    {{-- LINES TABLE --}}
    @php $montantIndex = 0; @endphp
    <table class="lines-table">
        <thead>
            <tr>
                <th style="width: 75%;">D&eacute;signation</th>
                <th class="text-end" style="width: 25%;">Montant (&euro;)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->lignes_json as $ligne)
                @if ($ligne['type'] === 'texte')
                    <tr class="ligne-texte">
                        <td colspan="2">{{ $ligne['libelle'] }}</td>
                    </tr>
                @else
                    <tr class="{{ $montantIndex % 2 === 1 ? 'row-even' : '' }}">
                        <td>{{ $ligne['libelle'] }}</td>
                        <td class="text-end">{{ number_format((float) $ligne['montant'], 2, ',', "\u{00A0}") }} &euro;</td>
                    </tr>
                    @php $montantIndex++; @endphp
                @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="text-end">{{ number_format((float) $document->montant_total, 2, ',', "\u{00A0}") }} &euro;</td>
            </tr>
        </tfoot>
    </table>

    {{-- FOOTER --}}
    <div class="footer-section">
        <p>Ce document n'est pas une facture.</p>
    </div>

</body>
</html>
```

- [ ] **Step 4: Add `genererPdf()` method to the service**

Add these methods to `DocumentPrevisionnelService`:

```php
use App\Models\Association;
use Atgp\FacturX\Writer as FacturXWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

public function genererPdf(DocumentPrevisionnel $document): string
{
    $document->load('participant.tiers', 'operation');

    $association = Association::first();
    $tiers = $document->participant->tiers;

    $headerLogoBase64 = null;
    $headerLogoMime = null;
    if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
        $logoContent = Storage::disk('public')->get($association->logo_path);
        if ($logoContent) {
            $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
            $headerLogoMime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';
            $headerLogoBase64 = base64_encode($logoContent);
        }
    }

    $pdf = Pdf::loadView('pdf.document-previsionnel', [
        'document' => $document,
        'association' => $association,
        'tiers' => $tiers,
        'headerLogoBase64' => $headerLogoBase64,
        'headerLogoMime' => $headerLogoMime,
    ])->setPaper('a4', 'portrait');

    $pdfContent = $pdf->output();

    // Convert to PDF/A-3 with metadata XML
    $xml = $this->genererMetadataXml($document, $association, $tiers);
    $writer = new FacturXWriter();
    $pdfA3Content = $writer->generate($pdfContent, $xml, 'minimum', false);

    // Store on disk
    $path = "documents-previsionnels/{$document->numero}.pdf";
    Storage::disk('local')->put($path, $pdfA3Content);
    $document->update(['pdf_path' => $path]);

    return $pdfA3Content;
}

private function genererMetadataXml(
    DocumentPrevisionnel $document,
    ?Association $association,
    \App\Models\Tiers $tiers,
): string {
    $typeLabel = htmlspecialchars($document->type->label(), ENT_XML1, 'UTF-8');
    $numero = htmlspecialchars($document->numero, ENT_XML1, 'UTF-8');
    $date = $document->date->format('Ymd');
    $montant = number_format((float) $document->montant_total, 2, '.', '');
    $sellerName = htmlspecialchars($association?->nom ?? '', ENT_XML1, 'UTF-8');
    $siret = htmlspecialchars($association?->siret ?? '', ENT_XML1, 'UTF-8');
    $buyerName = htmlspecialchars($tiers->displayName(), ENT_XML1, 'UTF-8');
    $operationName = htmlspecialchars($document->operation->nom, ENT_XML1, 'UTF-8');

    $siretBlock = '';
    if ($siret !== '') {
        $siretBlock = <<<XML
                <ram:SpecifiedLegalOrganization>
                    <ram:ID schemeID="0002">{$siret}</ram:ID>
                </ram:SpecifiedLegalOrganization>
XML;
    }

    // Use TypeCode 325 (Pro forma) instead of 380 (Invoice)
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:ExchangedDocumentContext>
        <ram:GuidelineSpecifiedDocumentContextParameter>
            <ram:ID>urn:factur-x.eu:1p0:minimum</ram:ID>
        </ram:GuidelineSpecifiedDocumentContextParameter>
    </rsm:ExchangedDocumentContext>
    <rsm:ExchangedDocument>
        <ram:ID>{$numero}</ram:ID>
        <ram:TypeCode>325</ram:TypeCode>
        <ram:IssueDateTime>
            <udt:DateTimeString format="102">{$date}</udt:DateTimeString>
        </ram:IssueDateTime>
    </rsm:ExchangedDocument>
    <rsm:SupplyChainTradeTransaction>
        <ram:ApplicableHeaderTradeAgreement>
            <ram:SellerTradeParty>
                <ram:Name>{$sellerName}</ram:Name>
                {$siretBlock}
            </ram:SellerTradeParty>
            <ram:BuyerTradeParty>
                <ram:Name>{$buyerName}</ram:Name>
            </ram:BuyerTradeParty>
        </ram:ApplicableHeaderTradeAgreement>
        <ram:ApplicableHeaderTradeDelivery/>
        <ram:ApplicableHeaderTradeSettlement>
            <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
            <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                <ram:TaxBasisTotalAmount>{$montant}</ram:TaxBasisTotalAmount>
                <ram:GrandTotalAmount>{$montant}</ram:GrandTotalAmount>
                <ram:DuePayableAmount>{$montant}</ram:DuePayableAmount>
            </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        </ram:ApplicableHeaderTradeSettlement>
    </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/DocumentPrevisionnelPdfTest.php`
Expected: All 3 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/DocumentPrevisionnelService.php resources/views/pdf/document-previsionnel.blade.php tests/Feature/DocumentPrevisionnelPdfTest.php
git commit -m "feat(devis): add PDF/A-3 generation for devis and proforma"
```

---

### Task 5: PDF Controller & Route

**Files:**
- Create: `app/Http/Controllers/DocumentPrevisionnelPdfController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DocumentPrevisionnel;
use App\Services\DocumentPrevisionnelService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class DocumentPrevisionnelPdfController extends Controller
{
    public function __invoke(
        DocumentPrevisionnel $document,
        DocumentPrevisionnelService $service,
    ): Response {
        // Serve stored PDF if available, otherwise generate
        if ($document->pdf_path && Storage::disk('local')->exists($document->pdf_path)) {
            $pdfContent = Storage::disk('local')->get($document->pdf_path);
        } else {
            $pdfContent = $service->genererPdf($document);
        }

        $document->load('participant.tiers');
        $label = $document->type->label();
        $filename = "{$label} {$document->numero} - {$document->participant->tiers->displayName()}.pdf";

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
```

- [ ] **Step 2: Add the route**

In `routes/web.php`, inside the `gestion` group (after the factures routes around line 152), add:

```php
// Documents prévisionnels (devis / pro forma)
Route::get('/documents-previsionnels/{document}/pdf', DocumentPrevisionnelPdfController::class)
    ->name('documents-previsionnels.pdf');
```

Also add the import at the top:

```php
use App\Http\Controllers\DocumentPrevisionnelPdfController;
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/DocumentPrevisionnelPdfController.php routes/web.php
git commit -m "feat(devis): add PDF controller and route for document previsionnel"
```

---

### Task 6: Integrate into ReglementTable (Livewire)

**Files:**
- Modify: `app/Livewire/ReglementTable.php`
- Modify: `resources/views/livewire/reglement-table.blade.php`

- [ ] **Step 1: Add emit methods to the Livewire component**

Add these methods and properties to `ReglementTable.php`:

After the existing `use` imports at the top, add:

```php
use App\Enums\TypeDocumentPrevisionnel;
use App\Models\DocumentPrevisionnel;
use App\Services\DocumentPrevisionnelService;
```

Add this method after `copierLigne()`:

```php
public function emettreDocument(int $participantId, string $type): mixed
{
    $participant = $this->operation->participants()->findOrFail($participantId);
    $typeEnum = TypeDocumentPrevisionnel::from($type);

    $service = app(DocumentPrevisionnelService::class);
    $document = $service->emettre($this->operation, $participant, $typeEnum);

    // Generate PDF if not already stored
    if (! $document->pdf_path) {
        $service->genererPdf($document);
    }

    return $this->redirect(
        route('gestion.documents-previsionnels.pdf', $document),
        navigate: false,
    );
}
```

In the `render()` method, after computing `$realiseMap`, add:

```php
// Load existing documents for badge display
$docVersions = DocumentPrevisionnel::where('operation_id', $this->operation->id)
    ->whereIn('participant_id', $participants->pluck('id'))
    ->select('participant_id', 'type', DB::raw('MAX(version) as last_version'))
    ->groupBy('participant_id', 'type')
    ->get()
    ->groupBy('participant_id')
    ->map(fn ($items) => $items->keyBy('type'));
```

Pass it to the view:

```php
return view('livewire.reglement-table', [
    'seances' => $seances,
    'participants' => $participants,
    'reglementMap' => $reglementMap,
    'realiseMap' => $realiseMap,
    'docVersions' => $docVersions,
]);
```

- [ ] **Step 2: Add buttons to the view**

In `reglement-table.blade.php`, find the participant name cell (the `<td rowspan="2"` around line 82). Replace it with:

```blade
<td rowspan="2" style="position:sticky;left:0;z-index:1;background:#fff;vertical-align:middle;padding:4px 6px;white-space:nowrap">
    <div style="font-size:11px;font-weight:500">{{ $participant->tiers->nom }} {{ $participant->tiers->prenom }}</div>
    <div class="d-flex gap-1 mt-1">
        @php
            $pVersions = $docVersions[$participant->id] ?? collect();
            $devisV = $pVersions->get('devis')?->last_version;
            $proformaV = $pVersions->get('proforma')?->last_version;
        @endphp
        <button class="btn btn-outline-primary btn-sm py-0 px-1" style="font-size:9px;line-height:1.4"
                wire:click="emettreDocument({{ $participant->id }}, 'devis')"
                wire:loading.attr="disabled"
                title="Émettre un devis">
            <i class="bi bi-file-earmark-text"></i> Devis
            @if($devisV) <span class="badge bg-primary" style="font-size:8px">v{{ $devisV }}</span> @endif
        </button>
        <button class="btn btn-outline-secondary btn-sm py-0 px-1" style="font-size:9px;line-height:1.4"
                wire:click="emettreDocument({{ $participant->id }}, 'proforma')"
                wire:loading.attr="disabled"
                title="Émettre une pro forma">
            <i class="bi bi-file-earmark-ruled"></i> PF
            @if($proformaV) <span class="badge bg-secondary" style="font-size:8px">v{{ $proformaV }}</span> @endif
        </button>
    </div>
</td>
```

- [ ] **Step 3: Run full test suite to check for regressions**

Run: `./vendor/bin/sail test`
Expected: All tests pass, no regressions.

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/ReglementTable.php resources/views/livewire/reglement-table.blade.php
git commit -m "feat(devis): add devis/proforma buttons on reglement table"
```

---

### Task 7: Integrate into ParticipantShow timeline

**Files:**
- Modify: `app/Livewire/ParticipantShow.php`
- Modify: `resources/views/livewire/participant-show.blade.php`

- [ ] **Step 1: Add documents to the timeline in `ParticipantShow.php`**

In the `render()` method, after the formulaire token timeline entries (around line 392, before `$timeline = $timeline->sortByDesc('date')`), add:

```php
// Documents prévisionnels (devis / pro forma)
$documents = DocumentPrevisionnel::where('participant_id', $this->participant->id)
    ->with('operation')
    ->orderByDesc('created_at')
    ->get();

foreach ($documents as $doc) {
    $timeline->push([
        'date' => $doc->created_at,
        'type' => 'document_previsionnel',
        'categorie' => $doc->type->value,
        'icon' => $doc->type === \App\Enums\TypeDocumentPrevisionnel::Devis
            ? 'bi-file-earmark-text'
            : 'bi-file-earmark-ruled',
        'color' => 'info',
        'description' => sprintf(
            '%s %s (v%d) — %s — %s',
            $doc->type->label(),
            $doc->numero,
            $doc->version,
            $doc->operation->nom,
            number_format((float) $doc->montant_total, 2, ',', "\u{00A0}") . ' €',
        ),
        'detail' => null,
        'copyable' => null,
        'pdf_url' => route('gestion.documents-previsionnels.pdf', $doc),
    ]);
}
```

Also add the import at the top:

```php
use App\Models\DocumentPrevisionnel;
```

- [ ] **Step 2: Update the timeline view to render PDF links**

In `participant-show.blade.php`, in the timeline loop (around line 503-529), after the `<div class="fw-semibold small">` line, update the event rendering to support a PDF link:

Replace the description line:

```blade
<div class="fw-semibold small">{{ $event['description'] }}</div>
```

With:

```blade
<div class="fw-semibold small">
    {{ $event['description'] }}
    @if(isset($event['pdf_url']))
        <a href="{{ $event['pdf_url'] }}" target="_blank" class="ms-1 text-decoration-none" title="Ouvrir le PDF">
            <i class="bi bi-box-arrow-up-right" style="font-size:0.75rem"></i>
        </a>
    @endif
</div>
```

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/ParticipantShow.php resources/views/livewire/participant-show.blade.php
git commit -m "feat(devis): show documents in participant timeline with PDF link"
```

---

### Task 8: Code quality & final verification

**Files:**
- All modified files

- [ ] **Step 1: Run Pint (PSR-12 formatting)**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`
Expected: Files formatted, no errors.

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: All tests pass.

- [ ] **Step 3: Commit any formatting fixes**

```bash
git add -A
git commit -m "style: apply pint formatting"
```

- [ ] **Step 4: Manual verification checklist**

Open the browser at http://localhost and verify:
- [ ] Navigate to an operation with participants and séances
- [ ] Open the Règlement tab
- [ ] Click "Devis" on a participant row → PDF opens in new tab with "DEVIS" title
- [ ] Click "PF" on the same participant → PDF opens with "PRO FORMA" title and per-séance lines
- [ ] Check the version badge appears after emission
- [ ] Click "Devis" again without changing amounts → same PDF (no new version)
- [ ] Change a montant_prevu, click "Devis" again → new version (v2)
- [ ] Navigate to the participant's page → Historique tab shows the documents with PDF links
