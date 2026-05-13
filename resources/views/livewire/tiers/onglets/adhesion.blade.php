<div>
    @if($recuFiscalError !== null)
        <div class="alert alert-danger alert-dismissible fade show py-2 small mb-2" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Émission du reçu fiscal impossible :</strong> {{ $recuFiscalError }}
            <button type="button" class="btn-close" wire:click="dismissRecuFiscalError" aria-label="Fermer"></button>
        </div>
    @endif

    {{-- Modale avertissement doublon HelloAsso --}}
    @if($showHelloAssoWarning)
        <div class="modal fade show d-block" tabindex="-1" style="z-index:2060;background-color:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Avertissement avant émission</h5>
                        <button type="button" class="btn-close" wire:click="cancelEmettreRecuApresAvertissement"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-0">
                            <strong>HelloAsso peut déjà avoir émis son propre reçu fiscal pour cette cotisation.</strong>
                            Confirmer l'émission peut créer un doublon côté donateur
                            (responsabilité du donateur de ne pas déduire deux fois).
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cancelEmettreRecuApresAvertissement">Annuler</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmEmettreRecuApresAvertissement">Confirmer l'émission</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Adhésions</span>
            <span class="text-muted small">{{ $dto->totalCount }} adhésion{{ $dto->totalCount > 1 ? 's' : '' }}</span>
        </div>
        <div class="card-body p-0">
            @if($dto->totalCount === 0)
                <div class="p-3 text-muted small">Aucune adhésion enregistrée pour ce tiers.</div>
            @else
                <table class="table table-sm mb-0">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Exercice</th>
                            <th>Formule / Validité</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th class="text-end">Montant / Motif</th>
                            <th>Compte</th>
                            <th>Reçu fiscal</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($dto->lignes as $ligneDto)
                        @php $adhesion = $ligneDto->adhesion; @endphp
                        <tr>
                            <td>{{ $ligneDto->libelleExercice() }}</td>
                            <td class="small">
                                @if($adhesion->formuleAdhesion)
                                    <span class="badge text-bg-info">{{ $adhesion->formuleAdhesion->nom }}</span>
                                @endif
                                @if($adhesion->deductible_fiscal)
                                    <span class="badge text-bg-success" title="Snapshot fiscal figé à la création de l'adhésion">
                                        <i class="bi bi-receipt"></i> Déductible
                                    </span>
                                @endif
                                @if($adhesion->isModeIllimite())
                                    <div class="text-success text-nowrap" style="font-size:.7rem">
                                        <i class="bi bi-infinity"></i> Permanente
                                    </div>
                                @elseif($adhesion->date_debut && $adhesion->date_fin)
                                    <div class="text-muted text-nowrap" style="font-size:.7rem">
                                        {{ $adhesion->date_debut->format('d/m/Y') }} → {{ $adhesion->date_fin->format('d/m/Y') }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($adhesion->estGratuite())
                                    <span class="badge text-bg-warning">Offerte</span>
                                @else
                                    <span class="badge text-bg-success">Cotisation</span>
                                @endif
                            </td>
                            <td data-sort="{{ $adhesion->estGratuite() ? optional($adhesion->created_at)->format('Y-m-d') : optional(optional($adhesion->transaction)->date)->format('Y-m-d') }}">
                                @if($adhesion->estGratuite())
                                    {{ optional($adhesion->created_at)->format('d/m/Y') }}
                                @else
                                    {{ optional(optional($adhesion->transaction)->date)->format('d/m/Y') }}
                                @endif
                            </td>
                            <td class="text-end">
                                @if($adhesion->estGratuite())
                                    <span class="text-muted small">{{ $adhesion->notes ?? '—' }}</span>
                                @else
                                    {{ number_format((float) optional($adhesion->transaction)->montant_total, 2, ',', ' ') }} €
                                @endif
                            </td>
                            <td>
                                @if(! $adhesion->estGratuite() && $adhesion->transaction?->compte)
                                    <span class="small">{{ $adhesion->transaction->compte->nom }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @php $recuActif = $ligneDto->recuFiscalActif(); @endphp
                                @if($recuActif !== null)
                                    <a href="{{ route('tiers.recu-fiscal.download', ['recu' => $recuActif]) }}"
                                       target="_blank"
                                       class="badge text-bg-primary text-decoration-none"
                                       title="Télécharger le reçu fiscal">
                                        <i class="bi bi-receipt"></i> {{ $recuActif->numero }}
                                    </a>
                                @elseif($ligneDto->peutEmettreRecu($asso))
                                    <button wire:click="emettreRecuFiscalAdhesion({{ $adhesion->id }})"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Émettre un reçu fiscal">
                                        <i class="bi bi-receipt-cutoff"></i> Émettre
                                    </button>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if(! $adhesion->estGratuite() && $adhesion->transaction_id)
                                    <a href="{{ route('tiers.transactions', $adhesion->tiers_id) }}?edit={{ $adhesion->transaction_id }}"
                                       target="_blank"
                                       class="btn btn-sm btn-outline-primary"
                                       title="Modifier la transaction">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
