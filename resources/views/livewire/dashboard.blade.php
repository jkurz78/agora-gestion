<div>
    @if ($exerciceCloture)
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Vous consultez un exercice clôturé (lecture seule).
        </div>
    @endif

    {{-- Header --}}
    <div class="mb-4">
        <h1 class="mb-0">Tableau de bord</h1>
    </div>

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
                    <h5 class="mb-0"><i class="bi bi-bank"></i> Comptes bancaires</h5>
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
                                                <a href="{{ route('comptes-bancaires.transactions', $item['compte']) }}"
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
                    <h5 class="mb-0">Résumé budget</h5>
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
                    <h5 class="mb-0">Dernières dépenses</h5>
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
                    <h5 class="mb-0">Dernières recettes</h5>
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
                    <h5 class="mb-0">Derniers dons</h5>
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
                    <h5 class="mb-0">Membres sans cotisation</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Prénom</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($membresSansCotisation as $membre)
                                    <tr>
                                        <td class="small">{{ $membre->nom }}</td>
                                        <td class="small">{{ $membre->prenom }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="text-muted text-center">Tous les membres ont cotisé.</td>
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
