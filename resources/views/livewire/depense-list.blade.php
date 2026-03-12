<div>
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
                    <label for="filter-beneficiaire" class="form-label">Bénéficiaire</label>
                    <input type="text" wire:model.live.debounce.300ms="beneficiaire"
                           id="filter-beneficiaire"
                           class="form-control form-control-sm" placeholder="Bénéficiaire...">
                </div>
            </div>
        </div>
    </div>

    {{-- Depenses table --}}
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Date</th>
                    <th>Libellé</th>
                    <th>Référence</th>
                    <th class="text-end">Montant</th>
                    <th>Mode paiement</th>
                    <th>Bénéficiaire</th>
                    <th>Pointé</th>
                    <th style="width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($depenses as $depense)
                    <tr>
                        <td>{{ $depense->date->format('d/m/Y') }}</td>
                        <td>{{ $depense->libelle }}</td>
                        <td>{{ $depense->reference ?? '-' }}</td>
                        <td class="text-end">{{ number_format((float) $depense->montant_total, 2, ',', ' ') }} &euro;</td>
                        <td>{{ $depense->mode_paiement->label() }}</td>
                        <td>{{ $depense->beneficiaire ?? '-' }}</td>
                        <td>
                            @if ($depense->pointe)
                                <span class="badge bg-success">Oui</span>
                            @else
                                <span class="badge bg-secondary">Non</span>
                            @endif
                        </td>
                        <td>
                            <button wire:click="$dispatch('edit-depense', { id: {{ $depense->id }} })"
                                    class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Modifier
                            </button>
                            <button wire:click="delete({{ $depense->id }})"
                                    wire:confirm="Supprimer cette dépense ?"
                                    class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-muted text-center">Aucune dépense trouvée.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $depenses->links() }}
</div>
