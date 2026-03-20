# Transaction Universelle — Plan d'implémentation (Lot 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implémenter le composant Livewire `TransactionUniverselle` accessible en `/transactions/all`, qui agrège toutes les entités financières (dépenses, recettes, dons, cotisations, virements) via SQL UNION, avec filtres de type (toggles), filtres QBE par colonne (popovers Alpine.js), solde courant conditionnel, expansion de lignes et bouton "Nouveau".

**Architecture:** Un nouveau `TransactionUniverselleService` encapsule le UNION SQL (généralisation de `TransactionCompteService`) paramétré par props verrouillées (`compteId`, `tiersId`, `types`, `exercice`) et filtres libres. Le composant Livewire `TransactionUniverselle` gère l'état des filtres et délègue au service. La vue blade utilise Alpine.js pour les popovers QBE et l'expansion inline. Les formulaires modaux (DonForm, CotisationForm, VirementInterneForm, TransactionForm) sont déjà dans le layout global (Lot 1).

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 (CDN), Alpine.js (bundled with Livewire), MySQL via Docker Sail, Pest PHP

**Spec :** `docs/superpowers/specs/2026-03-19-transaction-universelle-design.md`

---

## Fichiers créés / modifiés

| Fichier | Action | Rôle |
|---|---|---|
| `app/Services/TransactionUniverselleService.php` | Créer | UNION SQL, pagination, calcul solde courant |
| `app/Livewire/TransactionUniverselle.php` | Créer | Composant Livewire — props verrouillées, filtres libres, expansion |
| `resources/views/livewire/transaction-universelle.blade.php` | Créer | Vue — table, toggles type, QBE popovers, expansion, Nouveau |
| `resources/views/transactions/all.blade.php` | Créer | Page wrapper qui embed le composant |
| `routes/web.php` | Modifier | Ajouter route `/transactions/all` |
| `resources/views/layouts/app.blade.php` | Modifier | Ajouter lien nav "Toutes (v2)" dans le dropdown Transactions |
| `tests/Feature/TransactionUniverselleServiceTest.php` | Créer | Tests TDD du service |
| `tests/Feature/Livewire/TransactionUniverselleTest.php` | Créer | Tests Livewire du composant |

---

## Contexte codebase pour les subagents

### Pattern UNION existant

Le service existant `TransactionCompteService` (`app/Services/TransactionCompteService.php`) construit un UNION SQL avec 5 branches : `transactions` (type=depense + type=recette), `dons`, `cotisations`, `virements_internes` (sortant + entrant). Les colonnes UNION : `id, source_type, date, type_label, tiers, tiers_type, libelle, reference, montant, mode_paiement, pointe, numero_piece`. Le `TransactionUniverselleService` étend ce modèle en ajoutant : `tiers_id, categorie_label, nb_lignes, compte_id, compte_nom`.

### Soft deletes
Tous les modèles financiers ont `deleted_at` : `Transaction`, `Don`, `Cotisation`, `VirementInterne`.

### isLockedByRapprochement
Toutes ces entités ont une méthode `isLockedByRapprochement(): bool`.

### Formulaires modaux disponibles (Lot 1)
- `open-transaction-form` : `{id: null|int, type?: 'depense'|'recette'}` → TransactionForm
- `open-don-form` : `{id: null|int}` → DonForm
- `open-cotisation-form` : `{id: null|int}` → CotisationForm
- `open-virement-form` : `{id: null|int}` → VirementInterneForm

Ces composants sont déjà dans `resources/views/layouts/app.blade.php`.

### ExerciceService
```php
$exerciceService = app(ExerciceService::class);
$exercice = $exerciceService->current();  // int, ex: 2024
$range = $exerciceService->dateRange($exercice);
// $range['start'] => CarbonInterface (ex: 2024-09-01)
// $range['end']   => CarbonInterface (ex: 2025-08-31)
```

### Pattern pagination+perPage
```php
use App\Livewire\Concerns\WithPerPage;
use Livewire\WithPagination;
// $this->effectivePerPage() retourne 10|25|50
// $this->getPage() retourne la page courante
```

### En-têtes de tableaux
```html
<thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
```

### Badges type (par source_type)
| source_type | Badge | Couleur Bootstrap |
|---|---|---|
| depense | DÉP | `danger` |
| recette | REC | `success` |
| don | DON | `primary` |
| cotisation | COT | `secondary` + couleur custom `#6f42c1` |
| virement_sortant / virement_entrant | VIR | `warning` text-dark |

---

## Task 1 : TransactionUniverselleService

**Files:**
- Create: `app/Services/TransactionUniverselleService.php`
- Test: `tests/Feature/TransactionUniverselleServiceTest.php`

### Colonnes UNION (ordre strict, 16 colonnes)

```
id             INT     — PK de l'entité source
source_type    VARCHAR — 'depense'|'recette'|'don'|'cotisation'|'virement_sortant'|'virement_entrant'
date           DATE
numero_piece   VARCHAR (nullable)
reference      VARCHAR (nullable)
tiers          VARCHAR — nom du tiers ou "→ CompteX" / "← CompteX" pour virements (nullable)
tiers_type     VARCHAR — 'personne'|'entreprise'|null
tiers_id       INT     (nullable)
libelle        VARCHAR (nullable)
categorie_label VARCHAR (nullable) — 1ère ligne pour transactions multi-lignes
nb_lignes      INT     — 1 pour dons/cotisations/virements, COUNT(*) pour transactions
compte_id      INT     (nullable) — compte_source pour virement_sortant, compte_destination pour entrant
compte_nom     VARCHAR (nullable)
mode_paiement  VARCHAR (nullable)
montant        DECIMAL — signé : négatif pour depenses et virement_sortant
pointe         BOOLEAN
```

### SQL de chaque branche

**Branche `depense` :**
```sql
SELECT
  tx.id,
  'depense' as source_type,
  tx.date,
  tx.numero_piece,
  tx.reference,
  TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) as tiers,
  t.type as tiers_type,
  tx.tiers_id,
  tx.libelle,
  (SELECT sc.nom FROM transaction_lignes tl
   JOIN sous_categories sc ON sc.id = tl.sous_categorie_id
   WHERE tl.transaction_id = tx.id ORDER BY tl.id LIMIT 1) as categorie_label,
  (SELECT COUNT(*) FROM transaction_lignes WHERE transaction_id = tx.id) as nb_lignes,
  tx.compte_id,
  cb.nom as compte_nom,
  tx.mode_paiement,
  -(tx.montant_total) as montant,
  tx.pointe
FROM transactions tx
LEFT JOIN tiers t ON t.id = tx.tiers_id
LEFT JOIN comptes_bancaires cb ON cb.id = tx.compte_id
WHERE tx.type = 'depense' AND tx.deleted_at IS NULL
```

