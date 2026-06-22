# Compte de résultat — toggles colonnes N‑1 / budget — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter deux toggles (« Afficher N‑1 », « Afficher budget & écart ») au rapport Compte de résultat, qui masquent les colonnes correspondantes à l'écran **et** dans les exports XLSX et PDF.

**Architecture:** Pattern identique à `RapportCompteResultatOperations` — propriétés `#[Url]` sur le composant, propagées en query params via `exportUrl()`, lues par `RapportExportController` avec défaut `true`. Écran + PDF : masquage conditionnel `@if` dans le blade. XLSX : feuille construite normalement puis colonnes inutiles retirées via `removeColumn`.

**Tech Stack:** Laravel 11, Livewire 4, Pest, PhpSpreadsheet, dompdf.

**Spec:** `docs/specs/2026-06-22-compte-resultat-toggles-colonnes-design.md`

---

## File Structure

- Modify `app/Livewire/RapportCompteResultat.php` — 2 props `#[Url]`, `exportUrl()`, passage à la vue.
- Modify `resources/views/livewire/rapport-compte-resultat.blade.php` — 2 switches + `@if` colonnes.
- Modify `app/Http/Controllers/RapportExportController.php` — lecture params + XLSX `removeColumn` + PDF data flags.
- Modify `resources/views/pdf/rapport-compte-resultat.blade.php` — `@if` colonnes (miroir écran).
- Test `tests/Livewire/RapportCompteResultatTest.php` — toggles écran + exportUrl.
- Create `tests/Feature/Rapports/CompteResultatExportTogglesTest.php` — colonnes XLSX + smoke PDF.

---

## Task 1 : État du composant + propagation export

**Files:**
- Modify: `app/Livewire/RapportCompteResultat.php`
- Test: `tests/Livewire/RapportCompteResultatTest.php`

- [ ] **Step 1 : Test — défauts ON + exportUrl porte les params**

Ajouter dans `tests/Livewire/RapportCompteResultatTest.php` :

```php
it('a les deux toggles ON par défaut', function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertSet('compareN1', true)
        ->assertSet('compareBudget', true);
});

it('propage l\'état des toggles dans exportUrl', function () {
    $url = Livewire::test(RapportCompteResultat::class)
        ->set('compareN1', false)
        ->set('compareBudget', false)
        ->instance()
        ->exportUrl('xlsx');

    expect($url)->toContain('n1=0')->toContain('budget=0');
});
```

- [ ] **Step 2 : Lancer — échoue (props absentes)**

Run: `./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatTest.php --filter="toggles ON|propage"`
Expected: FAIL (`Property [compareN1] not found` / `exportUrl` sans les params).

- [ ] **Step 3 : Implémenter les props + exportUrl + passage vue**

Dans `app/Livewire/RapportCompteResultat.php`, ajouter l'import et les props en tête de classe :

```php
use Livewire\Attributes\Url;
```

```php
    #[Url(as: 'n1')]
    public bool $compareN1 = true;

    #[Url(as: 'budget')]
    public bool $compareBudget = true;
```

Modifier `exportUrl()` pour ajouter les params au tableau `route(...)` :

```php
        return route('rapports.export', [
            'rapport' => 'compte-resultat',
            'format' => $format,
            'exercice' => $exercice,
            'n1' => $this->compareN1 ? '1' : '0',
            'budget' => $this->compareBudget ? '1' : '0',
        ]);
```

Dans `render()`, ajouter au tableau passé à la vue :

```php
            'compareN1' => $this->compareN1,
            'compareBudget' => $this->compareBudget,
```

- [ ] **Step 4 : Lancer — passe**

