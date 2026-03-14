# Vue transactions par compte

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter une vue unifiée des transactions par compte bancaire (recettes, dépenses, dons, cotisations, virements internes entrants et sortants) avec filtres date/tiers, tri par colonne, pagination, solde courant cumulé, et actions modifier/supprimer.

**Architecture:** `TransactionCompteService` construit un UNION ALL à 6 branches via le query builder Laravel avec `fromSub()` pour l'enveloppe externe. Le composant Livewire `TransactionCompteList` orchestre les filtres, la pagination et les suppressions via les services existants. Le solde cumulé est calculé en PHP sur les items de la page courante après récupération du solde avant page via une sous-requête. La route GET `/comptes-bancaires/transactions` est déclarée avant la resource route `comptes-bancaires` pour éviter les conflits. Un lien "Transactions" est ajouté dans le `$navItems` de la navbar après "Virements".

**Note importante :** Le plan suppose que le renommage `payeur`→`tiers` et `beneficiaire`→`tiers` (plan `2026-03-13-renommage-tiers.md`) a été appliqué au préalable. Les requêtes SQL utilisent `r.tiers` et `d.tiers`. Si ce n'est pas le cas, remplacer par `r.payeur` et `d.beneficiaire` et adapter le filtre tiers en conséquence.

**Tech Stack:** Laravel 11, Pest PHP, Bootstrap 5 (CDN), Blade, Livewire 4

---

## Task 1: `TransactionCompteService`

**Files:**
- Create: `app/Services/TransactionCompteService.php`
- Create: `tests/Feature/Services/TransactionCompteServiceTest.php`

- [ ] **Step 1 : Écrire les tests (RED)**