**Branche `recette` :**
```sql
SELECT
  tx.id,
  'recette' as source_type,
  tx.date,
  tx.numero_piece,
  tx.reference,
  TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) as tiers,
  t.type as tiers_type,
  tx.tiers_id,
  tx.libelle,
  (SELECT sc.nom FROM transaction_lignes tl
   JOIN sous_categories sc ON sc.id = tl.sous_categorie_id
   WHERE tl.transaction_id = tx.id ORDER BY tl.id LIMIT 1) as categorie_label,
  (SELECT COUNT(*) FROM transaction_lignes WHERE transaction_id = tx.id) as nb_lignes,
  tx.compte_id,
  cb.nom as compte_nom,
  tx.mode_paiement,
  tx.montant_total as montant,
  tx.pointe
FROM transactions tx
LEFT JOIN tiers t ON t.id = tx.tiers_id
LEFT JOIN comptes_bancaires cb ON cb.id = tx.compte_id
WHERE tx.type = 'recette' AND tx.deleted_at IS NULL
```

**Branche `don` :**
```sql
SELECT
  dn.id,
  'don' as source_type,
  dn.date,
  dn.numero_piece,
  NULL as reference,
  TRIM(CONCAT(COALESCE(do.prenom,''), ' ', COALESCE(do.nom,''))) as tiers,
  `do`.type as tiers_type,
  dn.tiers_id,
  dn.objet as libelle,
  sc.nom as categorie_label,
  1 as nb_lignes,
  dn.compte_id,
  cb.nom as compte_nom,
  dn.mode_paiement,
  dn.montant,
  dn.pointe
FROM dons dn
LEFT JOIN tiers `do` ON `do`.id = dn.tiers_id
LEFT JOIN sous_categories sc ON sc.id = dn.sous_categorie_id
LEFT JOIN comptes_bancaires cb ON cb.id = dn.compte_id
WHERE dn.deleted_at IS NULL
```

**Branche `cotisation` :**
```sql
SELECT
  c.id,
  'cotisation' as source_type,
  c.date_paiement as date,
  c.numero_piece,
  NULL as reference,
  TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) as tiers,
  t.type as tiers_type,
  c.tiers_id,
  CONCAT('Cotisation ', c.exercice) as libelle,
  sc.nom as categorie_label,
  1 as nb_lignes,
  c.compte_id,
  cb.nom as compte_nom,
  c.mode_paiement,
  c.montant,
  c.pointe
FROM cotisations c
LEFT JOIN tiers t ON t.id = c.tiers_id
LEFT JOIN sous_categories sc ON sc.id = c.sous_categorie_id
LEFT JOIN comptes_bancaires cb ON cb.id = c.compte_id
WHERE c.deleted_at IS NULL
```

**Branche `virement_sortant` :**
```sql
SELECT
  vi.id,
  'virement_sortant' as source_type,
  vi.date,
  vi.numero_piece,
  vi.reference,
  CONCAT('→ ', cb_dest.nom) as tiers,
  NULL as tiers_type,
  NULL as tiers_id,
  CONCAT('Virement vers ', cb_dest.nom) as libelle,
  NULL as categorie_label,
  1 as nb_lignes,
  vi.compte_source_id as compte_id,
  cb_src.nom as compte_nom,
  NULL as mode_paiement,
  -(vi.montant) as montant,
  (vi.rapprochement_source_id IS NOT NULL) as pointe
FROM virements_internes vi
JOIN comptes_bancaires cb_dest ON cb_dest.id = vi.compte_destination_id
JOIN comptes_bancaires cb_src ON cb_src.id = vi.compte_source_id
WHERE vi.deleted_at IS NULL
```

**Branche `virement_entrant` :**
```sql
SELECT
  vi.id,
  'virement_entrant' as source_type,
  vi.date,
  vi.numero_piece,
  vi.reference,
  CONCAT('← ', cb_src.nom) as tiers,
  NULL as tiers_type,
  NULL as tiers_id,
  CONCAT('Virement depuis ', cb_src.nom) as libelle,
  NULL as categorie_label,
  1 as nb_lignes,
  vi.compte_destination_id as compte_id,
  cb_dest.nom as compte_nom,
  NULL as mode_paiement,
  vi.montant,
  (vi.rapprochement_destination_id IS NOT NULL) as pointe
FROM virements_internes vi
JOIN comptes_bancaires cb_src ON cb_src.id = vi.compte_source_id
JOIN comptes_bancaires cb_dest ON cb_dest.id = vi.compte_destination_id
WHERE vi.deleted_at IS NULL
```

### Signature du service

```php
<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TransactionUniverselleService
{
    /**
     * @param array<string>|null $types  null = tous; ['depense','recette','don','cotisation','virement']
     * @return array{paginator: LengthAwarePaginator, soldeAvantPage: float|null}
     */
    public function paginate(
        ?int    $compteId,
        ?int    $tiersId,
        ?array  $types,
        ?string $dateDebut,
        ?string $dateFin,
        ?string $searchTiers,
        ?string $searchLibelle,
        ?string $searchReference,
        ?string $searchNumeroPiece,
        ?string $modePaiement,
        ?bool   $pointe,
        bool    $computeSolde  = false,
        string  $sortColumn    = 'date',
        string  $sortDirection = 'desc',
        int     $perPage       = 25,
        int     $page          = 1,
    ): array
```

### Logique interne

