# Navigation hiérarchique Gestion des opérations — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transformer l'écran monolithique "Gestion des opérations" (dropdown + onglets) en navigation hiérarchique à 3 niveaux avec URLs dédiées : liste des opérations → détail opération → fiche participant.

**Architecture:** Remplacer le composant Livewire unique `GestionOperations` par 3 pages distinctes avec leurs propres routes. Le niveau 1 est un nouveau composant Livewire `OperationList`. Le niveau 2 réutilise les composants existants (ParticipantTable, SeanceTable, etc.) dans un nouveau layout avec breadcrumb. Le niveau 3 déplace `ParticipantShow` vers sa propre page au lieu de l'imbriquer dans le niveau 2.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Alpine.js

**Spec:** `docs/superpowers/specs/2026-04-02-navigation-gestion-operations-design.md`

---

## File Structure

### Fichiers à créer

| Fichier | Responsabilité |
|---------|---------------|
| `resources/views/gestion/operations/index.blade.php` | Niveau 1 — layout page liste |
| `resources/views/gestion/operations/show.blade.php` | Niveau 2 — layout page détail opération |
| `resources/views/gestion/operations/participant.blade.php` | Niveau 3 — layout page fiche participant |
| `app/Livewire/OperationList.php` | Composant Livewire niveau 1 — liste, filtres, modale CRUD |
| `resources/views/livewire/operation-list.blade.php` | Vue Livewire niveau 1 |
| `app/Livewire/OperationDetail.php` | Composant Livewire niveau 2 — onglets, breadcrumb |
| `resources/views/livewire/operation-detail.blade.php` | Vue Livewire niveau 2 |
| `resources/views/components/operation-breadcrumb.blade.php` | Composant Blade breadcrumb réutilisable |
| `resources/views/components/unsaved-changes-modal.blade.php` | Modale Bootstrap "Enregistrer / Abandonner" |
| `tests/Feature/GestionOperationNavigationTest.php` | Tests des 3 niveaux de navigation |

### Fichiers à modifier

| Fichier | Modification |
|---------|-------------|
| `routes/web.php` | Ajouter routes niveaux 2 et 3, modifier route niveau 1 |
| `resources/views/layouts/app.blade.php` | Mettre à jour le lien nav "Gestion des opérations" pour `routeIs('gestion.operations*')` |
| `resources/views/livewire/participant-show.blade.php` | Adapter pour page autonome : breadcrumb, bouton Enregistrer en haut, modale confirmation |
| `app/Livewire/ParticipantShow.php` | Adapter `save()` pour ne plus `dispatch('close-participant')` mais afficher message succès |
| `resources/views/livewire/participant-table.blade.php` | Remplacer `$dispatch('open-participant')` par lien `<a href>` vers niveau 3 |

### Fichiers à supprimer (après migration)

| Fichier | Raison |
|---------|--------|
| `app/Livewire/GestionOperations.php` | Remplacé par `OperationList` + `OperationDetail` |
| `resources/views/livewire/gestion-operations.blade.php` | Remplacé par les vues des 3 niveaux |
| `resources/views/gestion/operations.blade.php` | Wrapper 3 lignes remplacé par `operations/index.blade.php` |

---

## Task 1 : Routes et pages squelettes

**Files:**
- Modify: `routes/web.php:117`
- Create: `resources/views/gestion/operations/index.blade.php`
- Create: `resources/views/gestion/operations/show.blade.php`
- Create: `resources/views/gestion/operations/participant.blade.php`
- Test: `tests/Feature/GestionOperationNavigationTest.php`

- [ ] **Step 1: Écrire les tests de navigation**

```php
<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;

test('niveau 1: liste des opérations charge correctement', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertOk();
});

test('niveau 2: détail opération charge correctement', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Parcours Test Nav']);
    $this->actingAs($user)
        ->get("/gestion/operations/{$op->id}")
        ->assertOk()
        ->assertSee('Parcours Test Nav');
});

test('niveau 3: fiche participant charge correctement', function (): void {
    $user = User::factory()->create();
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create([
        'operation_id' => $op->id,
        'tiers_id' => $tiers->id,
    ]);
    $this->actingAs($user)
        ->get("/gestion/operations/{$op->id}/participants/{$participant->id}")
        ->assertOk()
        ->assertSee('Dupont');
});

test('niveau 2: retour navigateur fonctionne (URL dédiée)', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create();
    // Chaque niveau a sa propre URL, pas de query string
    $response = $this->actingAs($user)
        ->get("/gestion/operations/{$op->id}");
    $response->assertOk();
    // L'URL ne contient pas ?id= (ancien pattern)
    expect($response->baseResponse->headers->get('Location'))->toBeNull();
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `./vendor/bin/sail test tests/Feature/GestionOperationNavigationTest.php`
Expected: FAIL — routes 404

- [ ] **Step 3: Ajouter les routes dans web.php**

Dans `routes/web.php`, remplacer la ligne 117 :
```php
Route::view('/operations', 'gestion.operations')->name('operations');
```

Par :
```php
Route::view('/operations', 'gestion.operations.index')->name('operations');
Route::get('/operations/{operation}', function (Operation $operation) {
    return view('gestion.operations.show', compact('operation'));
})->name('operations.show');
Route::get('/operations/{operation}/participants/{participant}', function (Operation $operation, Participant $participant) {
    abort_unless($participant->operation_id === $operation->id, 404);
    return view('gestion.operations.participant', compact('operation', 'participant'));
})->name('operations.participants.show');
```

Ajouter les imports en haut du fichier :
```php
use App\Models\Participant;
```

Note : `Operation` est déjà importé.

- [ ] **Step 4: Créer les vues squelettes**

`resources/views/gestion/operations/index.blade.php` :
```blade
<x-app-layout>
    <livewire:operation-list />
