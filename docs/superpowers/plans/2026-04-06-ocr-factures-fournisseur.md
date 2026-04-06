# OCR Factures Fournisseur — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Analyser automatiquement les factures fournisseur via Claude Vision pour pré-remplir les transactions dépense.

**Architecture:** `InvoiceOcrService` fait un appel HTTP synchrone à l'API Claude, retourne des DTOs. Intégré dans `TransactionForm` (split horizontal) et `AnimateurManager` (enrichit le step upload existant). Clé API stockée chiffrée dans la table `association`.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, API Anthropic Messages

**Spec:** `docs/superpowers/specs/2026-04-06-ocr-factures-fournisseur-design.md`

---

### Task 1: Migration — clé API sur association

**Files:**
- Create: `database/migrations/2026_04_06_100001_add_anthropic_api_key_to_association.php`
- Modify: `app/Models/Association.php`

- [ ] **Step 1: Créer la migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->text('anthropic_api_key')->nullable()->after('facture_compte_bancaire_id');
        });
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropColumn('anthropic_api_key');
        });
    }
};
```

Note: `text` au lieu de `string` car le cast `encrypted` produit une valeur chiffrée plus longue que 255 caractères.

- [ ] **Step 2: Modifier le modèle Association**

Dans `app/Models/Association.php`, ajouter `'anthropic_api_key'` au `$fillable` et ajouter le cast encrypted :

```php
protected $fillable = [
    'nom',
    'adresse',
    'code_postal',
    'ville',
    'email',
    'telephone',
    'logo_path',
    'cachet_signature_path',
    'siret',
    'forme_juridique',
    'facture_conditions_reglement',
    'facture_mentions_legales',
    'facture_mentions_penalites',
    'facture_compte_bancaire_id',
    'anthropic_api_key',
];

protected function casts(): array
{
    return [
        'id' => 'integer',
        'nom' => 'string',
        'adresse' => 'string',
        'code_postal' => 'string',
        'ville' => 'string',
        'email' => 'string',
        'telephone' => 'string',
        'logo_path' => 'string',
        'cachet_signature_path' => 'string',
        'facture_compte_bancaire_id' => 'integer',
        'anthropic_api_key' => 'encrypted',
    ];
}
```

- [ ] **Step 3: Lancer la migration**

Run: `./vendor/bin/sail artisan migrate`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_06_100001_add_anthropic_api_key_to_association.php app/Models/Association.php
git commit -m "feat: migration + cast encrypted pour clé API Anthropic"
```

---

### Task 2: Écran Paramètres — champ clé API

**Files:**
- Modify: `app/Livewire/Parametres/AssociationForm.php`
- Modify: `resources/views/livewire/parametres/association-form.blade.php`

- [ ] **Step 1: Modifier le composant Livewire**

Dans `app/Livewire/Parametres/AssociationForm.php` :

Ajouter la propriété :
```php
public ?string $anthropic_api_key = null;
```

Dans `mount()`, ajouter après la ligne `$this->facture_compte_bancaire_id = ...` :
```php
$this->anthropic_api_key = $association->anthropic_api_key;
```

Dans `save()`, ajouter la validation :
```php
'anthropic_api_key' => ['nullable', 'string', 'max:255'],
```

Dans `save()`, ajouter au tableau `$data` :
```php
'anthropic_api_key' => $this->anthropic_api_key ?: null,
```

- [ ] **Step 2: Modifier la vue Blade**

Dans `resources/views/livewire/parametres/association-form.blade.php`, ajouter un nouvel onglet "OCR / IA" après l'onglet "Facturation" dans les `<ul class="nav nav-tabs">` :

```blade
<li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-ocr" data-bs-toggle="tab" data-bs-target="#pane-ocr"
            type="button" role="tab" aria-controls="pane-ocr" aria-selected="false">
        <i class="bi bi-robot"></i> OCR / IA
    </button>
</li>
```

Ajouter le panneau correspondant après le panneau Facturation, avant la fermeture du `</div>` du `tab-content` :

