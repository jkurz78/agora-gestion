<div>
    {{-- En-tete --}}
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="mb-1">
                <i class="bi bi-file-earmark-text"></i>
                @if ($facture->statut === \App\Enums\StatutFacture::Annulee && $facture->numero_avoir)
                    Avoir {{ $facture->numero_avoir }}
                @else
                    Facture {{ $facture->numero }}
                @endif

                @if ($isAcquittee)
                    <span class="badge bg-success fs-6">Acquittée</span>
                @elseif ($facture->statut === \App\Enums\StatutFacture::Annulee)
                    <span class="badge bg-danger fs-6">Annulée</span>
                @else
                    <span class="badge bg-primary fs-6">Validée</span>
                @endif
            </h1>
            <p class="text-muted mb-0">
                Date d'émission : <strong>{{ $facture->date->format('d/m/Y') }}</strong>
                — Exercice {{ $facture->exercice }}/{{ $facture->exercice + 1 }}
            </p>
        </div>
        <a href="{{ route('gestion.factures') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Retour à la liste
        </a>
    </div>

    <div class="row">
        <div class="col-lg-8">
            {{-- Lignes de facture --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-receipt"></i> Lignes de facture</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Désignation</th>
                                    <th class="text-end" style="width: 140px;">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @foreach ($facture->lignes as $ligne)
                                    <tr wire:key="ligne-{{ $ligne->id }}">
                                        @if ($ligne->type === \App\Enums\TypeLigneFacture::Texte)
                                            <td colspan="2" class="fw-bold">{{ $ligne->libelle }}</td>
                                        @else
                                            <td>{{ $ligne->libelle }}</td>
                                            <td class="text-end fw-semibold text-nowrap">{{ number_format((float) $ligne->montant, 2, ',', "\u{202f}") }}&nbsp;&euro;</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td class="text-end">Total</td>
                                    <td class="text-end text-nowrap">{{ number_format($facture->montantCalcule(), 2, ',', "\u{202f}") }}&nbsp;&euro;</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Statut de paiement --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Paiement</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-4 mb-3 mb-md-0">
                            <div class="text-muted small">Montant total</div>
                            <div class="fs-5 fw-bold">{{ number_format($facture->montantCalcule(), 2, ',', "\u{202f}") }}&nbsp;&euro;</div>
                        </div>
                        <div class="col-md-4 mb-3 mb-md-0">
                            <div class="text-muted small">Montant réglé</div>
                            <div class="fs-5 fw-bold text-success">{{ number_format($montantRegle, 2, ',', "\u{202f}") }}&nbsp;&euro;</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Reste dû</div>
                            @php $resteDu = $facture->montantCalcule() - $montantRegle; @endphp
                            <div class="fs-5 fw-bold {{ $resteDu > 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($resteDu, 2, ',', "\u{202f}") }}&nbsp;&euro;
                            </div>
                        </div>
                    </div>

                    @if ($isAcquittee)
                        <div class="text-center mt-3">
                            <span class="badge bg-success fs-6"><i class="bi bi-check-circle"></i> Acquittée</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            {{-- Tiers --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-person"></i> Tiers</h5>
                </div>
                <div class="card-body">
                    <p class="fw-bold mb-1">{{ $facture->tiers->displayName() }}</p>
                    @if ($facture->tiers->adresse_ligne1)
                        <p class="mb-0 text-muted small">
                            {{ $facture->tiers->adresse_ligne1 }}<br>
                            @if ($facture->tiers->code_postal || $facture->tiers->ville)
                                {{ $facture->tiers->code_postal }} {{ $facture->tiers->ville }}
                            @endif
                        </p>
                    @endif
                </div>
            </div>

            {{-- Conditions de reglement --}}
            @if ($facture->conditions_reglement)
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-clock"></i> Conditions de règlement</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">{!! nl2br(e($facture->conditions_reglement)) !!}</p>
                    </div>
                </div>
            @endif

            {{-- Mentions legales --}}
            @if ($facture->mentions_legales)
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-shield-check"></i> Mentions légales</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0 small">{!! nl2br(e($facture->mentions_legales)) !!}</p>
                    </div>
                </div>
            @endif

            {{-- Notes internes --}}
            @if ($facture->notes)
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-sticky"></i> Notes internes</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0 small text-muted">{!! nl2br(e($facture->notes)) !!}</p>
                    </div>
                </div>
            @endif

            {{-- Actions --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-lightning"></i> Actions</h5>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="{{ route('gestion.factures.pdf', ['facture' => $facture, 'mode' => 'inline']) }}" class="btn btn-outline-primary" target="_blank">
                        <i class="bi bi-file-earmark-pdf"></i> Télécharger PDF
                    </a>
                    <a href="{{ route('gestion.factures') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Retour à la liste
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
