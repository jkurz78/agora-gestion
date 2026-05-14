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
                    <h5 class="mb-0"><a href="{{ route('banques.comptes.index') }}" class="text-decoration-none text-dark"><i class="bi bi-bank"></i> Comptes bancaires</a></h5>
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
                                                <a href="{{ route('banques.comptes.transactions', $item['compte']) }}"
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

    {{-- Row 2: Opérations en cours (déplacé en haut — plus actionnable) --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><a href="{{ route('operations.index') }}" class="text-decoration-none text-dark"><i class="bi bi-calendar-event"></i> Opérations en cours</a></h5>
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
                                            <a href="{{ route('operations.show', $op) }}" class="text-decoration-none">
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

    {{-- Row 3: Dernières dépenses + Dernières recettes --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><a href="{{ route('comptabilite.transactions') }}" class="text-decoration-none text-dark">Dernières dépenses</a></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Tiers</th>
                                    <th>Libellé</th>
                                    <th class="text-end">Montant</th>
                                    <th class="text-center">Statut</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($dernieresDepenses as $dep)
                                    @php
                                        $statut = $dep->statut_reglement;
                                        if ($statut === null) {
                                            $statutBadge = null;
                                        } elseif ($statut->isEncaisse()) {
                                            $statutBadge = ['Payé', 'bg-success'];
                                        } else {
                                            $statutBadge = ['À payer', 'bg-warning text-dark'];
                                        }
                                    @endphp
                                    <tr>
                                        <td class="small text-nowrap">{{ $dep->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $dep->tiers?->displayName() ?? '—' }}</td>
                                        <td class="small">{{ $dep->libelle }}</td>
                                        <td class="text-end small fw-semibold text-danger text-nowrap">{{ number_format((float) $dep->montant_total, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-center small">
                                            @if($statutBadge)
                                                <span class="badge {{ $statutBadge[1] }}" style="font-size:.7rem">{{ $statutBadge[0] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-muted text-center">Aucune dépense.</td>
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
                    <h5 class="mb-0"><a href="{{ route('comptabilite.transactions') }}" class="text-decoration-none text-dark">Dernières recettes</a></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Tiers</th>
                                    <th>Libellé</th>
                                    <th class="text-end">Montant</th>
                                    <th class="text-center">Statut</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($dernieresRecettes as $rec)
                                    @php
                                        $statut = $rec->statut_reglement;
                                        if ($statut === null) {
                                            $statutBadge = null;
                                        } elseif ($statut->isEncaisse()) {
                                            $statutBadge = ['Reçu', 'bg-success'];
                                        } else {
                                            $statutBadge = ['En attente', 'bg-warning text-dark'];
                                        }
                                    @endphp
                                    <tr>
                                        <td class="small text-nowrap">{{ $rec->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $rec->tiers?->displayName() ?? '—' }}</td>
                                        <td class="small">{{ $rec->libelle }}</td>
                                        <td class="text-end small fw-semibold text-success text-nowrap">{{ number_format((float) $rec->montant_total, 2, ',', ' ') }} &euro;</td>
                                        <td class="text-center small">
                                            @if($statutBadge)
                                                <span class="badge {{ $statutBadge[1] }}" style="font-size:.7rem">{{ $statutBadge[0] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-muted text-center">Aucune recette.</td>
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
                    <h5 class="mb-0"><a href="{{ route('comptabilite.dons') }}" class="text-decoration-none text-dark">Derniers dons</a></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Donateur</th>
                                    <th>Nature</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($derniersDons as $don)
                                    @php $scNom = $don->lignes->first()?->sousCategorie?->nom ?? '—'; @endphp
                                    <tr>
                                        <td class="small text-nowrap">{{ $don->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $don->tiers ? $don->tiers->displayName() : 'Anonyme' }}</td>
                                        <td class="small text-muted">{{ $scNom }}</td>
                                        <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $don->montant_total, 2, ',', ' ') }} &euro;</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted text-center">Aucun don.</td>
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
                    <h5 class="mb-0"><a href="{{ route('comptabilite.cotisations') }}" class="text-decoration-none text-dark"><i class="bi bi-person-check"></i> Dernières adhésions</a></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Adhérent</th>
                                    <th>Formule</th>
                                    <th>Période</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse ($dernieresAdhesions as $tx)
                                    @php
                                        $adh = $tx->adhesions->first();
                                        $formule = $adh?->label_formule ?? $adh?->formuleAdhesion?->nom ?? '—';
                                        $debut = $adh?->date_debut?->format('d/m/Y');
                                        $fin = $adh?->date_fin?->format('d/m/Y');
                                        $periode = ($debut && $fin) ? "{$debut} → {$fin}" : ($debut ?: '—');
                                    @endphp
                                    <tr>
                                        <td class="small text-nowrap">{{ $tx->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $tx->tiers?->displayName() ?? '—' }}</td>
                                        <td class="small text-muted">{{ $formule }}</td>
                                        <td class="small text-nowrap text-muted">{{ $periode }}</td>
                                        <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $tx->montant_total, 2, ',', ' ') }} &euro;</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-muted text-center">Aucune adhésion récente.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 5: Résumé budget (déplacé en bas — info de synthèse, moins actionnable) --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header py-2">
                    <h6 class="mb-0"><a href="{{ route('comptabilite.budget') }}" class="text-decoration-none text-dark">Résumé budget</a></h6>
                </div>
                <div class="card-body py-2">
                    @if(empty($budgetParCategorie))
                        <div class="text-center text-muted small py-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Aucun budget défini pour cet exercice.
                            <a href="{{ route('comptabilite.budget') }}" class="ms-2">Configurer le budget →</a>
                            <div class="mt-1" style="font-size:.75rem;color:#999">
                                Le réalisé global de l'exercice est visible dans le bandeau « Solde général » en haut.
                            </div>
                        </div>
                    @else
                    <div class="row text-center mb-2">
                        <div class="col-md-4">
                            <div class="small text-muted">Prévu</div>
                            <div class="fw-bold">{{ number_format($totalPrevu, 2, ',', ' ') }} &euro;</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">Réalisé</div>
                            <div class="fw-bold">{{ number_format($totalRealise, 2, ',', ' ') }} &euro;</div>
                        </div>
                        <div class="col-md-4">
                            @php $ecart = $totalPrevu - $totalRealise; @endphp
                            <div class="small text-muted">Écart</div>
                            <div class="fw-bold {{ $ecart >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ number_format($ecart, 2, ',', ' ') }} &euro;
                            </div>
                        </div>
                    </div>

                    @if(! empty($budgetParCategorie))
                        <table class="table table-sm mb-0" style="font-size:.8rem">
                            <thead>
                                <tr class="text-muted">
                                    <th style="font-weight:500">Catégorie</th>
                                    <th class="text-end" style="font-weight:500">Prévu</th>
                                    <th class="text-end" style="font-weight:500">Réalisé</th>
                                    <th class="text-end" style="font-weight:500">Écart</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($budgetParCategorie as $catNom => $data)
                                    @php
                                        $catEcart = $data['prevu'] - $data['realise'];
                                        // Côté recette : écart négatif (réalisé < prévu) = mauvais (rouge)
                                        // Côté dépense : écart positif (prévu > réalisé, donc on a moins dépensé) = bon (vert)
                                        $ecartColor = $data['type'] === 'recette'
                                            ? ($catEcart > 0 ? 'text-danger' : 'text-success')
                                            : ($catEcart >= 0 ? 'text-success' : 'text-danger');
                                    @endphp
                                    <tr>
                                        <td>
                                            <span class="badge {{ $data['type'] === 'recette' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }} me-1" style="font-size:.65rem">
                                                {{ $data['type'] === 'recette' ? 'R' : 'D' }}
                                            </span>
                                            {{ $catNom }}
                                        </td>
                                        <td class="text-end">{{ number_format($data['prevu'], 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end">{{ number_format($data['realise'], 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end {{ $ecartColor }}">{{ number_format($catEcart, 2, ',', ' ') }} &euro;</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                    @endif {{-- /budgetParCategorie empty --}}
                </div>
            </div>
        </div>
    </div>
</div>