1. Construire le UNION via `buildUnion(...)` — inclure ou exclure chaque branche selon `$types`.
2. Appliquer tri (`orderBy $sortColumn $sortDirection`, puis `orderBy 'source_type'`, puis `orderBy 'id'`).
3. Paginer avec `->paginate($perPage, ['*'], 'page', $page)`.
4. Le service **ne calcule pas `showSolde`** (c'est la responsabilité du composant). Le service calcule `soldeAvantPage` uniquement quand `$computeSolde = true` est passé **et** que `$compteId !== null`.
5. Si `$computeSolde`, calculer `$soldeAvantPage` comme dans `TransactionCompteService` : somme des montants des lignes avant la page courante + `solde_initial` du compte.

### Construction du UNION conditionnel

```php
private function buildUnion(
    ?int $compteId, ?int $tiersId, ?array $types,
    ?string $dateDebut, ?string $dateFin,
    ?string $searchTiers, ?string $searchLibelle,
    ?string $searchReference, ?string $searchNumeroPiece,
    ?string $modePaiement, ?bool $pointe,
): Builder {
    $include = [
        'depense'  => $types === null || in_array('depense', $types),
        'recette'  => $types === null || in_array('recette', $types),
        'don'      => $types === null || in_array('don', $types),
        'cotisation' => $types === null || in_array('cotisation', $types),
        'virement' => $types === null || in_array('virement', $types),
    ];

    $queries = [];
    if ($include['depense'])    $queries[] = $this->brancheDepense(...);
    if ($include['recette'])    $queries[] = $this->brancheRecette(...);
    if ($include['don'])        $queries[] = $this->brancheDon(...);
    if ($include['cotisation']) $queries[] = $this->brancheCotisation(...);
    if ($include['virement']) {
        $queries[] = $this->brancheVirementSortant(...);
        $queries[] = $this->brancheVirementEntrant(...);
    }

    // Doit y avoir au moins 1 branche
    $base = array_shift($queries);
    foreach ($queries as $q) {
        $base->unionAll($q);
    }
    return $base;
}
```

Chaque méthode `brancheXxx(...)` accepte les mêmes paramètres et retourne un `Builder`. Signature type :
```php
private function brancheDepense(
    ?int $compteId, ?int $tiersId,
    ?string $dateDebut, ?string $dateFin,
): Builder { ... }
```

**Filtres appliqués dans chaque branche** (sur les tables sources, en SQL) :
- `$compteId` → `WHERE compte_id = $compteId` (virements : `compte_source_id`/`compte_destination_id` selon branche)
- `$tiersId` → `WHERE tiers_id = $tiersId` (virements : `->whereRaw('1 = 0')` car pas de tiers_id)
- `$dateDebut` / `$dateFin` → `WHERE date >= / <=` (cotisations : `date_paiement`)

**Filtres appliqués sur le wrapper externe** (`fromSub($union, 't')`) — car les colonnes calculées du UNION ne sont accessibles qu'à ce niveau :
- `$searchTiers` → `->where('t.tiers', 'like', "%{$searchTiers}%")`
- `$searchLibelle` → `->where('t.libelle', 'like', ...)`
- `$searchReference` → `->where('t.reference', 'like', ...)`
- `$searchNumeroPiece` → `->where('t.numero_piece', 'like', ...)`
- `$modePaiement` → `->where('t.mode_paiement', $modePaiement)`
- `$pointe` → `->where('t.pointe', $pointe)`

```php
$outer = DB::query()->fromSub($union, 't')
    ->when($searchTiers, fn($q) => $q->where('t.tiers', 'like', "%{$searchTiers}%"))
    ->when($searchLibelle, fn($q) => $q->where('t.libelle', 'like', "%{$searchLibelle}%"))
    ->when($searchReference, fn($q) => $q->where('t.reference', 'like', "%{$searchReference}%"))
    ->when($searchNumeroPiece, fn($q) => $q->where('t.numero_piece', 'like', "%{$searchNumeroPiece}%"))
    ->when($modePaiement, fn($q) => $q->where('t.mode_paiement', $modePaiement))
    ->when($pointe !== null, fn($q) => $q->where('t.pointe', $pointe))
    ->orderBy("t.{$sortColumn}", $sortDirection)
    ->orderBy('t.source_type')
    ->orderBy('t.id');
```

### Steps Task 1

- [ ] **Step 1 : Écrire les tests**

```php
<?php
declare(strict_types=1);

use App\Models\{Cotisation, Don, Transaction, VirementInterne};
use App\Services\TransactionUniverselleService;

beforeEach(function () {
    $this->svc = app(TransactionUniverselleService::class);
    $this->compte = \App\Models\CompteBancaire::factory()->create(['solde_initial' => 0]);
});

it('retourne toutes les entités par défaut', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Don::factory()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-11']);
    Cotisation::factory()->create(['compte_id' => $this->compte->id, 'date_paiement' => '2025-01-12']);
    VirementInterne::factory()->create(['compte_source_id' => $this->compte->id, 'date' => '2025-01-13']);

    $result = $this->svc->paginate(null, null, null, null, null, null, null, null, null, null, null);
    // 1 depense + 1 don + 1 cotisation + 2 virement (sortant+entrant) = 5 lignes min
    expect($result['paginator']->total())->toBeGreaterThanOrEqual(5);
});

it('filtre par compteId', function () {
    $autreCompte = \App\Models\CompteBancaire::factory()->create();
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Transaction::factory()->asDepense()->create(['compte_id' => $autreCompte->id, 'date' => '2025-01-10']);

    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null);
    // Seule la transaction du compte ciblé est retournée
    foreach ($result['paginator']->items() as $row) {
        expect($row->compte_id)->toBe($this->compte->id);
    }
});

it('filtre sur types uniquement depense', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Don::factory()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-11']);

    $result = $this->svc->paginate(null, null, ['depense'], null, null, null, null, null, null, null, null);
    foreach ($result['paginator']->items() as $row) {
        expect($row->source_type)->toBe('depense');
    }
});

it('filtre par dateDebut et dateFin', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-05']);
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-03-15']);

    $result = $this->svc->paginate(null, null, ['depense'], '2025-03-01', '2025-03-31', null, null, null, null, null, null);
    expect($result['paginator']->total())->toBe(1);
    expect($result['paginator']->items()[0]->date)->toBe('2025-03-15');
});

it('retourne soldeAvantPage non-null quand computeSolde=true et compteId fourni', function () {
    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null, true, 'date', 'asc');
    expect($result['soldeAvantPage'])->not->toBeNull();
});

it('retourne soldeAvantPage null quand computeSolde=false', function () {
    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null, false);
    expect($result['soldeAvantPage'])->toBeNull();
});
```

- [ ] **Step 2 : Lancer les tests (doivent échouer)**

```bash
./vendor/bin/sail artisan test tests/Feature/TransactionUniverselleServiceTest.php
```
Expected: FAIL — classe non trouvée.

- [ ] **Step 3 : Implémenter TransactionUniverselleService**

Créer `app/Services/TransactionUniverselleService.php` avec la structure décrite ci-dessus. Structurer les branches privées (`brancheDepense`, `brancheRecette`, `brancheDon`, `brancheCotisation`, `brancheVirementSortant`, `brancheVirementEntrant`) pour éviter la répétition des filtres.

Points importants :
- Les filtres `compteId`, `tiersId`, `dateDebut`, `dateFin`, `searchTiers` sont appliqués **dans chaque branche** (SQL sur les tables sources).
- Les filtres `searchLibelle`, `searchReference`, `searchNumeroPiece`, `modePaiement`, `pointe` sont appliqués sur le **wrapper externe** (`fromSub($union, 't')`).
- Pour le filtre `tiersId` sur les branches virement : les virements n'ont pas de `tiers_id` réel, donc si `$tiersId` est fourni, ajouter `->whereRaw('1 = 0')` pour exclure les virements.
- `showSolde` condition :
  ```php
  $showSolde = $compteId !== null
      && $types === null
      && $searchTiers === null && $searchLibelle === null
      && $searchReference === null && $searchNumeroPiece === null
      && $modePaiement === null && $pointe === null;
  ```
- Calcul `soldeAvantPage` : identique à `TransactionCompteService` (sum des montants avant offset + solde_initial du compte).

- [ ] **Step 4 : Lancer les tests (doivent passer)**

```bash
./vendor/bin/sail artisan test tests/Feature/TransactionUniverselleServiceTest.php
```
Expected: toutes PASS.

- [ ] **Step 5 : Commit**

```bash
git add app/Services/TransactionUniverselleService.php tests/Feature/TransactionUniverselleServiceTest.php
git commit -m "feat: TransactionUniverselleService — UNION multi-entités paginé"
```

---

## Task 2 : TransactionUniverselle Livewire component

**Files:**
- Create: `app/Livewire/TransactionUniverselle.php`
- Test: `tests/Feature/Livewire/TransactionUniverselleTest.php`

### Structure du composant

```php
<?php
declare(strict_types=1);

namespace App\Livewire;

use App\Models\{Cotisation, CompteBancaire, Don, Transaction, VirementInterne};
use App\Services\{CotisationService, DonService, ExerciceService,
                  TransactionService, TransactionUniverselleService, VirementInterneService};
use App\Livewire\Concerns\WithPerPage;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionUniverselle extends Component
{
    use WithPagination, WithPerPage;
    protected string $paginationTheme = 'bootstrap';

    // === Props verrouillées (injectées via mount depuis la page) ===
    public ?int   $compteId = null;   // compte fixe (vue par compte)
    public ?int   $tiersId  = null;   // tiers fixe (vue par tiers)
    /** @var array<string>|null */
    public ?array $lockedTypes = null; // types autorisés (null = tous)
    public ?int   $exercice   = null;  // exercice fixe (null = courant)

    // === Filtres libres (manipulables par l'utilisateur) ===
    /** @var array<string> */
    public array  $filterTypes        = []; // [] = "Toutes"
    public string $filterDateDebut    = '';
    public string $filterDateFin      = '';
    public string $filterTiers        = '';
    public string $filterReference    = '';
    public string $filterLibelle      = '';
    public string $filterNumeroPiece  = '';
    public string $filterModePaiement = '';
    public string $filterPointe       = ''; // '' | '1' | '0'
    public ?int   $filterCompteId     = null; // libre seulement si compteId prop est null

    // === Expansion de lignes ===
    /** @var array<string, mixed> */
    public array $expandedDetails = []; // clé: "source_type:id"

    // === Tri ===
    public string $sortColumn    = 'date';
    public string $sortDirection = 'desc';

    public function mount(
        ?int $compteId = null,
        ?int $tiersId = null,
        ?array $lockedTypes = null,
        ?int $exercice = null,
    ): void {
        $this->compteId    = $compteId;
        $this->tiersId     = $tiersId;
        $this->lockedTypes = $lockedTypes;
        $this->exercice    = $exercice;

        // Initialiser plage dates sur l'exercice courant
        $exerciceService = app(ExerciceService::class);
        $ex = $exercice ?? $exerciceService->current();
        $range = $exerciceService->dateRange($ex);
        $this->filterDateDebut = $range['start']->toDateString();
        $this->filterDateFin   = $range['end']->toDateString();
    }

    // Presets date
    public function applyDatePreset(string $preset): void
    {
        $exerciceService = app(ExerciceService::class);
        $now = now();
        match ($preset) {
            'exercice' => (function () use ($exerciceService) {
                $ex = $this->exercice ?? $exerciceService->current();
                $range = $exerciceService->dateRange($ex);
                $this->filterDateDebut = $range['start']->toDateString();
                $this->filterDateFin   = $range['end']->toDateString();
            })(),
            'mois' => (function () use ($now) {
                $this->filterDateDebut = $now->copy()->startOfMonth()->toDateString();
                $this->filterDateFin   = $now->copy()->endOfMonth()->toDateString();
            })(),
            'trimestre' => (function () use ($now) {
                $this->filterDateDebut = $now->copy()->startOfQuarter()->toDateString();
                $this->filterDateFin   = $now->copy()->endOfQuarter()->toDateString();
            })(),
            'all' => (function () {
                $this->filterDateDebut = '';
                $this->filterDateFin   = '';
            })(),
            default => null,
        };
        $this->resetPage();
    }

    // Toggle d'un type dans filterTypes (boutons au-dessus du tableau)
    public function toggleType(string $type): void
    {
        if (in_array($type, $this->filterTypes)) {
            $this->filterTypes = array_values(array_filter($this->filterTypes, fn($t) => $t !== $type));
        } else {
            $this->filterTypes[] = $type;
        }
        $this->resetPage();
    }

    // updatedX → resetPage
    public function updatedFilterTypes(): void { $this->resetPage(); }
    public function updatedFilterDateDebut(): void { $this->resetPage(); }
    public function updatedFilterDateFin(): void { $this->resetPage(); }
    public function updatedFilterTiers(): void { $this->resetPage(); }
    public function updatedFilterReference(): void { $this->resetPage(); }
    public function updatedFilterLibelle(): void { $this->resetPage(); }
    public function updatedFilterNumeroPiece(): void { $this->resetPage(); }
    public function updatedFilterModePaiement(): void { $this->resetPage(); }
    public function updatedFilterPointe(): void { $this->resetPage(); }
    public function updatedFilterCompteId(): void { $this->resetPage(); }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn    = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    // Expansion de ligne
    public function toggleDetail(string $sourceType, int $id): void
    {
        $key = "{$sourceType}:{$id}";
        if (isset($this->expandedDetails[$key])) {
            unset($this->expandedDetails[$key]);
        } else {
            $this->expandedDetails[$key] = $this->fetchDetail($sourceType, $id);
        }
    }

    private function fetchDetail(string $sourceType, int $id): array
    {
        return match ($sourceType) {
            'depense', 'recette' => $this->fetchTransactionDetail($id),
            'don'                => $this->fetchDonDetail($id),
            'cotisation'         => $this->fetchCotisationDetail($id),
            'virement_sortant', 'virement_entrant' => [],
            default              => [],
        };
    }

    private function fetchTransactionDetail(int $id): array
    {
        $tx = Transaction::with(['lignes.sousCategorie.categorie', 'operation'])->find($id);
        if (! $tx) return [];
        return [
            'lignes' => $tx->lignes->map(fn ($l) => [
                'categorie'     => $l->sousCategorie?->categorie?->nom,
                'sous_categorie'=> $l->sousCategorie?->nom,
                'montant'       => (float) $l->montant,
            ])->toArray(),
            'operation' => $tx->operation?->nom,
        ];
    }

    private function fetchDonDetail(int $id): array
    {
        $don = Don::with(['sousCategorie', 'operation'])->find($id);
        if (! $don) return [];
        return [
            'sous_categorie' => $don->sousCategorie?->nom,
            'operation'      => $don->operation?->nom,
            'seance'         => $don->seance,
            'objet'          => $don->objet,
        ];
    }

    private function fetchCotisationDetail(int $id): array
    {
        $cot = Cotisation::with('sousCategorie')->find($id);
        if (! $cot) return [];
        return [
            'sous_categorie' => $cot->sousCategorie?->nom,
            'exercice'       => $cot->exercice,
        ];
    }

    // Suppression
    public function deleteRow(string $sourceType, int $id): void
    {
        match ($sourceType) {
            'depense', 'recette'                   => $this->deleteTransaction($id),
            'don'                                  => $this->deleteDon($id),
            'cotisation'                           => $this->deleteCotisation($id),
            'virement_sortant', 'virement_entrant' => $this->deleteVirement($id),
            default                                => null,
        };
    }

    private function deleteTransaction(int $id): void
    {
        $tx = Transaction::find($id);
        if (! $tx || $tx->isLockedByRapprochement()) return;
        try { app(TransactionService::class)->delete($tx); }
        catch (\RuntimeException $e) { session()->flash('error', $e->getMessage()); }
    }

    private function deleteDon(int $id): void
    {
        $don = Don::find($id);
        if (! $don || $don->isLockedByRapprochement()) return;
        app(DonService::class)->delete($don);
    }

    private function deleteCotisation(int $id): void
    {
        $cot = Cotisation::find($id);
        if (! $cot || $cot->isLockedByRapprochement()) return;
        app(CotisationService::class)->delete($cot);
    }

    private function deleteVirement(int $id): void
    {
        $v = VirementInterne::find($id);
        if (! $v || $v->isLockedByRapprochement()) return;
        app(VirementInterneService::class)->delete($v);
    }

    // Écouter les événements des modaux pour rafraîchir la liste
    #[On('transaction-saved')]
    #[On('don-saved')]
    #[On('cotisation-saved')]
    #[On('virement-saved')]
    public function onEntitySaved(): void {}

    public function render(): View
    {
        $activeCompteId = $this->compteId ?? $this->filterCompteId;

        // Déterminer les types effectivement inclus
        $typesScope = $this->lockedTypes; // null = tous, ou subset limité
        $typesFilter = empty($this->filterTypes) ? null : $this->filterTypes;

        // Intersection des types scope et filter
        $effectiveTypes = null;
        if ($typesScope !== null && $typesFilter !== null) {
            $effectiveTypes = array_values(array_intersect($typesScope, $typesFilter));
        } elseif ($typesScope !== null) {
            $effectiveTypes = $typesScope;
        } elseif ($typesFilter !== null) {
            $effectiveTypes = $typesFilter;
        }

        // showSolde : compte unique + tous types scope + aucun filtre hors dates
        $showSolde = $activeCompteId !== null
            && empty($this->filterTypes)
            && $this->filterTiers === '' && $this->filterReference === ''
            && $this->filterLibelle === '' && $this->filterNumeroPiece === ''
            && $this->filterModePaiement === '' && $this->filterPointe === '';

        $sortDirection = ($showSolde && $this->sortColumn === 'date')
            ? 'asc'
            : $this->sortDirection;

        $result = app(TransactionUniverselleService::class)->paginate(
            compteId:          $activeCompteId,
            tiersId:           $this->tiersId,
            types:             $effectiveTypes,
            dateDebut:         $this->filterDateDebut ?: null,
            dateFin:           $this->filterDateFin ?: null,
            searchTiers:       $this->filterTiers ?: null,
            searchLibelle:     $this->filterLibelle ?: null,
            searchReference:   $this->filterReference ?: null,
            searchNumeroPiece: $this->filterNumeroPiece ?: null,
            modePaiement:      $this->filterModePaiement ?: null,
            pointe:            $this->filterPointe !== '' ? ($this->filterPointe === '1') : null,
            computeSolde:      $showSolde,
            sortColumn:        $this->sortColumn,
            sortDirection:     $sortDirection,
            perPage:           $this->effectivePerPage(),
            page:              $this->getPage(),
        );

        $rows = collect($result['paginator']->items());
        if ($showSolde && $result['soldeAvantPage'] !== null) {
            $solde = $result['soldeAvantPage'];
            foreach ($rows as $row) {
                $solde += (float) $row->montant;
                $row->solde_courant = $solde;
            }
        }

        return view('livewire.transaction-universelle', [
            'rows'          => $rows,
            'paginator'     => $result['paginator'],
            'showSolde'     => $showSolde,
            'comptes'       => CompteBancaire::orderBy('nom')->get(),
            'modesPaiement' => \App\Enums\ModePaiement::cases(),
            'availableTypes' => $this->lockedTypes ?? ['depense', 'recette', 'don', 'cotisation', 'virement'],
            'showCompteCol'  => $this->compteId === null,
            'showTiersCol'   => $this->tiersId === null,
        ]);
    }
}
```

### Tests

```php
<?php
declare(strict_types=1);

use App\Livewire\TransactionUniverselle;
use App\Models\{Don, Transaction, User};
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('se rend sans erreur', function () {
    Livewire::test(TransactionUniverselle::class)
        ->assertStatus(200);
});

it('accepte les props verrouillées en mount', function () {
    $compte = \App\Models\CompteBancaire::factory()->create();
    Livewire::test(TransactionUniverselle::class, ['compteId' => $compte->id])
        ->assertSet('compteId', $compte->id);
});

it('supprime un don via deleteRow', function () {
    $don = Don::factory()->create(['date' => '2025-10-01']);
    Livewire::test(TransactionUniverselle::class)
        ->call('deleteRow', 'don', $don->id);
    $this->assertSoftDeleted('dons', ['id' => $don->id]);
});

it('ne supprime pas une transaction pointée', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'date'   => '2025-10-01',
        'pointe' => true,
    ]);
    Livewire::test(TransactionUniverselle::class)
        ->call('deleteRow', 'depense', $tx->id);
    $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'deleted_at' => null]);
});
```

### Steps Task 2

- [ ] **Step 1 : Écrire les tests**

Créer `tests/Feature/Livewire/TransactionUniverselleTest.php` avec les tests ci-dessus.

- [ ] **Step 2 : Lancer les tests (doivent échouer)**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/TransactionUniverselleTest.php
```
Expected: FAIL — classe non trouvée.

- [ ] **Step 3 : Implémenter le composant**

Créer `app/Livewire/TransactionUniverselle.php` avec le code ci-dessus.

**Note sur les événements Livewire 4 :** Pour écouter plusieurs événements avec plusieurs attributs `#[On]`, en Livewire 4 on peut empiler les attributs sur la même méthode. Si cela cause une erreur, utiliser une méthode séparée par événement (`onDonSaved`, `onCotisationSaved`, etc.) déléguant toutes à la même logique.

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/TransactionUniverselleTest.php
```
Expected: toutes PASS.

- [ ] **Step 5 : Commit**

```bash
git add app/Livewire/TransactionUniverselle.php tests/Feature/Livewire/TransactionUniverselleTest.php
git commit -m "feat: TransactionUniverselle — composant Livewire avec props et filtres"
```

---

## Task 3 : Blade view (table, toggles, QBE, expansion, Nouveau)

**Files:**
- Create: `resources/views/livewire/transaction-universelle.blade.php`
- Create: `resources/views/transactions/all.blade.php`

Pas de tests automatisés — validation manuelle en ouvrant `/transactions/all`.

### Page wrapper `resources/views/transactions/all.blade.php`

```blade
@extends('layouts.app')

@section('title', 'Toutes les transactions')

@section('content')
<div class="container-fluid py-3">
    <h4 class="mb-3"><i class="bi bi-list-ul me-2"></i>Toutes les transactions</h4>
    <livewire:transaction-universelle />
</div>
@endsection
```

### Vue principale `resources/views/livewire/transaction-universelle.blade.php`

Structure générale :
```
<div>
  [1] Messages d'erreur session
  [2] Bouton "Nouveau" (dropdown ou direct selon availableTypes)
  [3] Toggles type (boutons radio Bootstrap)
  [4] Tableau avec headers QBE + tbody lignes + expansion inline
  [5] Pagination + sélecteur per-page
</div>
```

**[1] Session error :**
```blade
@if(session('error'))
    <div class="alert alert-danger alert-dismissible">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
```

**[2] Bouton Nouveau :**
```blade
<div class="mb-3 d-flex justify-content-between align-items-center">
    @if(count($availableTypes) === 1)
        @php $type = $availableTypes[0]; @endphp
        <button wire:click="$dispatch('{{ match($type) {
            'depense' => 'open-transaction-form', 'recette' => 'open-transaction-form',
            'don' => 'open-don-form', 'cotisation' => 'open-cotisation-form',
            'virement' => 'open-virement-form', default => 'open-transaction-form'
        } }}', { id: null {{ $type === 'depense' ? ", type: 'depense'" : ($type === 'recette' ? ", type: 'recette'" : '') }} })"
                class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg"></i>
            {{ match($type) {
                'depense' => 'Nouvelle dépense', 'recette' => 'Nouvelle recette',
                'don' => 'Nouveau don', 'cotisation' => 'Nouvelle cotisation',
                'virement' => 'Nouveau virement', default => 'Nouveau'
            } }}
        </button>
    @else
        <div class="dropdown">
            <button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-plus-lg"></i> Nouvelle transaction
            </button>
            <ul class="dropdown-menu">
                @if(in_array('depense', $availableTypes))
                    <li><a class="dropdown-item" href="#"
                        wire:click.prevent="$dispatch('open-transaction-form', { id: null, type: 'depense' })">
                        <i class="bi bi-arrow-down-circle text-danger me-1"></i> Dépense</a></li>
                @endif
                @if(in_array('recette', $availableTypes))
                    <li><a class="dropdown-item" href="#"
                        wire:click.prevent="$dispatch('open-transaction-form', { id: null, type: 'recette' })">
                        <i class="bi bi-arrow-up-circle text-success me-1"></i> Recette</a></li>
                @endif
                @if(in_array('don', $availableTypes))
                    <li><a class="dropdown-item" href="#"
                        wire:click.prevent="$dispatch('open-don-form', { id: null })">
                        <i class="bi bi-heart text-primary me-1"></i> Don</a></li>
                @endif
                @if(in_array('cotisation', $availableTypes))
                    <li><a class="dropdown-item" href="#"
                        wire:click.prevent="$dispatch('open-cotisation-form', { id: null })">
                        <i class="bi bi-person-check me-1"></i> Cotisation</a></li>
                @endif
                @if(in_array('virement', $availableTypes))
                    <li><a class="dropdown-item" href="#"
                        wire:click.prevent="$dispatch('open-virement-form', { id: null })">
                        <i class="bi bi-arrow-left-right text-warning me-1"></i> Virement</a></li>
                @endif
            </ul>
        </div>
    @endif
