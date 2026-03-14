# Améliorations UX et Virements Internes — Plan d'implémentation

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 7 modifications : opérations dans Paramètres, montant calculé automatiquement, UX formulaires dépenses/recettes, et nouveau module virements internes.

**Architecture:** Modifications Livewire (DepenseForm, RecetteForm) + ajout onglet Paramètres + nouveau module VirementInterne avec migration/modèle/service/Livewire. Pas de JS custom, tout en Livewire + Blade Bootstrap 5.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Pest PHP, MySQL via Sail

---

## Task 1 : Opérations dans l'onglet Paramètres

**Context:** Le modèle `Operation` existe déjà (nom, description, date_debut, date_fin, nombre_seances, statut). Il y a un `OperationController` complet à `/operations`. Il faut ajouter un onglet "Opérations" dans `/parametres` avec le même pattern (formulaire collapse + table).

**Files:**
- Modify: `app/Http/Controllers/ParametreController.php`
- Modify: `resources/views/parametres/index.blade.php`

**Step 1: Ajouter les opérations au ParametreController**

```php
// app/Http/Controllers/ParametreController.php
use App\Models\Operation;

public function index(): View
{
    return view('parametres.index', [
        'categories' => Categorie::with('sousCategories')->orderBy('nom')->get(),
        'comptesBancaires' => CompteBancaire::orderBy('nom')->get(),
        'utilisateurs' => User::orderBy('nom')->get(),
        'operations' => Operation::orderBy('nom')->get(),
    ]);
}
```

**Step 2: Ajouter un onglet Opérations dans la vue Paramètres**

Dans `resources/views/parametres/index.blade.php`, ajouter l'onglet dans le nav-tabs (après "Utilisateurs") :

```html
<li class="nav-item" role="presentation">
    <button class="nav-link" id="operations-tab" data-bs-toggle="tab"
            data-bs-target="#operations-pane" type="button" role="tab"
            aria-controls="operations-pane" aria-selected="false">
        <i class="bi bi-calendar-event"></i> Opérations
    </button>
</li>
```

Et ajouter le panneau correspondant dans `tab-content` (avant la fermeture `</div>` du tab-content) :

```html
{{-- ========== Opérations ========== --}}
<div class="tab-pane fade" id="operations-pane" role="tabpanel" aria-labelledby="operations-tab">
    <div class="mb-3">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                data-bs-target="#addOperationForm">
            <i class="bi bi-plus-lg"></i> Ajouter une opération
        </button>
    </div>

    <div class="collapse mb-3" id="addOperationForm">
        <div class="card card-body">
            <form action="{{ route('operations.store') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label for="op_nom" class="form-label">Nom</label>
                    <input type="text" name="nom" id="op_nom" class="form-control" required maxlength="150">
                </div>
                <div class="col-md-3">
                    <label for="op_description" class="form-label">Description</label>
                    <input type="text" name="description" id="op_description" class="form-control" maxlength="255">
                </div>
                <div class="col-md-2">
                    <label for="op_date_debut" class="form-label">Date début</label>
                    <input type="date" name="date_debut" id="op_date_debut" class="form-control">
                </div>
                <div class="col-md-2">
                    <label for="op_date_fin" class="form-label">Date fin</label>
                    <input type="date" name="date_fin" id="op_date_fin" class="form-control">
                </div>
                <div class="col-md-1">
                    <label for="op_seances" class="form-label">Séances</label>
                    <input type="number" name="nombre_seances" id="op_seances" class="form-control" min="1">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Nom</th>
                <th>Description</th>
                <th>Date début</th>
                <th>Date fin</th>
                <th>Séances</th>
                <th>Statut</th>
                <th style="width: 100px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($operations as $operation)
                <tr>
                    <td>{{ $operation->nom }}</td>
                    <td>{{ $operation->description ?? '—' }}</td>
                    <td>{{ $operation->date_debut?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $operation->date_fin?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $operation->nombre_seances ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $operation->statut->value === 'en_cours' ? 'bg-success' : 'bg-secondary' }}">
                            {{ $operation->statut->label() }}
                        </span>
                    </td>
                    <td>
                        <a href="{{ route('operations.edit', $operation) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-muted">Aucune opération enregistrée.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
```

**Step 3: Vérifier que StatutOperation a une méthode label()**

Lire `app/Enums/StatutOperation.php`. Si `label()` n'existe pas, utiliser `$operation->statut->value` directement dans la vue.

**Step 4: Commit**

```bash
git add app/Http/Controllers/ParametreController.php resources/views/parametres/index.blade.php
git commit -m "feat: add operations tab to parametres page"
```