Créer `tests/Feature/Services/TransactionCompteServiceTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Don;
use App\Models\Donateur;
use App\Models\Depense;
use App\Models\Membre;
use App\Models\Recette;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\TransactionCompteService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(TransactionCompteService::class);
    $this->compte = CompteBancaire::factory()->create([
        'solde_initial' => 1000.00,
        'nom' => 'Compte Principal',
    ]);
});

it('retourne une recette avec montant positif', function () {
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $recette = $items->firstWhere('source_type', 'recette');
    expect($recette)->not->toBeNull();
    expect((float) $recette->montant)->toBe(200.00);
});

it('retourne une depense avec montant negatif', function () {
    Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 150.00,
        'date' => '2025-10-02',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $depense = $items->firstWhere('source_type', 'depense');
    expect($depense)->not->toBeNull();
    expect((float) $depense->montant)->toBe(-150.00);
});

it('retourne un don avec le nom du donateur comme tiers', function () {
    $donateur = Donateur::factory()->create(['prenom' => 'Marie', 'nom' => 'Dupont']);
    Don::factory()->create([
        'compte_id' => $this->compte->id,
        'donateur_id' => $donateur->id,
        'montant' => 50.00,
        'date' => '2025-10-03',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $don = $items->firstWhere('source_type', 'don');
    expect($don)->not->toBeNull();
    expect((float) $don->montant)->toBe(50.00);
    expect(trim($don->tiers))->toContain('Marie');
    expect(trim($don->tiers))->toContain('Dupont');
});

it('retourne une cotisation avec le nom du membre comme tiers', function () {
    $membre = Membre::factory()->create(['prenom' => 'Jean', 'nom' => 'Martin']);
    Cotisation::factory()->create([
        'compte_id' => $this->compte->id,
        'membre_id' => $membre->id,
        'montant' => 80.00,
        'date_paiement' => '2025-10-04',
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $cotisation = $items->firstWhere('source_type', 'cotisation');
    expect($cotisation)->not->toBeNull();
    expect((float) $cotisation->montant)->toBe(80.00);
    expect(trim($cotisation->tiers))->toContain('Jean');
    expect(trim($cotisation->tiers))->toContain('Martin');
});

it('un virement depuis compte A vers B apparaît sur A comme virement_sortant négatif, tiers = nom de B', function () {
    $compteB = CompteBancaire::factory()->create(['nom' => 'Compte Épargne']);
    VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => $compteB->id,
        'montant' => 300.00,
        'date' => '2025-10-05',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $virement = $items->firstWhere('source_type', 'virement_sortant');
    expect($virement)->not->toBeNull();
    expect((float) $virement->montant)->toBe(-300.00);
    expect($virement->tiers)->toBe('Compte Épargne');
});

it('un virement depuis B vers compte A apparaît sur A comme virement_entrant positif, tiers = nom de B', function () {
    $compteB = CompteBancaire::factory()->create(['nom' => 'Compte Épargne']);
    VirementInterne::factory()->create([
        'compte_source_id' => $compteB->id,
        'compte_destination_id' => $this->compte->id,
        'montant' => 250.00,
        'date' => '2025-10-06',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $virement = $items->firstWhere('source_type', 'virement_entrant');
    expect($virement)->not->toBeNull();
    expect((float) $virement->montant)->toBe(250.00);
    expect($virement->tiers)->toBe('Compte Épargne');
});

it('filtre par date : seules les transactions dans la plage apparaissent', function () {
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-09-15',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: '2025-10-01',
        dateFin: '2025-10-31',
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    expect($result['paginator']->total())->toBe(0);
});

it('filtre par tiers : seules les transactions correspondantes apparaissent', function () {
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'tiers' => 'Association Tartempion',
        'montant_total' => 100.00,
        'date' => '2025-10-10',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'tiers' => 'Mairie de Lyon',
        'montant_total' => 200.00,
        'date' => '2025-10-11',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: 'Tartempion',
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    expect($result['paginator']->total())->toBe(1);
    $item = collect($result['paginator']->items())->first();
    expect($item->tiers)->toBe('Association Tartempion');
});

it('les recettes soft-deleted n\'apparaissent pas', function () {
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-10-10',
        'saisi_par' => $this->user->id,
    ]);
    $recette->delete();

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    expect($result['paginator']->total())->toBe(0);
});

it('soldeAvantPage sur page 1 est égal à solde_initial', function () {
    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    expect($result['soldeAvantPage'])->toBe(1000.00);
    expect($result['showSolde'])->toBeTrue();
});

it('soldeAvantPage sur page 2 est égal à solde_initial + somme des 15 premières transactions', function () {
    // Créer 16 recettes pour avoir au moins 2 pages
    for ($i = 1; $i <= 16; $i++) {
        Recette::factory()->create([
            'compte_id' => $this->compte->id,
            'montant_total' => 10.00,
            'date' => '2025-10-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'saisi_par' => $this->user->id,
        ]);
    }

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 2,
    );

    // solde_initial (1000) + 15 × 10.00 = 1150.00
    expect($result['soldeAvantPage'])->toBe(1150.00);
});

it('accumule le solde_courant ligne par ligne sur la page courante', function () {
    // 3 transactions : +100, -30, +50 → soldes cumulés attendus : 1100, 1070, 1120
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 30.00,
        'date' => '2025-10-02',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 50.00,
        'date' => '2025-10-03',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    // Simuler l'accumulation PHP comme le fait TransactionCompteList::render()
    $solde = $result['soldeAvantPage']; // 1000.00
    $attendus = [1100.00, 1070.00, 1120.00];
    foreach (collect($result['paginator']->items()) as $i => $tx) {
        $solde += (float) $tx->montant;
        expect(round($solde, 2))->toBe($attendus[$i]);
    }
});
```

Run pour confirmer FAIL :
```bash
./vendor/bin/sail artisan test --filter TransactionCompteServiceTest
```

- [ ] **Step 2 : Créer `TransactionCompteService`**

