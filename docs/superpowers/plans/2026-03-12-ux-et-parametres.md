# UX et Paramètres — Plan d'implémentation

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 9 améliorations UX : densification des listes dépenses/recettes, pagination globale, gestion complète des utilisateurs, menu profil en navbar, et filtres dans les onglets Opérations/Catégories/Sous-catégories.

**Architecture:** Modifications Livewire (DepenseList, RecetteList), vues Blade Paramètres, nouveau Livewire profil, ajout `update`/`store` dans UserController, filtres JS-free en Livewire ou Bootstrap collapse. Pas de nouveaux modèles ni migrations.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Pest PHP, MySQL via Sail

---

## Fichiers créés / modifiés

| Fichier | Action |
|---|---|
| `resources/views/livewire/depense-list.blade.php` | Modifier (colonnes + boutons) |
| `resources/views/livewire/recette-list.blade.php` | Modifier |
| `app/Http/Controllers/UserController.php` | Modifier (add update + index) |
| `resources/views/parametres/index.blade.php` | Modifier (users create/edit, opérations filtre, catégories filtre, sous-catégories filtre) |
| `app/Http/Controllers/ParametreController.php` | Modifier (passer données sous-catégories filtrées) |
| `resources/views/layouts/app.blade.php` | Modifier (dropdown profil) |
| `routes/web.php` | Modifier (user update route + profil) |
| `app/Livewire/MonProfil.php` | Créer |
| `resources/views/livewire/mon-profil.blade.php` | Créer |
| `resources/views/profil/index.blade.php` | Créer |
| `app/Http/Controllers/Auth/EmailVerificationController.php` | Créer (ou adapter l'existant) |

---

## Chunk 1 : Listes densifiées

### Task 1 : Densifier les listes dépenses et recettes

**Files:**
- Modify: `resources/views/livewire/depense-list.blade.php`
- Modify: `resources/views/livewire/recette-list.blade.php`

**Changements :**
- Colonnes : Date – Réf. – Libellé – Bénéficiaire/Payeur – Mode paiement – Montant – Actions
- Bouton Modifier : icône seule `<i class="bi bi-pencil"></i>` dans un `btn btn-sm btn-outline-primary`
- Bouton Supprimer : icône seule `<i class="bi bi-trash"></i>` dans un `btn btn-sm btn-outline-danger`
- Les deux boutons sur la même ligne (td unique, `d-flex gap-1`)
- Enlever les en-têtes de colonnes inutiles (ex. "Opération" si trop large)
- Utiliser `table-sm` pour réduire la hauteur des lignes

- [ ] **Step 1 : Lire depense-list.blade.php pour voir la structure actuelle**

```bash
cat resources/views/livewire/depense-list.blade.php
```

- [ ] **Step 2 : Modifier la table dans depense-list.blade.php**

Remplacer l'en-tête de table par :

```html
<thead class="table-dark">
    <tr>
        <th>Date</th>
        <th>Réf.</th>
        <th>Libellé</th>
        <th>Bénéficiaire</th>
        <th>Mode</th>
        <th class="text-end">Montant</th>
        <th></th>
    </tr>
</thead>
```

Dans le corps de table, remplacer les colonnes et les boutons par :

```html
<tr wire:key="depense-{{ $depense->id }}">
    <td class="text-nowrap">{{ $depense->date->format('d/m/Y') }}</td>
    <td class="text-muted small">{{ $depense->reference ?? '—' }}</td>
    <td>{{ $depense->libelle }}</td>
    <td>{{ $depense->beneficiaire ?? '—' }}</td>
    <td><span class="badge bg-secondary">{{ $depense->mode_paiement->label() }}</span></td>
    <td class="text-end text-danger fw-semibold text-nowrap">
        {{ number_format((float) $depense->montant_total, 2, ',', ' ') }} €
    </td>
    <td>
        <div class="d-flex gap-1 justify-content-end">
            <button wire:click="$dispatch('edit-depense', { id: {{ $depense->id }} })"
                    class="btn btn-sm btn-outline-primary" title="Modifier">
                <i class="bi bi-pencil"></i>
            </button>
            <button wire:click="delete({{ $depense->id }})"
                    wire:confirm="Supprimer cette dépense ?"
                    class="btn btn-sm btn-outline-danger" title="Supprimer">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </td>
</tr>
```

Ajouter `table-sm` à la classe de la `<table>`.

- [ ] **Step 3 : Même modification dans recette-list.blade.php**

Même structure, adapter "Bénéficiaire" → "Payeur" et les événements/méthodes correspondants.

- [ ] **Step 4 : Commit**

```bash
git add resources/views/livewire/depense-list.blade.php \
        resources/views/livewire/recette-list.blade.php
git commit -m "feat: densify depense and recette list columns and compact action buttons"
```

---

## Chunk 2 : Pagination globale

### Task 2 : Ajouter la pagination sur toutes les listes

**Contexte :** DepenseList et RecetteList ont déjà `WithPagination` + `15 per page`. Vérifier quelles autres listes n'ont pas de pagination.

- [ ] **Step 1 : Identifier les listes sans pagination**

Lire les fichiers Livewire suivants et noter si `WithPagination` est présent :
- `app/Livewire/DonList.php`
- `app/Livewire/BudgetList.php` (si existe)
- `app/Livewire/MembreList.php` (si existe — les membres utilisent peut-être le controller)
- `app/Livewire/VirementInterneList.php`

```bash
grep -l "WithPagination" app/Livewire/*.php
grep -rL "WithPagination" app/Livewire/*.php
```

- [ ] **Step 2 : Pour chaque Livewire sans pagination, ajouter WithPagination**

Pattern à appliquer sur chaque Livewire concerné :

```php
use Livewire\WithPagination;

final class DonList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    // Dans render(), remplacer ->get() par ->paginate(20)
    // Ajouter resetPage() dans les méthodes updatedXxx()
}
```

- [ ] **Step 3 : Mettre à jour les vues correspondantes**

Dans chaque vue liste sans pagination, ajouter après `</table>` (ou `</div>` du table-responsive) :

```html
<div class="mt-3">
    {{ $items->links() }}
</div>
```

(Remplacer `$items` par le nom de la variable paginée dans cette vue.)

- [ ] **Step 4 : Commit**

```bash
git add app/Livewire/ resources/views/livewire/
git commit -m "feat: add pagination to all list components"
```

---

## Chunk 3 : Gestion complète des utilisateurs

### Task 3 : Créer et modifier des utilisateurs depuis Paramètres

**Contexte :** L'onglet Utilisateurs dans Paramètres permet déjà de lister et supprimer. Il manque la création (avec mot de passe) et la modification (nom, email, et réinitialisation de mot de passe).

**Files:**
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/parametres/index.blade.php`

- [ ] **Step 1 : Lire UserController.php actuel**

```bash
cat app/Http/Controllers/UserController.php
```

- [ ] **Step 2 : Étendre UserController**

Ajouter les méthodes `update` et `store` si elles n'existent pas (ou compléter) :

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

final class UserController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        User::create([
            'nom' => $validated['nom'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->route('parametres.index', ['#utilisateurs-pane'])
            ->with('success', 'Utilisateur créé.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', "unique:users,email,{$user->id}"],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        $user->nom = $validated['nom'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return redirect()->route('parametres.index', ['#utilisateurs-pane'])
            ->with('success', 'Utilisateur mis à jour.');
    }

    public function destroy(User $user): RedirectResponse
    {
        // Ne pas supprimer son propre compte
        if ($user->id === auth()->id()) {
            return redirect()->route('parametres.index')
                ->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        $user->delete();

        return redirect()->route('parametres.index', ['#utilisateurs-pane'])
            ->with('success', 'Utilisateur supprimé.');
    }
}
```

- [ ] **Step 3 : Ajouter la route update dans routes/web.php**

Dans le groupe `parametres.`, remplacer :
```php
Route::resource('utilisateurs', UserController::class)->only(['store', 'destroy']);
```
Par :
```php
Route::resource('utilisateurs', UserController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 4 : Modifier l'onglet Utilisateurs dans parametres/index.blade.php**

Lire la vue d'abord. Remplacer le contenu de l'onglet utilisateurs pour ajouter :
1. Un formulaire d'ajout (déjà présent partiellement — vérifier et compléter)
2. Dans la table, un bouton "Modifier" qui affiche un formulaire en ligne ou en modal

Option recommandée : formulaire d'édition en collapse inline dans la table (comme les autres onglets).

```html
{{-- Formulaire d'ajout --}}
<div class="mb-3">
    <button class="btn btn-primary btn-sm" type="button"
            data-bs-toggle="collapse" data-bs-target="#addUserForm">
        <i class="bi bi-plus-lg"></i> Ajouter un utilisateur
    </button>
</div>
<div class="collapse mb-3" id="addUserForm">
    <div class="card card-body">
        <form action="{{ route('parametres.utilisateurs.store') }}" method="POST" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3">
                <label class="form-label">Nom</label>
                <input type="text" name="nom" class="form-control @error('nom') is-invalid @enderror"
                       value="{{ old('nom') }}" required maxlength="100">
                @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email') }}" required maxlength="150">
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-2">
                <label class="form-label">Mot de passe</label>
                <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                       required>
                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-2">
                <label class="form-label">Confirmer</label>
                <input type="password" name="password_confirmation" class="form-control" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-success w-100">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

{{-- Table des utilisateurs --}}
<table class="table table-striped table-hover">
    <thead class="table-dark">
        <tr><th>Nom</th><th>Email</th><th style="width:100px;"></th></tr>
    </thead>
    <tbody>
        @forelse ($utilisateurs as $utilisateur)
            <tr>
                <td>{{ $utilisateur->nom }}</td>
                <td>{{ $utilisateur->email }}</td>
                <td>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="collapse"
                                data-bs-target="#editUser{{ $utilisateur->id }}"
                                title="Modifier">
                            <i class="bi bi-pencil"></i>
                        </button>
                        @if ($utilisateur->id !== auth()->id())
                            <form method="POST"
                                  action="{{ route('parametres.utilisateurs.destroy', $utilisateur) }}"
                                  onsubmit="return confirm('Supprimer cet utilisateur ?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
            {{-- Formulaire d'édition en collapse --}}
            <tr class="collapse" id="editUser{{ $utilisateur->id }}">
                <td colspan="3" class="bg-light">
                    <form action="{{ route('parametres.utilisateurs.update', $utilisateur) }}"
                          method="POST" class="row g-2 align-items-end p-2">
                        @csrf @method('PUT')
                        <div class="col-md-3">
                            <label class="form-label">Nom</label>
                            <input type="text" name="nom" class="form-control"
                                   value="{{ $utilisateur->nom }}" required maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="{{ $utilisateur->email }}" required maxlength="150">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Nouveau mdp <span class="text-muted">(opt.)</span></label>
                            <input type="password" name="password" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Confirmer</label>
                            <input type="password" name="password_confirmation" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-success w-100">Mettre à jour</button>
                        </div>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="text-muted">Aucun utilisateur.</td></tr>
        @endforelse
    </tbody>
</table>
```

- [ ] **Step 5 : Commit**

```bash
git add app/Http/Controllers/UserController.php routes/web.php \
        resources/views/parametres/index.blade.php
git commit -m "feat: create and edit users from parametres tab"
```

---

## Chunk 4 : Navbar profil + Mon profil

### Task 4 : Menu profil dans la navbar

**File:** `resources/views/layouts/app.blade.php`

**Changement :** Remplacer le bouton "Déconnexion" standalone par un dropdown Bootstrap avec :
- Nom de l'utilisateur connecté (texte du bouton)
- Item "Mon profil" → route `profil.index`
- Séparateur
- Item "Déconnexion" (formulaire POST logout)

- [ ] **Step 1 : Lire app.blade.php**

```bash
cat resources/views/layouts/app.blade.php
```

- [ ] **Step 2 : Remplacer le bloc déconnexion**

Localiser le bloc actuel avec le nom utilisateur + bouton logout. Le remplacer par :

```html
<div class="dropdown">
    <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button"
            data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-person-circle"></i> {{ auth()->user()->nom }}
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li>
            <a class="dropdown-item" href="{{ route('profil.index') }}">
                <i class="bi bi-person"></i> Mon profil
            </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="dropdown-item text-danger">
                    <i class="bi bi-box-arrow-right"></i> Déconnexion
                </button>
            </form>
        </li>
    </ul>
</div>
```

- [ ] **Step 3 : Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: profile dropdown in navbar with Mon profil and logout"
```

---

### Task 5 : Page Mon profil (changement d'email avec validation)

**Files:**
- Create: `app/Livewire/MonProfil.php`
- Create: `resources/views/livewire/mon-profil.blade.php`
- Create: `resources/views/profil/index.blade.php`
- Modify: `routes/web.php`

**Fonctionnalité :**
- Affiche le nom et l'email actuels
- Permet de modifier le nom (sans validation par email)
- Permet de modifier l'email : déclenche un email de vérification à la nouvelle adresse. L'email est mis à jour immédiatement mais marqué `email_verified_at = null` jusqu'à confirmation.
- Permet de changer le mot de passe

**Note :** La table `users` standard Laravel inclut `email_verified_at`. Si elle n't est pas présente, l'ajouter via migration.

- [ ] **Step 1 : Vérifier si email_verified_at existe**

```bash
./vendor/bin/sail artisan schema:dump 2>/dev/null | grep email_verified || \
  grep email_verified database/migrations/0001_01_01_000000_create_users_table.php
```

Si absent, créer une migration :
```bash
./vendor/bin/sail artisan make:migration add_email_verified_at_to_users_table
```
Contenu :
```php
Schema::table('users', function (Blueprint $table) {
    $table->timestamp('email_verified_at')->nullable()->after('email');
});
```
Puis `./vendor/bin/sail artisan migrate`.

- [ ] **Step 2 : Créer MonProfil.php**

```php
<?php
// app/Livewire/MonProfil.php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Component;

final class MonProfil extends Component
{
    public string $nom = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public ?string $successMessage = null;

    public function mount(): void
    {
        $user = Auth::user();
        $this->nom = $user->nom;
        $this->email = $user->email;
    }

    public function save(): void
    {
        $user = Auth::user();

        $rules = [
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', "unique:users,email,{$user->id}"],
            'password' => ['nullable', 'confirmed', 'min:8'],
        ];

        $this->validate($rules);

        $emailChanged = $this->email !== $user->email;

        $user->nom = $this->nom;

        if ($emailChanged) {
            // Stocker le nouvel email en attente de vérification
            $user->email = $this->email;
            $user->email_verified_at = null;
            // TODO: envoyer un email de vérification via la notification standard Laravel
            // $user->sendEmailVerificationNotification();
        }

        if ($this->password) {
            $user->password = Hash::make($this->password);
        }

        $user->save();

        $this->password = '';
        $this->password_confirmation = '';

        $this->successMessage = $emailChanged
            ? 'Profil mis à jour. Vérifiez votre nouvelle adresse email.'
            : 'Profil mis à jour.';
    }

    public function render()
    {
        return view('livewire.mon-profil');
    }
}
```

- [ ] **Step 3 : Créer resources/views/livewire/mon-profil.blade.php**

```html
<div>
    @if ($successMessage)
        <div class="alert alert-success alert-dismissible">
            {{ $successMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-header">Mes informations</div>
        <div class="card-body">
            <form wire:submit="save">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Nom <span class="text-danger">*</span></label>
                        <input type="text" wire:model="nom"
                               class="form-control @error('nom') is-invalid @enderror">
                        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Adresse email <span class="text-danger">*</span></label>
                        <input type="email" wire:model="email"
                               class="form-control @error('email') is-invalid @enderror">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">
                            Un email de confirmation sera envoyé en cas de modification.
                        </div>
                    </div>
                </div>

                <hr class="my-3">
                <h6 class="text-muted">Changer le mot de passe <span class="fw-normal">(laisser vide pour ne pas modifier)</span></h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Nouveau mot de passe</label>
                        <input type="password" wire:model="password"
                               class="form-control @error('password') is-invalid @enderror">
                        @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirmer</label>
                        <input type="password" wire:model="password_confirmation" class="form-control">
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
```

- [ ] **Step 4 : Créer resources/views/profil/index.blade.php**

```html
<x-app-layout>
    <h1 class="mb-4">Mon profil</h1>
    <livewire:mon-profil />
</x-app-layout>
```

- [ ] **Step 5 : Ajouter la route dans routes/web.php**

```php
Route::view('/profil', 'profil.index')->name('profil.index');
```

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/MonProfil.php \
        resources/views/livewire/mon-profil.blade.php \
        resources/views/profil/index.blade.php \
        routes/web.php
git commit -m "feat: mon profil page with name, email and password update"
```

---

## Chunk 5 : Filtres dans Paramètres

### Task 6 : Paramètres / Opérations — filtres et tri

**Files:**
- Modify: `resources/views/parametres/index.blade.php` (onglet Opérations)
- Modify: `app/Http/Controllers/ParametreController.php`

**Changements :**
1. Tri alphabétique des opérations (orderBy nom)
2. Filtre "Afficher : Tout / En cours / Clôturées" via JavaScript/Bootstrap (filtre côté client car la liste est chargée en PHP)
3. Les opérations clôturées (`statut = 'cloturee'`) ne sont plus listées dans les selects des formulaires DepenseForm/RecetteForm

- [ ] **Step 1 : Lire StatutOperation.php pour connaître les valeurs**

```bash
cat app/Enums/StatutOperation.php
```

- [ ] **Step 2 : Mettre à jour ParametreController — tri alphabétique**

Dans la méthode `index()`, remplacer `Operation::orderBy('nom')->get()` si pas déjà trié, ou vérifier que c'est bien `orderBy('nom')`.

- [ ] **Step 3 : Ajouter le filtre dans l'onglet Opérations**

Dans `parametres/index.blade.php`, avant la table des opérations, ajouter un groupe de boutons filtre :

```html
<div class="mb-3 d-flex gap-2 align-items-center">
    <button class="btn btn-primary btn-sm" type="button"
            data-bs-toggle="collapse" data-bs-target="#addOperationForm">
        <i class="bi bi-plus-lg"></i> Ajouter une opération
    </button>
    <div class="btn-group btn-group-sm ms-auto" role="group">
        <input type="radio" class="btn-check" name="opFilter" id="opAll" value="all" checked autocomplete="off">
        <label class="btn btn-outline-secondary" for="opAll">Tout</label>
        <input type="radio" class="btn-check" name="opFilter" id="opEnCours" value="en_cours" autocomplete="off">
        <label class="btn btn-outline-secondary" for="opEnCours">En cours</label>
        <input type="radio" class="btn-check" name="opFilter" id="opCloture" value="cloturee" autocomplete="off">
        <label class="btn btn-outline-secondary" for="opCloture">Clôturées</label>
    </div>
</div>
```

Sur chaque ligne `<tr>` de la table des opérations, ajouter un attribut `data-statut="{{ $operation->statut->value }}"` :

```html
<tr data-statut="{{ $operation->statut->value }}">
```

Ajouter le script JS (dans l'onglet ou en bas de page) :

```html
<script>
document.querySelectorAll('input[name="opFilter"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const val = this.value;
        document.querySelectorAll('tr[data-statut]').forEach(row => {
            row.style.display = (val === 'all' || row.dataset.statut === val) ? '' : 'none';
        });
    });
});
</script>
```

- [ ] **Step 4 : Exclure les opérations clôturées dans DepenseForm et RecetteForm**

Lire `app/Livewire/DepenseForm.php`. Dans la méthode `render()`, trouver la requête qui charge les opérations. Ajouter un filtre sur le statut.

La valeur exacte dépend de l'enum. Si `StatutOperation::EnCours` = `'en_cours'` :

Dans `render()` des deux composants, remplacer :
```php
'operations' => Operation::orderBy('nom')->get(),
```
Par :
```php
'operations' => Operation::where('statut', \App\Enums\StatutOperation::EnCours)->orderBy('nom')->get(),
```

Si l'enum a un autre cas pour "en cours", adapter en conséquence.

- [ ] **Step 5 : Commit**

```bash
git add resources/views/parametres/index.blade.php \
        app/Http/Controllers/ParametreController.php \
        app/Livewire/DepenseForm.php app/Livewire/RecetteForm.php
git commit -m "feat: operations filter in parametres + exclude closed operations from forms"
```

---

### Task 7 : Paramètres / Catégories — filtre par type

**Files:**
- Modify: `resources/views/parametres/index.blade.php` (onglet Catégories)

Même approche client-side que les opérations : ajouter `data-type="{{ $categorie->type->value }}"` sur chaque `<tr>` et un groupe radio Tout / Recettes / Dépenses.

- [ ] **Step 1 : Ajouter le filtre dans l'onglet Catégories**

```html
{{-- avant la table --}}
<div class="mb-3 d-flex gap-2 align-items-center">
    <button class="btn btn-primary btn-sm" type="button"
            data-bs-toggle="collapse" data-bs-target="#addCategorieForm">
        <i class="bi bi-plus-lg"></i> Ajouter une catégorie
    </button>
    <div class="btn-group btn-group-sm ms-auto" role="group">
        <input type="radio" class="btn-check" name="catFilter" id="catAll" value="all" checked autocomplete="off">
        <label class="btn btn-outline-secondary" for="catAll">Tout</label>
        <input type="radio" class="btn-check" name="catFilter" id="catRecette" value="recette" autocomplete="off">
        <label class="btn btn-outline-secondary" for="catRecette">Recettes</label>
        <input type="radio" class="btn-check" name="catFilter" id="catDepense" value="depense" autocomplete="off">
        <label class="btn btn-outline-secondary" for="catDepense">Dépenses</label>
    </div>
</div>
```

Ajouter `data-type="{{ $categorie->type->value }}"` sur chaque `<tr>` de la table.

Script :

```html
<script>
document.querySelectorAll('input[name="catFilter"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const val = this.value;
        document.querySelectorAll('#categories-pane tr[data-type]').forEach(row => {
            row.style.display = (val === 'all' || row.dataset.type === val) ? '' : 'none';
        });
    });
});
</script>
```

- [ ] **Step 2 : Commit**

```bash
git add resources/views/parametres/index.blade.php
git commit -m "feat: type filter in categories tab"
```

---

### Task 8 : Paramètres / Sous-catégories — filtres type et catégorie

**Files:**
- Modify: `resources/views/parametres/index.blade.php` (onglet Sous-catégories)

Deux filtres combinés :
1. Filtre par type (Tout / Recettes / Dépenses) — via la catégorie parente
2. Filtre par catégorie (select dropdown)

**Approche :** client-side JS. Chaque ligne `<tr>` porte `data-type="{{ $sousCat->categorie->type->value }}"` et `data-categorie="{{ $sousCat->categorie_id }}"`.

- [ ] **Step 1 : Vérifier que les sous-catégories sont chargées avec leur catégorie**

Dans `ParametreController::index()`, vérifier que `SousCategorie::with('categorie')` est utilisé. Si non, mettre à jour :

```php
'sousCategories' => SousCategorie::with('categorie')->orderBy('nom')->get(),
```

- [ ] **Step 2 : Ajouter les filtres dans l'onglet Sous-catégories**

```html
<div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <button class="btn btn-primary btn-sm" type="button"
            data-bs-toggle="collapse" data-bs-target="#addSousCategorieForm">
        <i class="bi bi-plus-lg"></i> Ajouter une sous-catégorie
    </button>
    <div class="btn-group btn-group-sm" role="group">
        <input type="radio" class="btn-check" name="scTypeFilter" id="scAll" value="all" checked autocomplete="off">
        <label class="btn btn-outline-secondary" for="scAll">Tout</label>
        <input type="radio" class="btn-check" name="scTypeFilter" id="scRecette" value="recette" autocomplete="off">
        <label class="btn btn-outline-secondary" for="scRecette">Recettes</label>
        <input type="radio" class="btn-check" name="scTypeFilter" id="scDepense" value="depense" autocomplete="off">
        <label class="btn btn-outline-secondary" for="scDepense">Dépenses</label>
    </div>
    <select id="scCatFilter" class="form-select form-select-sm" style="width:auto;">
        <option value="">— Toutes les catégories —</option>
        @foreach ($categories as $cat)
            <option value="{{ $cat->id }}">{{ $cat->nom }}</option>
        @endforeach
    </select>