---

## Task 2 : Montant total calculé automatiquement (DepenseForm + RecetteForm)

**Context:** Actuellement `montant_total` est un champ saisi manuellement et vérifié contre la somme des lignes. Il faut le calculer automatiquement.

**Files:**
- Modify: `app/Livewire/DepenseForm.php`
- Modify: `app/Livewire/RecetteForm.php`
- Modify: `resources/views/livewire/depense-form.blade.php`
- Modify: `resources/views/livewire/recette-form.blade.php`

**Step 1: Modifier DepenseForm.php**

- Supprimer la propriété `public string $montant_total = '';`
- Ajouter une méthode calculée :

```php
public function getMontantTotalProperty(): float
{
    return round(collect($this->lignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2);
}
```

- Dans `resetForm()`, retirer `'montant_total'` de la liste reset
- Dans `save()` :
  - Retirer la règle de validation `'montant_total' => [...]`
  - Retirer le bloc de custom validation (les 5 lignes qui vérifient lignesSum vs montant_total)
  - Modifier `$data` pour utiliser le calculé : `'montant_total' => $this->montantTotal,`
- Dans `edit()`, retirer `$this->montant_total = (string) $depense->montant_total;`

**Step 2: Modifier RecetteForm.php** — mêmes changements que DepenseForm.

**Step 3: Modifier depense-form.blade.php**

Remplacer le bloc `col-md-2` du montant_total (input number + error) par un affichage lecture seule :

```html
<div class="col-md-2">
    <label class="form-label">Montant total</label>
    <div class="form-control bg-light fw-bold text-end">
        {{ number_format($this->montantTotal, 2, ',', ' ') }} €
    </div>
</div>
```

Et dans le `<tfoot>` du tableau, supprimer le `@if` qui montrait le triangle d'avertissement (n'est plus utile).

**Step 4: Modifier recette-form.blade.php** — mêmes changements.

**Step 5: Tester manuellement**

Ouvrir http://localhost/depenses → "Nouvelle dépense" → ajouter une ligne avec montant 50 → vérifier que le total affiche 50,00 € automatiquement. Ajouter une deuxième ligne à 30 → total doit passer à 80,00 €.

**Step 6: Commit**

```bash
git add app/Livewire/DepenseForm.php app/Livewire/RecetteForm.php \
        resources/views/livewire/depense-form.blade.php \
        resources/views/livewire/recette-form.blade.php
git commit -m "feat: auto-calculate montant_total from lignes in depense and recette forms"
```

---

## Task 3 : UX formulaires — ligne par défaut, date du jour, dernier compte (DepenseForm + RecetteForm)

**Context:** Quand on clique "Nouvelle dépense/recette", le formulaire s'ouvre vide. Il faut : 1 ligne ouverte, date = aujourd'hui, compte = dernier utilisé.

**Files:**
- Modify: `app/Livewire/DepenseForm.php`
- Modify: `app/Livewire/RecetteForm.php`
- Modify: `resources/views/livewire/depense-form.blade.php`
- Modify: `resources/views/livewire/recette-form.blade.php`

**Step 1: Ajouter showNewForm() dans DepenseForm.php**

```php
public function showNewForm(): void
{
    $this->showForm = true;
    $this->date = now()->format('Y-m-d');

    $lastDepense = Depense::where('saisi_par', auth()->id())
        ->whereNotNull('compte_id')
        ->latest()
        ->first();
    $this->compte_id = $lastDepense?->compte_id;

    $this->addLigne();
}
```

**Step 2: Ajouter showNewForm() dans RecetteForm.php** — même logique avec `Recette::where(...)`.

**Step 3: Modifier depense-form.blade.php**

Remplacer `wire:click="$set('showForm', true)"` par `wire:click="showNewForm"` sur le bouton "Nouvelle dépense".

**Step 4: Modifier recette-form.blade.php** — même remplacement.

**Step 5: Tester manuellement**

Cliquer "Nouvelle dépense" → vérifier : date = aujourd'hui, 1 ligne vide présente, compte pré-sélectionné (si déjà saisi une dépense avant). Annuler puis rouvrir → même comportement. Vérifier que l'édition d'une dépense existante n'est pas impactée.

**Step 6: Commit**

```bash
git add app/Livewire/DepenseForm.php app/Livewire/RecetteForm.php \
        resources/views/livewire/depense-form.blade.php \
        resources/views/livewire/recette-form.blade.php
git commit -m "feat: default line, today date and last account in depense/recette new form"
```

---

## Task 4 : Réordonner les champs des formulaires

