<div>
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Filter --}}
    <div class="card mb-4">
        <div class="card-body py-2">
            <div class="row g-2">
                <div class="col-md-3">
                    <input type="text" wire:model.live.debounce.300ms="tiers_search"
                           class="form-control form-control-sm" placeholder="Rechercher un membre...">
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Exercice</th>
                    <th>Membre</th>
                    <th>Date paiement</th>
                    <th class="text-end">Montant</th>
                    <th>Mode paiement</th>
                    <th>Compte</th>
                    <th>Pointé</th>
                    <th style="width: 80px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cotisations as $cotisation)
                    <tr>
                        <td class="text-muted small">{{ $cotisation->exercice }}-{{ $cotisation->exercice + 1 }}</td>
                        <td>{{ $cotisation->tiers?->displayName() ?? '—' }}</td>
                        <td>{{ $cotisation->date_paiement->format('d/m/Y') }}</td>
                        <td class="text-end">{{ number_format((float) $cotisation->montant, 2, ',', ' ') }} &euro;</td>
                        <td>{{ $cotisation->mode_paiement->label() }}</td>
                        <td>{{ $cotisation->compte?->nom ?? '—' }}</td>
                        <td>
                            @if ($cotisation->pointe)
                                <span class="badge bg-success">Oui</span>
                            @else
                                <span class="badge bg-secondary">Non</span>
                            @endif
                        </td>
                        <td>
                            @if ($cotisation->pointe)
                                <button class="btn btn-sm btn-outline-danger" disabled
                                        title="Dépointez cette cotisation avant de la supprimer.">
                                    <i class="bi bi-trash"></i>
                                </button>
                            @else
                                <button wire:click="delete({{ $cotisation->id }})"
                                        wire:confirm="Supprimer cette cotisation ?"
                                        class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            @endif
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

    {{ $cotisations->links() }}
</div>