</div>
```

**[3] Toggles type :**

N'afficher que si `count($availableTypes) > 1`.

```blade
@if(count($availableTypes) > 1)
<div class="mb-3 d-flex gap-1 flex-wrap">
    <button wire:click="$set('filterTypes', [])"
            class="btn btn-sm {{ empty($filterTypes) ? 'btn-secondary' : 'btn-outline-secondary' }}">
        Toutes
    </button>
    @foreach($availableTypes as $type)
        @php
            [$btnClass, $label] = match($type) {
                'depense'   => ['danger',    'DÉP'],
                'recette'   => ['success',   'REC'],
                'don'       => ['primary',   'DON'],
                'cotisation'=> ['secondary', 'COT'],
                'virement'  => ['warning',   'VIR'],
                default     => ['secondary', strtoupper($type)],
            };
            $active = in_array($type, $filterTypes);
        @endphp
        <button wire:click="toggleType('{{ $type }}')"
                class="btn btn-sm {{ $active ? "btn-{$btnClass}" : "btn-outline-{$btnClass}" }}">
            {{ $label }}
        </button>
    @endforeach
</div>
@endif
```

**[4] Tableau avec QBE :**

Les popovers QBE sont implémentés avec Alpine.js (`x-data="{ open: false }"`) sur chaque `<th>`. Un clic sur la loupe ouvre un `<div>` positionné en `position: absolute`. La colonne `<th>` a `position: relative`.

Pattern header QBE générique :
```blade
<th style="position:relative">
    <div class="d-flex align-items-center gap-1">
        <span>Label colonne</span>
        <span x-data="{ open: false }" style="position:relative">
            <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6"
               @click="open = !open"></i>
            @if($filterXxx !== '')
                <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                    {{ $filterXxx }}
                    <a href="#" wire:click.prevent="$set('filterXxx', '')" class="text-white ms-1">×</a>
                </span>
            @endif
            <div x-show="open" @click.outside="open = false"
                 class="position-absolute bg-white border rounded shadow-sm p-2"
                 style="z-index:200;min-width:180px;top:1.2rem;left:0">
                <input wire:model.live.debounce.300ms="filterXxx"
                       class="form-control form-control-sm"
                       placeholder="Filtrer…"
                       @keydown.escape="open = false">
            </div>
        </span>
    </div>
