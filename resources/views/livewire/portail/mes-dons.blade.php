<div>
    @if (session('portail.error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('portail.error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    @endif

    @if ($donsTimeline->raisonBlocageGlobal)
        <div class="alert alert-warning" role="alert">
            {{ $donsTimeline->raisonBlocageGlobal }}
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Mes dons</h4>
        @if ($urlNouveauDon)
            <a class="btn btn-primary" href="{{ $urlNouveauDon }}" target="_blank" rel="noopener">
                <i class="bi bi-heart"></i> Faire un nouveau don
            </a>
        @endif
    </div>

    @if ($donsTimeline->totalCount === 0)
        <div class="text-muted py-4 text-center">
            <i class="bi bi-gift fs-3 d-block mb-2"></i>
            Aucun don enregistré.
        </div>
    @else
        @foreach ($donsTimeline->annees as $annee)
            <h5 class="mt-4 mb-2">
                {{ $annee->annee }}
                <span class="text-muted small">— total {{ $annee->total }} €</span>
            </h5>

            <div class="table-responsive mb-4">
                <table class="table table-hover table-bordered align-middle">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Date</th>
                            <th>Nature</th>
                            <th class="text-end">Montant</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($annee->lignes as $donDto)
                            <tr>
                                <td data-sort="{{ $donDto->ligne->transaction->date->format('Y-m-d') }}">
                                    {{ $donDto->ligne->transaction->date->format('d/m/Y') }}
                                </td>
                                <td>{{ $donDto->ligne->sousCategorie?->nom ?? '—' }}</td>
                                <td class="text-end" data-sort="{{ $donDto->ligne->montant }}">
                                    {{ number_format((float) $donDto->ligne->montant, 2, ',', ' ') }}&nbsp;€
                                </td>
                                <td>
                                    @if ($donDto->peutTelecharger || $donDto->recu !== null)
                                        <button
                                            wire:click="telechargerRecuFiscal({{ $donDto->ligne->id }})"
                                            class="btn btn-sm btn-outline-secondary"
                                            wire:loading.attr="disabled"
                                        >
                                            <i class="bi bi-download"></i> Télécharger le reçu
                                        </button>
                                    @elseif ($donDto->raisonBlocage)
                                        <span class="text-muted small">{{ $donDto->raisonBlocage }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</div>