</x-app-layout>
```

`resources/views/gestion/operations/show.blade.php` :
```blade
<x-app-layout>
    <livewire:operation-detail :operation="$operation" />
</x-app-layout>
```

`resources/views/gestion/operations/participant.blade.php` :
```blade
<x-app-layout>
    <livewire:participant-show :operation="$operation" :participant="$participant" />
</x-app-layout>
```

- [ ] **Step 5: Créer les composants Livewire squelettes**

`app/Livewire/OperationList.php` :
```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Component;

final class OperationList extends Component
{
    public function render(): View
    {
        return view('livewire.operation-list');
    }
}
```

`resources/views/livewire/operation-list.blade.php` :
```blade
<div>
    <h4>Gestion des opérations</h4>
    <p class="text-muted">En construction</p>
</div>
```

`app/Livewire/OperationDetail.php` :
```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use Illuminate\View\View;
use Livewire\Component;

final class OperationDetail extends Component
{
    public Operation $operation;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation->loadMissing('typeOperation.sousCategorie');
    }

    public function render(): View
    {
        return view('livewire.operation-detail');
    }
}
```

`resources/views/livewire/operation-detail.blade.php` :
```blade
<div>
    <p>{{ $operation->nom }}</p>
</div>
```

- [ ] **Step 6: Lancer les tests**

Run: `./vendor/bin/sail test tests/Feature/GestionOperationNavigationTest.php`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/GestionOperationNavigationTest.php routes/web.php \
  resources/views/gestion/operations/ app/Livewire/OperationList.php \
  app/Livewire/OperationDetail.php resources/views/livewire/operation-list.blade.php \
  resources/views/livewire/operation-detail.blade.php
git commit -m "feat: routes et squelettes pour navigation 3 niveaux gestion opérations"
```

---

## Task 2 : Composant breadcrumb réutilisable

**Files:**
- Create: `resources/views/components/operation-breadcrumb.blade.php`

- [ ] **Step 1: Créer le composant Blade**

`resources/views/components/operation-breadcrumb.blade.php` :
```blade
@props([
    'operation' => null,
    'participant' => null,
    'operationMeta' => null,
    'participantMeta' => null,
])

<nav class="d-flex align-items-center mb-3" style="font-size: 13px;">
    {{-- Bouton retour --}}
    @if($participant)
        <a href="{{ route('gestion.operations.show', $operation) }}" class="text-decoration-none me-3" title="Retour aux participants">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
    @elseif($operation)
        <a href="{{ route('gestion.operations') }}" class="text-decoration-none me-3" title="Retour à la liste">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
    @endif

    {{-- Segments breadcrumb --}}
    <div class="d-flex align-items-center gap-1 flex-grow-1 text-truncate">
        @if($operation)
            <a href="{{ route('gestion.operations') }}" class="text-decoration-none" style="color: #A9014F;">Gestion des opérations</a>
            <span class="text-muted">/</span>
        @endif

        @if($participant)
            <a href="{{ route('gestion.operations.show', $operation) }}" class="text-decoration-none" style="color: #A9014F;">{{ $operation->nom }}</a>
            <span class="text-muted">/</span>
            <strong class="text-dark">{{ $participant->tiers?->prenom }} {{ $participant->tiers?->nom }}</strong>
            @if($participantMeta)
                <span class="text-muted ms-2" style="font-size: 11px;">{{ $participantMeta }}</span>
            @endif
        @elseif($operation)
            <strong class="text-dark">{{ $operation->nom }}</strong>
            @if($operation->typeOperation?->sousCategorie)
                @php
                    $sousCat = $operation->typeOperation->sousCategorie;
                    $badgeColors = [
                        'Parcours thérapeutiques' => ['bg' => '#e8f0fe', 'text' => '#1a56db'],
                        'Formations' => ['bg' => '#fce8f0', 'text' => '#A9014F'],
                    ];
                    $colors = $badgeColors[$sousCat->nom] ?? ['bg' => '#f0f0f0', 'text' => '#555'];
                @endphp
                <span class="ms-1" style="background: {{ $colors['bg'] }}; color: {{ $colors['text'] }}; padding: 1px 7px; border-radius: 3px; font-size: 10px;">
                    {{ $sousCat->nom }}
                </span>
            @endif
            @if($operationMeta)
                <span class="text-muted ms-2" style="font-size: 11px;">{{ $operationMeta }}</span>
            @endif
        @endif
    </div>

    {{-- Slot pour boutons à droite (engrenage, enregistrer...) --}}
    {{ $slot }}
</nav>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/operation-breadcrumb.blade.php
git commit -m "feat: composant breadcrumb réutilisable pour navigation opérations"
```

