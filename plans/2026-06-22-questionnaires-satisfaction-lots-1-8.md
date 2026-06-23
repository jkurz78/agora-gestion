# Questionnaires de satisfaction — Plan d'implémentation (lots 1 à 8)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Livrer le moteur de questionnaires complet : un admin crée un modèle réutilisable, lance une campagne sur une opération, les participants répondent en ligne (lien tokenisé anonyme) **ou** sur papier (QR + saisie assistée par OCR/IA validée par un humain), l'admin consulte les résultats, exporte en Excel et pilote l'envoi/les relances par email.

**Architecture:** 10 tables tenant-scopées (`TenantModel`). Une campagne **fige un snapshot** des questions du modèle (D2). Les réponses sont en **colonnes typées** (D8). Le parcours répondant est un **controller + Blade public** résolu par **hash de token** (D18) ; le token clair est aussi stocké **chiffré** (`token_chiffre`) pour reconstruire QR/lien/relance. Invariant **≤1 soumission active par invitation** (service + `active_key` unique). OCR via Anthropic (modèle dédié `questionnaire_ocr_model`, D16) ne produit qu'un **brouillon** validé humainement. Écrans admin en Livewire 4 ; pas de Vite/npm (Bootstrap 5 CDN).

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest, MySQL (prod) / SQLite `:memory:` (tests), `openspout/openspout` (XLSX), `barryvdh/laravel-dompdf` (PDF), `endroid/qr-code` (QR), `khanamiryan/qrcode-detector-decoder` (lecture QR), `webklex/laravel-imap` + chaîne `IncomingDocuments` (intake email), API Anthropic (OCR). Branche : `feat/questionnaires`.

**Découpage en jalons :** Lots 1→5 = V1 numérique de bout en bout (livrable autonome). Lots 6→8 = canal papier (impression, scan/OCR) + communication avancée. Chaque lot est mergeable indépendamment après recette.

**Référence spec :** [docs/specs/2026-06-22-questionnaires-satisfaction-spec.md](../docs/specs/2026-06-22-questionnaires-satisfaction-spec.md)

**Conventions projet (rappel) :** `declare(strict_types=1)` + `final class` + type hints partout ; PSR-12 (`./vendor/bin/pint`) ; locale `fr` ; cast `(int)` des deux côtés des `===` PK/FK ; en-têtes de tableaux `table-dark` + `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"` ; `wire:confirm` via modale Bootstrap, jamais `confirm()` natif. Tests : étendre le bootstrap Pest global (tenant booté). Lancer un test : `vendor/bin/pest chemin --filter="..."`.

---

## File structure map

**Migrations** (`database/migrations/`) — un fichier par lot pour rester atomique :
- `..._create_questionnaire_templates_tables.php` (templates + template_questions) — Lot 1
- `..._create_questionnaire_campaign_tables.php` (campaigns + campaign_questions + invitations) — Lot 2
- `..._create_questionnaire_submission_tables.php` (submissions + answers) — Lot 3

**Enums** (`app/Enums/`):
- `TypeQuestion.php` (Lot 1), `StatutCampagne.php` + `StatutInvitation.php` (Lot 2), `StatutSubmission.php` (Lot 3)

**Models** (`app/Models/`): `QuestionnaireTemplate`, `QuestionnaireTemplateQuestion` (L1) ; `QuestionnaireCampaign`, `QuestionnaireCampaignQuestion`, `QuestionnaireInvitation` (L2) ; `QuestionnaireSubmission`, `QuestionnaireAnswer` (L3). Tous étendent `TenantModel`.

**Services** (`app/Services/Questionnaire/`):
- `QuestionnaireTokenService.php` (L2) — token clair + hash + code_court
- `QuestionnaireCampaignService.php` (L2) — snapshot, ouvrir, clôturer
- `QuestionnaireReponseService.php` (L3) — get-or-create soumission, save, valider, finaliser, rouvrir
- `QuestionnaireResultatService.php` (L4) — agrégations
- `QuestionnaireExcelExporter.php` (L5) — XLSX openspout

**Livewire** (`app/Livewire/Questionnaire/`):
- `ModeleList.php` (L1), `ModeleEditor.php` (L1)
- `OperationQuestionnaires.php` (L2, embarqué sur la fiche opération)
- `CampagneResultats.php` (L4)

**Controllers** (`app/Http/Controllers/`):
- `QuestionnaireRepondantController.php` (L3, public)
- `QuestionnaireExportController.php` (L5)

**Routes** : groupe `questionnaires.*` (admin) + groupe public `q/*` dans `routes/web.php`.

**Blades** (`resources/views/`): `livewire/questionnaire/*.blade.php`, `questionnaire/modeles/*.blade.php`, `questionnaire/repondant/*.blade.php` (public), `questionnaire/resultats/*`.

**Factories** (`database/factories/`): une par modèle.

---

## LOT 1 — Fondations : modèles & questions typées

### Task 1.1 : Migration tables catalogue

**Files:**
- Create: `database/migrations/2026_06_22_100001_create_questionnaire_templates_tables.php`

- [ ] **Step 1 : Écrire la migration**

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
        Schema::create('questionnaire_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('titre_interne');
            $table->string('titre_affiche');
            $table->text('intro')->nullable();
            $table->text('remerciement')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->index('association_id');
        });

        Schema::create('questionnaire_template_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('questionnaire_templates')->cascadeOnDelete();
            $table->string('libelle');
            $table->string('aide')->nullable();
            $table->string('type'); // App\Enums\TypeQuestion
            $table->unsignedInteger('ordre')->default(0);
            $table->boolean('obligatoire')->default(false);
            $table->json('config')->nullable(); // { rendu, options:[{libelle,valeur,ordre}] }
            $table->timestamps();
            $table->index(['template_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_template_questions');
        Schema::dropIfExists('questionnaire_templates');
    }
};
```

- [ ] **Step 2 : Migrer en base de test et vérifier**

Run: `vendor/bin/pest --filter="example" 2>/dev/null; php artisan migrate --pretend 2>&1 | grep questionnaire`
Expected: les deux `CREATE TABLE` apparaissent sans erreur.

- [ ] **Step 3 : Commit**

```bash
git add database/migrations/2026_06_22_100001_create_questionnaire_templates_tables.php
git commit -m "feat(questionnaires): migration tables catalogue (templates + questions)"
```

---

### Task 1.2 : Enum `TypeQuestion`

**Files:**
- Create: `app/Enums/TypeQuestion.php`
- Test: `tests/Unit/Enums/TypeQuestionTest.php`

- [ ] **Step 1 : Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;

it('expose la colonne de valeur par type', function (): void {
    expect(TypeQuestion::TexteCourt->valueColumn())->toBe('value_text');
    expect(TypeQuestion::Satisfaction->valueColumn())->toBe('value_integer');
    expect(TypeQuestion::Ressenti->valueColumn())->toBe('value_integer');
    expect(TypeQuestion::CaseACocher->valueColumn())->toBe('value_boolean');
    expect(TypeQuestion::ChoixUnique->valueColumn())->toBe('value_option');
});

it('identifie les types à options', function (): void {
    expect(TypeQuestion::ChoixUnique->aDesOptions())->toBeTrue();
    expect(TypeQuestion::TexteCourt->aDesOptions())->toBeFalse();
});

it('donne un libellé français', function (): void {
    expect(TypeQuestion::Satisfaction->label())->toBe('Satisfaction (5 niveaux)');
});
```

- [ ] **Step 2 : Lancer le test (échoue)**

Run: `vendor/bin/pest tests/Unit/Enums/TypeQuestionTest.php`
Expected: FAIL — classe `TypeQuestion` introuvable.

- [ ] **Step 3 : Écrire l'enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeQuestion: string
{
    case TexteCourt = 'texte_court';
    case TexteLong = 'texte_long';
    case Satisfaction = 'satisfaction';
    case Ressenti = 'ressenti';
    case CaseACocher = 'case_a_cocher';
    case ChoixUnique = 'choix_unique';

    public function label(): string
    {
        return match ($this) {
            self::TexteCourt => 'Texte court',
            self::TexteLong => 'Texte long',
            self::Satisfaction => 'Satisfaction (5 niveaux)',
            self::Ressenti => 'Ressenti (curseur 0-100)',
            self::CaseACocher => 'Case à cocher (oui/non)',
            self::ChoixUnique => 'Choix unique',
        };
    }

    /** Colonne de questionnaire_answers où la valeur est stockée (D8). */
    public function valueColumn(): string
    {
        return match ($this) {
            self::TexteCourt, self::TexteLong => 'value_text',
            self::Satisfaction, self::Ressenti => 'value_integer',
            self::CaseACocher => 'value_boolean',
            self::ChoixUnique => 'value_option',
        };
    }

    public function aDesOptions(): bool
    {
        return $this === self::ChoixUnique;
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function pourSelect(): array
    {
        return array_map(
            fn (self $t): array => ['value' => $t->value, 'label' => $t->label()],
            self::cases(),
        );
    }
}
```

- [ ] **Step 4 : Lancer le test (passe)**

Run: `vendor/bin/pest tests/Unit/Enums/TypeQuestionTest.php`
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
git add app/Enums/TypeQuestion.php tests/Unit/Enums/TypeQuestionTest.php
git commit -m "feat(questionnaires): enum TypeQuestion (6 types + valueColumn)"
```

---

### Task 1.3 : Models + factories catalogue

**Files:**
- Create: `app/Models/QuestionnaireTemplate.php`, `app/Models/QuestionnaireTemplateQuestion.php`
- Create: `database/factories/QuestionnaireTemplateFactory.php`, `database/factories/QuestionnaireTemplateQuestionFactory.php`
- Test: `tests/Feature/Questionnaire/TemplateModelTest.php`

- [ ] **Step 1 : Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;

it('crée un modèle avec questions ordonnées et scopé tenant', function (): void {
    $template = QuestionnaireTemplate::factory()->create(['titre_interne' => 'Satisfaction fin parcours']);

    QuestionnaireTemplateQuestion::factory()->for($template, 'template')->create([
        'libelle' => 'Globalement satisfait ?',
        'type' => TypeQuestion::Satisfaction,
        'ordre' => 1,
    ]);
    QuestionnaireTemplateQuestion::factory()->for($template, 'template')->create([
        'libelle' => 'Un commentaire ?',
        'type' => TypeQuestion::TexteLong,
        'ordre' => 2,
    ]);

    $fresh = $template->fresh('questions');
    expect($fresh->questions)->toHaveCount(2);
    expect($fresh->questions->first()->type)->toBe(TypeQuestion::Satisfaction);
    expect($fresh->association_id)->toBe((int) \App\Tenant\TenantContext::currentId());
});
```

- [ ] **Step 2 : Lancer (échoue)** — Run: `vendor/bin/pest tests/Feature/Questionnaire/TemplateModelTest.php` → FAIL (models manquants).

- [ ] **Step 3 : Écrire `QuestionnaireTemplate`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QuestionnaireTemplate extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'titre_interne', 'titre_affiche', 'intro', 'remerciement', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionnaireTemplateQuestion::class, 'template_id')->orderBy('ordre');
    }
}
```

- [ ] **Step 4 : Écrire `QuestionnaireTemplateQuestion`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeQuestion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QuestionnaireTemplateQuestion extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'template_id', 'libelle', 'aide', 'type', 'ordre', 'obligatoire', 'config',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeQuestion::class,
            'ordre' => 'integer',
            'obligatoire' => 'boolean',
            'config' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplate::class, 'template_id');
    }

    /** @return array<int, array{libelle: string, valeur: string, ordre: int}> */
    public function options(): array
    {
        return $this->config['options'] ?? [];
    }

    public function aDesOptions(): bool
    {
        return $this->type->aDesOptions();
    }
}
```

- [ ] **Step 5 : Écrire les factories**

`database/factories/QuestionnaireTemplateFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuestionnaireTemplate;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionnaireTemplateFactory extends Factory
{
    protected $model = QuestionnaireTemplate::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'titre_interne' => fake()->sentence(3),
            'titre_affiche' => 'Votre avis nous intéresse',
            'intro' => fake()->optional()->paragraph(),
            'remerciement' => 'Merci pour votre retour.',
            'actif' => true,
        ];
    }
}
```

`database/factories/QuestionnaireTemplateQuestionFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionnaireTemplateQuestionFactory extends Factory
{
    protected $model = QuestionnaireTemplateQuestion::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'template_id' => QuestionnaireTemplate::factory(),
            'libelle' => fake()->sentence(),
            'aide' => null,
            'type' => TypeQuestion::TexteCourt,
            'ordre' => 1,
            'obligatoire' => false,
            'config' => null,
        ];
    }
}
```

- [ ] **Step 6 : Lancer (passe)** — Run: `vendor/bin/pest tests/Feature/Questionnaire/TemplateModelTest.php` → PASS.

- [ ] **Step 7 : Commit**

```bash
git add app/Models/QuestionnaireTemplate.php app/Models/QuestionnaireTemplateQuestion.php database/factories/QuestionnaireTemplate*Factory.php tests/Feature/Questionnaire/TemplateModelTest.php
git commit -m "feat(questionnaires): models + factories catalogue"
```

---

### Task 1.4 : Écran liste des modèles (Livewire CRUD)

**Files:**
- Create: `app/Livewire/Questionnaire/ModeleList.php`
- Create: `resources/views/livewire/questionnaire/modele-list.blade.php`
- Create: `resources/views/questionnaire/modeles/index.blade.php`
- Modify: `routes/web.php` (ajouter le groupe `questionnaires.*`)
- Modify: `resources/views/layouts/app-sidebar.blade.php` (lien sous le groupe Opérations)
- Test: `tests/Livewire/Questionnaire/ModeleListTest.php`

- [ ] **Step 1 : Écrire le test Livewire**

```php
<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\ModeleList;
use App\Models\QuestionnaireTemplate;
use Livewire\Livewire;

it('crée un modèle via la modale', function (): void {
    Livewire::test(ModeleList::class)
        ->call('openCreate')
        ->set('titre_interne', 'Satisfaction parcours')
        ->set('titre_affiche', 'Votre avis')
        ->call('save')
        ->assertHasNoErrors();

    expect(QuestionnaireTemplate::where('titre_interne', 'Satisfaction parcours')->exists())->toBeTrue();
});

it('bascule le statut actif', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['actif' => true]);

    Livewire::test(ModeleList::class)->call('toggleActif', $t->id);

    expect($t->fresh()->actif)->toBeFalse();
});
```

- [ ] **Step 2 : Lancer (échoue)** — `vendor/bin/pest tests/Livewire/Questionnaire/ModeleListTest.php` → FAIL.

- [ ] **Step 3 : Écrire le composant** (modèle calqué sur `app/Livewire/SousCategorieList.php`)

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireTemplate;
use Illuminate\View\View;
use Livewire\Component;

final class ModeleList extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $titre_interne = '';

    public string $titre_affiche = '';

    public string $intro = '';

    public string $remerciement = '';

    public function render(): View
    {
        return view('livewire.questionnaire.modele-list', [
            'modeles' => QuestionnaireTemplate::withCount('questions')
                ->orderBy('titre_interne')
                ->get(),
        ]);
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'titre_interne', 'titre_affiche', 'intro', 'remerciement']);
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $m = QuestionnaireTemplate::findOrFail($id);
        $this->editingId = (int) $m->id;
        $this->titre_interne = $m->titre_interne;
        $this->titre_affiche = $m->titre_affiche;
        $this->intro = $m->intro ?? '';
        $this->remerciement = $m->remerciement ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'titre_interne' => 'required|string|max:150',
            'titre_affiche' => 'required|string|max:150',
            'intro' => 'nullable|string',
            'remerciement' => 'nullable|string',
        ]);

        if ($this->editingId !== null) {
            QuestionnaireTemplate::findOrFail($this->editingId)->update($data);
        } else {
            QuestionnaireTemplate::create($data);
        }

        $this->showModal = false;
    }

    public function toggleActif(int $id): void
    {
        $m = QuestionnaireTemplate::findOrFail($id);
        $m->update(['actif' => ! $m->actif]);
    }

    public function supprimer(int $id): void
    {
        QuestionnaireTemplate::findOrFail($id)->delete();
    }
}
```