```blade
{{-- Onglet OCR / IA --}}
<div class="tab-pane fade" id="pane-ocr" role="tabpanel" aria-labelledby="tab-ocr">
    <div class="card" style="max-width: 640px;">
        <div class="card-body">
            <p class="text-muted small mb-3">
                Renseignez une clé API Anthropic pour activer l'analyse automatique des factures fournisseur.
                L'analyse utilise Claude Vision pour extraire la date, le tiers, les lignes et montants.
            </p>

            <div class="mb-4">
                <label class="form-label">Clé API Anthropic</label>
                <input type="password" class="form-control @error('anthropic_api_key') is-invalid @enderror"
                       wire:model="anthropic_api_key"
                       placeholder="sk-ant-api03-...">
                @error('anthropic_api_key') <div class="invalid-feedback">{{ $message }}</div> @enderror
                @if($anthropic_api_key)
                    <div class="form-text text-success"><i class="bi bi-check-circle"></i> Clé configurée — OCR actif</div>
                @else
                    <div class="form-text text-muted">OCR désactivé — aucune clé configurée</div>
                @endif
            </div>

            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
                <span wire:loading>Enregistrement…</span>
            </button>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Vérifier dans le navigateur**

Aller sur Paramètres > Association > onglet OCR / IA, saisir la clé API, enregistrer, recharger → la clé est conservée.

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/Parametres/AssociationForm.php resources/views/livewire/parametres/association-form.blade.php
git commit -m "feat: onglet OCR/IA dans paramètres association — clé API Anthropic"
```

---

### Task 3: DTOs + InvoiceOcrService

**Files:**
- Create: `app/DTOs/InvoiceOcrResult.php`
- Create: `app/DTOs/InvoiceOcrLigne.php`
- Create: `app/Services/InvoiceOcrService.php`
- Create: `app/Exceptions/OcrNotConfiguredException.php`
- Create: `app/Exceptions/OcrAnalysisException.php`
- Test: `tests/Feature/InvoiceOcrServiceTest.php`

- [ ] **Step 1: Créer les DTOs**

`app/DTOs/InvoiceOcrResult.php` :
```php
<?php

declare(strict_types=1);

namespace App\DTOs;

final class InvoiceOcrResult
{
    /**
     * @param  array<InvoiceOcrLigne>  $lignes
     * @param  array<string>  $warnings
     */
    public function __construct(
        public readonly ?string $date,
        public readonly ?string $reference,
        public readonly ?int $tiers_id,
        public readonly ?string $tiers_nom,
        public readonly ?float $montant_total,
        public readonly array $lignes,
        public readonly array $warnings = [],
    ) {}
}
```

`app/DTOs/InvoiceOcrLigne.php` :
```php
<?php

declare(strict_types=1);

namespace App\DTOs;

final class InvoiceOcrLigne
{
    public function __construct(
        public readonly ?string $description,
        public readonly ?int $sous_categorie_id,
        public readonly ?int $operation_id,
        public readonly ?int $seance,
        public readonly float $montant,
    ) {}
}
```

- [ ] **Step 2: Créer les exceptions**

`app/Exceptions/OcrNotConfiguredException.php` :
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

final class OcrNotConfiguredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Clé API Anthropic non configurée. Allez dans Paramètres > Association > OCR / IA.');
    }
}
```

`app/Exceptions/OcrAnalysisException.php` :
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

final class OcrAnalysisException extends \RuntimeException {}
```

- [ ] **Step 3: Créer InvoiceOcrService**

`app/Services/InvoiceOcrService.php` :
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\InvoiceOcrLigne;
use App\DTOs\InvoiceOcrResult;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Models\Association;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

final class InvoiceOcrService
{
    private const MODEL = 'claude-sonnet-4-20250514';

    public static function isConfigured(): bool
    {
        return Association::first()?->anthropic_api_key !== null;
    }