---

## Task 3 : Niveau 1 — Liste des opérations (OperationList)

**Files:**
- Modify: `app/Livewire/OperationList.php`
- Modify: `resources/views/livewire/operation-list.blade.php`
- Test: `tests/Feature/GestionOperationNavigationTest.php`

- [ ] **Step 1: Ajouter les tests du niveau 1**

Ajouter dans `tests/Feature/GestionOperationNavigationTest.php` :
```php
test('niveau 1: opérations listées dans le tableau', function (): void {
    $user = User::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Equithérapie', 'actif' => true]);
    $op = Operation::factory()->create([
        'nom' => 'Parcours Cheval Bleu',
        'type_operation_id' => $type->id,
        'date_debut' => now()->addDays(14),
        'date_fin' => now()->addMonths(9),
    ]);
    Participant::factory()->count(3)->create(['operation_id' => $op->id]);

    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertSee('Parcours Cheval Bleu')
        ->assertSee('Equithérapie')
        ->assertSee('3'); // participant count
});

test('niveau 1: filtre par type fonctionne', function (): void {
    $user = User::factory()->create();
    $type1 = TypeOperation::factory()->create(['nom' => 'Type A', 'actif' => true]);
    $type2 = TypeOperation::factory()->create(['nom' => 'Type B', 'actif' => true]);
    Operation::factory()->create(['nom' => 'Op A', 'type_operation_id' => $type1->id]);
    Operation::factory()->create(['nom' => 'Op B', 'type_operation_id' => $type2->id]);

    Livewire::test(OperationList::class)
        ->set('filterTypeId', $type1->id)
        ->assertSee('Op A')
        ->assertDontSee('Op B');
});

test('niveau 1: clic sur ligne redirige vers niveau 2', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Test Redirect']);

    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertSee("gestion/operations/{$op->id}");
});

test('niveau 1: opérations clôturées affichées en opacité réduite', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create([
        'nom' => 'Op Clôturée',
        'statut' => \App\Enums\StatutOperation::Cloturee,
    ]);

    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertSee('Op Clôturée')
        ->assertSee('opacity');
});
```

Ajouter l'import Livewire en haut du fichier :
```php
use App\Livewire\OperationList;
use Livewire\Livewire;
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `./vendor/bin/sail test tests/Feature/GestionOperationNavigationTest.php`
Expected: FAIL

- [ ] **Step 3: Implémenter OperationList.php**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutOperation;
use App\Models\Operation;
use App\Models\TypeOperation;
use App\Services\ExerciceService;
use Carbon\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class OperationList extends Component
{
    #[Url(as: 'type')]
    public ?int $filterTypeId = null;

    #[Url(as: 'exercice')]
    public ?int $filterExercice = null;

    // ── Modale CRUD ──
    public bool $showCreateModal = false;

    public bool $showEditModal = false;

    public ?int $editOperationId = null;

    public string $formNom = '';

    public string $formDescription = '';

    public string $formDateDebut = '';

    public string $formDateFin = '';

    public ?int $formNombreSeances = null;

    public ?int $formTypeOperationId = null;

    public function mount(): void
    {
        $this->filterExercice ??= app(ExerciceService::class)->current();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEditModal(int $operationId): void
    {
        $op = Operation::findOrFail($operationId);
        $this->editOperationId = $op->id;
        $this->formNom = $op->nom;
        $this->formDescription = $op->description ?? '';
        $this->formDateDebut = $op->date_debut?->format('Y-m-d') ?? '';
        $this->formDateFin = $op->date_fin?->format('Y-m-d') ?? '';
        $this->formNombreSeances = $op->nombre_seances;
        $this->formTypeOperationId = $op->type_operation_id;
        $this->showEditModal = true;
    }

    public function saveOperation(): void
    {
        $validated = $this->validate([
            'formNom' => 'required|string|max:150',
            'formDescription' => 'nullable|string',
            'formDateDebut' => 'required|date',
            'formDateFin' => 'required|date|after_or_equal:formDateDebut',
            'formNombreSeances' => 'nullable|integer|min:1',
            'formTypeOperationId' => 'required|exists:type_operations,id',
        ]);

        $data = [
            'nom' => $validated['formNom'],
            'description' => $validated['formDescription'] ?: null,
            'date_debut' => $validated['formDateDebut'],
            'date_fin' => $validated['formDateFin'],
            'nombre_seances' => $validated['formNombreSeances'],
            'type_operation_id' => $validated['formTypeOperationId'],
        ];

        if ($this->editOperationId) {
            Operation::findOrFail($this->editOperationId)->update($data);
            $this->dispatch('notify', message: 'Opération mise à jour.');
        } else {
            $data['statut'] = StatutOperation::EnCours;
            Operation::create($data);
            $this->dispatch('notify', message: 'Opération créée.');
        }

        $this->showCreateModal = false;
        $this->showEditModal = false;
        $this->resetForm();
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($this->filterExercice);

        $query = Operation::query()
            ->with(['typeOperation.sousCategorie'])
            ->withCount('participants')
            ->where(function ($q) use ($range): void {
                $q->where(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNotNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_fin', '>=', $range['start']->toDateString());
                })->orWhere(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_debut', '>=', $range['start']->toDateString());
                });
            });

        if ($this->filterTypeId !== null) {
            $query->where('type_operation_id', $this->filterTypeId);
        }

        $operations = $query->orderBy('date_debut')->get();

        return view('livewire.operation-list', [
            'operations' => $operations,
            'typeOperations' => TypeOperation::actif()->orderBy('nom')->get(),
            'exercice' => $this->filterExercice,
        ]);
    }

    private function resetForm(): void
    {
        $this->editOperationId = null;
        $this->formNom = '';
        $this->formDescription = '';
        $this->formDateDebut = '';
        $this->formDateFin = '';
        $this->formNombreSeances = null;
        $this->formTypeOperationId = null;
    }
}
```

