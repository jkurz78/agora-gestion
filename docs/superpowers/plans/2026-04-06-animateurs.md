# Écran Animateurs — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter un onglet "Animateurs" sur la page opération, avec une matrice séances × tiers montrant les factures reçues et permettant leur saisie simplifiée.

**Architecture:** Un composant Livewire `AnimateurManager` embarqué dans l'onglet existant de `OperationDetail`. Les animateurs sont déduits des transactions (dépenses) liées à l'opération — pas de table dédiée. La matrice joint `transaction_lignes.seance` (entier) avec `seances.numero` via un LEFT JOIN sur 2 colonnes. La saisie crée des transactions via `TransactionService::create()`.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 (CDN), Pest PHP

**Spec:** `docs/superpowers/specs/2026-04-06-animateurs-design.md`

---

## Fichiers

| Action | Fichier | Rôle |
|--------|---------|------|
| Créer | `app/Livewire/AnimateurManager.php` | Composant principal : data loading, modal, save |
| Créer | `resources/views/livewire/animateur-manager.blade.php` | Matrice + sélecteur tiers |
| Créer | `resources/views/livewire/animateur-manager-modal.blade.php` | Modale saisie/édition transaction |
| Modifier | `resources/views/livewire/operation-detail.blade.php` | Ajouter l'onglet "Animateurs" |
| Créer | `tests/Feature/Livewire/AnimateurManagerTest.php` | Tests Pest |

---

### Task 1 : Composant skeleton + onglet

**Files:**
- Create: `app/Livewire/AnimateurManager.php`
- Create: `resources/views/livewire/animateur-manager.blade.php`
- Modify: `resources/views/livewire/operation-detail.blade.php`

- [ ] **Step 1: Créer le composant Livewire**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use Livewire\Component;

final class AnimateurManager extends Component
{
    public Operation $operation;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    public function render(): mixed
    {
        return view('livewire.animateur-manager');
    }
}
```

- [ ] **Step 2: Créer la vue blade placeholder**

```blade
{{-- resources/views/livewire/animateur-manager.blade.php --}}
<div>
    <div class="card">
        <div class="card-body">
            <p class="text-muted">Matrice animateurs — en construction</p>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Ajouter l'onglet dans operation-detail.blade.php**

Dans la nav tabs, ajouter le bouton **juste après** le bouton "Participants" :

```blade
<li class="nav-item">
    <button class="nav-link {{ $activeTab === 'animateurs' ? 'active' : '' }}" wire:click="setTab('animateurs')">
        <i class="bi bi-person-workspace me-1"></i>Animateurs
    </button>
</li>
```

Dans le contenu des tabs, ajouter le bloc **juste après** le bloc `@if($activeTab === 'participants')` :

```blade
@if($activeTab === 'animateurs')
    <livewire:animateur-manager :operation="$operation" :key="'am-'.$operation->id" />
@endif
```

- [ ] **Step 4: Vérifier le rendu**

Run: `php artisan view:clear`