    /**
     * @param  array{tiers_attendu?: string, operation_attendue?: string, seance_attendue?: int}|null  $context
     */
    public function analyze(UploadedFile $file, ?array $context = null): InvoiceOcrResult
    {
        $apiKey = Association::first()?->anthropic_api_key;
        if ($apiKey === null) {
            throw new OcrNotConfiguredException;
        }

        $base64 = base64_encode(file_get_contents($file->getRealPath()));
        $mime = $file->getMimeType();
        $prompt = $this->buildPrompt($context);

        $mediaType = $mime === 'application/pdf' ? 'application/pdf' : $mime;
        $sourceType = $mime === 'application/pdf' ? 'document' : 'image';

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => self::MODEL,
            'max_tokens' => 1024,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => $sourceType,
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mediaType,
                            'data' => $base64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ]],
        ]);

        if ($response->failed()) {
            throw new OcrAnalysisException('Erreur API Anthropic : ' . $response->status() . ' — ' . $response->body());
        }

        $text = $response->json('content.0.text', '');
        // Strip markdown code blocks if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new OcrAnalysisException('Réponse IA non exploitable : JSON invalide.');
        }

        return $this->parseResult($data);
    }

    private function buildPrompt(?array $context): string
    {
        $tiers = Tiers::orderBy('nom')->get()
            ->map(fn (Tiers $t) => $t->id . ': ' . $t->displayName())
            ->implode("\n");

        $sousCategories = SousCategorie::with('categorie')
            ->whereHas('categorie', fn ($q) => $q->where('type', 'depense'))
            ->orderBy('nom')
            ->get()
            ->map(fn (SousCategorie $s) => $s->id . ': ' . $s->nom . ' (' . $s->categorie->nom . ')')
            ->implode("\n");

        $exercice = app(ExerciceService::class)->current();
        $operations = Operation::with('typeOperation')
            ->forExercice($exercice)
            ->orderBy('nom')
            ->get()
            ->map(fn (Operation $o) => $o->id . ': ' . $o->nom . ' (type: ' . ($o->typeOperation?->nom ?? '-') . ', séances: ' . $o->nombre_seances . ')')
            ->implode("\n");

        $prompt = <<<PROMPT
Tu es un assistant d'extraction de factures fournisseur pour une association.

Extrais les informations de cette facture au format JSON suivant :

{"date": "YYYY-MM-DD", "reference": "numéro de facture", "tiers_id": null, "tiers_nom": "nom fournisseur", "montant_total": 0.00, "lignes": [{"description": "...", "sous_categorie_id": null, "operation_id": null, "seance": null, "montant": 0.00}], "warnings": []}

Règles :
- Respecte les lignes telles qu'elles apparaissent sur la facture. Si la facture indique quantité 2 à 70€ pour un montant de 140€, c'est UNE SEULE ligne à 140€. Ne ventile jamais.
- Pour tiers_id, cherche le tiers le plus proche dans la liste ci-dessous. Si aucun ne correspond, mets null.
- Pour sous_categorie_id, choisis la sous-catégorie la plus pertinente. Si aucune ne correspond, mets null.
- Pour operation_id, cherche l'opération la plus proche si la facture mentionne une activité. Sinon mets null.
- Pour seance, extrais le numéro de séance si identifiable dans la description. Sinon mets null.

TIERS EXISTANTS :
{$tiers}

SOUS-CATEGORIES DEPENSE :
{$sousCategories}

OPERATIONS EN COURS :
{$operations}
PROMPT;

        if ($context !== null) {
            $ctxParts = [];
            if (isset($context['tiers_attendu'])) {
                $ctxParts[] = "Tiers attendu : {$context['tiers_attendu']}";
            }
            if (isset($context['operation_attendue'])) {
                $ctxParts[] = "Opération attendue : {$context['operation_attendue']}";
            }
            if (isset($context['seance_attendue'])) {
                $ctxParts[] = "Séance attendue : {$context['seance_attendue']}";
            }
            $ctxStr = implode("\n", $ctxParts);

            $prompt .= <<<PROMPT


CONTEXTE ENCADRANT (valeurs attendues) :
{$ctxStr}

Compare les informations extraites de la facture avec ce contexte. Si une valeur ne correspond pas, ajoute un warning dans le champ "warnings". Exemples :
- "Le tiers sur la facture (X) ne correspond pas au tiers sélectionné (Y)"
- "L'opération détectée (X) ne correspond pas à l'opération sélectionnée (Y)"
- "La séance détectée (N) ne correspond pas à la séance sélectionnée (M)"
PROMPT;
        }

        $prompt .= "\n\nRéponds UNIQUEMENT avec le JSON, sans commentaire ni bloc markdown.";

        return $prompt;
    }

    private function parseResult(array $data): InvoiceOcrResult
    {
        $lignes = [];
        foreach ($data['lignes'] ?? [] as $l) {
            $lignes[] = new InvoiceOcrLigne(
                description: $l['description'] ?? null,
                sous_categorie_id: isset($l['sous_categorie_id']) ? (int) $l['sous_categorie_id'] : null,
                operation_id: isset($l['operation_id']) && $l['operation_id'] !== null ? (int) $l['operation_id'] : null,
                seance: isset($l['seance']) && $l['seance'] !== null ? (int) $l['seance'] : null,
                montant: (float) ($l['montant'] ?? 0),
            );
        }

        return new InvoiceOcrResult(
            date: $data['date'] ?? null,
            reference: $data['reference'] ?? null,
            tiers_id: isset($data['tiers_id']) && $data['tiers_id'] !== null ? (int) $data['tiers_id'] : null,
            tiers_nom: $data['tiers_nom'] ?? null,
            montant_total: isset($data['montant_total']) ? (float) $data['montant_total'] : null,
            lignes: $lignes,
            warnings: $data['warnings'] ?? [],
        );
    }
}
```

- [ ] **Step 4: Écrire les tests**

`tests/Feature/InvoiceOcrServiceTest.php` :
```php
<?php