</th>
```

**Header Date avec presets :**
```blade
<th style="position:relative">
    <div class="d-flex align-items-center gap-1">
        <a href="#" wire:click.prevent="sortBy('date')" class="text-white text-decoration-none">
            Date @if($sortColumn === 'date')<i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>@endif
        </a>
        <span x-data="{ open: false }" style="position:relative">
            <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
            @if($filterDateDebut || $filterDateFin)
                <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                    {{ $filterDateDebut ? \Carbon\Carbon::parse($filterDateDebut)->format('d/m') : '…' }}
                    –
                    {{ $filterDateFin ? \Carbon\Carbon::parse($filterDateFin)->format('d/m') : '…' }}
                    <a href="#" wire:click.prevent="applyDatePreset('exercice')" class="text-white ms-1">×</a>
                </span>
            @endif
            <div x-show="open" @click.outside="open = false"
                 class="position-absolute bg-white border rounded shadow-sm p-2 text-dark"
                 style="z-index:200;min-width:220px;top:1.2rem;left:0">
                <div class="d-flex flex-column gap-1 mb-2">
                    <button wire:click="applyDatePreset('exercice')" @click="open=false"
                            class="btn btn-outline-secondary btn-sm text-start">Exercice en cours</button>
                    <button wire:click="applyDatePreset('mois')" @click="open=false"
                            class="btn btn-outline-secondary btn-sm text-start">Mois en cours</button>
                    <button wire:click="applyDatePreset('trimestre')" @click="open=false"
                            class="btn btn-outline-secondary btn-sm text-start">Trimestre en cours</button>
                    <button wire:click="applyDatePreset('all')" @click="open=false"
                            class="btn btn-outline-secondary btn-sm text-start">Toutes les dates</button>
                </div>
                <hr class="my-1">
                <div class="d-flex flex-column gap-1">
                    <label class="form-label small mb-0">Début</label>
                    <input wire:model.live="filterDateDebut" type="date" class="form-control form-control-sm">
                    <label class="form-label small mb-0">Fin</label>
                    <input wire:model.live="filterDateFin" type="date" class="form-control form-control-sm">
                </div>
            </div>
        </span>
    </div>
