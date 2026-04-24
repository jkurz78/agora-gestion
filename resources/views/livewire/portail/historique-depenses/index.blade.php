<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0"><i class="bi bi-clock-history me-1"></i> Historique de vos dépenses</h2>
    </div>

    @if ($resources->isEmpty())
        <div class="alert alert-info">
            Aucune dépense pour le moment.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Date</th>
                        <th>Notre réf</th>
                        <th>Réf</th>
                        <th class="text-end">Montant (€)</th>
                        <th>Statut</th>
                        <th class="text-center">PDF</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resources as $item)
                        <tr>
                            <td data-sort="{{ $item['date_piece'] }}">
                                {{ \Carbon\Carbon::parse($item['date_piece'])->format('d/m/Y') }}
                            </td>
                            <td>{{ $item['notre_ref'] }}</td>
                            <td>{{ $item['ref'] }}</td>
                            <td class="text-end" data-sort="{{ number_format($item['montant_ttc'], 2, '.', '') }}">
                                {{ number_format($item['montant_ttc'], 2, ',', ' ') }} €
                            </td>
                            <td>
                                @if ($item['statut_reglement'] === 'Réglée')
                                    <span class="badge bg-success">Réglée</span>
                                @else
                                    <span class="badge bg-warning text-dark">En attente</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($item['pdf_url'] !== null)
                                    <a href="{{ $item['pdf_url'] }}"
                                       class="btn btn-outline-secondary btn-sm"
                                       target="_blank"
                                       title="Télécharger le PDF">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $transactions->links() }}
    @endif

    <p class="text-muted mt-3 small">
        <em>
            Vos remboursements de notes de frais sont visibles dans l'écran
            <a href="{{ route('portail.ndf.index', ['association' => $association->slug]) }}">Vos notes de frais</a>.
        </em>
    </p>

    <div class="mt-2 text-end">
        <a href="{{ route('portail.home', ['association' => $association->slug]) }}"
           class="btn btn-link btn-sm text-muted">
            <i class="bi bi-arrow-left me-1"></i>Retour à l'accueil
        </a>
    </div>
</div>