**Context:** Ordre voulu : Date → Référence → Libellé → Bénéficiaire/Payeur → Mode paiement → Compte bancaire → Notes. Actuellement : Date → Libellé → Montant total → Mode paiement → Bénéficiaire / Référence → Compte → Notes.

**Files:**
- Modify: `resources/views/livewire/depense-form.blade.php`
- Modify: `resources/views/livewire/recette-form.blade.php`

**Step 1: Réécrire le bloc de champs dans depense-form.blade.php**

Remplacer les deux `div.row.g-3` d'en-tête par un seul bloc réordonné :

```html
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
        <input type="date" wire:model="date" id="date"
               class="form-control @error('date') is-invalid @enderror">
        @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-2">
        <label for="reference" class="form-label">Référence</label>
        <input type="text" wire:model="reference" id="reference" class="form-control">
    </div>
    <div class="col-md-3">
        <label for="libelle" class="form-label">Libellé <span class="text-danger">*</span></label>
        <input type="text" wire:model="libelle" id="libelle"
               class="form-control @error('libelle') is-invalid @enderror">
        @error('libelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-2">
        <label for="beneficiaire" class="form-label">Bénéficiaire</label>
        <input type="text" wire:model="beneficiaire" id="beneficiaire" class="form-control">
    </div>
    <div class="col-md-2">
        <label for="mode_paiement" class="form-label">Mode paiement <span class="text-danger">*</span></label>
        <select wire:model="mode_paiement" id="mode_paiement"
                class="form-select @error('mode_paiement') is-invalid @enderror">
            <option value="">-- Choisir --</option>
            @foreach ($modesPaiement as $mode)
                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
            @endforeach
        </select>
        @error('mode_paiement') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-3">
        <label for="compte_id" class="form-label">Compte bancaire</label>
        <select wire:model="compte_id" id="compte_id" class="form-select">
            <option value="">-- Aucun --</option>
            @foreach ($comptes as $compte)
                <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label">Montant total</label>
        <div class="form-control bg-light fw-bold text-end">
            {{ number_format($this->montantTotal, 2, ',', ' ') }} €
        </div>
    </div>
    <div class="col-md-7">
        <label for="notes" class="form-label">Notes</label>
        <input type="text" wire:model="notes" id="notes" class="form-control">
    </div>
</div>
```

**Step 2: Même réorganisation dans recette-form.blade.php** (avec "Payeur" au lieu de "Bénéficiaire").

**Step 3: Commit**

```bash
git add resources/views/livewire/depense-form.blade.php \
        resources/views/livewire/recette-form.blade.php
git commit -m "feat: reorder form fields in depense and recette forms"
```

---

## Task 5 : Migration + Modèle VirementInterne

**Context:** Un virement interne transfère un montant d'un compte bancaire vers un autre. Pas de ligne analytique — c'est une opération de trésorerie pure.

**Files:**
- Create: `database/migrations/2025_01_01_000014_create_virements_internes_table.php`
- Create: `app/Models/VirementInterne.php`

**Step 1: Créer la migration**

```bash
./vendor/bin/sail artisan make:migration create_virements_internes_table --path=database/migrations
```

Puis renommer le fichier généré en `2025_01_01_000014_create_virements_internes_table.php` et écrire :

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
        Schema::create('virements_internes', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->decimal('montant', 10, 2);
            $table->foreignId('compte_source_id')->constrained('comptes_bancaires');
            $table->foreignId('compte_destination_id')->constrained('comptes_bancaires');
            $table->string('reference', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('saisi_par')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virements_internes');
    }
};
```

**Step 2: Créer le modèle**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class VirementInterne extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'date',
        'montant',
        'compte_source_id',
        'compte_destination_id',
        'reference',
        'notes',
        'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'montant' => 'decimal:2',
        ];
    }

    public function compteSource(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_source_id');
    }

    public function compteDestination(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_destination_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    /**
     * @param Builder<VirementInterne> $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->whereBetween('date', [
            "{$exercice}-09-01",
            ($exercice + 1).'-08-31',
        ]);
    }
}
```

**Step 3: Lancer la migration**

```bash
./vendor/bin/sail artisan migrate
```

Vérifier : `migrate:status` doit montrer la migration comme "Ran".

**Step 4: Commit**

```bash
git add database/migrations/2025_01_01_000014_create_virements_internes_table.php \
        app/Models/VirementInterne.php
git commit -m "feat: migration and model for virements internes"
```

---

## Task 6 : Service + Livewire VirementInterne

**Files:**
- Create: `app/Services/VirementInterneService.php`
- Create: `app/Livewire/VirementInterneForm.php`
- Create: `app/Livewire/VirementInterneList.php`

