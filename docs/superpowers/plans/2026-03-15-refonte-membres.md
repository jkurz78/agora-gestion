# Refonte Membres Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer l'écran membres basé sur `statut_membre` par une page Livewire qui dérive le statut des cotisations enregistrées (à jour / en retard / tous), et ajouter un bouton "Nouvelle cotisation" par ligne lié au formulaire existant `CotisationForm`.

**Architecture:** Migration drop des 3 colonnes legacy → nettoyage du modèle `Tiers` et suppression des fichiers legacy → ajout de `updatedTiersId()` dans `TiersAutocomplete` et `openForTiers()` dans `CotisationForm` → nouveau composant Livewire `MembreList` + vue `membres/index.blade.php` + route `Route::view`.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, `whereHas`/`whereDoesntHave` pour les filtres de statut.

---

## Chunk 1: Migration + nettoyage Tiers model + suppression des fichiers legacy

### Task 1: Migration drop columns + Tiers model + fichiers legacy

**Files:**
- Create: `database/migrations/2026_03_15_100000_drop_membre_columns_from_tiers.php`
- Modify: `app/Models/Tiers.php`
- Modify: `database/factories/TiersFactory.php`
- Delete: `app/Http/Controllers/MembreController.php`
- Delete: `app/Http/Requests/StoreMembreRequest.php`
- Delete: `app/Http/Requests/UpdateMembreRequest.php`
- Delete: `resources/views/membres/create.blade.php`
- Delete: `resources/views/membres/edit.blade.php`
- Delete: `resources/views/membres/show.blade.php`
- Delete: `resources/views/membres/index.blade.php`
- Test: `tests/Feature/Migrations/DropMembreColumnsTest.php`

- [ ] **Step 1: Écrire le test qui vérifie l'absence des colonnes après migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('la table tiers ne contient plus les colonnes membres legacy', function (): void {
    expect(Schema::hasColumn('tiers', 'statut_membre'))->toBeFalse()
        ->and(Schema::hasColumn('tiers', 'date_adhesion'))->toBeFalse()
        ->and(Schema::hasColumn('tiers', 'notes_membre'))->toBeFalse();
});
```

- [ ] **Step 2: Lancer le test — vérifier qu'il échoue**

```bash
./vendor/bin/sail php artisan test tests/Feature/Migrations/DropMembreColumnsTest.php
```

Attendu : FAIL — les colonnes existent encore.

- [ ] **Step 3: Créer la migration**

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
        Schema::table('tiers', function (Blueprint $table): void {
            $table->dropColumn(['statut_membre', 'date_adhesion', 'notes_membre']);
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table): void {
            $table->string('statut_membre')->nullable();
            $table->date('date_adhesion')->nullable();
            $table->text('notes_membre')->nullable();
        });
    }
};
```

- [ ] **Step 4: Lancer la migration**

```bash
./vendor/bin/sail php artisan migrate
```

- [ ] **Step 5: Lancer le test — vérifier qu'il passe**

```bash
./vendor/bin/sail php artisan test tests/Feature/Migrations/DropMembreColumnsTest.php
```

Attendu : PASS (1 test).

- [ ] **Step 6: Nettoyer le modèle Tiers**

Dans `app/Models/Tiers.php`, remplacer le bloc `$fillable` :

```php
    protected $fillable = [
        'type',
        'nom',
        'prenom',
        'email',
        'telephone',
        'adresse',
        'pour_depenses',
        'pour_recettes',
    ];
```

Dans `casts()`, supprimer les entrées legacy :

```php
    protected function casts(): array
    {
        return [
            'pour_depenses' => 'boolean',
            'pour_recettes' => 'boolean',
        ];
    }
```

Supprimer la méthode `scopeMembres()` en entier.

- [ ] **Step 7: Mettre à jour `TiersFactory::membre()` pour supprimer les colonnes legacy**

Dans `database/factories/TiersFactory.php`, remplacer la méthode `membre()` :

```php
    public function membre(): static
    {
        return $this->state([
            'type'         => 'particulier',
            'prenom'       => fake()->firstName(),
            'pour_depenses' => false,
            'pour_recettes' => false,
        ]);
    }
```