Run: `./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatTest.php --filter="toggles ON|propage"`
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
git add app/Livewire/RapportCompteResultat.php tests/Livewire/RapportCompteResultatTest.php
git commit -m "feat(rapports): toggles compareN1/compareBudget sur le compte de résultat (état + export URL)"
```

---

## Task 2 : Écran — masquer la colonne N‑1

**Files:**
- Modify: `resources/views/livewire/rapport-compte-resultat.blade.php`
- Test: `tests/Livewire/RapportCompteResultatTest.php`

- [ ] **Step 1 : Test — N‑1 masquée quand compareN1=false**

```php
it('masque la colonne N-1 quand compareN1 est false', function () {
    $cat = Categorie::factory()->depense()->create(['association_id' => $this->association->id]);
    $sc = SousCategorie::factory()->create(['association_id' => $this->association->id, 'categorie_id' => $cat->id, 'nom' => 'Frais']);
    // Une dépense datée dans l'exercice N-1 (2024-2025) pour produire un montant_n1 distinct.
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2024-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 777.00]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('777,00')              // visible par défaut (colonne N-1 affichée)
        ->set('compareN1', false)
        ->assertDontSee('777,00');         // masquée
});
```

- [ ] **Step 2 : Lancer — échoue (colonne toujours affichée)**

Run: `./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatTest.php --filter="masque la colonne N-1"`
Expected: FAIL (`777,00` encore visible après set).

- [ ] **Step 3 : Envelopper les cellules N‑1 dans `@if($compareN1)`**

Dans `resources/views/livewire/rapport-compte-resultat.blade.php`, entourer **chaque** `<th>`/`<td>` de la colonne N‑1 (classe `cr-n1`, valeurs `montant_n1`) d'un `@if($compareN1) … @endif` :
- l'en‑tête de colonne N‑1 du `<thead>` ;
- ligne catégorie : `<td class="text-end cr-n1">{!! $fmt($cat['montant_n1']) !!}</td>` (≈ L145) ;
- ligne sous‑catégorie : `<td class="text-end cr-n1">{!! $fmt($sc['montant_n1']) !!}</td>` (≈ L155) ;
- toutes les lignes de totaux et le bloc provisions / extournes / résultat qui affichent une valeur `*_n1` (`resultatCourantN1`, `resultatBrutN1`, `resultatNetN1`, `totalProvisionsN1`, `totalExtournesN1`).

Exemple de transformation (ligne sous‑catégorie) :

```blade
@if($compareN1)
    <td class="text-end cr-n1">{!! $fmt($sc['montant_n1']) !!}</td>
@endif
```

- [ ] **Step 4 : Lancer — passe**

Run: `./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatTest.php --filter="masque la colonne N-1"`
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
git add resources/views/livewire/rapport-compte-resultat.blade.php tests/Livewire/RapportCompteResultatTest.php
git commit -m "feat(rapports): masque la colonne N-1 du compte de résultat via compareN1"
```

---

## Task 3 : Écran — masquer le groupe Budget / Écart / Barre

**Files:**
- Modify: `resources/views/livewire/rapport-compte-resultat.blade.php`
- Test: `tests/Livewire/RapportCompteResultatTest.php`

- [ ] **Step 1 : Test — groupe budget masqué quand compareBudget=false**

```php
it('masque budget/écart/barre quand compareBudget est false', function () {
    $cat = Categorie::factory()->depense()->create(['association_id' => $this->association->id]);
    $sc = SousCategorie::factory()->create(['association_id' => $this->association->id, 'categorie_id' => $cat->id, 'nom' => 'Salle']);
    BudgetLine::factory()->create(['association_id' => $this->association->id, 'sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 800.00]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSeeHtml('budget-bar-fill')   // barre visible par défaut
        ->set('compareBudget', false)
        ->assertDontSeeHtml('budget-bar-fill')
        ->assertDontSee('80 %');
});
```

- [ ] **Step 2 : Lancer — échoue**

Run: `./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatTest.php --filter="masque budget"`
Expected: FAIL (`budget-bar-fill` encore présent).

- [ ] **Step 3 : Envelopper les cellules Budget/Écart/Barre dans `@if($compareBudget)`**

