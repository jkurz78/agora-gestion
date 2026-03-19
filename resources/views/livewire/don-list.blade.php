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
                <div class="col-md-3">
                    <label for="filter-nature" class="form-label">Nature du don</label>
                    <select wire:model.live="sous_categorie_id" id="filter-nature" class="form-select form-select-sm">
                        <option value="">Toutes</option>
                        @foreach ($naturesdon as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Dons table --}}
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Date</th>
                    <th>Donateur</th>
                    <th>Nature du don</th>
                    <th class="text-end">Montant</th>
                    <th>Mode paiement</th>
                    <th>Objet</th>
                    <th>Opération</th>
                    <th>Pointé</th>
                    <th style="width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @forelse ($dons as $don)
                    <tr>
                        <td class="small text-nowrap">{{ $don->date->format('d/m/Y') }}</td>
                        <td class="small">
                            @if ($don->tiers)
                                <a href="#" wire:click.prevent="toggleTiersHistory({{ $don->tiers->id }})"
                                   class="text-decoration-none" style="color:#555">
                                    <span style="font-size:.7rem">{{ $don->tiers->type === 'entreprise' ? '🏢' : '👤' }}</span> {{ $don->tiers->displayName() }}
                                </a>
                            @else
                                <span class="text-muted fst-italic">Anonyme</span>
                            @endif
                        </td>
                        <td class="small text-muted">{{ $don->sousCategorie?->nom ?? '—' }}</td>
                        <td class="text-end fw-semibold small text-nowrap">{{ number_format((float) $don->montant, 2, ',', ' ') }} &euro;</td>
                        <td><span class="badge bg-secondary" style="font-size:.7rem">{{ $don->mode_paiement->label() }}</span></td>
                        <td class="small">{{ $don->objet ?? '-' }}</td>
                        <td class="small text-muted">{{ $don->operation?->nom ?? '-' }}</td>
                        <td>
                            @if ($don->pointe)
                                <i class="bi bi-check-lg text-success"></i>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex gap-1 justify-content-end">
                                <button wire:click="$dispatch('edit-don', { id: {{ $don->id }} })"
                                        class="btn btn-sm btn-outline-primary" title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if ($don->pointe)
                                    <button class="btn btn-sm btn-outline-danger" disabled
                                            title="Dépointez ce don avant de le supprimer.">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @else
                                    <button wire:click="delete({{ $don->id }})"
                                            wire:confirm="Supprimer ce don ?"
                                            class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>

                    {{-- Tiers history inline --}}
                    @if ($don->tiers && $showTiersId === $don->tiers->id)
                        <tr>
                            <td colspan="9" class="bg-light p-3">
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
                        <td colspan="9" class="text-muted text-center">Aucun don trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-per-page-selector :paginator="$dons" storageKey="dons" wire:model.live="perPage" />
    {{ $dons->links() }}
</div>
