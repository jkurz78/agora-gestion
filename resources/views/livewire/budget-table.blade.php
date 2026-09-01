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
        @if ($this->isAdmin)
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
        @if (! $exerciceCloture && $this->canEdit)
        <button wire:click="toggleImportPanel" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-upload"></i> Importer
        </button>
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
    @if ($showImportPanel && ! $exerciceCloture)
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
                <input type="file" wire:model="budgetFile" accept=".csv,.txt,.xlsx" class="form-control">
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
        <div class="card-header">
            <h5 class="mb-0">Charges (dépenses)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
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
                                    $ecart = $prevu - $realise;
                                    $totalChargesPrevu += $prevu;
                                    $totalChargesRealise += $realise;

                                    $lignesVentilees = $ventilations->get($compte->id, collect());
                                    $sommeVentilee = (float) $lignesVentilees->sum('montant_prevu');
                                    // Signal signé, jamais une contrainte : positif = reste à
                                    // affecter, négatif = dépassement engagé.
                                    $resteAAffecter = $prevu - $sommeVentilee;
                                @endphp
                                <tr>
                                    <td class="ps-4">
                                        @if ($lignesVentilees->isNotEmpty())
                                            <i class="bi bi-chevron-down text-muted me-1"></i>
                                        @endif
                                        <span class="font-monospace">{{ $compte->numero_pcg }}</span> — {{ $compte->intitule }}
                                        @if ($resteAAffecter < 0)
                                            <span class="badge bg-danger ms-1" title="La ventilation dépasse l'enveloppe">⚠</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if (! $exerciceCloture && $this->canEdit && $line && $editingLineId === $line->id)
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
                                        @elseif ($line && ! $exerciceCloture && $this->canEdit)
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
                                    <td class="text-end {{ $ecart < 0 ? 'text-danger' : '' }}">
                                        @if ($line)
                                            {{ number_format($ecart, 2, ',', ' ') }} &euro;
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if (! $exerciceCloture && $this->canEdit)
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
                                    @endphp
                                    <tr class="table-light">
                                        <td class="ps-5 text-muted small">
                                            <i class="bi bi-arrow-return-right"></i>
                                            {{ $v->operation?->nom ?? 'Opération supprimée' }}
                                        </td>
                                        <td class="text-end small">{{ number_format($vPrevu, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end small">{{ number_format($vRealise, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end small {{ $vPrevu - $vRealise < 0 ? 'text-danger' : '' }}">
                                            {{ number_format($vPrevu - $vRealise, 2, ',', ' ') }} &euro;
                                        </td>
                                        <td></td>
                                    </tr>
                                @endforeach
                                @if ($lignesVentilees->isNotEmpty())
                                    <tr class="table-light">
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
                        <tr class="table-warning fw-bold">
                            <td>Total Charges</td>
                            <td class="text-end">{{ number_format($totalChargesPrevu, 2, ',', ' ') }} &euro;</td>
                            <td class="text-end">{{ number_format($totalChargesRealise, 2, ',', ' ') }} &euro;</td>
                            <td class="text-end">{{ number_format($totalChargesPrevu - $totalChargesRealise, 2, ',', ' ') }} &euro;</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Produits (recettes) --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Produits (recettes)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
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
                                    $ecart = $prevu - $realise;
                                    $totalProduitsPrevu += $prevu;
                                    $totalProduitsRealise += $realise;

                                    $lignesVentilees = $ventilations->get($compte->id, collect());
                                    $sommeVentilee = (float) $lignesVentilees->sum('montant_prevu');
                                    // Signal signé, jamais une contrainte : positif = reste à
                                    // affecter, négatif = dépassement engagé.
                                    $resteAAffecter = $prevu - $sommeVentilee;
                                @endphp
                                <tr>
                                    <td class="ps-4">
                                        @if ($lignesVentilees->isNotEmpty())
                                            <i class="bi bi-chevron-down text-muted me-1"></i>
                                        @endif
                                        <span class="font-monospace">{{ $compte->numero_pcg }}</span> — {{ $compte->intitule }}
                                        @if ($resteAAffecter < 0)
                                            <span class="badge bg-danger ms-1" title="La ventilation dépasse l'enveloppe">⚠</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if (! $exerciceCloture && $this->canEdit && $line && $editingLineId === $line->id)
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
                                        @elseif ($line && ! $exerciceCloture && $this->canEdit)
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
                                    <td class="text-end {{ $ecart < 0 ? 'text-danger' : '' }}">
                                        @if ($line)
                                            {{ number_format($ecart, 2, ',', ' ') }} &euro;
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if (! $exerciceCloture && $this->canEdit)
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
                                    @endphp
                                    <tr class="table-light">
                                        <td class="ps-5 text-muted small">
                                            <i class="bi bi-arrow-return-right"></i>
                                            {{ $v->operation?->nom ?? 'Opération supprimée' }}
                                        </td>
                                        <td class="text-end small">{{ number_format($vPrevu, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end small">{{ number_format($vRealise, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end small {{ $vPrevu - $vRealise < 0 ? 'text-danger' : '' }}">
                                            {{ number_format($vPrevu - $vRealise, 2, ',', ' ') }} &euro;
                                        </td>
                                        <td></td>
                                    </tr>
                                @endforeach
                                @if ($lignesVentilees->isNotEmpty())
                                    <tr class="table-light">
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
                        <tr class="table-warning fw-bold">
                            <td>Total Produits</td>
                            <td class="text-end">{{ number_format($totalProduitsPrevu, 2, ',', ' ') }} &euro;</td>
                            <td class="text-end">{{ number_format($totalProduitsRealise, 2, ',', ' ') }} &euro;</td>
                            <td class="text-end">{{ number_format($totalProduitsPrevu - $totalProduitsRealise, 2, ',', ' ') }} &euro;</td>
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
    @endphp
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead class="table-primary">
                        <tr class="fw-bold">
                            <th>Résultat (Produits - Charges)</th>
                            <th class="text-end">{{ number_format($resultatPrevu, 2, ',', ' ') }} &euro;</th>
                            <th class="text-end">{{ number_format($resultatRealise, 2, ',', ' ') }} &euro;</th>
                            <th class="text-end">{{ number_format($resultatPrevu - $resultatRealise, 2, ',', ' ') }} &euro;</th>
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
</div>
