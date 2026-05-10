<div>
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