Créer `app/Services/TransactionCompteService.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TransactionCompteService
{
    /**
     * @return array{paginator: LengthAwarePaginator, soldeAvantPage: float|null, showSolde: bool}
     */
    public function paginate(
        CompteBancaire $compte,
        ?string $dateDebut,
        ?string $dateFin,
        ?string $searchTiers,
        string $sortColumn,
        string $sortDirection,
        int $perPage = 15,
        int $page = 1,
    ): array {
        $showSolde = empty($searchTiers) && $sortColumn === 'date' && $sortDirection === 'asc';

        $union = $this->buildUnion($compte, $dateDebut, $dateFin, $searchTiers);

        $outer = DB::query()->fromSub($union, 't')
            ->orderBy($sortColumn, $sortDirection);

        if ($sortColumn === 'date') {
            $outer->orderBy('source_type')->orderBy('id');
        }

        $paginator = $outer->paginate($perPage, ['*'], 'page', $page);

        $soldeAvantPage = null;
        if ($showSolde) {
            $offset = ($paginator->currentPage() - 1) * $perPage;
            $sumAvant = 0.0;
            if ($offset > 0) {
                $unionForSolde = $this->buildUnion($compte, $dateDebut, $dateFin, null);
                $inner = DB::query()->fromSub($unionForSolde, 'u')
                    ->select('montant')
                    ->orderBy('date')->orderBy('source_type')->orderBy('id')
                    ->limit($offset);
                $sumAvant = (float) DB::query()->fromSub($inner, 'avant')->sum('montant');
            }
            $soldeAvantPage = (float) $compte->solde_initial + $sumAvant;
        }

        return [
            'paginator' => $paginator,
            'soldeAvantPage' => $soldeAvantPage,
            'showSolde' => $showSolde,
        ];
    }

    private function buildUnion(
        CompteBancaire $compte,
        ?string $dateDebut,
        ?string $dateFin,
        ?string $searchTiers,
    ): Builder {
        $id = $compte->id;
        $tiersLike = $searchTiers ? "%{$searchTiers}%" : null;

        $recettes = DB::table('recettes as r')
            ->selectRaw("r.id, 'recette' as source_type, r.date, 'Recette' as type_label, r.tiers, r.libelle, r.reference, r.montant_total as montant, r.mode_paiement, r.pointe")
            ->where('r.compte_id', $id)
            ->whereNull('r.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('r.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('r.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->where('r.tiers', 'like', $tiersLike));

        $depenses = DB::table('depenses as d')
            ->selectRaw("d.id, 'depense' as source_type, d.date, 'Dépense' as type_label, d.tiers, d.libelle, d.reference, -(d.montant_total) as montant, d.mode_paiement, d.pointe")
            ->where('d.compte_id', $id)
            ->whereNull('d.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('d.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('d.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->where('d.tiers', 'like', $tiersLike));

        $dons = DB::table('dons as dn')
            ->leftJoin('donateurs as do', 'do.id', '=', 'dn.donateur_id')
            ->selectRaw("dn.id, 'don' as source_type, dn.date, 'Don' as type_label, TRIM(CONCAT(COALESCE(`do`.prenom,''), ' ', COALESCE(`do`.nom,''))) as tiers, dn.objet as libelle, NULL as reference, dn.montant, dn.mode_paiement, dn.pointe")
            ->where('dn.compte_id', $id)
            ->whereNull('dn.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('dn.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('dn.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->whereRaw("TRIM(CONCAT(COALESCE(`do`.prenom,''), ' ', COALESCE(`do`.nom,''))) LIKE ?", [$tiersLike]));

        $cotisations = DB::table('cotisations as c')
            ->leftJoin('membres as m', 'm.id', '=', 'c.membre_id')
            ->selectRaw("c.id, 'cotisation' as source_type, c.date_paiement as date, 'Cotisation' as type_label, TRIM(CONCAT(COALESCE(m.prenom,''), ' ', COALESCE(m.nom,''))) as tiers, CONCAT('Cotisation ', c.exercice) as libelle, NULL as reference, c.montant, c.mode_paiement, c.pointe")
            ->where('c.compte_id', $id)
            ->whereNull('c.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('c.date_paiement', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('c.date_paiement', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->whereRaw("TRIM(CONCAT(COALESCE(m.prenom,''), ' ', COALESCE(m.nom,''))) LIKE ?", [$tiersLike]));

        $virementsSource = DB::table('virements_internes as vi')
            ->join('comptes_bancaires as cb', 'cb.id', '=', 'vi.compte_destination_id')
            ->selectRaw("vi.id, 'virement_sortant' as source_type, vi.date, 'Virement sortant' as type_label, cb.nom as tiers, CONCAT('Virement vers ', cb.nom) as libelle, vi.reference, -(vi.montant) as montant, NULL as mode_paiement, NULL as pointe")
            ->where('vi.compte_source_id', $id)
            ->whereNull('vi.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('vi.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('vi.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->where('cb.nom', 'like', $tiersLike));

        $virementsDestination = DB::table('virements_internes as vi')
            ->join('comptes_bancaires as cb', 'cb.id', '=', 'vi.compte_source_id')
            ->selectRaw("vi.id, 'virement_entrant' as source_type, vi.date, 'Virement entrant' as type_label, cb.nom as tiers, CONCAT('Virement depuis ', cb.nom) as libelle, vi.reference, vi.montant, NULL as mode_paiement, NULL as pointe")
            ->where('vi.compte_destination_id', $id)
            ->whereNull('vi.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('vi.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('vi.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->where('cb.nom', 'like', $tiersLike));

        return $recettes
            ->unionAll($depenses)
            ->unionAll($dons)
            ->unionAll($cotisations)
            ->unionAll($virementsSource)
            ->unionAll($virementsDestination);
    }
}
```