- [ ] **Step 4: Implémenter la vue operation-list.blade.php**

```blade
<div>
    {{-- Titre + bouton créer --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Gestion des opérations</h4>
        <button class="btn btn-sm text-white" style="background-color: #A9014F;" wire:click="openCreateModal">
            <i class="bi bi-plus-lg me-1"></i> Nouvelle opération
        </button>
    </div>

    {{-- Filtres --}}
    <div class="d-flex gap-2 align-items-center mb-3">
        <select class="form-select form-select-sm" style="max-width: 180px;" wire:model.live="filterExercice">
            @for($y = now()->year + 1; $y >= now()->year - 3; $y--)
                <option value="{{ $y }}">{{ $y }}/{{ $y + 1 }}</option>
            @endfor
        </select>
        <select class="form-select form-select-sm" style="max-width: 220px;" wire:model.live="filterTypeId">
            <option value="">Tous les types</option>
            @foreach($typeOperations as $type)
                <option value="{{ $type->id }}">{{ $type->nom }}</option>
            @endforeach
        </select>
        <span class="text-muted ms-auto" style="font-size: 12px;">{{ $operations->count() }} opération{{ $operations->count() > 1 ? 's' : '' }}</span>
    </div>

    {{-- Tableau --}}
    <table class="table table-hover align-middle mb-0" id="operations-table">
        <thead>
            <tr style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880" class="table-dark">
                <th style="cursor:pointer" data-sort="string">Type</th>
                <th style="cursor:pointer" data-sort="string">Opération</th>
                <th style="cursor:pointer" data-sort="string">Période</th>
                <th style="cursor:pointer; text-align:center" data-sort="number">Participants</th>
                <th style="width:50px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($operations as $op)
                @php
                    $isCloturee = $op->statut === \App\Enums\StatutOperation::Cloturee;
                    $sousCat = $op->typeOperation?->sousCategorie;
                    $badgeColors = [
                        'Parcours thérapeutiques' => ['bg' => '#e8f0fe', 'text' => '#1a56db'],
                        'Formations' => ['bg' => '#fce8f0', 'text' => '#A9014F'],
                    ];
                    $colors = $badgeColors[$sousCat?->nom ?? ''] ?? ['bg' => '#f0f0f0', 'text' => '#555'];

                    // Date contextuelle
                    $now = now();
                    if ($op->date_debut && $op->date_fin) {
                        if ($op->date_debut->isFuture()) {
                            $diff = (int) $now->diffInDays($op->date_debut);
                            $dateContexte = "Débute dans {$diff} jour" . ($diff > 1 ? 's' : '');
                            $dateColor = '#198754';
                        } elseif ($op->date_fin->isFuture()) {
                            $diff = (int) $op->date_debut->diffInDays($now);
                            $dateContexte = "En cours depuis {$diff} jour" . ($diff > 1 ? 's' : '');
                            $dateColor = '#0d6efd';
                        } else {
                            $diff = (int) $op->date_fin->diffInDays($now);
                            if ($diff > 60) {
                                $mois = (int) round($diff / 30);
                                $dateContexte = "Terminée depuis {$mois} mois";
                            } else {
                                $dateContexte = "Terminée depuis {$diff} jour" . ($diff > 1 ? 's' : '');
                            }
                            $dateColor = '#6c757d';
                        }
                    } else {
                        $dateContexte = '—';
                        $dateColor = '#999';
                    }
                @endphp
                <tr style="{{ $isCloturee ? 'opacity: 0.5;' : '' }} cursor: pointer;"
                    onclick="if (!event.target.closest('.btn-gear')) window.location='{{ route('gestion.operations.show', $op) }}'">
                    <td data-sort="{{ $sousCat?->nom ?? '' }}">
                        <span style="background: {{ $colors['bg'] }}; color: {{ $colors['text'] }}; padding: 2px 8px; border-radius: 3px; font-size: 11px; white-space: nowrap;">
                            {{ $op->typeOperation?->nom ?? '—' }}
                        </span>
                    </td>
                    <td data-sort="{{ $op->nom }}" class="fw-medium">{{ $op->nom }}</td>
                    <td data-sort="{{ $op->date_debut?->format('Y-m-d') ?? '' }}">
                        <div style="color: {{ $dateColor }}; font-size: 12px; font-weight: 500;">{{ $dateContexte }}</div>
                        <div class="text-muted" style="font-size: 11px;">
                            {{ $op->date_debut?->format('d/m/Y') ?? '?' }} — {{ $op->date_fin?->format('d/m/Y') ?? '?' }}
                        </div>
                    </td>
                    <td class="text-center" data-sort="{{ $op->participants_count }}">
                        <span style="background: #f0f0f0; padding: 2px 10px; border-radius: 10px; font-weight: 600;">
                            {{ $op->participants_count }}
                        </span>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-link text-muted p-0 btn-gear" title="Paramètres"
                                wire:click.stop="openEditModal({{ $op->id }})">
                            <i class="bi bi-gear" style="font-size: 16px;"></i>
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        <i class="bi bi-calendar-event" style="font-size: 2rem; opacity: 0.3;"></i>
                        <p class="mt-2 mb-0">Aucune opération pour cet exercice.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Modale Créer --}}
    @if($showCreateModal)
        @include('livewire.partials.operation-form-modal', ['title' => 'Nouvelle opération'])
    @endif

    {{-- Modale Modifier --}}
    @if($showEditModal)
        @include('livewire.partials.operation-form-modal', ['title' => 'Paramètres de l\'opération'])
    @endif
</div>
```

