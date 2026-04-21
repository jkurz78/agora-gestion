<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0"><i class="bi bi-receipt me-1"></i> Note de frais</h2>
        <div class="d-flex gap-2 align-items-center">
            @if ($ndf->isArchived())
                <span class="badge bg-secondary fs-6">Archivée</span>
            @endif
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
                    <span class="badge bg-success fs-6">{{ $statut->label() }}</span>
                    @break
                @case('don_par_abandon_de_creances')
                    <span class="badge bg-info fs-6">{{ $statut->label() }}</span>
                    @break
            @endswitch
        </div>
    </div>

    @if (session('portail.success'))
        <div class="alert alert-success">{{ session('portail.success') }}</div>
    @endif

    @if ($statut->value === 'rejetee' && $ndf->motif_rejet)
        <div class="alert alert-danger mb-3">
            <strong><i class="bi bi-x-octagon me-1"></i>Motif de rejet :</strong>
            {{ $ndf->motif_rejet }}
        </div>
    @endif

    @if ($statut->value === 'don_par_abandon_de_creances' && $ndf->donTransaction)
        <div class="alert alert-success mb-3">
            <p class="mb-1">
                <i class="bi bi-gift me-1"></i>
                <strong>Don par abandon de créance — acté le {{ $ndf->donTransaction->date->format('d/m/Y') }}</strong>
            </p>
            <p class="mb-0">
                Montant du don : {{ number_format((float) $ndf->donTransaction->montant_total, 2, ',', ' ') }} €
            </p>
        </div>
    @elseif ($statut->value === 'soumise' && $ndf->abandon_creance_propose)
        <div class="alert alert-info mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Don par abandon de créance proposé — en attente de traitement
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Date</dt>
                <dd class="col-sm-9">{{ $ndf->date?->format('d/m/Y') }}</dd>

                <dt class="col-sm-3">Libellé</dt>
                <dd class="col-sm-9">{{ $ndf->libelle }}</dd>

                @if ($statut->value === 'payee')
                    <dt class="col-sm-3">Date de validation</dt>
                    <dd class="col-sm-9">{{ $ndf->validee_at?->format('d/m/Y') }}</dd>
                @endif

                @if ($ndf->isArchived())
                    <dt class="col-sm-3">Archivée le</dt>
                    <dd class="col-sm-9">{{ $ndf->archived_at?->format('d/m/Y') }}</dd>
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
                            <td>
                                @include('livewire.portail.note-de-frais.partials.ligne-details', [
                                    'ligne' => [
                                        'type' => $ligne->type->value,
                                        'libelle' => $ligne->libelle,
                                        'cv_fiscaux' => $ligne->metadata['cv_fiscaux'] ?? null,
                                        'distance_km' => $ligne->metadata['distance_km'] ?? null,
                                        'bareme_eur_km' => $ligne->metadata['bareme_eur_km'] ?? null,
                                    ]
                                ])
                            </td>
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

        @if (! $ndf->isArchived())
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

                @if (in_array($statut->value, ['payee', 'rejetee']))
                    {{-- Bouton Archiver avec confirmation via modale Bootstrap --}}
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#modalArchiver">
                        <i class="bi bi-archive me-1"></i>Archiver
                    </button>
                @endif
            </div>
        @endif
    </div>

    {{-- Modale de confirmation de suppression --}}
    @if (! $ndf->isArchived() && in_array($statut->value, ['brouillon', 'soumise', 'rejetee']))
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

    {{-- Modale de confirmation d'archivage --}}
    @if (! $ndf->isArchived() && in_array($statut->value, ['payee', 'rejetee']))
        <div class="modal fade" id="modalArchiver" tabindex="-1" aria-labelledby="modalArchiverLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalArchiverLabel">Confirmer l'archivage</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        Archiver cette note de frais la masquera de la liste des notes actives.
                        Cette action est irréversible.
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" wire:click="archiveNdf" data-bs-dismiss="modal" class="btn btn-warning">
                            <i class="bi bi-archive me-1"></i>Archiver
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
