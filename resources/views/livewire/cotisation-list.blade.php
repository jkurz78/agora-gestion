<div>
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Toolbar --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <button wire:click="$dispatch('open-cotisation-form', { id: null })"
                class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Nouvelle cotisation
        </button>
    </div>

    {{-- Filter --}}
    <div class="card mb-4">
        <div class="card-body py-2">
            <div class="row g-2">
                <div class="col-md-3">
                    <input type="text" wire:model.live.debounce.300ms="tiers_search"
                           class="form-control form-control-sm" placeholder="Rechercher un membre...">
                </div>
                <div class="col-md-3">
                    <select wire:model.live="sous_categorie_id" class="form-select form-select-sm">
                        <option value="">Tous les postes</option>
                        @foreach ($postescotisation as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Membre</th>
                    <th>Poste comptable</th>
                    <th>Date paiement</th>
                    <th class="text-end">Montant</th>
                    <th>Mode paiement</th>
                    <th>Compte</th>
                    <th>Pointé</th>
                    <th style="width: 80px;">Actions</th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @forelse ($cotisations as $cotisation)
                    <tr>
                        <td class="small">
    @if($cotisation->tiers)
        <a href="{{ route('tiers.transactions', $cotisation->tiers->id) }}"
           class="text-decoration-none text-reset">
            <span style="font-size:.7rem">{{ $cotisation->tiers->type === 'entreprise' ? '🏢' : '👤' }}</span>
            {{ $cotisation->tiers->displayName() }}
        </a>
    @else
        <span class="text-muted">—</span>
    @endif
</td>
                        <td class="small text-muted">{{ $cotisation->sousCategorie?->nom ?? '—' }}</td>
                        <td class="small text-nowrap">{{ $cotisation->date_paiement->format('d/m/Y') }}</td>
                        <td class="text-end fw-semibold small text-nowrap">{{ number_format((float) $cotisation->montant, 2, ',', ' ') }} &euro;</td>
                        <td><span class="badge bg-secondary" style="font-size:.7rem">{{ $cotisation->mode_paiement->label() }}</span></td>
                        <td class="small text-muted">{{ $cotisation->compte?->nom ?? '—' }}</td>
                        <td>
                            @if ($cotisation->pointe)
                                <i class="bi bi-check-lg text-success"></i>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex gap-1 justify-content-end">
                                <button wire:click="$dispatch('open-cotisation-form', { id: {{ $cotisation->id }} })"
                                        class="btn btn-sm btn-outline-primary" title="Modifier"
                                        @if($cotisation->pointe) disabled @endif>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if ($cotisation->pointe)
                                    <button class="btn btn-sm btn-outline-danger" disabled
                                            title="Dépointez cette cotisation avant de la supprimer.">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @else
                                    <button wire:click="delete({{ $cotisation->id }})"
                                            wire:confirm="Supprimer cette cotisation ?"
                                            class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-muted text-center py-3">Aucune cotisation pour cet exercice.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-per-page-selector :paginator="$cotisations" storageKey="cotisations" wire:model.live="perPage" />
    {{ $cotisations->links() }}
</div>