- [ ] **Step 5: Créer la vue partielle de la modale CRUD**

`resources/views/livewire/partials/operation-form-modal.blade.php` :
```blade
<div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5);">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ $title }}</h5>
                <button type="button" class="btn-close" wire:click="$set('showCreateModal', false); $set('showEditModal', false)"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Type d'opération <span class="text-danger">*</span></label>
                    <select wire:model="formTypeOperationId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach(\App\Models\TypeOperation::actif()->orderBy('nom')->get() as $type)
                            <option value="{{ $type->id }}">{{ $type->nom }}</option>
                        @endforeach
                    </select>
                    @error('formTypeOperationId') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">Nom <span class="text-danger">*</span></label>
                    <input type="text" wire:model="formNom" class="form-control form-control-sm" maxlength="150">
                    @error('formNom') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea wire:model="formDescription" class="form-control form-control-sm" rows="2"></textarea>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Date début <span class="text-danger">*</span></label>
                        <input type="date" wire:model="formDateDebut" class="form-control form-control-sm">
                        @error('formDateDebut') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date fin <span class="text-danger">*</span></label>
                        <input type="date" wire:model="formDateFin" class="form-control form-control-sm">
                        @error('formDateFin') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Nb séances</label>
                        <input type="number" wire:model="formNombreSeances" class="form-control form-control-sm" min="1">
                    </div>
                </div>
                @if($editOperationId)
                    <div class="text-end">
                        <a href="{{ route('gestion.parametres.type-operations.index') }}" class="small text-muted">
                            <i class="bi bi-gear"></i> Réglages avancés du type d'opération
                        </a>
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" wire:click="$set('showCreateModal', false); $set('showEditModal', false)">Annuler</button>
                <button class="btn btn-sm text-white" style="background-color: #A9014F;" wire:click="saveOperation">
                    {{ $editOperationId ? 'Enregistrer' : 'Créer' }}
                </button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 6: Lancer les tests**

Run: `./vendor/bin/sail test tests/Feature/GestionOperationNavigationTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/OperationList.php resources/views/livewire/operation-list.blade.php \
  resources/views/livewire/partials/operation-form-modal.blade.php \
  tests/Feature/GestionOperationNavigationTest.php
git commit -m "feat: niveau 1 — liste des opérations avec tableau, filtres et modale CRUD"
```

---

## Task 4 : Niveau 2 — Détail opération avec onglets

**Files:**
- Modify: `app/Livewire/OperationDetail.php`
- Modify: `resources/views/livewire/operation-detail.blade.php`
- Modify: `resources/views/livewire/participant-table.blade.php` (liens vers niveau 3)

- [ ] **Step 1: Implémenter OperationDetail.php**

Remplacer le squelette par la version complète. Ce composant reprend la logique financière de `GestionOperations` mais sans le sélecteur d'opération :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use Illuminate\View\View;
use Livewire\Component;

final class OperationDetail extends Component
{
    public Operation $operation;

    public string $activeTab = 'participants';

    public function mount(Operation $operation): void
    {
        $this->operation = $operation->loadMissing('typeOperation.sousCategorie');
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render(): View
    {
        $operation = $this->operation;

        $totalDepenses = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'depense'))
            ->sum('montant');
        $totalRecettes = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
            ->whereDoesntHave('sousCategorie', fn ($q) => $q->where('pour_dons', true))
            ->sum('montant');
        $totalDons = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
            ->whereHas('sousCategorie', fn ($q) => $q->where('pour_dons', true))
            ->sum('montant');
        $solde = ($totalRecettes + $totalDons) - $totalDepenses;

        $participantsCount = $operation->participants()->count();
        $seancesCount = $operation->seances()->count();

        // Metadata pour le breadcrumb
        $dateParts = [];
        if ($operation->date_debut) {
            $dateParts[] = $operation->date_debut->format('d/m');
        }
        if ($operation->date_fin) {
            $dateParts[] = $operation->date_fin->format('d/m');
        }
        $operationMeta = implode(' — ', $dateParts);
        if ($participantsCount > 0) {
            $operationMeta .= " · {$participantsCount} participant" . ($participantsCount > 1 ? 's' : '');
        }
        if ($seancesCount > 0) {
            $operationMeta .= " · {$seancesCount} séance" . ($seancesCount > 1 ? 's' : '');
        }

        return view('livewire.operation-detail', [
            'totalDepenses' => $totalDepenses,
            'totalRecettes' => $totalRecettes,
            'totalDons' => $totalDons,
            'solde' => $solde,
            'participantsCount' => $participantsCount,
            'operationMeta' => $operationMeta,
        ]);
    }
}
```