**Step 1: Créer VirementInterneService**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\VirementInterne;
use Illuminate\Support\Facades\DB;

final class VirementInterneService
{
    public function create(array $data): VirementInterne
    {
        return DB::transaction(function () use ($data) {
            $data['saisi_par'] = auth()->id();

            return VirementInterne::create($data);
        });
    }

    public function update(VirementInterne $virement, array $data): VirementInterne
    {
        return DB::transaction(function () use ($virement, $data) {
            $virement->update($data);

            return $virement->fresh();
        });
    }

    public function delete(VirementInterne $virement): void
    {
        $virement->delete();
    }
}
```

**Step 2: Créer VirementInterneForm.php**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\VirementInterne;
use App\Services\VirementInterneService;
use Livewire\Attributes\On;
use Livewire\Component;

final class VirementInterneForm extends Component
{
    public ?int $virementId = null;

    public string $date = '';

    public string $montant = '';

    public ?int $compte_source_id = null;

    public ?int $compte_destination_id = null;

    public ?string $reference = null;

    public ?string $notes = null;

    public bool $showForm = false;

    public function showNewForm(): void
    {
        $this->showForm = true;
        $this->date = now()->format('Y-m-d');
    }

    #[On('edit-virement')]
    public function edit(int $id): void
    {
        $virement = VirementInterne::findOrFail($id);

        $this->virementId = $virement->id;
        $this->date = $virement->date->format('Y-m-d');
        $this->montant = (string) $virement->montant;
        $this->compte_source_id = $virement->compte_source_id;
        $this->compte_destination_id = $virement->compte_destination_id;
        $this->reference = $virement->reference;
        $this->notes = $virement->notes;

        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'virementId', 'date', 'montant', 'compte_source_id',
            'compte_destination_id', 'reference', 'notes', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'date' => ['required', 'date'],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'compte_source_id' => ['required', 'exists:comptes_bancaires,id'],
            'compte_destination_id' => [
                'required',
                'exists:comptes_bancaires,id',
                'different:compte_source_id',
            ],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $data = [
            'date' => $this->date,
            'montant' => $this->montant,
            'compte_source_id' => $this->compte_source_id,
            'compte_destination_id' => $this->compte_destination_id,
            'reference' => $this->reference ?: null,
            'notes' => $this->notes ?: null,
        ];

        $service = app(VirementInterneService::class);

        if ($this->virementId) {
            $virement = VirementInterne::findOrFail($this->virementId);
            $service->update($virement, $data);
        } else {
            $service->create($data);
        }

        $this->dispatch('virement-saved');
        $this->resetForm();
    }

    public function render()
    {
        return view('livewire.virement-interne-form', [
            'comptes' => CompteBancaire::orderBy('nom')->get(),
        ]);
    }
}
```

**Step 3: Créer VirementInterneList.php**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\VirementInterne;
use App\Services\ExerciceService;
use App\Services\VirementInterneService;
use Livewire\Attributes\On;
use Livewire\Component;

final class VirementInterneList extends Component
{
    public int $exercice;

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    #[On('virement-saved')]
    public function refresh(): void {}

    public function delete(int $id): void
    {
        $virement = VirementInterne::findOrFail($id);
        app(VirementInterneService::class)->delete($virement);
    }

    public function render()
    {
        $virements = VirementInterne::with(['compteSource', 'compteDestination', 'saisiPar'])
            ->forExercice($this->exercice)
            ->orderByDesc('date')
            ->get();

        return view('livewire.virement-interne-list', [
            'virements' => $virements,
        ]);
    }
}
```

**Step 4: Commit**

```bash
git add app/Services/VirementInterneService.php \
        app/Livewire/VirementInterneForm.php \
        app/Livewire/VirementInterneList.php
git commit -m "feat: VirementInterneService and Livewire components"
```

---

## Task 7 : Vues Blade + Route + Navbar pour les virements

**Files:**
- Create: `resources/views/virements/index.blade.php`
- Create: `resources/views/livewire/virement-interne-form.blade.php`
- Create: `resources/views/livewire/virement-interne-list.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`

**Step 1: Créer resources/views/virements/index.blade.php**

```html
<x-app-layout>
    <h1 class="mb-4">Virements internes</h1>
    <livewire:virement-interne-form />
    <livewire:virement-interne-list />
