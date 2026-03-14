<div>
    @php
        $totalChargesPrevu = 0;
        $totalChargesRealise = 0;
        $totalProduitsPrevu = 0;
        $totalProduitsRealise = 0;
    @endphp

    {{-- Charges (dépenses) --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Charges (dépenses)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
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
                    <thead class="table-dark">
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
