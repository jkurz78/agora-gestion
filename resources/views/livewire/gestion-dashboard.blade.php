<div>
    <div class="row g-4">
        {{-- Carte Opérations --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-calendar-event"></i> Opérations
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Opération</th>
                                    <th>Début</th>
                                    <th>Fin</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse($operations as $op)
                                    @php
                                        $now = now();
                                        if ($op->statut === \App\Enums\StatutOperation::Cloturee) {
                                            $badge = ['Terminée', 'bg-secondary'];
                                        } elseif ($op->date_debut && $op->date_debut->isFuture()) {
                                            $days = (int) $now->diffInDays($op->date_debut);
                                            $badge = ["Dans {$days} jour" . ($days > 1 ? 's' : ''), 'bg-info'];
                                        } elseif ($op->date_fin && $op->date_fin->isPast()) {
                                            $badge = ['Terminée', 'bg-secondary'];
                                        } else {
                                            $badge = ['En cours', 'bg-success'];
                                        }
                                    @endphp
                                    <tr>
                                        <td>
                                            <a href="{{ route('gestion.operations.show', $op) }}" class="text-decoration-none">
                                                {{ $op->nom }}
                                            </a>
                                        </td>
                                        <td class="small text-nowrap">{{ $op->date_debut?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="small text-nowrap">{{ $op->date_fin?->format('d/m/Y') ?? '—' }}</td>
                                        <td><span class="badge {{ $badge[1] }}">{{ $badge[0] }}</span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-3">Aucune opération pour cet exercice.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Carte Dernières adhésions --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-person-check"></i> Dernières adhésions
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Date</th>
                                    <th>Adhérent</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse($dernieresAdhesions as $tx)
                                    <tr>
                                        <td class="small text-nowrap">{{ $tx->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $tx->tiers?->displayName() ?? '—' }}</td>
                                        <td class="small text-end fw-semibold">{{ number_format((float) $tx->montant_total, 2, ',', ' ') }} €</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">Aucune adhésion récente.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Carte Derniers dons --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-heart"></i> Derniers dons
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Date</th>
                                    <th>Donateur</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse($derniersDons as $don)
                                    <tr>
                                        <td class="small text-nowrap">{{ $don->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $don->tiers?->displayName() ?? '—' }}</td>
                                        <td class="small text-end fw-semibold">{{ number_format((float) $don->montant_total, 2, ',', ' ') }} €</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">Aucun don récent.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