declare(strict_types=1);

use App\DTOs\InvoiceOcrLigne;
use App\DTOs\InvoiceOcrResult;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\InvoiceOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('isConfigured retourne false sans clé API', function () {
    Association::create(['id' => 1, 'nom' => 'Test']);
    expect(InvoiceOcrService::isConfigured())->toBeFalse();
});

it('isConfigured retourne true avec clé API', function () {
    $asso = Association::create(['id' => 1, 'nom' => 'Test']);
    $asso->update(['anthropic_api_key' => 'sk-test-key']);
    expect(InvoiceOcrService::isConfigured())->toBeTrue();
});

it('analyze lance OcrNotConfiguredException sans clé', function () {
    Association::create(['id' => 1, 'nom' => 'Test']);
    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    app(InvoiceOcrService::class)->analyze($file);
})->throws(OcrNotConfiguredException::class);

it('analyze parse correctement la réponse API', function () {
    $asso = Association::create(['id' => 1, 'nom' => 'Test']);
    $asso->update(['anthropic_api_key' => 'sk-test-key']);
    SousCategorie::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'date' => '2025-11-22',
                    'reference' => '106',
                    'tiers_id' => null,
                    'tiers_nom' => 'Anne KURZ',
                    'montant_total' => 390.00,
                    'lignes' => [
                        ['description' => 'Séance 4', 'sous_categorie_id' => 1, 'operation_id' => null, 'seance' => 4, 'montant' => 250.00],
                        ['description' => 'Suivi', 'sous_categorie_id' => 1, 'operation_id' => null, 'seance' => null, 'montant' => 140.00],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    $result = app(InvoiceOcrService::class)->analyze($file);

    expect($result)->toBeInstanceOf(InvoiceOcrResult::class)
        ->and($result->date)->toBe('2025-11-22')
        ->and($result->reference)->toBe('106')
        ->and($result->tiers_nom)->toBe('Anne KURZ')
        ->and($result->montant_total)->toBe(390.00)
        ->and($result->lignes)->toHaveCount(2)
        ->and($result->lignes[0])->toBeInstanceOf(InvoiceOcrLigne::class)
        ->and($result->lignes[0]->montant)->toBe(250.00)
        ->and($result->lignes[0]->seance)->toBe(4);
});

it('analyze gère les warnings de cohérence', function () {
    $asso = Association::create(['id' => 1, 'nom' => 'Test']);
    $asso->update(['anthropic_api_key' => 'sk-test-key']);
    SousCategorie::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'date' => '2025-11-22',
                    'reference' => '106',
                    'tiers_id' => null,
                    'tiers_nom' => 'Anne KURZ',
                    'montant_total' => 390.00,
                    'lignes' => [],
                    'warnings' => ['Le tiers sur la facture (Anne KURZ) ne correspond pas au tiers sélectionné (Jürgen KURZ)'],
                ]),
            ]],
        ]),
    ]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    $result = app(InvoiceOcrService::class)->analyze($file, [
        'tiers_attendu' => 'Jürgen KURZ',
    ]);

    expect($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('Anne KURZ');
});

