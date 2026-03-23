# Lot 7 — Assistant de synchronisation HelloAsso (wizard) — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Séparer l'écran HelloAsso en deux (Paramètres vs Synchronisation) et transformer la partie synchronisation en wizard accordéon 3 étapes.

**Architecture:** Un nouveau composant Livewire `HelloassoSyncWizard` dans `App\Livewire\Banques` absorbe la logique des 3 composants existants (mapping formulaires, rapprochement tiers, synchronisation) dans un wizard à étapes avec layout accordéon. L'écran Paramètres conserve uniquement credentials + configuration comptes/sous-catégories. La navigation du menu Banques est enrichie d'une nouvelle entrée.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 (CDN), Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-23-lot7-helloasso-wizard-design.md`

---

### Task 1: Route, vue wrapper et entrée menu Banques

Créer l'infrastructure de base : route, vue, et entrée dans le menu.

**Files:**
- Modify: `routes/web.php` — ajouter la route `/banques/helloasso-sync`
- Create: `resources/views/banques/helloasso-sync.blade.php` — vue wrapper
- Modify: `resources/views/layouts/app.blade.php:161-193` — ajouter entrée menu + dividers

- [ ] **Step 1: Ajouter la route**

Dans `routes/web.php`, ajouter dans le groupe `auth`, après la ligne `Route::view('/virements', ...)` (ligne 42) :

```php
Route::view('/banques/helloasso-sync', 'banques.helloasso-sync')->name('banques.helloasso-sync');
```

- [ ] **Step 2: Créer la vue wrapper**

Créer `resources/views/banques/helloasso-sync.blade.php` :

```blade
<x-app-layout>
    <div class="container py-3">
        <h1 class="mb-4"><i class="bi bi-arrow-repeat"></i> Synchronisation HelloAsso</h1>
        <livewire:banques.helloasso-sync-wizard />
    </div>
</x-app-layout>
```

- [ ] **Step 3: Modifier le menu Banques**

Dans `resources/views/layouts/app.blade.php`, modifier le dropdown Banques :

1. **Ligne 163** — ajouter `request()->routeIs('banques.*')` dans la condition `active` du dropdown toggle :
```php
<a class="nav-link dropdown-toggle {{ request()->routeIs('comptes-bancaires.*') || request()->routeIs('rapprochement.*') || request()->routeIs('virements.*') || request()->routeIs('parametres.comptes-bancaires.*') || request()->routeIs('banques.*') ? 'active' : '' }}"
```

2. **Après la ligne `<li><hr class="dropdown-divider"></li>` (ligne 184)**, ajouter l'entrée Synchronisation HelloAsso + un second divider :
```blade
@if (Route::has('banques.helloasso-sync'))
<li>
    <a class="dropdown-item {{ request()->routeIs('banques.helloasso-sync') ? 'active' : '' }}"
       href="{{ route('banques.helloasso-sync') }}">
        <i class="bi bi-arrow-repeat"></i> Synchronisation HelloAsso
    </a>
</li>
@endif
<li><hr class="dropdown-divider"></li>
```

- [ ] **Step 4: Créer un composant wizard stub**

Créer `app/Livewire/Banques/HelloassoSyncWizard.php` minimal pour vérifier que la route fonctionne :

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Banques;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncWizard extends Component
{
    public int $step = 1;

    public function render(): View
    {
        return view('livewire.banques.helloasso-sync-wizard');
    }
}
```

Créer `resources/views/livewire/banques/helloasso-sync-wizard.blade.php` :

```blade
<div>
    <p>Wizard stub — étape {{ $step }}</p>
</div>
```

- [ ] **Step 5: Vérifier manuellement**

Ouvrir `http://localhost/banques/helloasso-sync` et vérifier :
- La page s'affiche avec le stub
- Le menu Banques contient "Synchronisation HelloAsso" avec les dividers
- Le dropdown Banques est surligné quand on est sur cette page

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/views/banques/helloasso-sync.blade.php resources/views/layouts/app.blade.php app/Livewire/Banques/HelloassoSyncWizard.php resources/views/livewire/banques/helloasso-sync-wizard.blade.php
git commit -m "feat(lot7): route, vue wrapper et menu Banques pour wizard HelloAsso"
```

---

### Task 2: Layout accordéon + vérification pré-requis

Implémenter le layout accordéon 3 étapes et la vérification de configuration au mount.

**Files:**
- Modify: `app/Livewire/Banques/HelloassoSyncWizard.php`
- Modify: `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`

**Contexte existant :** Le composant doit lire `HelloAssoParametres` (table `helloasso_parametres`, `association_id = 1`) pour vérifier credentials (`client_id`), comptes (`compte_helloasso_id`, `compte_versement_id`) et sous-catégories (`sous_categorie_don_id`, `sous_categorie_cotisation_id`, `sous_categorie_inscription_id`).

- [ ] **Step 1: Implémenter la vérification pré-requis au mount**

Dans `HelloassoSyncWizard.php`, ajouter la vérification de configuration :

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Banques;

use App\Models\HelloAssoParametres;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncWizard extends Component
{
    public int $step = 1;

    public bool $configBloquante = false;

    /** @var list<string> */
    public array $configErrors = [];

    /** @var list<string> */
    public array $configWarnings = [];

    public function mount(): void
    {
        $this->checkConfig();
    }

    private function checkConfig(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();

        if ($p === null || $p->client_id === null) {
            $this->configErrors[] = 'Les credentials HelloAsso ne sont pas encore configurés.';
        }

        if ($p !== null && $p->compte_helloasso_id === null) {
            $this->configErrors[] = 'Le compte bancaire HelloAsso n\'est pas configuré.';
        }

        if (count($this->configErrors) > 0) {
            $this->configBloquante = true;

            return;
        }

        if ($p->compte_versement_id === null) {
            $this->configWarnings[] = 'Le compte de versement n\'est pas configuré — les versements (cashouts) ne seront pas traités.';
        }
        if ($p->sous_categorie_don_id === null) {
            $this->configWarnings[] = 'La sous-catégorie Dons n\'est pas configurée — les dons ne seront pas importés.';
        }
        if ($p->sous_categorie_cotisation_id === null) {
            $this->configWarnings[] = 'La sous-catégorie Cotisations n\'est pas configurée — les cotisations ne seront pas importées.';
        }
        if ($p->sous_categorie_inscription_id === null) {
            $this->configWarnings[] = 'La sous-catégorie Inscriptions n\'est pas configurée — les inscriptions ne seront pas importées.';
        }
    }

    public function render(): View
    {
        return view('livewire.banques.helloasso-sync-wizard');
    }
}
```

