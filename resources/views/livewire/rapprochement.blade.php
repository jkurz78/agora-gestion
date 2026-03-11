<div>
    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="filter-compte" class="form-label">Compte bancaire</label>
                    <select wire:model.live="compte_id" id="filter-compte" class="form-select form-select-sm">
                        <option value="">-- Sélectionner un compte --</option>
                        @foreach ($comptes as $compte)
                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="filter-date-debut" class="form-label">Date début</label>
                    <input type="date" wire:model.live="date_debut" id="filter-date-debut" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label for="filter-date-fin" class="form-label">Date fin</label>
                    <input type="date" wire:model.live="date_fin" id="filter-date-fin" class="form-control form-control-sm">
                </div>
            </div>
        </div>
    </div>

    @if ($compte_id)
        {{-- Solde théorique --}}
        <div class="card mb-4 border-primary">
            <div class="card-body text-center">
                <h5 class="card-title text-muted mb-1">Solde théorique (pointé)</h5>
                <p class="display-6 fw-bold mb-0 {{ $soldeTheorique >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ number_format($soldeTheorique, 2, ',', ' ') }} &euro;
                </p>
            </div>
        </div>

        {{-- Transactions table --}}
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Libellé</th>
                        <th class="text-end">Montant</th>
                        <th class="text-center">Pointé</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $tx)
                        <tr>
                            <td>{{ $tx->date->format('d/m/Y') }}</td>
                            <td>
                                @switch($tx->type)
                                    @case('depense')
                                        <span class="badge bg-danger">Dépense</span>
                                        @break
                                    @case('recette')
                                        <span class="badge bg-success">Recette</span>
                                        @break
                                    @case('don')
                                        <span class="badge bg-info">Don</span>
                                        @break
                                    @case('cotisation')
                                        <span class="badge bg-warning text-dark">Cotisation</span>
                                        @break
                                @endswitch
                            </td>
                            <td>{{ $tx->label }}</td>
                            <td class="text-end">
                                @if ($tx->type === 'depense')
                                    <span class="text-danger">- {{ number_format($tx->montant, 2, ',', ' ') }} &euro;</span>
                                @else
                                    <span class="text-success">+ {{ number_format($tx->montant, 2, ',', ' ') }} &euro;</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <input type="checkbox"
                                       wire:click="toggle('{{ $tx->type }}', {{ $tx->id }})"
                                       {{ $tx->pointe ? 'checked' : '' }}
                                       class="form-check-input">
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted text-center">Aucune transaction trouvée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Sélectionnez un compte bancaire pour afficher le rapprochement.
        </div>
    @endif
</div>