</x-app-layout>
```

**Step 2: Créer resources/views/livewire/virement-interne-form.blade.php**

```html
<div>
    @if (! $showForm)
        <div class="mb-3">
            <button wire:click="showNewForm" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Nouveau virement
            </button>
        </div>
    @else
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ $virementId ? 'Modifier le virement' : 'Nouveau virement interne' }}</h5>
                <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Annuler
                </button>
            </div>
            <div class="card-body">
                <form wire:submit="save">
                    <div class="row g-3 mb-3">
                        <div class="col-md-2">
                            <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" wire:model="date" id="date"
                                   class="form-control @error('date') is-invalid @enderror">
                            @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="reference" class="form-label">Référence</label>
                            <input type="text" wire:model="reference" id="reference" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label for="montant" class="form-label">Montant <span class="text-danger">*</span></label>
                            <input type="number" wire:model="montant" id="montant" step="0.01" min="0.01"
                                   class="form-control @error('montant') is-invalid @enderror">
                            @error('montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="compte_source_id" class="form-label">Compte source <span class="text-danger">*</span></label>
                            <select wire:model="compte_source_id" id="compte_source_id"
                                    class="form-select @error('compte_source_id') is-invalid @enderror">
                                <option value="">-- Choisir --</option>
                                @foreach ($comptes as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                @endforeach
                            </select>
                            @error('compte_source_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="compte_destination_id" class="form-label">Compte destination <span class="text-danger">*</span></label>
                            <select wire:model="compte_destination_id" id="compte_destination_id"
                                    class="form-select @error('compte_destination_id') is-invalid @enderror">
                                <option value="">-- Choisir --</option>
                                @foreach ($comptes as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                @endforeach
                            </select>
                            @error('compte_destination_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-12">
                            <label for="notes" class="form-label">Notes</label>
                            <input type="text" wire:model="notes" id="notes" class="form-control">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" wire:click="resetForm" class="btn btn-secondary">Annuler</button>
                        <button type="submit" class="btn btn-success">
                            {{ $virementId ? 'Mettre à jour' : 'Enregistrer' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
```

**Step 3: Créer resources/views/livewire/virement-interne-list.blade.php**

```html
<div>
    @if ($virements->isEmpty())
        <p class="text-muted">Aucun virement enregistré pour cet exercice.</p>
    @else
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Référence</th>
                        <th>Compte source</th>
                        <th>Compte destination</th>
                        <th class="text-end">Montant</th>
                        <th>Notes</th>
                        <th>Saisi par</th>
                        <th style="width: 100px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($virements as $virement)
                        <tr wire:key="virement-{{ $virement->id }}">
                            <td>{{ $virement->date->format('d/m/Y') }}</td>
                            <td>{{ $virement->reference ?? '—' }}</td>
                            <td>{{ $virement->compteSource->nom }}</td>
                            <td>{{ $virement->compteDestination->nom }}</td>
                            <td class="text-end">{{ number_format((float) $virement->montant, 2, ',', ' ') }} €</td>
                            <td>{{ $virement->notes ?? '—' }}</td>
                            <td>{{ $virement->saisiPar->nom }}</td>
                            <td class="text-center">
                                <button wire:click="$dispatch('edit-virement', { id: {{ $virement->id }} })"
                                        class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button wire:click="delete({{ $virement->id }})"
                                        wire:confirm="Supprimer ce virement ?"
                                        class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
```

**Step 4: Ajouter la route dans routes/web.php**

Après la ligne `Route::view('/rapprochement', ...)`, ajouter :

```php
Route::view('/virements', 'virements.index')->name('virements.index');
```

**Step 5: Ajouter dans la navbar (layouts/app.blade.php)**

Dans le tableau `$navItems`, ajouter après 'rapprochement' :

```php
['route' => 'virements.index', 'icon' => 'arrow-left-right', 'label' => 'Virements'],
```

**Step 6: Tester manuellement**

- Naviguer vers http://localhost/virements
- Créer un virement de 100€ du compte A vers compte B → vérifier qu'il apparaît dans la liste
- Essayer de créer un virement avec source = destination → vérifier message d'erreur
- Modifier puis supprimer

**Step 7: Commit**

```bash
git add resources/views/virements/ \
        resources/views/livewire/virement-interne-form.blade.php \
        resources/views/livewire/virement-interne-list.blade.php \
        routes/web.php \
        resources/views/layouts/app.blade.php
git commit -m "feat: virements internes module (route, views, navbar)"
```

---

## Récapitulatif des commits attendus

1. `feat: add operations tab to parametres page`
2. `feat: auto-calculate montant_total from lignes in depense and recette forms`
3. `feat: default line, today date and last account in depense/recette new form`
4. `feat: reorder form fields in depense and recette forms`
5. `feat: migration and model for virements internes`
6. `feat: VirementInterneService and Livewire components`
7. `feat: virements internes module (route, views, navbar)`
