@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
    $exerciceService = app(App\Services\ExerciceService::class);
@endphp

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <a href="{{ route('immobilisations.index') }}" class="text-decoration-none small">
                <i class="bi bi-arrow-left"></i> Livre des immobilisations
            </a>
            <h4 class="mb-0 mt-1">{{ $immobilisation->numero }} — {{ $immobilisation->libelle }}</h4>
        </div>
        <a href="{{ route('immobilisations.pdf', $immobilisation) }}" class="btn btn-outline-secondary btn-sm" target="_blank">
            <i class="bi bi-file-earmark-pdf me-1"></i> Imprimer la fiche
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Identité</h6></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5">Quantité</dt><dd class="col-7">{{ $immobilisation->quantite }}</dd>
                        <dt class="col-5">Compte</dt>
                        <dd class="col-7">{{ $immobilisation->compte->numero_pcg }} — {{ $immobilisation->compte->intitule }}</dd>
                        <dt class="col-5">Amortissements</dt>
                        <dd class="col-7">{{ $immobilisation->compteAmortissement->numero_pcg }} — {{ $immobilisation->compteAmortissement->intitule }}</dd>
                        <dt class="col-5">Mise en service</dt>
                        <dd class="col-7">
                            {{ $immobilisation->date_mise_en_service->format('d/m/Y') }}
                            @unless ($immobilisation->estEnService())
                                <span class="badge bg-warning text-dark">Pas encore en service</span>
                            @endunless
                        </dd>
                        <dt class="col-5">Durée</dt><dd class="col-7">{{ $immobilisation->duree_label }}</dd>
                        <dt class="col-5">Valeur brute</dt>
                        <dd class="col-7">{{ $euros($immobilisation->montantAcquisitionCentimes()) }} €</dd>
                        <dt class="col-5">Valeur nette</dt>
                        <dd class="col-7 fw-semibold">{{ $euros($immobilisation->valeurNetteCentimes()) }} €</dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Acquisition</h6></div>
                <div class="card-body">
                    @foreach ($immobilisation->transactionsAcquisition() as $tx)
                        <dl class="row mb-0">
                            <dt class="col-5">Date</dt><dd class="col-7">{{ $tx->date->format('d/m/Y') }}</dd>
                            <dt class="col-5">Fournisseur</dt><dd class="col-7">{{ $tx->tiers?->nom_complet ?? '—' }}</dd>
                            <dt class="col-5">Pièce</dt><dd class="col-7">{{ $tx->numero_piece ?? '—' }}</dd>
                            <dt class="col-5">Montant</dt><dd class="col-7">{{ number_format((float) $tx->montant_total, 2, ',', ' ') }} €</dd>
                        </dl>
                    @endforeach
                    @if ($immobilisation->notes)
                        <hr><p class="mb-0 small text-muted">{{ $immobilisation->notes }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h6 class="mb-0">Plan d'amortissement</h6></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Exercice</th>
                        <th class="text-end">Mois</th>
                        <th class="text-end">Dotation</th>
                        <th class="text-end">Cumul</th>
                        <th class="text-end">Valeur nette</th>
                        <th>État</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plan as $ligne)
                        <tr class="{{ $ligne['comptabilisee'] ? '' : 'text-muted fst-italic' }}">
                            <td>{{ $exerciceService->label($ligne['exercice']) }}</td>
                            <td class="text-end">{{ $ligne['moisEcoules'] }}</td>
                            <td class="text-end">{{ $euros($ligne['dotationCentimes']) }} €</td>
                            <td class="text-end">{{ $euros($ligne['cumulCentimes']) }} €</td>
                            <td class="text-end">{{ $euros($ligne['valeurNetteCentimes']) }} €</td>
                            <td>
                                @if ($ligne['comptabilisee'])
                                    <span class="badge bg-success">Comptabilisée</span>
                                @else
                                    <span class="badge bg-light text-dark border">Prévisionnel</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