Dans `resources/views/livewire/rapport-compte-resultat.blade.php`, entourer d'un `@if($compareBudget) … @endif` **chaque** `<th>`/`<td>` des 3 colonnes Budget, Écart, Barre :
- en‑têtes `Budget`, `Écart`, (barre) du `<thead>` ;
- ligne catégorie L147‑149 (`$fmt($cat['budget'])`, `$renderEcart(...)`, `$renderBar(...)`) ;
- ligne sous‑catégorie L157‑159 (`$fmt($sc['budget'])`, `$renderEcart(...)`, `$renderBar(...)`) ;
- lignes de totaux correspondantes.

Exemple (ligne sous‑catégorie) :

```blade
@if($compareBudget)
    <td class="text-end">{!! $fmt($sc['budget']) !!}</td>
    <td class="text-end">{!! $renderEcart($sc['montant_n'], $sc['budget'], $section['isCharge']) !!}</td>
    <td class="text-center">{!! $renderBar($sc['montant_n'], $sc['budget'], $section['isCharge']) !!}</td>
@endif
```

- [ ] **Step 4 : Lancer — passe**

Run: `./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatTest.php --filter="masque budget"`
Expected: PASS.

- [ ] **Step 5 : Ajouter les 2 switches dans l'en‑tête + test de présence**

Ajouter dans le test :

```php
it('affiche les deux switches de comparaison', function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertSeeHtml('wire:model.live="compareN1"')
        ->assertSeeHtml('wire:model.live="compareBudget"');
});
```

Puis, dans le blade, à côté du bouton Exporter (en‑tête, ≈ L20‑33) :

```blade
<div class="form-check form-switch mb-0">
    <input type="checkbox" wire:model.live="compareN1" class="form-check-input" id="toggleN1">
    <label class="form-check-label small" for="toggleN1">Afficher N&#8209;1</label>
</div>
<div class="form-check form-switch mb-0">
    <input type="checkbox" wire:model.live="compareBudget" class="form-check-input" id="toggleBudget">
    <label class="form-check-label small" for="toggleBudget">Afficher budget &amp; écart</label>
</div>
```

- [ ] **Step 6 : Lancer la classe de test complète**

Run: `./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatTest.php`
Expected: PASS (tous).

- [ ] **Step 7 : Commit**

```bash
git add resources/views/livewire/rapport-compte-resultat.blade.php tests/Livewire/RapportCompteResultatTest.php
git commit -m "feat(rapports): toggle compareBudget + switches UI sur le compte de résultat"
```

---

## Task 4 : Export XLSX — colonnes conditionnelles

**Files:**
- Modify: `app/Http/Controllers/RapportExportController.php`
- Test: `tests/Feature/Rapports/CompteResultatExportTogglesTest.php` (créer)

- [ ] **Step 1 : Test — l'en‑tête XLSX omet les colonnes masquées**

Créer `tests/Feature/Rapports/CompteResultatExportTogglesTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

function headerCellsXlsx(\Illuminate\Testing\TestResponse $response): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'cr').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    $cells = [];
    foreach (range('A', 'G') as $col) {
        $cells[] = (string) $sheet->getCell($col.'1')->getValue();
    }
    @unlink($tmp);

    return array_filter($cells, fn ($v) => $v !== '');
}

it('XLSX : sans params, toutes les colonnes sont présentes', function () {
    $response = $this->get(route('rapports.export', ['rapport' => 'compte-resultat', 'format' => 'xlsx', 'exercice' => 2025]));
    $response->assertOk();
    expect(headerCellsXlsx($response))->toContain('Budget')->toContain('Écart');
});

it('XLSX : budget=0 retire Budget et Écart', function () {
    $response = $this->get(route('rapports.export', ['rapport' => 'compte-resultat', 'format' => 'xlsx', 'exercice' => 2025, 'budget' => '0']));
    $response->assertOk();
    $cells = headerCellsXlsx($response);
    expect($cells)->not->toContain('Budget');
    expect($cells)->not->toContain('Écart');
});

it('XLSX : n1=0 retire la colonne N-1', function () {
    $labelN1 = '2024-2025';
    $response = $this->get(route('rapports.export', ['rapport' => 'compte-resultat', 'format' => 'xlsx', 'exercice' => 2025, 'n1' => '0']));
    $response->assertOk();
    expect(headerCellsXlsx($response))->not->toContain($labelN1);
});
```