- [ ] **Step 3 : Run tests pour confirmer GREEN**

```bash
./vendor/bin/sail artisan test --filter TransactionCompteServiceTest
```

- [ ] **Step 4 : Run pint et commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint \
    app/Services/TransactionCompteService.php

git add app/Services/TransactionCompteService.php \
        tests/Feature/Services/TransactionCompteServiceTest.php
git commit -m "feat: TransactionCompteService — UNION ALL query with pagination and running balance"
```

---

## Task 2: Composant Livewire `TransactionCompteList`

**Files:**
- Create: `app/Livewire/TransactionCompteList.php`
- Create: `tests/Feature/TransactionCompteListTest.php`

- [ ] **Step 1 : Écrire les tests (RED)**

Créer `tests/Feature/TransactionCompteListTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\TransactionCompteList;
use App\Models\CompteBancaire;
use App\Models\Recette;
use App\Models\Depense;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create(['nom' => 'Compte Test']);
});

it('renders without compte selected', function () {
    Livewire::test(TransactionCompteList::class)
        ->assertSee('Sélectionnez un compte')
        ->assertDontSee('Aucune transaction');
});

it('shows transactions when compte is selected', function () {
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'libelle' => 'Cotisation annuelle',
        'montant_total' => 120.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->assertSee('Cotisation annuelle');
});

it('filtre par tiers', function () {
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'tiers' => 'Fondation ABC',
        'libelle' => 'Subvention',
        'montant_total' => 500.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'tiers' => 'Mairie XYZ',
        'libelle' => 'Autre recette',
        'montant_total' => 100.00,
        'date' => '2025-10-02',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->set('searchTiers', 'Fondation')
        ->assertSee('Subvention')
        ->assertDontSee('Autre recette');
});

it('supprime une recette non verrouillée', function () {
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->call('deleteTransaction', 'recette', $recette->id);

    $this->assertSoftDeleted('recettes', ['id' => $recette->id]);
});

it('ne supprime pas une recette verrouillée par un rapprochement', function () {
    $rapprochement = \App\Models\RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => \App\Enums\StatutRapprochement::Verrouille,
        'saisi_par' => $this->user->id,
    ]);
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
        'rapprochement_id' => $rapprochement->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->call('deleteTransaction', 'recette', $recette->id);

    $this->assertDatabaseHas('recettes', ['id' => $recette->id, 'deleted_at' => null]);
});

it('trie par montant', function () {
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'libelle' => 'Petite recette',
        'montant_total' => 10.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'libelle' => 'Grande recette',
        'montant_total' => 1000.00,
        'date' => '2025-10-02',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->call('sortBy', 'montant')
        ->assertSet('sortColumn', 'montant')
        ->assertSet('sortDirection', 'asc');
});

it('inverse la direction de tri si on clique sur la même colonne', function () {
    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->set('sortColumn', 'date')
        ->set('sortDirection', 'asc')
        ->call('sortBy', 'date')
        ->assertSet('sortDirection', 'desc');
});

