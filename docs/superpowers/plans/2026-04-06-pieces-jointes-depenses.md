# Pièces jointes sur les dépenses — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre d'attacher un justificatif PDF/image à une transaction dépense, avec prévisualisation split-view dans la modale encadrants.

**Architecture:** Colonne `piece_jointe_path` sur la table `transactions`, fichiers stockés dans `storage/app/private/pieces-jointes/{id}/`. Upload Livewire `WithFileUploads`, consultation via contrôleur dédié avec `Storage::response()`. AnimateurManager enrichi d'un step upload avant le formulaire.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP

**Spec:** `docs/superpowers/specs/2026-04-06-pieces-jointes-depenses-design.md`

---

### Task 1: Migration — colonnes pièce jointe sur transactions

**Files:**
- Create: `database/migrations/2026_04_06_100000_add_piece_jointe_to_transactions.php`

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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('piece_jointe_path', 500)->nullable()->after('numero_piece');
            $table->string('piece_jointe_nom', 255)->nullable()->after('piece_jointe_path');
            $table->string('piece_jointe_mime', 100)->nullable()->after('piece_jointe_nom');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['piece_jointe_path', 'piece_jointe_nom', 'piece_jointe_mime']);
        });
    }
};
```

- [ ] **Step 2: Lancer la migration**

Run: `./vendor/bin/sail artisan migrate`
Expected: table `transactions` mise à jour avec les 3 nouvelles colonnes nullable.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_06_100000_add_piece_jointe_to_transactions.php
git commit -m "feat: migration pièce jointe sur transactions"
```

---

### Task 2: Modèle Transaction — fillable + helpers

**Files:**
- Modify: `app/Models/Transaction.php`
- Test: `tests/Feature/TransactionPieceJointeTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('hasPieceJointe retourne false quand pas de pièce jointe', function () {
    $transaction = Transaction::factory()->create();
    expect($transaction->hasPieceJointe())->toBeFalse();
});

it('hasPieceJointe retourne true quand pièce jointe présente', function () {
    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => 'pieces-jointes/1/justificatif.pdf',
        'piece_jointe_nom' => 'facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);
    expect($transaction->hasPieceJointe())->toBeTrue();
});

it('pieceJointeUrl retourne null sans pièce jointe', function () {
    $transaction = Transaction::factory()->create();
    expect($transaction->pieceJointeUrl())->toBeNull();
});

it('pieceJointeUrl retourne une URL quand pièce jointe présente', function () {
    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => 'pieces-jointes/1/justificatif.pdf',
        'piece_jointe_nom' => 'facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);
    expect($transaction->pieceJointeUrl())->toContain('/transactions/' . $transaction->id . '/piece-jointe');
});
```

- [ ] **Step 2: Lancer le test — vérifier qu'il échoue**

Run: `./vendor/bin/sail test tests/Feature/TransactionPieceJointeTest.php`
Expected: FAIL — méthodes `hasPieceJointe` et `pieceJointeUrl` n'existent pas.

- [ ] **Step 3: Implémenter les modifications du modèle**

Dans `app/Models/Transaction.php`, ajouter les 3 champs au `$fillable` :

```php
protected $fillable = [
    'type',
    'date',
    'libelle',
    'montant_total',
    'mode_paiement',
    'tiers_id',
    'reference',
    'compte_id',
    'pointe',
    'notes',
    'saisi_par',
    'rapprochement_id',
    'remise_id',
    'reglement_id',
    'numero_piece',
    'piece_jointe_path',
    'piece_jointe_nom',
    'piece_jointe_mime',
    'helloasso_order_id',
    'helloasso_cashout_id',
    'helloasso_payment_id',
];
```

Ajouter les deux méthodes helper après `isLockedByFacture()` :

```php
public function hasPieceJointe(): bool
{
    return $this->piece_jointe_path !== null;
}

public function pieceJointeUrl(): ?string
{
    if (! $this->hasPieceJointe()) {
        return null;
    }

    return route('transactions.piece-jointe', $this);
}
```

- [ ] **Step 4: Lancer le test — vérifier qu'il passe**

