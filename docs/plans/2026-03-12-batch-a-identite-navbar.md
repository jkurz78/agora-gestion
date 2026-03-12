# Batch A — Identité visuelle & Navigation — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Remplacer "SVS Comptabilité" par le logo + nom sur 2 lignes ("Soigner•Vivre•Sourire" / "Comptabilité") dans la navbar et la page de connexion, et supprimer "Tableau de bord" du menu principal.

**Architecture:** Modifications purement de templates Blade — aucun changement de modèle, migration ou service requis. Le logo est un asset statique dans `public/images/`. Les deux layouts (`app.blade.php` et `guest.blade.php`) sont mis à jour indépendamment.

**Tech Stack:** Laravel 11, Blade, Bootstrap 5 CDN, Pest PHP

---

## Prérequis

> ⚠️ **Avant de commencer** : demander à l'utilisateur de déposer le fichier logo PNG à n'importe quel chemin sur son filesystem. Le copier ensuite dans `public/images/logo.png`. Si le fichier n'est pas encore disponible, créer un placeholder (voir Task 1) pour que les tests passent, puis le remplacer par le vrai logo.

---

### Task 1 : Préparer l'asset logo

**Files:**
- Create: `public/images/logo.png`

**Step 1 : Créer le dossier images**

```bash
mkdir -p public/images
```

**Step 2 : Copier le logo**

Si l'utilisateur a fourni le chemin du fichier (ex: `/Users/jurgen/Desktop/logo.png`) :
```bash
cp /CHEMIN/FOURNI/PAR/UTILISATEUR/logo.png public/images/logo.png
```

Si le logo n'est pas encore disponible, créer un PNG 1×1 pixel de substitution pour que les tests passent :
```bash
# Crée un PNG minimal valide (1x1 pixel transparent)
printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82' > public/images/logo.png
```

**Step 3 : Vérifier**

```bash
ls -la public/images/logo.png
```
Attendu : le fichier existe.

**Step 4 : Commit**

```bash
git add public/images/logo.png
git commit -m "feat: add logo asset to public/images"
```

---

### Task 2 : Mettre à jour la navbar (app.blade.php)

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/NavbarTest.php`

**Context — état actuel du fichier :**

`resources/views/layouts/app.blade.php` lignes clés :
- Ligne 7 : `<title>{{ $title ?? 'SVS Comptabilité' }}</title>`
- Ligne 16 : `<a class="navbar-brand" href="{{ route('dashboard') }}">SVS Comptabilité</a>`
- Lignes 23-34 : tableau `$navItems` avec "Tableau de bord" en première entrée

**Step 1 : Écrire le test qui échoue**

Créer `tests/Feature/NavbarTest.php` :

```php
<?php

use App\Models\User;

it('navbar brand shows new app name', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSee('Soigner•Vivre•Sourire');
    $response->assertSee('Comptabilité');
    $response->assertSee('images/logo.png', false);
});

it('navbar does not show tableau de bord nav item', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    // Le titre de la page peut contenir "Tableau de bord" mais pas le lien nav
    $response->assertDontSee('<a class="nav-link', false);
    $response->assertSee('Dépenses');
});
```

> Note : le second test vérifie indirectement via la présence des autres items. Un test plus précis suit à l'étape d'implémentation.

**Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
./vendor/bin/sail test --filter="NavbarTest" 2>&1
```
Attendu : FAIL — "Soigner•Vivre•Sourire" non trouvé.

**Step 3 : Mettre à jour app.blade.php**

Remplacer la ligne 7 :
```blade
<title>{{ $title ?? 'SVS Comptabilité' }}</title>
```
par :
```blade
<title>{{ $title ?? 'Soigner•Vivre•Sourire Comptabilité' }}</title>
```

Remplacer la ligne 16 :
```blade
<a class="navbar-brand" href="{{ route('dashboard') }}">SVS Comptabilité</a>
```
par :
```blade
<a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('dashboard') }}">
    <img src="{{ asset('images/logo.png') }}" alt="Soigner•Vivre•Sourire" height="45">
    <span class="d-inline-block lh-sm">
        <span class="d-block">Soigner•Vivre•Sourire</span>
        <span class="d-block small opacity-75">Comptabilité</span>
    </span>
</a>
```