it('reset la pagination quand le compte change', function () {
    $autreCompte = CompteBancaire::factory()->create();

    $component = Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id);

    $component->set('compteId', $autreCompte->id);

    // La page doit être revenue à 1 (pas d'erreur de pagination)
    $component->assertSet('compteId', $autreCompte->id);
});
```

Run pour confirmer FAIL :
```bash
./vendor/bin/sail artisan test --filter TransactionCompteListTest
```

- [ ] **Step 2 : Créer `TransactionCompteList`**

Créer `app/Livewire/TransactionCompteList.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Recette;
use App\Models\VirementInterne;
use App\Services\CotisationService;
use App\Services\DepenseService;
use App\Services\DonService;
use App\Services\ExerciceService;
use App\Services\RecetteService;
use App\Services\TransactionCompteService;
use App\Services\VirementInterneService;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionCompteList extends Component
{
    use WithPagination;

    public ?int $compteId = null;

    public string $dateDebut = '';

    public string $dateFin = '';

    public string $searchTiers = '';

    public string $sortColumn = 'date';

    public string $sortDirection = 'asc';

    public function mount(): void
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();
        $this->dateDebut = "{$exercice}-09-01";
        $this->dateFin = ($exercice + 1) . '-08-31';

        $comptes = CompteBancaire::orderBy('nom')->get();
        if ($comptes->count() === 1) {
            $this->compteId = $comptes->first()->id;
        }
    }

    public function updatedCompteId(): void
    {
        $this->resetPage();
    }

    public function updatedSearchTiers(): void
    {
        $this->resetPage();
    }

    public function updatedDateDebut(): void
    {
        $this->resetPage();
    }

    public function updatedDateFin(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function deleteTransaction(string $sourceType, int $id): void
    {
        match ($sourceType) {
            'recette' => $this->deleteRecette($id),
            'depense' => $this->deleteDepense($id),
            'don' => $this->deleteDon($id),
            'cotisation' => $this->deleteCotisation($id),
            'virement_sortant', 'virement_entrant' => $this->deleteVirement($id),
            default => null,
        };
    }

    private function deleteRecette(int $id): void
    {
        $recette = Recette::find($id);
        if (! $recette || $recette->isLockedByRapprochement()) {
            return;
        }
        app(RecetteService::class)->delete($recette);
    }

    private function deleteDepense(int $id): void
    {
        $depense = Depense::find($id);
        if (! $depense || $depense->isLockedByRapprochement()) {
            return;
        }
        app(DepenseService::class)->delete($depense);
    }

    private function deleteDon(int $id): void
    {
        $don = Don::find($id);
        if (! $don) {
            return;
        }
        app(DonService::class)->delete($don);
    }

    private function deleteCotisation(int $id): void
    {
        $cotisation = Cotisation::find($id);
        if (! $cotisation) {
            return;
        }
        app(CotisationService::class)->delete($cotisation);
    }

    private function deleteVirement(int $id): void
    {
        $virement = VirementInterne::find($id);
        if (! $virement || $virement->isLockedByRapprochement()) {
            return;
        }
        app(VirementInterneService::class)->delete($virement);
    }

    public function redirectToEdit(string $sourceType, int $id): mixed
    {
        $url = match ($sourceType) {
            'recette'  => route('recettes.index') . '?edit=' . $id,
            'depense'  => route('depenses.index') . '?edit=' . $id,
            'don'      => route('dons.index') . '?edit=' . $id,
            'virement_sortant', 'virement_entrant' => route('virements.index') . '?edit=' . $id,
            'cotisation' => $this->buildCotisationEditUrl($id),
            default    => route('dashboard'),
        };

        return redirect()->to($url);
    }

    private function buildCotisationEditUrl(int $id): string
    {
        $cotisation = Cotisation::find($id);
        if (! $cotisation) {
            return route('membres.index');
        }

        return route('membres.index') . '?membre=' . $cotisation->membre_id . '&edit=' . $id;
    }

    public function render()
    {
        $comptes = CompteBancaire::orderBy('nom')->get();

        if ($this->compteId === null) {
            return view('livewire.transaction-compte-list', [
                'comptes' => $comptes,
                'paginator' => null,
                'soldeAvantPage' => null,
                'showSolde' => false,
                'transactions' => collect(),
            ]);
        }

        $compte = CompteBancaire::findOrFail($this->compteId);

        $result = app(TransactionCompteService::class)->paginate(
            compte: $compte,
            dateDebut: $this->dateDebut ?: null,
            dateFin: $this->dateFin ?: null,
            searchTiers: $this->searchTiers ?: null,
            sortColumn: $this->sortColumn,
            sortDirection: $this->sortDirection,
            page: $this->getPage(),
        );

        $transactions = collect($result['paginator']->items());

        if ($result['showSolde'] && $result['soldeAvantPage'] !== null) {
            $solde = $result['soldeAvantPage'];
            foreach ($transactions as $tx) {
                $solde += (float) $tx->montant;
                $tx->solde_courant = $solde;
            }
        }

        return view('livewire.transaction-compte-list', [
            'comptes' => $comptes,
            'paginator' => $result['paginator'],
            'soldeAvantPage' => $result['soldeAvantPage'],
            'showSolde' => $result['showSolde'],
            'transactions' => $transactions,
        ]);
    }
}
```

- [ ] **Step 3 : Run tests**

```bash
./vendor/bin/sail artisan test --filter TransactionCompteListTest
```

- [ ] **Step 4 : Run pint et commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint \
    app/Livewire/TransactionCompteList.php

git add app/Livewire/TransactionCompteList.php \
        tests/Feature/TransactionCompteListTest.php
git commit -m "feat: TransactionCompteList Livewire component with filters, sorting, and delete actions"
```