Ouvrir une opération dans le navigateur → l'onglet "Animateurs" doit apparaître et afficher le placeholder.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/AnimateurManager.php resources/views/livewire/animateur-manager.blade.php resources/views/livewire/operation-detail.blade.php
git commit -m "feat(animateurs): skeleton composant + onglet sur OperationDetail"
```

---

### Task 2 : Chargement des données matricielles

**Files:**
- Modify: `app/Livewire/AnimateurManager.php`

- [ ] **Step 1: Ajouter la méthode de chargement dans AnimateurManager**

Remplacer la méthode `render()` et ajouter `buildMatrixData()` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TypeTransaction;
use App\Models\Operation;
use App\Models\TransactionLigne;
use Livewire\Component;

final class AnimateurManager extends Component
{
    public Operation $operation;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    public function render(): mixed
    {
        $seances = $this->operation->seances()->orderBy('numero')->get();

        $matrixData = $this->buildMatrixData($seances);

        return view('livewire.animateur-manager', [
            'seances' => $seances,
            'animateurs' => $matrixData['animateurs'],
            'seanceTotals' => $matrixData['seanceTotals'],
            'grandTotal' => $matrixData['grandTotal'],
        ]);
    }

    private function buildMatrixData($seances): array
    {
        $lignes = TransactionLigne::where('operation_id', $this->operation->id)
            ->whereHas('transaction', fn ($q) => $q->where('type', TypeTransaction::Depense))
            ->with(['transaction.tiers', 'sousCategorie'])
            ->get();

        $animateurs = [];
        $seanceTotals = [];
        $grandTotal = 0.0;

        foreach ($lignes as $ligne) {
            $tiersId = $ligne->transaction->tiers_id;
            if (! $tiersId) {
                continue;
            }
            $seanceNum = $ligne->seance; // integer or null
            $scId = $ligne->sous_categorie_id;
            $montant = (float) $ligne->montant;

            // Initialiser l'animateur
            if (! isset($animateurs[$tiersId])) {
                $animateurs[$tiersId] = [
                    'tiers' => $ligne->transaction->tiers,
                    'sousCategories' => [],
                    'seanceTotals' => [],
                    'total' => 0.0,
                ];
            }

            // Initialiser la sous-catégorie
            if (! isset($animateurs[$tiersId]['sousCategories'][$scId])) {
                $animateurs[$tiersId]['sousCategories'][$scId] = [
                    'sousCategorie' => $ligne->sousCategorie,
                    'seances' => [],
                    'transactionIds' => [],
                    'total' => 0.0,
                ];
            }

            // Accumuler le montant
            $key = $seanceNum ?? 'null';
            $animateurs[$tiersId]['sousCategories'][$scId]['seances'][$key]
                = ($animateurs[$tiersId]['sousCategories'][$scId]['seances'][$key] ?? 0.0) + $montant;
            $animateurs[$tiersId]['sousCategories'][$scId]['transactionIds'][$key][]
                = $ligne->transaction_id;
            $animateurs[$tiersId]['sousCategories'][$scId]['total'] += $montant;

            // Totaux par animateur × séance
            $animateurs[$tiersId]['seanceTotals'][$key]
                = ($animateurs[$tiersId]['seanceTotals'][$key] ?? 0.0) + $montant;
            $animateurs[$tiersId]['total'] += $montant;

            // Totaux par séance (toutes animateurs)
            $seanceTotals[$key] = ($seanceTotals[$key] ?? 0.0) + $montant;
            $grandTotal += $montant;
        }

        // Trier les animateurs par nom
        uasort($animateurs, fn ($a, $b) => strcmp(
            mb_strtolower($a['tiers']->nom ?? ''),
            mb_strtolower($b['tiers']->nom ?? '')
        ));

        return compact('animateurs', 'seanceTotals', 'grandTotal');
    }
}
```

- [ ] **Step 2: Vérifier que le composant charge sans erreur**

Run: `./vendor/bin/sail artisan tinker --execute="app(\App\Livewire\AnimateurManager::class)"`