- [ ] **Step 2: Implémenter la vue operation-detail.blade.php**

```blade
<div>
    {{-- Breadcrumb --}}
    <x-operation-breadcrumb :operation="$operation" :operationMeta="$operationMeta">
        <button class="btn btn-sm btn-link text-muted p-0" title="Paramètres"
                onclick="Livewire.dispatch('openEditModalFromDetail', { id: {{ $operation->id }} })">
            <i class="bi bi-gear" style="font-size: 16px;"></i>
        </button>
    </x-operation-breadcrumb>

    {{-- Onglets --}}
    <style>
        .nav-gestion .nav-link { color: #666; }
        .nav-gestion .nav-link:hover:not(.disabled) { color: #A9014F; }
        .nav-gestion .nav-link.active { color: #A9014F; font-weight: 600; border-color: #dee2e6 #dee2e6 #fff; }
    </style>
    <ul class="nav nav-tabs nav-gestion mb-3">
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'participants' ? 'active' : '' }}" wire:click="setTab('participants')">
                <i class="bi bi-people me-1"></i>Participants ({{ $participantsCount }})
            </button>
        </li>
        @if(auth()->user()?->peut_voir_donnees_sensibles)
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'seances' ? 'active' : '' }}" wire:click="setTab('seances')">
                <i class="bi bi-calendar-week me-1"></i>Séances
            </button>
        </li>
        @endif
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'reglements' ? 'active' : '' }}" wire:click="setTab('reglements')">
                <i class="bi bi-wallet2 me-1"></i>Règlements
            </button>
        </li>
        <li class="nav-item d-flex align-items-end" style="padding:0 4px">
            <span style="border-left:1px solid #ccc;height:20px;display:inline-block;margin-bottom:8px"></span>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'details' ? 'active' : '' }}" wire:click="setTab('details')">
                <i class="bi bi-card-text me-1"></i>Détails
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'compte_resultat' ? 'active' : '' }}" wire:click="setTab('compte_resultat')">
                <i class="bi bi-bar-chart-line me-1"></i>Compte résultat
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'compte_resultat_seances' ? 'active' : '' }}" wire:click="setTab('compte_resultat_seances')">
                <i class="bi bi-grid-3x3-gap me-1"></i>Résultat par séances
            </button>
        </li>
    </ul>

    {{-- Contenu des onglets --}}
    @if($activeTab === 'participants')
        <livewire:participant-table :operation="$operation" :key="'pt-'.$operation->id" />
    @endif

    @if($activeTab === 'seances')
        <livewire:seance-table :operation="$operation" :key="'st-'.$operation->id" />
    @endif

    @if($activeTab === 'reglements')
        <livewire:reglement-table :operation="$operation" :key="'rt-'.$operation->id" />
    @endif

    @if($activeTab === 'details')
        <div class="card">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Nom</dt>
                    <dd class="col-sm-9">{{ $operation->nom }}</dd>
                    <dt class="col-sm-3">Description</dt>
                    <dd class="col-sm-9">{{ $operation->description ?? '—' }}</dd>
                    <dt class="col-sm-3">Date début</dt>
                    <dd class="col-sm-9">{{ $operation->date_debut?->format('d/m/Y') ?? '—' }}</dd>
                    <dt class="col-sm-3">Date fin</dt>
                    <dd class="col-sm-9">{{ $operation->date_fin?->format('d/m/Y') ?? '—' }}</dd>
                    <dt class="col-sm-3">Nombre de séances</dt>
                    <dd class="col-sm-9">{{ $operation->nombre_seances ?? '—' }}</dd>
                    <dt class="col-sm-3">Statut</dt>
                    <dd class="col-sm-9">
                        <span class="badge {{ $operation->statut === \App\Enums\StatutOperation::EnCours ? 'bg-success' : 'bg-secondary' }}">
                            {{ $operation->statut->label() }}
                        </span>
                    </dd>
                </dl>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">Bilan financier</h6></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td>Total dépenses</td><td class="text-end text-danger fw-bold">{{ number_format($totalDepenses, 2, ',', ' ') }} €</td></tr>
                        <tr><td>Total recettes</td><td class="text-end text-success fw-bold">{{ number_format($totalRecettes, 2, ',', ' ') }} €</td></tr>
                        <tr><td>Total dons</td><td class="text-end text-success fw-bold">{{ number_format($totalDons, 2, ',', ' ') }} €</td></tr>
                        <tr class="table-active"><td class="fw-bold">Solde</td><td class="text-end fw-bold {{ $solde >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($solde, 2, ',', ' ') }} €</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($activeTab === 'compte_resultat')
        <livewire:rapport-compte-resultat-operations :selectedOperationIds="[$operation->id]" :key="'cr-'.$operation->id" />
    @endif

    @if($activeTab === 'compte_resultat_seances')
        <livewire:rapport-seances :selectedOperationIds="[$operation->id]" :key="'rs-'.$operation->id" />
    @endif
</div>
```

