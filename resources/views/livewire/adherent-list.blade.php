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

        <div class="dropdown ms-auto">
            <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-plus-lg"></i> Nouvelle adhésion
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <button type="button" class="dropdown-item" onclick="window.Livewire.dispatch('nouvelle-adhesion')">
                        <i class="bi bi-cash-coin me-1"></i> Nouvelle cotisation (avec paiement)
                    </button>
                </li>
                <li>
                    <button type="button" class="dropdown-item" onclick="window.Livewire.dispatch('nouvelle-adhesion', { gratuite: true })">
                        <i class="bi bi-gift me-1"></i> Adhésion gratuite (offerte)
                    </button>
                </li>
            </ul>
        </div>
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Nom</th>
                    <th>Dernière adhésion</th>
                    <th>Type</th>
                    <th>Formule</th>
                    <th>Validité</th>
                    <th>Montant</th>
                    <th>Mode</th>
                    <th>Compte</th>
                    <th>Pointé</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @forelse($membres as $membre)
                    @php $adh = $membre->derniereAdhesion; @endphp
                    <tr style="cursor:pointer" data-tiers-href="{{ route('tiers.show', $membre->id) }}" onclick="if (!event.target.closest('button,a,input,select,textarea')) { window.location = this.dataset.tiersHref; }">
                        <td class="small">
                            @if($membre->type === 'entreprise')
                                <i class="bi bi-building text-muted me-1" style="font-size:.7rem"></i>
                            @else
                                <i class="bi bi-person text-muted me-1" style="font-size:.7rem"></i>
                            @endif
                            {{ $membre->displayName() }}
                        </td>
                        <td class="small text-nowrap">
                            @if($adh && ! $adh->estGratuite() && $adh->transaction)
                                {{ $adh->transaction->date->format('d/m/Y') }}
                                <span class="text-muted">({{ app(\App\Services\ExerciceService::class)->anneeForDate($adh->transaction->date) }})</span>
                            @elseif($adh)
                                <span class="text-muted">Ex. {{ $adh->exercice }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small">
                            @if($adh && $adh->estGratuite())
                                <span class="badge text-bg-warning">Offerte</span>
                                @if($adh->notes)
                                    <span class="text-muted ms-1" style="font-size:.75rem">{{ $adh->notes }}</span>
                                @endif
                            @elseif($adh)
                                <span class="badge text-bg-success">Cotisation</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="small">
                            @if($adh && $adh->formuleAdhesion)
                                <span class="badge text-bg-info">{{ $adh->formuleAdhesion->nom }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small text-nowrap">
                            @if($adh && $adh->date_debut && $adh->date_fin)
                                {{ $adh->date_debut->format('d/m/Y') }} → {{ $adh->date_fin->format('d/m/Y') }}
                            @elseif($adh && $adh->exercice)
                                Ex. {{ $adh->exercice }}-{{ $adh->exercice + 1 }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small fw-semibold text-nowrap">
                            @if($adh && ! $adh->estGratuite() && $adh->transaction)
                                {{ number_format((float) $adh->transaction->lignes->sum('montant'), 2, ',', ' ') }} €
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($adh && ! $adh->estGratuite() && $adh->transaction?->mode_paiement)
                                <span class="badge bg-secondary" style="font-size:.7rem">{{ $adh->transaction->mode_paiement->label() }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="small text-muted">{{ $adh?->transaction?->compte?->nom ?? '—' }}</td>
                        <td class="small">
                            @if($adh && ! $adh->estGratuite() && $adh->transaction?->statut_reglement === \App\Enums\StatutReglement::Pointe)
                                <i class="bi bi-check-lg text-success"></i>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <x-tiers-row-trigger :tiersId="$membre->id" />
                                <a href="{{ route('tiers.transactions', $membre->id) }}"
                                   class="btn btn-sm btn-outline-secondary"
                                   title="Voir les transactions">
                                    <i class="bi bi-clock-history"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">Aucun adhérent trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-per-page-selector :paginator="$membres" storageKey="adherents" wire:model.live="perPage" />
    {{ $membres->links() }}
</div>