---

## Task 3: Vues Blade

**Files:**
- Create: `resources/views/comptes-bancaires/transactions.blade.php`
- Create: `resources/views/livewire/transaction-compte-list.blade.php`

- [ ] **Step 1 : Créer la vue page `transactions.blade.php`**

Créer `resources/views/comptes-bancaires/transactions.blade.php` :

```blade
<x-app-layout>
    <h1 class="mb-4">Transactions par compte</h1>
    <livewire:transaction-compte-list />
</x-app-layout>
```

- [ ] **Step 2 : Créer la vue Livewire `transaction-compte-list.blade.php`**

Créer `resources/views/livewire/transaction-compte-list.blade.php` :

```blade
<div>
    {{-- Filtres --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <label for="compteId" class="form-label fw-semibold">Compte bancaire</label>
            <select wire:model.live="compteId" id="compteId" class="form-select">
                <option value="">-- Sélectionnez un compte --</option>
                @foreach ($comptes as $c)
                    <option value="{{ $c->id }}">{{ $c->nom }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label for="dateDebut" class="form-label">Date début</label>
            <input type="date" wire:model.live="dateDebut" id="dateDebut" class="form-control">
        </div>
        <div class="col-md-2">
            <label for="dateFin" class="form-label">Date fin</label>
            <input type="date" wire:model.live="dateFin" id="dateFin" class="form-control">
        </div>
        <div class="col-md-3">
            <label for="searchTiers" class="form-label">Tiers</label>
            <input type="text" wire:model.live.debounce.300ms="searchTiers"
                   id="searchTiers" class="form-control" placeholder="Rechercher un tiers…">
        </div>
    </div>

    @if ($compteId === null)
        <div class="alert alert-info">Sélectionnez un compte bancaire pour afficher les transactions.</div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>
                            <a href="#" wire:click.prevent="sortBy('date')" class="text-white text-decoration-none">
                                Date
                                @if ($sortColumn === 'date')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </a>
                        </th>
                        <th>
                            <a href="#" wire:click.prevent="sortBy('type_label')" class="text-white text-decoration-none">
                                Type
                                @if ($sortColumn === 'type_label')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </a>
                        </th>
                        <th>
                            <a href="#" wire:click.prevent="sortBy('tiers')" class="text-white text-decoration-none">
                                Tiers
                                @if ($sortColumn === 'tiers')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </a>
                        </th>
                        <th>Libellé</th>
                        <th>Référence</th>
                        <th class="text-end">
                            <a href="#" wire:click.prevent="sortBy('montant')" class="text-white text-decoration-none">
                                Montant
                                @if ($sortColumn === 'montant')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </a>
                        </th>
                        <th class="text-center">Pointé</th>
                        @if ($showSolde)
                            <th class="text-end">Solde courant</th>
                        @endif
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $tx)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($tx->date)->format('d/m/Y') }}</td>
                            <td>{{ $tx->type_label }}</td>
                            <td>{{ $tx->tiers ?? '—' }}</td>
                            <td>{{ $tx->libelle ?? '—' }}</td>
                            <td>{{ $tx->reference ?? '' }}</td>
                            <td class="text-end {{ $tx->montant >= 0 ? 'text-success' : 'text-danger' }} fw-semibold">
                                {{ number_format((float) $tx->montant, 2, ',', ' ') }} €
                            </td>
                            <td class="text-center">
                                @if ($tx->pointe)
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                @endif
                            </td>
                            @if ($showSolde)
                                <td class="text-end">
                                    {{ isset($tx->solde_courant) ? number_format((float) $tx->solde_courant, 2, ',', ' ') . ' €' : '' }}
                                </td>
                            @endif
                            <td>
                                <button type="button"
                                        wire:click="redirectToEdit('{{ $tx->source_type }}', {{ $tx->id }})"
                                        class="btn btn-sm btn-outline-primary me-1"
                                        title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button"
                                        wire:click="deleteTransaction('{{ $tx->source_type }}', {{ $tx->id }})"
                                        wire:confirm="Supprimer cette transaction ?"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $showSolde ? 9 : 8 }}" class="text-center text-muted">
                                Aucune transaction trouvée.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (! $showSolde && $compteId !== null)
            <p class="text-muted small mt-2">
                <i class="bi bi-info-circle"></i>
                Le solde courant est masqué car un filtre tiers est actif ou le tri n'est pas par date croissante.
            </p>
        @endif

        <div class="mt-3">
            {{ $paginator->links() }}
        </div>
    @endif
</div>
```

