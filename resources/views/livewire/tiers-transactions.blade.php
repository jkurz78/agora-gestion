<div>
    <h5 class="mb-3">{{ $tiers->nom }}</h5>

    {{-- Filtres --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select wire:model.live="typeFilter" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="depense">Dépense</option>
                        <option value="recette">Recette</option>
                        <option value="don">Don</option>
                        <option value="cotisation">Cotisation</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Du</label>
                    <input type="date" wire:model.live="dateDebut" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Au</label>
                    <input type="date" wire:model.live="dateFin" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Libellé</label>
                    <input type="text" wire:model.live.debounce.300ms="search"
                           class="form-control form-control-sm" placeholder="Rechercher…">
                </div>
            </div>
        </div>
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th style="cursor:pointer" wire:click="sort('date')">
                        Date @if($sortBy === 'date') {{ $sortDir === 'asc' ? '▲' : '▼' }} @endif
                    </th>
                    <th style="cursor:pointer" wire:click="sort('source_type')">
                        Type @if($sortBy === 'source_type') {{ $sortDir === 'asc' ? '▲' : '▼' }} @endif
                    </th>
                    <th>Libellé</th>
                    <th>Compte</th>
                    <th class="text-end" style="cursor:pointer" wire:click="sort('montant')">
                        Montant @if($sortBy === 'montant') {{ $sortDir === 'asc' ? '▲' : '▼' }} @endif
                    </th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $tx)
                    <tr>
                        <td class="text-nowrap">{{ \Carbon\Carbon::parse($tx->date)->format('d/m/Y') }}</td>
                        <td>
                            @php
                                $badgeClass = match($tx->source_type) {
                                    'recette'    => 'bg-success',
                                    'depense'    => 'bg-danger',
                                    'don'        => 'bg-info',
                                    'cotisation' => 'bg-secondary',
                                    default      => 'bg-light text-dark',
                                };
                                $label = match($tx->source_type) {
                                    'recette'    => 'Recette',
                                    'depense'    => 'Dépense',
                                    'don'        => 'Don',
                                    'cotisation' => 'Cotisation',
                                    default      => $tx->source_type,
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ $label }}</span>
                        </td>
                        <td>{{ $tx->libelle }}</td>
                        <td>{{ $tx->compte ?? '—' }}</td>
                        <td class="text-end text-nowrap fw-semibold @if(in_array($tx->source_type, ['recette','don'])) text-success @else text-danger @endif">
                            {{ number_format((float) $tx->montant, 2, ',', ' ') }} €
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">Aucune transaction trouvée.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $transactions->links() }}
</div>