(Supprimer `date_adhesion` et `statut_membre` qui n'existent plus.)

- [ ] **Step 8: Supprimer les fichiers legacy**

```bash
rm app/Http/Controllers/MembreController.php
rm app/Http/Requests/StoreMembreRequest.php
rm app/Http/Requests/UpdateMembreRequest.php
rm resources/views/membres/create.blade.php
rm resources/views/membres/edit.blade.php
rm resources/views/membres/show.blade.php
rm resources/views/membres/index.blade.php
```

- [ ] **Step 9: Lancer la suite complète**

```bash
./vendor/bin/sail php artisan test
```

Attendu : 0 échec (les tests qui référençaient MembreController n'existent pas).

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_03_15_100000_drop_membre_columns_from_tiers.php \
        app/Models/Tiers.php \
        database/factories/TiersFactory.php \
        tests/Feature/Migrations/DropMembreColumnsTest.php
git rm app/Http/Controllers/MembreController.php \
       app/Http/Requests/StoreMembreRequest.php \
       app/Http/Requests/UpdateMembreRequest.php \
       resources/views/membres/create.blade.php \
       resources/views/membres/edit.blade.php \
       resources/views/membres/show.blade.php \
       resources/views/membres/index.blade.php
git commit -m "feat: supprimer statut_membre/date_adhesion/notes_membre + fichiers membres legacy"
```

---

## Chunk 2: TiersAutocomplete.updatedTiersId + CotisationForm.openForTiers

### Task 2: Adapter les composants existants pour la communication cross-component

**Files:**
- Modify: `app/Livewire/TiersAutocomplete.php`
- Modify: `app/Livewire/CotisationForm.php`
- Test: `tests/Livewire/TiersAutocompleteUpdatedTiersIdTest.php`
- Test: `tests/Livewire/CotisationFormOpenForTiersTest.php`

- [ ] **Step 1: Écrire les tests qui échouent**

`tests/Livewire/TiersAutocompleteUpdatedTiersIdTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\TiersAutocomplete;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('met à jour selectedLabel et selectedType quand tiersId change programmatiquement', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'type' => 'particulier', 'prenom' => 'Jean']);

    Livewire::actingAs($this->user)
        ->test(TiersAutocomplete::class)
        ->set('tiersId', $tiers->id)
        ->assertSet('selectedLabel', 'Jean Dupont')
        ->assertSet('selectedType', 'particulier');
});

it('vide selectedLabel et selectedType quand tiersId devient null', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont']);

    Livewire::actingAs($this->user)
        ->test(TiersAutocomplete::class, ['tiersId' => $tiers->id])
        ->set('tiersId', null)
        ->assertSet('selectedLabel', null)
        ->assertSet('selectedType', null);
});
```

`tests/Livewire/CotisationFormOpenForTiersTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\CotisationForm;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    session(['exercice_actif' => 2025]);
});

it('ouvre le formulaire avec le tiers pré-sélectionné via event', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->dispatch('open-cotisation-for-tiers', tiersId: $tiers->id)
        ->assertSet('showForm', true)
        ->assertSet('tiers_id', $tiers->id);
});

it('ouvre le formulaire sans tiers quand tiersId est null', function (): void {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->dispatch('open-cotisation-for-tiers', tiersId: null)
        ->assertSet('showForm', true)
        ->assertSet('tiers_id', null);
});
```

- [ ] **Step 2: Lancer les tests — vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Livewire/TiersAutocompleteUpdatedTiersIdTest.php tests/Livewire/CotisationFormOpenForTiersTest.php
```

Attendu : FAIL.

- [ ] **Step 3: Ajouter `updatedTiersId()` dans `TiersAutocomplete`**

Dans `app/Livewire/TiersAutocomplete.php`, après la méthode `mount()`, ajouter :

```php
    public function updatedTiersId(mixed $value): void
    {
        $id = ($value !== '' && $value !== null) ? (int) $value : null;
        $this->tiersId = $id;
        if ($id !== null) {
            $tiers = Tiers::find($id);
            $this->selectedLabel = $tiers?->displayName();
            $this->selectedType  = $tiers?->type;
        } else {
            $this->selectedLabel = null;
            $this->selectedType  = null;
        }
    }
```

- [ ] **Step 4: Ajouter `openForTiers()` dans `CotisationForm`**

En haut de `app/Livewire/CotisationForm.php`, ajouter l'import :

```php
use Livewire\Attributes\On;
```

Après la méthode `resetForm()`, ajouter :