</th>
```

**Header Mode paiement (select) :**
```blade
<th style="position:relative">
    <div class="d-flex align-items-center gap-1">
        Mode
        <span x-data="{ open: false }" style="position:relative">
            <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
            @if($filterModePaiement !== '')
                <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                    {{ $filterModePaiement }}
                    <a href="#" wire:click.prevent="$set('filterModePaiement', '')" class="text-white ms-1">×</a>
                </span>
            @endif
            <div x-show="open" @click.outside="open = false"
                 class="position-absolute bg-white border rounded shadow-sm p-2 text-dark"
                 style="z-index:200;min-width:160px;top:1.2rem;left:0">
                <select wire:model.live="filterModePaiement" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    @foreach($modesPaiement as $mode)
                        <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                    @endforeach
                </select>
            </div>
        </span>
    </div>
</th>
```

**Header Pointé (select) :**
```blade
<th style="position:relative">
    <div class="d-flex align-items-center gap-1">
        Pointé
        <span x-data="{ open: false }" style="position:relative">
            <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
            @if($filterPointe !== '')
                <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                    {{ $filterPointe === '1' ? 'Oui' : 'Non' }}
                    <a href="#" wire:click.prevent="$set('filterPointe', '')" class="text-white ms-1">×</a>
                </span>
            @endif
            <div x-show="open" @click.outside="open = false"
                 class="position-absolute bg-white border rounded shadow-sm p-2 text-dark"
                 style="z-index:200;min-width:120px;top:1.2rem;left:0">
                <select wire:model.live="filterPointe" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <option value="1">Oui</option>
                    <option value="0">Non</option>
                </select>
            </div>
        </span>
    </div>
