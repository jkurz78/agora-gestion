<div>
    {{-- Modale avertissement avant première émission reçu fiscal --}}
    @if($showModaleAvertissement)
        <div class="modal fade show d-block" tabindex="-1" style="z-index:2060;background-color:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Vérifications avant émission</h5>
                        <button type="button" class="btn-close" wire:click="fermerModaleAvertissement"></button>
                    </div>
                    <div class="modal-body">
                        @if(in_array('helloasso', $avertissementsActifs))
                            <div class="alert alert-warning">
                                <strong>HelloAsso peut avoir déjà émis un reçu fiscal pour ce don.</strong>
                                Le donateur ne doit pas déduire deux fois le même montant.
                            </div>
                        @endif
                        @if(in_array('donnees_modifiees', $avertissementsActifs))
                            <div class="alert alert-info">
                                Les coordonnées du donateur ou de l'association ont été modifiées depuis le don. Le reçu portera les coordonnées actuelles.
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fermerModaleAvertissement">Annuler</button>
                        <button type="button" class="btn btn-primary" wire:click="continuerTelechargement">Continuer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modale annulation + ré-émission reçu fiscal --}}
    @if($showModaleAnnulation)
        <div class="modal fade show d-block" tabindex="-1" style="z-index:2060;background-color:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Annuler et ré-émettre</h5>
                        <button type="button" class="btn-close" wire:click="fermerModaleAnnulation"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">Le reçu actuel sera annulé et un nouveau sera généré avec les coordonnées actuelles du tiers.</p>
                        <label for="motif-annulation" class="form-label">Motif</label>
                        <input type="text"
                               id="motif-annulation"
                               class="form-control"
                               wire:model="motifAnnulation"
                               placeholder="Ex : Adresse corrigée">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fermerModaleAnnulation">Annuler</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmerReEmission">
                            Confirmer la ré-émission
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Cumul global --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <span class="text-muted small">Total dons :</span>
            <span class="fw-semibold">{{ number_format((float) $dto->totalMontant, 2, ',', ' ') }} €</span>
            <span class="text-muted small">({{ $dto->totalCount }})</span>
        </div>
    </div>

    {{-- Encart blocage global --}}
    @if($dto->raisonBlocageGlobal)
        <div class="alert alert-warning small">{{ $dto->raisonBlocageGlobal }}</div>
    @endif

    {{-- Groupes par année civile --}}
    @foreach($dto->annees as $annee)
        <div class="card mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <span class="fw-semibold">{{ $annee->annee }}</span>
                <span class="text-muted small">{{ $annee->count }} don{{ $annee->count > 1 ? 's' : '' }} • {{ number_format((float) $annee->total, 2, ',', ' ') }} €</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Date</th>
                            <th>Nature du don</th>
                            <th>Opération</th>
                            <th class="text-end">Montant</th>
                            <th>Reçu fiscal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($annee->lignes as $ligneDto)
                        @php $don = $ligneDto->ligne; @endphp
                        <tr>
                            <td data-sort="{{ optional($don->transaction->date)->format('Y-m-d') }}">
                                {{ optional($don->transaction->date)->format('d/m/Y') }}
                            </td>
                            <td>{{ $don->sousCategorie->nom ?? '—' }}</td>
                            <td>{{ $don->operation->nom ?? '—' }}</td>
                            <td class="text-end" data-sort="{{ $don->montant }}">
                                {{ number_format((float) $don->montant, 2, ',', ' ') }} €
                            </td>
                            <td>
                                @if($ligneDto->recu)
                                    <span class="badge text-bg-success">Reçu émis</span>
                                    <button type="button"
                                            class="btn btn-link btn-sm p-0 ms-1"
                                            wire:click="ouvrirModaleAnnulation({{ $ligneDto->recu->id }})"
                                            title="Annuler et ré-émettre">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </button>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($ligneDto->peutTelecharger)
                                    @if(count($ligneDto->alertes) > 0)
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                wire:click="afficherAvertissements({{ $don->id }})"
                                                title="Télécharger reçu (avertissements)">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            Télécharger
                                        </button>
                                    @else
                                        <a href="{{ route('tiers.dons.recu-fiscal', ['tiers' => $tiers, 'ligne' => $don->id]) }}"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-primary"
                                           title="Télécharger reçu">
                                            <i class="bi bi-download"></i>
                                            Télécharger
                                        </a>
                                    @endif
                                @else
                                    <span class="text-muted small" title="{{ $ligneDto->raisonBlocage }}">
                                        {{ $ligneDto->raisonBlocage }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    @if(count($dto->annees) === 0)
        <div class="text-muted small">Aucun don enregistré pour ce tiers.</div>
    @endif
</div>
