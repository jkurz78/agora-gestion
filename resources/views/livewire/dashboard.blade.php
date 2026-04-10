<div>
    @if ($exerciceCloture)
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Vous consultez un exercice clôturé (lecture seule).
        </div>
    @endif

    {{-- Row 1: Solde général + Comptes bancaires --}}
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-primary h-100">
                <div class="card-body text-center">
                    <h5 class="card-title text-muted mb-1">Solde général</h5>
                    <p class="display-5 fw-bold mb-2 {{ $soldeGeneral >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ number_format($soldeGeneral, 2, ',', ' ') }} &euro;
                    </p>
                    <div class="d-flex justify-content-center gap-4 text-muted small">
                        <span>Recettes : {{ number_format($totalRecettes, 2, ',', ' ') }} &euro;</span>
                        <span>Dépenses : {{ number_format($totalDepenses, 2, ',', ' ') }} &euro;</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><a href="{{ route('compta.banques.comptes.index') }}" class="text-decoration-none text-dark"><i class="bi bi-bank"></i> Comptes bancaires</a></h5>
                </div>
                <div class="card-body d-flex align-items-center">
                    @if ($comptesAvecSolde->isEmpty())
                        <p class="text-muted mb-0">Aucun compte bancaire configuré.</p>
                    @else
                        <div class="row g-2 w-100">
                            @foreach ($comptesAvecSolde as $item)
                                <div class="col" wire:key="compte-{{ $item['compte']->id }}">
                                    <div class="card text-center border-secondary h-100">
                                        <div class="card-body p-2">
                                            <div class="small text-muted text-truncate">{{ $item['compte']->nom }}</div>
                                            <div class="fw-bold {{ $item['solde'] >= 0 ? 'text-success' : 'text-danger' }}">
                                                {{ number_format($item['solde'], 2, ',', ' ') }} &euro;
                                                <a href="{{ route('compta.banques.comptes.transactions', $item['compte']) }}"
                                                   class="ms-1 text-muted" style="font-size:.8rem"
                                                   data-bs-toggle="tooltip" title="Voir les transactions">
                                                    <i class="bi bi-list-ul"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Row 2: Budget summary --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><a href="{{ route('compta.budget.index') }}" class="text-decoration-none text-dark">Résumé budget</a></h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <h6 class="text-muted">Prévu</h6>
                            <p class="h4 fw-bold">{{ number_format($totalPrevu, 2, ',', ' ') }} &euro;</p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Réalisé</h6>
                            <p class="h4 fw-bold">{{ number_format($totalRealise, 2, ',', ' ') }} &euro;</p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Écart</h6>
                            @php $ecart = $totalPrevu - $totalRealise; @endphp
                            <p class="h4 fw-bold {{ $ecart >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ number_format($ecart, 2, ',', ' ') }} &euro;
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 3: Dernières dépenses + Dernières recettes --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><a href="{{ route('compta.transactions.index') }}" class="text-decoration-none text-dark">Dernières dépenses</a></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Libellé</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($dernieresDepenses as $dep)
                                    <tr>
                                        <td class="small text-nowrap">{{ $dep->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $dep->libelle }}</td>
                                        <td class="text-end small fw-semibold text-danger text-nowrap">{{ number_format((float) $dep->montant_total, 2, ',', ' ') }} &euro;</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-muted text-center">Aucune dépense.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><a href="{{ route('compta.transactions.index') }}" class="text-decoration-none text-dark">Dernières recettes</a></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Libellé</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($dernieresRecettes as $rec)
                                    <tr>
                                        <td class="small text-nowrap">{{ $rec->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $rec->libelle }}</td>
                                        <td class="text-end small fw-semibold text-success text-nowrap">{{ number_format((float) $rec->montant_total, 2, ',', ' ') }} &euro;</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-muted text-center">Aucune recette.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 4: Derniers dons + Membres sans cotisation --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><a href="{{ route('compta.dons.index') }}" class="text-decoration-none text-dark">Derniers dons</a></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Donateur</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($derniersDons as $don)
                                    <tr>
                                        <td class="small text-nowrap">{{ $don->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $don->tiers ? $don->tiers->displayName() : 'Anonyme' }}</td>
                                        <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $don->montant_total, 2, ',', ' ') }} &euro;</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-muted text-center">Aucun don.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><a href="{{ route('compta.cotisations.index') }}" class="text-decoration-none text-dark"><i class="bi bi-person-check"></i> Dernières adhésions</a></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Adhérent</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($dernieresAdhesions as $tx)
                                    <tr>
                                        <td class="small text-nowrap">{{ $tx->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $tx->tiers?->displayName() ?? '—' }}</td>
                                        <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $tx->montant_total, 2, ',', ' ') }} &euro;</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-muted text-center">Aucune adhésion récente.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 5: Opérations (en cours / à venir) --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><a href="{{ route('gestion.operations') }}" class="text-decoration-none text-dark"><i class="bi bi-calendar-event"></i> Opérations en cours</a></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Activité</th>
                                    <th>Type</th>
                                    <th>Opération</th>
                                    <th>Période</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($operations as $op)
                                    @php
                                        $scNom = $op->typeOperation?->sousCategorie?->nom ?? '—';
                                        $typeNom = $op->typeOperation?->nom ?? '—';

                                        $debut = $op->date_debut?->format('d/m/Y') ?? '?';
                                        $fin = $op->date_fin?->format('d/m/Y') ?? '…';
                                        $periode = "{$debut} → {$fin}";

                                        if ($op->date_debut && $op->date_debut->isFuture()) {
                                            $days = (int) now()->diffInDays($op->date_debut);
                                            $badge = ["Dans {$days} j.", 'bg-info'];
                                        } else {
                                            $badge = ['En cours', 'bg-success'];
                                        }
                                    @endphp
                                    <tr>
                                        <td class="small text-muted">{{ $scNom }}</td>
                                        <td class="small">{{ $typeNom }}</td>
                                        <td>
                                            <a href="{{ route('gestion.operations.show', $op) }}" class="text-decoration-none">
                                                {{ $op->nom }}
                                            </a>
                                            <span class="text-muted small">({{ $op->participants_count }})</span>
                                        </td>
                                        <td class="small text-nowrap">{{ $periode }}</td>
                                        <td><span class="badge {{ $badge[1] }}">{{ $badge[0] }}</span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-muted text-center">Aucune opération en cours.</td>
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