Run: `./vendor/bin/sail test tests/Feature/TransactionPieceJointeTest.php`
Expected: 4 tests PASS (le test `pieceJointeUrl` retournera une erreur de route manquante — c'est OK, on la crée à la Task 3).

Note : si le test `pieceJointeUrl` échoue à cause de la route manquante, ajouter temporairement un `->markTestSkipped()` ou ajuster — on valide la route dans la Task 3.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Transaction.php tests/Feature/TransactionPieceJointeTest.php
git commit -m "feat: Transaction model — fillable + hasPieceJointe + pieceJointeUrl"
```

---

### Task 3: Route & contrôleur de consultation

**Files:**
- Create: `app/Http/Controllers/TransactionPieceJointeController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TransactionPieceJointeTest.php` (ajout de tests)

- [ ] **Step 1: Écrire les tests de la route**

Ajouter à `tests/Feature/TransactionPieceJointeTest.php` :

```php
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use Illuminate\Support\Facades\Storage;

it('retourne 404 si la transaction n\'a pas de pièce jointe', function () {
    $transaction = Transaction::factory()->create();

    $this->get(route('transactions.piece-jointe', $transaction))
        ->assertNotFound();
});

it('retourne le fichier avec le bon Content-Disposition', function () {
    Storage::fake('local');
    $path = 'pieces-jointes/1/justificatif.pdf';
    Storage::disk('local')->put($path, 'fake-pdf-content');

    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => $path,
        'piece_jointe_nom' => 'ma-facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $response = $this->get(route('transactions.piece-jointe', $transaction));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="ma-facture.pdf"');
});

it('refuse l\'accès aux utilisateurs non authentifiés', function () {
    auth()->logout();
    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => 'pieces-jointes/1/justificatif.pdf',
        'piece_jointe_nom' => 'facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $this->get(route('transactions.piece-jointe', $transaction))
        ->assertRedirect(route('login'));
});
```

- [ ] **Step 2: Lancer les tests — vérifier qu'ils échouent**

Run: `./vendor/bin/sail test tests/Feature/TransactionPieceJointeTest.php`
Expected: FAIL — route `transactions.piece-jointe` n'existe pas.

- [ ] **Step 3: Créer le contrôleur**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class TransactionPieceJointeController extends Controller
{
    public function __invoke(Request $request, Transaction $transaction): Response
    {
        if (! $transaction->hasPieceJointe()) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($transaction->piece_jointe_path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $transaction->piece_jointe_path,
            $transaction->piece_jointe_nom,
            ['Content-Type' => $transaction->piece_jointe_mime],
            'inline'
        );
    }
}
```

- [ ] **Step 4: Ajouter la route**

Dans `routes/web.php`, dans le bloc `middleware('auth')` général (après la ligne `Route::view('/profil', ...)`), ajouter :

```php
Route::get('/transactions/{transaction}/piece-jointe', TransactionPieceJointeController::class)
    ->name('transactions.piece-jointe');
```

Et ajouter l'import en haut du fichier :

```php
use App\Http\Controllers\TransactionPieceJointeController;
```

- [ ] **Step 5: Lancer les tests — vérifier qu'ils passent**

Run: `./vendor/bin/sail test tests/Feature/TransactionPieceJointeTest.php`
Expected: tous les tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/TransactionPieceJointeController.php routes/web.php tests/Feature/TransactionPieceJointeTest.php
git commit -m "feat: route et contrôleur consultation pièce jointe"
```

---

### Task 4: TransactionService — storePieceJointe / deletePieceJointe

**Files:**
- Modify: `app/Services/TransactionService.php`
- Test: `tests/Feature/TransactionPieceJointeTest.php` (ajout de tests)

- [ ] **Step 1: Écrire les tests**

Ajouter à `tests/Feature/TransactionPieceJointeTest.php` :

```php
use App\Services\TransactionService;
use Illuminate\Http\UploadedFile;

it('storePieceJointe stocke le fichier et met à jour la transaction', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();
    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    app(TransactionService::class)->storePieceJointe($transaction, $file);

    $transaction->refresh();
    expect($transaction->piece_jointe_path)->toBe("pieces-jointes/{$transaction->id}/justificatif.pdf")
        ->and($transaction->piece_jointe_nom)->toBe('facture.pdf')
        ->and($transaction->piece_jointe_mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->exists($transaction->piece_jointe_path))->toBeTrue();
});

it('storePieceJointe remplace le fichier existant', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();

    $file1 = UploadedFile::fake()->create('ancienne.pdf', 100, 'application/pdf');
    app(TransactionService::class)->storePieceJointe($transaction, $file1);

    $file2 = UploadedFile::fake()->image('nouvelle.jpg', 800, 600);
    app(TransactionService::class)->storePieceJointe($transaction, $file2);

    $transaction->refresh();
    expect($transaction->piece_jointe_nom)->toBe('nouvelle.jpg')
        ->and($transaction->piece_jointe_mime)->toBe('image/jpeg')
        ->and(Storage::disk('local')->exists("pieces-jointes/{$transaction->id}/justificatif.pdf"))->toBeFalse()
        ->and(Storage::disk('local')->exists("pieces-jointes/{$transaction->id}/justificatif.jpg"))->toBeTrue();
});

