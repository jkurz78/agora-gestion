# Refonte modale TypeOperation — Onglets + Gabarits email TinyMCE

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Découper la modale TypeOperation en 3 onglets (Général/Tarifs/Emails) avec gabarits email éditables via TinyMCE et système modèle par défaut / personnalisé.

**Architecture:** Nouvelle table `email_templates` avec enum `CategorieEmail`, éditeur TinyMCE 6 self-hosted dans `public/vendor/tinymce/`, composant Livewire restructuré avec gestion d'onglets et sous-onglets email. Gabarits par défaut en base (seeder), surcharge optionnelle par type d'opération.

**Tech Stack:** Laravel 11, Livewire 4, Alpine.js, Bootstrap 5 (CDN), TinyMCE 6 (self-hosted, MIT), Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-28-type-operation-tabs-email-templates-design.md`

---

## Fichiers

| Fichier | Action | Responsabilité |
|---------|--------|----------------|
| `app/Enums/CategorieEmail.php` | Créer | Enum: formulaire, attestation, facture |
| `app/Models/EmailTemplate.php` | Créer | Modèle Eloquent |
| `database/migrations/2026_03_28_200001_create_email_templates_table.php` | Créer | Table + migration données + suppression anciennes colonnes |
| `database/seeders/EmailTemplateSeeder.php` | Créer | 3 gabarits par défaut |
| `database/seeders/DatabaseSeeder.php` | Modifier | Appeler EmailTemplateSeeder |
| `app/Models/TypeOperation.php` | Modifier | Ajouter relation emailTemplates(), retirer email_formulaire_* de fillable |
| `public/vendor/tinymce/` | Créer | TinyMCE 6 self-hosted |
| `app/Livewire/TypeOperationManager.php` | Modifier | Onglets, gestion gabarits, template loading |
| `resources/views/livewire/type-operation-manager.blade.php` | Modifier | Restructurer en onglets, TinyMCE, sous-onglets email |
| `app/Mail/FormulaireInvitation.php` | Modifier | HTML riche, nouvelle variable {type_operation} |
| `resources/views/emails/formulaire-invitation.blade.php` | Modifier | Adapter pour HTML riche |
| `app/Livewire/ParticipantTable.php` | Modifier | Charger gabarit depuis email_templates |

---

### Task 1: Database layer — Enum, Model, Migration, Seeder

**Files:**
- Create: `app/Enums/CategorieEmail.php`
- Create: `app/Models/EmailTemplate.php`
- Create: `database/migrations/2026_03_28_200001_create_email_templates_table.php`
- Create: `database/seeders/EmailTemplateSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `app/Models/TypeOperation.php`

**Contexte :** Conventions du projet : `declare(strict_types=1)`, `final class`, type hints partout, PSR-12 via Pint. Les enums existants sont dans `app/Enums/` et ont une méthode `label(): string`. Voir `app/Enums/TypeCategorie.php` pour un exemple.

