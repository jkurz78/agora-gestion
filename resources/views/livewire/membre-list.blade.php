<div>
    {{-- Barre de filtres --}}
    <div class="d-flex gap-3 align-items-center mb-3 flex-wrap">
        <div class="btn-group" role="group">
            <input type="radio" class="btn-check" wire:model.live="filtre" value="a_jour" id="filtre-a-jour">
            <label class="btn btn-outline-success" for="filtre-a-jour">À jour</label>

            <input type="radio" class="btn-check" wire:model.live="filtre" value="en_retard" id="filtre-retard">
            <label class="btn btn-outline-warning" for="filtre-retard">En retard</label>

            <input type="radio" class="btn-check" wire:model.live="filtre" value="tous" id="filtre-tous">
            <label class="btn btn-outline-secondary" for="filtre-tous">Tous</label>
        </div>

        <input type="text"
               wire:model.live.debounce.300ms="search"
               class="form-control form-control-sm"
               style="max-width:250px"
               placeholder="Rechercher un membre…">
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Nom</th>
                    <th>Dernière cotisation</th>
                    <th>Montant</th>
                    <th>Mode</th>
                    <th>Compte</th>
                    <th>Pointé</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($membres as $membre)
                    @php $cot = $membre->derniereCotisation; @endphp
                    <tr>
                        <td>
                            @if($membre->type === 'entreprise')
                                <i class="bi bi-building text-muted me-1" style="font-size:.75rem"></i>
                            @else
                                <i class="bi bi-person text-muted me-1" style="font-size:.75rem"></i>
                            @endif
                            {{ $membre->displayName() }}
                        </td>
                        <td class="text-nowrap">
                            @if($cot)
                                {{ $cot->date_paiement->format('d/m/Y') }}
                                <span class="text-muted small">({{ $cot->exercice }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            @if($cot)
                                {{ number_format((float) $cot->montant, 2, ',', ' ') }} €
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($cot)
                                <span class="badge bg-secondary">{{ $cot->mode_paiement->label() }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $cot?->compte?->nom ?? '—' }}</td>
                        <td>
                            @if($cot)
                                {{ $cot->pointe ? '✓' : '—' }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end">
                            <button
                                wire:click="$dispatch('open-cotisation-for-tiers', { tiersId: {{ $membre->id }} })"
                                class="btn btn-sm btn-outline-primary"
                                title="Nouvelle cotisation">
                                <i class="bi bi-plus-circle"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Aucun membre trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $membres->links() }}
</div>
