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
                <div class="col-md-2">
                    <label for="filter-exercice" class="form-label">Exercice</label>
                    <select wire:model.live="exercice" id="filter-exercice" class="form-select form-select-sm">
                        @foreach ($exercices as $ex)
                            <option value="{{ $ex }}">{{ $exerciceService->label($ex) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-categorie" class="form-label">Catégorie</label>
                    <select wire:model.live="categorie_id" id="filter-categorie" class="form-select form-select-sm">
                        <option value="">Toutes</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-operation" class="form-label">Opération</label>
                    <select wire:model.live="operation_id" id="filter-operation" class="form-select form-select-sm">
                        <option value="">Toutes</option>
                        @foreach ($operations as $op)
                            <option value="{{ $op->id }}">{{ $op->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-compte" class="form-label">Compte</label>
                    <select wire:model.live="compte_id" id="filter-compte" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        @foreach ($comptes as $compte)
                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-pointe" class="form-label">Pointé</label>
                    <select wire:model.live="pointe" id="filter-pointe" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="1">Oui</option>
                        <option value="0">Non</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-tiers" class="form-label">Tiers</label>
                    <input type="text" wire:model.live.debounce.300ms="tiers"
                           id="filter-tiers"
                           class="form-control form-control-sm" placeholder="Tiers...">
                </div>
            </div>
        </div>
    </div>

    {{-- Recettes table --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>N°</th>
                    <th>Date</th>
                    <th>Réf.</th>
                    <th>Libellé</th>
                    <th>Tiers</th>
                    <th>Mode</th>
                    <th class="text-end">Montant</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recettes as $recette)
                    <tr wire:key="recette-{{ $recette->id }}">
                        <td class="text-muted small">{{ $recette->numero_piece ?? '—' }}</td>
                        <td class="text-nowrap">{{ $recette->date->format('d/m/Y') }}</td>
                        <td class="text-muted small">{{ $recette->reference ?? '—' }}</td>
                        <td>{{ $recette->libelle }}</td>
                        <td>{{ $recette->tiers ?? '—' }}</td>
                        <td><span class="badge bg-secondary">{{ $recette->mode_paiement->label() }}</span></td>
                        <td class="text-end text-success fw-semibold text-nowrap">
                            {{ number_format((float) $recette->montant_total, 2, ',', ' ') }} €
                        </td>
                        <td>
                            <div class="d-flex gap-1 justify-content-end">
                                <button wire:click="$dispatch('edit-recette', { id: {{ $recette->id }} })"
                                        class="btn btn-sm btn-outline-primary" title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if ($recette->pointe)
                                    <button class="btn btn-sm btn-outline-danger" disabled
                                            title="Dépointez cette recette avant de la supprimer.">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @else
                                    <button wire:click="delete({{ $recette->id }})"
                                            wire:confirm="Supprimer cette recette ?"
                                            class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-muted text-center">Aucune recette trouvée.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $recettes->links() }}
</div>