- [ ] **Step 3: Modifier participant-table.blade.php — liens vers niveau 3**

Dans `resources/views/livewire/participant-table.blade.php`, remplacer les 3 occurrences de `$dispatch('open-participant')` :

Remplacer :
```blade
<a href="#" wire:click.prevent="$dispatch('open-participant', { id: {{ $p->id }} })" class="text-decoration-none fw-semibold">
```

Par :
```blade
<a href="{{ route('gestion.operations.participants.show', [$operation, $p]) }}" class="text-decoration-none fw-semibold">
```

Et remplacer le bouton dispatch (ligne ~349) :
```blade
wire:click="$dispatch('open-participant', { id: {{ $p->id }} })"
```

Par :
```blade
onclick="window.location='{{ route('gestion.operations.participants.show', [$operation, $p]) }}'"
```

- [ ] **Step 4: Lancer les tests**

Run: `./vendor/bin/sail test tests/Feature/GestionOperationNavigationTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/OperationDetail.php resources/views/livewire/operation-detail.blade.php \
  resources/views/livewire/participant-table.blade.php
git commit -m "feat: niveau 2 — détail opération avec breadcrumb et onglets"
```

---

## Task 5 : Niveau 3 — Fiche participant autonome

**Files:**
- Modify: `app/Livewire/ParticipantShow.php`
- Modify: `resources/views/livewire/participant-show.blade.php`
- Create: `resources/views/components/unsaved-changes-modal.blade.php`

- [ ] **Step 1: Créer le composant modale de confirmation**

`resources/views/components/unsaved-changes-modal.blade.php` :
```blade
{{-- Modale de confirmation modifications non sauvegardées --}}
<div x-show="showUnsavedModal" x-cloak class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5);">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Modifications non enregistrées</h6>
            </div>
            <div class="modal-body">
                <p class="mb-0">Vous avez des modifications non enregistrées. Que souhaitez-vous faire ?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-sm btn-outline-secondary" @click="showUnsavedModal = false; window.location = pendingUrl;">
                    Abandonner les modifications
                </button>
                <button class="btn btn-sm btn-primary" @click="showUnsavedModal = false; $wire.save().then(() => window.location = pendingUrl);">
                    Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Adapter participant-show.blade.php**

Remplacer le `x-data` en ligne 1 et le header (lignes 1-20), et le footer (lignes 552-567) :

Nouveau contenu en haut du fichier :
```blade
<div x-data="{
        isDirty: false,
        showUnsavedModal: false,
        pendingUrl: '',
        navigateTo(url) {
            if (this.isDirty) {
                this.pendingUrl = url;
                this.showUnsavedModal = true;
            } else {
                window.location = url;
            }
        }
     }"
     x-on:input="isDirty = true"
     x-on:beforeunload.window="if (isDirty) { $event.preventDefault(); $event.returnValue = ''; }">

    {{-- Breadcrumb avec bouton Enregistrer --}}
    @php
        $tiers = $participant->tiers;
        $tarif = $participant->typeOperationTarif;
        $metaParts = array_filter([
            $tiers?->telephone,
            $tiers?->email,
            $tarif?->libelle,
        ]);
        $participantMeta = implode(' · ', $metaParts);
    @endphp

    <x-operation-breadcrumb :operation="$operation" :participant="$participant" :participantMeta="$participantMeta">
        @if($successMessage)
            <span class="text-success me-2" style="font-size: 12px;"><i class="bi bi-check-lg"></i> {{ $successMessage }}</span>
        @endif
        <button type="button" class="btn btn-sm btn-primary" wire:click="save" x-on:click="isDirty = false">
            <i class="bi bi-check-lg"></i> Enregistrer
        </button>
    </x-operation-breadcrumb>