- [ ] **Step 2 : Lancer — échoue (colonnes toujours là)**

Run: `./vendor/bin/sail artisan test tests/Feature/Rapports/CompteResultatExportTogglesTest.php`
Expected: FAIL (les colonnes Budget/Écart/N‑1 sont encore présentes).

- [ ] **Step 3 : Lire les params + supprimer les colonnes**

Dans `app/Http/Controllers/RapportExportController.php`, modifier l'appel dans `exportXlsx()` (≈ L101) pour passer les deux booléens :

```php
            'compte-resultat' => $this->xlsxCompteResultat(
                $rapportService,
                $exercice,
                $label,
                $request->boolean('n1', true),
                $request->boolean('budget', true),
            ),
```

Changer la signature de `xlsxCompteResultat` (≈ L120) :

```php
    private function xlsxCompteResultat(
        RapportService $rapportService,
        int $exercice,
        string $label,
        bool $compareN1 = true,
        bool $compareBudget = true,
    ): Spreadsheet {
```

Tout en bas de la méthode, **juste avant le `return $spreadsheet;`**, retirer les colonnes inutiles (toujours la plus à droite en premier pour éviter tout décalage) :

```php
        // Colonnes : A Type | B Catégorie | C Sous-catégorie | D N-1 | E N | F Budget | G Écart
        if (! $compareBudget) {
            $sheet->removeColumn('F', 2); // Budget + Écart
        }
        if (! $compareN1) {
            $sheet->removeColumn('D', 1); // N-1
        }
```

- [ ] **Step 4 : Lancer — passe**

Run: `./vendor/bin/sail artisan test tests/Feature/Rapports/CompteResultatExportTogglesTest.php`
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
git add app/Http/Controllers/RapportExportController.php tests/Feature/Rapports/CompteResultatExportTogglesTest.php
git commit -m "feat(rapports): export XLSX du compte de résultat respecte les toggles N-1/budget"
```

---

## Task 5 : Export PDF — colonnes conditionnelles

**Files:**
- Modify: `app/Http/Controllers/RapportExportController.php`
- Modify: `resources/views/pdf/rapport-compte-resultat.blade.php`
- Test: `tests/Feature/Rapports/CompteResultatExportTogglesTest.php`

- [ ] **Step 1 : Test — la route PDF répond avec les params, et la vue PDF omet les colonnes**

Ajouter au fichier de test :

```php
it('PDF : la route répond 200 avec les toggles', function () {
    $this->get(route('rapports.export', ['rapport' => 'compte-resultat', 'format' => 'pdf', 'exercice' => 2025, 'n1' => '0', 'budget' => '0']))
        ->assertOk();
});