it('analyze lance OcrAnalysisException si API échoue', function () {
    $asso = Association::create(['id' => 1, 'nom' => 'Test']);
    $asso->update(['anthropic_api_key' => 'sk-test-key']);
    SousCategorie::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response('Internal Server Error', 500),
    ]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    app(InvoiceOcrService::class)->analyze($file);
})->throws(OcrAnalysisException::class);

it('analyze lance OcrAnalysisException si JSON invalide', function () {
    $asso = Association::create(['id' => 1, 'nom' => 'Test']);
    $asso->update(['anthropic_api_key' => 'sk-test-key']);
    SousCategorie::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ceci nest pas du json']],
        ]),
    ]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    app(InvoiceOcrService::class)->analyze($file);
})->throws(OcrAnalysisException::class);
```

- [ ] **Step 5: Lancer les tests**

Run: `./vendor/bin/sail test tests/Feature/InvoiceOcrServiceTest.php`
Expected: tous les tests PASS.

- [ ] **Step 6: Pint + Commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint
git add app/DTOs/ app/Services/InvoiceOcrService.php app/Exceptions/OcrNotConfiguredException.php app/Exceptions/OcrAnalysisException.php tests/Feature/InvoiceOcrServiceTest.php
git commit -m "feat: InvoiceOcrService + DTOs + tests"
```

---

### Task 4: TransactionForm — workflow OCR depuis facture (espace comptable)

**Files:**
- Modify: `app/Livewire/TransactionForm.php`
- Modify: `resources/views/livewire/transaction-form.blade.php`

- [ ] **Step 1: Modifier le composant Livewire**

Dans `app/Livewire/TransactionForm.php`, ajouter les imports :
```php
use App\Services\InvoiceOcrService;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
```

Ajouter les propriétés :
```php
public bool $ocrMode = false;

public bool $ocrAnalyzing = false;

public ?string $ocrError = null;

/** @var array<string> */
public array $ocrWarnings = [];
```

Ajouter la méthode pour ouvrir le formulaire en mode OCR (appelée après que le fichier est sélectionné et uploadé via Livewire) :

```php
#[On('open-transaction-form-ocr')]
public function openFormOcr(): void
{
    $this->showNewForm('depense');
    $this->ocrMode = true;
    $this->ocrWarnings = [];
    $this->ocrError = null;
}
```

Modifier `updatedPieceJointe()` ou ajouter cette méthode — quand une PJ est uploadée ET qu'on est en mode OCR, lancer l'analyse :