```

Le breadcrumb utilise `navigateTo()` dans son composant. On doit aussi intercepter les clics sur le breadcrumb. Modifier `resources/views/components/operation-breadcrumb.blade.php` pour supporter un mode "dirty-aware" : les liens du breadcrumb utilisent `@click.prevent="$parent.navigateTo('url')"` quand `x-data` est présent au niveau du parent. Pour simplifier, on ajoute un attribut `data-navigate` aux liens :

Mettre à jour les liens dans `operation-breadcrumb.blade.php` pour utiliser des `<a>` normaux — le JS interceptera les clics globalement au niveau du wrapper `x-data` du participant-show. On n'a pas besoin de modifier le breadcrumb car la navigation fonctionne via `x-on:beforeunload` comme filet de sécurité, et les liens sont des vrais liens HTML. La modale se déclenche uniquement dans `participant-show` via les boutons Retour et breadcrumb.

Pour intercepter proprement, ajouter au `x-data` du participant-show :
```blade
x-on:click.window="
    if (isDirty) {
        const link = $event.target.closest('a[href*=\'/gestion/operations\']');
        if (link && !link.closest('.btn-primary')) {
            $event.preventDefault();
            pendingUrl = link.href;
            showUnsavedModal = true;
        }
    }
"
```

Supprimer les boutons Annuler/Enregistrer du bas du fichier (lignes 552-567 de l'ancien fichier).

- [ ] **Step 3: Adapter ParticipantShow.php — save() ne dispatch plus close-participant**

Dans `app/Livewire/ParticipantShow.php`, modifier la méthode `save()` :

Remplacer :
```php
$this->dispatch('close-participant');
```

Par :
```php
$this->successMessage = 'Modifications enregistrées.';
```

- [ ] **Step 4: Ajouter la modale dans la vue**

À la fin de `participant-show.blade.php`, avant le `</div>` fermant, ajouter :
```blade
    <x-unsaved-changes-modal />
</div>
```

- [ ] **Step 5: Lancer les tests**

Run: `./vendor/bin/sail test tests/Feature/GestionOperationNavigationTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/ParticipantShow.php resources/views/livewire/participant-show.blade.php \
  resources/views/components/unsaved-changes-modal.blade.php \
  resources/views/components/operation-breadcrumb.blade.php
git commit -m "feat: niveau 3 — fiche participant autonome avec breadcrumb et modale sauvegarde"
```

---

## Task 6 : Mise à jour navigation et nettoyage

**Files:**
- Modify: `resources/views/layouts/app.blade.php:300-312`
- Delete: `app/Livewire/GestionOperations.php`
- Delete: `resources/views/livewire/gestion-operations.blade.php`
- Delete: `resources/views/gestion/operations.blade.php`
- Modify: `tests/Feature/GestionOperationsTest.php`

- [ ] **Step 1: Mettre à jour le lien nav dans app.blade.php**

Dans `resources/views/layouts/app.blade.php`, le lien du menu fonctionne déjà car il pointe vers `route('gestion.operations')` et le `routeIs('gestion.operations*')` matche les 3 niveaux. Aucun changement nécessaire.

Vérifier en relisant les lignes 300-312 — si le pattern `gestion.operations*` couvre bien `gestion.operations.show` et `gestion.operations.participants.show`.

- [ ] **Step 2: Mettre à jour les tests existants**

Remplacer `tests/Feature/GestionOperationsTest.php` :
```php
<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\User;

test('gestion operations page loads with operation list', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertOk()
        ->assertSee('Gestion des opérations');
});

test('operations are listed in table', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Art-thérapie test']);
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertSee('Art-thérapie test');
});

test('operation detail page loads', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Sophrologie test']);
    $this->actingAs($user)
        ->get("/gestion/operations/{$op->id}")
        ->assertOk()
        ->assertSee('Sophrologie test');
});
```

- [ ] **Step 3: Supprimer les anciens fichiers**

```bash
rm app/Livewire/GestionOperations.php
rm resources/views/livewire/gestion-operations.blade.php
rm resources/views/gestion/operations.blade.php
```

- [ ] **Step 4: Lancer la suite de tests complète**

Run: `./vendor/bin/sail test`
Expected: PASS (vérifier qu'aucun test ne référence l'ancien composant)

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: nettoyage — suppression ancien composant GestionOperations monolithique"
```

---

## Task 7 : Test intégration complète et pint

**Files:**
- All files created/modified above

- [ ] **Step 1: Lancer pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Lancer la suite de tests complète**

Run: `./vendor/bin/sail test`
Expected: PASS

- [ ] **Step 3: Vérifier manuellement les 3 niveaux**

Instructions pour test manuel :
1. Aller sur http://localhost/gestion/operations → doit afficher le tableau des opérations
2. Cliquer sur une opération → doit afficher le détail avec breadcrumb et onglets
3. Onglet Participants par défaut, cliquer sur un participant → fiche avec breadcrumb 3 niveaux
4. Bouton "← Retour" ramène au niveau précédent
5. Breadcrumb cliquable sur chaque segment
6. Bouton retour navigateur fonctionne correctement
7. Engrenage → modale paramètres rapides
8. Bouton "+ Nouvelle opération" → modale de création
9. Modifier un champ participant, cliquer Retour → modale "Enregistrer / Abandonner"
10. Opérations clôturées affichées en opacité réduite

- [ ] **Step 4: Commit final si corrections pint**

```bash
git add -A
git commit -m "style: pint formatting"
```