- [ ] **Step 2: Implémenter le layout accordéon dans la vue**

Remplacer le contenu de `helloasso-sync-wizard.blade.php` :

```blade
<div>
    {{-- Erreurs bloquantes --}}
    @if ($configBloquante)
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-octagon me-1"></i>
            <strong>Configuration incomplète</strong>
            <ul class="mb-0 mt-1">
                @foreach ($configErrors as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
            <a href="{{ route('parametres.helloasso') }}" class="alert-link d-block mt-2">
                <i class="bi bi-gear me-1"></i> Paramètres → Connexion HelloAsso
            </a>
        </div>
    @else
        {{-- Avertissements non bloquants --}}
        @if (count($configWarnings) > 0)
            <div class="alert alert-warning small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <ul class="mb-0">
                    @foreach ($configWarnings as $warn)
                        <li>{{ $warn }}</li>
                    @endforeach
                </ul>
                <a href="{{ route('parametres.helloasso') }}" class="alert-link d-block mt-1">
                    Configurer dans Paramètres → Connexion HelloAsso
                </a>
            </div>
        @endif

        {{-- Étape 1 --}}
        <div class="card mb-3 {{ $step === 1 ? 'border-primary' : '' }}"
             style="{{ $step === 1 ? 'border-width:2px' : '' }}">
            <div class="card-header d-flex align-items-center gap-2 {{ $step !== 1 ? 'cursor-pointer' : '' }}"
                 @if ($step !== 1 && $step > 1) wire:click="goToStep(1)" @endif
                 style="{{ $step !== 1 && $step > 1 ? 'cursor:pointer' : '' }}">
                <span class="badge rounded-pill {{ $step > 1 ? 'bg-success' : ($step === 1 ? 'bg-primary' : 'bg-secondary') }}">1</span>
                <strong>Mapping Formulaires → Opérations</strong>
                @if ($step > 1)
                    <span class="ms-auto small text-muted">{{ $stepOneSummary ?? '' }}</span>
                @endif
            </div>
            @if ($step === 1)
                <div class="card-body">
                    <p class="text-muted">Contenu étape 1 (task suivante)</p>
                </div>
            @endif
        </div>

        {{-- Étape 2 --}}
        <div class="card mb-3 {{ $step === 2 ? 'border-primary' : '' }}"
             style="{{ $step === 2 ? 'border-width:2px' : '' }}">
            <div class="card-header d-flex align-items-center gap-2"
                 @if ($step > 2) wire:click="goToStep(2)" @endif
                 style="{{ $step > 2 ? 'cursor:pointer' : '' }}">
                <span class="badge rounded-pill {{ $step > 2 ? 'bg-success' : ($step === 2 ? 'bg-primary' : 'bg-secondary') }}">2</span>
                <strong>Rapprochement des Tiers</strong>
                @if ($step > 2)
                    <span class="ms-auto small text-muted">{{ $stepTwoSummary ?? '' }}</span>
                @endif
            </div>
            @if ($step === 2)
                <div class="card-body">
                    <p class="text-muted">Contenu étape 2 (task suivante)</p>
                </div>
            @endif
        </div>

        {{-- Étape 3 --}}
        <div class="card mb-3 {{ $step === 3 ? 'border-primary' : '' }}"
             style="{{ $step === 3 ? 'border-width:2px' : '' }}">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="badge rounded-pill {{ $step === 3 ? 'bg-primary' : 'bg-secondary' }}">3</span>
                <strong>Synchronisation</strong>
                @if ($step > 3)
                    <span class="ms-auto small text-muted">{{ $stepThreeSummary ?? '' }}</span>
                @endif
            </div>
            @if ($step === 3)
                <div class="card-body">
                    <p class="text-muted">Contenu étape 3 (task suivante)</p>
                </div>
            @endif
        </div>
    @endif
</div>
```

- [ ] **Step 3: Ajouter les propriétés de résumé et la méthode goToStep**

Dans `HelloassoSyncWizard.php`, ajouter :

```php
public ?string $stepOneSummary = null;
public ?string $stepTwoSummary = null;
public ?string $stepThreeSummary = null;

public function goToStep(int $step): void
{
    if ($step < $this->step) {
        $this->step = $step;
    }
}
```

- [ ] **Step 4: Vérifier manuellement**