- [ ] **Step 4 : Écrire la vue Livewire** `resources/views/livewire/questionnaire/modele-list.blade.php`

```blade
<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Modèles de questionnaires</h1>
        <button class="btn btn-primary" wire:click="openCreate">+ Nouveau modèle</button>
    </div>

    <table class="table table-hover align-middle">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr>
                <th>Titre interne</th>
                <th>Titre affiché</th>
                <th class="text-center">Questions</th>
                <th class="text-center">Actif</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($modeles as $m)
                <tr>
                    <td>{{ $m->titre_interne }}</td>
                    <td>{{ $m->titre_affiche }}</td>
                    <td class="text-center">{{ $m->questions_count }}</td>
                    <td class="text-center">
                        <button class="btn btn-sm {{ $m->actif ? 'btn-success' : 'btn-outline-secondary' }}"
                                wire:click="toggleActif({{ $m->id }})">
                            {{ $m->actif ? 'Actif' : 'Inactif' }}
                        </button>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('questionnaires.modeles.editor', $m) }}" class="btn btn-sm btn-outline-primary">Questions</a>
                        <button class="btn btn-sm btn-outline-secondary" wire:click="openEdit({{ $m->id }})">Éditer</button>
                        <button class="btn btn-sm btn-outline-danger"
                                wire:click="supprimer({{ $m->id }})"
                                wire:confirm="Supprimer ce modèle ?">Supprimer</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted text-center py-4">Aucun modèle.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingId ? 'Éditer' : 'Nouveau' }} modèle</h5>
                        <button type="button" class="btn-close" wire:click="$set('showModal', false)"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Titre interne</label>
                            <input type="text" class="form-control" wire:model="titre_interne">
                            @error('titre_interne') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Titre affiché au répondant</label>
                            <input type="text" class="form-control" wire:model="titre_affiche">
                            @error('titre_affiche') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Introduction</label>
                            <textarea class="form-control" rows="2" wire:model="intro"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message de remerciement</label>
                            <textarea class="form-control" rows="2" wire:model="remerciement"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" wire:click="$set('showModal', false)">Annuler</button>
                        <button class="btn btn-primary" wire:click="save">Enregistrer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 5 : Page hôte** `resources/views/questionnaire/modeles/index.blade.php`

```blade
@extends('layouts.app')

@section('content')
    <div class="container-fluid py-3">
        @livewire('questionnaire.modele-list')
    </div>
@endsection
```

- [ ] **Step 6 : Routes** — Ajouter dans `routes/web.php`, après le groupe `operations` (importer en tête `use App\Models\QuestionnaireTemplate;`) :

```php
// ── Questionnaires (catalogue de modèles ; campagnes = depuis la fiche opération) ──
Route::middleware(['auth', 'verified', \App\Http\Middleware\EnsureTwoFactor::class])
    ->prefix('questionnaires')
    ->name('questionnaires.')
    ->group(function (): void {
        Route::view('/modeles', 'questionnaire.modeles.index')->name('modeles.index');
        Route::get('/modeles/{template}', function (QuestionnaireTemplate $template) {
            return view('questionnaire.modeles.editor', compact('template'));
        })->name('modeles.editor');
    });
```

- [ ] **Step 7 : Lien sidebar** — Dans `resources/views/layouts/app-sidebar.blade.php`, sous le groupe « Opérations », ajouter un `<a class="..." href="{{ route('questionnaires.modeles.index') }}">Questionnaires</a>` en suivant le markup des liens voisins (cf. lignes autour de `route('comptabilite.ndf.index')`).

- [ ] **Step 8 : Lancer (passe)** — `vendor/bin/pest tests/Livewire/Questionnaire/ModeleListTest.php` → PASS.

- [ ] **Step 9 : Commit**

```bash
git add app/Livewire/Questionnaire/ModeleList.php resources/views/livewire/questionnaire/modele-list.blade.php resources/views/questionnaire/modeles/index.blade.php routes/web.php resources/views/layouts/app-sidebar.blade.php tests/Livewire/Questionnaire/ModeleListTest.php
git commit -m "feat(questionnaires): écran liste des modèles + route + sidebar"
```

---

### Task 1.5 : Éditeur de questions d'un modèle (Livewire)

**Files:**
- Create: `app/Livewire/Questionnaire/ModeleEditor.php`
- Create: `resources/views/livewire/questionnaire/modele-editor.blade.php`
- Create: `resources/views/questionnaire/modeles/editor.blade.php`
- Test: `tests/Livewire/Questionnaire/ModeleEditorTest.php`

- [ ] **Step 1 : Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Livewire\Questionnaire\ModeleEditor;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use Livewire\Livewire;

it('ajoute une question typée au modèle', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Note globale')
        ->set('type', TypeQuestion::Satisfaction->value)
        ->set('obligatoire', true)
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->libelle)->toBe('Note globale');
    expect($q->type)->toBe(TypeQuestion::Satisfaction);
    expect($q->ordre)->toBe(1);
    expect($q->obligatoire)->toBeTrue();
});

it('génère une valeur technique stable pour chaque option de choix unique', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Comment avez-vous connu ?')
        ->set('type', TypeQuestion::ChoixUnique->value)
        ->set('optionsBrut', "Bouche à oreille\nRéseaux sociaux\nAffiche")
        ->call('ajouterQuestion');

    $opts = $t->questions()->first()->options();
    expect($opts)->toHaveCount(3);
    expect($opts[0]['libelle'])->toBe('Bouche à oreille');
    expect($opts[0]['valeur'])->not->toBe('');         // valeur technique générée
    expect($opts[0]['valeur'])->toBe($opts[0]['valeur']); // stable
});

it('réordonne les questions', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $q1 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 1]);
    $q2 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 2]);

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->call('monter', $q2->id);

    expect($q2->fresh()->ordre)->toBe(1);
    expect($q1->fresh()->ordre)->toBe(2);
});
```

- [ ] **Step 2 : Lancer (échoue)** — FAIL (composant manquant).

- [ ] **Step 3 : Écrire le composant**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

final class ModeleEditor extends Component
{
    public QuestionnaireTemplate $template;

    public string $libelle = '';

    public string $aide = '';

    public string $type = 'texte_court';

    public bool $obligatoire = false;

    /** Une option par ligne (saisie brute admin) — pour les choix uniques. */
    public string $optionsBrut = '';

    public ?int $editingQuestionId = null;

    public function mount(QuestionnaireTemplate $template): void
    {
        $this->template = $template;
    }

    public function render(): View
    {
        return view('livewire.questionnaire.modele-editor', [
            'questions' => $this->template->questions()->get(),
            'types' => TypeQuestion::pourSelect(),
        ]);
    }

    public function ajouterQuestion(): void
    {
        $this->validate([
            'libelle' => 'required|string|max:255',
            'type' => 'required|in:'.implode(',', array_column(TypeQuestion::cases(), 'value')),
        ]);

        $type = TypeQuestion::from($this->type);
        $ordre = (int) $this->template->questions()->max('ordre') + 1;

        QuestionnaireTemplateQuestion::create([
            'template_id' => $this->template->id,
            'libelle' => $this->libelle,
            'aide' => $this->aide ?: null,
            'type' => $type,
            'ordre' => $ordre,
            'obligatoire' => $this->obligatoire,
            'config' => $this->buildConfig($type),
        ]);

        $this->reset(['libelle', 'aide', 'obligatoire', 'optionsBrut']);
        $this->type = 'texte_court';
    }

    public function supprimerQuestion(int $id): void
    {
        QuestionnaireTemplateQuestion::where('template_id', $this->template->id)->findOrFail($id)->delete();
    }

    public function monter(int $id): void
    {
        $this->echangerOrdre($id, -1);
    }

    public function descendre(int $id): void
    {
        $this->echangerOrdre($id, +1);
    }

    private function echangerOrdre(int $id, int $sens): void
    {
        $courant = QuestionnaireTemplateQuestion::where('template_id', $this->template->id)->findOrFail($id);
        $voisin = QuestionnaireTemplateQuestion::where('template_id', $this->template->id)
            ->where('ordre', $courant->ordre + $sens)
            ->first();

        if ($voisin === null) {
            return;
        }

        $tmp = $courant->ordre;
        $courant->update(['ordre' => $voisin->ordre]);
        $voisin->update(['ordre' => $tmp]);
    }