```php
    #[On('open-cotisation-for-tiers')]
    public function openForTiers(?int $tiersId = null): void
    {
        $this->resetForm();
        $this->date_paiement = app(ExerciceService::class)->defaultDate();
        $this->showForm = true;
        if ($tiersId !== null) {
            $this->tiers_id = $tiersId;
        }
    }
```

- [ ] **Step 5: Lancer les tests**

```bash
./vendor/bin/sail php artisan test tests/Livewire/TiersAutocompleteUpdatedTiersIdTest.php tests/Livewire/CotisationFormOpenForTiersTest.php
```

Attendu : PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/TiersAutocomplete.php app/Livewire/CotisationForm.php \
        tests/Livewire/TiersAutocompleteUpdatedTiersIdTest.php \
        tests/Livewire/CotisationFormOpenForTiersTest.php
git commit -m "feat: TiersAutocomplete.updatedTiersId + CotisationForm.openForTiers avec event"
```

---

## Chunk 3: MembreList + vue membres/index + route

### Task 3: Composant MembreList, vue index, route

**Files:**
- Create: `app/Livewire/MembreList.php`
- Create: `resources/views/livewire/membre-list.blade.php`
- Create: `resources/views/membres/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Livewire/MembreListTest.php`

- [ ] **Step 1: Écrire les tests qui échouent**

```php
<?php

declare(strict_types=1);

use App\Livewire\MembreList;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    session(['exercice_actif' => 2025]);
});

it('renders without error', function (): void {
    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->assertOk();
});