- Page affiche les 3 cards accordéon, seule l'étape 1 est ouverte
- Erreurs bloquantes s'affichent si credentials/compte manquants
- Avertissements s'affichent pour sous-catégories manquantes

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Banques/HelloassoSyncWizard.php resources/views/livewire/banques/helloasso-sync-wizard.blade.php
git commit -m "feat(lot7): layout accordéon 3 étapes + vérification pré-requis"
```

---

### Task 3: Étape 1 — Mapping formulaires avec auto-fetch et filtre par date

Implémenter l'étape 1 : chargement automatique des formulaires, filtre par date d'exercice, tableau de mapping, sauvegarde.

**Files:**
- Modify: `app/Livewire/Banques/HelloassoSyncWizard.php`
- Modify: `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`

**Logique existante à réutiliser :** La méthode `chargerFormulaires()` de `HelloassoSyncConfig` (lignes 72-115) fait l'appel API + upsert des `HelloAssoFormMapping`. La méthode `sauvegarderFormulaires()` (lignes 117-126) sauvegarde les mappings. Réutiliser cette logique dans le wizard.

**Filtre par date :** Le modèle `HelloAssoFormMapping` a `end_date` (cast `date`). Afficher uniquement ceux dont `end_date` est null ou `>= dateRange(exercice)['start']`. L'exercice vient de `ExerciceService::current()`.

- [ ] **Step 1: Ajouter les propriétés et méthodes de l'étape 1**

Dans `HelloassoSyncWizard.php`, ajouter les propriétés :

```php
use App\Models\HelloAssoFormMapping;
use App\Models\Operation;
use App\Services\HelloAssoApiClient;

// Étape 1 — Formulaires
public bool $formsLoading = false;
public bool $formsLoaded = false;
/** @var array<int, ?int> mapping_id → operation_id */
public array $formOperations = [];
public ?string $formErreur = null;
```

L'auto-fetch est déclenché via `wire:init` dans la vue (chargement asynchrone avec spinner visible). **Ne pas** appeler `loadFormulaires()` dans `mount()`.

Ajouter les méthodes :

```php
public function loadFormulaires(): void
{
    $this->formsLoading = true;
    $this->formErreur = null;

    $p = HelloAssoParametres::where('association_id', 1)->first();
    if ($p === null || $p->client_id === null) {
        $this->formErreur = 'Paramètres HelloAsso non configurés.';
        $this->formsLoading = false;

        return;
    }

    try {
        $client = new HelloAssoApiClient($p);
        $forms = $client->fetchForms();
    } catch (\RuntimeException $e) {
        $this->formErreur = $e->getMessage();
        $this->formsLoading = false;

        return;
    }

    foreach ($forms as $form) {
        HelloAssoFormMapping::updateOrCreate(
            [
                'helloasso_parametres_id' => $p->id,
                'form_slug' => $form['formSlug'],
            ],
            [
                'form_type' => $form['formType'] ?? '',
                'form_title' => $form['title'] ?? $form['formSlug'],
                'start_date' => isset($form['startDate']) ? \Carbon\Carbon::parse($form['startDate'])->toDateString() : null,
                'end_date' => isset($form['endDate']) ? \Carbon\Carbon::parse($form['endDate'])->toDateString() : null,
                'state' => $form['state'] ?? null,
            ],
        );
    }

    $this->formOperations = [];
    foreach ($p->formMappings()->get() as $m) {
        $this->formOperations[$m->id] = $m->operation_id;
    }

    $this->formsLoaded = true;
    $this->formsLoading = false;
}

public function sauvegarderEtSuite(): void
{
    foreach ($this->formOperations as $mappingId => $operationId) {
        HelloAssoFormMapping::where('id', $mappingId)->update([
            'operation_id' => $operationId ?: null,
        ]);
    }

    $this->updateStepOneSummary();
    $this->step = 2;
}

