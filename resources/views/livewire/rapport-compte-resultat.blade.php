<div>
    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="filter-exercice" class="form-label">Exercice</label>
                    <select wire:model.live="exercice" id="filter-exercice" class="form-select form-select-sm">
                        @foreach ($exercices as $ex)
                            <option value="{{ $ex }}">{{ $exerciceService->label($ex) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Filtrer par opérations</label>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach ($operations as $op)
                            <div class="form-check">
                                <input type="checkbox" wire:model.live="selectedOperationIds"
                                       value="{{ $op->id }}"
                                       id="op-{{ $op->id }}"
                                       class="form-check-input">
                                <label for="op-{{ $op->id }}" class="form-check-label">{{ $op->nom }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button wire:click="exportCsv" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download"></i> Exporter CSV
                    </button>
                </div>
            </div>
        </div>
    </div>

    @php
        $totalCharges = collect($charges)->sum('montant');
        $totalProduits = collect($produits)->sum('montant');
        $resultatNet = $totalProduits - $totalCharges;
    @endphp

    {{-- Charges --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Charges</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Code CERFA</th>
                            <th>Libellé</th>
                            <th class="text-end">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($charges as $charge)
                            <tr>
                                <td>{{ $charge['code_cerfa'] ?? '-' }}</td>
                                <td>{{ $charge['label'] }}</td>
                                <td class="text-end">{{ number_format($charge['montant'], 2, ',', ' ') }} &euro;</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-muted text-center">Aucune charge.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="table-warning fw-bold">
                            <td colspan="2">Total Charges</td>
                            <td class="text-end">{{ number_format($totalCharges, 2, ',', ' ') }} &euro;</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Produits --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Produits</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Code CERFA</th>
                            <th>Libellé</th>
                            <th class="text-end">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($produits as $produit)
                            <tr>
                                <td>{{ $produit['code_cerfa'] ?? '-' }}</td>
                                <td>{{ $produit['label'] }}</td>
                                <td class="text-end">{{ number_format($produit['montant'], 2, ',', ' ') }} &euro;</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-muted text-center">Aucun produit.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="table-warning fw-bold">
                            <td colspan="2">Total Produits</td>
                            <td class="text-end">{{ number_format($totalProduits, 2, ',', ' ') }} &euro;</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Résultat net --}}
    <div class="card border-primary">
        <div class="card-body text-center">
            <h5 class="card-title text-muted mb-1">Résultat net (Produits - Charges)</h5>
            <p class="display-6 fw-bold mb-0 {{ $resultatNet >= 0 ? 'text-success' : 'text-danger' }}">
                {{ number_format($resultatNet, 2, ',', ' ') }} &euro;
            </p>
        </div>
    </div>
</div>
