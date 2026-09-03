<div style="font-size:.85rem;">
    @php
        $totalChargesPrevu = 0;
        $totalChargesRealise = 0;
        $totalProduitsPrevu = 0;
        $totalProduitsRealise = 0;
    @endphp

    {{-- Bandeau : budget figé --}}
    @if ($exerciceModele?->budgetEstValide())
    <div class="alert alert-primary d-flex justify-content-between align-items-center py-2">
        <div>
            <i class="bi bi-lock-fill"></i>
            Budget validé le {{ $exerciceModele->budget_valide_le->format('d/m/Y') }}
            @if ($exerciceModele->budgetValidePar)
                par {{ $exerciceModele->budgetValidePar->name }}
            @endif
            — les enveloppes sont verrouillées, la ventilation par opération reste modifiable.
        </div>
        @if ($this->isAdmin && ! $exerciceCloture)
        <button wire:click="$set('showDeverrouillageModal', true)" class="btn btn-sm btn-outline-primary">
            Déverrouiller
        </button>
        @endif
    </div>
    @elseif ($this->isAdmin && ! $exerciceCloture)
    <div class="alert alert-light border d-flex justify-content-between align-items-center py-2">
        <div>Le budget de l'exercice {{ $exerciceLabel }} n'est pas encore validé.</div>
        <button wire:click="validerBudget"
                wire:confirm="Valider le budget de l'exercice {{ $exerciceLabel }} ? Les enveloppes passeront en lecture seule."
                class="btn btn-sm btn-primary">
            <i class="bi bi-lock"></i> Valider le budget
        </button>
    </div>
    @endif

    {{-- Bandeau : opérations sans budget affecté --}}
    @if (! $exerciceCloture && $operationsSansBudget->isNotEmpty())
    <div class="alert alert-warning py-2">
        <i class="bi bi-exclamation-triangle"></i>
        {{ $operationsSansBudget->count() }}
        {{ $operationsSansBudget->count() > 1 ? 'opérations ouvertes' : 'opération ouverte' }}
        sans budget affecté sur cet exercice :
        @foreach ($operationsSansBudget as $op)
            <a href="#" wire:click.prevent="$dispatch('ouvrir-affectation', { operationId: {{ $op->id }} })"
               class="badge bg-warning text-dark text-decoration-none">{{ $op->nom }}</a>
        @endforeach
    </div>
    @endif

    {{-- Boutons Export / Import --}}
    <div class="d-flex gap-2 mb-3">
        <button wire:click="openExportModal" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download"></i> Exporter
        </button>
        {{-- L'import ne touche que les enveloppes : gelé avec elles. --}}
        @if (! $exerciceCloture && $this->canEdit && ! $this->budgetValide)
        <button wire:click="toggleImportPanel" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-upload"></i> Importer
        </button>
        @endif
        {{-- La ventilation reste modifiable même budget validé : ce bouton
             n'est jamais gelé par $this->budgetValide. --}}
        @if (! $exerciceCloture && $this->canEdit)
        <button wire:click="$dispatch('ouvrir-affectation', { operationId: 0 })"
                class="btn btn-outline-primary btn-sm">
            <i class="bi bi-diagram-3"></i> Affecter un budget à une opération
        </button>
        @endif
    </div>

    {{-- Modal Export --}}
    @if ($showExportModal)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Exporter le budget</h5>
                    <button wire:click="closeExportModal" type="button" class="btn-close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Format</label>
                        <select wire:model="exportFormat" class="form-select">
                            <option value="csv">CSV</option>
                            <option value="xlsx">Excel (.xlsx)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Exercice à écrire dans le fichier</label>
                        <select wire:model="exportExercice" class="form-select">
                            <option value="courant">Exercice courant ({{ $exportExerciceCourant }}-{{ $exportExerciceCourant + 1 }})</option>
                            <option value="suivant">Exercice suivant ({{ $exportExerciceSuivant }}-{{ $exportExerciceSuivant + 1 }})</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Montants à inclure</label>
                        <select wire:model="exportSource" class="form-select">
                            <option value="zero">Zéro partout (cellules vides)</option>
                            <option value="courant">Réalisé exercice courant</option>
                            <option value="budget">Budget exercice courant</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Exercice de référence</label>
                        <select wire:model="exportSourceExercice" class="form-select">
                            @foreach ($anneesDisponibles as $annee)
                                <option value="{{ $annee }}">{{ $annee }}-{{ $annee + 1 }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            Sert au pré-remplissage « réalisé » et à la colonne de référence du fichier.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button wire:click="closeExportModal" type="button" class="btn btn-secondary">Annuler</button>
                    <button wire:click="export" type="button" class="btn btn-primary">
                        <i class="bi bi-download"></i> Télécharger
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Panel Import --}}
    @if ($showImportPanel && ! $exerciceCloture && ! $this->budgetValide)
    <div class="card mb-3 border-warning">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Importer le budget — exercice {{ $exerciceLabel }}</span>
            <button wire:click="toggleImportPanel" type="button" class="btn-close"></button>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                L'import remplacera les enveloppes existantes pour l'exercice {{ $exerciceLabel }} avant de charger les nouvelles données.
                La ventilation par opération est conservée. Les montants vides ou nuls ne sont pas chargés. Cette action est irréversible.
            </div>

            @if ($importSuccess)
                <div class="alert alert-success">{{ $importSuccess }}</div>
            @endif

            @if ($importErrors)
                <div class="alert alert-danger">
                    <strong>Erreurs de validation :</strong>
                    <ul class="mb-0 mt-1">
                        @foreach ($importErrors as $error)
                            <li>{{ $error['message'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($compteRenduImport)
            <div class="alert alert-info py-2 small mb-2">
                <div class="fw-semibold">Import du budget {{ $exerciceLabel }}</div>
                <div>{{ $compteRenduImport['enveloppes'] }} enveloppe(s) seront remplacée(s)</div>
                @if ($compteRenduImport['ventilations'] > 0)
                <div>
                    {{ $compteRenduImport['ventilations'] }} ligne(s) de ventilation
                    ({{ number_format($compteRenduImport['montant_ventile'], 2, ',', ' ') }} €)
                    sur {{ $compteRenduImport['operations'] }} opération(s) seront conservées
                </div>
                @endif
            </div>
            @endif

            <div class="mb-3">
                <label class="form-label">Fichier budget (CSV ou Excel)</label>
                <x-zone-depot>
                    <input type="file" wire:model="budgetFile" accept=".csv,.txt,.xlsx" class="form-control">
                </x-zone-depot>
                @error('budgetFile') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <button wire:click="importBudget" class="btn btn-warning" wire:loading.attr="disabled">
                <span wire:loading wire:target="importBudget" class="spinner-border spinner-border-sm"></span>
                Valider l'import
            </button>
        </div>
    </div>
    @endif

    {{-- Charges (dépenses) --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Charges (dépenses)</h5>
            <button type="button" class="btn btn-sm btn-outline-secondary budget-toggle-all"
                    data-toggle-all-target="budget-table-charges">
                <i class="bi bi-arrows-expand"></i> Tout déplier
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0" id="budget-table-charges">
                    {{-- Largeurs identiques sur les trois tableaux (Charges, Produits,
                         Résultat) : c'est ce qui fait tomber leurs colonnes à l'aplomb les
                         unes des autres alors qu'il s'agit de trois <table> distincts. --}}
                    <colgroup>
                        <col>
                        <col style="width: 140px;">
                        <col style="width: 140px;">
                        <col style="width: 140px;">
                        <col style="width: 100px;">
                    </colgroup>
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Compte</th>
                            <th class="text-end">Prévu</th>
                            <th class="text-end">Réalisé</th>
                            <th class="text-end">Écart</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($depenseGroupes as $codeFamille => $groupe)
                            <tr class="table-secondary">
                                <td colspan="5" class="fw-bold">{{ $groupe['famille']?->libelle() ?? $codeFamille }}</td>
                            </tr>
                            @foreach ($groupe['comptes'] as $compte)
                                @php
                                    $line = $budgetLines->get($compte->id);
                                    $prevu = $line ? (float) $line->montant_prevu : 0;
                                    $realise = $realiseData[$compte->id] ?? 0;
                                    $ecart = \App\Support\ComparaisonBudgetaire::ecart($prevu, $realise);
                                    $ecartFavorable = \App\Support\ComparaisonBudgetaire::ecartEstFavorable($ecart, true);
                                    $totalChargesPrevu += $prevu;
                                    $totalChargesRealise += $realise;

                                    $lignesVentilees = $ventilations->get($compte->id, collect());
                                    $sommeVentilee = (float) $lignesVentilees->sum('montant_prevu');
                                    // Signal signé, jamais une contrainte : positif = reste à
                                    // affecter, négatif = dépassement engagé.
                                    $resteAAffecter = $prevu - $sommeVentilee;
                                @endphp
                                <tr
                                    @if ($lignesVentilees->isNotEmpty())
                                        data-compte-toggle="{{ $compte->id }}"
                                        style="cursor: pointer;"
                                        title="Cliquer pour déplier / replier la ventilation"
                                    @endif
                                >
                                    <td class="ps-4">
                                        @if ($lignesVentilees->isNotEmpty())
                                            <i class="bi bi-chevron-right budget-chevron text-muted me-1"></i>
                                        @endif
                                        <span class="font-monospace">{{ $compte->numero_pcg }}</span> — {{ $compte->intitule }}
                                        @if ($resteAAffecter < 0)
                                            <span class="badge bg-danger ms-1" title="La ventilation dépasse l'enveloppe">⚠</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if (! $exerciceCloture && $this->canEdit && ! $this->budgetValide && $line && $editingLineId === $line->id)
                                            <div class="d-flex justify-content-end gap-1">
                                                <input type="number" wire:model="editingMontant" step="0.01" min="0"
                                                       class="form-control form-control-sm" style="width: 120px;"
                                                       wire:keydown.enter="saveEdit"
                                                       wire:keydown.escape="cancelEdit">
                                                <button wire:click="saveEdit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                                <button wire:click="cancelEdit" class="btn btn-sm btn-secondary">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        @elseif ($line && ! $exerciceCloture && $this->canEdit && ! $this->budgetValide)
                                            <span wire:click="startEdit({{ $line->id }})" style="cursor: pointer;"
                                                  class="text-primary" title="Cliquer pour modifier">
                                                {{ number_format($prevu, 2, ',', ' ') }} &euro;
                                            </span>
                                        @elseif ($line)
                                            {{ number_format($prevu, 2, ',', ' ') }} &euro;
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format($realise, 2, ',', ' ') }} &euro;</td>
                                    <td class="text-end {{ ! $ecartFavorable ? 'text-danger' : '' }}">
                                        @if ($line)
                                            {{ $ecart > 0 ? '+' : '' }}{{ number_format($ecart, 2, ',', ' ') }} &euro;
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        {{-- Enveloppe uniquement : ces trois contrôles sont gelés avec le
                                             budget validé, contrairement aux actions de ventilation ci-dessous. --}}
                                        @if (! $exerciceCloture && $this->canEdit && ! $this->budgetValide)
                                        @if (! $line)
                                            <button wire:click="addLine({{ $compte->id }})"
                                                    class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-plus-lg"></i>
                                            </button>
                                        @else
                                            <button wire:click="deleteLine({{ $line->id }})"
                                                    wire:confirm="Supprimer cette ligne budgétaire ?"
                                                    class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        @endif
                                        @endif
                                    </td>
                                </tr>
                                @foreach ($lignesVentilees as $v)
                                    @php
                                        $vRealise = $realiseParOperation[$compte->id][$v->operation_id] ?? 0;
                                        $vPrevu = (float) $v->montant_prevu;
                                        $vEcart = \App\Support\ComparaisonBudgetaire::ecart($vPrevu, $vRealise);
                                        $vEcartFavorable = \App\Support\ComparaisonBudgetaire::ecartEstFavorable($vEcart, true);
                                    @endphp
                                    @php
                                        // Cliquable seulement si l'opération est encore proposable à la saisie
                                        // (non soft-supprimée) : sinon elle n'est pas dans le sélecteur de la
                                        // modale et le clic n'aurait aucun effet utile.
                                        $vCliquable = $v->operation && ! $v->operation->trashed();
                                    @endphp
                                    {{-- d-none par défaut : la ventilation est repliée au premier affichage.
                                         Le repli est purement visuel (classe JS/CSS) — la ligne reste bien
                                         dans le HTML rendu, jamais retirée côté serveur. --}}
                                    <tr class="table-light d-none"
                                        data-ventilation-of="{{ $compte->id }}"
                                        @if ($vCliquable)
                                            wire:click="$dispatch('ouvrir-affectation', { operationId: {{ $v->operation_id }} })"
                                            style="cursor: pointer;"
                                            title="Cliquer pour modifier l'affectation"
                                        @endif
                                    >
                                        <td class="ps-5 text-muted small">
                                            <i class="bi bi-arrow-return-right"></i>
                                            @if ($vCliquable)
                                                {{-- Même événement que les badges du bandeau "sans budget affecté" :
                                                     ouvre la modale d'affectation sur cette opération. Toute la
                                                     ligne est cliquable (wire:click sur le <tr> ci-dessus) — le nom
                                                     n'est qu'un repère visuel, plus de wire:click ici. --}}
                                                {{ $v->operation->nom }}
                                            @elseif ($v->operation)
                                                {{-- Opération soft-supprimée : nom conservé (withTrashed() sur la
                                                     relation), mais non cliquable — elle n'est plus proposable à la
                                                     saisie, donc pas dans le sélecteur de la modale. --}}
                                                {{ $v->operation->nom }} <span class="fst-italic">(supprimée)</span>
                                            @else
                                                Opération supprimée
                                            @endif
                                        </td>
                                        <td class="text-end small">{{ number_format($vPrevu, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end small">{{ number_format($vRealise, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end small {{ ! $vEcartFavorable ? 'text-danger' : '' }}">
                                            {{ $vEcart > 0 ? '+' : '' }}{{ number_format($vEcart, 2, ',', ' ') }} &euro;
                                        </td>
                                        <td>
                                            {{-- La ventilation reste modifiable toute l'année, gel ou non : pas de
                                                 garde sur $this->budgetValide ici (elle ne s'applique qu'aux
                                                 enveloppes). wire:click.stop : le bouton est DANS la ligne
                                                 cliquable ci-dessus, sans quoi le supprimer ouvrirait aussi la
                                                 modale d'affectation. --}}
                                            @if (! $exerciceCloture && $this->canEdit)
                                            <button wire:click.stop="deleteLine({{ $v->id }})"
                                                    wire:confirm="Supprimer cette ventilation ?"
                                                    class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                @if ($lignesVentilees->isNotEmpty())
                                    <tr class="table-light d-none" data-ventilation-of="{{ $compte->id }}">
                                        <td class="ps-5 small fst-italic {{ $resteAAffecter < 0 ? 'text-danger fw-bold' : 'text-muted' }}">
                                            {{ $resteAAffecter < 0 ? 'Dépassement engagé' : 'Non affecté' }}
                                        </td>
                                        <td class="text-end small {{ $resteAAffecter < 0 ? 'text-danger fw-bold' : 'text-muted' }}">
                                            {{ number_format($resteAAffecter, 2, ',', ' ') }} &euro;
                                        </td>
                                        <td colspan="3"></td>
                                    </tr>
                                @endif
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot>
                        @php
                            $totalChargesEcart = \App\Support\ComparaisonBudgetaire::ecart($totalChargesPrevu, $totalChargesRealise);
                            $totalChargesFavorable = \App\Support\ComparaisonBudgetaire::ecartEstFavorable($totalChargesEcart, true);
                        @endphp
                        <tr class="table-warning fw-bold">
                            <td>Total Charges</td>
                            <td class="text-end">{{ number_format($totalChargesPrevu, 2, ',', ' ') }} &euro;</td>
                            <td class="text-end">{{ number_format($totalChargesRealise, 2, ',', ' ') }} &euro;</td>
                            <td class="text-end {{ ! $totalChargesFavorable ? 'text-danger' : '' }}">{{ $totalChargesEcart > 0 ? '+' : '' }}{{ number_format($totalChargesEcart, 2, ',', ' ') }} &euro;</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Produits (recettes) --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Produits (recettes)</h5>
            <button type="button" class="btn btn-sm btn-outline-secondary budget-toggle-all"
                    data-toggle-all-target="budget-table-produits">
                <i class="bi bi-arrows-expand"></i> Tout déplier
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0" id="budget-table-produits">
                    <colgroup>
                        <col>
                        <col style="width: 140px;">
                        <col style="width: 140px;">
                        <col style="width: 140px;">
                        <col style="width: 100px;">
                    </colgroup>
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Compte</th>
                            <th class="text-end">Prévu</th>
                            <th class="text-end">Réalisé</th>
                            <th class="text-end">Écart</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recetteGroupes as $codeFamille => $groupe)
                            <tr class="table-secondary">
                                <td colspan="5" class="fw-bold">{{ $groupe['famille']?->libelle() ?? $codeFamille }}</td>
                            </tr>
                            @foreach ($groupe['comptes'] as $compte)
                                @php
                                    $line = $budgetLines->get($compte->id);
                                    $prevu = $line ? (float) $line->montant_prevu : 0;
                                    $realise = $realiseData[$compte->id] ?? 0;
                                    $ecart = \App\Support\ComparaisonBudgetaire::ecart($prevu, $realise);
                                    $ecartFavorable = \App\Support\ComparaisonBudgetaire::ecartEstFavorable($ecart, false);
                                    $totalProduitsPrevu += $prevu;
                                    $totalProduitsRealise += $realise;

                                    $lignesVentilees = $ventilations->get($compte->id, collect());
                                    $sommeVentilee = (float) $lignesVentilees->sum('montant_prevu');
                                    // Signal signé, jamais une contrainte : positif = reste à
                                    // affecter, négatif = dépassement engagé.
                                    $resteAAffecter = $prevu - $sommeVentilee;
                                @endphp
                                <tr
                                    @if ($lignesVentilees->isNotEmpty())
                                        data-compte-toggle="{{ $compte->id }}"
                                        style="cursor: pointer;"
                                        title="Cliquer pour déplier / replier la ventilation"
                                    @endif
                                >
                                    <td class="ps-4">
                                        @if ($lignesVentilees->isNotEmpty())
                                            <i class="bi bi-chevron-right budget-chevron text-muted me-1"></i>
                                        @endif
                                        <span class="font-monospace">{{ $compte->numero_pcg }}</span> — {{ $compte->intitule }}
                                        @if ($resteAAffecter < 0)
                                            <span class="badge bg-danger ms-1" title="La ventilation dépasse l'enveloppe">⚠</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if (! $exerciceCloture && $this->canEdit && ! $this->budgetValide && $line && $editingLineId === $line->id)
                                            <div class="d-flex justify-content-end gap-1">
                                                <input type="number" wire:model="editingMontant" step="0.01" min="0"
                                                       class="form-control form-control-sm" style="width: 120px;"
                                                       wire:keydown.enter="saveEdit"
                                                       wire:keydown.escape="cancelEdit">
                                                <button wire:click="saveEdit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                                <button wire:click="cancelEdit" class="btn btn-sm btn-secondary">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        @elseif ($line && ! $exerciceCloture && $this->canEdit && ! $this->budgetValide)
                                            <span wire:click="startEdit({{ $line->id }})" style="cursor: pointer;"
                                                  class="text-primary" title="Cliquer pour modifier">
                                                {{ number_format($prevu, 2, ',', ' ') }} &euro;
                                            </span>
                                        @elseif ($line)
                                            {{ number_format($prevu, 2, ',', ' ') }} &euro;
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format($realise, 2, ',', ' ') }} &euro;</td>
                                    <td class="text-end {{ ! $ecartFavorable ? 'text-danger' : '' }}">
                                        @if ($line)
                                            {{ $ecart > 0 ? '+' : '' }}{{ number_format($ecart, 2, ',', ' ') }} &euro;
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        {{-- Enveloppe uniquement : ces trois contrôles sont gelés avec le
                                             budget validé, contrairement aux actions de ventilation ci-dessous. --}}
                                        @if (! $exerciceCloture && $this->canEdit && ! $this->budgetValide)
                                        @if (! $line)
                                            <button wire:click="addLine({{ $compte->id }})"
                                                    class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-plus-lg"></i>
                                            </button>
                                        @else
                                            <button wire:click="deleteLine({{ $line->id }})"
                                                    wire:confirm="Supprimer cette ligne budgétaire ?"
                                                    class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        @endif
                                        @endif
                                    </td>
                                </tr>
                                @foreach ($lignesVentilees as $v)
                                    @php
                                        $vRealise = $realiseParOperation[$compte->id][$v->operation_id] ?? 0;
                                        $vPrevu = (float) $v->montant_prevu;
                                        $vEcart = \App\Support\ComparaisonBudgetaire::ecart($vPrevu, $vRealise);
                                        $vEcartFavorable = \App\Support\ComparaisonBudgetaire::ecartEstFavorable($vEcart, false);
                                    @endphp
                                    @php
                                        // Cliquable seulement si l'opération est encore proposable à la saisie
                                        // (non soft-supprimée) : sinon elle n'est pas dans le sélecteur de la
                                        // modale et le clic n'aurait aucun effet utile.
                                        $vCliquable = $v->operation && ! $v->operation->trashed();
                                    @endphp
                                    {{-- d-none par défaut : la ventilation est repliée au premier affichage.
                                         Le repli est purement visuel (classe JS/CSS) — la ligne reste bien
                                         dans le HTML rendu, jamais retirée côté serveur. --}}
                                    <tr class="table-light d-none"
                                        data-ventilation-of="{{ $compte->id }}"
                                        @if ($vCliquable)
                                            wire:click="$dispatch('ouvrir-affectation', { operationId: {{ $v->operation_id }} })"
                                            style="cursor: pointer;"
                                            title="Cliquer pour modifier l'affectation"
                                        @endif
                                    >
                                        <td class="ps-5 text-muted small">
                                            <i class="bi bi-arrow-return-right"></i>
                                            @if ($vCliquable)
                                                {{-- Même événement que les badges du bandeau "sans budget affecté" :
                                                     ouvre la modale d'affectation sur cette opération. Toute la
                                                     ligne est cliquable (wire:click sur le <tr> ci-dessus) — le nom
                                                     n'est qu'un repère visuel, plus de wire:click ici. --}}
                                                {{ $v->operation->nom }}
                                            @elseif ($v->operation)
                                                {{-- Opération soft-supprimée : nom conservé (withTrashed() sur la
                                                     relation), mais non cliquable — elle n'est plus proposable à la
                                                     saisie, donc pas dans le sélecteur de la modale. --}}
                                                {{ $v->operation->nom }} <span class="fst-italic">(supprimée)</span>
                                            @else
                                                Opération supprimée
                                            @endif
                                        </td>
                                        <td class="text-end small">{{ number_format($vPrevu, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end small">{{ number_format($vRealise, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end small {{ ! $vEcartFavorable ? 'text-danger' : '' }}">
                                            {{ $vEcart > 0 ? '+' : '' }}{{ number_format($vEcart, 2, ',', ' ') }} &euro;
                                        </td>
                                        <td>
                                            {{-- La ventilation reste modifiable toute l'année, gel ou non : pas de
                                                 garde sur $this->budgetValide ici (elle ne s'applique qu'aux
                                                 enveloppes). wire:click.stop : le bouton est DANS la ligne
                                                 cliquable ci-dessus, sans quoi le supprimer ouvrirait aussi la
                                                 modale d'affectation. --}}
                                            @if (! $exerciceCloture && $this->canEdit)
                                            <button wire:click.stop="deleteLine({{ $v->id }})"
                                                    wire:confirm="Supprimer cette ventilation ?"
                                                    class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                @if ($lignesVentilees->isNotEmpty())
                                    <tr class="table-light d-none" data-ventilation-of="{{ $compte->id }}">
                                        <td class="ps-5 small fst-italic {{ $resteAAffecter < 0 ? 'text-danger fw-bold' : 'text-muted' }}">
                                            {{ $resteAAffecter < 0 ? 'Dépassement engagé' : 'Non affecté' }}
                                        </td>
                                        <td class="text-end small {{ $resteAAffecter < 0 ? 'text-danger fw-bold' : 'text-muted' }}">
                                            {{ number_format($resteAAffecter, 2, ',', ' ') }} &euro;
                                        </td>
                                        <td colspan="3"></td>
                                    </tr>
                                @endif
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot>
                        @php
                            $totalProduitsEcart = \App\Support\ComparaisonBudgetaire::ecart($totalProduitsPrevu, $totalProduitsRealise);
                            $totalProduitsFavorable = \App\Support\ComparaisonBudgetaire::ecartEstFavorable($totalProduitsEcart, false);
                        @endphp
                        <tr class="table-warning fw-bold">
                            <td>Total Produits</td>
                            <td class="text-end">{{ number_format($totalProduitsPrevu, 2, ',', ' ') }} &euro;</td>
                            <td class="text-end">{{ number_format($totalProduitsRealise, 2, ',', ' ') }} &euro;</td>
                            <td class="text-end {{ ! $totalProduitsFavorable ? 'text-danger' : '' }}">{{ $totalProduitsEcart > 0 ? '+' : '' }}{{ number_format($totalProduitsEcart, 2, ',', ' ') }} &euro;</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Résultat --}}
    @php
        $resultatPrevu = $totalProduitsPrevu - $totalChargesPrevu;
        $resultatRealise = $totalProduitsRealise - $totalChargesRealise;
        // Le résultat se comporte comme un produit : favorable = le réalisé
        // dépasse le prévu (même règle que la tuile budget du tableau de
        // bord, resources/views/livewire/dashboard.blade.php).
        $resultatEcart = \App\Support\ComparaisonBudgetaire::ecart($resultatPrevu, $resultatRealise);
        $resultatFavorable = \App\Support\ComparaisonBudgetaire::ecartEstFavorable($resultatEcart, false);
    @endphp
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <colgroup>
                        <col>
                        <col style="width: 140px;">
                        <col style="width: 140px;">
                        <col style="width: 140px;">
                        <col style="width: 100px;">
                    </colgroup>
                    <thead class="table-primary">
                        <tr class="fw-bold">
                            <th>Résultat (Produits - Charges)</th>
                            <th class="text-end">{{ number_format($resultatPrevu, 2, ',', ' ') }} &euro;</th>
                            <th class="text-end">{{ number_format($resultatRealise, 2, ',', ' ') }} &euro;</th>
                            <th class="text-end {{ ! $resultatFavorable ? 'text-danger' : '' }}">{{ $resultatEcart > 0 ? '+' : '' }}{{ number_format($resultatEcart, 2, ',', ' ') }} &euro;</th>
                            <th style="width: 100px;"></th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>

    @if ($showDeverrouillageModal)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Déverrouiller le budget</h5>
                    <button wire:click="$set('showDeverrouillageModal', false)" type="button" class="btn-close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">
                        Le budget voté redeviendra modifiable. L'opération est tracée dans
                        l'historique de l'exercice.
                    </p>
                    <label class="form-label fw-semibold">Motif</label>
                    <textarea wire:model="commentaireDeverrouillage" class="form-control" rows="3"
                              placeholder="Ex. : coquille sur le compte 613 signalée en bureau"></textarea>
                    @error('commentaireDeverrouillage')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>
                <div class="modal-footer">
                    <button wire:click="$set('showDeverrouillageModal', false)" type="button" class="btn btn-secondary">Annuler</button>
                    <button wire:click="deverrouillerBudget" type="button" class="btn btn-warning">Déverrouiller</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <livewire:budget-affectation-modal />

    {{-- ═══════════════════════════════════════════════════════════
         JS : REPLI / DÉPLIAGE DES SOUS-LIGNES DE VENTILATION (côté client)

         En JS pur, pas en propriété Livewire : un aller-retour serveur par
         chevron re-rendrait tout le tableau (deux requêtes groupées + le plan
         comptable) pour un simple pliage d'affichage.

         L'état ouvert/fermé vit dans un Set JS en mémoire (pas de
         localStorage : il n'a pas à survivre à un rechargement de page).
         Piège à éviter : tout re-render Livewire (édition d'un montant,
         enregistrement depuis la modale d'affectation…) régénère le HTML des
         lignes avec leurs classes par défaut (repliées), ce qui effacerait
         l'état sans réapplication — accroché sur Livewire.hook('morph.updated', …),
         même mécanisme déjà utilisé par le tri de colonnes de l'écran
         Provisions (resources/views/livewire/provisions/provision-index.blade.php).
         ═══════════════════════════════════════════════════════════ --}}
    <style>
        .budget-chevron { display: inline-block; transition: transform 0.15s ease; }
        .budget-chevron.expanded { transform: rotate(90deg); }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tableIds = ['budget-table-charges', 'budget-table-produits'];

            // Ids de compte (string, tel que lu dans data-compte-toggle) actuellement
            // dépliés. Partagé entre les deux tableaux (Charges / Produits) : les ids
            // de compte n'entrent jamais en collision entre les deux blocs.
            const openAccountIds = new Set();

            // Le bouton « Tout déplier / replier » n'a pas d'état à lui : son
            // libellé est dérivé du Set à chaque applyState(), pour ne jamais
            // désynchroniser d'un repli/dépliage individuel ou d'un re-render.
            function syncToggleAllLabel(tableId, rows) {
                const btn = document.querySelector('[data-toggle-all-target="' + tableId + '"]');
                if (!btn) return;
                const allOpen = rows.length > 0 && rows.every(function (row) {
                    return openAccountIds.has(row.dataset.compteToggle);
                });
                btn.innerHTML = allOpen
                    ? '<i class="bi bi-arrows-collapse"></i> Tout replier'
                    : '<i class="bi bi-arrows-expand"></i> Tout déplier';
            }

            function applyState(tableId) {
                const table = document.getElementById(tableId);
                if (!table) return;

                const rows = Array.from(table.querySelectorAll('tr[data-compte-toggle]'));
                rows.forEach(function (row) {
                    const id = row.dataset.compteToggle;
                    const open = openAccountIds.has(id);
                    const chevron = row.querySelector('.budget-chevron');
                    if (chevron) chevron.classList.toggle('expanded', open);

                    table.querySelectorAll('[data-ventilation-of="' + id + '"]').forEach(function (sub) {
                        sub.classList.toggle('d-none', !open);
                    });
                });

                syncToggleAllLabel(tableId, rows);
            }

            function toggleAccount(row) {
                const id = row.dataset.compteToggle;
                if (openAccountIds.has(id)) {
                    openAccountIds.delete(id);
                } else {
                    openAccountIds.add(id);
                }
                applyState(row.closest('table').id);
            }

            tableIds.forEach(function (tableId) {
                const table = document.getElementById(tableId);
                if (!table) return;

                // Délégation sur le tableau entier : le clic sur une ligne de compte
                // ventilée (data-compte-toggle) replie/déplie ses sous-lignes. Ignoré
                // si la cible est un contrôle d'édition d'enveloppe (montant, boutons
                // ajouter/supprimer) déjà porteur de sa propre action wire:click —
                // sans quoi éditer le montant replierait/déplierait la ligne au passage.
                table.addEventListener('click', function (e) {
                    const row = e.target.closest('tr[data-compte-toggle]');
                    if (!row || !table.contains(row)) return;
                    if (e.target.closest('button, input, [wire\\:click]')) return;
                    toggleAccount(row);
                });

                const toggleAllBtn = document.querySelector('[data-toggle-all-target="' + tableId + '"]');
                if (toggleAllBtn) {
                    toggleAllBtn.addEventListener('click', function () {
                        const rows = Array.from(table.querySelectorAll('tr[data-compte-toggle]'));
                        const allOpen = rows.length > 0 && rows.every(function (row) {
                            return openAccountIds.has(row.dataset.compteToggle);
                        });
                        rows.forEach(function (row) {
                            const id = row.dataset.compteToggle;
                            if (allOpen) {
                                openAccountIds.delete(id);
                            } else {
                                openAccountIds.add(id);
                            }
                        });
                        applyState(tableId);
                    });
                }

                applyState(tableId);
            });

            Livewire.hook('morph.updated', ({ el }) => {
                tableIds.forEach(function (tableId) {
                    if (el.id === tableId || el.closest('#' + tableId)) {
                        requestAnimationFrame(function () { applyState(tableId); });
                    }
                });
            });
        });
    </script>
</div>
