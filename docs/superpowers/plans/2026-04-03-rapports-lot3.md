# Lot 3 — État de flux de trésorerie — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter un rapport "Flux de trésorerie" consolidé avec synthèse annuelle, bloc rapprochement, et tableau mensuel optionnel.

**Architecture:** Nouveau composant Livewire `RapportFluxTresorerie` + méthode `RapportService::fluxTresorerie()` qui extrait et consolide la logique de `ClotureWizard::computeFinancialSummary()`. Route dédiée, entrée navbar.

**Tech Stack:** Laravel 11, Livewire 3 (`#[Url]`), Bootstrap 5, Blade

**Spec:** `docs/superpowers/specs/2026-04-03-rapports-lot3-design.md`

---

## Task 1 : Méthode service `fluxTresorerie()`

**Files:**
- Modify: `app/Services/RapportService.php` — ajouter la méthode publique `fluxTresorerie()`
- Test: `tests/Feature/Services/RapportFluxTresorerieTest.php`

La méthode consolide tous les comptes bancaires (y compris système) et retourne synthèse + rapprochement + ventilation mensuelle.

Référence : la logique de calcul existe dans `app/Livewire/Exercices/ClotureWizard.php:93-193`.

- [ ] **Step 1 : Créer le fichier test avec les cas de base**

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\RapportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Exercice::create(['annee' => 2025, 'statut' => 'ouvert']);
    $this->compte = CompteBancaire::factory()->create([
        'solde_initial' => 10000.00,
        'date_solde_initial' => '2025-09-01',
        'est_systeme' => false,
    ]);
});

it('retourne la structure attendue', function () {
    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data)->toHaveKeys(['exercice', 'synthese', 'rapprochement', 'mensuel', 'ecritures_non_pointees']);
    expect($data['exercice'])->toHaveKeys(['annee', 'label', 'date_debut', 'date_fin', 'is_cloture', 'date_cloture']);
    expect($data['synthese'])->toHaveKeys(['solde_ouverture', 'total_recettes', 'total_depenses', 'variation', 'solde_theorique']);
    expect($data['rapprochement'])->toHaveKeys(['solde_theorique', 'recettes_non_pointees', 'nb_recettes_non_pointees', 'depenses_non_pointees', 'nb_depenses_non_pointees', 'solde_reel']);
    expect($data['mensuel'])->toHaveCount(12);
    expect($data['mensuel'][0])->toHaveKeys(['mois', 'recettes', 'depenses', 'solde', 'cumul']);
});

it('calcule la synthèse consolidée correctement', function () {
    // Recette en octobre 2025
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => 5000.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);
    // Dépense en novembre 2025
    Transaction::factory()->create([
        'type' => 'depense',
        'date' => '2025-11-20',
        'montant_total' => 2000.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['synthese']['total_recettes'])->toBe(5000.00);
    expect($data['synthese']['total_depenses'])->toBe(2000.00);
    expect($data['synthese']['variation'])->toBe(3000.00);
    // solde_ouverture = solde_reel - recettes + depenses = (10000 + 5000 - 2000) - 5000 + 2000 = 10000
    expect($data['synthese']['solde_ouverture'])->toBe(10000.00);
    expect($data['synthese']['solde_theorique'])->toBe(13000.00);
});

it('ventile les flux par mois', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => 3000.00,
        'compte_id' => $this->compte->id,
    ]);
    Transaction::factory()->create([
        'type' => 'depense',
        'date' => '2025-10-20',
        'montant_total' => 1000.00,
        'compte_id' => $this->compte->id,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    // Mois index 0 = septembre (pas de mouvement)
    expect($data['mensuel'][0]['recettes'])->toBe(0.0);
    expect($data['mensuel'][0]['depenses'])->toBe(0.0);
    expect($data['mensuel'][0]['cumul'])->toBe(10000.00); // solde ouverture

    // Mois index 1 = octobre
    expect($data['mensuel'][1]['recettes'])->toBe(3000.00);
    expect($data['mensuel'][1]['depenses'])->toBe(1000.00);
    expect($data['mensuel'][1]['solde'])->toBe(2000.00);
    expect($data['mensuel'][1]['cumul'])->toBe(12000.00); // 10000 + 2000
});

