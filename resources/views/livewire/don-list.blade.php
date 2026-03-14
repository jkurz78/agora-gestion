<div>
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Filter row --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="filter-tiers" class="form-label">Donateur</label>
                    <input type="text" wire:model.live.debounce.300ms="tiers_search" id="filter-tiers"
                           class="form-control form-control-sm" placeholder="Rechercher un donateur...">
                </div>
                <div class="col-md-3">
                    <label for="filter-operation" class="form-label">Opération</label>
                    <select wire:model.live="operation_id" id="filter-operation" class="form-select form-select-sm">
                        <option value="">Toutes</option>
                        @foreach ($operations as $op)
                            <option value="{{ $op->id }}">{{ $op->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Dons table --}}
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Date</th>
                    <th>Donateur</th>
                    <th class="text-end">Montant</th>
                    <th>Mode paiement</th>
                    <th>Objet</th>
                    <th>Opération</th>
                    <th>Pointé</th>
                    <th style="width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($dons as $don)
                    <tr>
                        <td>{{ $don->date->format('d/m/Y') }}</td>
                        <td>
                            @if ($don->tiers)
                                <a href="#" wire:click.prevent="toggleTiersHistory({{ $don->tiers->id }})"
                                   class="text-decoration-none">
                                    {{ $don->tiers->displayName() }}
                                </a>
                            @else
                                <span class="text-muted fst-italic">Anonyme</span>
                            @endif
                        </td>
                        <td class="text-end">{{ number_format((float) $don->montant, 2, ',', ' ') }} &euro;</td>
                        <td>{{ $don->mode_paiement->label() }}</td>
                        <td>{{ $don->objet ?? '-' }}</td>
                        <td>{{ $don->operation?->nom ?? '-' }}</td>
                        <td>
                            @if ($don->pointe)
                                <span class="badge bg-success">Oui</span>
                            @else
                                <span class="badge bg-secondary">Non</span>
                            @endif
                        </td>
                        <td>
                            <button wire:click="$dispatch('edit-don', { id: {{ $don->id }} })"
                                    class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Modifier
                            </button>
                            @if ($don->pointe)
                                <button class="btn btn-sm btn-outline-danger" disabled
                                        title="Dépointez ce don avant de le supprimer.">
                                    <i class="bi bi-trash"></i>
                                </button>
                            @else
                                <button wire:click="delete({{ $don->id }})"
                                        wire:confirm="Supprimer ce don ?"
                                        class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            @endif
                        </td>
                    </tr>

                    {{-- Tiers history inline --}}
                    @if ($don->tiers && $showTiersId === $don->tiers->id)
                        <tr>
                            <td colspan="8" class="bg-light p-3">
                                <h6 class="mb-2">Historique des dons de {{ $don->tiers->displayName() }}</h6>
                                <table class="table table-sm table-bordered mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-end">Montant</th>
                                            <th>Mode</th>
                                            <th>Objet</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($tiersDons as $histDon)
                                            <tr>
                                                <td>{{ $histDon->date->format('d/m/Y') }}</td>
                                                <td class="text-end">{{ number_format((float) $histDon->montant, 2, ',', ' ') }} &euro;</td>
                                                <td>{{ $histDon->mode_paiement->label() }}</td>
                                                <td>{{ $histDon->objet ?? '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="8" class="text-muted text-center">Aucun don trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $dons->links() }}
</div>