it('filtre a_jour retourne les tiers avec cotisation exercice courant', function (): void {
    $aJour  = Tiers::factory()->create(['nom' => 'AJour']);
    $retard = Tiers::factory()->create(['nom' => 'EnRetard']);

    Cotisation::factory()->create(['tiers_id' => $aJour->id, 'exercice' => 2025]);
    Cotisation::factory()->create(['tiers_id' => $retard->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('AJour')
        ->assertDontSee('EnRetard');
});

it('filtre en_retard retourne les tiers avec cotisation N-1 sans cotisation N', function (): void {
    $aJour  = Tiers::factory()->create(['nom' => 'AJour']);
    $retard = Tiers::factory()->create(['nom' => 'EnRetard']);

    Cotisation::factory()->create(['tiers_id' => $aJour->id, 'exercice' => 2024]);
    Cotisation::factory()->create(['tiers_id' => $aJour->id, 'exercice' => 2025]);
    Cotisation::factory()->create(['tiers_id' => $retard->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->set('filtre', 'en_retard')
        ->assertSee('EnRetard')
        ->assertDontSee('AJour');
});

it('filtre tous retourne tous les tiers avec au moins une cotisation', function (): void {
    $avecCot  = Tiers::factory()->create(['nom' => 'AvecCot']);
    $sansCot  = Tiers::factory()->create(['nom' => 'SansCot']);

    Cotisation::factory()->create(['tiers_id' => $avecCot->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->set('filtre', 'tous')
        ->assertSee('AvecCot')
        ->assertDontSee('SansCot');
});

it('filtre par recherche texte sur le nom', function (): void {
    $martin = Tiers::factory()->create(['nom' => 'Martin']);
    $dupont = Tiers::factory()->create(['nom' => 'Dupont']);

    Cotisation::factory()->create(['tiers_id' => $martin->id, 'exercice' => 2025]);
    Cotisation::factory()->create(['tiers_id' => $dupont->id, 'exercice' => 2025]);

    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->set('filtre', 'a_jour')
        ->set('search', 'Martin')
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});
```

- [ ] **Step 2: Lancer les tests — vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Livewire/MembreListTest.php
```

Attendu : FAIL — classe non trouvée.

- [ ] **Step 3: Créer le composant `app/Livewire/MembreList.php`**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class MembreList extends Component
{
    use WithPagination;

    public string $filtre = 'a_jour';
    public string $search = '';

    public function updatedFiltre(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Tiers::query();

        match ($this->filtre) {
            'a_jour' => $query->whereHas(
                'cotisations',
                fn ($q) => $q->forExercice($exercice)
            ),
            'en_retard' => $query
                ->whereHas('cotisations', fn ($q) => $q->forExercice($exercice - 1))
                ->whereDoesntHave('cotisations', fn ($q) => $q->forExercice($exercice)),
            default => $query->whereHas('cotisations'),
        };

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', '%' . $this->search . '%')
                    ->orWhere('prenom', 'like', '%' . $this->search . '%');
            });
        }

        $membres = $query->orderBy('nom')->paginate(50);

        // Eager-load dernière cotisation par tiers
        $membres->getCollection()->each(function (Tiers $tiers): void {
            $tiers->setRelation(
                'derniereCotisation',
                $tiers->cotisations()->latest('date_paiement')->first()
            );
        });

        return view('livewire.membre-list', compact('membres'));
    }
}
```

- [ ] **Step 4: Créer la vue `resources/views/livewire/membre-list.blade.php`**

```blade
<div>
    {{-- Barre de filtres --}}
    <div class="d-flex gap-3 align-items-center mb-3 flex-wrap">
        <div class="btn-group" role="group">
            <input type="radio" class="btn-check" wire:model.live="filtre" value="a_jour" id="filtre-a-jour">
            <label class="btn btn-outline-success" for="filtre-a-jour">À jour</label>

            <input type="radio" class="btn-check" wire:model.live="filtre" value="en_retard" id="filtre-retard">
            <label class="btn btn-outline-warning" for="filtre-retard">En retard</label>

            <input type="radio" class="btn-check" wire:model.live="filtre" value="tous" id="filtre-tous">
            <label class="btn btn-outline-secondary" for="filtre-tous">Tous</label>
        </div>

        <input type="text"
               wire:model.live.debounce.300ms="search"
               class="form-control form-control-sm"
               style="max-width:250px"
               placeholder="Rechercher un membre…">
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Nom</th>
                    <th>Dernière cotisation</th>
                    <th>Montant</th>
                    <th>Mode</th>
                    <th>Compte</th>
                    <th>Pointé</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($membres as $membre)
                    @php $cot = $membre->derniereCotisation; @endphp
                    <tr>
                        <td>
                            @if($membre->type === 'entreprise')
                                <i class="bi bi-building text-muted me-1"></i>
                            @else
                                <i class="bi bi-person text-muted me-1"></i>
                            @endif
                            {{ $membre->displayName() }}
                        </td>
                        <td class="text-nowrap">
                            @if($cot)
                                {{ $cot->date_paiement->format('d/m/Y') }}
                                <span class="text-muted small">({{ $cot->exercice }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            @if($cot)
                                {{ number_format((float) $cot->montant, 2, ',', ' ') }} €
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($cot)
                                <span class="badge bg-secondary">{{ $cot->mode_paiement->label() }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $cot?->compte?->nom ?? '—' }}</td>
                        <td>
                            @if($cot)
                                {{ $cot->pointe ? '✓' : '—' }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end">
                            <button
                                wire:click="$dispatch('open-cotisation-for-tiers', { tiersId: {{ $membre->id }} })"
                                class="btn btn-sm btn-outline-primary"
                                title="Nouvelle cotisation">
                                <i class="bi bi-plus-circle"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Aucun membre trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $membres->links() }}
</div>
```

- [ ] **Step 5: Lancer les tests**

```bash
./vendor/bin/sail php artisan test tests/Livewire/MembreListTest.php
```

Attendu : PASS (5 tests).

- [ ] **Step 6: Créer `resources/views/membres/index.blade.php`**

```blade
<x-app-layout>
    <livewire:cotisation-form />
    <livewire:membre-list />
</x-app-layout>
```

- [ ] **Step 7: Mettre à jour `routes/web.php`**

Supprimer la ligne :
```php
use App\Http\Controllers\MembreController;
```

Et remplacer :
```php
    Route::resource('membres', MembreController::class);
```

Par :
```php
    Route::view('/membres', 'membres.index')->name('membres.index');
```

- [ ] **Step 8: Lancer la suite complète**

```bash
./vendor/bin/sail php artisan test
```

Attendu : 0 échec.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/MembreList.php \
        resources/views/livewire/membre-list.blade.php \
        resources/views/membres/index.blade.php \
        routes/web.php \
        tests/Livewire/MembreListTest.php
git commit -m "feat: MembreList — statut dérivé des cotisations, filtres à_jour/en_retard/tous"
```

- [ ] **Step 10: Lancer la suite complète une dernière fois**

```bash
./vendor/bin/sail php artisan test
```

Attendu : 0 échec.
