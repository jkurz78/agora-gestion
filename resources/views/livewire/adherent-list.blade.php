<div>
    {{-- Filtres + action --}}
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
               placeholder="Rechercher un adhérent…">

        <a href="{{ route('comptabilite.transactions') }}"
           class="btn btn-primary btn-sm ms-auto">
            <i class="bi bi-plus-lg"></i> Nouvelle cotisation
        </a>
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
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
            <tbody style="color:#555">
                @forelse($membres as $membre)
                    @php $cot = $membre->derniereCotisation; @endphp
                    <tr>
                        <td class="small">
                            @if($membre->type === 'entreprise')
                                <i class="bi bi-building text-muted me-1" style="font-size:.7rem"></i>
                            @else
                                <i class="bi bi-person text-muted me-1" style="font-size:.7rem"></i>
                            @endif
                            {{ $membre->displayName() }}
                        </td>
                        <td class="small text-nowrap">
                            @if($cot)
                                {{ $cot->transaction->date->format('d/m/Y') }}
                                <span class="text-muted">({{ app(\App\Services\ExerciceService::class)->anneeForDate($cot->transaction->date) }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small fw-semibold text-nowrap">
                            @if($cot)
                                {{ number_format((float) $cot->montant, 2, ',', ' ') }} €
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($cot && $cot->transaction->mode_paiement)
                                <span class="badge bg-secondary" style="font-size:.7rem">{{ $cot->transaction->mode_paiement->label() }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="small text-muted">{{ $cot?->transaction?->compte?->nom ?? '—' }}</td>
                        <td class="small">
                            @if($cot && $cot->transaction->statut_reglement === \App\Enums\StatutReglement::Pointe)
                                <i class="bi bi-check-lg text-success"></i>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="{{ route('tiers.transactions', $membre->id) }}"
                                   class="btn btn-sm btn-outline-secondary"
                                   title="Voir les transactions">
                                    <i class="bi bi-clock-history"></i>
                                </a>
                                <a href="{{ route('comptabilite.transactions') }}"
                                   class="btn btn-sm btn-outline-primary"
                                   title="Nouvelle cotisation">
                                    <i class="bi bi-plus-circle"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Aucun adhérent trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-per-page-selector :paginator="$membres" storageKey="adherents" wire:model.live="perPage" />
    {{ $membres->links() }}
</div>
