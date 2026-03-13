<div>
    {{-- Filtres --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <label for="compteId" class="form-label fw-semibold">Compte bancaire</label>
            <select wire:model.live="compteId" id="compteId" class="form-select">
                <option value="">-- Sélectionnez un compte --</option>
                @foreach ($comptes as $c)
                    <option value="{{ $c->id }}">{{ $c->nom }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label for="dateDebut" class="form-label">Date début</label>
            <input type="date" wire:model.live="dateDebut" id="dateDebut" class="form-control">
        </div>
        <div class="col-md-2">
            <label for="dateFin" class="form-label">Date fin</label>
            <input type="date" wire:model.live="dateFin" id="dateFin" class="form-control">
        </div>
        <div class="col-md-3">
            <label for="searchTiers" class="form-label">Tiers</label>
            <input type="text" wire:model.live.debounce.300ms="searchTiers"
                   id="searchTiers" class="form-control" placeholder="Rechercher un tiers…">
        </div>
    </div>

    @if ($compteId === null)
        <div class="alert alert-info">Sélectionnez un compte bancaire pour afficher les transactions.</div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>
                            <a href="#" wire:click.prevent="sortBy('date')" class="text-white text-decoration-none">
                                Date
                                @if ($sortColumn === 'date')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </a>
                        </th>
                        <th>
                            <a href="#" wire:click.prevent="sortBy('type_label')" class="text-white text-decoration-none">
                                Type
                                @if ($sortColumn === 'type_label')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </a>
                        </th>
                        <th>
                            <a href="#" wire:click.prevent="sortBy('tiers')" class="text-white text-decoration-none">
                                Tiers
                                @if ($sortColumn === 'tiers')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </a>
                        </th>
                        <th>Libellé</th>
                        <th>Référence</th>
                        <th class="text-end">
                            <a href="#" wire:click.prevent="sortBy('montant')" class="text-white text-decoration-none">
                                Montant
                                @if ($sortColumn === 'montant')
                                    <i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </a>
                        </th>
                        <th class="text-center">Pointé</th>
                        @if ($showSolde)
                            <th class="text-end">Solde courant</th>
                        @endif
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $tx)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($tx->date)->format('d/m/Y') }}</td>
                            <td>{{ $tx->type_label }}</td>
                            <td>{{ $tx->tiers ?? '—' }}</td>
                            <td>{{ $tx->libelle ?? '—' }}</td>
                            <td>{{ $tx->reference ?? '' }}</td>
                            <td class="text-end {{ $tx->montant >= 0 ? 'text-success' : 'text-danger' }} fw-semibold">
                                {{ number_format((float) $tx->montant, 2, ',', ' ') }} €
                            </td>
                            <td class="text-center">
                                @if ($tx->pointe)
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                @endif
                            </td>
                            @if ($showSolde)
                                <td class="text-end">
                                    {{ isset($tx->solde_courant) ? number_format((float) $tx->solde_courant, 2, ',', ' ') . ' €' : '' }}
                                </td>
                            @endif
                            <td>
                                <button type="button"
                                        wire:click="redirectToEdit('{{ $tx->source_type }}', {{ $tx->id }})"
                                        class="btn btn-sm btn-outline-primary me-1"
                                        title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button"
                                        wire:click="deleteTransaction('{{ $tx->source_type }}', {{ $tx->id }})"
                                        wire:confirm="Supprimer cette transaction ?"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $showSolde ? 9 : 8 }}" class="text-center text-muted">
                                Aucune transaction trouvée.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (! $showSolde && $compteId !== null)
            <p class="text-muted small mt-2">
                <i class="bi bi-info-circle"></i>
                Le solde courant est masqué car un filtre tiers est actif ou le tri n'est pas par date croissante.
            </p>
        @endif

        <div class="mt-3">
            {{ $paginator->links() }}
        </div>
    @endif
</div>
