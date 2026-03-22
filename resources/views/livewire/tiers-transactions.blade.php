<div>
    <h5 class="mb-3">{{ $tiers->displayName() }}</h5>

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
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Du</label>
                    <x-date-input name="date_debut" wire:model.live="dateDebut" :value="$dateDebut" />
                </div>
                <div class="col-md-2">
                    <label class="form-label">Au</label>
                    <x-date-input name="date_fin" wire:model.live="dateFin" :value="$dateFin" />
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
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
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
            <tbody style="color:#555">
                @forelse($transactions as $tx)
                    <tr>
                        <td class="text-nowrap small">{{ \Carbon\Carbon::parse($tx->date)->format('d/m/Y') }}</td>
                        <td>
                            @php
                                $badgeClass = match($tx->source_type) {
                                    'recette'    => 'bg-success',
                                    'depense'    => 'bg-danger',
                                    default      => 'bg-light text-dark',
                                };
                                $label = match($tx->source_type) {
                                    'recette'    => 'Recette',
                                    'depense'    => 'Dépense',
                                    default      => $tx->source_type,
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}" style="font-size:.7rem">{{ $label }}</span>
                        </td>
                        <td class="small">{{ $tx->libelle }}</td>
                        <td class="small text-muted">{{ $tx->compte ?? '—' }}</td>
                        <td class="text-end text-nowrap fw-semibold small @if($tx->source_type === 'recette') text-success @else text-danger @endif">
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

    <x-per-page-selector :paginator="$transactions" storageKey="tiers-transactions" wire:model.live="perPage" />
    {{ $transactions->links() }}
</div>
