# Harmonisation des listes — Lots A+B — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harmoniser visuellement les 6 écrans de liste (Lot A) et ajouter des liens de navigation inter-écrans (Lot B) — uniquement des modifications Blade et un vérification d'eager loading.

**Architecture:** Modifications purement dans les templates Blade Livewire (`resources/views/livewire/*.blade.php`). Aucune modification de logique PHP, sauf vérification que `tiers` est déjà en eager loading dans `CotisationList.php` (il l'est). Aucune migration, aucun modèle à toucher.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 Icons (`bi-*`), Pest PHP pour les tests.

**Worktree:** `/Users/jurgen/dev/svs-accounting/.worktrees/harmonisation-listes-ab` (branche `feature/harmonisation-listes-ab`)

**Spec:** `docs/superpowers/specs/2026-03-18-harmonisation-listes-ab.md`

---

## Fichiers modifiés

| Fichier | Tâche(s) |
|---|---|
| `resources/views/livewire/tiers-list.blade.php` | A1, A2 |
| `resources/views/livewire/don-list.blade.php` | A3, A6 |
| `resources/views/livewire/cotisation-list.blade.php` | A3, A4, A6, B2 |
| `resources/views/livewire/membre-list.blade.php` | A3, A6, B1 |
| `resources/views/livewire/virement-interne-list.blade.php` | A5, A6 |
| `resources/views/livewire/transaction-list.blade.php` | A5 |
| `tests/Feature/Livewire/TiersListTest.php` | créé — Task 1 |
| `tests/Feature/Livewire/DonListTest.php` | créé — Task 2 |
| `tests/Feature/Livewire/CotisationListTest.php` | créé — Task 3 |
| `tests/Feature/Livewire/MembreListTest.php` | créé — Task 4 |
| `tests/Feature/Livewire/VirementInterneListTest.php` | créé — Task 5 |
| `tests/Feature/Livewire/TransactionListTest.php` | créé — Task 6 |

---

## Task 1 : TiersList — en-tête dark (A1) + icônes Dép./Rec. (A2)

**Fichiers :**
- Modify: `resources/views/livewire/tiers-list.blade.php:29`
- Create: `tests/Feature/Livewire/TiersListTest.php`

**Contexte :**
Le `<thead>` utilise actuellement `class="table-light"`. Il faut le remplacer par le style dark uniforme de l'app. Les colonnes Depenses et Recettes affichent `<span class="badge bg-danger">Oui</span>` uniquement quand vrai (rien sinon). Il faut remplacer par `bi-check-lg text-success` quand vrai et `<span class="text-muted">—</span>` quand faux, avec les en-têtes raccourcis `Dép.` et `Rec.`. TiersList a déjà les boutons en `btn-sm` — ne pas toucher les boutons.

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Livewire/TiersListTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\TiersList;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('affiche un en-tête table-dark avec le style bleu', function () {
    Livewire::test(TiersList::class)
        ->assertSeeHtml('class="table-dark"')
        ->assertSeeHtml('--bs-table-bg:#3d5473');
});

it('affiche bi-check-lg pour un tiers avec pour_depenses=true', function () {
    Tiers::factory()->create(['pour_depenses' => true, 'pour_recettes' => false]);

    Livewire::test(TiersList::class)
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('affiche un tiret pour un tiers avec pour_depenses=false', function () {
    Tiers::factory()->create(['pour_depenses' => false, 'pour_recettes' => false]);

    Livewire::test(TiersList::class)
        ->assertSeeHtml('text-muted">—</span>');
});

it('affiche les en-têtes raccourcis Dép. et Rec.', function () {
    Livewire::test(TiersList::class)
        ->assertSee('Dép.')
        ->assertSee('Rec.');
});

it('n\'affiche plus l\'en-tête table-light', function () {
    Livewire::test(TiersList::class)
        ->assertDontSeeHtml('table-light');
});
```

- [ ] **Step 2 : Lancer les tests et vérifier qu'ils échouent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/TiersListTest.php --compact
```

Attendu : FAILED (table-light présent, pas de table-dark, pas de bi-check-lg)

- [ ] **Step 3 : Modifier `tiers-list.blade.php`**

Remplacer les lignes 29–30 (`<thead class="table-light">` + `<tr>`) ensemble — les deux lignes forment une paire et doivent être remplacées ensemble :
```blade
<thead>
    <tr class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
```

Remplacer les en-têtes (lignes 35-36) :
```blade
<th class="text-center">Dép.</th>
<th class="text-center">Rec.</th>
```

Remplacer la cellule Depenses (lignes 51-55) :
```blade
<td class="text-center">
    @if ($tiers->pour_depenses)
        <i class="bi bi-check-lg text-success"></i>
    @else
        <span class="text-muted">—</span>
    @endif
</td>
```

Remplacer la cellule Recettes (lignes 56-60) :
```blade
<td class="text-center">
    @if ($tiers->pour_recettes)
        <i class="bi bi-check-lg text-success"></i>
    @else
        <span class="text-muted">—</span>
    @endif
</td>
```

- [ ] **Step 4 : Lancer les tests et vérifier qu'ils passent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/TiersListTest.php --compact
```

Attendu : 5 passed

- [ ] **Step 5 : Commit**

```bash
cd /Users/jurgen/dev/svs-accounting/.worktrees/harmonisation-listes-ab
git add resources/views/livewire/tiers-list.blade.php tests/Feature/Livewire/TiersListTest.php
git commit -m "feat: TiersList — en-tête dark + icônes Dép./Rec. (A1, A2)"
```

---

## Task 2 : DonList — Pointé icône (A3) + boutons sans style inline (A6)

**Fichiers :**
- Modify: `resources/views/livewire/don-list.blade.php:75-103`
- Create: `tests/Feature/Livewire/DonListTest.php`

**Contexte :**
La colonne Pointé affiche `<span class="badge bg-success">Oui</span>` / `<span class="badge bg-secondary">Non</span>`. Il faut remplacer par `bi-check-lg text-success` / `<span class="text-muted">—</span>`. Les boutons ont déjà `btn-sm` mais avec un `style="padding:.15rem .35rem;font-size:.75rem"` superflu — retirer ces styles inline pour un code propre. La structure `d-flex gap-1` est déjà en place.

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Livewire/DonListTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\DonList;
use App\Models\Don;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('affiche bi-check-lg pour un don pointé', function () {
    Don::factory()->create(['pointe' => true]);

    Livewire::test(DonList::class)
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('affiche un tiret pour un don non pointé', function () {
    Don::factory()->create(['pointe' => false]);

    Livewire::test(DonList::class)
        ->assertSeeHtml('class="text-muted">—</span>')
        ->assertDontSee('Non');
});

it('n\'affiche plus les badges Oui/Non pour Pointé', function () {
    Don::factory()->create(['pointe' => true]);
    Don::factory()->create(['pointe' => false]);

    Livewire::test(DonList::class)
        ->assertDontSeeHtml('badge bg-success">Oui')
        ->assertDontSeeHtml('badge bg-secondary">Non');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    Don::factory()->create();

    Livewire::test(DonList::class)
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
```

- [ ] **Step 2 : Lancer les tests et vérifier qu'ils échouent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/DonListTest.php --compact
```

Attendu : FAILED (badges Oui/Non encore présents)

- [ ] **Step 3 : Modifier `don-list.blade.php`**

Remplacer les lignes 75-82 (colonne Pointé) :
```blade
<td>
    @if ($don->pointe)
        <i class="bi bi-check-lg text-success"></i>
    @else
        <span class="text-muted">—</span>
    @endif
</td>
```

Sur les boutons d'action (lignes 84-103), retirer les attributs `style="padding:.15rem .35rem;font-size:.75rem"` des balises `<button>`.

- [ ] **Step 4 : Lancer les tests et vérifier qu'ils passent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/DonListTest.php --compact
```

Attendu : 4 passed

- [ ] **Step 5 : Commit**

```bash
cd /Users/jurgen/dev/svs-accounting/.worktrees/harmonisation-listes-ab
git add resources/views/livewire/don-list.blade.php tests/Feature/Livewire/DonListTest.php
git commit -m "feat: DonList — Pointé → icône, retrait styles inline boutons (A3, A6)"
```

---

## Task 3 : CotisationList — suppression Exercice (A4) + Pointé icône (A3) + boutons (A6) + nom membre cliquable (B2)

**Fichiers :**
- Modify: `resources/views/livewire/cotisation-list.blade.php`
- Create: `tests/Feature/Livewire/CotisationListTest.php`

**Contexte :**
- A4 : supprimer `<th>Exercice</th>` (ligne 34) et le `<td>` correspondant (ligne 48). Le `colspan="9"` dans la ligne vide (ligne 83) devra passer à `8`.
- A3 : Pointé → icône (lignes 55-61).
- A6 : retirer `style="padding:..."` des boutons.
- B2 : le nom du membre (ligne 49) doit devenir un lien vers `route('tiers.transactions', $cotisation->tiers->id)`. La relation `tiers` est déjà en eager loading dans `CotisationList.php` (with(['tiers', 'compte', 'sousCategorie'])). Guard null-safe requis.

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Livewire/CotisationListTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\CotisationList;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('n\'affiche pas de colonne Exercice', function () {
    Livewire::test(CotisationList::class)
        ->assertDontSee('Exercice');
});

it('affiche bi-check-lg pour une cotisation pointée', function () {
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create(['tiers_id' => $tiers->id, 'pointe' => true]);

    Livewire::test(CotisationList::class)
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('affiche un tiret pour une cotisation non pointée', function () {
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create(['tiers_id' => $tiers->id, 'pointe' => false]);

    Livewire::test(CotisationList::class)
        ->assertDontSeeHtml('badge bg-success">Oui')
        ->assertDontSeeHtml('badge bg-secondary">Non');
});

it('affiche le nom du membre comme lien vers ses transactions', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'type' => 'particulier']);
    Cotisation::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(CotisationList::class)
        ->assertSeeHtml('href="' . route('tiers.transactions', $tiers->id) . '"');
});

it('affiche un tiret sans erreur pour une cotisation sans tiers', function () {
    // tiers_id est nullable dans cotisations (migration add_tiers_id_fk_to_transactions, ligne ->nullable())
    Cotisation::factory()->create(['tiers_id' => null]);

    Livewire::test(CotisationList::class)
        ->assertSee('—');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(CotisationList::class)
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
```

- [ ] **Step 2 : Lancer les tests et vérifier qu'ils échouent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/CotisationListTest.php --compact
```

Attendu : FAILED (Exercice encore présent, badges encore là, pas de lien)

- [ ] **Step 3 : Modifier `cotisation-list.blade.php`**

**Supprimer** `<th>Exercice</th>` (ligne 34).

**Supprimer** la `<td>` Exercice (ligne 48) : `<td class="text-muted small">{{ $cotisation->exercice }}-{{ $cotisation->exercice + 1 }}</td>`

**Changer** `colspan="9"` en `colspan="8"` dans la ligne vide (ligne 83).

**Remplacer** la cellule Membre (ligne 49) :
```blade
<td class="small">
    @if($cotisation->tiers)
        <a href="{{ route('tiers.transactions', $cotisation->tiers->id) }}"
           class="text-decoration-none text-reset">
            <span style="font-size:.7rem">{{ $cotisation->tiers->type === 'entreprise' ? '🏢' : '👤' }}</span>
            {{ $cotisation->tiers->displayName() }}
        </a>
    @else
        <span class="text-muted">—</span>
    @endif
</td>
```

**Remplacer** la colonne Pointé (lignes 55-61) :
```blade
<td>
    @if ($cotisation->pointe)
        <i class="bi bi-check-lg text-success"></i>
    @else
        <span class="text-muted">—</span>
    @endif
</td>
```

**Retirer** les attributs `style="padding:.15rem .35rem;font-size:.75rem"` des boutons.

- [ ] **Step 4 : Lancer les tests et vérifier qu'ils passent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/CotisationListTest.php --compact
```

Attendu : 5 passed

- [ ] **Step 5 : Commit**

```bash
cd /Users/jurgen/dev/svs-accounting/.worktrees/harmonisation-listes-ab
git add resources/views/livewire/cotisation-list.blade.php tests/Feature/Livewire/CotisationListTest.php
git commit -m "feat: CotisationList — supprime Exercice, Pointé → icône, lien membre (A3, A4, A6, B2)"
```

---

## Task 4 : MembreList — Pointé icône (A3) + boutons (A6) + lien transactions (B1)

**Fichiers :**
- Modify: `resources/views/livewire/membre-list.blade.php:71-86`
- Create: `tests/Feature/Livewire/MembreListTest.php`

**Contexte :**
- A3 : La colonne Pointé affiche `$cot->pointe ? '✓' : '—'` (caractère Unicode `✓`). Remplacer le `✓` par `<i class="bi bi-check-lg text-success"></i>`.
- A6 : Le bouton "Nouvelle cotisation" a un `style` inline à retirer.
- B1 : Ajouter un bouton `bi-clock-history` → `route('tiers.transactions', $membre->id)` dans la colonne Actions. La variable `$membre` est un objet `Tiers` — son id est directement `$membre->id`.

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Livewire/MembreListTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\MembreList;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('affiche bi-check-lg Bootstrap Icon pour un membre avec cotisation pointée', function () {
    $tiers = Tiers::factory()->create();
    // La derniereCotisation est chargée via une relation — on crée une cotisation pointée
    \App\Models\Cotisation::factory()->create([
        'tiers_id' => $tiers->id,
        'pointe'   => true,
    ]);

    Livewire::test(MembreList::class)
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('n\'affiche pas le caractère unicode ✓', function () {
    $tiers = Tiers::factory()->create();
    \App\Models\Cotisation::factory()->create([
        'tiers_id' => $tiers->id,
        'pointe'   => true,
    ]);

    Livewire::test(MembreList::class)
        ->assertDontSee('✓');
});

it('affiche un bouton bi-clock-history lié aux transactions du membre', function () {
    $tiers = Tiers::factory()->create();
    \App\Models\Cotisation::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(MembreList::class)
        ->assertSeeHtml('bi bi-clock-history')
        ->assertSeeHtml('href="' . route('tiers.transactions', $tiers->id) . '"');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    $tiers = Tiers::factory()->create();
    \App\Models\Cotisation::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(MembreList::class)
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
```

- [ ] **Step 2 : Lancer les tests et vérifier qu'ils échouent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/MembreListTest.php --compact
```

Attendu : FAILED (✓ unicode encore présent, pas de bi-clock-history)

- [ ] **Step 3 : Modifier `membre-list.blade.php`**

**Remplacer** la cellule Pointé (lignes 71-77) :
```blade
<td class="small">
    @if($cot && $cot->pointe)
        <i class="bi bi-check-lg text-success"></i>
    @else
        <span class="text-muted">—</span>
    @endif
</td>
```

**Remplacer** la cellule Actions (lignes 78-86) — ajouter le bouton historique et retirer le style inline :
```blade
<td class="text-end">
    <div class="d-flex gap-1 justify-content-end">
        <a href="{{ route('tiers.transactions', $membre->id) }}"
           class="btn btn-sm btn-outline-secondary"
           title="Voir les transactions">
            <i class="bi bi-clock-history"></i>
        </a>
        <button
            wire:click="$dispatch('open-cotisation-for-tiers', { tiersId: {{ $membre->id }} })"
            class="btn btn-sm btn-outline-primary"
            title="Nouvelle cotisation">
            <i class="bi bi-plus-circle"></i>
        </button>
    </div>
</td>
```

- [ ] **Step 4 : Lancer les tests et vérifier qu'ils passent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/MembreListTest.php --compact
```

Attendu : 4 passed

- [ ] **Step 5 : Commit**

```bash
cd /Users/jurgen/dev/svs-accounting/.worktrees/harmonisation-listes-ab
git add resources/views/livewire/membre-list.blade.php tests/Feature/Livewire/MembreListTest.php
git commit -m "feat: MembreList — Pointé → icône, lien transactions (A3, A6, B1)"
```

---

## Task 5 : VirementInterneList — notes en tooltip (A5) + suppression colonne Notes (A6)

**Fichiers :**
- Modify: `resources/views/livewire/virement-interne-list.blade.php`
- Create: `tests/Feature/Livewire/VirementInterneListTest.php`

**Contexte :**
- Supprimer le `<th>Notes</th>` (ligne 21) et la `<td>{{ $virement->notes ?? '—' }}</td>` (ligne 34).
- Dans la colonne Référence (ligne 30), accoler `bi-sticky text-muted` avec tooltip si `!empty($virement->notes)`.
- Retirer les styles inline des boutons.
- Le modèle `VirementInterne` a `notes` dans `$fillable` — pas de modification PHP nécessaire.

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Livewire/VirementInterneListTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\VirementInterneList;
use App\Models\CompteBancaire;
use App\Models\User;
use App\Models\VirementInterne;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->source = CompteBancaire::factory()->create();
    $this->destination = CompteBancaire::factory()->create();
});

it('n\'affiche pas de colonne Notes dans l\'en-tête', function () {
    Livewire::test(VirementInterneList::class)
        ->assertDontSee('Notes');
});

it('affiche bi-sticky si le virement a des notes', function () {
    VirementInterne::factory()->create([
        'compte_source_id'      => $this->source->id,
        'compte_destination_id' => $this->destination->id,
        'saisi_par'             => $this->user->id,
        'notes'                 => 'Provision pour charges Q4',
    ]);

    Livewire::test(VirementInterneList::class)
        ->assertSeeHtml('bi bi-sticky')
        ->assertSeeHtml('title="Provision pour charges Q4"');
});

it('n\'affiche pas bi-sticky si notes est null', function () {
    VirementInterne::factory()->create([
        'compte_source_id'      => $this->source->id,
        'compte_destination_id' => $this->destination->id,
        'saisi_par'             => $this->user->id,
        'notes'                 => null,
    ]);

    Livewire::test(VirementInterneList::class)
        ->assertDontSeeHtml('bi bi-sticky');
});

it('n\'affiche pas bi-sticky si notes est une chaîne vide', function () {
    VirementInterne::factory()->create([
        'compte_source_id'      => $this->source->id,
        'compte_destination_id' => $this->destination->id,
        'saisi_par'             => $this->user->id,
        'notes'                 => '',
    ]);

    Livewire::test(VirementInterneList::class)
        ->assertDontSeeHtml('bi bi-sticky');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    VirementInterne::factory()->create([
        'compte_source_id'      => $this->source->id,
        'compte_destination_id' => $this->destination->id,
        'saisi_par'             => $this->user->id,
    ]);

    Livewire::test(VirementInterneList::class)
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
```

- [ ] **Step 2 : Lancer les tests et vérifier qu'ils échouent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/VirementInterneListTest.php --compact
```

Attendu : FAILED (Notes colonne présente, bi-sticky absent)

- [ ] **Step 3 : Modifier `virement-interne-list.blade.php`**

**Supprimer** `<th>Notes</th>` (ligne 21).

**Remplacer** la cellule Référence (ligne 30) :
```blade
<td>
    {{ $virement->reference ?? '—' }}
    @if(!empty($virement->notes))
        <i class="bi bi-sticky text-muted ms-1" title="{{ $virement->notes }}"></i>
    @endif
</td>
```

**Supprimer** la cellule Notes brute (ligne 34) : `<td>{{ $virement->notes ?? '—' }}</td>`

**Retirer** les attributs `style="padding:.15rem .35rem;font-size:.75rem"` des boutons.

- [ ] **Step 4 : Lancer les tests et vérifier qu'ils passent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/VirementInterneListTest.php --compact
```

Attendu : 4 passed

- [ ] **Step 5 : Commit**

```bash
cd /Users/jurgen/dev/svs-accounting/.worktrees/harmonisation-listes-ab
git add resources/views/livewire/virement-interne-list.blade.php tests/Feature/Livewire/VirementInterneListTest.php
git commit -m "feat: VirementInterneList — notes en tooltip bi-sticky, supprime colonne Notes (A5, A6)"
```

---

## Task 6 : TransactionList — notes en tooltip (A5)

**Fichiers :**
- Modify: `resources/views/livewire/transaction-list.blade.php:113`
- Create: `tests/Feature/Livewire/TransactionListTest.php`

**Contexte :**
La colonne Libellé (ligne 113) affiche `{{ $transaction->libelle }}`. Accoler `bi-sticky text-muted` si `!empty($transaction->notes)`. Le modèle `Transaction` a `notes` dans `$fillable` — aucune modification PHP.

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Livewire/TransactionListTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\TransactionList;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('affiche bi-sticky accolé au libellé si la transaction a des notes', function () {
    Transaction::factory()->asDepense()->create([
        'libelle' => 'Loyer octobre',
        'notes'   => 'Provision incluse dans le loyer',
    ]);

    Livewire::test(TransactionList::class)
        ->assertSeeHtml('bi bi-sticky')
        ->assertSeeHtml('title="Provision incluse dans le loyer"');
});

it('n\'affiche pas bi-sticky si notes est null', function () {
    Transaction::factory()->asDepense()->create([
        'libelle' => 'Loyer octobre',
        'notes'   => null,
    ]);

    Livewire::test(TransactionList::class)
        ->assertDontSeeHtml('bi bi-sticky');
});

it('n\'affiche pas bi-sticky si notes est une chaîne vide', function () {
    Transaction::factory()->asDepense()->create([
        'libelle' => 'Loyer octobre',
        'notes'   => '',
    ]);

    Livewire::test(TransactionList::class)
        ->assertDontSeeHtml('bi bi-sticky');
});
```

- [ ] **Step 2 : Lancer les tests et vérifier qu'ils échouent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/TransactionListTest.php --compact
```

Attendu : FAILED (bi-sticky absent)

- [ ] **Step 3 : Modifier `transaction-list.blade.php`**

Remplacer la cellule Libellé (ligne 113) :
```blade
<td class="small">
    {{ $transaction->libelle }}
    @if(!empty($transaction->notes))
        <i class="bi bi-sticky text-muted ms-1" title="{{ $transaction->notes }}"></i>
    @endif
</td>
```

- [ ] **Step 4 : Lancer les tests et vérifier qu'ils passent**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest tests/Feature/Livewire/TransactionListTest.php --compact
```

Attendu : 3 passed

- [ ] **Step 5 : Lancer toute la suite de tests**

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest --compact
```

Attendu : tous passants (la suite existante avait 1 passed + 425 deprecated)

- [ ] **Step 6 : Commit**

```bash
cd /Users/jurgen/dev/svs-accounting/.worktrees/harmonisation-listes-ab
git add resources/views/livewire/transaction-list.blade.php tests/Feature/Livewire/TransactionListTest.php
git commit -m "feat: TransactionList — notes en tooltip bi-sticky (A5)"
```

---

## Vérification finale

Après toutes les tâches, lancer la suite complète :

```bash
cd /Users/jurgen/dev/svs-accounting && ./vendor/bin/pest --compact
```

Tous les tests doivent passer.