it('consolide plusieurs comptes et annule les virements internes', function () {
    $compte2 = CompteBancaire::factory()->create([
        'solde_initial' => 5000.00,
        'date_solde_initial' => '2025-09-01',
        'est_systeme' => false,
    ]);

    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 2000.00,
        'compte_id' => $this->compte->id,
    ]);

    // Virement interne : ne doit pas impacter le consolidé
    VirementInterne::create([
        'date' => '2025-10-15',
        'montant' => 1000.00,
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => $compte2->id,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    // Solde ouverture consolidé = 10000 + 5000 = 15000
    expect($data['synthese']['solde_ouverture'])->toBe(15000.00);
    // Recettes = 2000, dépenses = 0
    expect($data['synthese']['total_recettes'])->toBe(2000.00);
    expect($data['synthese']['total_depenses'])->toBe(0.0);
    expect($data['synthese']['solde_theorique'])->toBe(17000.00);
});

it('calcule le rapprochement avec écritures non pointées', function () {
    // Recette pointée (rapprochement_id non null)
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 3000.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => 999, // simule un rapprochement
    ]);
    // Recette non pointée
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => 1500.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);
    // Dépense non pointée
    Transaction::factory()->create([
        'type' => 'depense',
        'date' => '2025-11-01',
        'montant_total' => 500.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['rapprochement']['recettes_non_pointees'])->toBe(1500.00);
    expect($data['rapprochement']['nb_recettes_non_pointees'])->toBe(1);
    expect($data['rapprochement']['depenses_non_pointees'])->toBe(500.00);
    expect($data['rapprochement']['nb_depenses_non_pointees'])->toBe(1);
    // solde_reel = solde_theorique - recettes_non_pointees + depenses_non_pointees
    expect($data['rapprochement']['solde_reel'])->toBe($data['rapprochement']['solde_theorique'] - 1500.00 + 500.00);
});

it('exclut les comptes système du rapprochement', function () {
    $compteSys = CompteBancaire::factory()->create([
        'solde_initial' => 0,
        'date_solde_initial' => '2025-09-01',
        'est_systeme' => true,
    ]);

    // Transaction non pointée sur compte système — ne doit PAS compter dans le rapprochement
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 999.00,
        'compte_id' => $compteSys->id,
        'rapprochement_id' => null,
    ]);
    // Transaction non pointée sur compte réel — doit compter
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 500.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['rapprochement']['nb_recettes_non_pointees'])->toBe(1);
    expect($data['rapprochement']['recettes_non_pointees'])->toBe(500.00);
    // Mais la synthèse inclut bien le compte système
    expect($data['synthese']['total_recettes'])->toBe(1499.00);
});

it('expose la liste des écritures non pointées pour le PDF', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => 1500.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
        'libelle' => 'Cotisation Dupont',
        'numero_piece' => 'R-2025-042',
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['ecritures_non_pointees'])->toHaveCount(1);
    expect($data['ecritures_non_pointees'][0])->toHaveKeys(['numero_piece', 'date', 'tiers', 'libelle', 'type', 'montant']);
});

it('retourne les informations exercice avec statut clôturé', function () {
    $exercice = Exercice::where('annee', 2025)->first();
    $exercice->update(['statut' => 'cloture', 'date_cloture' => '2026-09-15 10:00:00']);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['exercice']['is_cloture'])->toBeTrue();
    expect($data['exercice']['date_cloture'])->toBe('15/09/2026');
});
```

- [ ] **Step 2 : Lancer les tests, vérifier qu'ils échouent**

```bash
./vendor/bin/sail test tests/Feature/Services/RapportFluxTresorerieTest.php --stop-on-failure
```

Attendu : FAIL — méthode `fluxTresorerie` n'existe pas.

- [ ] **Step 3 : Implémenter `fluxTresorerie()` dans `RapportService`**

Ajouter à la fin de `app/Services/RapportService.php`, avant l'accolade fermante de la classe :

```php
/**
 * État de flux de trésorerie consolidé.
 *
 * @return array{exercice: array, synthese: array, rapprochement: array, mensuel: list<array>, ecritures_non_pointees: list<array>}
 */