Remplacer le tableau `$navItems` (supprimer l'entrée `dashboard`) :
```php
$navItems = [
    ['route' => 'depenses.index',      'icon' => 'arrow-down-circle',      'label' => 'Dépenses'],
    ['route' => 'recettes.index',      'icon' => 'arrow-up-circle',        'label' => 'Recettes'],
    ['route' => 'virements.index',     'icon' => 'arrow-left-right',       'label' => 'Virements'],
    ['route' => 'budget.index',        'icon' => 'piggy-bank',             'label' => 'Budget'],
    ['route' => 'rapprochement.index', 'icon' => 'bank',                   'label' => 'Rapprochement'],
    ['route' => 'membres.index',       'icon' => 'people',                 'label' => 'Membres'],
    ['route' => 'dons.index',          'icon' => 'heart',                  'label' => 'Dons'],
    ['route' => 'rapports.index',      'icon' => 'file-earmark-bar-graph', 'label' => 'Rapports'],
    ['route' => 'parametres.index',    'icon' => 'gear',                   'label' => 'Paramètres'],
];
```

**Step 4 : Réécrire le test avec assertion précise sur l'absence du lien "Tableau de bord"**

Mettre à jour `tests/Feature/NavbarTest.php` :

```php
<?php

use App\Models\User;

it('navbar brand shows new app name and logo', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSee('Soigner•Vivre•Sourire');
    $response->assertSee('images/logo.png', false);
});

it('navbar does not contain tableau de bord nav item link', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    // Vérifie que le lien vers dashboard n'apparaît PAS dans les nav-items
    // (il reste dans le brand, mais pas dans la liste <ul class="navbar-nav">)
    $response->assertSee('Dépenses');
    $response->assertSee('Recettes');
    $response->assertDontSee('>Tableau de bord<', false);
});

it('page title is updated', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSee('Soigner•Vivre•Sourire Comptabilité', false);
});
```

**Step 5 : Lancer les tests**

```bash
./vendor/bin/sail test --filter="NavbarTest" 2>&1
```
Attendu : 3 tests PASS.

**Step 6 : Commit**

```bash
git add resources/views/layouts/app.blade.php tests/Feature/NavbarTest.php
git commit -m "feat: update navbar brand to logo + Soigner•Vivre•Sourire, remove Tableau de bord nav item"
```

---

### Task 3 : Mettre à jour la page de connexion (guest.blade.php)

**Files:**
- Modify: `resources/views/layouts/guest.blade.php`
- Test: `tests/Feature/NavbarTest.php` (ajout de tests)

**Context — état actuel :**

`resources/views/layouts/guest.blade.php` :
- Ligne 7 : `<title>SVS Comptabilité - Connexion</title>`
- Ligne 16 : `<h2><i class="bi bi-journal-bookmark-fill"></i> SVS Comptabilité</h2>`

**Step 1 : Écrire le test qui échoue**

Ajouter dans `tests/Feature/NavbarTest.php` :

```php
it('login page shows logo and new app name', function () {
    $response = $this->get('/login');

    $response->assertSee('Soigner•Vivre•Sourire');
    $response->assertSee('images/logo.png', false);
});

it('login page title is updated', function () {
    $response = $this->get('/login');

    $response->assertSee('Soigner•Vivre•Sourire Comptabilité - Connexion', false);
});
```

**Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
./vendor/bin/sail test --filter="NavbarTest" 2>&1
```
Attendu : les 2 nouveaux tests FAIL.

**Step 3 : Mettre à jour guest.blade.php**

Remplacer la ligne 7 :
```blade
<title>SVS Comptabilité - Connexion</title>
```
par :
```blade
<title>Soigner•Vivre•Sourire Comptabilité - Connexion</title>
```

Remplacer le bloc `<div class="text-center mb-4">` (ligne 15-17) :
```blade
<div class="text-center mb-4">
    <h2><i class="bi bi-journal-bookmark-fill"></i> SVS Comptabilité</h2>
</div>
```
par :
```blade
<div class="text-center mb-4">
    <img src="{{ asset('images/logo.png') }}" alt="Soigner•Vivre•Sourire" height="100" class="mb-3">
    <h2 class="mb-0">Soigner•Vivre•Sourire</h2>
    <p class="text-muted mb-0">Comptabilité</p>
</div>
```

**Step 4 : Lancer tous les tests**

```bash
./vendor/bin/sail test 2>&1
```
Attendu : tous les tests PASS (aucune régression).

**Step 5 : Commit**

```bash
git add resources/views/layouts/guest.blade.php tests/Feature/NavbarTest.php
git commit -m "feat: update login page with logo and Soigner•Vivre•Sourire branding"
```