it('storePieceJointe rejette un fichier au MIME non autorisé', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();
    $file = UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload');

    app(TransactionService::class)->storePieceJointe($transaction, $file);
})->throws(\InvalidArgumentException::class, 'Type de fichier non autorisé');

it('deletePieceJointe supprime le fichier et remet les colonnes à null', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();
    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    $service = app(TransactionService::class);
    $service->storePieceJointe($transaction, $file);
    $service->deletePieceJointe($transaction);

    $transaction->refresh();
    expect($transaction->piece_jointe_path)->toBeNull()
        ->and($transaction->piece_jointe_nom)->toBeNull()
        ->and($transaction->piece_jointe_mime)->toBeNull()
        ->and(Storage::disk('local')->exists("pieces-jointes/{$transaction->id}/justificatif.pdf"))->toBeFalse();
});
```

- [ ] **Step 2: Lancer les tests — vérifier qu'ils échouent**

Run: `./vendor/bin/sail test tests/Feature/TransactionPieceJointeTest.php --filter="storePieceJointe|deletePieceJointe"`
Expected: FAIL — méthodes n'existent pas.

- [ ] **Step 3: Implémenter dans TransactionService**

Ajouter en haut du fichier l'import :

```php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

Ajouter les deux méthodes après `supprimerAffectations()` :

```php
private const ALLOWED_MIMES = [
    'application/pdf',
    'image/jpeg',
    'image/png',
];

public function storePieceJointe(Transaction $transaction, UploadedFile $file): void
{
    $mime = $file->getMimeType();
    if (! in_array($mime, self::ALLOWED_MIMES, true)) {
        throw new \InvalidArgumentException('Type de fichier non autorisé : ' . $mime);
    }

    $dir = "pieces-jointes/{$transaction->id}";

    // Supprimer l'ancien fichier s'il existe
    if ($transaction->piece_jointe_path !== null) {
        Storage::disk('local')->deleteDirectory($dir);
    }

    $extension = $file->guessExtension() ?? 'bin';
    $storedPath = $file->storeAs($dir, "justificatif.{$extension}", 'local');

    $transaction->update([
        'piece_jointe_path' => $storedPath,
        'piece_jointe_nom' => $file->getClientOriginalName(),
        'piece_jointe_mime' => $mime,
    ]);
}

public function deletePieceJointe(Transaction $transaction): void
{
    if ($transaction->piece_jointe_path === null) {
        return;
    }

    Storage::disk('local')->deleteDirectory("pieces-jointes/{$transaction->id}");

    $transaction->update([
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);
}
```

- [ ] **Step 4: Lancer les tests — vérifier qu'ils passent**

Run: `./vendor/bin/sail test tests/Feature/TransactionPieceJointeTest.php`
Expected: tous les tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TransactionService.php tests/Feature/TransactionPieceJointeTest.php
git commit -m "feat: TransactionService — storePieceJointe / deletePieceJointe"
```

---

### Task 5: TransactionForm — upload pièce jointe (espace comptable)

**Files:**
- Modify: `app/Livewire/TransactionForm.php`
- Modify: `resources/views/livewire/transaction-form.blade.php`

- [ ] **Step 1: Modifier le composant Livewire**

Dans `app/Livewire/TransactionForm.php` :

Ajouter les imports :

```php
use Livewire\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
```

Ajouter le trait et la propriété après les propriétés existantes :

```php
use WithFileUploads;
```

Ajouter les propriétés :

```php
/** @var TemporaryUploadedFile|null */
public $pieceJointe = null;

public ?string $existingPieceJointeNom = null;

