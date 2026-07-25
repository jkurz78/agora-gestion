<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Postes tiers ouverts</h1>
            <p class="text-muted mb-0">Créances clients et dettes fournisseurs restant à régler.</p>
        </div>
        <span class="badge text-bg-light border">Exercice {{ $exercice }}</span>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-sm-6 col-lg-2">
                    <label for="postes-tiers-compte" class="form-label small">Type</label>
                    <select id="postes-tiers-compte" class="form-select" wire:model.live="filtreCompte">
                        <option value="">Tous</option>
                        <option value="411">Clients (411)</option>
                        <option value="401">Fournisseurs (401)</option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="postes-tiers-tiers" class="form-label small">Tiers</label>
                    <select id="postes-tiers-tiers" class="form-select" wire:model.live="filtreTiersId">
                        <option value="">Tous les tiers</option>
                        @foreach($tiers as $tiersItem)
                            <option value="{{ $tiersItem->id }}">{{ $tiersItem->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="postes-tiers-exercice-origine" class="form-label small">Exercice d’origine</label>
                    <select id="postes-tiers-exercice-origine" class="form-select" wire:model.live="filtreExerciceOrigine">
                        <option value="">Tous</option>
                        @foreach($exercices as $exerciceItem)
                            <option value="{{ $exerciceItem->annee }}">{{ $exerciceItem->annee }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-5">
                    <label for="postes-tiers-recherche" class="form-label small">Recherche</label>
                    <input id="postes-tiers-recherche" type="search" class="form-control"
                           placeholder="Tiers, pièce, référence ou libellé"
                           wire:model.live.debounce.300ms="recherche">
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Type</th>
                    <th>Tiers</th>
                    <th class="text-end">Solde</th>
                    <th>Date d’origine</th>
                    <th>Pièce</th>
                    <th>Référence</th>
                    <th>Libellé</th>
                    <th>Exercice</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($postes as $poste)
                    <tr>
                        <td>
                            @if($poste->numeroCompte === '411')
                                <span class="badge text-bg-success">Client</span>
                            @else
                                <span class="badge text-bg-warning">Fournisseur</span>
                            @endif
                        </td>
                        <td>{{ $poste->tiersNom }}</td>
                        <td class="text-end" data-sort="{{ $poste->soldeCentimes }}">
                            {{ number_format($poste->soldeCentimes / 100, 2, ',', ' ') }} €
                        </td>
                        <td data-sort="{{ $poste->dateOrigine->toDateString() }}">
                            {{ $poste->dateOrigine->format('d/m/Y') }}
                        </td>
                        <td>{{ $poste->numeroPiece ?? '—' }}</td>
                        <td>{{ $poste->reference ?? '—' }}</td>
                        <td>
                            {{ $poste->libelle }}
                            @if($poste->estReporte)
                                <span class="badge text-bg-info ms-1">Report AN</span>
                            @endif
                        </td>
                        <td>{{ $poste->exerciceOrigine }}</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-primary"
                                    wire:click="regler({{ $poste->ligneActionId }})">
                                Régler
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            Aucun poste tiers ouvert pour ces critères.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $postes->links() }}

    <livewire:compta.poste-tiers-reglement-modal :exercice="$exercice" />
    <livewire:compta.annulation-reglement-tiers-modal />
</div>
