<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0"><i class="bi bi-file-earmark-text me-1"></i> Vos factures déposées</h2>
        <a href="{{ route('portail.factures.create', ['association' => $association->slug]) }}"
           class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Déposer une facture
        </a>
    </div>

    @if (session('portail.success'))
        <div class="alert alert-success">{{ session('portail.success') }}</div>
    @endif

    @if ($depots->isEmpty())
        <div class="alert alert-info">
            Aucune facture en attente de traitement.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Date facture</th>
                        <th>Numéro</th>
                        <th>Déposée le</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($depots as $depot)
                        <tr>
                            <td data-sort="{{ $depot->date_facture?->format('Y-m-d') }}">
                                {{ $depot->date_facture?->format('d/m/Y') }}
                            </td>
                            <td>{{ $depot->numero_facture }}</td>
                            <td data-sort="{{ $depot->created_at?->format('Y-m-d') }}">
                                {{ $depot->created_at?->format('d/m/Y') }}
                            </td>
                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <a href="{{ $depot->pdf_url }}"
                                       class="btn btn-outline-secondary btn-sm"
                                       target="_blank">
                                        <i class="bi bi-file-earmark-pdf me-1"></i>Voir PDF
                                    </a>
                                    <button type="button"
                                            class="btn btn-outline-danger btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalSupprimer-{{ $depot->id }}">
                                        <i class="bi bi-trash me-1"></i>Supprimer
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <div class="modal fade" id="modalSupprimer-{{ $depot->id }}" tabindex="-1"
                             aria-labelledby="modalSupprimerLabel-{{ $depot->id }}" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="modalSupprimerLabel-{{ $depot->id }}">
                                            Confirmer la suppression
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Fermer"></button>
                                    </div>
                                    <div class="modal-body">
                                        Supprimer la facture <strong>{{ $depot->numero_facture }}</strong> ?
                                        Cette action est irréversible.
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary"
                                                data-bs-dismiss="modal">Annuler</button>
                                        <button type="button"
                                                wire:click="oublier({{ $depot->id }})"
                                                data-bs-dismiss="modal"
                                                class="btn btn-danger">
                                            <i class="bi bi-trash me-1"></i>Supprimer
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mt-3 text-end">
        <a href="{{ route('portail.home', ['association' => $association->slug]) }}"
           class="btn btn-link btn-sm text-muted">
            <i class="bi bi-arrow-left me-1"></i>Retour à l'accueil
        </a>
    </div>
</div>