public ?string $existingPieceJointeUrl = null;
```

Dans `showNewForm()`, ajouter au `$this->reset([...])` les nouveaux champs : `'pieceJointe', 'existingPieceJointeNom', 'existingPieceJointeUrl'`.

Dans `edit(int $id)`, après le chargement des lignes, ajouter :

```php
$this->existingPieceJointeNom = $transaction->piece_jointe_nom;
$this->existingPieceJointeUrl = $transaction->pieceJointeUrl();
$this->pieceJointe = null;
```

Dans `resetForm()`, ajouter les champs au `$this->reset([...])` : `'pieceJointe', 'existingPieceJointeNom', 'existingPieceJointeUrl'`.

Dans `save()`, ajouter la validation du fichier après le bloc de validation existant (après `$this->validate(...)`) :

```php
if ($this->pieceJointe !== null && $this->type === 'depense') {
    $this->validate([
        'pieceJointe' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
    ], [
        'pieceJointe.mimes' => 'Le justificatif doit être un fichier PDF, JPG ou PNG.',
        'pieceJointe.max' => 'Le justificatif ne doit pas dépasser 10 Mo.',
    ]);
}
```

Dans `save()`, après le bloc `try { ... }` qui crée/met à jour la transaction, et avant `$this->dispatch('transaction-saved')`, ajouter la gestion du fichier :

```php
// Sauvegarder la pièce jointe si uploadée
if ($this->pieceJointe !== null && $this->type === 'depense') {
    $tx = $this->transactionId
        ? Transaction::find($this->transactionId)
        : $transaction;
    if ($tx) {
        $service->storePieceJointe($tx, $this->pieceJointe);
    }
}
```

Note : la variable `$transaction` n'existe que dans le cas de la création. Pour la mise à jour, on utilise `$this->transactionId`. Ajuster le bloc try/catch pour capturer la transaction créée :

Modifier le bloc try/catch pour stocker le résultat :

```php
$createdTransaction = null;
try {
    if ($this->transactionId) {
        $transaction = Transaction::findOrFail($this->transactionId);
        $service->update($transaction, $data, $lignes);
    } else {
        $createdTransaction = $service->create($data, $lignes);
    }
} catch (\RuntimeException $e) {
    $this->addError('lignes', $e->getMessage());
    return;
}

// Sauvegarder la pièce jointe si uploadée
if ($this->pieceJointe !== null && $this->type === 'depense') {
    $tx = $createdTransaction ?? Transaction::find($this->transactionId);
    if ($tx) {
        $service->storePieceJointe($tx, $this->pieceJointe);
    }
}
```

Ajouter une méthode pour supprimer la PJ :

```php
public function deletePieceJointe(): void
{
    if (! $this->canEdit || $this->transactionId === null) {
        return;
    }

    $transaction = Transaction::findOrFail($this->transactionId);
    app(TransactionService::class)->deletePieceJointe($transaction);
    $this->existingPieceJointeNom = null;
    $this->existingPieceJointeUrl = null;
}
```

- [ ] **Step 2: Modifier la vue Blade**

Dans `resources/views/livewire/transaction-form.blade.php`, après la `<div class="col-12">` contenant les notes (ligne ~125-129), et avant la fermeture du `</div>` du `row g-3 mb-4`, ajouter :

```blade
{{-- Pièce jointe (dépenses uniquement) --}}
@if ($type === 'depense' && ! $exerciceCloture)
<div class="col-12">
    <label class="form-label"><i class="bi bi-paperclip"></i> Justificatif</label>

    @if ($existingPieceJointeNom && ! $pieceJointe)
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted">{{ $existingPieceJointeNom }}</span>
            <a href="{{ $existingPieceJointeUrl }}" target="_blank" class="btn btn-sm btn-outline-primary" title="Consulter">
                <i class="bi bi-eye"></i>
            </a>
            @if ($this->canEdit)
            <button type="button" wire:click="deletePieceJointe" wire:confirm="Supprimer le justificatif ?"
                    class="btn btn-sm btn-outline-danger" title="Supprimer">
                <i class="bi bi-trash"></i>
            </button>
            @endif
            <label class="btn btn-sm btn-outline-secondary mb-0" title="Remplacer">
                <i class="bi bi-arrow-repeat"></i> Remplacer
                <input type="file" wire:model="pieceJointe" accept=".pdf,.jpg,.jpeg,.png" class="d-none">
            </label>
        </div>
    @else
        <div class="d-flex align-items-center gap-2">
            <label class="btn btn-sm btn-outline-secondary mb-0">
                <i class="bi bi-paperclip"></i> Joindre un justificatif
                <input type="file" wire:model="pieceJointe" accept=".pdf,.jpg,.jpeg,.png" class="d-none">
            </label>
            @if ($pieceJointe)
                <span class="small text-success"><i class="bi bi-check-circle"></i> {{ $pieceJointe->getClientOriginalName() }}</span>
            @endif
            <div wire:loading wire:target="pieceJointe" class="spinner-border spinner-border-sm text-primary"></div>
        </div>
    @endif

    @error('pieceJointe') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
</div>
@endif
```

- [ ] **Step 3: Vérifier manuellement dans le navigateur**

- Aller sur http://localhost/compta/transactions
- Cliquer "Nouvelle dépense" → vérifier que la zone "Justificatif" apparaît sous les notes
- Uploader un PDF → vérifier le spinner puis le nom du fichier
- Enregistrer → vérifier que la transaction est créée
- Ré-ouvrir la transaction → vérifier que le nom du fichier existant s'affiche avec les boutons œil/poubelle/remplacer
- Cliquer "Nouvelle recette" → vérifier que la zone "Justificatif" n'apparaît PAS

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/TransactionForm.php resources/views/livewire/transaction-form.blade.php
git commit -m "feat: upload pièce jointe dans TransactionForm (espace comptable)"
```

