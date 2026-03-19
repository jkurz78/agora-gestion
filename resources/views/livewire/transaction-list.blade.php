<div>
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Boutons de création + import --}}
    <div class="d-flex align-items-center gap-2 mb-3">
        <button wire:click="$dispatch('open-transaction-form', {type: 'depense'})"
                class="btn btn-danger">
            <i class="bi bi-plus-lg"></i> Nouvelle dépense
        </button>
        <button wire:click="$dispatch('open-transaction-form', {type: 'recette'})"
                class="btn btn-success">
            <i class="bi bi-plus-lg"></i> Nouvelle recette
        </button>
        <div class="ms-auto d-flex gap-2">
            <livewire:import-csv type="depense" />
            <livewire:import-csv type="recette" />
        </div>
    </div>

    {{-- Filter row --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 mb-2">
                    <div class="btn-group" role="group">
                        <input type="radio" class="btn-check" wire:model.live="typeFilter" value="" id="filter-type-all">
                        <label class="btn btn-outline-secondary btn-sm" for="filter-type-all">Toutes</label>
                        <input type="radio" class="btn-check" wire:model.live="typeFilter" value="depense" id="filter-type-depense">
                        <label class="btn btn-outline-danger btn-sm" for="filter-type-depense">Dépenses</label>
                        <input type="radio" class="btn-check" wire:model.live="typeFilter" value="recette" id="filter-type-recette">
                        <label class="btn btn-outline-success btn-sm" for="filter-type-recette">Recettes</label>
                    </div>
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

    {{-- Transactions table --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>N°</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Réf.</th>
                    <th>Libellé</th>
                    <th>Tiers</th>
                    <th>Mode</th>
                    <th class="text-end">Montant</th>
                    <th></th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @forelse ($transactions as $transaction)
                    <tr wire:key="transaction-{{ $transaction->id }}">
                        <td class="text-muted small">{{ $transaction->numero_piece ?? '—' }}</td>
                        <td class="text-nowrap small">{{ $transaction->date->format('d/m/Y') }}</td>
                        <td>
                            @if($transaction->type === \App\Enums\TypeTransaction::Depense)
                                <span class="badge bg-danger" style="font-size:.7rem">Dépense</span>
                            @else
                                <span class="badge bg-success" style="font-size:.7rem">Recette</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ $transaction->reference ?? '—' }}</td>
                        <td class="small">
                            {{ $transaction->libelle }}
                            @if(!empty($transaction->notes))
                                <i class="bi bi-sticky text-muted ms-1" title="{{ $transaction->notes }}"></i>
                            @endif
                        </td>
                        <td class="small">@if($transaction->tiers)<span style="font-size:.7rem">{{ $transaction->tiers->type === 'entreprise' ? '🏢' : '👤' }}</span> {{ $transaction->tiers->displayName() }}@else—@endif</td>
                        <td><span class="badge bg-secondary" style="font-size:.7rem">{{ $transaction->mode_paiement->label() }}</span></td>
                        <td class="text-end fw-semibold text-nowrap small">
                            @if($transaction->type === \App\Enums\TypeTransaction::Depense)
                                <span class="text-danger">-{{ number_format((float) $transaction->montant_total, 2, ',', ' ') }} €</span>
                            @else
                                <span class="text-success">{{ number_format((float) $transaction->montant_total, 2, ',', ' ') }} €</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex gap-1 justify-content-end">
                                <button wire:click="$dispatch('edit-transaction', { id: {{ $transaction->id }} })"
                                        class="btn btn-sm btn-outline-primary" title="Modifier"
                                        style="padding:.15rem .35rem;font-size:.75rem">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if ($transaction->pointe)
                                    <button class="btn btn-sm btn-outline-danger" disabled
                                            title="Dépointez cette transaction avant de la supprimer."
                                            style="padding:.15rem .35rem;font-size:.75rem">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @else
                                    <button wire:click="delete({{ $transaction->id }})"
                                            wire:confirm="Supprimer cette transaction ?"
                                            class="btn btn-sm btn-outline-danger" title="Supprimer"
                                            style="padding:.15rem .35rem;font-size:.75rem">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-muted text-center">Aucune transaction trouvée.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-per-page-selector :paginator="$transactions" storageKey="transactions" wire:model.live="perPage" />
    {{ $transactions->links() }}
</div>