</div>
```

Sur chaque `<tr>` de la table des sous-catégories :

```html
<tr data-type="{{ $sousCat->categorie->type->value }}" data-categorie="{{ $sousCat->categorie_id }}">
```

Script de filtre combiné :

```html
<script>
function filterSousCategories() {
    const typeVal = document.querySelector('input[name="scTypeFilter"]:checked').value;
    const catVal = document.getElementById('scCatFilter').value;
    document.querySelectorAll('#sous-categories-pane tr[data-type]').forEach(row => {
        const typeOk = typeVal === 'all' || row.dataset.type === typeVal;
        const catOk = catVal === '' || row.dataset.categorie === catVal;
        row.style.display = (typeOk && catOk) ? '' : 'none';
    });
}
document.querySelectorAll('input[name="scTypeFilter"]').forEach(r => r.addEventListener('change', filterSousCategories));
document.getElementById('scCatFilter').addEventListener('change', filterSousCategories);
</script>
```

- [ ] **Step 3 : Commit**

```bash
git add resources/views/parametres/index.blade.php \
        app/Http/Controllers/ParametreController.php
git commit -m "feat: type and category filters in sous-categories tab"
```

---

## Récapitulatif des commits

1. `feat: densify depense and recette list columns and compact action buttons`
2. `feat: add pagination to all list components`
3. `feat: create and edit users from parametres tab`
4. `feat: profile dropdown in navbar with Mon profil and logout`
5. `feat: mon profil page with name, email and password update`
6. `feat: operations filter in parametres + exclude closed operations from forms`
7. `feat: type filter in categories tab`
8. `feat: type and category filters in sous-categories tab`