---

### Task 6: Icône trombone dans la liste des transactions

**Files:**
- Modify: `resources/views/livewire/transaction-list.blade.php`

- [ ] **Step 1: Ajouter l'icône trombone**

Dans `resources/views/livewire/transaction-list.blade.php`, dans la boucle `@forelse ($transactions as $transaction)`, après la colonne libellé (la `<td>` avec `$transaction->libelle`, vers la ligne 119-128), ajouter l'icône dans la colonne libellé. Modifier le `<td class="small">` du libellé pour ajouter le trombone après le texte :

Remplacer le bloc `<td>` du libellé (lignes ~119-128) par :

```blade
<td class="small">
    @if(!empty($transaction->notes))
        <span data-bs-toggle="tooltip" data-bs-title="{{ $transaction->notes }}" style="cursor:default">
            {{ $transaction->libelle }}
            <i class="bi bi-chat-left-text text-muted ms-1"></i>
        </span>
    @else
        {{ $transaction->libelle }}
    @endif
    @if($transaction->hasPieceJointe())
        <a href="{{ $transaction->pieceJointeUrl() }}" target="_blank" title="Justificatif : {{ $transaction->piece_jointe_nom }}" class="text-muted ms-1">
            <i class="bi bi-paperclip"></i>
        </a>
    @endif
</td>
```

- [ ] **Step 2: Vérifier dans le navigateur**

- Aller sur http://localhost/compta/transactions
- Les transactions avec pièce jointe affichent un trombone cliquable
- Cliquer le trombone → ouvre le fichier dans un nouvel onglet

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/transaction-list.blade.php
git commit -m "feat: icône trombone dans la liste des transactions"
```

---

### Task 7: AnimateurManager — step upload + split view

**Files:**
- Modify: `app/Livewire/AnimateurManager.php`
- Modify: `resources/views/livewire/animateur-manager-modal.blade.php`

- [ ] **Step 1: Modifier le composant Livewire**

Dans `app/Livewire/AnimateurManager.php` :

Ajouter les imports :

```php
use Livewire\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use App\Services\TransactionService;
```

Ajouter le trait :

```php
use WithFileUploads;
```

Ajouter les propriétés après les propriétés de modal existantes :

```php
public string $modalStep = 'form'; // 'upload' | 'form'

/** @var TemporaryUploadedFile|null */
public $modalPieceJointe = null;

public ?string $existingPieceJointeNom = null;

public ?string $existingPieceJointeUrl = null;
```

Modifier `openCreateModal()` — ajouter à la fin (avant `$this->showModal = true;`) :

```php
$this->modalStep = 'upload';
$this->modalPieceJointe = null;
$this->existingPieceJointeNom = null;
$this->existingPieceJointeUrl = null;
```

Ajouter une méthode pour passer au formulaire :

```php
public function skipUpload(): void
{
    $this->modalStep = 'form';
    $this->modalPieceJointe = null;
}