```php
public function updatedPieceJointe(): void
{
    if ($this->pieceJointe === null || ! $this->ocrMode) {
        return;
    }

    if (! InvoiceOcrService::isConfigured()) {
        return;
    }

    $this->ocrAnalyzing = true;
    $this->ocrError = null;

    try {
        $this->validate([
            'pieceJointe' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $result = app(InvoiceOcrService::class)->analyze($this->pieceJointe);
        $this->applyOcrResult($result);
    } catch (OcrAnalysisException $e) {
        $this->ocrError = $e->getMessage();
    } catch (OcrNotConfiguredException $e) {
        $this->ocrError = $e->getMessage();
    } catch (\Throwable $e) {
        $this->ocrError = 'Erreur inattendue : ' . $e->getMessage();
    } finally {
        $this->ocrAnalyzing = false;
    }
}

public function retryOcr(): void
{
    if ($this->pieceJointe !== null) {
        $this->updatedPieceJointe();
    }
}

private function applyOcrResult(\App\DTOs\InvoiceOcrResult $result): void
{
    if ($result->date !== null) {
        $this->date = $result->date;
    }
    if ($result->reference !== null) {
        $this->reference = $result->reference;
    }
    if ($result->tiers_id !== null) {
        $this->tiers_id = $result->tiers_id;
    }

    if (! empty($result->lignes)) {
        $this->lignes = [];
        foreach ($result->lignes as $ligne) {
            $this->lignes[] = [
                'id' => null,
                'sous_categorie_id' => $ligne->sous_categorie_id !== null ? (string) $ligne->sous_categorie_id : '',
                'operation_id' => $ligne->operation_id !== null ? (string) $ligne->operation_id : '',
                'seance' => $ligne->seance !== null ? (string) $ligne->seance : '',
                'montant' => (string) $ligne->montant,
                'notes' => $ligne->description ?? '',
            ];
        }
    }

    $this->ocrWarnings = $result->warnings;
}
```

Dans `showNewForm()`, ajouter au reset : `'ocrMode', 'ocrAnalyzing', 'ocrError', 'ocrWarnings'`.
Dans `resetForm()`, ajouter au reset : `'ocrMode', 'ocrAnalyzing', 'ocrError', 'ocrWarnings'`.

- [ ] **Step 2: Modifier la vue Blade**

Dans `resources/views/livewire/transaction-form.blade.php` :

**Spinner OCR** — ajouter après l'ouverture du `<div class="card-body">` et avant le badge type :
```blade
@if ($ocrAnalyzing)
    <div class="alert alert-info py-2 d-flex align-items-center gap-2">
        <div class="spinner-border spinner-border-sm"></div>
        Analyse de la facture en cours...
    </div>
@endif

@if ($ocrError)
    <div class="alert alert-danger py-2">
        <i class="bi bi-exclamation-triangle"></i> {{ $ocrError }}
        <div class="mt-2">
            <button type="button" wire:click="retryOcr" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-arrow-clockwise"></i> Réessayer
            </button>
            <button type="button" wire:click="$set('ocrError', null)" class="btn btn-sm btn-outline-secondary">
                Ignorer
            </button>
        </div>
    </div>
@endif

@if (! empty($ocrWarnings))
    <div class="alert alert-warning py-2 small">
        @foreach ($ocrWarnings as $warning)
            <div><i class="bi bi-exclamation-triangle"></i> {{ $warning }}</div>
        @endforeach
    </div>
@endif
```

**Split horizontal** — en mode OCR avec PJ, ajouter après la fermeture du `</form>` et avant la fermeture du `</div>` card-body :
```blade
@if ($ocrMode && $pieceJointe)
    <hr class="my-3">
    <div style="height:40vh" x-data="{ pUrl: sessionStorage.getItem('pj-ocr-preview-url') }">
        <template x-if="pUrl">
            <iframe :src="pUrl + '#navpanes=0'" style="width:100%;height:100%;border:1px solid #dee2e6;border-radius:4px"></iframe>
        </template>
    </div>
@endif
```

**Champ justificatif sur la ligne Montant total** — quand `$ocrMode` est true, déplacer le champ justificatif à côté du montant total. C'est une adaptation du layout existant. Dans le `<div class="col-12">` contenant le justificatif, ajouter une condition pour le mode OCR qui le place en ligne avec le montant total au lieu d'être sur sa propre ligne.

Plus simple : quand `$ocrMode` est true, le bloc justificatif col-12 existant devient un col-md-6 et le montant total passe en col-md-4 + justificatif en col-md-2 sur la même rangée. L'implémenteur devra ajuster le layout de la zone montant+justificatif.

**File picker JS** — le bouton dans TransactionUniverselle dispatche un event Livewire. Le `@change` JS du file input doit stocker le blob URL dans `sessionStorage` sous `pj-ocr-preview-url` pour la prévisualisation.

