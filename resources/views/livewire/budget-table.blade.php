<div>
    @php
        $totalChargesPrevu = 0;
        $totalChargesRealise = 0;
        $totalProduitsPrevu = 0;
        $totalProduitsRealise = 0;
    @endphp

    {{-- Boutons Export / Import --}}
    <div class="d-flex gap-2 mb-3">
        <button wire:click="openExportModal" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download"></i> Exporter
        </button>
        <button wire:click="toggleImportPanel" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-upload"></i> Importer
        </button>
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
                            <option value="courant">Montants de l'exercice courant</option>
                            <option value="n1">Montants de l'exercice N-1</option>
                        </select>
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
    @if ($showImportPanel)
    <div class="card mb-3 border-warning">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Importer le budget — exercice {{ $exerciceLabel }}</span>
            <button wire:click="toggleImportPanel" type="button" class="btn-close"></button>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                L'import supprimera toutes les lignes budgétaires existantes pour l'exercice {{ $exerciceLabel }} avant de charger les nouvelles données.
                Les montants vides ou nuls ne sont pas chargés. Cette action est irréversible.
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
                            <th>Sous-catégorie</th>
                            <th class="text-end">Prévu</th>
                            <th class="text-end">Réalisé</th>
                            <th class="text-end">Écart</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($depenseCategories as $categorie)
                            <tr class="table-secondary">
                                <td colspan="5" class="fw-bold">{{ $categorie->nom }}</td>
                            </tr>
                            @foreach ($categorie->sousCategories as $sc)
                                @php
                                    $line = $budgetLines->get($sc->id);
                                    $prevu = $line ? (float) $line->montant_prevu : 0;
                                    $realise = $realiseData[$sc->id] ?? 0;
                                    $ecart = $prevu - $realise;
                                    $totalChargesPrevu += $prevu;
                                    $totalChargesRealise += $realise;
                                @endphp
                                <tr>
                                    <td class="ps-4">{{ $sc->nom }}</td>
                                    <td class="text-end">
                                        @if ($line && $editingLineId === $line->id)
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
                                        @elseif ($line)
                                            <span wire:click="startEdit({{ $line->id }})" style="cursor: pointer;"
                                                  class="text-primary" title="Cliquer pour modifier">
                                                {{ number_format($prevu, 2, ',', ' ') }} &euro;
                                            </span>
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
                                        @if (! $line)
                                            <button wire:click="addLine({{ $sc->id }})"
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
                                    </td>
                                </tr>
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
                            <th>Sous-catégorie</th>
                            <th class="text-end">Prévu</th>
                            <th class="text-end">Réalisé</th>
                            <th class="text-end">Écart</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recetteCategories as $categorie)
                            <tr class="table-secondary">
                                <td colspan="5" class="fw-bold">{{ $categorie->nom }}</td>
                            </tr>
                            @foreach ($categorie->sousCategories as $sc)
                                @php
                                    $line = $budgetLines->get($sc->id);
                                    $prevu = $line ? (float) $line->montant_prevu : 0;
                                    $realise = $realiseData[$sc->id] ?? 0;
                                    $ecart = $prevu - $realise;
                                    $totalProduitsPrevu += $prevu;
                                    $totalProduitsRealise += $realise;
                                @endphp
                                <tr>
                                    <td class="ps-4">{{ $sc->nom }}</td>
                                    <td class="text-end">
                                        @if ($line && $editingLineId === $line->id)
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
                                        @elseif ($line)
                                            <span wire:click="startEdit({{ $line->id }})" style="cursor: pointer;"
                                                  class="text-primary" title="Cliquer pour modifier">
                                                {{ number_format($prevu, 2, ',', ' ') }} &euro;
                                            </span>
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
                                        @if (! $line)
                                            <button wire:click="addLine({{ $sc->id }})"
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
                                    </td>
                                </tr>
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
</div>