public function proceedWithFile(): void
{
    if ($this->modalPieceJointe !== null) {
        $this->validate([
            'modalPieceJointe' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'modalPieceJointe.mimes' => 'Le justificatif doit être un fichier PDF, JPG ou PNG.',
            'modalPieceJointe.max' => 'Le justificatif ne doit pas dépasser 10 Mo.',
        ]);
    }
    $this->modalStep = 'form';
}
```

Modifier `openEditModal()` — ajouter à la fin (avant `$this->showModal = true;`) :

```php
$this->modalStep = 'form';
$this->modalPieceJointe = null;
$this->existingPieceJointeNom = $transaction->piece_jointe_nom;
$this->existingPieceJointeUrl = $transaction->pieceJointeUrl();
```

Modifier `closeModal()` — ajouter au reset :

```php
$this->modalStep = 'form';
$this->modalPieceJointe = null;
$this->existingPieceJointeNom = null;
$this->existingPieceJointeUrl = null;
```

Modifier `saveTransaction()` — après le bloc `try { ... }` qui crée/met à jour la transaction, avant `$this->closeModal()`, ajouter :

```php
// Sauvegarder la pièce jointe si uploadée
if ($this->modalPieceJointe !== null) {
    $savedTransaction = $this->isEditing
        ? Transaction::find($this->editingTransactionId)
        : Transaction::where('tiers_id', $this->modalTiersId)
            ->where('type', TypeTransaction::Depense)
            ->latest('id')
            ->first();

    if ($savedTransaction) {
        app(TransactionService::class)->storePieceJointe($savedTransaction, $this->modalPieceJointe);
    }
}
```

Note : pour la création, on récupère la dernière transaction créée pour ce tiers. C'est fragile — une meilleure approche : capturer le retour de `$service->create()`. Modifier le bloc try/catch :

```php
try {
    $service = app(TransactionService::class);
    $savedTransaction = null;

    if ($this->isEditing && $this->editingTransactionId !== null) {
        $transaction = Transaction::findOrFail($this->editingTransactionId);
        $service->update($transaction, $data, $lignes);
        $savedTransaction = $transaction;
    } else {
        $savedTransaction = $service->create($data, $lignes);
    }

    // Sauvegarder la pièce jointe si uploadée
    if ($this->modalPieceJointe !== null && $savedTransaction !== null) {
        $service->storePieceJointe($savedTransaction, $this->modalPieceJointe);
    }

    $this->closeModal();
} catch (\Throwable $e) {
    $this->errorMessage = $e->getMessage();
}
```

Cela remplace le bloc try/catch existant dans `saveTransaction()` (lignes ~265-278 de l'original).

- [ ] **Step 2: Modifier la vue modale**

Réécrire `resources/views/livewire/animateur-manager-modal.blade.php` :

```blade
@if($showModal)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" wire:click.self="closeModal">
    <div class="modal-dialog {{ ($modalStep === 'form' && ($modalPieceJointe || $existingPieceJointeUrl)) ? 'modal-xl' : 'modal-lg' }}">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">
                    @if($modalStep === 'upload')
                        Joindre un justificatif
                    @else
                        {{ $isEditing ? 'Modifier la facture' : 'Nouvelle facture d\'encadrement' }}
                    @endif
                </h6>
                <button type="button" class="btn-close" wire:click="closeModal"></button>
            </div>

            @if($modalStep === 'upload')
                {{-- Step 1: Upload --}}
                <div class="modal-body text-center py-5">
                    <div class="mb-4">
                        <i class="bi bi-cloud-arrow-up" style="font-size:3rem;color:#6c757d"></i>
                        <p class="mt-2 text-muted">Uploadez la facture du fournisseur pour l'afficher pendant la saisie</p>
                    </div>

                    @if($modalPieceJointe)
                        <div class="mb-3">
                            <span class="badge bg-success fs-6"><i class="bi bi-check-circle me-1"></i>{{ $modalPieceJointe->getClientOriginalName() }}</span>
                        </div>
                        <button type="button" class="btn btn-primary" wire:click="proceedWithFile">
                            <i class="bi bi-arrow-right me-1"></i> Continuer avec ce fichier
                        </button>
                    @else
                        <label class="btn btn-primary btn-lg mb-3">
                            <i class="bi bi-upload me-2"></i> Choisir un fichier
                            <input type="file" wire:model="modalPieceJointe" accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                        </label>
                        <div wire:loading wire:target="modalPieceJointe" class="mt-2">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <span class="text-muted small">Upload en cours...</span>
                        </div>
                    @endif

                    @error('modalPieceJointe') <div class="text-danger small mt-2">{{ $message }}</div> @enderror

                    <div class="mt-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="skipUpload">
                            Ignorer <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>

            @else
                {{-- Step 2: Formulaire (avec ou sans split view) --}}
                <div class="modal-body">
                    @php
                        $hasPieceJointe = $modalPieceJointe || $existingPieceJointeUrl;
                    @endphp

                    <div class="row">
                        {{-- Colonne gauche : prévisualisation --}}
                        @if($hasPieceJointe)
                        <div class="col-md-5">
                            <div class="border rounded p-1 h-100 d-flex flex-column" style="min-height:500px">
                                @if($modalPieceJointe)
                                    @php $tempUrl = $modalPieceJointe->temporaryUrl(); $mime = $modalPieceJointe->getMimeType(); @endphp
                                @else
                                    @php $tempUrl = $existingPieceJointeUrl; $mime = null; @endphp
                                @endif

                                @if($mime && str_starts_with($mime, 'image/'))
                                    <div class="flex-grow-1 overflow-auto text-center p-2" x-data="{ scale: 1 }">
                                        <div class="mb-2">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="scale = Math.max(0.25, scale - 0.25)"><i class="bi bi-dash-lg"></i></button>
                                            <span class="mx-2 small" x-text="Math.round(scale * 100) + '%'"></span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="scale = Math.min(3, scale + 0.25)"><i class="bi bi-plus-lg"></i></button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary ms-1" @click="scale = 1">1:1</button>
                                        </div>
                                        <img :src="'{{ $tempUrl }}'" :style="'transform: scale(' + scale + '); transform-origin: top center'" class="img-fluid">
                                    </div>
                                @else
                                    <iframe src="{{ $tempUrl }}" class="flex-grow-1 w-100" style="border:none;min-height:500px"></iframe>
                                @endif

                                <div class="text-center py-1 small text-muted border-top">
                                    {{ $modalPieceJointe ? $modalPieceJointe->getClientOriginalName() : $existingPieceJointeNom }}
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- Colonne droite (ou pleine largeur) : formulaire --}}
                        <div class="{{ $hasPieceJointe ? 'col-md-7' : 'col-12' }}">
                            {{-- Error message --}}
                            @if($errorMessage)
                                <div class="alert alert-danger py-2 small">{{ $errorMessage }}</div>
                            @endif

                            {{-- Validation errors --}}
                            @if($errors->any())
                                <div class="alert alert-danger py-2 small">
                                    <ul class="mb-0 ps-3">
                                        @foreach($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{-- Tiers (read-only) --}}
                            <div class="row mb-3">
                                <div class="col-12">
                                    <label class="form-label fw-medium" style="font-size:13px">Tiers</label>
                                    <input type="text" class="form-control form-control-sm" value="{{ $modalTiersLabel }}" disabled>
                                </div>
                            </div>

                            {{-- Date + Reference + Mode paiement + Compte --}}
                            <div class="row mb-3 g-2">
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:12px">Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" wire:model="modalDate">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:12px">N° facture <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" wire:model="modalReference" placeholder="FA-001"
                                           x-init="$nextTick(() => $el.focus())" autofocus>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:12px">Mode paiement</label>
                                    <select class="form-select form-select-sm" wire:model="modalModePaiement">
                                        <option value="">--</option>
                                        @foreach($modesPaiement as $mode)
                                            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:12px">Compte bancaire</label>
                                    <select class="form-select form-select-sm" wire:model="modalCompteId">
                                        <option value="">--</option>
                                        @foreach($comptes as $compte)
                                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Lines table --}}
                            <label class="form-label fw-medium" style="font-size:13px">Lignes de d&eacute;pense</label>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0" style="font-size:12px">
                                    <thead>
                                        <tr style="background:#3d5473;color:#fff">
                                            <th style="min-width:160px">Op&eacute;ration</th>
                                            <th style="min-width:80px">S&eacute;ance</th>
                                            <th style="min-width:200px">Sous-cat&eacute;gorie</th>
                                            <th style="min-width:90px">Montant</th>
                                            <th style="width:40px"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($modalLignes as $idx => $ligne)
                                            <tr wire:key="modal-ligne-{{ $idx }}">
                                                <td>
                                                    <select class="form-select form-select-sm" wire:model="modalLignes.{{ $idx }}.operation_id" style="font-size:11px">
                                                        <option value="">--</option>
                                                        @foreach($this->modalOperations as $op)
                                                            <option value="{{ $op['id'] }}">{{ $op['nom'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                                <td>
                                                    @php
                                                        $opId = $ligne['operation_id'] ?? null;
                                                        $nbSeances = null;
                                                        if ($opId) {
                                                            foreach ($this->modalOperations as $op) {
                                                                if ((int) $op['id'] === (int) $opId) {
                                                                    $nbSeances = $op['nombre_seances'];
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                    @endphp
                                                    @if($nbSeances)
                                                        <select class="form-select form-select-sm" wire:model="modalLignes.{{ $idx }}.seance" style="font-size:11px">
                                                            <option value="">--</option>
                                                            @for($s = 1; $s <= $nbSeances; $s++)
                                                                <option value="{{ $s }}">S{{ $s }}</option>
                                                            @endfor
                                                        </select>
                                                    @else
                                                        <input type="number" class="form-control form-control-sm" wire:model="modalLignes.{{ $idx }}.seance" min="1" style="font-size:11px" placeholder="N&deg;">
                                                    @endif
                                                </td>
                                                <td>
                                                    <livewire:sous-categorie-autocomplete
                                                        wire:model="modalLignes.{{ $idx }}.sous_categorie_id"
                                                        filtre="depense"
                                                        :key="'sc-ac-'.$idx.'-'.($ligne['sous_categorie_id'] ?? 'null')"
                                                    />
                                                </td>
                                                <td>
                                                    <input type="number" step="0.01" min="0.01"
                                                           class="form-control form-control-sm text-end"
                                                           wire:model="modalLignes.{{ $idx }}.montant"
                                                           style="font-size:12px"
                                                           placeholder="0,00">
                                                </td>
                                                <td class="text-center align-middle">
                                                    @if(count($modalLignes) > 1)
                                                        <button type="button" class="btn btn-sm p-0" style="color:#dc3545;font-size:14px;border:none;background:none"
                                                                wire:click="removeModalLigne({{ $idx }})"
                                                                title="Supprimer cette ligne">&times;</button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" wire:click="addModalLigne">
                                <i class="bi bi-plus"></i> Ajouter une ligne
                            </button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer py-2 d-flex justify-content-between align-items-center">
                    <div style="font-size:13px">
                        @php
                            $modalTotal = 0;
                            foreach ($modalLignes as $l) {
                                $modalTotal += (float) ($l['montant'] ?? 0);
                            }
                        @endphp
                        <strong>Total : {{ number_format($modalTotal, 2, ',', "\u{202F}") }} &euro;</strong>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="closeModal">Annuler</button>
                        <button type="button" class="btn btn-sm btn-primary" wire:click="saveTransaction"
                                wire:loading.attr="disabled" wire:target="saveTransaction">
                            <span wire:loading.remove wire:target="saveTransaction">
                                <i class="bi bi-check-lg me-1"></i>{{ $isEditing ? 'Mettre &agrave; jour' : 'Enregistrer' }}
                            </span>
                            <span wire:loading wire:target="saveTransaction">
                                <i class="bi bi-hourglass-split me-1"></i>En cours...
                            </span>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endif
```

- [ ] **Step 3: Vérifier manuellement dans le navigateur**

- Aller sur l'espace gestion → une opération → onglet encadrants
- Cliquer sur un `+` → la modale s'ouvre sur le step upload
- Cliquer "Ignorer" → le formulaire s'affiche en pleine largeur (modal-lg)
- Ré-ouvrir → uploader un PDF → cliquer "Continuer" → split view (modal-xl) avec le PDF à gauche
- Uploader une image JPG → vérifier le zoom +/-
- Remplir le formulaire et enregistrer → vérifier que la pièce jointe est attachée
- Ré-ouvrir en édition → vérifier que le split view s'affiche avec la PJ existante

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/AnimateurManager.php resources/views/livewire/animateur-manager-modal.blade.php
git commit -m "feat: AnimateurManager — step upload + split view pièce jointe"
```

---

### Task 8: Lancer Pint + tests complets

**Files:** aucun nouveau

- [ ] **Step 1: Lancer Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`
Corriger si nécessaire.

- [ ] **Step 2: Lancer tous les tests**

Run: `./vendor/bin/sail test`
Expected: tous les tests PASS.

- [ ] **Step 3: Commit si Pint a modifié des fichiers**

```bash
git add -A && git commit -m "style: pint formatting"
```

---

### Task 9: Nettoyage de la pièce jointe à la suppression d'une transaction

**Files:**
- Modify: `app/Services/TransactionService.php`
- Test: `tests/Feature/TransactionPieceJointeTest.php` (ajout)

- [ ] **Step 1: Écrire le test**

Ajouter à `tests/Feature/TransactionPieceJointeTest.php` :

```php
it('la suppression d\'une transaction supprime aussi la pièce jointe du disque', function () {
    Storage::fake('local');
    $sc = SousCategorie::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $service = app(TransactionService::class);

    $transaction = $service->create([
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-01',
        'libelle' => 'Test',
        'montant_total' => '50.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-PJ',
        'compte_id' => $compte->id,
    ], [['sous_categorie_id' => $sc->id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    $service->storePieceJointe($transaction, $file);
    $path = $transaction->fresh()->piece_jointe_path;

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $service->delete($transaction);

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});
```

- [ ] **Step 2: Lancer le test — vérifier qu'il échoue**

Run: `./vendor/bin/sail test tests/Feature/TransactionPieceJointeTest.php --filter="suppression"`
Expected: FAIL — le fichier reste sur le disque après suppression.

- [ ] **Step 3: Modifier TransactionService::delete()**

Dans `app/Services/TransactionService.php`, dans la méthode `delete()`, dans le `DB::transaction()` callback, ajouter avant `$transaction->delete()` :

```php
// Supprimer la pièce jointe si présente
if ($transaction->hasPieceJointe()) {
    $this->deletePieceJointe($transaction);
}
```

- [ ] **Step 4: Lancer le test — vérifier qu'il passe**

Run: `./vendor/bin/sail test tests/Feature/TransactionPieceJointeTest.php`
Expected: tous les tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TransactionService.php tests/Feature/TransactionPieceJointeTest.php
git commit -m "feat: nettoyage pièce jointe à la suppression de transaction"
```