</th>
```

**Header Compte (select, visible si `$showCompteCol`) :**
```blade
@if($showCompteCol)
<th style="position:relative">
    <div class="d-flex align-items-center gap-1">
        Compte
        <span x-data="{ open: false }" style="position:relative">
            <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
            @if($filterCompteId)
                <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                    × <a href="#" wire:click.prevent="$set('filterCompteId', null)" class="text-white">×</a>
                </span>
            @endif
            <div x-show="open" @click.outside="open = false"
                 class="position-absolute bg-white border rounded shadow-sm p-2 text-dark"
                 style="z-index:200;min-width:180px;top:1.2rem;left:0">
                <select wire:model.live="filterCompteId" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    @foreach($comptes as $c)
                        <option value="{{ $c->id }}">{{ $c->nom }}</option>
                    @endforeach
                </select>
            </div>
        </span>
    </div>
</th>
@endif
```

**Ligne de données `<tr>` :**
```blade
@foreach($rows as $tx)
    @php
        $key = $tx->source_type . ':' . $tx->id;
        $isExpanded = isset($expandedDetails[$key]);
        $detail = $expandedDetails[$key] ?? null;
        [$badgeClass, $badgeLabel] = match($tx->source_type) {
            'depense'              => ['danger',    'DÉP'],
            'recette'              => ['success',   'REC'],
            'don'                  => ['primary',   'DON'],
            'cotisation'           => ['secondary', 'COT'],
            'virement_sortant',
            'virement_entrant'     => ['warning',   'VIR'],
            default                => ['secondary', '?'],
        };
        $isLocked = (bool) $tx->pointe;
    @endphp
    <tr style="cursor:pointer" wire:click="toggleDetail('{{ $tx->source_type }}', {{ $tx->id }})">
        <td class="small text-muted text-nowrap">{{ $tx->numero_piece ?? '—' }}</td>
        <td class="small text-nowrap">{{ \Carbon\Carbon::parse($tx->date)->format('d/m') }}</td>
        @if(count($availableTypes) > 1)
            <td>
                <span class="badge text-bg-{{ $badgeClass }}" style="font-size:.65rem">{{ $badgeLabel }}</span>
            </td>
        @endif
        <td class="small text-muted text-nowrap">{{ $tx->reference ?? '' }}</td>
        @if($showTiersCol)
            <td class="small text-nowrap" style="max-width:160px;overflow:hidden;text-overflow:ellipsis">
                @if($tx->tiers)
                    @if($tx->tiers_type === 'entreprise')
                        <i class="bi bi-building text-muted me-1" style="font-size:.7rem"></i>
                    @elseif($tx->tiers_type)
                        <i class="bi bi-person text-muted me-1" style="font-size:.7rem"></i>
                    @else
                        <i class="bi bi-bank text-muted me-1" style="font-size:.7rem"></i>
                    @endif
                    {{ $tx->tiers }}
                @else
                    <span class="text-muted">—</span>
                @endif
            </td>
        @endif
        @if($showCompteCol)
            <td class="small text-muted">{{ $tx->compte_nom ?? '—' }}</td>
        @endif
        <td class="small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis">{{ $tx->libelle ?? '—' }}</td>
        <td class="small text-muted">
            @if((int)$tx->nb_lignes > 1)
                <i class="bi bi-diagram-2 text-secondary me-1" title="{{ $tx->nb_lignes }} lignes"></i>
            @endif
            {{ $tx->categorie_label ?? '' }}
        </td>
        <td class="small text-muted">{{ $tx->mode_paiement ?? '—' }}</td>
        <td class="text-end fw-semibold small text-nowrap {{ (float)$tx->montant >= 0 ? 'text-success' : 'text-danger' }}">
            {{ number_format(abs((float)$tx->montant), 2, ',', ' ') }} €
        </td>
        <td class="text-center">
            @if($tx->pointe)
                <i class="bi bi-check-circle-fill text-success" style="font-size:.85rem"></i>
            @endif
        </td>
        @if($showSolde)
            <td class="text-end small text-muted">
                {{ isset($tx->solde_courant) ? number_format((float)$tx->solde_courant, 2, ',', ' ').' €' : '' }}
            </td>
        @endif
        <td>
            <div class="d-flex gap-1" @click.stop>
                <button wire:click="$dispatch('{{ match($tx->source_type) {
                    'depense', 'recette' => 'open-transaction-form',
                    'don' => 'open-don-form',
                    'cotisation' => 'open-cotisation-form',
                    'virement_sortant', 'virement_entrant' => 'open-virement-form',
                    default => 'open-transaction-form'
                } }}', { id: {{ $tx->id }} })"
                        @if($isLocked) style="display:none" @endif
                        class="btn btn-sm btn-outline-primary"
                        style="padding:.15rem .3rem;font-size:.7rem"
                        title="Modifier">
                    <i class="bi bi-pencil"></i>
                </button>
                <button wire:click="deleteRow('{{ $tx->source_type }}', {{ $tx->id }})"
                        wire:confirm="Supprimer cette ligne ?"
                        @if($isLocked) style="display:none" @endif
                        class="btn btn-sm btn-outline-danger"
                        style="padding:.15rem .3rem;font-size:.7rem"
                        title="Supprimer">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </td>
    </tr>
    {{-- Ligne d'expansion --}}
    @if($isExpanded && $detail)
        <tr class="table-light">
            <td colspan="{{ 9 + (count($availableTypes) > 1 ? 1 : 0) + ($showTiersCol ? 1 : 0) + ($showCompteCol ? 1 : 0) + ($showSolde ? 1 : 0) }}"
                class="px-4 py-2 small text-muted">
                @if(!empty($detail['lignes']))
                    <strong>Ventilation :</strong>
                    <ul class="mb-0 mt-1">
                        @foreach($detail['lignes'] as $ligne)
                            <li>{{ $ligne['categorie'] }} › {{ $ligne['sous_categorie'] }} — {{ number_format($ligne['montant'], 2, ',', ' ') }} €</li>
                        @endforeach
                    </ul>
                @endif
                @if(!empty($detail['sous_categorie']))
                    <strong>Sous-catégorie :</strong> {{ $detail['sous_categorie'] }}
                @endif
                @if(!empty($detail['operation']))
                    &nbsp;· <strong>Opération :</strong> {{ $detail['operation'] }}
                @endif
                @if(!empty($detail['seance']))
                    &nbsp;· <strong>Séance :</strong> {{ $detail['seance'] }}
                @endif
                @if(!empty($detail['exercice']))
                    &nbsp;· <strong>Exercice :</strong> {{ $detail['exercice'] }}
                @endif
            </td>
        </tr>
    @endif
@endforeach
```

**[5] Pagination :**
```blade
<div class="mt-3">
    <x-per-page-selector :paginator="$paginator" storageKey="transaction-universelle" wire:model.live="perPage" />
    {{ $paginator->links() }}
</div>
```

### Steps Task 3

- [ ] **Step 1 : Créer `resources/views/transactions/all.blade.php`**

Simple page wrapper (code ci-dessus).

- [ ] **Step 2 : Créer `resources/views/livewire/transaction-universelle.blade.php`**

Assembler tous les éléments décrits ci-dessus :
1. Message erreur session
2. Bouton Nouveau (dropdown)
3. Toggles type (appel `toggleType()`)
4. `<div class="table-responsive">` + `<table class="table table-sm table-hover align-middle">`
5. `<thead>` avec les en-têtes QBE pour : N°pièce, Date (presets), Type (si multi), Référence, Tiers (si visible), Compte (si visible), Libellé, Catégorie, Mode, Montant, Pointé, Solde (si visible), Actions
6. `<tbody>` avec boucle forelse sur `$rows` + ligne expansion
7. Pagination

Ordre des colonnes exactement comme dans la spec : N°pièce | Date | Type | Référence | Tiers | Compte | Libellé | Catégorie | Mode | Montant | Pointé | Solde | Actions

- [ ] **Step 3 : Effectuer Task 4 (route + nav) puis vérifier en navigateur**

Compléter Task 4 avant cette étape. Ensuite :

```
http://localhost/transactions/all
```

Vérifier :
- La table se charge avec des données
- Les toggles type filtrent correctement
- Les popovers QBE s'ouvrent et filtrent
- L'expansion d'une ligne affiche les détails
- Le bouton Nouveau dropdown ouvre les bons modaux

- [ ] **Step 4 : Commit**

```bash
git add resources/views/livewire/transaction-universelle.blade.php \
        resources/views/transactions/all.blade.php
git commit -m "feat: vue blade TransactionUniverselle — table QBE, toggles, expansion, Nouveau"
```

---

## Task 4 : Route + navigation

**Files:**
- Modify: `routes/web.php` (ligne ~22 zone Route::view)
- Modify: `resources/views/layouts/app.blade.php` (dropdown Transactions ~ligne 126)

### Steps Task 4

- [ ] **Step 1 : Ajouter la route dans `routes/web.php`**

Après la ligne `Route::view('/transactions', 'transactions.index')->name('transactions.index');`, ajouter :

```php
Route::view('/transactions/all', 'transactions.all')->name('transactions.all');
```

**Important :** Cette route doit être AVANT les routes paramétrées commençant par `/transactions/` pour éviter des conflits. En pratique, `/transactions/all` est statique donc pas de conflit avec d'éventuelles routes paramétrées.

- [ ] **Step 2 : Ajouter le lien nav dans `resources/views/layouts/app.blade.php`**

Dans le dropdown Transactions (autour de ligne 127), après le lien "Toutes" existant, ajouter un lien vers la nouvelle vue :

```blade
<li>
    <a class="dropdown-item {{ request()->routeIs('transactions.all') ? 'active' : '' }}"
       href="{{ route('transactions.all') }}">
        <i class="bi bi-table"></i> Vue unifiée <span class="badge bg-secondary ms-1" style="font-size:.6rem">v2</span>
    </a>
</li>
```

Ajouter également `transactions.all` dans la condition d'activation du dropdown parent (ligne ~122) :

```blade
class="nav-link dropdown-toggle {{ request()->routeIs('transactions.*') || request()->routeIs('virements.*') || request()->routeIs('dons.*') || request()->routeIs('cotisations.*') ? 'active' : '' }}"
```

(Déjà couverte par `transactions.*`.)

- [ ] **Step 3 : Vérifier**

```
http://localhost/transactions/all
```

La page doit s'afficher avec le composant Livewire.
Cliquer sur "Vue unifiée" dans le menu → doit naviguer correctement.

- [ ] **Step 4 : Lancer tous les tests**

```bash
./vendor/bin/sail artisan test --filter=TransactionUniverselle
```
Expected: toutes PASS.

- [ ] **Step 5 : Commit**

```bash
git add routes/web.php resources/views/layouts/app.blade.php
git commit -m "feat: route /transactions/all et lien nav Vue unifiée"
```

---

## Notes pour les subagents

### Tester en local

L'application tourne via Docker Sail :
```bash
# Tests PHP
./vendor/bin/sail artisan test [chemin/test]

# Si Docker n'est pas démarré :
docker compose up -d
```

### Conventions du projet
- `declare(strict_types=1)` + `final class` sur tous les composants Livewire et Services
- PSR-12 : `./vendor/bin/pint` pour formater
- Locale fr partout

### Don et Cotisation — has soft deletes
Les deux ont `deleted_at` — toujours ajouter `->whereNull('x.deleted_at')` dans le UNION.

### Relations don
`Don` a : `tiers()`, `sousCategorie()`, `operation()`, `compte()`. Champs : `tiers_id`, `sous_categorie_id`, `operation_id`, `seance`, `objet`.

### Relations cotisation
`Cotisation` a : `tiers()`, `sousCategorie()`, `compte()`. Champs : `tiers_id`, `sous_categorie_id`, `exercice`, `date_paiement`.

### On event multiples en Livewire 4
En Livewire 4 PHP 8.x, plusieurs attributs `#[On]` sur une même méthode sont supportés. Si erreur de compilation, déclarer une méthode par événement :
```php
#[On('don-saved')]
public function onDonSaved(): void {}
#[On('cotisation-saved')]
public function onCotisationSaved(): void {}
// etc.
```