- [ ] **Step 1: Créer l'enum CategorieEmail**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum CategorieEmail: string
{
    case Formulaire = 'formulaire';
    case Attestation = 'attestation';
    case Facture = 'facture';

    public function label(): string
    {
        return match ($this) {
            self::Formulaire => 'Formulaire',
            self::Attestation => 'Attestation de présence',
            self::Facture => 'Facture',
        };
    }

    /**
     * Variables disponibles pour cette catégorie.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $common = [
            '{prenom}' => 'Prénom du participant',
            '{nom}' => 'Nom du participant',
            '{operation}' => 'Nom de l\'opération',
            '{type_operation}' => 'Nom du type d\'opération',
            '{date_debut}' => 'Date début opération',
            '{date_fin}' => 'Date fin opération',
            '{nb_seances}' => 'Nombre de séances',
        ];

        return match ($this) {
            self::Formulaire => $common,
            self::Attestation => $common + [
                '{numero_seance}' => 'Numéro de la séance',
                '{date_seance}' => 'Date de la séance',
            ],
            self::Facture => $common + [
                '{numero_seance}' => 'Numéro de la séance',
                '{date_seance}' => 'Date de la séance',
                '{date_facture}' => 'Date de la facture',
                '{numero_facture}' => 'Numéro de la facture',
            ],
        };
    }
}
```

- [ ] **Step 2: Créer le modèle EmailTemplate**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategorieEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailTemplate extends Model
{
    protected $fillable = [
        'categorie',
        'type_operation_id',
        'objet',
        'corps',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieEmail::class,
            'type_operation_id' => 'integer',
        ];
    }

    public function typeOperation(): BelongsTo
    {
        return $this->belongsTo(TypeOperation::class);
    }

    /**
     * Sanitise le HTML du corps : ne garde que les balises autorisées.
     */
    public static function sanitizeCorps(string $html): string
    {
        return strip_tags($html, '<p><br><strong><em><u><ul><ol><li><a><h1><h2><h3><h4><span><div>');
    }
}
```

- [ ] **Step 3: Créer la migration**

Fichier : `database/migrations/2026_03_28_200001_create_email_templates_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('categorie', 20);
            $table->foreignId('type_operation_id')->nullable()->constrained('type_operations')->cascadeOnDelete();
            $table->string('objet', 255);
            $table->text('corps');
            $table->timestamps();

            $table->unique(['categorie', 'type_operation_id']);
        });

        // Migrate existing email templates from type_operations
        $types = DB::table('type_operations')
            ->whereNotNull('email_formulaire_corps')
            ->get(['id', 'email_formulaire_objet', 'email_formulaire_corps']);

        foreach ($types as $type) {
            DB::table('email_templates')->insert([
                'categorie' => 'formulaire',
                'type_operation_id' => $type->id,
                'objet' => $type->email_formulaire_objet ?? 'Formulaire à compléter — {operation}',
                'corps' => $type->email_formulaire_corps,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Remove old columns from type_operations
        Schema::table('type_operations', function (Blueprint $table) {
            $table->dropColumn(['email_formulaire_objet', 'email_formulaire_corps']);
        });
    }

    public function down(): void
    {
        // Re-add columns to type_operations
        Schema::table('type_operations', function (Blueprint $table) {
            $table->string('email_formulaire_objet', 255)->nullable();
            $table->text('email_formulaire_corps')->nullable();
        });

        // Copy data back
        $templates = DB::table('email_templates')
            ->where('categorie', 'formulaire')
            ->whereNotNull('type_operation_id')
            ->get();

        foreach ($templates as $t) {
            DB::table('type_operations')
                ->where('id', $t->type_operation_id)
                ->update([
                    'email_formulaire_objet' => $t->objet,
                    'email_formulaire_corps' => $t->corps,
                ]);
        }

        Schema::dropIfExists('email_templates');
    }
};
```

- [ ] **Step 4: Mettre à jour TypeOperation modèle**

Dans `app/Models/TypeOperation.php` :
- Retirer `'email_formulaire_objet'` et `'email_formulaire_corps'` de `$fillable`
- Ajouter la relation `emailTemplates()` :

```php
public function emailTemplates(): HasMany
{
    return $this->hasMany(EmailTemplate::class);
}
```

Ajouter l'import : `use App\Models\EmailTemplate;` (en réalité pas nécessaire si le return type est HasMany, mais par cohérence).

- [ ] **Step 5: Créer le seeder**

Fichier `database/seeders/EmailTemplateSeeder.php` — crée 3 gabarits par défaut (`type_operation_id = NULL`). Le contenu sera du HTML riche basique.

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        EmailTemplate::updateOrCreate(
            ['categorie' => 'formulaire', 'type_operation_id' => null],
            [
                'objet' => 'Formulaire à compléter — {operation}',
                'corps' => '<p>Bonjour <strong>{prenom}</strong>,</p>'
                    . '<p>Nous vous invitons à compléter votre formulaire d\'inscription pour <strong>{operation}</strong> ({type_operation}).</p>'
                    . '<p>Dates : du {date_debut} au {date_fin}.</p>'
                    . '<p>Merci de compléter ce formulaire dans les meilleurs délais.</p>'
                    . '<p>Cordialement,<br>L\'équipe</p>',
            ],
        );

        EmailTemplate::updateOrCreate(
            ['categorie' => 'attestation', 'type_operation_id' => null],
            [
                'objet' => 'Attestation de présence — {operation}',
                'corps' => '<p>Bonjour <strong>{prenom}</strong>,</p>'
                    . '<p>Veuillez trouver ci-joint votre attestation de présence pour <strong>{operation}</strong>.</p>'
                    . '<p>Séance n°{numero_seance} du {date_seance}.</p>'
                    . '<p>Cordialement,<br>L\'équipe</p>',
            ],
        );

        EmailTemplate::updateOrCreate(
            ['categorie' => 'facture', 'type_operation_id' => null],
            [
                'objet' => 'Facture n°{numero_facture} — {operation}',
                'corps' => '<p>Bonjour <strong>{prenom} {nom}</strong>,</p>'
                    . '<p>Veuillez trouver ci-joint la facture n°<strong>{numero_facture}</strong> du {date_facture} '
                    . 'relative à <strong>{operation}</strong>.</p>'
                    . '<p>Cordialement,<br>L\'équipe</p>',
            ],
        );
    }
}
```

- [ ] **Step 6: Ajouter l'appel au seeder dans DatabaseSeeder**

Dans `database/seeders/DatabaseSeeder.php`, ajouter après la ligne `$this->call(TypeOperationSeeder::class);` :
```php
$this->call(EmailTemplateSeeder::class);
```

- [ ] **Step 7: Exécuter la migration et le seeder**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed --class=EmailTemplateSeeder
```

- [ ] **Step 8: Lancer les tests**

```bash
./vendor/bin/sail test
```

Corriger les tests qui échouent à cause des colonnes `email_formulaire_*` supprimées (probablement dans `tests/Feature/TypeOperationTest.php` ou similaire).

- [ ] **Step 9: Pint + Commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint
git add app/Enums/CategorieEmail.php app/Models/EmailTemplate.php app/Models/TypeOperation.php database/migrations/2026_03_28_200001_create_email_templates_table.php database/seeders/EmailTemplateSeeder.php database/seeders/DatabaseSeeder.php
git add -u  # pour les fichiers modifiés/tests
git commit -m "feat(email-templates): table email_templates, enum, modèle, seeder, migration données

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: TinyMCE 6 — Setup self-hosted

**Files:**
- Create: `public/vendor/tinymce/` (dossier avec les fichiers TinyMCE)

**Contexte :** Le projet n'utilise pas npm/Vite. Tout est en CDN ou fichiers statiques dans `public/`. TinyMCE 6 est MIT en self-hosted.

- [ ] **Step 1: Télécharger TinyMCE 6**

```bash
cd /Users/jurgen/dev/svs-accounting
mkdir -p public/vendor/tinymce
curl -L "https://download.tiny.cloud/tinymce/community/tinymce_6.8.5.zip" -o /tmp/tinymce.zip
unzip -o /tmp/tinymce.zip -d /tmp/tinymce_extract
cp -r /tmp/tinymce_extract/tinymce/js/tinymce/* public/vendor/tinymce/
rm -rf /tmp/tinymce.zip /tmp/tinymce_extract
```

Si le téléchargement échoue, essayer une version alternative :
```bash
curl -L "https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" -o public/vendor/tinymce/tinymce.min.js
```

En dernier recours, utiliser le CDN dans la vue Blade : `<script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js"></script>`

- [ ] **Step 2: Vérifier que le fichier principal existe**

```bash
ls -la public/vendor/tinymce/tinymce.min.js
```

- [ ] **Step 3: Ajouter public/vendor/ au .gitignore**

Vérifier si `public/vendor/` est déjà dans `.gitignore`. Si non, l'ajouter. Alternativement, on peut commit les fichiers TinyMCE si on veut un déploiement sans étapes supplémentaires. **Recommandation : commit les fichiers** pour simplifier le déploiement O2Switch.

- [ ] **Step 4: Commit**

```bash
git add public/vendor/tinymce/
git commit -m "chore: ajout TinyMCE 6 self-hosted

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Refactor TypeOperationManager — Onglets + Général

**Files:**
- Modify: `app/Livewire/TypeOperationManager.php`
- Modify: `resources/views/livewire/type-operation-manager.blade.php`

**Contexte :** Restructurer la modale en 3 onglets. Cette task ne touche que l'infrastructure des onglets et l'onglet Général. Les onglets Tarifs et Emails seront dans les tasks suivantes. Le composant Livewire fait actuellement 385 lignes.

- [ ] **Step 1: Ajouter les propriétés d'onglet au composant**

Dans `TypeOperationManager.php`, ajouter après la propriété `$editingId` :

```php
// ── Tab state ──────────────────────────────────────────────
public int $activeTab = 1;

public int $maxVisitedTab = 1;
```

Mettre à jour `resetForm()` pour réinitialiser :
```php
$this->activeTab = 1;
$this->maxVisitedTab = 1;
```

Mettre à jour `openEdit()` pour permettre tous les onglets en édition :
```php
$this->maxVisitedTab = 3;
```
(ajouter cette ligne après `$this->showModal = true;`)

Ajouter les méthodes de navigation :
```php
public function goToTab(int $tab): void
{
    if ($tab > $this->maxVisitedTab && $this->editingId === null) {
        return;
    }
    $this->activeTab = $tab;
}

public function nextTab(): void
{
    if ($this->activeTab < 3) {
        $this->activeTab++;
        if ($this->activeTab > $this->maxVisitedTab) {
            $this->maxVisitedTab = $this->activeTab;
        }
    }
}

public function previousTab(): void
{
    if ($this->activeTab > 1) {
        $this->activeTab--;
    }
}
```

- [ ] **Step 2: Restructurer la vue Blade en onglets**

Remplacer le contenu de la modale dans `type-operation-manager.blade.php`. La modale doit contenir :

1. **Barre d'onglets** en haut de la modale (après le titre h5) :
```blade
{{-- Tab navigation --}}
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <button class="nav-link {{ $activeTab === 1 ? 'active' : '' }} {{ ($editingId !== null || $maxVisitedTab >= 1) ? '' : 'disabled' }}"
                wire:click="goToTab(1)" type="button">
            Général
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link {{ $activeTab === 2 ? 'active' : '' }} {{ ($editingId !== null || $maxVisitedTab >= 2) ? '' : 'disabled' }}"
                wire:click="goToTab(2)" type="button">
            Tarifs
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link {{ $activeTab === 3 ? 'active' : '' }} {{ ($editingId !== null || $maxVisitedTab >= 3) ? '' : 'disabled' }}"
                wire:click="goToTab(3)" type="button">
            Emails
        </button>
    </li>
</ul>
```

2. **Contenu conditionnel** par onglet : `@if($activeTab === 1)` ... `@endif` etc.

3. **Boutons de navigation** en bas :
```blade
<div class="d-flex justify-content-between mt-4">
    @if($activeTab > 1)
        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="previousTab">
            <i class="bi bi-arrow-left"></i> Précédent
        </button>
    @else
        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showModal', false)">Annuler</button>
    @endif

    <div class="d-flex gap-2">
        @if($editingId !== null)
            <button type="button" class="btn btn-sm btn-primary" wire:click="save">
                <i class="bi bi-check-lg"></i> Enregistrer
            </button>
        @endif

        @if($activeTab < 3)
            <button type="button" class="btn btn-sm btn-primary" wire:click="nextTab">
                Suivant <i class="bi bi-arrow-right"></i>
            </button>
        @elseif($editingId === null)
            <button type="button" class="btn btn-sm btn-primary" wire:click="save">
                <i class="bi bi-check-lg"></i> Enregistrer
            </button>
        @endif
    </div>
</div>
```

4. L'onglet **Général** contient le contenu actuel de la modale SAUF les tarifs et l'email (Code, Nom, Description, Sous-catégorie, Nb séances, Switches, Logo).

5. L'onglet **Tarifs** contient un placeholder `<p class="text-muted">Onglet Tarifs — à venir (Task 4)</p>` temporairement.

6. L'onglet **Emails** contient un placeholder `<p class="text-muted">Onglet Emails — à venir (Task 5)</p>` temporairement.

- [ ] **Step 3: Vérifier que les tests passent**

```bash
./vendor/bin/sail test
```

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/TypeOperationManager.php resources/views/livewire/type-operation-manager.blade.php
git commit -m "feat(type-operation): restructurer modale en 3 onglets avec navigation

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Onglet Tarifs — Tableau propre

**Files:**
- Modify: `resources/views/livewire/type-operation-manager.blade.php`

**Contexte :** Remplacer le placeholder de l'onglet Tarifs par un tableau propre avec en-tête bleu foncé `#3d5473`, colonnes Libellé/Montant/Actions, montants formatés, ajout en dernière ligne, tri par montant décroissant. Le composant Livewire gère déjà les tarifs (propriété `$tarifs`, méthodes `addTarif()`, `removeTarif()`), pas besoin de toucher au PHP.

- [ ] **Step 1: Remplacer le placeholder Tarifs par le tableau**

Dans l'onglet `@if($activeTab === 2)`, remplacer le placeholder par :

```blade
@if($activeTab === 2)
    {{-- Onglet Tarifs --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Libellé</th>
                    <th class="text-end" style="width:120px">Montant</th>
                    <th style="width:50px"></th>
                </tr>
            </thead>
            <tbody>
                @php
                    $sortedTarifs = collect($tarifs)->sortByDesc(fn ($t) => (float) str_replace(',', '.', $t['montant']));
                @endphp
                @forelse ($sortedTarifs as $index => $tarif)
                    <tr>
                        <td class="small">{{ $tarif['libelle'] }}</td>
                        <td class="text-end small">{{ number_format((float) str_replace(',', '.', $tarif['montant']), 2, ',', ' ') }} &euro;</td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                    wire:click="removeTarif({{ $index }})" title="Retirer">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-muted small">Aucun tarif défini.</td>
                    </tr>
                @endforelse
                {{-- Ligne d'ajout --}}
                <tr class="table-light">
                    <td>
                        <input type="text" wire:model="newTarifLibelle" class="form-control form-control-sm" placeholder="Libellé">
                    </td>
                    <td>
                        <input type="text" wire:model="newTarifMontant" class="form-control form-control-sm text-end" placeholder="0,00">
                        @error('newTarifMontant') <div class="text-danger small">{{ $message }}</div> @enderror
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-success" wire:click="addTarif" title="Ajouter">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
@endif
```

- [ ] **Step 2: Vérifier que les tests passent**

```bash
./vendor/bin/sail test
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/type-operation-manager.blade.php
git commit -m "feat(type-operation): onglet Tarifs avec tableau trié par montant décroissant

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Onglet Emails — Gabarits + TinyMCE

**Files:**
- Modify: `app/Livewire/TypeOperationManager.php`
- Modify: `resources/views/livewire/type-operation-manager.blade.php`

**Contexte :** C'est la task la plus conséquente. Elle ajoute :
- Les propriétés de gestion de gabarits email au composant
- Les sous-onglets Formulaire/Attestation/Facture
- L'intégration TinyMCE avec bouton Variables custom
- La logique Personnaliser / Revenir au défaut

- [ ] **Step 1: Ajouter les propriétés email templates au composant**

Dans `TypeOperationManager.php`, remplacer les propriétés email existantes :

```php
// ── Email fields ──────────────────────────────────────────────
public string $email_from = '';

public string $email_from_name = '';

public string $testEmailTo = '';

public bool $showTestEmailModal = false;

// ── Email template state ──────────────────────────────────────
public string $emailSubTab = 'formulaire';

/** @var array<string, array{id: int|null, objet: string, corps: string, is_default: bool}> */
public array $emailTemplates = [];
```

Supprimer les propriétés `$email_formulaire_objet` et `$email_formulaire_corps`.

- [ ] **Step 2: Ajouter les méthodes de gestion des gabarits**

```php
public function loadEmailTemplates(?int $typeOperationId): void
{
    foreach (CategorieEmail::cases() as $cat) {
        // Try custom template for this type first
        $custom = $typeOperationId !== null
            ? EmailTemplate::where('categorie', $cat->value)
                ->where('type_operation_id', $typeOperationId)
                ->first()
            : null;

        if ($custom) {
            $this->emailTemplates[$cat->value] = [
                'id' => $custom->id,
                'objet' => $custom->objet,
                'corps' => $custom->corps,
                'is_default' => false,
            ];
        } else {
            // Fall back to default
            $default = EmailTemplate::where('categorie', $cat->value)
                ->whereNull('type_operation_id')
                ->first();

            $this->emailTemplates[$cat->value] = [
                'id' => $default?->id,
                'objet' => $default?->objet ?? '',
                'corps' => $default?->corps ?? '',
                'is_default' => true,
            ];
        }
    }
}

public function personnaliserTemplate(string $categorie): void
{
    if (! isset($this->emailTemplates[$categorie]) || ! $this->emailTemplates[$categorie]['is_default']) {
        return;
    }

    // Mark as custom (will be saved on save())
    $this->emailTemplates[$categorie]['is_default'] = false;
    $this->emailTemplates[$categorie]['id'] = null;
}

public function revenirAuDefaut(string $categorie): void
{
    if (! isset($this->emailTemplates[$categorie]) || $this->emailTemplates[$categorie]['is_default']) {
        return;
    }

    // Delete custom template if it exists in DB
    if ($this->emailTemplates[$categorie]['id'] !== null) {
        EmailTemplate::where('id', $this->emailTemplates[$categorie]['id'])->delete();
    }

    // Reload default
    $default = EmailTemplate::where('categorie', $categorie)
        ->whereNull('type_operation_id')
        ->first();

    $this->emailTemplates[$categorie] = [
        'id' => $default?->id,
        'objet' => $default?->objet ?? '',
        'corps' => $default?->corps ?? '',
        'is_default' => true,
    ];
}
```

Ajouter les imports en haut du fichier :
```php
use App\Enums\CategorieEmail;
use App\Models\EmailTemplate;
```

- [ ] **Step 3: Mettre à jour openCreate(), openEdit(), save(), resetForm()**

**openCreate()** — après `$this->showModal = true;` ajouter :
```php
$this->loadEmailTemplates(null);
```

**openEdit()** — remplacer les lignes `email_formulaire_*` par :
```php
$this->loadEmailTemplates($type->id);
```
Et garder `email_from`, `email_from_name` comme avant.

**save()** — dans le `$data` array, retirer les lignes `email_formulaire_objet` et `email_formulaire_corps`. Après le `syncTarifs()`, ajouter :
```php
// ── Sync email templates ─────────────────────────
$this->syncEmailTemplates($type);
```

Créer la méthode `syncEmailTemplates()` :
```php
private function syncEmailTemplates(TypeOperation $type): void
{
    foreach ($this->emailTemplates as $categorie => $data) {
        if ($data['is_default']) {
            continue; // Don't touch defaults
        }

        EmailTemplate::updateOrCreate(
            ['categorie' => $categorie, 'type_operation_id' => $type->id],
            [
                'objet' => $data['objet'],
                'corps' => EmailTemplate::sanitizeCorps($data['corps']),
            ],
        );
    }
}
```

**resetForm()** — remplacer les lignes `email_formulaire_*` par :
```php
$this->emailSubTab = 'formulaire';
$this->emailTemplates = [];
```

- [ ] **Step 4: Créer la vue de l'onglet Emails**

Remplacer le placeholder de l'onglet Emails dans la vue Blade. L'onglet contient :

1. **Adresse expéditeur** (en haut, inchangée)
2. **Sous-onglets** (nav-pills) Formulaire / Attestation / Facture
3. **Pour chaque sous-onglet** : select Défaut/Personnalisé, boutons, éditeur TinyMCE

Le TinyMCE doit être dans un div `wire:ignore` pour survivre aux re-renders Livewire. La synchronisation se fait via Alpine.js qui écoute les events TinyMCE et met à jour le modèle Livewire.

```blade
@if($activeTab === 3)
    {{-- Adresse expéditeur --}}
    <div class="mb-3 p-3 bg-light rounded border">
        <label class="form-label small fw-semibold">Adresse d'expédition</label>
        <div class="row g-2">
            <div class="col-md-3">
                <input type="text" wire:model="email_from_name" class="form-control form-control-sm" placeholder="Nom expéditeur">
            </div>
            <div class="col-md-6">
                <input type="email" wire:model.live.debounce.500ms="email_from" class="form-control form-control-sm @error('email_from') is-invalid @enderror" placeholder="adresse@exemple.fr">
                @error('email_from') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-sm btn-outline-secondary w-100"
                        {{ $email_from ? '' : 'disabled' }}
                        wire:click="openTestEmailModal">
                    <i class="bi bi-envelope"></i> Tester
                </button>
            </div>
        </div>
    </div>

    {{-- Sous-onglets email --}}
    <ul class="nav nav-pills nav-fill mb-3">
        @foreach (\App\Enums\CategorieEmail::cases() as $cat)
            <li class="nav-item">
                <button class="nav-link {{ $emailSubTab === $cat->value ? 'active' : '' }}"
                        wire:click="$set('emailSubTab', '{{ $cat->value }}')" type="button">
                    {{ $cat->label() }}
                </button>
            </li>
        @endforeach
    </ul>

    {{-- Contenu du sous-onglet actif --}}
    @php $tplData = $emailTemplates[$emailSubTab] ?? null; @endphp
    @if($tplData)
        <div class="border rounded p-3">
            {{-- Statut + Boutons --}}
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge {{ $tplData['is_default'] ? 'bg-secondary' : 'bg-primary' }}">
                    {{ $tplData['is_default'] ? 'Modèle par défaut' : 'Personnalisé' }}
                </span>
                @if($tplData['is_default'])
                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="personnaliserTemplate('{{ $emailSubTab }}')">
                        <i class="bi bi-pencil"></i> Personnaliser
                    </button>
                @else
                    <button type="button" class="btn btn-sm btn-outline-warning" wire:click="revenirAuDefaut('{{ $emailSubTab }}')">
                        <i class="bi bi-arrow-counterclockwise"></i> Revenir au défaut
                    </button>
                @endif
            </div>

            {{-- Objet --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Objet</label>
                <input type="text" class="form-control form-control-sm"
                       wire:model="emailTemplates.{{ $emailSubTab }}.objet"
                       {{ $tplData['is_default'] ? 'readonly' : '' }}>
            </div>

            {{-- Corps — TinyMCE --}}
            <div class="mb-2">
                <label class="form-label small fw-semibold">Corps</label>
            </div>
            <div wire:ignore
                 x-data="emailEditor('{{ $emailSubTab }}', @js($tplData['corps']), @js($tplData['is_default']), @js(\App\Enums\CategorieEmail::from($emailSubTab)->variables()))"
                 x-init="initEditor()"
                 x-effect="updateReadonly()">
                <textarea x-ref="editor" style="visibility:hidden"></textarea>
            </div>

            <div class="form-text small mt-2">
                Variables disponibles :
                @foreach (\App\Enums\CategorieEmail::from($emailSubTab)->variables() as $var => $desc)
                    <code title="{{ $desc }}">{{ $var }}</code>
                @endforeach
            </div>
        </div>
    @endif
@endif
```

- [ ] **Step 5: Ajouter le JavaScript TinyMCE + Alpine**

En bas de la vue, dans la section `<script>`, ajouter la fonction Alpine `emailEditor` et l'initialisation TinyMCE :

```blade
{{-- TinyMCE initialization --}}
@if($showModal && $activeTab === 3)
<script src="{{ asset('vendor/tinymce/tinymce.min.js') }}"></script>
<script>
    function emailEditor(categorie, initialContent, isReadonly, variables) {
        return {
            editor: null,
            categorie: categorie,

            initEditor() {
                const self = this;
                const textarea = this.$refs.editor;

                // Build variables menu items
                const menuItems = Object.entries(variables).map(([key, label]) => ({
                    type: 'menuitem',
                    text: key + ' — ' + label,
                    onAction: () => self.editor.insertContent(key),
                }));

                tinymce.init({
                    target: textarea,
                    language: 'fr_FR',
                    language_url: '/vendor/tinymce/langs/fr_FR.js',
                    height: 250,
                    menubar: false,
                    statusbar: false,
                    plugins: 'lists link',
                    toolbar: 'bold italic underline | bullist numlist | link | variablesButton',
                    readonly: isReadonly,
                    content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
                    setup: function (editor) {
                        self.editor = editor;

                        editor.ui.registry.addMenuButton('variablesButton', {
                            text: 'Variables',
                            fetch: function (callback) {
                                callback(menuItems);
                            },
                        });

                        editor.on('init', function () {
                            editor.setContent(initialContent || '');
                        });

                        editor.on('Change KeyUp', function () {
                            if (!isReadonly) {
                                const content = editor.getContent();
                                self.$wire.set('emailTemplates.' + categorie + '.corps', content);
                            }
                        });
                    },
                });
            },

            updateReadonly() {
                // Called by x-effect when is_default changes
                if (this.editor) {
                    const currentReadonly = this.$wire.get('emailTemplates.' + this.categorie + '.is_default');
                    this.editor.mode.set(currentReadonly ? 'readonly' : 'design');
                }
            },

            destroy() {
                if (this.editor) {
                    tinymce.remove(this.editor);
                }
            },
        };
    }
</script>
@endif
```

**Note importante :** L'emplacement du `<script>` TinyMCE et la conditionnelle `@if($showModal && $activeTab === 3)` sont essentiels pour charger TinyMCE uniquement quand nécessaire. Si le chargement conditionnel pose problème, charger TinyMCE inconditionnellement dans le layout principal via `@push('scripts')`.

- [ ] **Step 6: Lancer les tests**

```bash
./vendor/bin/sail test
```

- [ ] **Step 7: Tester visuellement en local**

Ouvrir http://localhost, aller dans Paramètres > Type d'opérations, ouvrir un type en édition, vérifier :
- Les 3 onglets fonctionnent
- L'onglet Emails affiche les sous-onglets
- TinyMCE se charge correctement
- Le bouton Variables fonctionne
- Personnaliser / Revenir au défaut fonctionne

- [ ] **Step 8: Pint + Commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint
git add app/Livewire/TypeOperationManager.php resources/views/livewire/type-operation-manager.blade.php
git commit -m "feat(type-operation): onglet Emails avec TinyMCE et gabarits personnalisables

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Adapter FormulaireInvitation + ParticipantTable

**Files:**
- Modify: `app/Mail/FormulaireInvitation.php`
- Modify: `resources/views/emails/formulaire-invitation.blade.php`
- Modify: `app/Livewire/ParticipantTable.php`

**Contexte :** Le corps email est maintenant du HTML riche (TinyMCE). Il ne faut plus appliquer `nl2br(e(...))`. Il faut sanitiser avec `strip_tags()` sur les balises autorisées. Ajouter la variable `{type_operation}`.

- [ ] **Step 1: Mettre à jour FormulaireInvitation**

Modifier le constructeur pour ajouter `$nomTypeOperation` et ne plus appliquer `nl2br(e(...))` :

```php
public function __construct(
    public readonly string $prenomParticipant,
    public readonly string $nomParticipant,
    public readonly string $nomOperation,
    public readonly string $nomTypeOperation,
    public readonly string $formulaireUrl,
    public readonly string $tokenCode,
    public readonly string $dateExpiration,
    public readonly string $dateDebut = '',
    public readonly string $dateFin = '',
    public readonly string $nombreSeances = '',
    public readonly ?string $customObjet = null,
    public readonly ?string $customCorps = null,
) {
    $vars = $this->variables();

    $corps = $this->customCorps
        ?? '<p>Bonjour <strong>{prenom}</strong>,</p><p>Nous vous invitons à compléter votre formulaire pour <strong>{operation}</strong>.</p>';

    $corps = str_replace(array_keys($vars), array_values($vars), $corps);

    // Sanitize HTML — only allow safe tags
    $this->corpsHtml = EmailTemplate::sanitizeCorps($corps);
}
```

Mettre à jour `variables()` pour inclure `{type_operation}` :
```php
private function variables(): array
{
    return [
        '{prenom}' => $this->prenomParticipant,
        '{nom}' => $this->nomParticipant,
        '{operation}' => $this->nomOperation,
        '{type_operation}' => $this->nomTypeOperation,
        '{date_debut}' => $this->dateDebut,
        '{date_fin}' => $this->dateFin,
        '{nb_seances}' => $this->nombreSeances,
    ];
}
```

Ajouter l'import : `use App\Models\EmailTemplate;`

- [ ] **Step 2: Mettre à jour la vue email**

La vue `resources/views/emails/formulaire-invitation.blade.php` affiche déjà `{!! $corpsHtml !!}`. Rien à changer si le HTML est déjà sanitisé côté PHP. Vérifier que c'est le cas.

- [ ] **Step 3: Mettre à jour ParticipantTable**

Dans `app/Livewire/ParticipantTable.php`, méthode `envoyerTokenParEmail()`, remplacer la construction du mail :

```php
// Charger le gabarit email
$template = EmailTemplate::where('categorie', 'formulaire')
    ->where('type_operation_id', $typeOp->id)
    ->first()
    ?? EmailTemplate::where('categorie', 'formulaire')
        ->whereNull('type_operation_id')
        ->first();

$mail = new FormulaireInvitation(
    prenomParticipant: $participant->tiers->prenom ?? 'Participant',
    nomParticipant: $participant->tiers->nom ?? '',
    nomOperation: $op->nom,
    nomTypeOperation: $typeOp->nom,
    formulaireUrl: $this->tokenUrl,
    tokenCode: $this->tokenCode ?? '',
    dateExpiration: Carbon::parse($this->tokenExpireAt)->format('d/m/Y'),
    dateDebut: $op->date_debut?->format('d/m/Y') ?? '',
    dateFin: $op->date_fin?->format('d/m/Y') ?? '',
    nombreSeances: $op->nombre_seances !== null ? (string) $op->nombre_seances : '',
    customObjet: $template?->objet,
    customCorps: $template?->corps,
);
```

Ajouter l'import : `use App\Models\EmailTemplate;`

- [ ] **Step 4: Lancer les tests**

```bash
./vendor/bin/sail test
```

- [ ] **Step 5: Pint + Commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint
git add app/Mail/FormulaireInvitation.php resources/views/emails/formulaire-invitation.blade.php app/Livewire/ParticipantTable.php
git commit -m "feat(email): HTML riche + variable {type_operation} + chargement depuis email_templates

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```