Ouvrir une opération avec des dépenses liées → pas d'erreur PHP.

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/AnimateurManager.php
git commit -m "feat(animateurs): chargement données matricielles depuis transactions"
```

---

### Task 3 : Template blade de la matrice

**Files:**
- Modify: `resources/views/livewire/animateur-manager.blade.php`

- [ ] **Step 1: Écrire le template complet de la matrice**

```blade
{{-- resources/views/livewire/animateur-manager.blade.php --}}
<div>
    @php
        $fmt = fn ($v) => $v ? number_format((float) $v, 2, ',', "\u{202F}") . ' €' : '—';
    @endphp

    <div class="table-responsive">
        <table class="table table-bordered table-sm mb-0"
               style="font-size:12px;table-layout:fixed;width:{{ 220 + ($seances->count() * 120) + 100 }}px">
            {{-- EN-TÊTE --}}
            <thead>
                <tr style="background:#3d5473;color:#fff">
                    <td style="position:sticky;left:0;z-index:2;background:#3d5473;width:220px;font-weight:600">
                        Animateur
                    </td>
                    @foreach ($seances as $seance)
                        <td style="width:120px;text-align:center;font-weight:600">
                            S{{ $seance->numero }}
                        </td>
                    @endforeach
                    <td style="width:100px;text-align:center;font-weight:700">Total</td>
                </tr>
            </thead>

            <tbody>
                @forelse ($animateurs as $tiersId => $data)
                    {{-- LIGNE PARENT : nom animateur + totaux --}}
                    <tr style="font-weight:600;background:#f8f9fa">
                        <td style="position:sticky;left:0;z-index:1;background:#f8f9fa">
                            {{ $data['tiers']->displayName() }}
                        </td>
                        @foreach ($seances as $seance)
                            <td style="text-align:right">
                                @if (($data['seanceTotals'][$seance->numero] ?? 0) > 0)
                                    {{ $fmt($data['seanceTotals'][$seance->numero]) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                                <span class="text-success ms-1" style="cursor:pointer;font-size:14px"
                                      wire:click="openCreateModal({{ $tiersId }}, {{ $seance->numero }})"
                                      title="Saisir une facture">⊕</span>
                            </td>
                        @endforeach
                        <td style="text-align:right;font-weight:700">
                            {{ $fmt($data['total']) }}
                        </td>
                    </tr>

                    {{-- SOUS-LIGNES : par sous-catégorie --}}
                    @foreach ($data['sousCategories'] as $scId => $scData)
                        <tr style="font-size:11px;color:#666">
                            <td style="position:sticky;left:0;z-index:1;background:#fff;padding-left:24px">
                                {{ $scData['sousCategorie']->nom }}
                            </td>
                            @foreach ($seances as $seance)
                                @php
                                    $montant = $scData['seances'][$seance->numero] ?? null;
                                    $txIds = $scData['transactionIds'][$seance->numero] ?? [];
                                @endphp
                                <td style="text-align:right">
                                    @if ($montant)
                                        <span style="cursor:pointer;text-decoration:underline dotted"
                                              wire:click="openEditModal({{ json_encode(array_values(array_unique($txIds))) }})"
                                              title="Voir / modifier">
                                            {{ $fmt($montant) }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td style="text-align:right">{{ $fmt($scData['total']) }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="{{ $seances->count() + 2 }}" class="text-center text-muted py-3">
                            Aucune facture d'animateur enregistrée pour cette opération.
                        </td>
                    </tr>
                @endforelse
            </tbody>

            {{-- PIED : totaux par séance --}}
            @if (count($animateurs) > 0)
                <tfoot>
                    <tr style="background:#e9ecef;font-weight:700;font-size:12px">
                        <td style="position:sticky;left:0;z-index:1;background:#e9ecef">Total</td>
                        @foreach ($seances as $seance)
                            <td style="text-align:right">
                                {{ $fmt($seanceTotals[$seance->numero] ?? null) }}
                            </td>
                        @endforeach
                        <td style="text-align:right">{{ $fmt($grandTotal) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    {{-- SÉLECTEUR TIERS : ajouter un nouvel animateur --}}
    <div class="mt-3 d-flex align-items-center gap-2" style="max-width:400px">
        <label class="form-label mb-0 text-nowrap small">Nouvel animateur :</label>
        <livewire:tiers-autocomplete
            wire:model="newTiersId"
            filtre="depenses"
            :key="'animateur-tiers-ac-'.$operation->id"
        />
    </div>

    {{-- MODALE DE SAISIE / ÉDITION --}}
    @include('livewire.animateur-manager-modal')
</div>
```

- [ ] **Step 2: Créer le partial de la modale (fichier séparé pour lisibilité)**

Créer `resources/views/livewire/animateur-manager-modal.blade.php` :

```blade
{{-- resources/views/livewire/animateur-manager-modal.blade.php --}}
@if ($showModal)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        {{ $isEditing ? 'Modifier la transaction' : 'Saisir une facture d\'animation' }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                </div>
                <div class="modal-body">
                    {{-- Tiers (lecture seule) --}}
                    <div class="mb-3">
                        <label class="form-label fw-bold">Tiers</label>
                        <input type="text" class="form-control" value="{{ $modalTiersLabel }}" disabled>
                    </div>

                    <div class="row mb-3">
                        {{-- Date --}}
                        <div class="col-md-4">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control @error('modalDate') is-invalid @enderror"
                                   wire:model="modalDate">
                            @error('modalDate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        {{-- Référence --}}
                        <div class="col-md-4">
                            <label class="form-label">N° facture <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('modalReference') is-invalid @enderror"
                                   wire:model="modalReference" placeholder="Réf. facture">
                            @error('modalReference') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        {{-- Mode de paiement --}}
                        <div class="col-md-4">
                            <label class="form-label">Mode de paiement</label>
                            <select class="form-select" wire:model="modalModePaiement">
                                <option value="">—</option>
                                <option value="virement">Virement</option>
                                <option value="cheque">Chèque</option>
                                <option value="especes">Espèces</option>
                                <option value="cb">CB</option>
                                <option value="prelevement">Prélèvement</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        {{-- Compte bancaire --}}
                        <div class="col-md-4">
                            <label class="form-label">Compte bancaire</label>
                            <select class="form-select" wire:model="modalCompteId">
                                <option value="">—</option>
                                @foreach (\App\Models\CompteBancaire::orderBy('libelle')->get() as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->libelle }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Lignes de transaction --}}
                    <label class="form-label fw-bold">Lignes</label>
                    @error('modalLignes') <div class="text-danger small mb-1">{{ $message }}</div> @enderror

                    <table class="table table-sm table-bordered" style="font-size:13px">
                        <thead class="table-light">
                            <tr>
                                <th style="width:25%">Opération</th>
                                <th style="width:15%">Séance</th>
                                <th style="width:30%">Sous-catégorie</th>
                                <th style="width:20%">Montant</th>
                                <th style="width:10%"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($modalLignes as $index => $ligne)
                                <tr wire:key="modal-ligne-{{ $index }}">
                                    {{-- Opération --}}
                                    <td>
                                        <select class="form-select form-select-sm"
                                                wire:model.live="modalLignes.{{ $index }}.operation_id">
                                            <option value="">-- Aucune --</option>
                                            @foreach ($modalOperations as $op)
                                                <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    {{-- Séance --}}
                                    <td>
                                        @php
                                            $opId = $ligne['operation_id'] ?? '';
                                            $selectedOp = $opId !== '' ? $modalOperations->firstWhere('id', (int) $opId) : null;
                                            $nbSeances = $selectedOp?->nombre_seances;
                                        @endphp
                                        @if ($nbSeances)
                                            <select class="form-select form-select-sm"
                                                    wire:model="modalLignes.{{ $index }}.seance">
                                                <option value="">--</option>
                                                @for ($s = 1; $s <= $nbSeances; $s++)
                                                    <option value="{{ $s }}">{{ $s }}</option>
                                                @endfor
                                            </select>
                                        @endif
                                    </td>
                                    {{-- Sous-catégorie --}}
                                    <td>
                                        <livewire:sous-categorie-autocomplete
                                            :key="'sc-anim-'.$index.'-'.($editingTransactionId ?? 'new')"
                                            wire:model="modalLignes.{{ $index }}.sous_categorie_id"
                                            filtre="depense"
                                        />
                                    </td>
                                    {{-- Montant --}}
                                    <td>
                                        <input type="number" step="0.01" min="0.01"
                                               class="form-control form-control-sm text-end
                                                      @error('modalLignes.'.$index.'.montant') is-invalid @enderror"
                                               wire:model="modalLignes.{{ $index }}.montant"
                                               placeholder="0,00">
                                    </td>
                                    {{-- Supprimer --}}
                                    <td class="text-center">
                                        @if (count($modalLignes) > 1)
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="removeModalLigne({{ $index }})">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            wire:click="addModalLigne">
                                        <i class="bi bi-plus-lg me-1"></i>Ajouter une ligne
                                    </button>
                                </td>
                                <td class="text-end fw-bold" style="font-size:13px">
                                    {{ number_format(collect($modalLignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2, ',', "\u{202F}") }} €
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeModal">Annuler</button>
                    <button type="button" class="btn btn-primary" wire:click="saveTransaction">
                        <span wire:loading wire:target="saveTransaction" class="spinner-border spinner-border-sm me-1"></span>
                        {{ $isEditing ? 'Mettre à jour' : 'Enregistrer' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
```

- [ ] **Step 3: Vérifier le rendu visuel**

Ouvrir une opération ayant des séances et des dépenses → la matrice doit afficher les données. Si pas de dépenses, le message "Aucune facture" doit s'afficher.

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/animateur-manager.blade.php resources/views/livewire/animateur-manager-modal.blade.php
git commit -m "feat(animateurs): template matrice séances × animateurs + modale"
```

---

### Task 4 : Logique de la modale (création + édition)

**Files:**
- Modify: `app/Livewire/AnimateurManager.php`

- [ ] **Step 1: Ajouter les propriétés et méthodes de la modale**

Ajouter dans `AnimateurManager.php` les propriétés, les méthodes d'ouverture/fermeture, et la méthode de sauvegarde. Voici le composant **complet** mis à jour :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutOperation;
use App\Enums\TypeTransaction;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use App\Services\TransactionService;
use Illuminate\Support\Collection;
use Livewire\Component;

final class AnimateurManager extends Component
{
    public Operation $operation;

    // Modal state
    public bool $showModal = false;
    public bool $isEditing = false;
    public ?int $editingTransactionId = null;
    public ?int $modalTiersId = null;
    public string $modalTiersLabel = '';
    public string $modalDate = '';
    public string $modalReference = '';
    public ?string $modalModePaiement = null;
    public array $modalLignes = [];

    // New animateur
    public ?int $newTiersId = null;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    // ── Ouverture modale : création ──

    public function openCreateModal(int $tiersId, ?int $seanceNum = null): void
    {
        $tiers = Tiers::findOrFail($tiersId);

        $this->isEditing = false;
        $this->editingTransactionId = null;
        $this->modalTiersId = $tiersId;
        $this->modalTiersLabel = $tiers->displayName();
        $this->modalDate = now()->format('Y-m-d');
        $this->modalReference = '';
        $this->modalModePaiement = null;
        $this->modalLignes = [
            $this->newLigne($this->operation->id, $seanceNum),
        ];
        $this->showModal = true;
    }

    // ── Ouverture modale : édition ──

    public function openEditModal(array $transactionIds): void
    {
        // Ouvrir la première transaction (cas le plus courant = une seule)
        $transaction = Transaction::with('lignes.sousCategorie', 'lignes.operation', 'tiers')
            ->findOrFail($transactionIds[0]);

        $this->isEditing = true;
        $this->editingTransactionId = $transaction->id;
        $this->modalTiersId = $transaction->tiers_id;
        $this->modalTiersLabel = $transaction->tiers?->displayName() ?? '—';
        $this->modalDate = $transaction->date->format('Y-m-d');
        $this->modalReference = $transaction->reference ?? '';
        $this->modalModePaiement = $transaction->mode_paiement?->value;
        $this->modalCompteId = $transaction->compte_id;
        $this->modalLignes = $transaction->lignes->map(fn (TransactionLigne $l) => [
            'id' => $l->id,
            'operation_id' => (string) ($l->operation_id ?? ''),
            'seance' => (string) ($l->seance ?? ''),
            'sous_categorie_id' => (string) ($l->sous_categorie_id ?? ''),
            'montant' => (string) $l->montant,
        ])->toArray();

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetValidation();
    }

    // ── Gestion des lignes ──

    public function addModalLigne(): void
    {
        $this->modalLignes[] = $this->newLigne($this->operation->id);
    }

    public function removeModalLigne(int $index): void
    {
        unset($this->modalLignes[$index]);
        $this->modalLignes = array_values($this->modalLignes);
    }

    private function newLigne(?int $operationId = null, ?int $seanceNum = null): array
    {
        return [
            'id' => null,
            'operation_id' => (string) ($operationId ?? ''),
            'seance' => (string) ($seanceNum ?? ''),
            'sous_categorie_id' => '',
            'montant' => '',
        ];
    }

    // ── Sauvegarde ──

    public function saveTransaction(): void
    {
        $this->validate([
            'modalDate' => ['required', 'date'],
            'modalReference' => ['required', 'string', 'max:100'],
            'modalLignes' => ['required', 'array', 'min:1'],
            'modalLignes.*.sous_categorie_id' => ['required', 'exists:sous_categories,id'],
            'modalLignes.*.montant' => ['required', 'numeric', 'min:0.01'],
        ], [
            'modalDate.required' => 'La date est obligatoire.',
            'modalReference.required' => 'Le numéro de facture est obligatoire.',
            'modalLignes.*.sous_categorie_id.required' => 'La sous-catégorie est obligatoire.',
            'modalLignes.*.montant.required' => 'Le montant est obligatoire.',
            'modalLignes.*.montant.min' => 'Le montant doit être supérieur à 0.',
        ]);

        $tiers = Tiers::findOrFail($this->modalTiersId);

        $data = [
            'type' => TypeTransaction::Depense->value,
            'date' => $this->modalDate,
            'libelle' => "Facture d'animation {$this->modalReference} de {$tiers->displayName()}",
            'montant_total' => round(collect($this->modalLignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2),
            'mode_paiement' => $this->modalModePaiement ?: null,
            'tiers_id' => $this->modalTiersId,
            'reference' => $this->modalReference,
            'compte_id' => $this->modalCompteId ?: null,
        ];

        $lignes = collect($this->modalLignes)->map(fn ($l) => [
            'id' => $l['id'] ?? null,
            'sous_categorie_id' => (int) $l['sous_categorie_id'],
            'operation_id' => $l['operation_id'] !== '' ? (int) $l['operation_id'] : null,
            'seance' => $l['seance'] !== '' ? (int) $l['seance'] : null,
            'montant' => (float) $l['montant'],
            'notes' => $this->buildLigneNotes($l),
        ])->toArray();

        $service = app(TransactionService::class);

        try {
            if ($this->isEditing && $this->editingTransactionId) {
                $transaction = Transaction::findOrFail($this->editingTransactionId);
                $service->update($transaction, $data, $lignes);
            } else {
                $service->create($data, $lignes);
            }
        } catch (\RuntimeException $e) {
            $this->addError('modalLignes', $e->getMessage());

            return;
        }

        $this->closeModal();
    }

    private function buildLigneNotes(array $ligne): ?string
    {
        $parts = [];

        if ($ligne['operation_id'] !== '') {
            $op = Operation::find((int) $ligne['operation_id']);
            if ($op) {
                $parts[] = $op->nom;
            }
        }

        if ($ligne['seance'] !== '') {
            $parts[] = 'Séance '.$ligne['seance'];
        }

        if ($ligne['sous_categorie_id'] !== '') {
            $sc = \App\Models\SousCategorie::find((int) $ligne['sous_categorie_id']);
            if ($sc) {
                $parts[] = $sc->nom;
            }
        }

        return count($parts) > 0 ? implode(' — ', $parts) : null;
    }

    // ── Ajout nouvel animateur via autocomplete ──

    public function updatedNewTiersId(?int $value): void
    {
        if ($value) {
            $this->openCreateModal($value);
            $this->newTiersId = null;
            $this->dispatch('$refresh');
        }
    }

    // ── Données pour la modale ──

    public function getModalOperationsProperty(): Collection
    {
        return Operation::with('typeOperation')
            ->forExercice(app(ExerciceService::class)->current())
            ->where('statut', StatutOperation::EnCours)
            ->orderBy('nom')
            ->get();
    }

    // ── Rendu ──

    public function render(): mixed
    {
        $seances = $this->operation->seances()->orderBy('numero')->get();
        $matrixData = $this->buildMatrixData($seances);

        return view('livewire.animateur-manager', [
            'seances' => $seances,
            'animateurs' => $matrixData['animateurs'],
            'seanceTotals' => $matrixData['seanceTotals'],
            'grandTotal' => $matrixData['grandTotal'],
            'modalOperations' => $this->showModal ? $this->modalOperations : collect(),
        ]);
    }

    private function buildMatrixData($seances): array
    {
        $lignes = TransactionLigne::where('operation_id', $this->operation->id)
            ->whereHas('transaction', fn ($q) => $q->where('type', TypeTransaction::Depense))
            ->with(['transaction.tiers', 'sousCategorie'])
            ->get();

        $animateurs = [];
        $seanceTotals = [];
        $grandTotal = 0.0;

        foreach ($lignes as $ligne) {
            $tiersId = $ligne->transaction->tiers_id;
            if (! $tiersId) {
                continue;
            }
            $seanceNum = $ligne->seance;
            $scId = $ligne->sous_categorie_id;
            $montant = (float) $ligne->montant;

            if (! isset($animateurs[$tiersId])) {
                $animateurs[$tiersId] = [
                    'tiers' => $ligne->transaction->tiers,
                    'sousCategories' => [],
                    'seanceTotals' => [],
                    'total' => 0.0,
                ];
            }

            if (! isset($animateurs[$tiersId]['sousCategories'][$scId])) {
                $animateurs[$tiersId]['sousCategories'][$scId] = [
                    'sousCategorie' => $ligne->sousCategorie,
                    'seances' => [],
                    'transactionIds' => [],
                    'total' => 0.0,
                ];
            }

            $key = $seanceNum ?? 'null';
            $animateurs[$tiersId]['sousCategories'][$scId]['seances'][$key]
                = ($animateurs[$tiersId]['sousCategories'][$scId]['seances'][$key] ?? 0.0) + $montant;
            $animateurs[$tiersId]['sousCategories'][$scId]['transactionIds'][$key][]
                = $ligne->transaction_id;
            $animateurs[$tiersId]['sousCategories'][$scId]['total'] += $montant;

            $animateurs[$tiersId]['seanceTotals'][$key]
                = ($animateurs[$tiersId]['seanceTotals'][$key] ?? 0.0) + $montant;
            $animateurs[$tiersId]['total'] += $montant;

            $seanceTotals[$key] = ($seanceTotals[$key] ?? 0.0) + $montant;
            $grandTotal += $montant;
        }

        uasort($animateurs, fn ($a, $b) => strcmp(
            mb_strtolower($a['tiers']->nom ?? ''),
            mb_strtolower($b['tiers']->nom ?? '')
        ));

        return compact('animateurs', 'seanceTotals', 'grandTotal');
    }
}
```

- [ ] **Step 2: Vérifier la modale de création**

Ouvrir une opération → onglet Animateurs → clic sur un ⊕ vert → la modale s'ouvre avec le tiers et la séance pré-remplis.

- [ ] **Step 3: Vérifier la sauvegarde**

Remplir la modale (référence, sous-catégorie, montant) → clic Enregistrer → la transaction apparaît dans la matrice. Vérifier en base que `transactions` et `transaction_lignes` sont créées correctement.

- [ ] **Step 4: Vérifier l'édition**

Cliquer sur un montant existant dans la matrice → la modale s'ouvre pré-remplie → modifier le montant → Mettre à jour → le montant est mis à jour dans la matrice.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/AnimateurManager.php
git commit -m "feat(animateurs): modale saisie/édition transaction + tiers autocomplete"
```

---

### Task 5 : Tests Pest

**Files:**
- Create: `tests/Feature/Livewire/AnimateurManagerTest.php`

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Livewire\AnimateurManager;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->categorie = Categorie::factory()->create(['type' => 'depense']);
    $this->sousCategorie = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
    ]);

    $this->operation = Operation::factory()->create(['nombre_seances' => 3]);
    $this->seance1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);
    $this->seance2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2, 'date' => now()]);

    $this->animateur = Tiers::factory()->pourDepenses()->create([
        'nom' => 'DUPONT',
        'prenom' => 'Marie',
    ]);
});

it('renders the animateurs tab with empty matrix', function () {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->assertSee('Aucune facture d\'animateur enregistrée');
});

it('displays animateur from existing depense transaction', function () {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $this->animateur->id,
        'montant_total' => 150.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'sous_categorie_id' => $this->sousCategorie->id,
        'montant' => 150.00,
    ]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->assertDontSee('Aucune facture')
        ->assertSee('DUPONT');
});

it('opens create modal with pre-filled tiers and seance', function () {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openCreateModal', $this->animateur->id, 2)
        ->assertSet('showModal', true)
        ->assertSet('isEditing', false)
        ->assertSet('modalTiersId', $this->animateur->id)
        ->assertSet('modalTiersLabel', $this->animateur->displayName());
});

it('creates a depense transaction from the modal', function () {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openCreateModal', $this->animateur->id, 1)
        ->set('modalDate', '2026-04-06')
        ->set('modalReference', 'FA-2026-001')
        ->set('modalLignes.0.sous_categorie_id', (string) $this->sousCategorie->id)
        ->set('modalLignes.0.montant', '150.00')
        ->call('saveTransaction')
        ->assertSet('showModal', false);

    $tx = Transaction::where('tiers_id', $this->animateur->id)
        ->where('type', TypeTransaction::Depense)
        ->first();

    expect($tx)->not->toBeNull()
        ->and($tx->reference)->toBe('FA-2026-001')
        ->and($tx->libelle)->toContain('Facture d\'animation')
        ->and((float) $tx->montant_total)->toBe(150.00);

    $ligne = $tx->lignes()->first();
    expect($ligne->operation_id)->toBe($this->operation->id)
        ->and($ligne->seance)->toBe(1)
        ->and($ligne->sous_categorie_id)->toBe($this->sousCategorie->id);
});

it('validates required fields before saving', function () {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openCreateModal', $this->animateur->id, 1)
        ->set('modalDate', '')
        ->set('modalReference', '')
        ->call('saveTransaction')
        ->assertHasErrors(['modalDate', 'modalReference']);
});

it('opens edit modal with existing transaction data', function () {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $this->animateur->id,
        'montant_total' => 200.00,
        'reference' => 'FA-OLD',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'sous_categorie_id' => $this->sousCategorie->id,
        'montant' => 200.00,
    ]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openEditModal', [$tx->id])
        ->assertSet('showModal', true)
        ->assertSet('isEditing', true)
        ->assertSet('editingTransactionId', $tx->id)
        ->assertSet('modalReference', 'FA-OLD');
});

it('adds and removes modal lines', function () {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openCreateModal', $this->animateur->id, 1)
        ->assertCount('modalLignes', 1)
        ->call('addModalLigne')
        ->assertCount('modalLignes', 2)
        ->call('removeModalLigne', 1)
        ->assertCount('modalLignes', 1);
});
```

- [ ] **Step 2: Vérifier que les factories nécessaires existent**

Run: `./vendor/bin/sail artisan tinker --execute="Operation::factory()->make()"`

Si `OperationFactory` ou `SeanceFactory` n'existe pas, les créer en suivant le pattern des factories existantes. Si `Seance` n'a pas de factory, utiliser `Seance::create()` directement dans les tests (comme dans le code ci-dessus).

- [ ] **Step 3: Lancer les tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/AnimateurManagerTest.php --verbose`

Expected: tous les tests passent.

- [ ] **Step 4: Corriger si nécessaire et relancer**

Ajuster le code du composant ou des tests selon les erreurs. Les causes probables :
- Factory manquante → créer ou utiliser `::create()` directement
- Relation manquante sur un modèle → vérifier `TransactionLigne::transaction()`
- Enum casting → vérifier que `TypeTransaction::Depense` matche la valeur en base

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Livewire/AnimateurManagerTest.php
git commit -m "test(animateurs): tests Pest pour AnimateurManager"
```

---

### Task 6 : Vérification finale et polish

**Files:**
- Possiblement : ajustements mineurs sur les fichiers créés

- [ ] **Step 1: Test visuel complet**

Scénario à dérouler manuellement dans le navigateur :
1. Ouvrir une opération avec séances
2. Onglet Animateurs → message vide
3. Sélectionner un tiers via l'autocomplete en bas → modale s'ouvre
4. Remplir : date, référence, sous-catégorie, montant → Enregistrer
5. Le tiers apparaît dans la matrice avec le montant dans la bonne case
6. Clic sur le ⊕ pour ajouter une autre facture sur une autre séance
7. Clic sur un montant existant → modale d'édition pré-remplie
8. Modifier le montant → Mettre à jour → la matrice se rafraîchit
9. Vérifier les totaux (ligne, colonne, général)

- [ ] **Step 2: Vérifier Pint (PSR-12)**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint --test`

Si des erreurs, corriger avec `./vendor/bin/pint`.

- [ ] **Step 3: Lancer toute la suite de tests**

Run: `./vendor/bin/sail test --verbose`

Vérifier qu'aucun test existant n'est cassé.

- [ ] **Step 4: Commit final**

```bash
git add -A
git commit -m "feat(animateurs): onglet Animateurs complet — matrice + saisie simplifiée"
```
