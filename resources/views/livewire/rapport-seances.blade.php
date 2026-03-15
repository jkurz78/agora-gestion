<div>
    {{-- Filter --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="filter-operation" class="form-label">Opération</label>
                    <select wire:model.live="operation_id" id="filter-operation" class="form-select form-select-sm">
                        <option value="">-- Sélectionner une opération --</option>
                        @foreach ($operations as $op)
                            <option value="{{ $op->id }}">{{ $op->nom }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($operation_id)
                    <div class="col-md-3 d-flex align-items-end">
                        <button wire:click="exportCsv" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-download"></i> Exporter CSV
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($operation && count($data) > 0)
        @php
            $nbSeances = $operation->nombre_seances ?? 0;
            $depenseRows = collect($data)->where('type', 'depense');
            $recetteRows = collect($data)->where('type', 'recette');
        @endphp

        <div class="table-responsive">
            <table class="table table-striped table-hover table-bordered">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Sous-catégorie</th>
                        @for ($i = 1; $i <= $nbSeances; $i++)
                            <th class="text-end">Séance {{ $i }}</th>
                        @endfor
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Charges --}}
                    <tr class="table-secondary">
                        <td colspan="{{ $nbSeances + 2 }}" class="fw-bold">Charges</td>
                    </tr>
                    @foreach ($depenseRows as $row)
                        <tr>
                            <td class="ps-4">{{ $row['sous_categorie'] }}</td>
                            @for ($i = 1; $i <= $nbSeances; $i++)
                                <td class="text-end">{{ number_format($row['seances'][$i] ?? 0, 2, ',', ' ') }} &euro;</td>
                            @endfor
                            <td class="text-end fw-bold">{{ number_format($row['total'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                    @endforeach

                    {{-- Produits --}}
                    <tr class="table-secondary">
                        <td colspan="{{ $nbSeances + 2 }}" class="fw-bold">Produits</td>
                    </tr>
                    @foreach ($recetteRows as $row)
                        <tr>
                            <td class="ps-4">{{ $row['sous_categorie'] }}</td>
                            @for ($i = 1; $i <= $nbSeances; $i++)
                                <td class="text-end">{{ number_format($row['seances'][$i] ?? 0, 2, ',', ' ') }} &euro;</td>
                            @endfor
                            <td class="text-end fw-bold">{{ number_format($row['total'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                    @endforeach

                    {{-- Solde per séance --}}
                    <tr class="table-primary fw-bold">
                        <td>Solde</td>
                        @for ($i = 1; $i <= $nbSeances; $i++)
                            @php
                                $seanceRecettes = $recetteRows->sum(fn ($r) => $r['seances'][$i] ?? 0);
                                $seanceDepenses = $depenseRows->sum(fn ($r) => $r['seances'][$i] ?? 0);
                                $solde = $seanceRecettes - $seanceDepenses;
                            @endphp
                            <td class="text-end {{ $solde >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ number_format($solde, 2, ',', ' ') }} &euro;
                            </td>
                        @endfor
                        @php
                            $totalRecettes = $recetteRows->sum('total');
                            $totalDepenses = $depenseRows->sum('total');
                            $totalSolde = $totalRecettes - $totalDepenses;
                        @endphp
                        <td class="text-end {{ $totalSolde >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ number_format($totalSolde, 2, ',', ' ') }} &euro;
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    @elseif ($operation_id)
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Aucune donnée par séance pour cette opération.
        </div>
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Sélectionnez une opération avec des séances.
        </div>
    @endif
</div>