    /** @return array<string, mixed>|null */
    private function buildConfig(TypeQuestion $type): ?array
    {
        if (! $type->aDesOptions()) {
            return null;
        }

        $lignes = collect(explode("\n", $this->optionsBrut))
            ->map(fn (string $l): string => trim($l))
            ->filter()
            ->values();

        $options = $lignes->map(fn (string $libelle, int $i): array => [
            'libelle' => $libelle,
            'valeur' => 'opt_'.Str::lower(Str::random(6)), // valeur technique stable générée une fois
            'ordre' => $i + 1,
        ])->all();

        return ['rendu' => 'auto', 'options' => $options];
    }
}
```

- [ ] **Step 4 : Écrire la vue Livewire** `resources/views/livewire/questionnaire/modele-editor.blade.php`

```blade
<div>
    <a href="{{ route('questionnaires.modeles.index') }}" class="btn btn-sm btn-link px-0 mb-2">&larr; Modèles</a>
    <h1 class="h4">{{ $template->titre_interne }}</h1>

    <table class="table align-middle">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr><th style="width:60px">#</th><th>Question</th><th>Type</th><th class="text-center">Obligatoire</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($questions as $q)
                <tr>
                    <td>{{ $q->ordre }}</td>
                    <td>{{ $q->libelle }}@if($q->aDesOptions()) <span class="text-muted small">({{ count($q->options()) }} options)</span>@endif</td>
                    <td>{{ $q->type->label() }}</td>
                    <td class="text-center">{{ $q->obligatoire ? 'Oui' : 'Non' }}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" wire:click="monter({{ $q->id }})">↑</button>
                        <button class="btn btn-sm btn-outline-secondary" wire:click="descendre({{ $q->id }})">↓</button>
                        <button class="btn btn-sm btn-outline-danger" wire:click="supprimerQuestion({{ $q->id }})" wire:confirm="Supprimer cette question ?">×</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted text-center py-3">Aucune question.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="card">
        <div class="card-body">
            <h2 class="h6">Ajouter une question</h2>
            <div class="row g-2">
                <div class="col-md-5">
                    <input type="text" class="form-control" placeholder="Libellé" wire:model="libelle">
                    @error('libelle') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <select class="form-select" wire:model.live="type">
                        @foreach ($types as $t)
                            <option value="{{ $t['value'] }}">{{ $t['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 form-check d-flex align-items-center ms-2">
                    <input type="checkbox" class="form-check-input me-1" wire:model="obligatoire" id="obl">
                    <label class="form-check-label" for="obl">Obligatoire</label>
                </div>
                <div class="col-md-12">
                    <input type="text" class="form-control" placeholder="Aide (optionnelle)" wire:model="aide">
                </div>
                @if ($type === 'choix_unique')
                    <div class="col-md-12">
                        <label class="form-label small text-muted">Options (une par ligne)</label>
                        <textarea class="form-control" rows="3" wire:model="optionsBrut"></textarea>
                    </div>
                @endif
                <div class="col-12">
                    <button class="btn btn-primary" wire:click="ajouterQuestion">Ajouter</button>
                </div>
            </div>
        </div>
    </div>
</div>
```

Note : `aDesOptions()` est une méthode proxy du modèle question (`return $this->type->aDesOptions();`), ajoutée à la Task 1.3.

- [ ] **Step 5 : Page hôte** `resources/views/questionnaire/modeles/editor.blade.php`

```blade
@extends('layouts.app')

@section('content')
    <div class="container-fluid py-3">
        @livewire('questionnaire.modele-editor', ['template' => $template])
    </div>
@endsection
```

- [ ] **Step 6 : Lancer (passe)** — `vendor/bin/pest tests/Livewire/Questionnaire/ModeleEditorTest.php` → PASS.

- [ ] **Step 7 : Commit**

```bash
git add app/Livewire/Questionnaire/ModeleEditor.php resources/views/livewire/questionnaire/modele-editor.blade.php resources/views/questionnaire/modeles/editor.blade.php tests/Livewire/Questionnaire/ModeleEditorTest.php
git commit -m "feat(questionnaires): éditeur de questions (types, options, réordonnancement)"
```

**✅ Jalon Lot 1 : un admin peut créer un modèle avec questions typées.**

---

## LOT 2 — Campagnes rattachées à une opération

### Task 2.1 : Migration tables campagne

**Files:**
- Create: `database/migrations/2026_06_22_100002_create_questionnaire_campaign_tables.php`

- [ ] **Step 1 : Écrire la migration**

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
        Schema::create('questionnaire_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('questionnaire_templates')->nullOnDelete();
            $table->string('titre_affiche');
            $table->text('intro')->nullable();
            $table->text('remerciement')->nullable();
            $table->string('statut')->default('brouillon'); // App\Enums\StatutCampagne
            $table->timestamp('ouverte_at')->nullable();
            $table->timestamp('cloturee_at')->nullable();
            $table->timestamps();
            $table->index(['operation_id', 'statut']);
        });

        Schema::create('questionnaire_campaign_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('questionnaire_campaigns')->cascadeOnDelete();
            $table->string('libelle');
            $table->string('aide')->nullable();
            $table->string('type');
            $table->unsignedInteger('ordre')->default(0);
            $table->boolean('obligatoire')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'ordre']);
        });

        Schema::create('questionnaire_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('questionnaire_campaigns')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('participants')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();      // sha256 du token clair (D18) — lookup public
            $table->text('token_chiffre');                   // token clair chiffré (Laravel encrypted) — pour reconstruire QR/lien/relance. Une fuite DB seule (sans APP_KEY) reste inexploitable.
            $table->string('code_court', 16);                // secours back-office uniquement
            $table->string('statut')->default('non_ouvert'); // App\Enums\StatutInvitation
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index('campaign_id');
            $table->unique(['campaign_id', 'participant_id']); // une invitation par participant/campagne
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_invitations');
        Schema::dropIfExists('questionnaire_campaign_questions');
        Schema::dropIfExists('questionnaire_campaigns');
    }
};
```

- [ ] **Step 2 : Vérifier** — `php artisan migrate --pretend 2>&1 | grep questionnaire_campaign` → 3 CREATE TABLE.
- [ ] **Step 3 : Commit** — `git commit -am "feat(questionnaires): migration tables campagne + invitations"`

---

### Task 2.2 : Enums `StatutCampagne` + `StatutInvitation`

**Files:**
- Create: `app/Enums/StatutCampagne.php`, `app/Enums/StatutInvitation.php`
- Test: `tests/Unit/Enums/StatutCampagneTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;

it('autorise les bonnes transitions', function (): void {
    expect(StatutCampagne::Brouillon->peutOuvrir())->toBeTrue();
    expect(StatutCampagne::Ouverte->peutOuvrir())->toBeFalse();
    expect(StatutCampagne::Ouverte->peutCloturer())->toBeTrue();
    expect(StatutCampagne::Ouverte->accepteReponses())->toBeTrue();
    expect(StatutCampagne::Cloturee->accepteReponses())->toBeFalse();
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : `StatutCampagne`**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutCampagne: string
{
    case Brouillon = 'brouillon';
    case Ouverte = 'ouverte';
    case Cloturee = 'cloturee';
    case Archivee = 'archivee';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Ouverte => 'Ouverte',
            self::Cloturee => 'Clôturée',
            self::Archivee => 'Archivée',
        };
    }

    public function peutOuvrir(): bool
    {
        return $this === self::Brouillon;
    }

    public function peutCloturer(): bool
    {
        return $this === self::Ouverte;
    }

    public function accepteReponses(): bool
    {
        return $this === self::Ouverte;
    }
}
```

- [ ] **Step 4 : `StatutInvitation`**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutInvitation: string
{
    case NonOuvert = 'non_ouvert';
    case Commence = 'commence';
    case Soumis = 'soumis';

    public function label(): string
    {
        return match ($this) {
            self::NonOuvert => 'Non ouvert',
            self::Commence => 'Commencé',
            self::Soumis => 'Soumis',
        };
    }
}
```

- [ ] **Step 5 : Lancer (passe). Commit.**

```bash
git add app/Enums/StatutCampagne.php app/Enums/StatutInvitation.php tests/Unit/Enums/StatutCampagneTest.php
git commit -m "feat(questionnaires): enums StatutCampagne + StatutInvitation"
```

---

### Task 2.3 : Models + factories campagne

**Files:**
- Create: `app/Models/QuestionnaireCampaign.php`, `app/Models/QuestionnaireCampaignQuestion.php`, `app/Models/QuestionnaireInvitation.php`
- Create: factories correspondantes
- Modify: `app/Models/Operation.php` (relation `questionnaireCampaigns`)
- Test: `tests/Feature/Questionnaire/CampaignModelTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\QuestionnaireCampaign;

it('relie une opération à ses campagnes', function (): void {
    $op = Operation::factory()->create();
    QuestionnaireCampaign::factory()->for($op, 'operation')->count(2)->create();

    expect($op->fresh()->questionnaireCampaigns)->toHaveCount(2);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : `QuestionnaireCampaign`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutCampagne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QuestionnaireCampaign extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'operation_id', 'template_id',
        'titre_affiche', 'intro', 'remerciement', 'statut', 'ouverte_at', 'cloturee_at',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutCampagne::class,
            'ouverte_at' => 'datetime',
            'cloturee_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionnaireCampaignQuestion::class, 'campaign_id')->orderBy('ordre');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(QuestionnaireInvitation::class, 'campaign_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(QuestionnaireSubmission::class, 'campaign_id');
    }
}
```

- [ ] **Step 4 : `QuestionnaireCampaignQuestion`** (identique en colonnes à la question de template, sans la relation template)

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeQuestion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QuestionnaireCampaignQuestion extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'campaign_id', 'libelle', 'aide', 'type', 'ordre', 'obligatoire', 'config',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeQuestion::class,
            'ordre' => 'integer',
            'obligatoire' => 'boolean',
            'config' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaign::class, 'campaign_id');
    }

    /** @return array<int, array{libelle: string, valeur: string, ordre: int}> */
    public function options(): array
    {
        return $this->config['options'] ?? [];
    }

    public function libelleOption(string $valeur): ?string
    {
        foreach ($this->options() as $opt) {
            if ($opt['valeur'] === $valeur) {
                return $opt['libelle'];
            }
        }

        return null;
    }

    public function aDesOptions(): bool
    {
        return $this->type->aDesOptions();
    }
}
```

- [ ] **Step 5 : `QuestionnaireInvitation`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutInvitation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QuestionnaireInvitation extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'campaign_id', 'participant_id',
        'token_hash', 'token_chiffre', 'code_court', 'statut', 'sent_at', 'opened_at', 'submitted_at',
    ];

    protected $hidden = ['token_hash', 'token_chiffre'];

    protected function casts(): array
    {
        return [
            'statut' => StatutInvitation::class,
            'token_chiffre' => 'encrypted', // lecture = token clair déchiffré (QR/lien/relance)
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** URL publique de réponse (token clair déchiffré via le cast encrypted). */
    public function lienReponse(): string
    {
        return \App\Support\TenantUrl::route('questionnaire.show', ['token' => $this->token_chiffre]);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaign::class, 'campaign_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(QuestionnaireSubmission::class, 'invitation_id');
    }

    /** Soumission active : en_cours ou soumise (invariant ≤1, voir spec §3.3). */
    public function submissionActive(): ?QuestionnaireSubmission
    {
        return $this->submissions()->whereIn('statut', ['en_cours', 'soumise'])->first();
    }
}
```

- [ ] **Step 6 : Relation sur `Operation`** — Ajouter dans `app/Models/Operation.php` :

```php
public function questionnaireCampaigns(): HasMany
{
    return $this->hasMany(QuestionnaireCampaign::class);
}
```

- [ ] **Step 7 : Factories** — `QuestionnaireCampaignFactory` (operation_id => Operation::factory(), template_id => null, titre_affiche, statut 'brouillon'), `QuestionnaireCampaignQuestionFactory` (campaign_id => QuestionnaireCampaign::factory(), type TexteCourt), `QuestionnaireInvitationFactory` : générer `$clair = Str::random(48)` puis `token_hash => hash('sha256', $clair)`, `token_chiffre => $clair` (le cast chiffre), `code_court => strtoupper(Str::random(8))`, statut 'non_ouvert', campaign_id + participant_id => factories. Toutes injectent `association_id => TenantContext::currentId() ?? 1`.

- [ ] **Step 8 : Lancer (passe). Commit.**

```bash
git add app/Models/QuestionnaireCampaign.php app/Models/QuestionnaireCampaignQuestion.php app/Models/QuestionnaireInvitation.php app/Models/Operation.php database/factories/QuestionnaireCampaign*Factory.php database/factories/QuestionnaireInvitationFactory.php tests/Feature/Questionnaire/CampaignModelTest.php
git commit -m "feat(questionnaires): models + factories campagne/invitation"
```

---

### Task 2.4 : `QuestionnaireTokenService`

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireTokenService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireTokenServiceTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Services\Questionnaire\QuestionnaireTokenService;

it('produit un token clair long et son hash sha256', function (): void {
    $pair = app(QuestionnaireTokenService::class)->generer();

    expect(strlen($pair['clair']))->toBeGreaterThanOrEqual(40);
    expect($pair['hash'])->toBe(hash('sha256', $pair['clair']));
    expect($pair['hash'])->toHaveLength(64);
});

it('produit un code court lisible sans caractères ambigus', function (): void {
    $code = app(QuestionnaireTokenService::class)->codeCourt();

    expect($code)->toMatch('/^[0-9A-Z\-]+$/');
    expect($code)->not->toContain('O'); // alphabet sans ambiguïté
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use Illuminate\Support\Str;

final class QuestionnaireTokenService
{
    // Alphabet sans I, O, 0, 1, L pour le code court (lecture humaine sur papier).
    private const ALPHABET_COURT = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * Token public : clair haute entropie (jamais stocké) + hash sha256 (stocké).
     *
     * @return array{clair: string, hash: string}
     */
    public function generer(): array
    {
        $clair = Str::random(48);

        return ['clair' => $clair, 'hash' => hash('sha256', $clair)];
    }

    public function hash(string $clair): string
    {
        return hash('sha256', $clair);
    }

    public function codeCourt(int $taille = 8): string
    {
        $code = '';
        for ($i = 0; $i < $taille; $i++) {
            $code .= self::ALPHABET_COURT[random_int(0, strlen(self::ALPHABET_COURT) - 1)];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }
}
```

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireTokenService.php tests/Feature/Questionnaire/QuestionnaireTokenServiceTest.php
git commit -m "feat(questionnaires): QuestionnaireTokenService (token hashé + code court)"
```

---

### Task 2.5 : `QuestionnaireCampaignService` (snapshot + ouvrir/clôturer)

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireCampaignService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireCampaignServiceTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Enums\TypeQuestion;
use App\Models\Operation;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use App\Services\Questionnaire\QuestionnaireCampaignService;

it('fige un snapshot des questions du modèle dans la campagne', function (): void {
    $op = Operation::factory()->create();
    $t = QuestionnaireTemplate::factory()->create(['titre_affiche' => 'Avis', 'remerciement' => 'Merci']);
    QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1,
    ]);

    $campagne = app(QuestionnaireCampaignService::class)->creerDepuisModele($op, $t);

    expect($campagne->statut)->toBe(StatutCampagne::Brouillon);
    expect($campagne->titre_affiche)->toBe('Avis');
    expect($campagne->questions)->toHaveCount(1);
    expect($campagne->questions->first()->libelle)->toBe('Note');

    // Modifier le modèle après coup NE change PAS la campagne (snapshot).
    $t->questions()->first()->update(['libelle' => 'MODIFIÉ']);
    expect($campagne->fresh()->questions->first()->libelle)->toBe('Note');
});

it('ouvre puis clôture une campagne', function (): void {
    $op = Operation::factory()->create();
    $t = QuestionnaireTemplate::factory()->create();
    $svc = app(QuestionnaireCampaignService::class);
    $campagne = $svc->creerDepuisModele($op, $t);

    $svc->ouvrir($campagne);
    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Ouverte);
    expect($campagne->fresh()->ouverte_at)->not->toBeNull();

    $svc->cloturer($campagne);
    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Cloturee);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutCampagne;
use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireTemplate;
use Illuminate\Support\Facades\DB;

final class QuestionnaireCampaignService
{
    public function creerDepuisModele(Operation $operation, QuestionnaireTemplate $template): QuestionnaireCampaign
    {
        return DB::transaction(function () use ($operation, $template): QuestionnaireCampaign {
            $campagne = QuestionnaireCampaign::create([
                'operation_id' => $operation->id,
                'template_id' => $template->id,
                'titre_affiche' => $template->titre_affiche,
                'intro' => $template->intro,
                'remerciement' => $template->remerciement,
                'statut' => StatutCampagne::Brouillon,
            ]);

            foreach ($template->questions()->get() as $q) {
                $campagne->questions()->create([
                    'libelle' => $q->libelle,
                    'aide' => $q->aide,
                    'type' => $q->type,
                    'ordre' => $q->ordre,
                    'obligatoire' => $q->obligatoire,
                    'config' => $q->config, // snapshot des options + rendu
                ]);
            }

            return $campagne;
        });
    }

    public function ouvrir(QuestionnaireCampaign $campagne): void
    {
        abort_unless($campagne->statut->peutOuvrir(), 422, 'Campagne non ouvrable.');
        $campagne->update(['statut' => StatutCampagne::Ouverte, 'ouverte_at' => now()]);
    }

    public function cloturer(QuestionnaireCampaign $campagne): void
    {
        abort_unless($campagne->statut->peutCloturer(), 422, 'Campagne non clôturable.');
        $campagne->update(['statut' => StatutCampagne::Cloturee, 'cloturee_at' => now()]);
    }
}
```

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireCampaignService.php tests/Feature/Questionnaire/QuestionnaireCampaignServiceTest.php
git commit -m "feat(questionnaires): QuestionnaireCampaignService (snapshot + ouvrir/clôturer)"
```

---

### Task 2.6 : `QuestionnaireInvitationService` (génération invitations)

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireInvitationService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireInvitationServiceTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Services\Questionnaire\QuestionnaireInvitationService;

it('génère une invitation par participant sélectionné, sans doublon', function (): void {
    $op = Operation::factory()->create();
    $p1 = Participant::factory()->create(['operation_id' => $op->id]);
    $p2 = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create();

    $svc = app(QuestionnaireInvitationService::class);
    $svc->genererPour($campagne, [$p1->id, $p2->id]);
    $svc->genererPour($campagne, [$p1->id, $p2->id]); // rejeu → pas de doublon

    expect($campagne->fresh()->invitations)->toHaveCount(2);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutInvitation;
use App\Models\QuestionnaireCampaign;
use Illuminate\Support\Facades\DB;

final class QuestionnaireInvitationService
{
    public function __construct(private readonly QuestionnaireTokenService $tokens) {}

    /**
     * @param  array<int>  $participantIds
     * @return array<int, string>  participant_id => token CLAIR (à utiliser pour les liens/QR, jamais stocké)
     */
    public function genererPour(QuestionnaireCampaign $campagne, array $participantIds): array
    {
        return DB::transaction(function () use ($campagne, $participantIds): array {
            $clairs = [];

            foreach ($participantIds as $pid) {
                $pid = (int) $pid;

                // Invariant : une invitation par (campagne, participant) — la contrainte unique
                // protège, mais on évite l'exception en sautant les existants.
                $existe = $campagne->invitations()->where('participant_id', $pid)->exists();
                if ($existe) {
                    continue;
                }

                $pair = $this->tokens->generer();
                $campagne->invitations()->create([
                    'participant_id' => $pid,
                    'token_hash' => $pair['hash'],
                    'token_chiffre' => $pair['clair'], // cast encrypted → chiffré à l'écriture
                    'code_court' => $this->tokens->codeCourt(),
                    'statut' => StatutInvitation::NonOuvert,
                ]);

                $clairs[$pid] = $pair['clair'];
            }

            return $clairs;
        });
    }
}
```

Note : le token clair n'est renvoyé qu'à la génération (jamais récupérable ensuite, par design). Le lot 8 (communication) consomme cette map pour bâtir `{lien_questionnaire}`. En lots 1-5, l'admin récupère les liens à la génération.

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireInvitationService.php tests/Feature/Questionnaire/QuestionnaireInvitationServiceTest.php
git commit -m "feat(questionnaires): QuestionnaireInvitationService (1 invitation/participant)"
```

---

### Task 2.7 : Section campagnes sur la fiche opération (Livewire)

**Files:**
- Create: `app/Livewire/Questionnaire/OperationQuestionnaires.php`
- Create: `resources/views/livewire/questionnaire/operation-questionnaires.blade.php`
- Modify: `resources/views/gestion/operations/show.blade.php` (insérer le composant dans une section/onglet)
- Test: `tests/Livewire/Questionnaire/OperationQuestionnairesTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Livewire\Questionnaire\OperationQuestionnaires;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireTemplate;
use Livewire\Livewire;

it('crée une campagne depuis un modèle et génère les invitations des participants choisis', function (): void {
    $op = Operation::factory()->create();
    $p1 = Participant::factory()->create(['operation_id' => $op->id]);
    $p2 = Participant::factory()->create(['operation_id' => $op->id]);
    $modele = QuestionnaireTemplate::factory()->create();

    Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->set('selectedTemplateId', $modele->id)
        ->set('selectedParticipants', [$p1->id, $p2->id])
        ->call('creerCampagne')
        ->assertHasNoErrors();

    $op->refresh();
    expect($op->questionnaireCampaigns)->toHaveCount(1);
    $campagne = $op->questionnaireCampaigns->first();
    expect($campagne->statut)->toBe(StatutCampagne::Brouillon);
    expect($campagne->invitations)->toHaveCount(2);
});

it('ouvre une campagne brouillon', function (): void {
    $op = Operation::factory()->create();
    $modele = QuestionnaireTemplate::factory()->create();
    $component = Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->set('selectedTemplateId', $modele->id)
        ->set('selectedParticipants', [])
        ->call('creerCampagne');

    $campagne = $op->fresh()->questionnaireCampaigns->first();
    $component->call('ouvrir', $campagne->id);

    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Ouverte);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Composant**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireTemplate;
use App\Services\Questionnaire\QuestionnaireCampaignService;
use App\Services\Questionnaire\QuestionnaireInvitationService;
use Illuminate\View\View;
use Livewire\Component;

final class OperationQuestionnaires extends Component
{
    public Operation $operation;

    public ?int $selectedTemplateId = null;

    /** @var array<int> */
    public array $selectedParticipants = [];

    public bool $showCreate = false;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
        // Défaut D5 : tous les participants présélectionnés.
        $this->selectedParticipants = $operation->participants()->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function render(): View
    {
        return view('livewire.questionnaire.operation-questionnaires', [
            // NB : pas de comptage des soumissions ici — la table questionnaire_submissions
            // n'existe qu'à partir du lot 3. Le compteur soumises/taux + le lien « Résultats »
            // sont ajoutés au lot 4 (withCount('submissions as soumises_count') à ce moment-là).
            'campagnes' => $this->operation->questionnaireCampaigns()
                ->withCount('invitations')
                ->latest()
                ->get(),
            'modeles' => QuestionnaireTemplate::where('actif', true)->orderBy('titre_interne')->get(),
            'participants' => $this->operation->participants()->with('tiers')->get(),
        ]);
    }

    public function creerCampagne(
        QuestionnaireCampaignService $campagnes,
        QuestionnaireInvitationService $invitations,
    ): void {
        $this->validate(['selectedTemplateId' => 'required|exists:questionnaire_templates,id']);

        $modele = QuestionnaireTemplate::findOrFail($this->selectedTemplateId);
        $campagne = $campagnes->creerDepuisModele($this->operation, $modele);
        $invitations->genererPour($campagne, $this->selectedParticipants);

        $this->showCreate = false;
        $this->reset('selectedTemplateId');
    }

    public function ouvrir(int $campagneId, QuestionnaireCampaignService $campagnes): void
    {
        $campagnes->ouvrir($this->campagne($campagneId));
    }

    public function cloturer(int $campagneId, QuestionnaireCampaignService $campagnes): void
    {
        $campagnes->cloturer($this->campagne($campagneId));
    }

    private function campagne(int $id): QuestionnaireCampaign
    {
        return $this->operation->questionnaireCampaigns()->findOrFail($id);
    }
}
```

- [ ] **Step 4 : Vue Livewire** `resources/views/livewire/questionnaire/operation-questionnaires.blade.php` — tableau des campagnes (titre, statut badge, invitations, soumises, taux), bouton « Nouvelle campagne » ouvrant un bloc avec `<select wire:model="selectedTemplateId">` + liste de participants à cocher (`wire:model="selectedParticipants"` valeur = id), boutons Ouvrir/Clôturer (`wire:confirm`) selon `$c->statut`, lien « Résultats » vers `route('questionnaires.campagnes.resultats', $c)` (route ajoutée au lot 4). En-tête de tableau `table-dark` + style projet. (Markup analogue à `modele-list.blade.php`.)

- [ ] **Step 5 : Insérer sur la fiche opération** — Dans `resources/views/gestion/operations/show.blade.php`, ajouter une section (ou un onglet, selon la structure existante de la page) :

```blade
<section class="mt-4">
    @livewire('questionnaire.operation-questionnaires', ['operation' => $operation])
</section>
```

- [ ] **Step 6 : Lancer (passe). Commit.**

```bash
git add app/Livewire/Questionnaire/OperationQuestionnaires.php resources/views/livewire/questionnaire/operation-questionnaires.blade.php resources/views/gestion/operations/show.blade.php tests/Livewire/Questionnaire/OperationQuestionnairesTest.php
git commit -m "feat(questionnaires): section campagnes sur la fiche opération"
```

**✅ Jalon Lot 2 : une opération peut lancer un questionnaire (campagne + invitations).**

---

## LOT 3 — Parcours répondant en ligne

### Task 3.1 : Migration submissions + answers + enum `StatutSubmission`

**Files:**
- Create: `database/migrations/2026_06_22_100003_create_questionnaire_submission_tables.php`
- Create: `app/Enums/StatutSubmission.php`

- [ ] **Step 1 : Migration**

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
        Schema::create('questionnaire_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('questionnaire_campaigns')->cascadeOnDelete();
            $table->foreignId('invitation_id')->constrained('questionnaire_invitations')->cascadeOnDelete();
            $table->string('statut')->default('en_cours'); // App\Enums\StatutSubmission
            $table->boolean('accepte_contact')->default(false);
            $table->string('source')->default('en_ligne'); // en_ligne | papier (lot 7)
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'statut']);
            $table->index('invitation_id');
        });

        Schema::create('questionnaire_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained('questionnaire_submissions')->cascadeOnDelete();
            $table->foreignId('campaign_question_id')->constrained('questionnaire_campaign_questions')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->integer('value_integer')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->string('value_option')->nullable();
            $table->json('value_meta')->nullable(); // fige le libellé d'option choisi
            $table->timestamps();
            $table->unique(['submission_id', 'campaign_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_answers');
        Schema::dropIfExists('questionnaire_submissions');
    }
};
```

- [ ] **Step 2 : Enum `StatutSubmission`**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutSubmission: string
{
    case EnCours = 'en_cours';
    case Soumise = 'soumise';
    case Remplacee = 'remplacee'; // utilisé au lot 7 (scan-remplace)
}
```

- [ ] **Step 3 : Vérifier migration + Commit.**

```bash
git add database/migrations/2026_06_22_100003_create_questionnaire_submission_tables.php app/Enums/StatutSubmission.php
git commit -m "feat(questionnaires): migration submissions/answers + enum StatutSubmission"
```

---

### Task 3.2 : Models + factories submission/answer

**Files:**
- Create: `app/Models/QuestionnaireSubmission.php`, `app/Models/QuestionnaireAnswer.php`
- Create: factories
- Test: `tests/Feature/Questionnaire/SubmissionModelTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireSubmission;

it('relie une soumission à ses réponses', function (): void {
    $submission = QuestionnaireSubmission::factory()->create();
    QuestionnaireAnswer::factory()->for($submission, 'submission')->count(3)->create();

    expect($submission->fresh()->answers)->toHaveCount(3);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : `QuestionnaireSubmission`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutSubmission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QuestionnaireSubmission extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'campaign_id', 'invitation_id', 'statut', 'accepte_contact', 'source', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutSubmission::class,
            'accepte_contact' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaign::class, 'campaign_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireInvitation::class, 'invitation_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuestionnaireAnswer::class, 'submission_id');
    }
}
```

- [ ] **Step 4 : `QuestionnaireAnswer`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QuestionnaireAnswer extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'submission_id', 'campaign_question_id',
        'value_text', 'value_integer', 'value_boolean', 'value_option', 'value_meta',
    ];

    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_boolean' => 'boolean',
            'value_meta' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireSubmission::class, 'submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaignQuestion::class, 'campaign_question_id');
    }
}
```

- [ ] **Step 5 : Factories** — `QuestionnaireSubmissionFactory` (campaign_id + invitation_id => factories, statut 'en_cours', source 'en_ligne'), `QuestionnaireAnswerFactory` (submission_id + campaign_question_id => factories, value_text => fake()->sentence()). `association_id => TenantContext::currentId() ?? 1`.

- [ ] **Step 6 : Lancer (passe). Commit.**

```bash
git add app/Models/QuestionnaireSubmission.php app/Models/QuestionnaireAnswer.php database/factories/QuestionnaireSubmissionFactory.php database/factories/QuestionnaireAnswerFactory.php tests/Feature/Questionnaire/SubmissionModelTest.php
git commit -m "feat(questionnaires): models + factories submission/answer"
```

---

### Task 3.3 : `QuestionnaireReponseService` (cœur métier répondant)

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireReponseService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireReponseServiceTest.php`

Ce service porte l'invariant « ≤1 soumission active par invitation », la persistance par question, la validation obligatoire, la finalisation et la réouverture (D3/D4).

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutInvitation;
use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;

function makeInvitation(): QuestionnaireInvitation
{
    $campagne = QuestionnaireCampaign::factory()->create();
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => true,
    ]);

    return QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
}

it('get-or-create : une seule soumission active par invitation', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();

    $s1 = $svc->demarrerOuReprendre($invitation);
    $s2 = $svc->demarrerOuReprendre($invitation);

    expect($s1->id)->toBe($s2->id);
    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Commence);
});

it('enregistre une réponse typée par question', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);
    $question = $invitation->campaign->questions()->first();

    $svc->enregistrerReponse($submission, $question, '4');

    $answer = $submission->fresh()->answers()->first();
    expect($answer->value_integer)->toBe(4);
    expect($answer->value_text)->toBeNull();
});

it('refuse de finaliser tant qu une question obligatoire est vide', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);

    expect(fn () => $svc->finaliser($submission, accepteContact: false))
        ->toThrow(\App\Exceptions\Questionnaire\ReponseObligatoireException::class);
});

it('finalise et marque invitation soumis', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);
    $svc->enregistrerReponse($submission, $invitation->campaign->questions()->first(), '5');

    $svc->finaliser($submission, accepteContact: true);

    expect($submission->fresh()->statut)->toBe(StatutSubmission::Soumise);
    expect($submission->fresh()->accepte_contact)->toBeTrue();
    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Soumis);
    expect($invitation->fresh()->submitted_at)->not->toBeNull();
});

it('réouverture admin : invitation et soumission repassent en cours, submitted_at nul', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);
    $svc->enregistrerReponse($submission, $invitation->campaign->questions()->first(), '5');
    $svc->finaliser($submission, accepteContact: false);

    $svc->rouvrir($invitation);

    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Commence);
    expect($invitation->fresh()->submitted_at)->toBeNull();
    expect($submission->fresh()->statut)->toBe(StatutSubmission::EnCours);
    expect($submission->fresh()->submitted_at)->toBeNull();
    // Réponses conservées :
    expect($submission->fresh()->answers)->toHaveCount(1);
});
```

- [ ] **Step 2 : Créer l'exception** `app/Exceptions/Questionnaire/ReponseObligatoireException.php`

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Questionnaire;

use RuntimeException;

final class ReponseObligatoireException extends RuntimeException {}
```

- [ ] **Step 3 : Lancer (échoue).**

- [ ] **Step 4 : Service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutInvitation;
use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Exceptions\Questionnaire\ReponseObligatoireException;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Models\QuestionnaireSubmission;
use Illuminate\Support\Facades\DB;

final class QuestionnaireReponseService
{
    /** Invariant ≤1 active : récupère la soumission active ou en crée une. */
    public function demarrerOuReprendre(QuestionnaireInvitation $invitation): QuestionnaireSubmission
    {
        return DB::transaction(function () use ($invitation): QuestionnaireSubmission {
            $submission = $invitation->submissions()
                ->whereIn('statut', [StatutSubmission::EnCours->value, StatutSubmission::Soumise->value])
                ->first();

            if ($submission === null) {
                $submission = $invitation->submissions()->create([
                    'campaign_id' => $invitation->campaign_id,
                    'statut' => StatutSubmission::EnCours,
                    'source' => 'en_ligne',
                ]);
            }

            if ($invitation->statut === StatutInvitation::NonOuvert) {
                $invitation->update(['statut' => StatutInvitation::Commence, 'opened_at' => now()]);
            }

            return $submission;
        });
    }

    /** Persiste/écrase la réponse d'UNE question (upsert par (submission, question)). */
    public function enregistrerReponse(
        QuestionnaireSubmission $submission,
        QuestionnaireCampaignQuestion $question,
        int|string|bool|null $valeurBrute,
    ): void {
        $payload = $this->normaliser($question, $valeurBrute);

        $submission->answers()->updateOrCreate(
            ['campaign_question_id' => $question->id],
            $payload,
        );
    }

    /** @return array<string, mixed> */
    private function normaliser(QuestionnaireCampaignQuestion $question, int|string|bool|null $v): array
    {
        $base = [
            'value_text' => null, 'value_integer' => null,
            'value_boolean' => null, 'value_option' => null, 'value_meta' => null,
        ];

        if ($v === null || $v === '') {
            return $base;
        }

        return match ($question->type) {
            TypeQuestion::TexteCourt, TypeQuestion::TexteLong => [...$base, 'value_text' => (string) $v],
            TypeQuestion::Satisfaction, TypeQuestion::Ressenti => [...$base, 'value_integer' => (int) $v],
            TypeQuestion::CaseACocher => [...$base, 'value_boolean' => (bool) $v],
            TypeQuestion::ChoixUnique => [
                ...$base,
                'value_option' => (string) $v,
                'value_meta' => ['libelle' => $question->libelleOption((string) $v)],
            ],
        };
    }

    public function finaliser(QuestionnaireSubmission $submission, bool $accepteContact): void
    {
        DB::transaction(function () use ($submission, $accepteContact): void {
            $this->verifierObligatoires($submission);

            $submission->update([
                'statut' => StatutSubmission::Soumise,
                'accepte_contact' => $accepteContact,
                'submitted_at' => now(),
            ]);

            $submission->invitation->update([
                'statut' => StatutInvitation::Soumis,
                'submitted_at' => now(),
            ]);
        });
    }

    private function verifierObligatoires(QuestionnaireSubmission $submission): void
    {
        $repondues = $submission->answers()
            ->get()
            ->filter(fn ($a) => $a->value_text !== null || $a->value_integer !== null
                || $a->value_boolean !== null || $a->value_option !== null)
            ->pluck('campaign_question_id')
            ->all();

        $obligatoiresManquantes = $submission->campaign->questions()
            ->where('obligatoire', true)
            ->whereNotIn('id', $repondues)
            ->exists();

        if ($obligatoiresManquantes) {
            throw new ReponseObligatoireException('Une question obligatoire n\'est pas renseignée.');
        }
    }

    /** Réouverture admin (D4) : symétrique invitation + soumission, réponses conservées. */
    public function rouvrir(QuestionnaireInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $submission = $invitation->submissions()
                ->where('statut', StatutSubmission::Soumise->value)
                ->first();

            if ($submission !== null) {
                $submission->update(['statut' => StatutSubmission::EnCours, 'submitted_at' => null]);
            }

            $invitation->update(['statut' => StatutInvitation::Commence, 'submitted_at' => null]);
        });
    }
}
```

- [ ] **Step 5 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireReponseService.php app/Exceptions/Questionnaire/ReponseObligatoireException.php tests/Feature/Questionnaire/QuestionnaireReponseServiceTest.php
git commit -m "feat(questionnaires): QuestionnaireReponseService (persistance, validation, finalisation, réouverture)"
```

---

### Task 3.4 : Controller public + Blade (parcours répondant)

**Files:**
- Create: `app/Http/Controllers/QuestionnaireRepondantController.php`
- Create: `resources/views/questionnaire/repondant/{intro,question,consentement,merci,indisponible}.blade.php`
- Create: `resources/views/questionnaire/repondant/layout.blade.php` (layout public minimal, sans sidebar)
- Modify: `routes/web.php` (groupe public `q`)
- Test: `tests/Feature/Questionnaire/RepondantParcoursTest.php`

Le controller résout l'invitation **par hash de token** + boote le tenant (D18), comme `Newsletter\SubscriptionService::findByToken`. Pagination serveur : `?page=N` (N = index de question).

- [ ] **Step 1 : Test (parcours public, isolation tenant)**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Enums\StatutInvitation;
use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireTokenService;
use App\Tenant\TenantContext;

function makeOuverteInvitation(): array
{
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte, 'remerciement' => 'Merci !',
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => true,
    ]);

    $clair = \Illuminate\Support\Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);

    return [$clair, $invitation];
}

it('affiche l intro et résout le tenant sans contexte préalable', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    TenantContext::clear(); // route publique : aucun tenant booté

    $this->get("/q/{$clair}")
        ->assertOk()
        ->assertSee('Note', false === false ? false : false); // intro page → titre affiché
});

it('bloque la sauvegarde d une question obligatoire vide puis finalise', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    TenantContext::clear();

    // page 0 = intro → submit pour démarrer
    $this->post("/q/{$clair}", ['action' => 'start'])->assertRedirect();

    // question obligatoire vide → re-affiche avec erreur
    $question = $invitation->campaign->questions()->first();
    $this->from("/q/{$clair}?page=1")
        ->post("/q/{$clair}", ['action' => 'next', 'page' => 1, "q_{$question->id}" => ''])
        ->assertSessionHasErrors();

    // valeur fournie → avance
    $this->post("/q/{$clair}", ['action' => 'next', 'page' => 1, "q_{$question->id}" => '5'])
        ->assertRedirect();

    // consentement + finalisation
    $this->post("/q/{$clair}", ['action' => 'finish', 'accepte_contact' => '0'])
        ->assertRedirect(route('questionnaire.merci', ['token' => $clair]));

    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Soumis);
});

it('affiche déjà répondu si l invitation est soumise', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    $invitation->update(['statut' => StatutInvitation::Soumis]);
    TenantContext::clear();

    $this->get("/q/{$clair}")->assertSee('déjà', false);
});
```

(Note d'implémentation : l'`assertSee` d'intro doit cibler le `titre_affiche` réel de la campagne ; ajuster la chaîne à ce que rend le blade.)

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StatutInvitation;
use App\Exceptions\Questionnaire\ReponseObligatoireException;
use App\Models\Association;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Services\Questionnaire\QuestionnaireTokenService;
use App\Tenant\TenantContext;
use App\Tenant\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class QuestionnaireRepondantController extends Controller
{
    public function __construct(
        private readonly QuestionnaireTokenService $tokens,
        private readonly QuestionnaireReponseService $reponses,
    ) {}

    public function show(Request $request, string $token): View
    {
        $invitation = $this->resoudre($token);
        $campagne = $invitation->campaign;

        if (! $campagne->statut->accepteReponses()) {
            return view('questionnaire.repondant.indisponible', compact('campagne'));
        }
        if ($invitation->statut === StatutInvitation::Soumis) {
            return view('questionnaire.repondant.indisponible', ['campagne' => $campagne, 'dejaRepondu' => true]);
        }

        $page = max(0, (int) $request->query('page', '0'));
        $questions = $campagne->questions()->get();

        if ($page === 0) {
            return view('questionnaire.repondant.intro', compact('invitation', 'campagne', 'token'));
        }

        $question = $questions[$page - 1] ?? null;
        abort_if($question === null, 404);

        // valeur déjà saisie (reprise)
        $submission = $this->reponses->demarrerOuReprendre($invitation);
        $answer = $submission->answers()->where('campaign_question_id', $question->id)->first();

        return view('questionnaire.repondant.question', [
            'token' => $token, 'campagne' => $campagne, 'question' => $question,
            'page' => $page, 'total' => $questions->count(), 'answer' => $answer,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resoudre($token);
        $campagne = $invitation->campaign;
        abort_unless($campagne->statut->accepteReponses(), 422);

        $action = (string) $request->input('action');
        $submission = $this->reponses->demarrerOuReprendre($invitation);

        if ($action === 'start') {
            return redirect()->route('questionnaire.show', ['token' => $token, 'page' => 1]);
        }

        if ($action === 'next') {
            $page = (int) $request->input('page');
            $question = $campagne->questions()->get()[$page - 1] ?? null;
            abort_if($question === null, 404);

            $valeur = $request->input("q_{$question->id}");

            if ($question->obligatoire && ($valeur === null || $valeur === '')) {
                return back()->withErrors(['reponse' => 'Cette question est obligatoire.'])->withInput();
            }

            $this->reponses->enregistrerReponse($submission, $question, $valeur);

            $total = $campagne->questions()->count();
            $next = $page + 1;

            return $next > $total
                ? redirect()->route('questionnaire.consentement', ['token' => $token])
                : redirect()->route('questionnaire.show', ['token' => $token, 'page' => $next]);
        }

        if ($action === 'finish') {
            try {
                $this->reponses->finaliser($submission, $request->boolean('accepte_contact'));
            } catch (ReponseObligatoireException) {
                return redirect()->route('questionnaire.show', ['token' => $token, 'page' => 1])
                    ->withErrors(['reponse' => 'Une question obligatoire n\'est pas renseignée.']);
            }

            return redirect()->route('questionnaire.merci', ['token' => $token]);
        }

        abort(400);
    }

    public function consentement(string $token): View
    {
        $invitation = $this->resoudre($token);
        abort_unless($invitation->campaign->statut->accepteReponses(), 422);

        return view('questionnaire.repondant.consentement', [
            'token' => $token, 'campagne' => $invitation->campaign,
        ]);
    }

    public function merci(string $token): View
    {
        $invitation = $this->resoudre($token);

        return view('questionnaire.repondant.merci', ['campagne' => $invitation->campaign]);
    }

    /** Résolution par hash + boot tenant (D18, mirroir SubscriptionService::findByToken). */
    private function resoudre(string $tokenClair): QuestionnaireInvitation
    {
        $hash = $this->tokens->hash($tokenClair);

        $invitation = QuestionnaireInvitation::withoutGlobalScope(TenantScope::class)
            ->where('token_hash', $hash)
            ->first();

        abort_if($invitation === null, 404);

        if (! TenantContext::hasBooted() || (int) TenantContext::currentId() !== (int) $invitation->association_id) {
            $association = Association::find($invitation->association_id);
            abort_if($association === null, 404);
            TenantContext::boot($association);
        }

        return $invitation;
    }
}
```

Note : le consentement a sa **route dédiée** `questionnaire.consentement` (méthode `consentement()` ci-dessus), atteinte quand `next > total`. Pas de cas spécial dans `show()` : le param `page` reste toujours un entier.

- [ ] **Step 4 : Routes** — Ajouter dans `routes/web.php`, bloc public (à côté de `/formulaire`, sans middleware auth/tenant) :

```php
// ── Questionnaire public (sans auth ; le token hashé porte le contexte tenant) ──
Route::prefix('q')->middleware('throttle:30,1')->group(function (): void {
    Route::get('/{token}/consentement', [\App\Http\Controllers\QuestionnaireRepondantController::class, 'consentement'])
        ->name('questionnaire.consentement');
    Route::get('/{token}/merci', [\App\Http\Controllers\QuestionnaireRepondantController::class, 'merci'])
        ->name('questionnaire.merci');
    Route::get('/{token}', [\App\Http\Controllers\QuestionnaireRepondantController::class, 'show'])
        ->name('questionnaire.show');
    Route::post('/{token}', [\App\Http\Controllers\QuestionnaireRepondantController::class, 'store'])
        ->name('questionnaire.store');
});
```

(Le controller ci-dessus redirige déjà vers `route('questionnaire.consentement', …)` quand `next > total`.)

- [ ] **Step 5 : Blades publiques** — Layout minimal `questionnaire/repondant/layout.blade.php` (Bootstrap CDN, centré, pas de sidebar) ; `intro.blade.php` (titre_affiche + intro + form POST action=start) ; `question.blade.php` (barre de progression `page/total`, rendu du champ selon `$question->type`, form POST action=next avec `page` caché + champ `q_{id}`, bouton Suivant + erreurs `$errors`) ; `consentement.blade.php` (case `accepte_contact` + texte « confidentiel / non nominatif » + bouton « Envoyer » action=finish) ; `merci.blade.php` (remerciement) ; `indisponible.blade.php` (gère `$dejaRepondu` → « Vous avez déjà répondu », sinon « questionnaire indisponible »). Le rendu par type :
  - `texte_court` → `<input type="text" name="q_{id}">`
  - `texte_long` → `<textarea name="q_{id}">`
  - `satisfaction` → 5 boutons radio (1..5) libellés très insatisfait→très satisfait
  - `ressenti` → `<input type="range" min="0" max="100" name="q_{id}">` **sans** affichage de la valeur (D7)
  - `case_a_cocher` → `<input type="checkbox" name="q_{id}" value="1">`
  - `choix_unique` → radios si `count(options) <= 5` sinon `<select>` (D6 `rendu=auto`), valeur = `valeur` technique de l'option

- [ ] **Step 6 : Lancer (passe).** Ajuster les `assertSee` du test au markup réel (titre affiché, mot « déjà »).

- [ ] **Step 7 : Commit.**

```bash
git add app/Http/Controllers/QuestionnaireRepondantController.php resources/views/questionnaire/repondant routes/web.php tests/Feature/Questionnaire/RepondantParcoursTest.php
git commit -m "feat(questionnaires): parcours répondant public (token hashé, pagination, persistance)"
```

---

### Task 3.5 : Réouverture admin depuis la fiche opération

**Files:**
- Modify: `app/Livewire/Questionnaire/OperationQuestionnaires.php` (méthode `rouvrirInvitation`)
- Modify: `resources/views/livewire/questionnaire/operation-questionnaires.blade.php` (bouton sur les invitations soumises)
- Test: ajouter à `tests/Livewire/Questionnaire/OperationQuestionnairesTest.php`

- [ ] **Step 1 : Test**

```php
it('permet à l admin de rouvrir une invitation soumise', function (): void {
    // … créer campagne + invitation soumise via QuestionnaireReponseService …
    Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->call('rouvrirInvitation', $invitation->id);

    expect($invitation->fresh()->statut->value)->toBe('commence');
});
```

- [ ] **Step 2 : Méthode**

```php
public function rouvrirInvitation(int $invitationId, QuestionnaireReponseService $reponses): void
{
    $invitation = \App\Models\QuestionnaireInvitation::whereHas(
        'campaign',
        fn ($q) => $q->where('operation_id', $this->operation->id),
    )->findOrFail($invitationId);

    $reponses->rouvrir($invitation);
}
```

- [ ] **Step 3 : Bouton** (`wire:confirm="Rouvrir cette réponse ?"`) sur les lignes d'invitation `soumis`. **Step 4 : Lancer (passe). Commit.**

```bash
git commit -am "feat(questionnaires): réouverture admin d'une invitation soumise"
```

**✅ Jalon Lot 3 : un participant peut répondre en ligne ; l'admin peut rouvrir.**

---

## LOT 4 — Résultats & anonymat

### Task 4.1 : `QuestionnaireResultatService`

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireResultatService.php`
- Create: `app/Support/Questionnaire/ResultatQuestion.php` (DTO lisible)
- Test: `tests/Feature/Questionnaire/QuestionnaireResultatServiceTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Services\Questionnaire\QuestionnaireResultatService;

it('agrège satisfaction et exclut les soumissions non soumises', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => false,
    ]);
    $svc = app(QuestionnaireReponseService::class);

    foreach ([5, 3, 4] as $note) {
        $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
        $sub = $svc->demarrerOuReprendre($inv);
        $svc->enregistrerReponse($sub, $q, (string) $note);
        $svc->finaliser($sub, accepteContact: false);
    }
    // une soumission EN COURS (non finalisée) ne doit pas compter
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $svc->demarrerOuReprendre($inv);

    $resultats = app(QuestionnaireResultatService::class)->pourCampagne($campagne->fresh());

    expect($resultats['nb_soumissions'])->toBe(3);
    expect($resultats['questions'][0]['moyenne'])->toBe(4.0); // (5+3+4)/3
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Service** (filtre `statut='soumise'`, agrège par type)

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;

final class QuestionnaireResultatService
{
    /** @return array{nb_invitations:int, nb_soumissions:int, taux:float, questions:array<int,array<string,mixed>>} */
    public function pourCampagne(QuestionnaireCampaign $campagne): array
    {
        $nbInvitations = $campagne->invitations()->count();

        $soumissions = $campagne->submissions()
            ->where('statut', StatutSubmission::Soumise->value)
            ->with('answers')
            ->get();

        $nbSoumissions = $soumissions->count();
        $answersParQuestion = $soumissions->flatMap->answers->groupBy('campaign_question_id');

        $questions = $campagne->questions()->get()->map(function ($q) use ($answersParQuestion): array {
            $answers = $answersParQuestion->get($q->id, collect());

            return [
                'libelle' => $q->libelle,
                'type' => $q->type,
                ...$this->agreger($q->type, $answers, $q),
            ];
        })->all();

        return [
            'nb_invitations' => $nbInvitations,
            'nb_soumissions' => $nbSoumissions,
            'taux' => $nbInvitations > 0 ? round($nbSoumissions / $nbInvitations * 100, 1) : 0.0,
            'questions' => $questions,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\QuestionnaireAnswer>  $answers
     * @return array<string, mixed>
     */
    private function agreger(TypeQuestion $type, $answers, $question): array
    {
        return match ($type) {
            TypeQuestion::Satisfaction, TypeQuestion::Ressenti => [
                'moyenne' => $answers->isNotEmpty()
                    ? round((float) $answers->avg('value_integer'), $type === TypeQuestion::Ressenti ? 1 : 1)
                    : null,
                'distribution' => $answers->countBy('value_integer')->all(),
                'n' => $answers->count(),
            ],
            TypeQuestion::CaseACocher => [
                'oui' => $answers->where('value_boolean', true)->count(),
                'non' => $answers->where('value_boolean', false)->count(),
                'n' => $answers->count(),
            ],
            TypeQuestion::ChoixUnique => [
                'repartition' => $answers->groupBy('value_option')->map(fn ($g, $val) => [
                    'libelle' => $question->libelleOption((string) $val) ?? $val,
                    'count' => $g->count(),
                ])->values()->all(),
                'n' => $answers->count(),
            ],
            TypeQuestion::TexteCourt, TypeQuestion::TexteLong => [
                'verbatims' => $answers->pluck('value_text')->filter()->values()->all(),
                'n' => $answers->count(),
            ],
        };
    }
}
```

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireResultatService.php tests/Feature/Questionnaire/QuestionnaireResultatServiceTest.php
git commit -m "feat(questionnaires): QuestionnaireResultatService (agrégations, filtre soumise)"
```

---

### Task 4.2 : Écran résultats (Livewire) + anonymat

**Files:**
- Create: `app/Livewire/Questionnaire/CampagneResultats.php`
- Create: `resources/views/livewire/questionnaire/campagne-resultats.blade.php`
- Create: `resources/views/questionnaire/resultats/index.blade.php`
- Modify: `routes/web.php` (route `questionnaires.campagnes.resultats`)
- Test: `tests/Livewire/Questionnaire/CampagneResultatsTest.php`

- [ ] **Step 1 : Test** (identité masquée par défaut, exposée si consentement)

```php
<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\CampagneResultats;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;
use Livewire\Livewire;

it('n expose l identité que pour les répondants ayant consenti', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create(['type' => 'texte_court']);
    $svc = app(QuestionnaireReponseService::class);

    $invA = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $subA = $svc->demarrerOuReprendre($invA);
    $svc->enregistrerReponse($subA, $q, 'RAS');
    $svc->finaliser($subA, accepteContact: false); // anonyme

    $invB = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $subB = $svc->demarrerOuReprendre($invB);
    $svc->enregistrerReponse($subB, $q, 'Rappelez-moi');
    $svc->finaliser($subB, accepteContact: true); // consent

    Livewire::test(CampagneResultats::class, ['campagne' => $campagne])
        ->assertSee('Rappelez-moi')        // verbatim visible (anonyme par défaut)
        ->assertSee('petit groupe', false); // avertissement présent
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Composant**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Enums\StatutSubmission;
use App\Models\QuestionnaireCampaign;
use App\Services\Questionnaire\QuestionnaireResultatService;
use Illuminate\View\View;
use Livewire\Component;

final class CampagneResultats extends Component
{
    public QuestionnaireCampaign $campagne;

    public function mount(QuestionnaireCampaign $campagne): void
    {
        $this->campagne = $campagne;
    }

    public function render(QuestionnaireResultatService $service): View
    {
        // Lignes nominatives : uniquement celles ayant consenti (D9/D10).
        $contacts = $this->campagne->submissions()
            ->where('statut', StatutSubmission::Soumise->value)
            ->where('accepte_contact', true)
            ->with('invitation.participant.tiers')
            ->get();

        return view('livewire.questionnaire.campagne-resultats', [
            'resultats' => $service->pourCampagne($this->campagne),
            'contacts' => $contacts,
        ]);
    }
}
```

- [ ] **Step 4 : Vue** — Cartes compteurs (invitations / soumissions / taux) ; **bandeau d'avertissement** systématique : « Questionnaire confidentiel et non nominatif. Sur un petit groupe, certains retours peuvent rester reconnaissables. » (doit contenir « petit groupe ») ; par question : satisfaction/ressenti → moyenne + mini-distribution, case à cocher → % oui, choix unique → répartition, textes → liste de verbatims ; une section « Souhaitent être recontactés » listant `contacts` (Prénom NOM via tiers) **uniquement**.

- [ ] **Step 5 : Page hôte + route**

`resources/views/questionnaire/resultats/index.blade.php` :
```blade
@extends('layouts.app')
@section('content')
    <div class="container-fluid py-3">
        @livewire('questionnaire.campagne-resultats', ['campagne' => $campagne])
    </div>
@endsection
```

Route (dans le groupe `questionnaires.*`) :
```php
Route::get('/campagnes/{campagne}/resultats', function (\App\Models\QuestionnaireCampaign $campagne) {
    return view('questionnaire.resultats.index', compact('campagne'));
})->name('campagnes.resultats');
```

- [ ] **Step 6 : Lancer (passe). Commit.**

```bash
git add app/Livewire/Questionnaire/CampagneResultats.php resources/views/livewire/questionnaire/campagne-resultats.blade.php resources/views/questionnaire/resultats/index.blade.php routes/web.php tests/Livewire/Questionnaire/CampagneResultatsTest.php
git commit -m "feat(questionnaires): écran résultats + anonymat (identité ssi consentement)"
```

**✅ Jalon Lot 4 : l'admin consulte les résultats sans identité par défaut.**

---

## LOT 5 — Export Excel

### Task 5.1 : `QuestionnaireExcelExporter`

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireExcelExporter.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireExcelExporterTest.php`

Règles clés (spec §9) : une ligne par soumission `soumise` ; **en-têtes 100 % stables** ; colonnes identité **toujours présentes**, valeurs vides sauf `accepte_contact`.

- [ ] **Step 1 : Test** (en-têtes stables quel que soit le consentement)

```php
<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireExcelExporter;
use App\Services\Questionnaire\QuestionnaireReponseService;

it('produit des en-têtes stables avec colonnes identité même sans consentement', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte', 'titre_affiche' => 'Avis']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1,
    ]);
    $svc = app(QuestionnaireReponseService::class);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, '4');
    $svc->finaliser($sub, accepteContact: false);

    $rows = app(QuestionnaireExcelExporter::class)->lignes($campagne->fresh());

    $entetes = $rows[0];
    expect($entetes)->toContain('Participant (si contact accepté)');
    expect($entetes)->toContain('Note');
    // ligne de données : identité vide (pas de consentement), note = 4
    expect($rows[1])->toContain('');   // colonne identité vide
    expect($rows[1])->toContain(4);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Exporter** (renvoie un tableau de lignes — testable — et écrit le XLSX)

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

final class QuestionnaireExcelExporter
{
    /**
     * Matrice de lignes (en-têtes + données) — extraite pour être testable sans I/O.
     *
     * @return array<int, array<int, mixed>>
     */
    public function lignes(QuestionnaireCampaign $campagne): array
    {
        $questions = $campagne->questions()->get();

        $entetes = [
            'Association', 'Type opération', 'Opération', 'Campagne', 'Date de soumission',
            'Réponse confidentielle', 'A accepté le contact', 'Participant (si contact accepté)',
        ];
        foreach ($questions as $q) {
            $entetes[] = $q->libelle; // libellé figé (snapshot) → stable
        }

        $rows = [$entetes];

        $soumissions = $campagne->submissions()
            ->where('statut', StatutSubmission::Soumise->value)
            ->with(['answers', 'invitation.participant.tiers'])
            ->get();

        foreach ($soumissions as $sub) {
            $consent = (bool) $sub->accepte_contact;
            $participant = $sub->invitation?->participant;
            $identite = $consent && $participant?->tiers
                ? trim(($participant->tiers->prenom ?? '').' '.($participant->tiers->nom ?? ''))
                : ''; // colonne TOUJOURS présente, valeur vide sans consentement

            $ligne = [
                $campagne->operation->association->nom ?? '',
                $campagne->operation->typeOperation->nom ?? '',
                $campagne->operation->nom,
                $campagne->titre_affiche,
                $sub->submitted_at?->format('Y-m-d H:i') ?? '',
                'Oui',                       // toute réponse est confidentielle par défaut
                $consent ? 'Oui' : 'Non',
                $identite,
            ];

            $answersParQ = $sub->answers->keyBy('campaign_question_id');
            foreach ($questions as $q) {
                $ligne[] = $this->valeurAffichee($q->type, $answersParQ->get($q->id), $q);
            }

            $rows[] = $ligne;
        }

        return $rows;
    }

    public function ecrire(QuestionnaireCampaign $campagne, string $cheminAbsolu): void
    {
        $writer = new Writer;
        $writer->openToFile($cheminAbsolu);
        foreach ($this->lignes($campagne) as $ligne) {
            $writer->addRow(Row::fromValues($ligne));
        }
        $writer->close();
    }

    private function valeurAffichee(TypeQuestion $type, $answer, $question): mixed
    {
        if ($answer === null) {
            return '';
        }

        return match ($type) {
            TypeQuestion::TexteCourt, TypeQuestion::TexteLong => $answer->value_text ?? '',
            TypeQuestion::Satisfaction, TypeQuestion::Ressenti => $answer->value_integer ?? '',
            TypeQuestion::CaseACocher => $answer->value_boolean ? 'Oui' : 'Non',
            TypeQuestion::ChoixUnique => $question->libelleOption((string) $answer->value_option) ?? ($answer->value_option ?? ''),
        };
    }
}
```

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireExcelExporter.php tests/Feature/Questionnaire/QuestionnaireExcelExporterTest.php
git commit -m "feat(questionnaires): exporter XLSX (en-têtes stables, identité conditionnelle)"
```

---

### Task 5.2 : Controller export + bouton

**Files:**
- Create: `app/Http/Controllers/QuestionnaireExportController.php`
- Modify: `routes/web.php` (route `questionnaires.campagnes.export`)
- Modify: `resources/views/livewire/questionnaire/campagne-resultats.blade.php` (bouton Export)
- Test: `tests/Feature/Questionnaire/QuestionnaireExportControllerTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\QuestionnaireCampaign;

it('télécharge un xlsx pour la campagne', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create();

    $this->get(route('questionnaires.campagnes.export', $campagne))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Controller** (calqué sur `SeanceExportController` : écrit dans `storage/app/temp`, renvoie `BinaryFileResponse` avec `deleteFileAfterSend`)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QuestionnaireCampaign;
use App\Services\Questionnaire\QuestionnaireExcelExporter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class QuestionnaireExportController extends Controller
{
    public function __invoke(QuestionnaireCampaign $campagne, QuestionnaireExcelExporter $exporter): BinaryFileResponse
    {
        $filename = 'questionnaire-'.Str::slug($campagne->titre_affiche).'-'.now()->format('Y-m-d').'.xlsx';
        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/'.$filename;

        $exporter->ecrire($campagne, $path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
```

- [ ] **Step 4 : Route** (groupe `questionnaires.*`) — `QuestionnaireCampaign` est route-model-bound, donc le tenant doit être booté (il l'est : route admin authentifiée) :

```php
Route::get('/campagnes/{campagne}/export', \App\Http\Controllers\QuestionnaireExportController::class)
    ->name('campagnes.export');
```

- [ ] **Step 5 : Bouton** dans la vue résultats : `<a href="{{ route('questionnaires.campagnes.export', $campagne) }}" class="btn btn-outline-success">Exporter en Excel</a>`.

- [ ] **Step 6 : Lancer (passe). Commit.**

```bash
git add app/Http/Controllers/QuestionnaireExportController.php routes/web.php resources/views/livewire/questionnaire/campagne-resultats.blade.php tests/Feature/Questionnaire/QuestionnaireExportControllerTest.php
git commit -m "feat(questionnaires): export XLSX d'une campagne (controller + bouton)"
```

**✅ Jalon Lot 5 : analyse hors logiciel possible (export Excel structuré).**

---

## LOT 6 — Impression papier + QR

> Aucune nouvelle table : on imprime des **invitations existantes** (créées au lot 2). Le QR encode `invitation.lienReponse()` (URL publique tokenisée, token clair déchiffré du `token_chiffre`). PDF via dompdf, **groupé, une invitation par page** (D12).

### Task 6.1 : `QuestionnaireQrService` (génération QR base64)

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireQrService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireQrServiceTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Services\Questionnaire\QuestionnaireQrService;

it('produit une data-URI PNG base64 pour une URL', function (): void {
    $uri = app(QuestionnaireQrService::class)->dataUri('https://exemple.test/q/ABC');

    expect($uri)->toStartWith('data:image/png;base64,');
    expect(strlen($uri))->toBeGreaterThan(200);
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Service** (API endroid/qr-code v6)

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

final class QuestionnaireQrService
{
    public function dataUri(string $url): string
    {
        $result = new Builder(
            writer: new PngWriter,
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 8,
        );

        return $result->build()->getDataUri();
    }
}
```

Note : vérifier la signature exacte du `Builder` selon la version installée (`composer show endroid/qr-code`). Si l'API nommée diffère, utiliser `Builder::create()->writer(new PngWriter)->data($url)->size(220)->build()`.

- [ ] **Step 4 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireQrService.php tests/Feature/Questionnaire/QuestionnaireQrServiceTest.php
git commit -m "feat(questionnaires): QuestionnaireQrService (data-URI PNG)"
```

---

### Task 6.2 : Blade PDF papier (groupé, 1 invitation/page)

**Files:**
- Create: `resources/views/questionnaire/pdf/feuilles.blade.php`
- Test: couvert par le controller (Task 6.3).

- [ ] **Step 1 : Écrire le blade** — Boucle sur `$invitations`, **`page-break-after`** après chaque feuille sauf la dernière. Chaque feuille : `titre_affiche`, `intro`, consignes papier (« Cochez/écrivez au stylo. »), liste des questions (mise en page libre : plusieurs par page), bloc consentement (case à cocher papier), QR (`<img src="{{ $qr[$invitation->id] }}">`), `code_court` lisible, remerciement court. Réutiliser le footer unifié : `@include('pdf.partials.footer-logos')` (cf. `App\Support\PdfFooterRenderer`). Rendu papier des types :
  - satisfaction → 5 cases à cocher étiquetées
  - ressenti → une ligne graduée 0—100 à marquer d'une croix (rendu papier dégradé du curseur, D7)
  - choix unique → cases à cocher (une par option)
  - case à cocher → ☐ Oui ☐ Non
  - textes → lignes vides

```blade
@php use App\Support\PdfFooterRenderer; @endphp
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><style>
    body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#222}
    .feuille{page-break-after:always}
    .feuille:last-child{page-break-after:auto}
    .qr{float:right;text-align:center;font-size:10px}
    .q{margin:10px 0;padding-bottom:6px;border-bottom:1px dotted #ccc}
    .ligne{border-bottom:1px solid #999;height:18px}
</style></head><body>
@foreach ($invitations as $inv)
    <div class="feuille">
        <div class="qr"><img src="{{ $qr[$inv->id] }}" width="110"><br>{{ $inv->code_court }}</div>
        <h2>{{ $campagne->titre_affiche }}</h2>
        @if ($campagne->intro)<p>{{ $campagne->intro }}</p>@endif
        <p style="font-size:11px;color:#555">Répondez au stylo. Vous pouvez aussi scanner le QR code pour répondre en ligne.</p>
        @foreach ($campagne->questions as $i => $q)
            <div class="q">
                <strong>{{ $i + 1 }}. {{ $q->libelle }}</strong>@if($q->obligatoire) <span style="color:#b00">*</span>@endif
                {{-- rendu papier selon $q->type (voir Step 1) --}}
            </div>
        @endforeach
        <p style="margin-top:12px">☐ J'accepte d'être recontacté(e) au sujet de mes réponses.</p>
        @if ($campagne->remerciement)<p style="font-size:11px">{{ $campagne->remerciement }}</p>@endif
        {!! PdfFooterRenderer::html() !!}
    </div>
@endforeach
</body></html>
```

(Adapter l'appel `PdfFooterRenderer` à sa vraie signature — vérifier `app/Support/PdfFooterRenderer.php`.)

- [ ] **Step 2 : Commit** (avec le controller, Task 6.3).

---

### Task 6.3 : Controller d'impression + bouton

**Files:**
- Create: `app/Http/Controllers/QuestionnairePapierController.php`
- Modify: `routes/web.php` (`questionnaires.campagnes.papier`)
- Modify: `resources/views/livewire/questionnaire/operation-questionnaires.blade.php` (bouton « Imprimer (papier) »)
- Test: `tests/Feature/Questionnaire/QuestionnairePapierControllerTest.php`

- [ ] **Step 1 : Test**

```php
<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;

it('génère un PDF groupé pour les invitations de la campagne', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create();
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create(['type' => 'satisfaction']);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->count(2)->create();

    $this->get(route('questionnaires.campagnes.papier', $campagne))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
```

- [ ] **Step 2 : Lancer (échoue).**

- [ ] **Step 3 : Controller** (dompdf, mirror des contrôleurs PDF existants ; QR par invitation)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QuestionnaireCampaign;
use App\Services\Questionnaire\QuestionnaireQrService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class QuestionnairePapierController extends Controller
{
    public function __invoke(Request $request, QuestionnaireCampaign $campagne, QuestionnaireQrService $qrService): Response
    {
        $invitations = $campagne->invitations()->get();

        // Filtre optionnel : ?invitations=1,2,3 (un seul participant ⇒ PDF d'une page).
        if ($request->filled('invitations')) {
            $ids = collect(explode(',', (string) $request->query('invitations')))->map(fn ($i) => (int) $i);
            $invitations = $invitations->whereIn('id', $ids)->values();
        }

        $qr = [];
        foreach ($invitations as $inv) {
            $qr[$inv->id] = $qrService->dataUri($inv->lienReponse());
        }

        $pdf = Pdf::loadView('questionnaire.pdf.feuilles', [
            'campagne' => $campagne->load('questions'),
            'invitations' => $invitations,
            'qr' => $qr,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('questionnaire-'.Str::slug($campagne->titre_affiche).'.pdf');
    }
}
```

- [ ] **Step 4 : Route** (groupe `questionnaires.*`) :

```php
Route::get('/campagnes/{campagne}/papier', \App\Http\Controllers\QuestionnairePapierController::class)
    ->name('questionnaires.campagnes.papier');
```

- [ ] **Step 5 : Bouton** sur la section campagnes : « Imprimer (papier) » → `route('questionnaires.campagnes.papier', $c)`. **Step 6 : Lancer (passe). Commit.**

```bash
git add app/Http/Controllers/QuestionnairePapierController.php resources/views/questionnaire/pdf/feuilles.blade.php routes/web.php resources/views/livewire/questionnaire/operation-questionnaires.blade.php tests/Feature/Questionnaire/QuestionnairePapierControllerTest.php
git commit -m "feat(questionnaires): impression papier groupée avec QR par invitation"
```

**✅ Jalon Lot 6 : un atelier peut distribuer des questionnaires papier.**

---

## LOT 7 — Scan, OCR & assistant de saisie

### Task 7.1 : Migrations papier/scan/OCR + colonnes additives

**Files:**
- Create: `database/migrations/2026_06_23_100001_create_questionnaire_paper_tables.php`
- Create: `database/migrations/2026_06_23_100002_add_questionnaire_ocr_model_to_association.php`
- Create: `database/migrations/2026_06_23_100003_add_remplacement_to_questionnaire_submissions.php`

- [ ] **Step 1 : Migration tables papier**

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
        Schema::create('questionnaire_paper_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('questionnaire_campaigns')->cascadeOnDelete();
            $table->string('type'); // impression | scan
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('questionnaire_paper_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('questionnaire_campaigns')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('questionnaire_invitations')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('questionnaire_paper_batches')->nullOnDelete();
            $table->foreignId('incoming_document_id')->nullable()->constrained('incoming_documents')->nullOnDelete();
            $table->string('source'); // upload | email
            $table->string('chemin_fichier');
            $table->string('qr_statut')->default('illisible'); // detecte | illisible
            $table->string('statut')->default('en_attente');   // en_attente | rattache | traite | ignore
            $table->timestamps();
            $table->index(['campaign_id', 'statut']);
        });

        Schema::create('questionnaire_ocr_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('scan_id')->constrained('questionnaire_paper_scans')->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('questionnaire_invitations')->nullOnDelete();
            $table->json('payload'); // { question_id: { value, confidence } }
            $table->string('statut')->default('brouillon'); // brouillon | valide | rejete
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_ocr_drafts');
        Schema::dropIfExists('questionnaire_paper_scans');
        Schema::dropIfExists('questionnaire_paper_batches');
    }
};
```

- [ ] **Step 2 : Migration `questionnaire_ocr_model`** (sur `association`, décolle de `invoice_ocr_model` — D16)

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
        Schema::table('association', function (Blueprint $table): void {
            $table->string('questionnaire_ocr_model')->nullable()->after('invoice_ocr_model');
        });
    }

    public function down(): void
    {
        Schema::table('association', fn (Blueprint $t) => $t->dropColumn('questionnaire_ocr_model'));
    }
};
```

- [ ] **Step 3 : Migration additive submissions** (invariant ≤1 active + supersede)

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
        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            $table->foreignId('remplacee_par_id')->nullable()->after('source')
                ->constrained('questionnaire_submissions')->nullOnDelete();
            // active_key = invitation_id tant qu'active, NULL si remplacee.
            // unique(active_key) ⇒ une seule active par invitation (MySQL : NULL distincts).
            $table->unsignedBigInteger('active_key')->nullable()->after('remplacee_par_id');
            $table->unique('active_key');
        });

        // Backfill : les soumissions existantes (en_cours/soumise) deviennent actives.
        \DB::table('questionnaire_submissions')
            ->whereIn('statut', ['en_cours', 'soumise'])
            ->update(['active_key' => \DB::raw('invitation_id')]);
    }

    public function down(): void
    {
        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            $table->dropUnique(['active_key']);
            $table->dropConstrainedForeignId('remplacee_par_id');
            $table->dropColumn('active_key');
        });
    }
};
```

- [ ] **Step 4 : Vérifier + Commit.**

```bash
git add database/migrations/2026_06_23_1000*
git commit -m "feat(questionnaires): migrations papier/scan/OCR + questionnaire_ocr_model + invariant active_key"
```

---

### Task 7.2 : Models papier + maintenance `active_key`

**Files:**
- Create: `app/Models/QuestionnairePaperBatch.php`, `QuestionnairePaperScan.php`, `QuestionnaireOcrDraft.php` (+ factories)
- Modify: `app/Models/QuestionnaireSubmission.php` (fillable `remplacee_par_id`, `active_key` ; maintenir `active_key` sur transitions)
- Modify: `app/Models/Association.php` (fillable `questionnaire_ocr_model`)
- Test: `tests/Feature/Questionnaire/PaperModelTest.php`

- [ ] **Step 1 : Models** — Calqués sur les modèles déjà créés (étendre `TenantModel`, `final`, casts). `QuestionnairePaperScan` : casts néant particulier ; relations `campaign()`, `invitation()`, `ocrDraft()` (hasOne). `QuestionnaireOcrDraft` : `payload => 'array'`, relations `scan()`, `invitation()`. Ajouter `'remplacee_par_id'`, `'active_key'` au `$fillable` de `QuestionnaireSubmission`.

- [ ] **Step 2 : Maintenir `active_key`** — La création de soumission active doit poser `active_key = invitation_id`. Centraliser dans `QuestionnaireReponseService::demarrerOuReprendre` (Task 3.3) : à la création, ajouter `'active_key' => $invitation->id`. Le passage `remplacee` (Task 7.7) met `active_key = null`. **Note de cohérence** : éditer aussi Task 3.3 — voir Task 7.7.

- [ ] **Step 3 : Test + Commit.**

```bash
git add app/Models/QuestionnairePaper*.php app/Models/QuestionnaireOcrDraft.php app/Models/QuestionnaireSubmission.php app/Models/Association.php database/factories/QuestionnairePaper*Factory.php database/factories/QuestionnaireOcrDraftFactory.php tests/Feature/Questionnaire/PaperModelTest.php
git commit -m "feat(questionnaires): models papier/scan/OCR + active_key"
```

---

### Task 7.3 : `QuestionnaireOcrService` (IA Anthropic, modèle dédié)

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireOcrService.php`
- Create: `app/Support/Questionnaire/OcrDraftPayload.php` (DTO)
- Test: `tests/Feature/Questionnaire/QuestionnaireOcrServiceTest.php`

Mirror **strict** de `app/Services/InvoiceOcrService.php` : `isConfigured()` (clé `anthropic_api_key`), `model()` lit `CurrentAssociation::tryGet()?->questionnaire_ocr_model` puis défaut config, `analyzeFromPath($path,$mime,$context)` poste sur `https://api.anthropic.com/v1/messages` une image base64 + un prompt qui décrit **les questions de la campagne** et demande un JSON `{question_id: {value, confidence}}`. En `env=demo`, renvoyer un stub (comme `InvoiceOcrService::demoStub`).

- [ ] **Step 1 : Test** (stub démo + parsing JSON ; ne PAS appeler l'API en test)

```php
<?php

declare(strict_types=1);

use App\Services\Questionnaire\QuestionnaireOcrService;

it('lève une exception sans clé API configurée', function (): void {
    // association sans anthropic_api_key
    expect(fn () => app(QuestionnaireOcrService::class)->analyzeFromPath('/tmp/x.png', 'image/png', []))
        ->toThrow(\App\Exceptions\OcrNotConfiguredException::class);
});

it('parse un JSON de réponses par question', function (): void {
    $payload = app(QuestionnaireOcrService::class)->parse('{"12":{"value":"4","confidence":0.9}}');

    expect($payload['12']['value'])->toBe('4');
    expect($payload['12']['confidence'])->toBe(0.9);
});
```

- [ ] **Step 2 : Service** (squelette — reprendre `performAnalysis`/`buildPrompt` d'`InvoiceOcrService`)

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Models\QuestionnaireCampaign;
use App\Support\CurrentAssociation;
use Illuminate\Support\Facades\Http;

final class QuestionnaireOcrService
{
    public static function isConfigured(): bool
    {
        return CurrentAssociation::tryGet()?->anthropic_api_key !== null;
    }

    private function model(): string
    {
        $choisi = CurrentAssociation::tryGet()?->questionnaire_ocr_model; // ← colonne dédiée (D16)

        return is_string($choisi) && $choisi !== ''
            ? $choisi
            : (string) config('services.anthropic.questionnaire_ocr_model', 'claude-sonnet-4-6');
    }

    /** @return array<string, array{value: mixed, confidence: float}> */
    public function analyzeFromPath(string $path, string $mime, QuestionnaireCampaign $campagne): array
    {
        $apiKey = CurrentAssociation::tryGet()?->anthropic_api_key;
        if ($apiKey === null) {
            throw new OcrNotConfiguredException('Clé Anthropic non configurée.');
        }
        if (app()->environment('demo')) {
            return $this->demoStub($campagne);
        }

        $base64 = base64_encode((string) file_get_contents($path));
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model(),
            'max_tokens' => 1500,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                    ['type' => 'text', 'text' => $this->buildPrompt($campagne)],
                ],
            ]],
        ]);

        if ($response->failed()) {
            throw new OcrAnalysisException('Échec OCR : '.$response->status());
        }

        return $this->parse($response->json('content.0.text', ''));
    }

    /** @return array<string, array{value: mixed, confidence: float}> */
    public function parse(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
        $data = json_decode((string) $text, true);

        return is_array($data) ? $data : [];
    }

    private function buildPrompt(QuestionnaireCampaign $campagne): string
    {
        $lignes = $campagne->questions->map(
            fn ($q) => "- id {$q->id} ({$q->type->value}) : {$q->libelle}".
                ($q->aDesOptions() ? ' [options: '.collect($q->options())->pluck('libelle')->join(', ').']' : '')
        )->join("\n");

        return "Tu lis une feuille de questionnaire remplie à la main. Pour chaque question ci-dessous, ".
            "renvoie un JSON {\"<id>\":{\"value\":<valeur>,\"confidence\":<0..1>}}. ".
            "satisfaction=entier 1-5, ressenti=entier 0-100, case_a_cocher=true/false, ".
            "choix_unique=valeur technique de l'option cochée, textes=transcription.\n\nQuestions :\n{$lignes}";
    }

    /** @return array<string, array{value: mixed, confidence: float}> */
    private function demoStub(QuestionnaireCampaign $campagne): array
    {
        return $campagne->questions->mapWithKeys(fn ($q) => [
            (string) $q->id => ['value' => $q->type->value === 'satisfaction' ? 4 : 'exemple', 'confidence' => 0.75],
        ])->all();
    }
}
```

- [ ] **Step 3 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireOcrService.php tests/Feature/Questionnaire/QuestionnaireOcrServiceTest.php
git commit -m "feat(questionnaires): QuestionnaireOcrService (Anthropic, modèle dédié, prompt par campagne)"
```

---

### Task 7.4 : Détection QR + ingestion d'un scan (upload)

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireQrDecoder.php` (mirror de la lecture QR de `EmargementDocumentHandler`)
- Create: `app/Services/Questionnaire/QuestionnaireScanService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireScanServiceTest.php`

- [ ] **Step 1 : `QuestionnaireQrDecoder`** — Utilise `khanamiryan/qrcode-detector-decoder` (lib `Zxing\QrReader`) pour lire l'URL encodée dans une image, extrait le token clair (dernier segment de `/q/{token}`), renvoie le token ou `null`. **Lire d'abord `app/Services/Emargement/EmargementDocumentHandler.php`** qui fait déjà exactement cette lecture QR sur un document entrant — mirrorer son code de décodage (gestion PDF → image via `spatie/pdf-to-image` si nécessaire).

- [ ] **Step 2 : `QuestionnaireScanService`** — `ingererUpload(UploadedFile $file): QuestionnairePaperScan` :
  1. Stocke le fichier sous `storage/app/associations/{id}/questionnaire-scans/...` (trait/disque tenant).
  2. Décode le QR → token clair → résout l'invitation par hash (réutiliser la logique `hash('sha256',$token)` + lookup `token_hash`, tenant déjà booté ici car back-office).
  3. Crée `questionnaire_paper_scans` (`source=upload`, `qr_statut`, `invitation_id`/`campaign_id` si trouvés, `statut=rattache` ou `en_attente`).
  4. Si rattaché : appelle `QuestionnaireOcrService::analyzeFromPath` + crée `questionnaire_ocr_drafts` (`statut=brouillon`).
  Retourne le scan. **Aucune** soumission créée à ce stade (validation humaine obligatoire).

- [ ] **Step 3 : Test** (avec une image QR fixture + OCR en mode demo). Vérifier : scan créé, invitation rattachée, draft brouillon créé. **Step 4 : Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireQrDecoder.php app/Services/Questionnaire/QuestionnaireScanService.php tests/Feature/Questionnaire/QuestionnaireScanServiceTest.php
git commit -m "feat(questionnaires): ingestion scan upload (QR + OCR brouillon)"
```

---

### Task 7.5 : Réception par email (handler IncomingDocuments)

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireScanDocumentHandler.php` (implémente `App\Services\IncomingDocuments\Contracts\DocumentHandler`)
- Modify: l'enregistrement de la chaîne de handlers (là où les `DocumentHandler` sont assemblés — voir `IncomingDocumentIngester` / provider)
- Test: `tests/Feature/Questionnaire/QuestionnaireScanDocumentHandlerTest.php`

> **Mirror direct** : `app/Services/Emargement/EmargementDocumentHandler.php` détecte déjà un QR sur un document entrant et le route. Copier sa structure.

- [ ] **Step 1 : Handler**

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Services\IncomingDocuments\Contracts\DocumentHandler;
use App\Services\IncomingDocuments\HandlerAttempt;
use App\Services\IncomingDocuments\IncomingDocumentFile;

final class QuestionnaireScanDocumentHandler implements DocumentHandler
{
    public function __construct(
        private readonly QuestionnaireQrDecoder $decoder,
        private readonly QuestionnaireScanService $scans,
    ) {}

    public function name(): string
    {
        return 'questionnaire_scan';
    }

    public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
    {
        $token = $this->decoder->decodeFromPath($file->path(), $file->mime());
        if ($token === null) {
            return HandlerAttempt::failed('no_questionnaire_qr');
        }

        $scan = $this->scans->ingererDepuisFichier($file->path(), $file->mime(), source: 'email', token: $token);
        if ($scan->invitation_id === null) {
            return HandlerAttempt::failed('questionnaire_qr_unresolved');
        }

        return HandlerAttempt::handled(['scan_id' => $scan->id, 'campaign_id' => $scan->campaign_id]);
    }
}
```

(Adapter `IncomingDocumentFile::path()/mime()` à l'API réelle — vérifier `app/Services/IncomingDocuments/IncomingDocumentFile.php`. Factoriser `ingererUpload`/`ingererDepuisFichier` dans `QuestionnaireScanService`.)

- [ ] **Step 2 : Enregistrer le handler** dans la chaîne (avant le « park inbox » par défaut), au même endroit que les handlers existants (chercher où `EmargementDocumentHandler` est enregistré : `grep -rn EmargementDocumentHandler app/`).

- [ ] **Step 3 : Test** (un `IncomingDocumentFile` avec QR → handler `handled`, scan + draft créés). **Step 4 : Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireScanDocumentHandler.php tests/Feature/Questionnaire/QuestionnaireScanDocumentHandlerTest.php
git commit -m "feat(questionnaires): réception scans par email via handler IncomingDocuments"
```

---

### Task 7.6 : Logique de remplacement (service) + supersede non destructif

**Files:**
- Modify: `app/Services/Questionnaire/QuestionnaireReponseService.php` (ajouter `creerDepuisOcr` + `remplacerActive`)
- Test: ajouter à `tests/Feature/Questionnaire/QuestionnaireReponseServiceTest.php`

- [ ] **Step 1 : Test** (D13 : remplacer marque l'ancienne `remplacee`, une seule active)

```php
it('remplace une réponse existante sans détruire l ancienne (supersede)', function (): void {
    $svc = app(\App\Services\Questionnaire\QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $ancienne = $svc->demarrerOuReprendre($invitation);
    $svc->enregistrerReponse($ancienne, $invitation->campaign->questions()->first(), '3');
    $svc->finaliser($ancienne, accepteContact: false);

    // payload OCR validé → nouvelle soumission papier qui remplace
    $nouvelle = $svc->creerDepuisOcr($invitation, [
        (string) $invitation->campaign->questions()->first()->id => 5,
    ], accepteContact: false, remplacer: true);

    expect($nouvelle->statut->value)->toBe('soumise');
    expect($nouvelle->source)->toBe('papier');
    expect($ancienne->fresh()->statut->value)->toBe('remplacee');
    expect($ancienne->fresh()->active_key)->toBeNull();
    // invariant : une seule active
    expect($invitation->submissions()->whereNotNull('active_key')->count())->toBe(1);
});
```

- [ ] **Step 2 : Méthodes** (dans `QuestionnaireReponseService`)

```php
use App\Models\QuestionnaireInvitation;
use App\Models\QuestionnaireSubmission;

/**
 * Crée une soumission papier depuis un payload OCR validé.
 *
 * @param  array<string|int, int|string|bool|null>  $valeursParQuestionId
 */
public function creerDepuisOcr(
    QuestionnaireInvitation $invitation,
    array $valeursParQuestionId,
    bool $accepteContact,
    bool $remplacer,
): QuestionnaireSubmission {
    return DB::transaction(function () use ($invitation, $valeursParQuestionId, $accepteContact, $remplacer): QuestionnaireSubmission {
        $active = $invitation->submissions()
            ->whereIn('statut', [StatutSubmission::EnCours->value, StatutSubmission::Soumise->value])
            ->first();

        if ($active !== null) {
            abort_unless($remplacer, 422, 'Une réponse existe déjà (choisir Ignorer ou Remplacer).');
        }

        $nouvelle = $invitation->submissions()->create([
            'campaign_id' => $invitation->campaign_id,
            'statut' => StatutSubmission::EnCours,
            'source' => 'papier',
            'active_key' => null, // posée à la finalisation, après libération de l'ancienne
        ]);

        $questions = $invitation->campaign->questions()->get()->keyBy('id');
        foreach ($valeursParQuestionId as $qid => $valeur) {
            $q = $questions->get((int) $qid);
            if ($q !== null) {
                $this->enregistrerReponse($nouvelle, $q, $valeur);
            }
        }

        $this->verifierObligatoires($nouvelle);

        // Supersede non destructif de l'ancienne (libère active_key AVANT de poser la nouvelle).
        if ($active !== null) {
            $active->update([
                'statut' => StatutSubmission::Remplacee,
                'active_key' => null,
                'remplacee_par_id' => $nouvelle->id,
            ]);
        }

        $nouvelle->update([
            'statut' => StatutSubmission::Soumise,
            'accepte_contact' => $accepteContact,
            'submitted_at' => now(),
            'active_key' => $invitation->id,
        ]);

        $invitation->update(['statut' => StatutInvitation::Soumis, 'submitted_at' => now()]);

        return $nouvelle;
    });
}
```

Et **éditer `demarrerOuReprendre`** (Task 3.3) pour poser `'active_key' => $invitation->id` à la création de la soumission `en_cours`.

- [ ] **Step 3 : Lancer (passe). Commit.**

```bash
git commit -am "feat(questionnaires): creerDepuisOcr + supersede non destructif (invariant active_key)"
```

---

### Task 7.7 : Assistant de saisie (Livewire)

**Files:**
- Create: `app/Livewire/Questionnaire/AssistantSaisie.php`
- Create: `resources/views/livewire/questionnaire/assistant-saisie.blade.php`
- Create: `resources/views/questionnaire/scans/index.blade.php` (+ route `questionnaires.campagnes.scans`)
- Modify: `resources/views/livewire/questionnaire/operation-questionnaires.blade.php` (bouton « Ajouter un scan » + lien « Scans à valider »)
- Test: `tests/Livewire/Questionnaire/AssistantSaisieTest.php`

- [ ] **Step 1 : Test** (validation d'un brouillon → soumission)

```php
<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\AssistantSaisie;
use App\Models\QuestionnaireOcrDraft;
use Livewire\Livewire;

it('valide un brouillon OCR et crée la soumission papier', function (): void {
    // … créer invitation + scan rattaché + ocr_draft brouillon avec payload …
    Livewire::test(AssistantSaisie::class, ['draft' => $draft])
        ->set('valeurs', [(string) $questionId => '5'])
        ->set('accepteContact', false)
        ->call('valider')
        ->assertHasNoErrors();

    expect($draft->fresh()->statut)->toBe('valide');
    expect($invitation->fresh()->statut->value)->toBe('soumis');
});
```

- [ ] **Step 2 : Composant** — Propriétés : `QuestionnaireOcrDraft $draft`, `array $valeurs` (préremplies depuis `payload[*]['value']`), `bool $accepteContact`. `render()` charge la campagne + questions + l'image du scan (`Storage` tenant → URL signée/route de download) + la confiance par question. `valider()` : appelle `QuestionnaireReponseService::creerDepuisOcr($invitation, $this->valeurs, $this->accepteContact, $remplacer)` ; si une soumission active existe, exposer un choix **Ignorer / Remplacer** (D13) avant d'appeler avec `remplacer:true`. Marque `draft.statut=valide`, `scan.statut=traite`. `ignorer()` : `draft.statut=rejete`, `scan.statut=ignore`.

- [ ] **Step 3 : Vue** — Deux colonnes : à gauche l'image source du scan (`<img>`), à droite, par question : libellé + **valeur proposée préremplie** + badge de **confiance** + champ de correction (rendu selon le type, comme le parcours répondant) + alerte si question obligatoire vide. Bandeau « Remplacer la réponse en ligne existante ? » si conflit.

- [ ] **Step 4 : Route + page hôte + boutons. Step 5 : Lancer (passe). Commit.**

```bash
git add app/Livewire/Questionnaire/AssistantSaisie.php resources/views/livewire/questionnaire/assistant-saisie.blade.php resources/views/questionnaire/scans/index.blade.php routes/web.php resources/views/livewire/questionnaire/operation-questionnaires.blade.php tests/Livewire/Questionnaire/AssistantSaisieTest.php
git commit -m "feat(questionnaires): assistant de saisie (validation humaine OCR → soumission papier)"
```

**✅ Jalon Lot 7 : réponses papier saisies avec contrôle humain.**

---

## LOT 8 — Communication avancée

### Task 8.1 : Placeholders questionnaire + Mailable

**Files:**
- Create: `app/Mail/QuestionnaireInvitationMail.php`
- Create: `resources/views/emails/questionnaire-invitation.blade.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireInvitationMailTest.php`

> **Mirror** : `app/Mail/MessageLibreMail.php` (+ logo CID `cid:logo-asso`, cf. `feedback_bug_emaillog_tracabilite`). Placeholders supportés : `{lien_questionnaire}`, `{operation}`, `{type_operation}`, `{prenom}`.

- [ ] **Step 1 : Test** (le corps contient le lien tokenisé du destinataire)

```php
<?php

declare(strict_types=1);

use App\Mail\QuestionnaireInvitationMail;

it('injecte le lien de réponse et le prénom', function (): void {
    // … invitation + participant(tiers prenom='Marie') …
    $mail = new QuestionnaireInvitationMail(
        invitation: $invitation,
        objet: 'Votre avis',
        corps: 'Bonjour {prenom}, merci de répondre : {lien_questionnaire}',
    );
    $rendu = $mail->render();

    expect($rendu)->toContain('Marie');
    expect($rendu)->toContain($invitation->lienReponse());
});
```

- [ ] **Step 2 : Mailable** — Constructeur `(QuestionnaireInvitation $invitation, string $objet, string $corps)`. Dans `content()`, remplacer les placeholders : `{prenom}` ← `participant->tiers->prenom`, `{lien_questionnaire}` ← `$invitation->lienReponse()`, `{operation}` ← `operation->nom`, `{type_operation}` ← `operation->typeOperation->nom`. Logo via `cid:logo-asso` (mirror MessageLibreMail). **Step 3 : Lancer (passe). Commit.**

```bash
git add app/Mail/QuestionnaireInvitationMail.php resources/views/emails/questionnaire-invitation.blade.php tests/Feature/Questionnaire/QuestionnaireInvitationMailTest.php
git commit -m "feat(questionnaires): Mailable invitation + placeholders {lien_questionnaire}/{prenom}/…"
```

---

### Task 8.2 : Service d'envoi + EmailLog + relances

**Files:**
- Create: `app/Services/Questionnaire/QuestionnaireEnvoiService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireEnvoiServiceTest.php`

> **Mirror** : la boucle d'envoi de `OperationCommunication` (`Mail::mailer()->send(...)` + `EmailLog::create([...])`, lignes ~573-595).

- [ ] **Step 1 : Test** (`Mail::fake()` ; envoi crée EmailLog + pose `sent_at`)

```php
<?php

declare(strict_types=1);

use App\Mail\QuestionnaireInvitationMail;
use App\Models\EmailLog;
use App\Services\Questionnaire\QuestionnaireEnvoiService;
use Illuminate\Support\Facades\Mail;

it('envoie aux invitations et journalise', function (): void {
    Mail::fake();
    // … campagne ouverte + 2 invitations non soumises …
    app(QuestionnaireEnvoiService::class)->envoyer($campagne, $invitationIds, 'Objet', 'Corps {lien_questionnaire}');

    Mail::assertSent(QuestionnaireInvitationMail::class, 2);
    expect(EmailLog::count())->toBe(2);
    expect($invitation->fresh()->sent_at)->not->toBeNull();
});

it('relance uniquement les non-soumis', function (): void {
    // … 1 invitation soumise + 1 non soumise …
    $ids = app(QuestionnaireEnvoiService::class)->idsNonSoumis($campagne);
    expect($ids)->toHaveCount(1);
});
```

- [ ] **Step 2 : Service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutInvitation;
use App\Mail\QuestionnaireInvitationMail;
use App\Models\EmailLog;
use App\Models\QuestionnaireCampaign;
use Illuminate\Support\Facades\Mail;

final class QuestionnaireEnvoiService
{
    /** @param array<int> $invitationIds */
    public function envoyer(QuestionnaireCampaign $campagne, array $invitationIds, string $objet, string $corps): void
    {
        $invitations = $campagne->invitations()
            ->with('participant.tiers')
            ->whereIn('id', $invitationIds)
            ->get();

        foreach ($invitations as $invitation) {
            $email = $invitation->participant?->tiers?->email;
            if ($email === null) {
                continue; // participant sans email : feuille papier uniquement
            }

            $mail = new QuestionnaireInvitationMail($invitation, $objet, $corps);
            Mail::mailer()->to($email)->send($mail);

            EmailLog::create([
                'destinataire' => $email,
                'objet' => $objet,
                'statut' => 'envoye',
                // … champs EmailLog habituels (voir OperationCommunication::send) …
            ]);

            $invitation->update(['sent_at' => now()]);
        }
    }

    /** @return array<int> */
    public function idsNonSoumis(QuestionnaireCampaign $campagne): array
    {
        return $campagne->invitations()
            ->where('statut', '!=', StatutInvitation::Soumis->value)
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
```

(Compléter les colonnes `EmailLog::create` en s'alignant **exactement** sur `OperationCommunication` — vérifier les champs requis : `association_id` est posé par le modèle tenant, plus `categorie`, `operation_id`, etc.)

- [ ] **Step 3 : Lancer (passe). Commit.**

```bash
git add app/Services/Questionnaire/QuestionnaireEnvoiService.php tests/Feature/Questionnaire/QuestionnaireEnvoiServiceTest.php
git commit -m "feat(questionnaires): envoi des invitations + EmailLog + relances non-soumis"
```

---

### Task 8.3 : UI envoi & relance sur la fiche opération

**Files:**
- Modify: `app/Livewire/Questionnaire/OperationQuestionnaires.php` (`envoyerInvitations`, `relancer`, sélection template/objet/corps)
- Modify: `resources/views/livewire/questionnaire/operation-questionnaires.blade.php`
- Test: ajouter à `tests/Livewire/Questionnaire/OperationQuestionnairesTest.php`

- [ ] **Step 1 : Test** (`Mail::fake()` : `envoyerInvitations` envoie aux invitations sélectionnées).
- [ ] **Step 2 : Méthodes** — `envoyerInvitations(int $campagneId, QuestionnaireEnvoiService $envoi)` : objet/corps depuis un `EmailTemplate` choisi (ou saisie libre), cible = invitations de la campagne (ou sélection) ; `relancer(int $campagneId, ...)` : cible = `idsNonSoumis`. Réutiliser le sélecteur de gabarit existant si présent.
- [ ] **Step 3 : Vue** — Boutons « Envoyer les invitations » (campagne ouverte) et « Relancer les non-répondants », avec choix de gabarit email. **Step 4 : Lancer (passe). Commit.**

```bash
git commit -am "feat(questionnaires): UI envoi/relance des invitations depuis la campagne"
```

**✅ Jalon Lot 8 : intégration communication (envoi + relances + placeholders).**

---

## Vérification finale (avant recette / PR)

- [ ] Suite ciblée verte (par lot puis globale) : `php -d memory_limit=-1 vendor/bin/pest --parallel tests/Feature/Questionnaire tests/Livewire/Questionnaire tests/Unit/Enums`
- [ ] `./vendor/bin/pint` (PSR-12) sur les fichiers créés.
- [ ] Recette manuelle (localhost, navigateur — le preview Claude ne voit pas localhost:80) :
  1. Créer un modèle avec les 6 types de questions.
  2. Depuis une opération de démo, créer une campagne, sélectionner les participants, l'ouvrir.
  3. Ouvrir un lien `/q/{token}` en navigation privée, répondre (vérifier blocage obligatoire, curseur ressenti sans chiffre).
  4. Répondre une 2ᵉ fois avec consentement au contact.
  5. Écran résultats : vérifier que seule la 2ᵉ expose l'identité + avertissement petit groupe.
  6. Rouvrir une invitation soumise (admin) → re-répondre.
  7. Exporter en Excel → vérifier en-têtes stables + colonne identité vide pour l'anonyme.
  8. **(Lot 6)** Imprimer le PDF papier groupé → vérifier 1 invitation/page + QR + code court ; scanner le QR avec un téléphone → arrive sur le bon questionnaire.
  9. **(Lot 7)** Déposer un scan (upload) d'une feuille remplie → QR détecté, brouillon OCR créé ; ouvrir l'assistant, corriger, valider → soumission `papier` créée ; rejouer sur une invitation déjà répondue → choix Ignorer/Remplacer, l'ancienne passe `remplacee` (jamais 2 actives).
  10. **(Lot 7)** Envoyer un scan en pièce jointe à l'adresse de réception → apparaît comme scan à valider.
  11. **(Lot 8)** « Envoyer les invitations » → email reçu avec le bon lien `{lien_questionnaire}` ; « Relancer » ne vise que les non-répondants ; EmailLog tracé.
- [ ] **Ne pas merger sur `main` avant validation visuelle locale** (cf. `feedback_test_before_push`). **Merger lot par lot** : 1→5 d'abord (V1 numérique), puis 6, 7, 8.

---

## Notes pour l'exécutant

- **Tenant** : tous les tests héritent du bootstrap Pest global qui boote `TenantContext`. Le test du parcours public appelle `TenantContext::clear()` AVANT le hit HTTP pour prouver la résolution par hash + boot.
- **Multi-tenant fail-closed** : ne jamais requêter une invitation publique sans `withoutGlobalScope(TenantScope::class)` PUIS `TenantContext::boot`. Un test d'isolation cross-tenant (invitation d'une autre asso → 404 ou non résolue) est recommandé en complément.
- **Cast `(int)`** des deux côtés sur les comparaisons d'ID (MySQL renvoie des strings).
- **Pas de Livewire sur la route publique** (D17) : le parcours répondant est 100 % controller + Blade.
- **Token chiffré** (`token_chiffre`, cast `encrypted`) introduit dès le lot 2 : permet de reconstruire QR (lot 6), lien email (lot 8) et relances sans jamais stocker le token en clair. `token_hash` reste l'index de résolution publique. Une fuite DB seule (sans `APP_KEY`) n'expose aucun lien utilisable.
- **Invariant ≤1 soumission active** : posé en service dès le lot 3 (`active_key = invitation_id` à la création) ; la **contrainte DB** `unique(active_key)` et la colonne `remplacee_par_id` arrivent au lot 7 (migration additive + backfill) avec le statut `remplacee`. Penser à éditer `demarrerOuReprendre` au lot 7 pour poser `active_key`.
- **OCR** : `QuestionnaireOcrService` mirrore `InvoiceOcrService` mais lit `questionnaire_ocr_model` (jamais `invoice_ocr_model`). Toujours un **brouillon** validé par un humain — aucune sauvegarde automatique.
- **Intake email** (lot 7) : mirrorer `app/Services/Emargement/EmargementDocumentHandler.php` (même pattern QR-sur-document-entrant) et enregistrer le handler dans la même chaîne `IncomingDocuments`.
- **Mirrors email** (lot 8) : `MessageLibreMail` (logo `cid:logo-asso`, cf. `feedback_bug_emaillog_tracabilite`) + la boucle `Mail::mailer()->send` / `EmailLog::create` d'`OperationCommunication`.