- [ ] **Step 3: Pint + Commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint
git add app/Livewire/TransactionForm.php resources/views/livewire/transaction-form.blade.php
git commit -m "feat: TransactionForm — mode OCR avec split horizontal et pré-remplissage"
```

---

### Task 5: Bouton "Nouvelle dépense depuis facture" dans TransactionUniverselle

**Files:**
- Modify: `resources/views/livewire/transaction-universelle.blade.php`

- [ ] **Step 1: Ajouter le bouton**

Dans `resources/views/livewire/transaction-universelle.blade.php`, dans le bloc dropdown "Nouvelle transaction" (il y en a deux instances — layout compact et layout standard), après l'item "Dépense", ajouter :

```blade
@if(in_array('depense', $availableTypes) && \App\Services\InvoiceOcrService::isConfigured())
    <li><a class="dropdown-item" href="#" x-data
        @click.prevent="
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = '.pdf,.jpg,.jpeg,.png';
            input.onchange = (e) => {
                const file = e.target.files[0];
                if (file) {
                    sessionStorage.setItem('pj-ocr-preview-url', URL.createObjectURL(file));
                    $dispatch('open-transaction-form-ocr');
                    // Transfer file to Livewire via hidden input
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    const hiddenInput = document.querySelector('#ocr-file-input');
                    if (hiddenInput) { hiddenInput.files = dt.files; hiddenInput.dispatchEvent(new Event('change', {bubbles: true})); }
                }
            };
            input.click();
        ">
        <i class="bi bi-file-earmark-text text-primary me-1"></i> Dépense depuis facture (OCR)</a></li>
@endif
```

Note : l'approche avec le file picker JS natif + transfert vers un input Livewire caché est complexe. Alternative plus simple : le bouton dispatch `open-transaction-form-ocr`, ce qui ouvre le TransactionForm en mode OCR, et l'utilisateur utilise le bouton "Joindre un justificatif" existant dans le formulaire pour sélectionner le fichier. L'analyse se déclenche automatiquement via `updatedPieceJointe()`. C'est moins fluide (2 clics au lieu de 1) mais beaucoup plus simple à implémenter.

L'implémenteur doit choisir l'approche la plus propre. L'essentiel : le formulaire s'ouvre en mode OCR et l'analyse se déclenche dès le fichier uploadé.

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/transaction-universelle.blade.php
git commit -m "feat: bouton Nouvelle dépense depuis facture (OCR) dans TransactionUniverselle"
```

---

### Task 6: AnimateurManager — enrichissement OCR du step upload

**Files:**
- Modify: `app/Livewire/AnimateurManager.php`
- Modify: `resources/views/livewire/animateur-manager-modal.blade.php`

- [ ] **Step 1: Modifier le composant Livewire**

Dans `app/Livewire/AnimateurManager.php`, ajouter les imports :
```php
use App\Services\InvoiceOcrService;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
```

Ajouter les propriétés :
```php
public bool $ocrAnalyzing = false;

public ?string $ocrError = null;

/** @var array<string> */
public array $ocrWarnings = [];
```

Modifier `updatedModalPieceJointe()` pour intégrer l'OCR :

```php
public function updatedModalPieceJointe(): void
{
    $this->ocrError = null;
    $this->ocrWarnings = [];

    if ($this->modalPieceJointe === null) {
        return;
    }

    $this->validate([
        'modalPieceJointe' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
    ], [
        'modalPieceJointe.mimes' => 'Le justificatif doit être un fichier PDF, JPG ou PNG.',
        'modalPieceJointe.max' => 'Le justificatif ne doit pas dépasser 10 Mo.',
    ]);

    $this->previewUrl = $this->modalPieceJointe->temporaryUrl();
    $this->previewMime = $this->modalPieceJointe->getMimeType();

    // Lancer l'OCR si configuré
    if (InvoiceOcrService::isConfigured()) {
        $this->ocrAnalyzing = true;

        try {
            $tiers = \App\Models\Tiers::find($this->modalTiersId);
            $context = [
                'tiers_attendu' => $tiers?->displayName() ?? '',
                'operation_attendue' => $this->operation->nom,
            ];
            // Trouver la séance attendue depuis les modalLignes
            $seance = $this->modalLignes[0]['seance'] ?? null;
            if ($seance !== null && $seance !== '') {
                $context['seance_attendue'] = (int) $seance;
            }

            $result = app(InvoiceOcrService::class)->analyze($this->modalPieceJointe, $context);
            $this->applyOcrResult($result);
        } catch (OcrAnalysisException|OcrNotConfiguredException $e) {
            $this->ocrError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->ocrError = 'Erreur inattendue : ' . $e->getMessage();
        } finally {
            $this->ocrAnalyzing = false;
        }
    }

    $this->modalStep = 'form';
}
```

