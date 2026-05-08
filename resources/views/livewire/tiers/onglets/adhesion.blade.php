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
                            <th>Type</th>
                            <th>Date</th>
                            <th class="text-end">Montant / Motif</th>
                            <th>Compte</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($dto->lignes as $ligneDto)
                        @php $adhesion = $ligneDto->adhesion; @endphp
                        <tr>
                            <td>{{ $ligneDto->libelleExercice() }}</td>
                            <td>
                                @if($adhesion->gratuite)
                                    <span class="badge text-bg-warning">Offerte</span>
                                @else
                                    <span class="badge text-bg-success">Cotisation</span>
                                @endif
                            </td>
                            <td data-sort="{{ $adhesion->gratuite ? optional($adhesion->created_at)->format('Y-m-d') : optional(optional($adhesion->transaction)->date)->format('Y-m-d') }}">
                                @if($adhesion->gratuite)
                                    {{ optional($adhesion->created_at)->format('d/m/Y') }}
                                @else
                                    {{ optional(optional($adhesion->transaction)->date)->format('d/m/Y') }}
                                @endif
                            </td>
                            <td class="text-end">
                                @if($adhesion->gratuite)
                                    <span class="text-muted small">{{ $adhesion->motif_gratuite ?? '—' }}</span>
                                @else
                                    {{ number_format((float) optional($adhesion->transaction)->montant_total, 2, ',', ' ') }} €
                                @endif
                            </td>
                            <td>
                                @if(! $adhesion->gratuite && $adhesion->transaction?->compte)
                                    <span class="small">{{ $adhesion->transaction->compte->nom }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if(! $adhesion->gratuite && $adhesion->transaction_id)
                                    <a href="{{ route('tiers.transactions', $adhesion->tiers_id) }}"
                                       target="_blank"
                                       class="btn btn-sm btn-link p-0"
                                       title="Voir les transactions">
                                        <i class="bi bi-link-45deg"></i>
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