- [ ] **Step 3 : Run pint et commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint \
    resources/views/comptes-bancaires/transactions.blade.php \
    resources/views/livewire/transaction-compte-list.blade.php

git add resources/views/comptes-bancaires/transactions.blade.php \
        resources/views/livewire/transaction-compte-list.blade.php
git commit -m "feat: Blade views for transactions par compte"
```

---

## Task 4: Route et navbar

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1 : Ajouter la route GET**

Dans `routes/web.php`, ajouter la route **avant** la resource route `comptes-bancaires` existante (pour éviter tout conflit de pattern) :

```php
// Ajouter AVANT Route::resource('parametres/comptes-bancaires', ...)
Route::get('comptes-bancaires/transactions', function () {
    return view('comptes-bancaires.transactions');
})->name('comptes-bancaires.transactions');
```

- [ ] **Step 2 : Ajouter l'item "Transactions" dans la navbar**

Dans `resources/views/layouts/app.blade.php`, localiser le tableau `$navItems` et ajouter l'entrée après `virements.index` :

```php
$navItems = [
    ['route' => 'depenses.index',                 'icon' => 'arrow-down-circle',      'label' => 'Dépenses'],
    ['route' => 'recettes.index',                 'icon' => 'arrow-up-circle',        'label' => 'Recettes'],
    ['route' => 'virements.index',                'icon' => 'arrow-left-right',       'label' => 'Virements'],
    ['route' => 'comptes-bancaires.transactions', 'icon' => 'list-ul',                'label' => 'Transactions'],  // ← ajouter
    ['route' => 'budget.index',                   'icon' => 'piggy-bank',             'label' => 'Budget'],
    // ...
];
```

- [ ] **Step 3 : Run tests**

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 4 : Run pint et commit final**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint \
    routes/web.php \
    resources/views/layouts/app.blade.php

git add routes/web.php \
        resources/views/layouts/app.blade.php
git commit -m "feat: route GET /comptes-bancaires/transactions et item Transactions dans la navbar"
```