Ajouter la méthode pour réessayer :
```php
public function retryOcr(): void
{
    if ($this->modalPieceJointe !== null) {
        $this->ocrError = null;
        $this->updatedModalPieceJointe();
    }
}
```

Ajouter la méthode pour appliquer le résultat :
```php
private function applyOcrResult(\App\DTOs\InvoiceOcrResult $result): void
{
    if ($result->date !== null) {
        $this->modalDate = $result->date;
    }
    if ($result->reference !== null) {
        $this->modalReference = $result->reference;
    }

    // Appliquer les lignes extraites (garder opération et séance du contexte matrice)
    if (! empty($result->lignes)) {
        $existingOpId = $this->modalLignes[0]['operation_id'] ?? null;
        $existingSeance = $this->modalLignes[0]['seance'] ?? null;

        $this->modalLignes = [];
        foreach ($result->lignes as $ligne) {
            $this->modalLignes[] = [
                'sous_categorie_id' => $ligne->sous_categorie_id,
                'operation_id' => $existingOpId,  // on garde le contexte matrice
                'seance' => $existingSeance,       // on garde le contexte matrice
                'montant' => number_format($ligne->montant, 2, '.', ''),
                'id' => null,
            ];
        }
    }

    $this->ocrWarnings = $result->warnings;
}
```

Dans `closeModal()`, ajouter au reset : `$this->ocrAnalyzing = false; $this->ocrError = null; $this->ocrWarnings = [];`

- [ ] **Step 2: Modifier la vue modale**

Dans `resources/views/livewire/animateur-manager-modal.blade.php`, dans le step form (le `@else`), après l'ouverture du `<div>` de la colonne formulaire et avant le bloc erreurs, ajouter :

```blade
@if ($ocrAnalyzing)
    <div class="alert alert-info py-2 d-flex align-items-center gap-2 small">
        <div class="spinner-border spinner-border-sm"></div>
        Analyse de la facture en cours...
    </div>
@endif

@if ($ocrError)
    <div class="alert alert-danger py-2 small">
        <i class="bi bi-exclamation-triangle"></i> {{ $ocrError }}
        <div class="mt-1">
            <button type="button" wire:click="retryOcr" class="btn btn-sm btn-outline-danger py-0" style="font-size:11px">
                <i class="bi bi-arrow-clockwise"></i> Réessayer
            </button>
            <button type="button" wire:click="$set('ocrError', null)" class="btn btn-sm btn-outline-secondary py-0" style="font-size:11px">
                Ignorer
            </button>
        </div>
    </div>
@endif

@if (! empty($ocrWarnings))
    <div class="alert alert-warning py-2 small">
        @foreach ($ocrWarnings as $warning)
            <div><i class="bi bi-exclamation-triangle"></i> {{ $warning }}</div>
        @endforeach
    </div>
@endif
```

- [ ] **Step 3: Pint + Commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint
git add app/Livewire/AnimateurManager.php resources/views/livewire/animateur-manager-modal.blade.php
git commit -m "feat: AnimateurManager — OCR avec pré-remplissage et warnings de cohérence"
```

---

### Task 7: Pint + tests complets

- [ ] **Step 1: Lancer Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Lancer tous les tests**

Run: `./vendor/bin/sail test`
Expected: tous les tests PASS.

- [ ] **Step 3: Commit si Pint a modifié des fichiers**

```bash
git add -A && git commit -m "style: pint formatting"
```