private function updateStepOneSummary(): void
{
    $exercice = app(ExerciceService::class)->current();
    $range = app(ExerciceService::class)->dateRange($exercice);
    $exerciceStart = $range['start']->toDateString();

    $p = HelloAssoParametres::where('association_id', 1)->first();
    $filtered = $p?->formMappings()
        ->where(function ($q) use ($exerciceStart) {
            $q->whereNull('end_date')
              ->orWhere('end_date', '>=', $exerciceStart);
        })->get() ?? collect();

    $total = $filtered->count();
    $mapped = $filtered->whereNotNull('operation_id')->count();
    $this->stepOneSummary = "{$total} formulaires, {$mapped} mappés";
}
```

- [ ] **Step 2: Ajouter la query filtrée des formulaires dans render()**

Dans la méthode `render()`, passer les données nécessaires :

```php
public function render(): View
{
    $exercice = app(ExerciceService::class)->current();
    $range = app(ExerciceService::class)->dateRange($exercice);
    $exerciceStart = $range['start']->toDateString();

    $p = HelloAssoParametres::where('association_id', 1)->first();

    $formMappings = $p?->formMappings()
        ->where(function ($q) use ($exerciceStart) {
            $q->whereNull('end_date')
              ->orWhere('end_date', '>=', $exerciceStart);
        })
        ->orderBy('form_title')
        ->get() ?? collect();

    return view('livewire.banques.helloasso-sync-wizard', [
        'formMappings' => $formMappings,
        'operations' => Operation::orderBy('nom')->get(),
    ]);
}
```

- [ ] **Step 3: Implémenter la vue de l'étape 1**

Dans `helloasso-sync-wizard.blade.php`, remplacer le placeholder de l'étape 1 :

```blade
@if ($step === 1)
    <div class="card-body" wire:init="loadFormulaires">
        @if ($formErreur)
            <div class="alert alert-danger">{{ $formErreur }}</div>
        @endif

        @if ($formsLoading && ! $formsLoaded)
            <div class="text-center py-3">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="text-muted mt-2">Chargement des formulaires HelloAsso...</p>
            </div>
        @elseif ($formsLoaded)
            @if ($formMappings->isEmpty())
                <p class="text-muted">Aucun formulaire trouvé pour cet exercice.</p>
            @else
                <table class="table table-sm">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Formulaire</th>
                            <th>Type</th>
                            <th>Période</th>
                            <th>Statut</th>
                            <th>Opération SVS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($formMappings as $fm)
                            <tr wire:key="fm-{{ $fm->id }}">
                                <td class="small">{{ $fm->form_title ?? $fm->form_slug }}</td>
                                <td class="small"><span class="badge text-bg-secondary">{{ $fm->form_type }}</span></td>
                                <td class="small text-nowrap">
                                    @if ($fm->start_date || $fm->end_date)
                                        {{ $fm->start_date?->format('d/m/Y') ?? '—' }}
                                        → {{ $fm->end_date?->format('d/m/Y') ?? '…' }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if ($fm->state)
                                        @php
                                            $badgeClass = match($fm->state) {
                                                'Public' => 'text-bg-success',
                                                'Draft' => 'text-bg-warning',
                                                'Private' => 'text-bg-info',
                                                'Disabled' => 'text-bg-danger',
                                                default => 'text-bg-secondary',
                                            };
                                        @endphp
                                        <span class="badge {{ $badgeClass }}">{{ $fm->state }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <select wire:model="formOperations.{{ $fm->id }}" class="form-select form-select-sm">
                                            <option value="">Ne pas suivre</option>
                                            @foreach ($operations as $op)
                                                <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                            @endforeach
                                        </select>
                                        <button wire:click="openCreateOperation({{ $fm->id }})"
                                                class="btn btn-sm btn-outline-primary" title="Créer une opération"
                                                style="padding:.15rem .5rem">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <div class="d-flex justify-content-end mt-3">
                <button wire:click="sauvegarderEtSuite" class="btn btn-primary">
                    Suite <i class="bi bi-arrow-right ms-1"></i>
                </button>
            </div>
        @endif
    </div>
@endif
```

- [ ] **Step 4: Vérifier manuellement**

- Les formulaires se chargent automatiquement au mount
- Seuls les formulaires de l'exercice courant (ou sans date de fin) s'affichent
- Le bouton "Suite" sauvegarde les mappings et ouvre l'étape 2
- L'étape 1 se replie avec un résumé ("X formulaires, Y mappés")

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Banques/HelloassoSyncWizard.php resources/views/livewire/banques/helloasso-sync-wizard.blade.php
git commit -m "feat(lot7): étape 1 — mapping formulaires avec auto-fetch et filtre par date"
```

---

### Task 4: Étape 1 — Création d'opération inline

Ajouter le bouton "+" qui permet de créer une opération directement depuis la ligne du formulaire.

**Files:**
- Modify: `app/Livewire/Banques/HelloassoSyncWizard.php`
- Modify: `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`

**Contexte :** Le modèle `Operation` a les champs `nom` (requis), `date_debut` (requis, cast date), `date_fin` (cast date), `description`, `nombre_seances`, `statut` (enum `StatutOperation`, valeurs : `en_cours`, `cloturee`). Les dates du formulaire HelloAsso sont dans `HelloAssoFormMapping.start_date` et `end_date`.

- [ ] **Step 1: Ajouter les propriétés et méthodes de création**

Dans `HelloassoSyncWizard.php` :

```php
use App\Enums\StatutOperation;

// Création opération inline
public ?int $creatingOperationForMapping = null;
public string $newOperationNom = '';
public ?string $newOperationDateDebut = null;
public ?string $newOperationDateFin = null;

public function openCreateOperation(int $mappingId): void
{
    $mapping = HelloAssoFormMapping::find($mappingId);
    $this->creatingOperationForMapping = $mappingId;
    $this->newOperationNom = $mapping?->form_title ?? '';
    $this->newOperationDateDebut = $mapping?->start_date?->format('Y-m-d');
    $this->newOperationDateFin = $mapping?->end_date?->format('Y-m-d');
}

public function cancelCreateOperation(): void
{
    $this->creatingOperationForMapping = null;
    $this->reset('newOperationNom', 'newOperationDateDebut', 'newOperationDateFin');
}

public function storeOperation(): void
{
    $this->validate([
        'newOperationNom' => 'required|string|max:255',
        'newOperationDateDebut' => 'required|date',
        'newOperationDateFin' => 'nullable|date|after_or_equal:newOperationDateDebut',
    ]);

    $operation = Operation::create([
        'nom' => $this->newOperationNom,
        'date_debut' => $this->newOperationDateDebut,
        'date_fin' => $this->newOperationDateFin,
        'statut' => StatutOperation::EnCours,
    ]);

    // Auto-sélectionner dans le dropdown
    if ($this->creatingOperationForMapping !== null) {
        $this->formOperations[$this->creatingOperationForMapping] = $operation->id;
    }

    $this->cancelCreateOperation();
}
```

- [ ] **Step 2: Ajouter le formulaire inline dans la vue**

Dans `helloasso-sync-wizard.blade.php`, après le `</tr>` de chaque formulaire dans le `@foreach`, ajouter :

```blade
@if ($creatingOperationForMapping === $fm->id)
    <tr wire:key="create-op-{{ $fm->id }}">
        <td colspan="5">
            <div class="bg-light rounded p-3">
                <h6 class="mb-2"><i class="bi bi-plus-circle me-1"></i> Nouvelle opération</h6>
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small">Nom *</label>
                        <input type="text" wire:model="newOperationNom" class="form-control form-control-sm"
                               @error('newOperationNom') is-invalid @enderror>
                        @error('newOperationNom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Date début *</label>
                        <x-date-input name="new_op_debut" wire:model="newOperationDateDebut" :value="$newOperationDateDebut" />
                        @error('newOperationDateDebut') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Date fin</label>
                        <x-date-input name="new_op_fin" wire:model="newOperationDateFin" :value="$newOperationDateFin" />
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button wire:click="storeOperation" class="btn btn-sm btn-success">
                            <i class="bi bi-check-lg"></i> Créer
                        </button>
                        <button wire:click="cancelCreateOperation" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            </div>
        </td>
                    </tr>
@endif
```

- [ ] **Step 3: Vérifier manuellement**

- Le bouton "+" ouvre le formulaire inline sous la ligne
- Les champs sont pré-remplis depuis le formulaire HelloAsso
- La création fonctionne et sélectionne l'opération dans le dropdown
- Le bouton "X" annule et ferme le formulaire

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/Banques/HelloassoSyncWizard.php resources/views/livewire/banques/helloasso-sync-wizard.blade.php
git commit -m "feat(lot7): création d'opération inline depuis le mapping formulaires"
```

---

### Task 5: Étape 2 — Rapprochement des tiers

Implémenter l'étape 2 : auto-fetch des tiers, affichage des non-liés uniquement, association et création.

**Files:**
- Modify: `app/Livewire/Banques/HelloassoSyncWizard.php`
- Modify: `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`

**Logique existante à réutiliser :** La méthode `fetchTiers()` de `HelloassoTiersRapprochement` (lignes 36-122) fait l'appel API + résolution. Les méthodes `associer()` (lignes 124-142) et `creer()` (lignes 144-169) lient ou créent des tiers. Reprendre cette logique en ne conservant que les tiers non liés.

- [ ] **Step 1: Ajouter les propriétés et méthodes de l'étape 2**

Dans `HelloassoSyncWizard.php`, ajouter :

```php
use App\Models\Tiers;
use App\Services\HelloAssoTiersResolver;

// Étape 2 — Tiers
public bool $tiersFetched = false;
public bool $tiersLoading = false;
public ?string $tiersErreur = null;

/**
 * @var list<array{firstName: string, lastName: string, email: ?string, address: ?string, city: ?string, zipCode: ?string, country: ?string, tiers_id: ?int, tiers_name: ?string}>
 */
public array $persons = [];

/** @var array<int, ?int> */
public array $selectedTiers = [];
```

Modifier `goToStep()` pour déclencher l'auto-fetch :

```php
public function goToStep(int $step): void
{
    if ($step >= $this->step) {
        return;
    }

    $this->step = $step;

    if ($step === 2 && ! $this->tiersFetched) {
        $this->loadTiers();
    }
}
```

Modifier `sauvegarderEtSuite()` pour déclencher l'auto-fetch au passage vers l'étape 2 :

```php
public function sauvegarderEtSuite(): void
{
    // ... existing save logic ...
    $this->updateStepOneSummary();
    $this->step = 2;

    if (! $this->tiersFetched) {
        $this->loadTiers();
    }
}
```

Ajouter les méthodes :

```php
public function loadTiers(): void
{
    $this->tiersLoading = true;
    $this->tiersErreur = null;

    $p = HelloAssoParametres::where('association_id', 1)->first();

    try {
        $client = new HelloAssoApiClient($p);
        $exercice = app(ExerciceService::class)->current();
        $range = app(ExerciceService::class)->dateRange($exercice);
        $orders = $client->fetchOrders($range['start']->toDateString(), $range['end']->toDateString());
    } catch (\RuntimeException $e) {
        $this->tiersErreur = $e->getMessage();
        $this->tiersLoading = false;

        return;
    }

    $resolver = new HelloAssoTiersResolver;
    $extractedPersons = $resolver->extractPersons($orders);
    $result = $resolver->resolve($extractedPersons);

    $personDataByKey = [];
    foreach ($extractedPersons as $personData) {
        $key = strtolower($personData['lastName']).'|'.strtolower($personData['firstName']);
        $personDataByKey[$key] = $personData;
    }

    // Only unlinked persons
    $this->persons = [];
    $this->selectedTiers = [];
    $index = 0;

    foreach ($result['unlinked'] as $person) {
        $suggestedId = count($person['suggestions']) > 0 ? $person['suggestions'][0]['tiers_id'] : null;
        $key = strtolower($person['lastName']).'|'.strtolower($person['firstName']);
        $data = $personDataByKey[$key] ?? [];

        $this->persons[] = [
            'firstName' => $person['firstName'],
            'lastName' => $person['lastName'],
            'email' => $person['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'zipCode' => $data['zipCode'] ?? null,
            'country' => $data['country'] ?? null,
            'tiers_id' => null,
            'tiers_name' => null,
        ];
        $this->selectedTiers[$index] = $suggestedId;
        $index++;
    }

    $this->tiersFetched = true;
    $this->tiersLoading = false;
    $this->updateStepTwoSummary();
}

public function associerTiers(int $index): void
{
    $tiersId = $this->selectedTiers[$index] ?? null;
    $person = $this->persons[$index] ?? null;
    if ($tiersId === null || $person === null) {
        return;
    }

    $tiers = Tiers::findOrFail($tiersId);
    $tiers->update([
        'est_helloasso' => true,
        'helloasso_nom' => $person['lastName'],
        'helloasso_prenom' => $person['firstName'],
    ]);

    $this->persons[$index]['tiers_id'] = $tiers->id;
    $this->persons[$index]['tiers_name'] = $tiers->displayName();
    $this->updateStepTwoSummary();
}

public function creerTiers(int $index): void
{
    $person = $this->persons[$index] ?? null;
    if ($person === null) {
        return;
    }

    $tiers = Tiers::create([
        'type' => 'particulier',
        'nom' => $person['lastName'],
        'prenom' => $person['firstName'],
        'email' => $person['email'],
        'adresse_ligne1' => $person['address'],
        'ville' => $person['city'],
        'code_postal' => $person['zipCode'],
        'pays' => $person['country'],
        'est_helloasso' => true,
        'helloasso_nom' => $person['lastName'],
        'helloasso_prenom' => $person['firstName'],
        'pour_recettes' => true,
    ]);

    $this->persons[$index]['tiers_id'] = $tiers->id;
    $this->persons[$index]['tiers_name'] = $tiers->displayName();
    $this->selectedTiers[$index] = $tiers->id;
    $this->updateStepTwoSummary();
}

private function updateStepTwoSummary(): void
{
    $unlinked = collect($this->persons)->whereNull('tiers_id')->count();
    $this->stepTwoSummary = $unlinked > 0 ? "{$unlinked} tiers à lier" : 'Tous les tiers liés';
}
```

- [ ] **Step 2: Implémenter la vue de l'étape 2**

Dans `helloasso-sync-wizard.blade.php`, remplacer le placeholder de l'étape 2 :

```blade
@if ($step === 2)
    <div class="card-body">
        @if ($tiersErreur)
            <div class="alert alert-danger">{{ $tiersErreur }}</div>
        @endif

        @if ($tiersLoading && ! $tiersFetched)
            <div class="text-center py-3">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="text-muted mt-2">Récupération des tiers HelloAsso...</p>
            </div>
        @elseif ($tiersFetched)
            @php
                $unlinkedPersons = collect($persons)->whereNull('tiers_id');
            @endphp

            @if ($unlinkedPersons->isEmpty())
                <div class="alert alert-success mb-3">
                    <i class="bi bi-check-circle me-1"></i> Tous les tiers HelloAsso sont déjà associés.
                </div>
            @else
                <table class="table table-sm table-hover">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Personne HelloAsso</th>
                            <th>Email</th>
                            <th>Correspond à</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($persons as $index => $person)
                            @if ($person['tiers_id'] === null)
                                <tr wire:key="person-{{ $index }}">
                                    <td class="small">{{ $person['firstName'] }} {{ $person['lastName'] }}</td>
                                    <td class="small text-muted">{{ $person['email'] }}</td>
                                    <td>
                                        <livewire:tiers-autocomplete
                                            wire:model.live="selectedTiers.{{ $index }}"
                                            filtre="recettes"
                                            :key="'rapprochement-'.$index"
                                        />
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <button wire:click="associerTiers({{ $index }})"
                                                    class="btn btn-sm btn-outline-success py-0 px-2">
                                                <i class="bi bi-link-45deg me-1"></i>Associer
                                            </button>
                                            <button wire:click="creerTiers({{ $index }})"
                                                    class="btn btn-sm btn-outline-primary py-0 px-2"
                                                    title="Créer un nouveau tiers à partir des données HelloAsso">
                                                <i class="bi bi-person-plus me-1"></i>Ajouter depuis HelloAsso
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            @endif

            <div class="d-flex justify-content-end mt-3">
                <button wire:click="lancerSynchronisation" class="btn btn-success"
                        wire:loading.attr="disabled" wire:target="lancerSynchronisation">
                    <span wire:loading wire:target="lancerSynchronisation" class="spinner-border spinner-border-sm me-1"></span>
                    <i class="bi bi-arrow-repeat me-1" wire:loading.remove wire:target="lancerSynchronisation"></i>
                    Lancer la synchronisation
                </button>
            </div>
        @endif
    </div>
@endif
```

- [ ] **Step 3: Ajouter la méthode lancerSynchronisation (stub)**

Dans `HelloassoSyncWizard.php` :

```php
public function lancerSynchronisation(): void
{
    $this->updateStepTwoSummary();
    $this->step = 3;
    $this->synchroniser();
}
```

La méthode `synchroniser()` sera implémentée dans la task suivante. Ajouter un stub temporaire :

```php
public function synchroniser(): void
{
    // Implémenté dans Task 6
}
```

- [ ] **Step 4: Vérifier manuellement**

- Passage à l'étape 2 déclenche l'auto-fetch
- Seuls les tiers non liés s'affichent
- "Associer" et "Créer depuis HelloAsso" fonctionnent
- Les tiers liés disparaissent du tableau
- Le résumé s'affiche quand l'étape 1 est repliée
- Réouvrir l'étape 2 après passage à l'étape 3 ne relance pas le fetch

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Banques/HelloassoSyncWizard.php resources/views/livewire/banques/helloasso-sync-wizard.blade.php
git commit -m "feat(lot7): étape 2 — rapprochement tiers avec auto-fetch"
```

---

### Task 6: Étape 3 — Synchronisation et rapport

Implémenter l'étape 3 : synchronisation automatique et affichage du rapport.

**Files:**
- Modify: `app/Livewire/Banques/HelloassoSyncWizard.php`
- Modify: `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`

**Logique existante à réutiliser :** La méthode `synchroniser()` de `HelloassoSync` (lignes 28-102) orchestre l'appel API orders + sync + cashouts. Reprendre cette logique.

- [ ] **Step 1: Implémenter la méthode synchroniser()**

Dans `HelloassoSyncWizard.php`, remplacer le stub par :

```php
use App\Services\HelloAssoSyncService;

// Étape 3 — Synchronisation
/** @var array<string, mixed>|null */
public ?array $syncResult = null;
public ?string $syncErreur = null;
public bool $syncLoading = false;

public function synchroniser(): void
{
    $this->syncLoading = true;
    $this->syncResult = null;
    $this->syncErreur = null;

    $parametres = HelloAssoParametres::where('association_id', 1)->first();
    $exercice = app(ExerciceService::class)->current();
    $exerciceService = app(ExerciceService::class);

    try {
        $client = new HelloAssoApiClient($parametres);
        $range = $exerciceService->dateRange($exercice);
        $from = $range['start']->toDateString();
        $to = $range['end']->toDateString();

        $orders = $client->fetchOrders($from, $to);
    } catch (\RuntimeException $e) {
        $this->syncErreur = $e->getMessage();
        $this->syncLoading = false;

        return;
    }

    $syncService = new HelloAssoSyncService($parametres);
    $syncResult = $syncService->synchroniser($orders, $exercice);

    $this->syncResult = [
        'transactionsCreated' => $syncResult->transactionsCreated,
        'transactionsUpdated' => $syncResult->transactionsUpdated,
        'lignesCreated' => $syncResult->lignesCreated,
        'lignesUpdated' => $syncResult->lignesUpdated,
        'ordersSkipped' => $syncResult->ordersSkipped,
        'errors' => $syncResult->errors,
        'virementsCreated' => 0,
        'virementsUpdated' => 0,
        'rapprochementsCreated' => 0,
        'cashoutsIncomplets' => [],
        'cashoutSkipped' => false,
    ];

    if ($parametres->compte_versement_id === null) {
        $this->syncResult['cashoutSkipped'] = true;
    } else {
        try {
            $rangePrev = $exerciceService->dateRange($exercice - 1);
            $paymentsFrom = $rangePrev['start']->toDateString();

            $payments = $client->fetchPayments($paymentsFrom, $to);
            $cashOuts = HelloAssoApiClient::extractCashOutsFromPayments($payments);
            $cashoutResult = $syncService->synchroniserCashouts($cashOuts, $exercice);

            $this->syncResult['virementsCreated'] = $cashoutResult['virements_created'];
            $this->syncResult['virementsUpdated'] = $cashoutResult['virements_updated'];
            $this->syncResult['rapprochementsCreated'] = $cashoutResult['rapprochements_created'];
            $this->syncResult['cashoutsIncomplets'] = $cashoutResult['cashouts_incomplets'];

            if (! empty($cashoutResult['errors'])) {
                $this->syncResult['errors'] = array_merge($this->syncResult['errors'], $cashoutResult['errors']);
            }
        } catch (\RuntimeException $e) {
            $this->syncResult['errors'][] = "Cashouts : {$e->getMessage()}";
        }
    }

    $this->syncLoading = false;
    $this->updateStepThreeSummary();
}

private function updateStepThreeSummary(): void
{
    if ($this->syncResult === null) {
        return;
    }
    $parts = [];
    $total = $this->syncResult['transactionsCreated'] + $this->syncResult['transactionsUpdated'];
    if ($total > 0) {
        $parts[] = "{$total} transactions";
    }
    $rap = $this->syncResult['rapprochementsCreated'] ?? 0;
    if ($rap > 0) {
        $parts[] = "{$rap} rapprochement(s)";
    }
    $this->stepThreeSummary = implode(', ', $parts) ?: 'Aucun changement';
}
```

- [ ] **Step 2: Implémenter la vue de l'étape 3**

Dans `helloasso-sync-wizard.blade.php`, remplacer le placeholder de l'étape 3 :

```blade
@if ($step === 3)
    <div class="card-body">
        @if ($syncErreur)
            <div class="alert alert-danger">{{ $syncErreur }}</div>
        @endif

        @if ($syncLoading)
            <div class="text-center py-3">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="text-muted mt-2">Synchronisation en cours...</p>
            </div>
        @elseif ($syncResult)
            <div class="alert {{ count($syncResult['errors']) > 0 ? 'alert-warning' : 'alert-success' }}">
                <strong><i class="bi bi-check-circle me-1"></i> Synchronisation terminée</strong>
                <ul class="mb-0 mt-2">
                    <li>Transactions : <strong>{{ $syncResult['transactionsCreated'] }} créée(s)</strong>, <strong>{{ $syncResult['transactionsUpdated'] }} mise(s) à jour</strong></li>
                    <li>Lignes : <strong>{{ $syncResult['lignesCreated'] }} créée(s)</strong>, <strong>{{ $syncResult['lignesUpdated'] }} mise(s) à jour</strong></li>
                    @if ($syncResult['ordersSkipped'] > 0)
                        <li>Commandes ignorées : <strong>{{ $syncResult['ordersSkipped'] }}</strong></li>
                    @endif
                    @if (($syncResult['virementsCreated'] ?? 0) > 0)
                        <li>Virements : <strong>{{ $syncResult['virementsCreated'] }} créé(s)</strong></li>
                    @endif
                    @if (($syncResult['rapprochementsCreated'] ?? 0) > 0)
                        <li>Rapprochements auto-verrouillés : <strong>{{ $syncResult['rapprochementsCreated'] }}</strong></li>
                    @endif
                </ul>
            </div>

            @if (! empty($syncResult['cashoutSkipped']))
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle me-1"></i> Versements non synchronisés : le compte de versement n'est pas configuré.
                </div>
            @endif

            @if (! empty($syncResult['cashoutsIncomplets']))
                <div class="alert alert-warning">
                    <strong><i class="bi bi-exclamation-triangle me-1"></i> Versements incomplets :</strong>
                    <ul class="mb-0 mt-1">
                        @foreach ($syncResult['cashoutsIncomplets'] as $warning)
                            <li class="small">{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (count($syncResult['errors']) > 0)
                <div class="alert alert-danger">
                    <strong><i class="bi bi-exclamation-triangle me-1"></i> {{ count($syncResult['errors']) }} erreur(s) :</strong>
                    <ul class="mb-0 mt-1">
                        @foreach ($syncResult['errors'] as $error)
                            <li class="small">{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </div>
@endif
```

- [ ] **Step 3: Modifier lancerSynchronisation pour effacer le résultat précédent**

Le bouton de l'étape 2 peut être cliqué plusieurs fois si l'utilisateur revient en arrière. Vérifier que `lancerSynchronisation()` fait bien `$this->syncResult = null` avant d'appeler `synchroniser()` — c'est le cas via la méthode `synchroniser()` elle-même.

- [ ] **Step 4: Vérifier manuellement**

- Le bouton "Lancer la synchronisation" de l'étape 2 passe à l'étape 3 avec spinner
- Le rapport s'affiche comme sur l'ancien écran
- Les cashouts incomplets et erreurs s'affichent
- Le résumé replié de l'étape 3 est correct

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Banques/HelloassoSyncWizard.php resources/views/livewire/banques/helloasso-sync-wizard.blade.php
git commit -m "feat(lot7): étape 3 — synchronisation et rapport"
```

---

### Task 7: Nettoyage de l'écran Paramètres

Retirer les composants déplacés de l'écran Paramètres HelloAsso.

**Files:**
- Modify: `resources/views/parametres/helloasso.blade.php` — retirer les 3 composants déplacés
- Modify: `resources/views/livewire/parametres/helloasso-sync-config.blade.php` — retirer le bloc mapping formulaires

- [ ] **Step 1: Modifier la vue wrapper**

Remplacer `resources/views/parametres/helloasso.blade.php` :

```blade
<x-app-layout>
    <div class="container py-3">
        <h1 class="mb-4">Connexion HelloAsso</h1>
        <livewire:parametres.helloasso-form />
        <livewire:parametres.helloasso-sync-config />
    </div>
</x-app-layout>
```

- [ ] **Step 2: Retirer le bloc mapping formulaires de la vue sync-config**

Dans `resources/views/livewire/parametres/helloasso-sync-config.blade.php`, supprimer tout le second `<div class="card mb-4">` dont le `card-header` contient l'icône `bi-diagram-3` et le titre "Mapping des formulaires → opérations" — c'est tout le bloc depuis `{{-- Mapping formulaires → opérations --}}` jusqu'à la fin du fichier (avant le `</div>` racine).

- [ ] **Step 3: Retirer la logique formulaires du composant sync-config**

Dans `app/Livewire/Parametres/HelloassoSyncConfig.php` :
- Supprimer la propriété `$formOperations` (ligne 29)
- Supprimer les méthodes `chargerFormulaires()` (lignes 72-115) et `sauvegarderFormulaires()` (lignes 117-126)
- Supprimer le chargement des formMappings dans `mount()` (lignes 46-48)
- Dans `render()`, supprimer les lignes passant `operations` et `formMappings` à la vue
- Nettoyer les imports inutilisés (`HelloAssoFormMapping`, `Operation`, `HelloAssoApiClient`)

- [ ] **Step 4: Vérifier manuellement**

- L'écran Paramètres → Connexion HelloAsso ne montre que credentials + config comptes/sous-catégories
- Plus de mapping formulaires, plus de tiers, plus de synchro
- Le bouton "Enregistrer la configuration" fonctionne toujours

- [ ] **Step 5: Commit**

```bash
git add resources/views/parametres/helloasso.blade.php resources/views/livewire/parametres/helloasso-sync-config.blade.php app/Livewire/Parametres/HelloassoSyncConfig.php
git commit -m "feat(lot7): nettoyer écran Paramètres — retirer composants déplacés vers wizard"
```

---

### Task 8: Suppression des composants absorbés

Supprimer les anciens composants maintenant absorbés dans le wizard.

**Files:**
- Delete: `app/Livewire/Parametres/HelloassoTiersRapprochement.php`
- Delete: `resources/views/livewire/parametres/helloasso-tiers-rapprochement.blade.php`
- Delete: `app/Livewire/Parametres/HelloassoSync.php`
- Delete: `resources/views/livewire/parametres/helloasso-sync.blade.php`

- [ ] **Step 1: Supprimer les fichiers**

```bash
rm app/Livewire/Parametres/HelloassoTiersRapprochement.php
rm resources/views/livewire/parametres/helloasso-tiers-rapprochement.blade.php
rm app/Livewire/Parametres/HelloassoSync.php
rm resources/views/livewire/parametres/helloasso-sync.blade.php
```

- [ ] **Step 2: Vérifier qu'aucune référence ne subsiste**

```bash
grep -r "helloasso-tiers-rapprochement\|HelloassoTiersRapprochement\|HelloassoSync[^CW]" resources/views/ app/ tests/ --include="*.php" --include="*.blade.php" | grep -v "helloasso-sync-config\|helloasso-sync-wizard\|HelloassoSyncWizard\|HelloassoSyncConfig\|HelloAssoSyncService\|HelloAssoSyncResult"
```

Ne doit retourner aucun résultat. Si des tests référencent les classes supprimées, les supprimer ou adapter.

- [ ] **Step 3: Lancer les tests**

```bash
./vendor/bin/sail test
```

Vérifier qu'aucun test existant ne casse.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore(lot7): supprimer composants HelloAsso absorbés dans le wizard"
```

---

### Résumé des tasks

| Task | Description | Fichiers principaux |
|------|-------------|-------------------|
| 1 | Route, vue wrapper, menu Banques | `web.php`, `app.blade.php`, stub composant |
| 2 | Layout accordéon + vérification pré-requis | `HelloassoSyncWizard.php`, vue |
| 3 | Étape 1 — Mapping formulaires auto-fetch + filtre | `HelloassoSyncWizard.php`, vue |
| 4 | Étape 1 — Création opération inline | `HelloassoSyncWizard.php`, vue |
| 5 | Étape 2 — Rapprochement tiers | `HelloassoSyncWizard.php`, vue |
| 6 | Étape 3 — Synchronisation et rapport | `HelloassoSyncWizard.php`, vue |
| 7 | Nettoyage écran Paramètres | `helloasso.blade.php`, `helloasso-sync-config.*` |
| 8 | Suppression composants absorbés | 4 fichiers supprimés |