public function fluxTresorerie(int $exercice): array
{
    $exerciceService = app(ExerciceService::class);
    $soldeService = app(SoldeService::class);
    $range = $exerciceService->dateRange($exercice);
    $start = $range['start']->toDateString();
    $end = $range['end']->toDateString();

    // --- Exercice info ---
    $exerciceModel = Exercice::where('annee', $exercice)->first();
    $exerciceInfo = [
        'annee' => $exercice,
        'label' => $exerciceService->label($exercice),
        'date_debut' => $start,
        'date_fin' => $end,
        'is_cloture' => $exerciceModel?->isCloture() ?? false,
        'date_cloture' => $exerciceModel?->date_cloture?->format('d/m/Y'),
    ];

    // --- Solde d'ouverture consolidé (tous comptes, y compris système) ---
    $comptes = CompteBancaire::all();
    $soldeOuverture = 0.0;

    foreach ($comptes as $compte) {
        $soldeReel = $soldeService->solde($compte);
        $recettesCompte = (float) $compte->recettes()->forExercice($exercice)->sum('montant_total');
        $depensesCompte = (float) $compte->depenses()->forExercice($exercice)->sum('montant_total');
        $virementsIn = (float) VirementInterne::where('compte_destination_id', $compte->id)
            ->forExercice($exercice)->sum('montant');
        $virementsOut = (float) VirementInterne::where('compte_source_id', $compte->id)
            ->forExercice($exercice)->sum('montant');

        $soldeOuverture += $soldeReel - $recettesCompte + $depensesCompte - $virementsIn + $virementsOut;
    }
    $soldeOuverture = round($soldeOuverture, 2);

    // --- Totaux consolidés (virements s'annulent) ---
    $totalRecettes = round((float) Transaction::where('type', 'recette')->forExercice($exercice)->sum('montant_total'), 2);
    $totalDepenses = round((float) Transaction::where('type', 'depense')->forExercice($exercice)->sum('montant_total'), 2);
    $variation = round($totalRecettes - $totalDepenses, 2);
    $soldeTheorique = round($soldeOuverture + $variation, 2);

    // --- Rapprochement (comptes réels uniquement, pas les comptes système) ---
    $comptesReelsIds = CompteBancaire::where('est_systeme', false)->pluck('id');
    $nonPointees = Transaction::whereNull('rapprochement_id')
        ->whereIn('compte_id', $comptesReelsIds)
        ->whereBetween('date', [$start, $end])
        ->selectRaw("
            SUM(CASE WHEN type = 'recette' THEN montant_total ELSE 0 END) as total_recettes,
            SUM(CASE WHEN type = 'depense' THEN montant_total ELSE 0 END) as total_depenses,
            SUM(CASE WHEN type = 'recette' THEN 1 ELSE 0 END) as nb_recettes,
            SUM(CASE WHEN type = 'depense' THEN 1 ELSE 0 END) as nb_depenses
        ")
        ->first();

    $recettesNonPointees = round((float) ($nonPointees->total_recettes ?? 0), 2);
    $depensesNonPointees = round((float) ($nonPointees->total_depenses ?? 0), 2);
    $nbRecettesNonPointees = (int) ($nonPointees->nb_recettes ?? 0);
    $nbDepensesNonPointees = (int) ($nonPointees->nb_depenses ?? 0);
    $soldeReel = round($soldeTheorique - $recettesNonPointees + $depensesNonPointees, 2);

    // --- Ventilation mensuelle ---
    $mensuelRows = Transaction::forExercice($exercice)
        ->selectRaw("
            YEAR(date) as annee, MONTH(date) as mois_num,
            SUM(CASE WHEN type = 'recette' THEN montant_total ELSE 0 END) as recettes,
            SUM(CASE WHEN type = 'depense' THEN montant_total ELSE 0 END) as depenses
        ")
        ->groupByRaw('YEAR(date), MONTH(date)')
        ->get()
        ->keyBy(fn ($row) => $row->annee . '-' . str_pad((string) $row->mois_num, 2, '0', STR_PAD_LEFT));

    $mensuel = [];
    $cumul = $soldeOuverture;
    // 12 mois : septembre (mois 9) de l'année $exercice → août (mois 8) de l'année $exercice+1
    for ($i = 0; $i < 12; $i++) {
        $moisNum = (($i + 8) % 12) + 1; // 9,10,11,12,1,2,3,4,5,6,7,8
        $annee = $moisNum >= 9 ? $exercice : $exercice + 1;
        $key = $annee . '-' . str_pad((string) $moisNum, 2, '0', STR_PAD_LEFT);

        $recettes = round((float) ($mensuelRows[$key]->recettes ?? 0), 2);
        $depenses = round((float) ($mensuelRows[$key]->depenses ?? 0), 2);
        $solde = round($recettes - $depenses, 2);
        $cumul = round($cumul + $solde, 2);

        $moisLabel = ucfirst(\Carbon\Carbon::create($annee, $moisNum, 1)->translatedFormat('F Y'));

        $mensuel[] = [
            'mois' => $moisLabel,
            'recettes' => $recettes,
            'depenses' => $depenses,
            'solde' => $solde,
            'cumul' => $cumul,
        ];
    }

    // --- Liste écritures non pointées (pour PDF lot 4, comptes réels uniquement) ---
    $ecrituresNonPointees = Transaction::whereNull('rapprochement_id')
        ->whereIn('compte_id', $comptesReelsIds)
        ->whereBetween('date', [$start, $end])
        ->with('tiers')
        ->orderBy('date')
        ->get()
        ->map(fn (Transaction $t) => [
            'numero_piece' => $t->numero_piece,
            'date' => $t->date->format('d/m/Y'),
            'tiers' => $t->tiers?->displayName() ?? '—',
            'libelle' => $t->libelle,
            'type' => $t->type->value,
            'montant' => (float) $t->montant_total,
        ])
        ->values()
        ->all();

    return [
        'exercice' => $exerciceInfo,
        'synthese' => [
            'solde_ouverture' => $soldeOuverture,
            'total_recettes' => $totalRecettes,
            'total_depenses' => $totalDepenses,
            'variation' => $variation,
            'solde_theorique' => $soldeTheorique,
        ],
        'rapprochement' => [
            'solde_theorique' => $soldeTheorique,
            'recettes_non_pointees' => $recettesNonPointees,
            'nb_recettes_non_pointees' => $nbRecettesNonPointees,
            'depenses_non_pointees' => $depensesNonPointees,
            'nb_depenses_non_pointees' => $nbDepensesNonPointees,
            'solde_reel' => $soldeReel,
        ],
        'mensuel' => $mensuel,
        'ecritures_non_pointees' => $ecrituresNonPointees,
    ];
}
```

N'oublier pas les imports en haut du fichier s'ils ne sont pas déjà présents :

```php
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\VirementInterne;
use App\Services\SoldeService;
```

- [ ] **Step 4 : Lancer les tests, vérifier qu'ils passent**

```bash
./vendor/bin/sail test tests/Feature/Services/RapportFluxTresorerieTest.php --stop-on-failure
```

Attendu : tous les tests passent.

- [ ] **Step 5 : Commit**

```bash
git add app/Services/RapportService.php tests/Feature/Services/RapportFluxTresorerieTest.php
git commit -m "feat(rapports): méthode RapportService::fluxTresorerie() avec tests"
```

---

## Task 2 : Composant Livewire `RapportFluxTresorerie`

**Files:**
- Create: `app/Livewire/RapportFluxTresorerie.php`
- Test: `tests/Feature/Livewire/RapportFluxTresorerieTest.php`

Composant simple qui appelle le service et passe les données à la vue. Un toggle `#[Url]` pour les flux mensuels.

- [ ] **Step 1 : Créer le test du composant**

```php
<?php

declare(strict_types=1);

use App\Livewire\RapportFluxTresorerie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(\App\Models\User::factory()->create());
    Exercice::create(['annee' => 2025, 'statut' => 'ouvert']);
    CompteBancaire::factory()->create([
        'solde_initial' => 10000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
});

it('affiche le composant avec la synthèse', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 5000.00,
        'compte_id' => CompteBancaire::first()->id,
    ]);

    Livewire::test(RapportFluxTresorerie::class)
        ->assertSee('Flux de trésorerie')
        ->assertSee('Rapport provisoire')
        ->assertSee('5 000,00')   // recettes
        ->assertSee('10 000,00'); // solde ouverture
});

it('masque le tableau mensuel par défaut', function () {
    Livewire::test(RapportFluxTresorerie::class)
        ->assertDontSee('Septembre 2025');
});

it('affiche le tableau mensuel quand le toggle est activé', function () {
    Livewire::test(RapportFluxTresorerie::class)
        ->set('fluxMensuels', true)
        ->assertSee('Septembre 2025');
});

it('affiche rapport définitif quand exercice clôturé', function () {
    Exercice::where('annee', 2025)->update([
        'statut' => 'cloture',
        'date_cloture' => '2026-09-15 10:00:00',
    ]);

    Livewire::test(RapportFluxTresorerie::class)
        ->assertSee('Rapport définitif')
        ->assertSee('15/09/2026');
});

it('persiste fluxMensuels dans URL', function () {
    Livewire::test(RapportFluxTresorerie::class)
        ->set('fluxMensuels', true)
        ->assertSet('fluxMensuels', true);
});
```

- [ ] **Step 2 : Lancer les tests, vérifier qu'ils échouent**

```bash
./vendor/bin/sail test tests/Feature/Livewire/RapportFluxTresorerieTest.php --stop-on-failure
```

Attendu : FAIL — classe `RapportFluxTresorerie` n'existe pas.

- [ ] **Step 3 : Créer le composant Livewire**

Créer `app/Livewire/RapportFluxTresorerie.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RapportFluxTresorerie extends Component
{
    #[Url(as: 'mensuel')]
    public bool $fluxMensuels = false;

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $data = app(RapportService::class)->fluxTresorerie($exercice);

        return view('livewire.rapport-flux-tresorerie', $data);
    }
}
```

- [ ] **Step 4 : Créer la vue Blade du composant**

Créer `resources/views/livewire/rapport-flux-tresorerie.blade.php` :

```blade
<div>
    {{-- Réutilise les styles CR existants + styles spécifiques trésorerie --}}
    <style>
        .ft-row td { padding: 10px 16px; font-size: 14px; border-bottom: 1px solid #e2e8f0; }
        .ft-row-label { font-weight: 600; color: #1e3a5f; }
        .ft-row-indent { padding-left: 32px !important; color: #555; }
        .ft-row-separator td { padding: 0; border-bottom: 3px double #3d5473; }
        .ft-row-result td { background: #3d5473; color: #fff; font-weight: 700; font-size: 15px; padding: 12px 16px; border-bottom: none; }
        .ft-row-rapprochement td { background: #f7f9fc; padding: 8px 16px; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
        .ft-row-rapprochement-result td { background: #dce6f0; font-weight: 700; padding: 10px 16px; font-size: 14px; }
        .ft-mensuel-header th { background: #3d5473; color: #fff; font-weight: 400; font-size: 12px; padding: 8px 12px; border: none; }
        .ft-mensuel-row td { padding: 6px 12px; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
        .ft-mensuel-total td { background: #5a7fa8; color: #fff; font-weight: 700; font-size: 13px; padding: 9px 12px; border: none; }
    </style>

    @php
        $fmt = fn(float $v): string => number_format($v, 2, ',', ' ') . ' €';
    @endphp

    {{-- Bandeau statut --}}
    @if ($exercice['is_cloture'])
        <div class="alert alert-success mb-3">
            <i class="bi bi-check-circle me-1"></i>
            Rapport définitif — Exercice {{ $exercice['label'] }} clôturé le {{ $exercice['date_cloture'] }}
        </div>
    @else
        <div class="alert alert-info mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Rapport provisoire — Exercice {{ $exercice['label'] }} en cours
        </div>
    @endif

    {{-- Toggle flux mensuels --}}
    <div class="d-flex justify-content-end mb-3">
        <div class="form-check form-switch">
            <input type="checkbox" wire:model.live="fluxMensuels" class="form-check-input" id="toggleMensuel">
            <label class="form-check-label small" for="toggleMensuel">Flux mensuels</label>
        </div>
    </div>

    {{-- Section 1 : Synthèse annuelle --}}
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="border-collapse:collapse;width:100%;">
                <tbody>
                    <tr class="ft-row">
                        <td class="ft-row-label">Solde de trésorerie au {{ \Carbon\Carbon::parse($exercice['date_debut'])->translatedFormat('j F Y') }}</td>
                        <td class="text-end" style="width:180px;">{{ $fmt($synthese['solde_ouverture']) }}</td>
                    </tr>
                    <tr class="ft-row">
                        <td class="ft-row-label"><span class="text-success">+</span> Encaissements (recettes)</td>
                        <td class="text-end">{{ $fmt($synthese['total_recettes']) }}</td>
                    </tr>
                    <tr class="ft-row">
                        <td class="ft-row-label"><span class="text-danger">−</span> Décaissements (dépenses)</td>
                        <td class="text-end">{{ $fmt($synthese['total_depenses']) }}</td>
                    </tr>
                    <tr class="ft-row">
                        <td class="ft-row-label">= Variation de trésorerie</td>
                        <td class="text-end fw-bold {{ $synthese['variation'] >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $synthese['variation'] >= 0 ? '+' : '' }}{{ $fmt($synthese['variation']) }}
                        </td>
                    </tr>
                    <tr class="ft-row-separator"><td colspan="2"></td></tr>
                    <tr class="ft-row-result">
                        <td>Solde de trésorerie théorique au {{ \Carbon\Carbon::parse($exercice['date_fin'])->translatedFormat('j F Y') }}</td>
                        <td class="text-end">{{ $fmt($synthese['solde_theorique']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Section Rapprochement --}}
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="border-collapse:collapse;width:100%;">
                <tbody>
                    <tr style="background:#3d5473;color:#fff;">
                        <td colspan="2" style="font-weight:700;font-size:14px;padding:8px 16px;border:none;">Rapprochement bancaire</td>
                    </tr>
                    <tr class="ft-row-rapprochement">
                        <td>Solde théorique</td>
                        <td class="text-end" style="width:180px;">{{ $fmt($rapprochement['solde_theorique']) }}</td>
                    </tr>
                    <tr class="ft-row-rapprochement">
                        <td class="ft-row-indent">
                            <span class="text-danger">−</span> Recettes non pointées
                            <span class="text-muted">({{ $rapprochement['nb_recettes_non_pointees'] }} {{ Str::plural('écriture', $rapprochement['nb_recettes_non_pointees']) }})</span>
                        </td>
                        <td class="text-end">{{ $fmt($rapprochement['recettes_non_pointees']) }}</td>
                    </tr>
                    <tr class="ft-row-rapprochement">
                        <td class="ft-row-indent">
                            <span class="text-success">+</span> Dépenses non pointées
                            <span class="text-muted">({{ $rapprochement['nb_depenses_non_pointees'] }} {{ Str::plural('écriture', $rapprochement['nb_depenses_non_pointees']) }})</span>
                        </td>
                        <td class="text-end">{{ $fmt($rapprochement['depenses_non_pointees']) }}</td>
                    </tr>
                    <tr class="ft-row-rapprochement-result">
                        <td>= Solde bancaire réel</td>
                        <td class="text-end">{{ $fmt($rapprochement['solde_reel']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Section 2 : Tableau mensuel (conditionnel) --}}
    @if ($fluxMensuels)
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="border-collapse:collapse;width:100%;">
                <thead>
                    <tr class="ft-mensuel-header">
                        <th>Mois</th>
                        <th class="text-end" style="width:140px;">Recettes</th>
                        <th class="text-end" style="width:140px;">Dépenses</th>
                        <th class="text-end" style="width:140px;">Solde (R-D)</th>
                        <th class="text-end" style="width:160px;">Trésorerie cumulée</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mensuel as $ligne)
                        <tr class="ft-mensuel-row">
                            <td>{{ $ligne['mois'] }}</td>
                            <td class="text-end">{{ $fmt($ligne['recettes']) }}</td>
                            <td class="text-end">{{ $fmt($ligne['depenses']) }}</td>
                            <td class="text-end {{ $ligne['solde'] >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $ligne['solde'] >= 0 ? '+' : '' }}{{ $fmt($ligne['solde']) }}
                            </td>
                            <td class="text-end fw-bold">{{ $fmt($ligne['cumul']) }}</td>
                        </tr>
                    @endforeach
                    @php
                        $totalR = collect($mensuel)->sum('recettes');
                        $totalD = collect($mensuel)->sum('depenses');
                        $totalS = round($totalR - $totalD, 2);
                    @endphp
                    <tr class="ft-mensuel-total">
                        <td>Total</td>
                        <td class="text-end">{{ $fmt($totalR) }}</td>
                        <td class="text-end">{{ $fmt($totalD) }}</td>
                        <td class="text-end">{{ $totalS >= 0 ? '+' : '' }}{{ $fmt($totalS) }}</td>
                        <td class="text-end">{{ $fmt(end($mensuel)['cumul']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
```

- [ ] **Step 5 : Lancer les tests, vérifier qu'ils passent**

```bash
./vendor/bin/sail test tests/Feature/Livewire/RapportFluxTresorerieTest.php --stop-on-failure
```

Attendu : tous les tests passent.

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/RapportFluxTresorerie.php resources/views/livewire/rapport-flux-tresorerie.blade.php tests/Feature/Livewire/RapportFluxTresorerieTest.php
git commit -m "feat(rapports): composant Livewire RapportFluxTresorerie avec vue et tests"
```

---

## Task 3 : Route, page layout et navbar

**Files:**
- Create: `resources/views/rapports/flux-tresorerie.blade.php`
- Modify: `routes/web.php:97-101` — ajouter la route
- Modify: `resources/views/layouts/app.blade.php:249-268` — ajouter l'entrée menu

- [ ] **Step 1 : Créer la page layout**

Créer `resources/views/rapports/flux-tresorerie.blade.php` (même pattern que `rapports/compte-resultat.blade.php`) :

```blade
<x-app-layout>
    <h1 class="mb-4">Flux de trésorerie</h1>
    <livewire:rapport-flux-tresorerie />
</x-app-layout>
```

- [ ] **Step 2 : Ajouter la route**

Dans `routes/web.php`, après la ligne `Route::view('/rapports/operations', 'rapports.operations')->name('rapports.operations');` (ligne 99), ajouter :

```php
Route::view('/rapports/flux-tresorerie', 'rapports.flux-tresorerie')->name('rapports.flux-tresorerie');
```

- [ ] **Step 3 : Ajouter l'entrée dans le dropdown navbar**

Dans `resources/views/layouts/app.blade.php`, après le bloc du lien "Compte de résultat par opérations" (après la ligne 260 `</li>`) et avant le `<li><hr class="dropdown-divider">`, ajouter :

```blade
<li>
    <a class="dropdown-item {{ request()->routeIs('compta.rapports.flux-tresorerie') ? 'active' : '' }}"
       href="{{ route('compta.rapports.flux-tresorerie') }}">
        <i class="bi bi-cash-stack me-1"></i>Flux de trésorerie
    </a>
</li>
```

- [ ] **Step 4 : Tester l'accès à la page**

```bash
./vendor/bin/sail test --filter="flux" 2>/dev/null; echo "---"; ./vendor/bin/sail artisan route:list --name=rapports
```

Vérifier que la route `compta.rapports.flux-tresorerie` apparaît dans la liste.

- [ ] **Step 5 : Commit**

```bash
git add resources/views/rapports/flux-tresorerie.blade.php routes/web.php resources/views/layouts/app.blade.php
git commit -m "feat(rapports): route, page et navbar pour flux de trésorerie"
```

---

## Task 4 : Vérification visuelle et Pint

**Files:**
- Tous les fichiers modifiés/créés

- [ ] **Step 1 : Lancer tous les tests du projet**

```bash
./vendor/bin/sail test --stop-on-failure
```

Attendu : tous les tests passent (pas de régression).

- [ ] **Step 2 : Appliquer le formatage Pint**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint
```

- [ ] **Step 3 : Commit le formatage si nécessaire**

```bash
git diff --name-only
```

Si des fichiers sont modifiés :

```bash
git add -A && git commit -m "style: apply Pint formatting"
```

- [ ] **Step 4 : Vérification visuelle**

Naviguer sur `http://localhost/compta/rapports/flux-tresorerie` et vérifier :
1. Le bandeau de statut "Rapport provisoire" s'affiche
2. La synthèse annuelle montre les bons montants
3. Le bloc rapprochement s'affiche avec les écritures non pointées
4. La case "Flux mensuels" affiche/masque le tableau mensuel
5. Le tableau mensuel a 12 lignes + ligne total
6. Le lien apparaît dans le dropdown Rapports de la navbar
7. Cocher "Flux mensuels" persiste dans l'URL (`?mensuel=1`)

**Demander à l'utilisateur de valider visuellement avant de pusher.**
