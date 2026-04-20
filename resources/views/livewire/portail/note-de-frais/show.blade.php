<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0"><i class="bi bi-receipt me-1"></i> Note de frais</h2>
        @php $statut = $ndf->statut; @endphp
        @switch($statut->value)
            @case('brouillon')
                <span class="badge bg-secondary fs-6">{{ $statut->label() }}</span>
                @break
            @case('soumise')
                <span class="badge bg-primary fs-6">{{ $statut->label() }}</span>
                @break
            @case('rejetee')
                <span class="badge bg-danger fs-6">{{ $statut->label() }}</span>
                @break
            @case('validee')
                <span class="badge bg-success fs-6">{{ $statut->label() }}</span>
                @break
            @case('payee')
                <span class="badge bg-success text-dark fs-6">{{ $statut->label() }}</span>
                @break
        @endswitch
    </div>

    @if (session('portail.success'))
        <div class="alert alert-success">{{ session('portail.success') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Date</dt>
                <dd class="col-sm-9">{{ $ndf->date?->format('d/m/Y') }}</dd>

                <dt class="col-sm-3">Libellé</dt>
                <dd class="col-sm-9">{{ $ndf->libelle }}</dd>

                @if ($statut->value === 'rejetee' && $ndf->motif_rejet)
                    <dt class="col-sm-3 text-danger">Motif de rejet</dt>
                    <dd class="col-sm-9 text-danger">{{ $ndf->motif_rejet }}</dd>
                @endif

                @if ($statut->value === 'payee')
                    <dt class="col-sm-3">Date de validation</dt>
                    <dd class="col-sm-9">{{ $ndf->validee_at?->format('d/m/Y') }}</dd>
                @endif
            </dl>
        </div>
    </div>

    <h6 class="fw-semibold mb-2">Lignes</h6>
    @if ($ndf->lignes->isEmpty())
        <p class="text-muted small">Aucune ligne.</p>
    @else
        <div class="table-responsive mb-3">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Libellé</th>
                        <th>Sous-catégorie</th>
                        <th class="text-end">Montant</th>
                        <th>Justificatif</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ndf->lignes as $ligne)
                        <tr>
                            <td>{{ $ligne->libelle }}</td>
                            <td>{{ $ligne->sousCategorie?->nom ?? '—' }}</td>
                            <td class="text-end">{{ number_format((float) $ligne->montant, 2, ',', ' ') }} €</td>
                            <td>
                                @if ($ligne->piece_jointe_path)
                                    <span class="badge bg-success-subtle text-success">
                                        <i class="bi bi-paperclip me-1"></i>Joint
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="2" class="text-end">Total :</td>
                        <td class="text-end">{{ number_format((float) $ndf->lignes->sum('montant'), 2, ',', ' ') }} €</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mt-3">
        <a href="{{ route('portail.ndf.index', ['association' => $association->slug]) }}"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour à la liste
        </a>

        <div class="d-flex gap-2">
            @if (in_array($statut->value, ['brouillon', 'soumise', 'rejetee']))
                <a href="{{ route('portail.ndf.edit', ['association' => $association->slug, 'noteDeFrais' => $ndf->id]) }}"
                   class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Modifier
                </a>

                {{-- Bouton Supprimer avec confirmation via modale Bootstrap --}}
                <button type="button"
                        class="btn btn-outline-danger btn-sm"
                        data-bs-toggle="modal"
                        data-bs-target="#modalSupprimer">
                    <i class="bi bi-trash me-1"></i>Supprimer
                </button>
            @endif
        </div>
    </div>

    {{-- Modale de confirmation de suppression --}}
    @if (in_array($statut->value, ['brouillon', 'soumise', 'rejetee']))
        <div class="modal fade" id="modalSupprimer" tabindex="-1" aria-labelledby="modalSupprimerLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalSupprimerLabel">Confirmer la suppression</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        Êtes-vous sûr de vouloir supprimer cette note de frais ? Cette action est irréversible.
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" wire:click="delete" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i>Supprimer définitivement
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