it('PDF : la vue omet N-1 et budget quand les flags sont false', function () {
    $html = view('pdf.rapport-compte-resultat', [
        'charges' => [], 'produits' => [],
        'labelN' => '2025-2026', 'labelN1' => '2024-2025',
        'totalChargesN' => 0.0, 'totalProduitsN' => 0.0, 'totalChargesN1' => 0.0, 'totalProduitsN1' => 0.0,
        'provisions' => collect(), 'provisionsN1' => collect(), 'extournes' => collect(), 'extournesN1' => collect(),
        'totalProvisions' => 0.0, 'totalProvisionsN1' => 0.0, 'totalExtournes' => 0.0, 'totalExtournesN1' => 0.0,
        'resultatCourant' => 0.0, 'resultatCourantN1' => 0.0, 'resultatBrut' => 0.0, 'resultatBrutN1' => 0.0,
        'resultatNet' => 0.0, 'resultatNetN1' => 0.0,
        'title' => 'Compte de résultat', 'subtitle' => 'Exercice 2025-2026',
        'association' => null, 'headerLogoBase64' => null, 'headerLogoMime' => null,
        'appLogoBase64' => null, 'footerLogoBase64' => null, 'footerLogoMime' => null,
        'compareN1' => false, 'compareBudget' => false,
    ])->render();

    expect($html)->not->toContain('2024-2025')   // en-tête N-1
        ->not->toContain('Budget');
});
```

> Note : si la vue PDF requiert d'autres variables, les ajouter au tableau ci‑dessus en se calant sur le `return` de `pdfCompteResultatData()` (RapportExportController L1158+). Les valeurs neutres (0.0 / collect()) suffisent pour le rendu.

- [ ] **Step 2 : Lancer — échoue**

Run: `./vendor/bin/sail artisan test tests/Feature/Rapports/CompteResultatExportTogglesTest.php --filter="PDF"`
Expected: FAIL (`compareN1` non défini dans la vue / colonnes encore présentes).

- [ ] **Step 3 : Threader les flags vers la vue PDF**

Dans `app/Http/Controllers/RapportExportController.php`, `exportPdf()` (≈ L1100), passer `$request` :

```php
            'compte-resultat' => $this->pdfCompteResultatData($rapportService, $exercice, $label, $request),
```

Signature de `pdfCompteResultatData` (≈ L1133) :

```php
    private function pdfCompteResultatData(RapportService $rapportService, int $exercice, string $label, Request $request): array
    {
```

Dans le tableau `return` de `pdfCompteResultatData` (≈ L1158), ajouter :

```php
            'compareN1' => $request->boolean('n1', true),
            'compareBudget' => $request->boolean('budget', true),
```

- [ ] **Step 4 : Masquage conditionnel dans la vue PDF**

Dans `resources/views/pdf/rapport-compte-resultat.blade.php` (même structure de colonnes que l'écran : N‑1, N, Budget, Écart), entourer :
- le `<th>`/`<td>` de la colonne **N‑1** d'un `@if($compareN1) … @endif` ;
- les `<th>`/`<td>` des colonnes **Budget** et **Écart** d'un `@if($compareBudget) … @endif` ;
- ajuster tout `colspan` d'en‑tête en conséquence (ex. un `colspan` total qui couvrait N colonnes).

Reproduire exactement le découpage fait à l'écran aux Tasks 2 et 3.

- [ ] **Step 5 : Lancer — passe**

Run: `./vendor/bin/sail artisan test tests/Feature/Rapports/CompteResultatExportTogglesTest.php`
Expected: PASS (tous, XLSX + PDF).

- [ ] **Step 6 : Commit**

```bash
git add app/Http/Controllers/RapportExportController.php resources/views/pdf/rapport-compte-resultat.blade.php tests/Feature/Rapports/CompteResultatExportTogglesTest.php
git commit -m "feat(rapports): export PDF du compte de résultat respecte les toggles N-1/budget"
```

---

## Task 6 : Vérification finale

- [ ] **Step 1 : Suite ciblée verte**

Run: `./vendor/bin/sail artisan test --filter="CompteResultat|RapportCompteResultat|CompteResultatExportToggles"`
Expected: PASS, 0 échec.

- [ ] **Step 2 : Pint**

Run: `./vendor/bin/sail pint app/Livewire/RapportCompteResultat.php app/Http/Controllers/RapportExportController.php`
Expected: clean.

- [ ] **Step 3 : Recette manuelle navigateur (avant tout push)**

Vérifier à l'écran : basculer chaque switch masque/affiche les bonnes colonnes ; télécharger XLSX et PDF avec chaque combinaison (les colonnes suivent). Cf. convention « tester en local avant de pousser ».
